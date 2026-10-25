<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__ . '/Support.php';

$boot = wr_boot();
wr_init_database();
$app = $boot['app'];

$payments = [];
$outToPayment = [];
wr_fake_pay(function ($request) use (&$payments, &$outToPayment) {
    $url = $request->url();
    if ($request->method() === 'GET' && preg_match('~/api/payments/([A-Za-z0-9_-]+)$~', $url, $m)) {
        $payment = $payments[$m[1]] ?? null;
        return $payment ? wr_pay_json($payment) : wr_pay_fail('missing', 404);
    }
    if ($request->method() === 'POST' && str_ends_with($url, '/api/payments')) {
        $body = $request->data();
        $outTradeNo = (string) ($body['out_trade_no'] ?? '');
        $paymentId = $outToPayment[$outTradeNo] ?? ('pay_' . substr(hash('sha256', $outTradeNo), 0, 20));
        $outToPayment[$outTradeNo] = $paymentId;
        $payments[$paymentId] = $payments[$paymentId] ?? [
            'payment_id' => $paymentId,
            'out_trade_no' => $outTradeNo,
            'status' => 'pending',
            'amount' => (int) ($body['amount'] ?? 0),
            'currency' => 'CNY',
            'paid_at' => null,
            'checkout_url' => 'http://127.0.0.1:9/checkout/' . $paymentId,
        ];
        return wr_pay_json(['payment_id' => $paymentId, 'checkout_url' => $payments[$paymentId]['checkout_url']]);
    }
    return wr_pay_fail('unexpected', 400);
});

function ready_order(int $userId, int $amount, string $key): array
{
    $created = wr_service()->createOrder(wr_user($userId), ['amountCents' => $amount, 'idempotencyKey' => $key]);
    wr_service()->startPayment(wr_user($userId), $created['orderNo']);
    $attempt = wr_attempt((string) $created['orderNo']);
    return [
        'order_no' => $created['orderNo'],
        'out_trade_no' => (string) $attempt->out_trade_no,
        'payment_id' => (string) $attempt->payment_id,
        'amount' => (int) $attempt->payable_cents,
    ];
}

function spawn_jobs(array $jobs, array $boot): array
{
    $dir = sys_get_temp_dir() . '/wr-concurrency-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('failed to create concurrency dir');
    }
    $startFile = $dir . '/start';
    $startAt = microtime(true) + 60;
    $workers = [];

    foreach ($jobs as $i => $job) {
        $job['database'] = $boot['database'];
        $job['keyFile'] = $boot['keyFile'];
        $job['cloudKeyFile'] = $boot['cloudKeyFile'];
        $job['ready_file'] = $dir . '/ready-' . $i;
        $job['result_file'] = $dir . '/result-' . $i . '.json';
        $job['start_file'] = $startFile;
        $job['start_at'] = $startAt;
        $jobFile = $dir . '/job-' . $i . '.json';
        file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $log = $dir . '/log-' . $i;
        $cmd = escapeshellarg(PHP_BINARY)
            . ' -n -d extension_dir=' . escapeshellarg((string) ini_get('extension_dir'))
            . ' -d extension=pdo_sqlite '
            . escapeshellarg(__DIR__ . '/recharge_child_worker.php') . ' '
            . escapeshellarg($jobFile);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('failed to start worker ' . $i);
        }
        $workers[$i] = ['proc' => $proc, 'log' => $log, 'result' => $job['result_file']];
    }

    $deadline = microtime(true) + 60;
    foreach ($jobs as $i => $_) {
        while (!is_file($dir . '/ready-' . $i)) {
            if (microtime(true) > $deadline) {
                foreach ($workers as $w) {
                    echo file_get_contents($w['log']);
                }
                throw new RuntimeException('worker ready timeout ' . $i);
            }
            usleep(10_000);
        }
    }
    touch($startFile);

    $results = [];
    foreach ($workers as $i => $worker) {
        $status = proc_close($worker['proc']);
        $raw = is_file($worker['result']) ? (string) file_get_contents($worker['result']) : '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            echo file_get_contents($worker['log']);
            throw new RuntimeException('worker result missing ' . $i . ' exit=' . $status);
        }
        $decoded['exit'] = $status;
        $results[$i] = $decoded;
    }

    return ['results' => $results, 'dir' => $dir];
}

function callback_job(int $userId, array $order, string $txn): array
{
    return [
        'action' => 'callback',
        'user_id' => $userId,
        'payload' => wr_callback_payload($order['out_trade_no'], $order['amount'], $txn),
        'payment' => [
            'payment_id' => $order['payment_id'],
            'out_trade_no' => $order['out_trade_no'],
            'amount' => $order['amount'],
            'status' => 'paid',
        ],
    ];
}

wr_seed_user(2001, 1000);
wr_seed_user(2002, 2000);
wr_seed_user(2003, 3000);
wr_seed_user(2004, 1000);

echo "== 多进程重复回调：同一订单只入账一次 ==\n";
$orderA = ready_order(2001, 500, 'concurrent-dup-callback');
$jobs = [];
for ($i = 0; $i < 8; $i++) {
    $jobs[] = callback_job(2001, $orderA, 'ch_dup_concurrent');
}
$run = spawn_jobs($jobs, $boot);
$credited = 0;
foreach ($run['results'] as $r) {
    wr_assert($r['ok'] === true, '重复回调子进程成功: ' . json_encode($r['state']['state'] ?? $r['code']));
    if (($r['state']['state'] ?? null) === 'credited') $credited++;
}
wr_assert_same(1, $credited, '8 个并发回调中恰好一个 credited');
wr_assert_same(1500, wr_balance(2001), '重复回调后余额只增加一次');
wr_assert_same(1, (int) DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderA['order_no'])->count(), '重复回调只有一条流水');

echo "== 多进程回调与查单同时运行：仍只入账一次 ==\n";
$orderB = ready_order(2002, 700, 'concurrent-refresh');
$jobs = [];
for ($i = 0; $i < 4; $i++) $jobs[] = callback_job(2002, $orderB, 'ch_refresh_race');
for ($i = 0; $i < 4; $i++) {
    $jobs[] = [
        'action' => 'refresh',
        'user_id' => 2002,
        'order_no' => $orderB['order_no'],
        'payment' => [
            'payment_id' => $orderB['payment_id'],
            'out_trade_no' => $orderB['out_trade_no'],
            'amount' => $orderB['amount'],
            'status' => 'paid',
        ],
    ];
}
$run = spawn_jobs($jobs, $boot);
foreach ($run['results'] as $r) wr_assert($r['ok'] === true, '回调/查单子进程成功: ' . ($r['code'] ?? $r['state']['status'] ?? ''));
wr_assert_same(2700, wr_balance(2002), '回调与查单并发只增加一次');
wr_assert_same(1, (int) DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderB['order_no'])->count(), '回调与查单并发只有一条流水');

echo "== 两笔充值同时到同一用户：不丢更新 ==\n";
$orderC1 = ready_order(2003, 400, 'concurrent-two-orders-1');
$orderC2 = ready_order(2003, 600, 'concurrent-two-orders-2');
$jobs = [];
for ($i = 0; $i < 4; $i++) $jobs[] = callback_job(2003, $orderC1, 'ch_order_c1');
for ($i = 0; $i < 4; $i++) $jobs[] = callback_job(2003, $orderC2, 'ch_order_c2');
$run = spawn_jobs($jobs, $boot);
foreach ($run['results'] as $r) wr_assert($r['ok'] === true, '两单并发子进程成功: ' . ($r['code'] ?? ''));
wr_assert_same(4000, wr_balance(2003), '两笔充值并发最终余额 3000+400+600');
wr_assert_same(2, (int) DB::table('v2_wallet_recharge_ledger')->whereIn('order_no', [$orderC1['order_no'], $orderC2['order_no']])->count(), '两笔充值各一条流水');

echo "== 充值入账与云扣款同时发生：不丢更新、不负余额 ==\n";
$orderD = ready_order(2004, 700, 'concurrent-cloud-debit');
$jobs = [];
for ($i = 0; $i < 4; $i++) $jobs[] = callback_job(2004, $orderD, 'ch_recharge_vs_debit');
for ($i = 0; $i < 4; $i++) {
    $jobs[] = [
        'action' => 'cloud_debit',
        'user_id' => 2004,
        'order_no' => 'TJ-CONCURRENT-DEBIT',
        'idempotency_key' => 'cloud:concurrent:debit:1',
        'amount_cents' => 300,
        'payment' => [
            'payment_id' => $orderD['payment_id'],
            'out_trade_no' => $orderD['out_trade_no'],
            'amount' => $orderD['amount'],
            'status' => 'paid',
        ],
    ];
}
$run = spawn_jobs($jobs, $boot);
$debits = 0;
foreach ($run['results'] as $r) {
    wr_assert($r['ok'] === true, '充值/扣款并发子进程成功: ' . ($r['code'] ?? ''));
    if ($r['action'] === 'cloud_debit' && $r['ok']) $debits++;
}
wr_assert($debits >= 1, '至少一个云扣款成功');
wr_assert_same(1400, wr_balance(2004), '并发后余额 = 1000 + 700 - 300');
wr_assert(DB::table('v2_user')->where('id', 2004)->value('balance') >= 0, '余额不为负');
wr_assert_same(1, (int) DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderD['order_no'])->count(), '充值并发只一条流水');
wr_assert_same(1, (int) DB::table('v2_cloud_wallet_ledger')->where('order_no', 'TJ-CONCURRENT-DEBIT')->where('kind', 'debit')->count(), '云扣款并发只一条流水');

$integrity = DB::select('PRAGMA integrity_check');
wr_assert_same('ok', strtolower((string) ($integrity[0]->integrity_check ?? '')), 'SQLite integrity_check = ok');

wr_cleanup($boot);
echo "ALL RECHARGE CONCURRENCY PROBES PASSED\n";
