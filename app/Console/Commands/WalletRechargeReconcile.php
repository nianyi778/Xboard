<?php

namespace App\Console\Commands;

use App\Services\WalletRecharge\WalletRechargeConfig;
use App\Services\WalletRecharge\WalletRechargeService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class WalletRechargeReconcile extends Command
{
    protected $signature = 'wallet-recharge:reconcile {--day= : Asia/Shanghai 自然日，格式 YYYY-MM-DD}';

    protected $description = '输出余额充值的脱敏日对账（已核验付款、入账、差异、异常、长期 pending）';

    public function handle(WalletRechargeService $recharge): int
    {
        $day = (string) ($this->option('day') ?: (new DateTimeImmutable('now', new DateTimeZone(WalletRechargeConfig::TIMEZONE)))->format('Y-m-d'));
        try {
            $report = $recharge->reconcileDay($day);
        } catch (Throwable $e) {
            $this->error('对账失败：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
