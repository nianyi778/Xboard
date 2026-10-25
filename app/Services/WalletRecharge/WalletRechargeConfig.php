<?php

namespace App\Services\WalletRecharge;

use App\Exceptions\WalletRechargeException;

/**
 * 充值配置快照。所有金额是整数分；这里不做浮点运算。
 *
 * 默认关闭：enabled=false、金额上下限为空。只有开关、上下限以及支付凭据
 * 全部就绪时才报告“可创建新单”。回调验签只依赖支付凭据，不受 enabled 影响。
 */
final class WalletRechargeConfig
{
    public const CURRENCY = 'CNY';
    public const TIMEZONE = 'Asia/Shanghai';
    public const ABSOLUTE_MAX_CENTS = 2147483647;

    public function __construct(
        public readonly bool $enabled,
        public readonly ?int $minCents,
        public readonly ?int $maxCents,
        public readonly int $feeBps,
        public readonly int $feeFixedCents,
        public readonly string $feeBearer,
        public readonly string $payBaseUrl,
        public readonly string $payAppId,
        public readonly string $paySecretFile,
        public readonly string $notifyUrl,
        public readonly string $panelUrl,
        public readonly int $payTimeoutSeconds,
        public readonly int $expiresMinutes,
        public readonly int $callbackClockSkewSeconds,
        public readonly array $returnPathPrefixes,
    ) {
    }

    public static function fromConfig(): self
    {
        $min = self::nullableInt(config('wallet_recharge.min_amount_cents'));
        $max = self::nullableInt(config('wallet_recharge.max_amount_cents'));
        $feeBps = max(0, min(10000, (int) config('wallet_recharge.fee_bps', 0)));
        $feeFixed = max(0, (int) config('wallet_recharge.fee_fixed_cents', 0));
        $bearer = strtolower(trim((string) config('wallet_recharge.fee_bearer', 'customer')));
        if (!in_array($bearer, ['customer', 'platform'], true)) {
            $bearer = 'customer';
        }

        return new self(
            (bool) config('wallet_recharge.enabled', false),
            $min,
            $max,
            $feeBps,
            $feeFixed,
            $bearer,
            rtrim(trim((string) config('wallet_recharge.pay.base_url', '')), '/'),
            trim((string) config('wallet_recharge.pay.app_id', '')),
            trim((string) config('wallet_recharge.pay.secret_file', '')),
            trim((string) config('wallet_recharge.pay.notify_url', '')),
            rtrim(trim((string) config('wallet_recharge.pay.panel_url', '')), '/'),
            max(1, min(30, (int) config('wallet_recharge.pay.timeout_seconds', 10))),
            max(30, min(120, (int) config('wallet_recharge.pay.expires_minutes', 40))),
            max(60, min(900, (int) config('wallet_recharge.callback_clock_skew_seconds', 900))),
            array_values(array_filter(array_map('strval', (array) config('wallet_recharge.return_path_prefixes', [])))),
        );
    }

    /** 创建新单/新付款尝试是否可用。关闭或配置缺失时返回原因码。 */
    public function collectionBlockReason(): ?string
    {
        if (!$this->enabled) {
            return 'disabled';
        }
        if ($this->minCents === null || $this->maxCents === null
            || $this->minCents < 1 || $this->maxCents < $this->minCents
            || $this->maxCents > self::ABSOLUTE_MAX_CENTS) {
            return 'limits_missing';
        }
        if ($this->payBaseUrl === '' || $this->payAppId === '' || $this->notifyUrl === ''
            || $this->panelUrl === '') {
            return 'pay_not_configured';
        }
        if ($this->secret() === null) {
            return 'pay_not_configured';
        }

        return null;
    }

    public function collectionAvailable(): bool
    {
        return $this->collectionBlockReason() === null;
    }

    /**
     * 支付方 app secret 只从独立文件读取。缺失/过短返回 null，调用方必须拒绝。
     */
    public function secret(): ?string
    {
        if ($this->paySecretFile === '' || !is_file($this->paySecretFile) || !is_readable($this->paySecretFile)) {
            return null;
        }
        $raw = @file_get_contents($this->paySecretFile);
        if ($raw === false) {
            return null;
        }
        $secret = trim($raw);
        if (strlen($secret) < 32) {
            return null;
        }

        return $secret;
    }

    public function assertCollectionAvailable(): void
    {
        $reason = $this->collectionBlockReason();
        if ($reason !== null) {
            throw WalletRechargeException::disabled($reason);
        }
    }

    /** 手续费：四舍五入到分，全程整数。 */
    public function feeFor(int $principalCents): int
    {
        $bpsFee = intdiv($principalCents * $this->feeBps + 5000, 10000);
        $fee = $bpsFee + $this->feeFixedCents;
        if (!is_int($fee) || $fee < 0) {
            throw WalletRechargeException::invalidRequest('fee overflow');
        }
        if ($fee > self::ABSOLUTE_MAX_CENTS) {
            throw WalletRechargeException::invalidRequest('fee exceeds limit');
        }

        return $fee;
    }

    public function payableFor(int $principalCents): int
    {
        $fee = $this->feeFor($principalCents);
        $payable = $this->feeBearer === 'customer' ? $principalCents + $fee : $principalCents;
        if ($payable > self::ABSOLUTE_MAX_CENTS) {
            throw WalletRechargeException::invalidRequest('payment amount exceeds limit');
        }

        return $payable;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,12}$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
