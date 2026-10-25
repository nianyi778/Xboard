<?php
/** Codex independent probes. No application boot, production env, network or credentials. */
declare(strict_types=1);
$root = $argv[1] ?? '';
$vendor = $argv[2] ?? '';
if (!is_file($root.'/app/Services/CloudWallet/CloudWalletService.php') || !is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "usage: php review-provider.php XBOARD_CHECKOUT VENDOR_DIRECTORY\n"); exit(2);
}
require $vendor.'/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require $path;
    }
}, true, true);
$app = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository(['cloud_wallet'=>[
    'max_amount_cents'=>2147483647, 'reconcile_default_limit'=>100, 'reconcile_max_limit'=>500,
]]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->bind('db.schema', fn()=> $capsule->getConnection()->getSchemaBuilder());
$db=$capsule->getConnection();
$db->statement('CREATE TABLE v2_user(id INTEGER PRIMARY KEY,balance INTEGER NOT NULL,banned INTEGER NOT NULL DEFAULT 0)');
$migration=require $root.'/database/migrations/2026_10_25_000001_create_cloud_wallet_tables.php';
$migration->up();
$db->insert('INSERT INTO v2_user VALUES (1,10000,0)');
$wallet=new App\Services\CloudWallet\CloudWalletService();
$results=[];
$check=function(string $name,bool $pass)use(&$results){$results[$name]=$pass;};
$reject=function(callable $fn):bool {try{$fn();return false;}catch(App\Exceptions\CloudWalletException){return true;}};
$request=['userId'=>1,'orderNo'=>'TJ-PROBE-1','idempotencyKey'=>'cloud:TJ-PROBE-1:debit:1','amountCents'=>1000,'currency'=>'CNY'];
$first=$wallet->debit($request);
$check('same-key replay returns original ledger and debits once', $wallet->debit($request)===$first && (int)$db->table('v2_user')->value('balance')===9000);
$check('same order cannot debit twice by changing key', $reject(fn()=> $wallet->debit(array_replace($request,['idempotencyKey'=>'cloud:TJ-PROBE-1:debit:2']))));
$check('same key changed amount rejected', $reject(fn()=> $wallet->debit(array_replace($request,['amountCents'=>500]))));
$check('empty refund reason rejected', $reject(fn()=> $wallet->refund(['debitId'=>$first['id'],'idempotencyKey'=>'refund-empty','reason'=>''])));
$second=$wallet->debit(array_replace($request,['orderNo'=>'TJ-PROBE-2','idempotencyKey'=>'cloud:TJ-PROBE-2:debit:1']));
$db->table('v2_user')->where('id',1)->update(['banned'=>1]);
$before=(int)$db->table('v2_user')->value('balance');
$refund=$wallet->refund(['debitId'=>$second['id'],'idempotencyKey'=>'refund-valid','reason'=>'verified unfulfilled order']);
$check('banned customer still receives original refund', (int)$db->table('v2_user')->value('balance')===$before+1000 && $refund['userId']===1);
$check('different refund key cannot credit original debit again', $reject(fn()=> $wallet->refund(['debitId'=>$second['id'],'idempotencyKey'=>'refund-other','reason'=>'retry'])));
$db->table('v2_user')->where('id',1)->update(['banned'=>0]);
$before=(int)$db->table('v2_user')->value('balance');
$db->statement("CREATE TRIGGER reject_new_ledger BEFORE INSERT ON v2_cloud_wallet_ledger BEGIN SELECT RAISE(ABORT,'injected ledger write failure'); END");
$failed=false;
try {$wallet->debit(array_replace($request,['orderNo'=>'TJ-PROBE-3','idempotencyKey'=>'fault-key']));} catch(Throwable) {$failed=true;}
$check('ledger insert failure rolls back balance and transaction', $failed && (int)$db->table('v2_user')->value('balance')===$before && $db->transactionLevel()===0);
$db->statement('DROP TRIGGER reject_new_ledger');
$check('fractional cents rejected', $reject(fn()=> $wallet->debit(array_replace($request,['amountCents'=>1.5]))));
$check('other currency rejected', $reject(fn()=> $wallet->debit(array_replace($request,['currency'=>'USD']))));
$db->insert('INSERT INTO v2_user VALUES (2,100,0)');
$decline=array_replace($request,['userId'=>2,'orderNo'=>'TJ-DECLINE','idempotencyKey'=>'decline-once','amountCents'=>400]);
$check('insufficient attempt has stable business rejection', $reject(fn()=> $wallet->debit($decline)));
$db->table('v2_user')->where('id',2)->increment('balance',1000); // isolated fixture top-up
$check('delayed rejected key cannot debit after top-up', $reject(fn()=> $wallet->debit($decline)) && (int)$db->table('v2_user')->where('id',2)->value('balance')===1100);
$retry=$wallet->debit(array_replace($decline,['idempotencyKey'=>'decline-new-attempt']));
$check('explicit new attempt can pay after top-up', $retry['amountCents']===400 && (int)$db->table('v2_user')->where('id',2)->value('balance')===700);
$check('late old rejection remains rejected after new successful attempt', $reject(fn()=> $wallet->debit($decline)) && (int)$db->table('v2_user')->where('id',2)->value('balance')===700);
$blocked=false;
try {
    $row=(array)$db->table('v2_cloud_wallet_ledger')->where('id',(int)$first['id'])->first();
    unset($row['id']);$row['idempotency_key']='direct-sql-other-key';
    $db->table('v2_cloud_wallet_ledger')->insert($row);
} catch (Illuminate\Database\QueryException) {$blocked=true;}
$check('database unique constraint rejects second debit for same order', $blocked);
$check('ledger migration cannot roll back committed financial entries', (function()use($migration){try{$migration->down();return false;}catch(RuntimeException){return true;}})());
echo json_encode(['passed'=>count(array_filter($results)),'total'=>count($results),'checks'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit(in_array(false,$results,true)?1:0);
