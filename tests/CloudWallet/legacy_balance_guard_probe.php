<?php

declare(strict_types=1);
require __DIR__ . '/Support.php';

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\UserUpdate;
use App\Services\CloudWallet\LegacyBalanceWriteGuard;
use Illuminate\Support\Facades\DB;

$boot = cw_boot();
try {
    cw_init_database();
    cw_seed_user(1, 1000);
    config(['cloud_wallet.enabled' => true]);
    $request = new class extends UserUpdate {
        public function validated($key = null, $default = null) { return ['id' => 1, 'balance' => 99]; }
    };
    $response = (new UserController())->update($request);
    cw_assert_same(409, $response->getStatusCode(), 'actual admin controller rejects legacy absolute wallet overwrite');
    cw_assert_same(1000, (int) DB::table('v2_user')->where('id', 1)->value('balance'), 'rejected admin write does not change balance');
    cw_assert_same(null, LegacyBalanceWriteGuard::reject(['email' => 'other@example.invalid']), 'non-balance fields pass guard');
    cw_assert_same(null, LegacyBalanceWriteGuard::reject(['commission_balance' => 12]), 'commission remains outside wallet guard');
    cw_assert_same(409, LegacyBalanceWriteGuard::reject(['balance' => null])->getStatusCode(), 'null cannot bypass balance-field guard');
    config(['cloud_wallet.enabled' => false]);
    cw_assert_same(null, LegacyBalanceWriteGuard::reject(['balance' => 99]), 'never-enabled empty wallet preserves legacy behavior');
    config(['cloud_wallet.enabled' => true]);
    cw_assert_success(cw_send($boot['app'], cw_path('debit'), [
        'userId' => 1, 'orderNo' => 'guard-test', 'idempotencyKey' => 'guard-debit',
        'amountCents' => 100, 'currency' => 'CNY',
    ]), 'wallet debit creates financial history');
    config(['cloud_wallet.enabled' => false]);
    cw_assert_same(409, LegacyBalanceWriteGuard::reject(['balance' => 99])->getStatusCode(), 'API off cannot reopen unsafe editor after ledger exists');
    cw_assert_same(900, (int) DB::table('v2_user')->where('id', 1)->value('balance'), 'wallet debit survives rejected legacy editor');
    echo "legacy balance guard probe: ALL PASS\n";
} finally { cw_cleanup($boot); }
