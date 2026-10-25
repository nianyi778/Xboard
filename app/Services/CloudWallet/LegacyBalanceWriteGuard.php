<?php

namespace App\Services\CloudWallet;

use App\Support\CloudWalletResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** The legacy absolute-balance editor has neither a movement ledger nor CAS. */
final class LegacyBalanceWriteGuard
{
    public static function reject(array $params): ?JsonResponse
    {
        if (!array_key_exists('balance', $params)) {
            return null;
        }
        try {
            $active = (bool) config('cloud_wallet.enabled', false);
            // Turning off the API must not reopen an unsafe write path while
            // financial history or unsettled transactions still exist.
            if (!$active && Schema::hasTable('v2_cloud_wallet_ledger')) {
                $active = DB::table('v2_cloud_wallet_ledger')->exists();
            }
            if (!$active) {
                return null;
            }
            return CloudWalletResponse::fail(409, 'manual_balance_write_disabled',
                '统一钱包已接入，禁止直接覆盖余额；请使用有流水和审计的充值或原路退款流程');
        } catch (Throwable) {
            return CloudWalletResponse::fail(503, 'wallet_unavailable', '无法确认钱包状态，余额修改已拒绝');
        }
    }
}
