<?php

namespace App\Services\WalletRecharge;

use App\Exceptions\WalletRechargeException;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 平台余额主动充值 P1。
 *
 * 资金事实：
 *   · 余额唯一主账仍是 v2_user.balance，本服务只做“已核验付款 → 一次入账”。
 *   · 订单、付款尝试、入账流水和异常队列各自持久化，金额全部为整数分。
 *   · 浏览器 return、前端成功字段和 HTTP 200 都不是付款证据；只有支付方签名 +
 *     服务端查单确认（订单号、金额、币种、状态）才进入入账事务。
 */
final class WalletRechargeService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAYING = 'paying';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVIEW = 'review';

    public const ATTEMPT_CREATING = 'creating';
    public const ATTEMPT_CREATED = 'created';
    public const ATTEMPT_UNKNOWN = 'unknown';
    public const ATTEMPT_PAID = 'paid';
    public const ATTEMPT_EXPIRED = 'expired';
    public const ATTEMPT_FAILED = 'failed';
    public const ATTEMPT_DUPLICATE = 'duplicate';

    private const MAX_BALANCE = 2147483647;
    private const IDEMPOTENCY_RE = '/^[A-Za-z0-9._:-]{8,128}$/';
    private const ORDER_NO_RE = '/^[A-Za-z0-9_-]{8,64}$/';

    private WalletRechargeConfig $cfg;
    private WalletRechargePayClient $pay;

    public function __construct(?WalletRechargeConfig $cfg = null, ?WalletRechargePayClient $pay = null)
    {
        $this->cfg = $cfg ?? WalletRechargeConfig::fromConfig();
        $this->pay = $pay ?? new WalletRechargePayClient($this->cfg);
    }

    public function capability(User $user): array
    {
        $fresh = DB::table('v2_user')->where('id', (int) $user->id)->first(['id', 'balance', 'banned']);
        $reason = $this->cfg->collectionBlockReason();
        if ($fresh && (bool) $fresh->banned) {
            $reason = 'user_banned';
        }

        return [
            'enabled' => $reason === null,
            'reason' => $reason,
            'minCents' => $this->cfg->minCents,
            'maxCents' => $this->cfg->maxCents,
            'feeBps' => $this->cfg->feeBps,
            'feeFixedCents' => $this->cfg->feeFixedCents,
            'feeBearer' => $this->cfg->feeBearer,
            'currency' => WalletRechargeConfig::CURRENCY,
            'timezone' => WalletRechargeConfig::TIMEZONE,
            'balanceCents' => $fresh ? (int) $fresh->balance : null,
        ];
    }

    public function createOrder(User $user, array $payload): array
    {
        $userId = (int) $user->id;
        $this->assertUserCanStart($userId);
        $this->cfg->assertCollectionAvailable();

        $amountCents = $this->positiveInt($payload['amountCents'] ?? null, 'amountCents');
        $idempotencyKey = $this->idempotencyKey($payload['idempotencyKey'] ?? null);
        $returnPath = $this->returnPath($payload['returnPath'] ?? null);
        $requestHash = $this->requestHash($userId, $amountCents, $returnPath);

        $existing = DB::table('v2_wallet_recharge_orders')
            ->where('user_id', $userId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->replayCreate($existing, $requestHash);
        }

        $this->assertAmountInRange($amountCents);
        $feeCents = $this->cfg->feeFor($amountCents);
        $payableCents = $this->cfg->payableFor($amountCents);
        $now = time();
        $orderNo = 'WR' . gmdate('Ymd', $now) . bin2hex(random_bytes(8));

        try {
            return WalletRechargeTransaction::run(function () use (
                $userId, $orderNo, $amountCents, $feeCents, $payableCents, $idempotencyKey, $requestHash, $returnPath, $now
            ): array {
                $raced = DB::table('v2_wallet_recharge_orders')
                    ->where('user_id', $userId)->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($raced) {
                    return $this->replayCreate($raced, $requestHash);
                }

                DB::table('v2_wallet_recharge_orders')->insert([
                    'order_no' => $orderNo,
                    'user_id' => $userId,
                    'amount_cents' => $amountCents,
                    'fee_cents' => $feeCents,
                    'payable_cents' => $payableCents,
                    'currency' => WalletRechargeConfig::CURRENCY,
                    'status' => self::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'return_path' => $returnPath,
                    'payment_provider' => 'tianji-pay',
                    'expires_at' => $now + $this->cfg->expiresMinutes * 60,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $order = DB::table('v2_wallet_recharge_orders')->where('order_no', $orderNo)->first();

                return $this->orderView($order);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('v2_wallet_recharge_orders')
                ->where('user_id', $userId)->where('idempotency_key', $idempotencyKey)->first();
            if (!$existing) {
                throw WalletRechargeException::busy();
            }

            return $this->replayCreate($existing, $requestHash);
        }
    }

    public function listOrders(User $user, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows = DB::table('v2_wallet_recharge_orders')
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row): array => $this->orderView($row))->all();
    }

    public function getOrder(User $user, string $orderNo): array
    {
        $order = $this->loadOwnOrder((int) $user->id, $orderNo);
        $attempts = DB::table('v2_wallet_recharge_attempts')
            ->where('order_id', (int) $order->id)
            ->orderByDesc('attempt_no')
            ->get()
            ->map(fn (object $a): array => [
                'attemptNo' => (int) $a->attempt_no,
                'status' => (string) $a->status,
                'amountCents' => (int) $a->amount_cents,
                'feeCents' => (int) $a->fee_cents,
                'payableCents' => (int) $a->payable_cents,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $a->created_at),
                'verifiedAt' => $a->verified_at === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $a->verified_at),
            ])->all();

        return $this->orderView($order, $attempts);
    }

    /**
     * 创建/继续当前付款尝试。P1 默认一单一个活动尝试；只有上游明确确认旧尝试
     * 已 expired/failed 后才允许重建，结果未知时阻止重建。
     */
    public function startPayment(User $user, string $orderNo): array
    {
        $this->cfg->assertCollectionAvailable();
        $userId = (int) $user->id;
        $this->assertUserCanStart($userId);
        $order = $this->loadOwnOrder($userId, $orderNo);

        if ($order->status === self::STATUS_PAID) {
            return ['state' => 'paid', 'orderNo' => (string) $order->order_no];
        }
        if (in_array($order->status, [self::STATUS_REVIEW, self::STATUS_CANCELLED, self::STATUS_FAILED], true)) {
            throw WalletRechargeException::stateConflict('当前充值订单状态不允许继续支付');
        }

        $attempt = $this->latestAttempt((int) $order->id);
        if ($attempt && in_array($attempt->status, [self::ATTEMPT_CREATING, self::ATTEMPT_CREATED, self::ATTEMPT_UNKNOWN], true)) {
            if ($attempt->payment_id === null) {
                return $this->createUpstreamPayment($order, $attempt);
            }

            $upstream = $this->pay->getPayment((string) $attempt->payment_id);
            $this->assertUpstreamMatches($attempt, $upstream);
            if ($upstream['status'] === 'paid') {
                $this->completeVerifiedPayment($attempt, $upstream, null, 'query');

                return ['state' => 'paid', 'orderNo' => (string) $order->order_no];
            }
            if (in_array($upstream['status'], ['expired', 'failed'], true)) {
                $this->closeAttempt((int) $attempt->id, $upstream['status'] === 'expired' ? self::ATTEMPT_EXPIRED : self::ATTEMPT_FAILED, false);
                $attempt = null;
                $order = $this->loadOwnOrder($userId, $orderNo);
                if ($order->status === self::STATUS_PAID) {
                    return ['state' => 'paid', 'orderNo' => (string) $order->order_no];
                }
            } elseif ($upstream['status'] === 'pending') {
                if ($attempt->checkout_url) {
                    return $this->paymentView($order, $attempt);
                }
                throw WalletRechargeException::paymentUnknown();
            } else {
                throw WalletRechargeException::stateConflict('支付单状态需要人工核实');
            }
        }

        $attempt = $this->latestAttempt((int) $order->id);
        if ($attempt && !in_array($attempt->status, [self::ATTEMPT_EXPIRED, self::ATTEMPT_FAILED], true)) {
            throw WalletRechargeException::stateConflict('上一笔付款状态尚未确认，不能重建付款');
        }

        $newAttempt = $this->reserveAttempt($order);
        $order = $this->loadOwnOrder($userId, $orderNo);

        return $this->createUpstreamPayment($order, $newAttempt);
    }

    /** 主动查单补账；浏览器返回不直接入账。 */
    public function refreshOrder(User $user, string $orderNo): array
    {
        $userId = (int) $user->id;
        $order = $this->loadOwnOrder($userId, $orderNo);
        $attempt = $this->latestAttempt((int) $order->id);
        if (!$attempt || $attempt->payment_id === null || $order->status === self::STATUS_PAID) {
            return $this->orderView($order);
        }
        if (!in_array($attempt->status, [
            self::ATTEMPT_CREATING, self::ATTEMPT_CREATED, self::ATTEMPT_UNKNOWN,
        ], true)) {
            return $this->orderView($order);
        }

        $upstream = $this->pay->getPayment((string) $attempt->payment_id);
        $this->assertUpstreamMatches($attempt, $upstream);

        if ($upstream['status'] === 'paid') {
            $this->completeVerifiedPayment($attempt, $upstream, null, 'query');
        } elseif (in_array($upstream['status'], ['expired', 'failed'], true)) {
            $this->closeAttempt((int) $attempt->id, $upstream['status'] === 'expired' ? self::ATTEMPT_EXPIRED : self::ATTEMPT_FAILED, true);
        } elseif ($upstream['status'] !== 'pending') {
            throw WalletRechargeException::stateConflict('支付单状态需要人工核实');
        }

        return $this->getOrder($user, $orderNo);
    }

    /**
     * 支付方回调。
     *
     * @return array{handled:bool,credited:bool,state:string,orderNo:string}
     */
    public function handleNotify(array $payload): array
    {
        $now = time();
        $this->verifyNotifySignature($payload, $now);

        $outTradeNo = $this->stringValue($payload['orderNo'] ?? null, 'orderNo', 64);
        $amountCents = $this->positiveInt($payload['amountCents'] ?? null, 'amountCents');
        $txnId = $this->stringValue($payload['txnId'] ?? null, 'txnId', 128);
        $method = $this->stringValue($payload['method'] ?? null, 'method', 32);

        $attempt = DB::table('v2_wallet_recharge_attempts')->where('out_trade_no', $outTradeNo)->first();
        if (!$attempt) {
            throw WalletRechargeException::notFound();
        }
        $order = DB::table('v2_wallet_recharge_orders')->where('id', (int) $attempt->order_id)->first();
        if (!$order) {
            throw WalletRechargeException::notFound();
        }

        if ($attempt->payment_id === null) {
            throw WalletRechargeException::verificationFailed('付款尝试没有上游支付单号');
        }
        if ((int) $attempt->payable_cents !== $amountCents) {
            throw WalletRechargeException::verificationFailed('回调金额与订单快照不一致');
        }
        if ((string) $attempt->currency !== WalletRechargeConfig::CURRENCY) {
            throw WalletRechargeException::verificationFailed('订单币种无效');
        }

        // 回调内容只是触发信号；真正证据来自服务端带签名查单。
        $upstream = $this->pay->getPayment((string) $attempt->payment_id);
        $this->assertUpstreamMatches($attempt, $upstream);
        if ($upstream['status'] !== 'paid') {
            throw WalletRechargeException::paymentUnknown('支付方尚未确认成功');
        }

        $result = $this->completeVerifiedPayment($attempt, $upstream, $txnId, 'notify:' . $method);
        $result['orderNo'] = (string) $order->order_no;

        return $result;
    }

    public function reconcileDay(string $day): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw WalletRechargeException::invalidRequest('day must be YYYY-MM-DD');
        }
        $tz = new DateTimeZone(WalletRechargeConfig::TIMEZONE);
        $start = (new DateTimeImmutable($day . ' 00:00:00', $tz))->getTimestamp();
        $end = (new DateTimeImmutable($day . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
        $longPendingBefore = time() - 3600;

        $verifiedRows = DB::table('v2_wallet_recharge_attempts as a')
            ->join('v2_wallet_recharge_orders as o', 'o.id', '=', 'a.order_id')
            ->whereNotNull('a.verified_at')
            ->where('a.verified_at', '>=', $start)->where('a.verified_at', '<', $end)
            ->get(['a.id', 'a.order_id', 'a.out_trade_no', 'a.amount_cents', 'a.status', 'a.verified_at', 'o.order_no', 'o.user_id']);
        $creditedRows = DB::table('v2_wallet_recharge_ledger')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get(['id', 'recharge_order_id', 'order_no', 'user_id', 'amount_cents', 'fee_cents', 'created_at']);

        $creditedByOrder = [];
        foreach ($creditedRows as $row) {
            $creditedByOrder[(int) $row->recharge_order_id] = (int) $row->amount_cents;
        }

        $differences = [];
        foreach ($verifiedRows as $row) {
            $credited = $creditedByOrder[(int) $row->order_id] ?? 0;
            if ($credited !== (int) $row->amount_cents) {
                $differences[] = [
                    'orderNo' => (string) $row->order_no,
                    'userId' => (int) $row->user_id,
                    'outTradeNo' => (string) $row->out_trade_no,
                    'verifiedPrincipalCents' => (int) $row->amount_cents,
                    'creditedCents' => $credited,
                    'attemptStatus' => (string) $row->status,
                ];
            }
        }

        $exceptions = DB::table('v2_wallet_recharge_exceptions')
            ->where('status', 'open')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (object $e): array => [
                'id' => (int) $e->id,
                'kind' => (string) $e->kind,
                'orderNo' => $e->out_trade_no,
                'userId' => $e->user_id === null ? null : (int) $e->user_id,
                'amountCents' => $e->amount_cents === null ? null : (int) $e->amount_cents,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $e->created_at),
            ])->all();

        $longPending = DB::table('v2_wallet_recharge_orders')
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PAYING])
            ->where('created_at', '<', $longPendingBefore)
            ->orderBy('id')
            ->limit(200)
            ->get(['order_no', 'user_id', 'amount_cents', 'status', 'created_at'])
            ->map(fn (object $o): array => [
                'orderNo' => (string) $o->order_no,
                'userId' => (int) $o->user_id,
                'amountCents' => (int) $o->amount_cents,
                'status' => (string) $o->status,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $o->created_at),
            ])->all();

        return [
            'day' => $day,
            'timezone' => WalletRechargeConfig::TIMEZONE,
            'currency' => WalletRechargeConfig::CURRENCY,
            'verifiedPrincipalCents' => array_sum(array_map(fn (object $r): int => (int) $r->amount_cents, $verifiedRows->all())),
            'creditedTotalCents' => array_sum(array_map(fn (object $r): int => (int) $r->amount_cents, $creditedRows->all())),
            'feeTotalCents' => array_sum(array_map(fn (object $r): int => (int) $r->fee_cents, $creditedRows->all())),
            'verifiedAttempts' => $verifiedRows->count(),
            'creditedEntries' => $creditedRows->count(),
            'differenceOrders' => $differences,
            'openExceptions' => $exceptions,
            'longPendingOrders' => $longPending,
        ];
    }

    // ───────────────────────── 入账 ─────────────────────────

    /**
     * @param array{payment_id:string,out_trade_no:string,status:string,amount:int,currency:string,paid_at:?string} $upstream
     */
    private function completeVerifiedPayment(object $attempt, array $upstream, ?string $channelTxnId, string $source): array
    {
        if ((int) $attempt->payable_cents !== (int) $upstream['amount']
            || (string) $attempt->currency !== (string) $upstream['currency']) {
            throw WalletRechargeException::verificationFailed('上游金额/币种与订单快照不一致');
        }

        try {
            return WalletRechargeTransaction::run(function () use ($attempt, $upstream, $channelTxnId, $source): array {
                $order = DB::table('v2_wallet_recharge_orders')
                    ->where('id', (int) $attempt->order_id)->lockForUpdate()->first();
                if (!$order) {
                    throw WalletRechargeException::notFound();
                }
                $lockedAttempt = DB::table('v2_wallet_recharge_attempts')
                    ->where('id', (int) $attempt->id)->lockForUpdate()->first();
                if (!$lockedAttempt) {
                    throw WalletRechargeException::notFound();
                }
                $paymentId = (string) $upstream['payment_id'];

                $ledger = DB::table('v2_wallet_recharge_ledger')
                    ->where('recharge_order_id', (int) $order->id)->lockForUpdate()->first();

                if ($ledger) {
                    $differentPayment = (string) $ledger->upstream_txn_id !== $paymentId;
                    $differentChannel = $channelTxnId !== null && $ledger->channel_txn_id !== null
                        && (string) $ledger->channel_txn_id !== $channelTxnId;
                    if ($differentPayment || $differentChannel) {
                        $this->recordException('duplicate_payment', $order, $lockedAttempt, $channelTxnId, 'open', [
                            'paymentId' => $paymentId,
                            'creditedPaymentId' => (string) $ledger->upstream_txn_id,
                            'source' => $source,
                        ]);
                        DB::table('v2_wallet_recharge_attempts')->where('id', (int) $lockedAttempt->id)->update([
                            'status' => self::ATTEMPT_DUPLICATE,
                            'verified_at' => time(),
                            'channel_txn_id' => $channelTxnId,
                            'updated_at' => time(),
                        ]);

                        return ['handled' => true, 'credited' => false, 'state' => 'duplicate_payment'];
                    }

                    if ($channelTxnId !== null && $ledger->channel_txn_id === null) {
                        DB::table('v2_wallet_recharge_ledger')->where('id', (int) $ledger->id)
                            ->update(['channel_txn_id' => $channelTxnId]);
                        DB::table('v2_wallet_recharge_attempts')->where('id', (int) $lockedAttempt->id)
                            ->update(['channel_txn_id' => $channelTxnId, 'updated_at' => time()]);
                    }

                    return ['handled' => true, 'credited' => false, 'state' => 'already_credited'];
                }

                if ($order->status === self::STATUS_PAID) {
                    // 已标 paid 却没有入账流水是内部一致性问题，冻结等人工，不重复加钱。
                    $this->recordException('paid_without_ledger', $order, $lockedAttempt, $channelTxnId, 'open', [
                        'paymentId' => $paymentId,
                    ]);
                    $this->setOrderReview((int) $order->id);

                    return ['handled' => true, 'credited' => false, 'state' => 'review'];
                }

                if (in_array($order->status, [
                    self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_FAILED, self::STATUS_REVIEW,
                ], true)) {
                    $this->recordException('payment_after_terminal', $order, $lockedAttempt, $channelTxnId, 'open', [
                        'paymentId' => $paymentId,
                        'orderStatus' => (string) $order->status,
                        'source' => $source,
                    ]);
                    $this->setOrderReview((int) $order->id);
                    DB::table('v2_wallet_recharge_attempts')->where('id', (int) $lockedAttempt->id)
                        ->update(['status' => self::ATTEMPT_DUPLICATE, 'verified_at' => time(), 'updated_at' => time()]);

                    return ['handled' => true, 'credited' => false, 'state' => 'review'];
                }

                if ($channelTxnId !== null && $this->channelTxnUsedElsewhere($channelTxnId, (int) $order->id)) {
                    $this->recordException('channel_txn_used_by_other_order', $order, $lockedAttempt, $channelTxnId, 'open', [
                        'paymentId' => $paymentId,
                        'source' => $source,
                    ]);
                    $this->setOrderReview((int) $order->id);

                    return ['handled' => true, 'credited' => false, 'state' => 'review'];
                }

                $user = DB::table('v2_user')->where('id', (int) $order->user_id)->lockForUpdate()->first();
                if (!$user) {
                    $this->recordException('recharge_user_missing', $order, $lockedAttempt, $channelTxnId, 'open', [
                        'paymentId' => $paymentId,
                    ]);
                    $this->setOrderReview((int) $order->id);

                    return ['handled' => true, 'credited' => false, 'state' => 'review'];
                }
                if ((bool) $user->banned) {
                    $this->recordException('recharge_user_banned', $order, $lockedAttempt, $channelTxnId, 'open', [
                        'paymentId' => $paymentId,
                    ]);
                    $this->setOrderReview((int) $order->id);

                    return ['handled' => true, 'credited' => false, 'state' => 'review'];
                }

                $principal = (int) $order->amount_cents;
                $before = (int) $user->balance;
                if ($principal < 1 || $before < 0 || $before > self::MAX_BALANCE - $principal) {
                    $this->recordException('recharge_balance_overflow', $order, $lockedAttempt, $channelTxnId, 'open', [
                        'paymentId' => $paymentId,
                        'balanceBeforeCents' => $before,
                    ]);
                    $this->setOrderReview((int) $order->id);

                    return ['handled' => true, 'credited' => false, 'state' => 'review'];
                }

                $after = $before + $principal;
                $affected = DB::table('v2_user')
                    ->where('id', (int) $order->user_id)
                    ->where('banned', 0)
                    ->where('balance', $before)
                    ->update(['balance' => $after, 'updated_at' => time()]);
                if ($affected !== 1) {
                    throw WalletRechargeException::busy();
                }

                DB::table('v2_wallet_recharge_ledger')->insert([
                    'user_id' => (int) $order->user_id,
                    'recharge_order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'kind' => 'recharge',
                    'source' => 'recharge',
                    'amount_cents' => $principal,
                    'fee_cents' => (int) $order->fee_cents,
                    'currency' => WalletRechargeConfig::CURRENCY,
                    'balance_before_cents' => $before,
                    'balance_after_cents' => $after,
                    'provider' => (string) $order->payment_provider,
                    'upstream_txn_id' => $paymentId,
                    'channel_txn_id' => $channelTxnId,
                    'operator_type' => 'system',
                    'evidence_attempt_id' => (int) $lockedAttempt->id,
                    'created_at' => time(),
                ]);

                DB::table('v2_wallet_recharge_attempts')->where('id', (int) $lockedAttempt->id)->update([
                    'status' => self::ATTEMPT_PAID,
                    'upstream_txn_id' => $paymentId,
                    'channel_txn_id' => $channelTxnId,
                    'verified_at' => time(),
                    'updated_at' => time(),
                ]);
                DB::table('v2_wallet_recharge_orders')->where('id', (int) $order->id)->update([
                    'status' => self::STATUS_PAID,
                    'upstream_payment_id' => $paymentId,
                    'upstream_txn_id' => $paymentId,
                    'paid_at' => time(),
                    'credited_at' => time(),
                    'last_error' => null,
                    'updated_at' => time(),
                ]);

                return ['handled' => true, 'credited' => true, 'state' => 'credited'];
            });
        } catch (UniqueConstraintViolationException) {
            // 并发通知/补单撞上唯一约束：回读入账流水，回放首次结果。
            $ledger = DB::table('v2_wallet_recharge_ledger')
                ->where('recharge_order_id', (int) $attempt->order_id)->first();
            if ($ledger) {
                return ['handled' => true, 'credited' => false, 'state' => 'already_credited'];
            }
            throw WalletRechargeException::busy();
        } catch (WalletRechargeException $e) {
            throw $e;
        } catch (Throwable) {
            throw WalletRechargeException::busy();
        }
    }

    // ───────────────────── 付款尝试与上游 ─────────────────────

    private function reserveAttempt(object $order): object
    {
        return WalletRechargeTransaction::run(function () use ($order): object {
            $locked = DB::table('v2_wallet_recharge_orders')
                ->where('id', (int) $order->id)->lockForUpdate()->first();
            if (!$locked || !in_array($locked->status, [self::STATUS_PENDING, self::STATUS_PAYING], true)) {
                throw WalletRechargeException::stateConflict('订单状态不允许发起支付');
            }
            $last = DB::table('v2_wallet_recharge_attempts')
                ->where('order_id', (int) $locked->id)->orderByDesc('attempt_no')->first();
            if ($last && !in_array($last->status, [self::ATTEMPT_EXPIRED, self::ATTEMPT_FAILED], true)) {
                throw WalletRechargeException::stateConflict('上一笔付款状态尚未确认，不能重建付款');
            }
            $attemptNo = $last ? ((int) $last->attempt_no + 1) : 1;
            $outTradeNo = $attemptNo === 1
                ? (string) $locked->order_no
                : substr((string) $locked->order_no, 0, 45) . 'R' . $attemptNo;
            $now = time();

            DB::table('v2_wallet_recharge_attempts')->insert([
                'order_id' => (int) $locked->id,
                'attempt_no' => $attemptNo,
                'out_trade_no' => $outTradeNo,
                'provider' => (string) $locked->payment_provider,
                'payment_id' => null,
                'checkout_url' => null,
                'amount_cents' => (int) $locked->amount_cents,
                'fee_cents' => (int) $locked->fee_cents,
                'payable_cents' => (int) $locked->payable_cents,
                'currency' => (string) $locked->currency,
                'status' => self::ATTEMPT_CREATING,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('v2_wallet_recharge_orders')->where('id', (int) $locked->id)->update([
                'status' => self::STATUS_PAYING,
                'updated_at' => $now,
            ]);

            return DB::table('v2_wallet_recharge_attempts')
                ->where('order_id', (int) $locked->id)->where('attempt_no', $attemptNo)->first();
        });
    }

    private function createUpstreamPayment(object $order, object $attempt): array
    {
        $returnUrl = $this->cfg->panelUrl . '/wallet/recharge?order=' . rawurlencode((string) $order->order_no);
        try {
            $created = $this->pay->createPayment(
                (string) $attempt->out_trade_no,
                (int) $attempt->payable_cents,
                '平台余额充值',
                $returnUrl
            );
        } catch (WalletRechargeException $e) {
            $status = $e->errorCode() === 'payment_unknown' ? self::ATTEMPT_UNKNOWN : self::ATTEMPT_FAILED;
            WalletRechargeTransaction::run(function () use ($attempt, $status, $e): void {
                DB::table('v2_wallet_recharge_attempts')->where('id', (int) $attempt->id)->update([
                    'status' => $status,
                    'last_error' => $e->errorCode(),
                    'updated_at' => time(),
                ]);
                if ($status === self::ATTEMPT_FAILED) {
                    DB::table('v2_wallet_recharge_orders')->where('id', (int) $attempt->order_id)->update([
                        'status' => self::STATUS_FAILED,
                        'last_error' => $e->errorCode(),
                        'updated_at' => time(),
                    ]);
                }
            });
            throw $e;
        }

        $now = time();
        WalletRechargeTransaction::run(function () use ($attempt, $created, $now): void {
            DB::table('v2_wallet_recharge_attempts')->where('id', (int) $attempt->id)->update([
                'payment_id' => $created['payment_id'],
                'checkout_url' => $created['checkout_url'],
                'status' => self::ATTEMPT_CREATED,
                'last_error' => null,
                'updated_at' => $now,
            ]);
            DB::table('v2_wallet_recharge_orders')->where('id', (int) $attempt->order_id)->update([
                'status' => self::STATUS_PAYING,
                'upstream_payment_id' => $created['payment_id'],
                'last_error' => null,
                'updated_at' => $now,
            ]);
        });

        $fresh = DB::table('v2_wallet_recharge_attempts')->where('id', (int) $attempt->id)->first();
        $freshOrder = DB::table('v2_wallet_recharge_orders')->where('id', (int) $attempt->order_id)->first();

        return $this->paymentView($freshOrder, $fresh);
    }

    private function closeAttempt(int $attemptId, string $status, bool $closeOrder): void
    {
        WalletRechargeTransaction::run(function () use ($attemptId, $status, $closeOrder): void {
            $attempt = DB::table('v2_wallet_recharge_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if (!$attempt) {
                return;
            }
            DB::table('v2_wallet_recharge_attempts')->where('id', $attemptId)->update([
                'status' => $status,
                'updated_at' => time(),
            ]);
            $order = DB::table('v2_wallet_recharge_orders')->where('id', (int) $attempt->order_id)->lockForUpdate()->first();
            if ($closeOrder && $order && $order->status === self::STATUS_PAYING) {
                DB::table('v2_wallet_recharge_orders')->where('id', (int) $order->id)->update([
                    'status' => $status === self::ATTEMPT_EXPIRED ? self::STATUS_EXPIRED : self::STATUS_FAILED,
                    'updated_at' => time(),
                ]);
            }
        });
    }

    private function latestAttempt(int $orderId): ?object
    {
        return DB::table('v2_wallet_recharge_attempts')
            ->where('order_id', $orderId)->orderByDesc('attempt_no')->first();
    }

    private function verifyNotifySignature(array $payload, int $now): void
    {
        $orderNo = $this->stringValue($payload['orderNo'] ?? null, 'orderNo', 64);
        $amount = $this->positiveInt($payload['amountCents'] ?? null, 'amountCents');
        $txnId = $this->stringValue($payload['txnId'] ?? null, 'txnId', 128);
        $timestamp = $payload['timestamp'] ?? null;
        $sign = $payload['sign'] ?? null;
        if (!is_int($timestamp) || $timestamp < 0) {
            throw WalletRechargeException::invalidRequest('timestamp must be integer seconds');
        }
        if (abs($now - $timestamp) > $this->cfg->callbackClockSkewSeconds) {
            throw WalletRechargeException::invalidTimestamp();
        }
        if (!is_string($sign) || !preg_match('/^[0-9a-fA-F]{64}$/', $sign)) {
            throw WalletRechargeException::invalidSignature();
        }
        $secret = $this->cfg->secret();
        if ($secret === null) {
            throw WalletRechargeException::disabled('callback_secret_missing');
        }
        $expected = hash_hmac('sha256', $orderNo . '.' . $amount . '.' . $txnId . '.' . $timestamp, $secret);
        if (!hash_equals($expected, strtolower($sign))) {
            throw WalletRechargeException::invalidSignature();
        }
    }

    /**
     * @param array{payment_id:string,out_trade_no:string,status:string,amount:int,currency:string,paid_at:?string} $upstream
     */
    private function assertUpstreamMatches(object $attempt, array $upstream): void
    {
        if ((string) $upstream['payment_id'] !== (string) $attempt->payment_id
            || (string) $upstream['out_trade_no'] !== (string) $attempt->out_trade_no
            || (int) $upstream['amount'] !== (int) $attempt->payable_cents
            || (string) $upstream['currency'] !== (string) $attempt->currency) {
            throw WalletRechargeException::verificationFailed('支付方返回与本地订单快照不一致');
        }
    }

    // ───────────────────── 异常与回读 ─────────────────────

    private function recordException(
        string $kind,
        object $order,
        object $attempt,
        ?string $channelTxnId,
        string $status,
        array $detail
    ): void {
        $payload = [
            'kind' => $kind,
            'order_id' => (int) $order->id,
            'attempt_id' => (int) $attempt->id,
            'user_id' => (int) $order->user_id,
            'provider' => (string) $order->payment_provider,
            'upstream_txn_id' => $attempt->payment_id === null ? null : (string) $attempt->payment_id,
            'channel_txn_id' => $channelTxnId,
            'out_trade_no' => (string) $attempt->out_trade_no,
            'amount_cents' => (int) $attempt->payable_cents,
            'currency' => (string) $attempt->currency,
            'status' => $status,
            'detail_json' => json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => time(),
        ];
        try {
            DB::table('v2_wallet_recharge_exceptions')->insert($payload);
        } catch (UniqueConstraintViolationException) {
            // 同一笔上游交易重复通知只保留一条未决异常。
        }
    }

    private function setOrderReview(int $orderId): void
    {
        DB::table('v2_wallet_recharge_orders')->where('id', $orderId)->update([
            'status' => self::STATUS_REVIEW,
            'last_error' => 'manual_review',
            'updated_at' => time(),
        ]);
    }

    private function channelTxnUsedElsewhere(string $channelTxnId, int $orderId): bool
    {
        $ledger = DB::table('v2_wallet_recharge_ledger')
            ->where('channel_txn_id', $channelTxnId)
            ->where('recharge_order_id', '!=', $orderId)->exists();
        if ($ledger) {
            return true;
        }

        return DB::table('v2_wallet_recharge_attempts')
            ->where('channel_txn_id', $channelTxnId)
            ->where('order_id', '!=', $orderId)->exists();
    }

    // ───────────────────── 视图与校验 ─────────────────────

    private function loadOwnOrder(int $userId, string $orderNo): object
    {
        if (!preg_match(self::ORDER_NO_RE, $orderNo)) {
            throw WalletRechargeException::notFound();
        }
        $order = DB::table('v2_wallet_recharge_orders')
            ->where('order_no', $orderNo)->where('user_id', $userId)->first();
        if (!$order) {
            throw WalletRechargeException::notFound();
        }

        return $order;
    }

    private function orderView(object $order, ?array $attempts = null): array
    {
        $status = (string) $order->status;
        $paymentState = match ($status) {
            self::STATUS_PAID => 'paid',
            self::STATUS_REVIEW => 'review',
            self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_EXPIRED => 'closed',
            default => 'processing',
        };

        $view = [
            'orderNo' => (string) $order->order_no,
            'status' => $status,
            'paymentState' => $paymentState,
            'amountCents' => (int) $order->amount_cents,
            'feeCents' => (int) $order->fee_cents,
            'payableCents' => (int) $order->payable_cents,
            'currency' => (string) $order->currency,
            'returnPath' => $order->return_path === null ? null : (string) $order->return_path,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $order->created_at),
            'expiresAt' => $order->expires_at === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $order->expires_at),
            'paidAt' => $order->paid_at === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $order->paid_at),
            'creditedAt' => $order->credited_at === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $order->credited_at),
        ];
        if ($attempts !== null) {
            $view['attempts'] = $attempts;
        }

        return $view;
    }

    private function paymentView(object $order, object $attempt): array
    {
        if (!$attempt->checkout_url) {
            throw WalletRechargeException::paymentUnknown();
        }

        return [
            'state' => 'payment_required',
            'orderNo' => (string) $order->order_no,
            'checkoutUrl' => (string) $attempt->checkout_url,
            'paymentId' => $attempt->payment_id === null ? null : (string) $attempt->payment_id,
            'attemptNo' => (int) $attempt->attempt_no,
            'payableCents' => (int) $attempt->payable_cents,
            'expiresAt' => $order->expires_at === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $order->expires_at),
        ];
    }

    private function replayCreate(object $order, string $requestHash): array
    {
        if (!hash_equals((string) $order->request_hash, $requestHash)) {
            throw WalletRechargeException::idempotencyConflict();
        }

        return $this->orderView($order);
    }

    private function assertUserCanStart(int $userId): void
    {
        $user = DB::table('v2_user')->where('id', $userId)->first(['id', 'banned']);
        if (!$user) {
            throw WalletRechargeException::forbidden('用户不存在');
        }
        if ((bool) $user->banned) {
            throw WalletRechargeException::forbidden('账号已被封禁');
        }
    }

    private function assertAmountInRange(int $amountCents): void
    {
        if ($this->cfg->minCents === null || $this->cfg->maxCents === null) {
            throw WalletRechargeException::disabled('limits_missing');
        }
        if ($amountCents < $this->cfg->minCents || $amountCents > $this->cfg->maxCents) {
            throw WalletRechargeException::invalidRequest('金额超出允许范围');
        }
    }

    private function requestHash(int $userId, int $amountCents, ?string $returnPath): string
    {
        return hash('sha256', json_encode([
            'version' => 'wallet-recharge-v1',
            'userId' => $userId,
            'amountCents' => $amountCents,
            'currency' => WalletRechargeConfig::CURRENCY,
            'returnPath' => $returnPath,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_BALANCE) {
            throw WalletRechargeException::invalidRequest($field . ' 必须是正整数分');
        }

        return $value;
    }

    private function stringValue(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength) {
            throw WalletRechargeException::invalidRequest($field . ' 无效');
        }

        return trim($value);
    }

    private function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || !preg_match(self::IDEMPOTENCY_RE, $value)) {
            throw WalletRechargeException::invalidRequest('idempotencyKey 无效');
        }

        return $value;
    }

    private function returnPath(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 512) {
            throw WalletRechargeException::invalidRequest('returnPath 无效');
        }
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')
            || str_contains($value, '\\') || str_contains($value, '..') || str_contains($value, '#')
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw WalletRechargeException::invalidRequest('returnPath 必须是受控站内相对路径');
        }
        foreach ($this->cfg->returnPathPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        throw WalletRechargeException::invalidRequest('returnPath 不在允许范围内');
    }
}
