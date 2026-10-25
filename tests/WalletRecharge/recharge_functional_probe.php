<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__ . '/Support.php';

$boot = wr_boot();
wr_init_database();
$app = $boot['app'];

/** @var array<string,array> $payments payment_id => record */
$payments = [];
$outToPayment = [];

$fake = function () use (&$payments, &$outToPayment): void {
    wr_fake_pay(function ($request) use (&$payments, &$outToPayment) {
        $url = $request->url();
        if ($request->method() === 'GET' && preg_match('~/api/payments/([A-Za-z0-9_-]+)$~', $url, $m)) {
            $payment = $payments[$m[1]] ?? null;
            if (!$payment) {
                return wr_pay_fail('支付单不存在', 404);
            }
            return wr_pay_json($payment);
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
                'currency' => (string) ($body['currency'] ?? 'CNY'),
                'paid_at' => null,
                'checkout_url' => 'http://127.0.0.1:9/checkout/' . $paymentId,
            ];
            return wr_pay_json([
                'payment_id' => $paymentId,
                'checkout_url' => $payments[$paymentId]['checkout_url'],
            ]);
        }
        return wr_pay_fail('unexpected', 400);
    });
};
$fake();

$user = static function (int $id) {
    return wr_user($id);
};
$resetPay = static function (array $payments) {
    return $payments;
};

wr_seed_user(1, 1000);
wr_seed_user(2, 2000);
wr_seed_user(3, 3000);
wr_seed_user(4, 4000);
wr_seed_user(5, 5000);

echo "== 创建、幂等与输入校验 ==\n";
wr_assert_same(true, wr_request($app, 'GET', '/api/v1/user/wallet/recharge/capability', null, $user(1))['body']['data']['enabled'], '能力开关读真实配置');
$create = wr_assert_success(wr_create_order($app, $user(1), 500, 'idem-create-001', '/cloud/orders/TJ-1'), '合法充值订单创建');
wr_assert_same('pending', $create['status'], '新单状态 pending');
wr_assert_same(500, $create['amountCents'], '本金=500 分');
wr_assert_same(0, $create['feeCents'], '默认手续费为 0');
wr_assert_same(500, $create['payableCents'], '应付=本金');
wr_assert_same('/cloud/orders/TJ-1', $create['returnPath'], '受控站内返回路径被保存');
$orderNo1 = $create['orderNo'];

$replay = wr_assert_success(wr_create_order($app, $user(1), 500, 'idem-create-001', '/cloud/orders/TJ-1'), '同键同内容回放');
wr_assert_same($orderNo1, $replay['orderNo'], '回放返回原订单，不产生第二单');
wr_assert_status(wr_create_order($app, $user(1), 600, 'idem-create-001'), 409, 'idempotency_conflict', '同键异金额冲突');
wr_assert_status(wr_create_order($app, $user(1), 500, 'idem-create-001', '/cloud/orders/TJ-2'), 409, 'idempotency_conflict', '同键异返回路径冲突');

foreach ([
    ['amountCents' => 0, 'label' => '零金额'],
    ['amountCents' => -1, 'label' => '负金额'],
    ['amountCents' => 1.25, 'label' => '小数分'],
    ['amountCents' => '500', 'label' => '字符串金额'],
    ['amountCents' => 100001, 'label' => '超过配置上限'],
] as $case) {
    $r = wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders', [
        'amountCents' => $case['amountCents'],
        'idempotencyKey' => 'bad-' . bin2hex(random_bytes(8)),
    ], $user(1));
    wr_assert_status($r, 400, 'invalid_request', $case['label'] . ' 被拒绝');
}
wr_assert_status(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders', ['amountCents' => 500], $user(1)), 400, 'invalid_request', '缺少幂等键被拒绝');
wr_assert_status(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders', [
    'amountCents' => 500, 'idempotencyKey' => 'short',
], $user(1)), 400, 'invalid_request', '过短幂等键被拒绝');
foreach (['//evil.example/x', 'https://evil.example/x', '/admin', '/cloud/orders/../../etc', '/cloud/orders/x#frag'] as $badReturn) {
    $r = wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders', [
        'amountCents' => 500,
        'idempotencyKey' => 'ret-' . bin2hex(random_bytes(8)),
        'returnPath' => $badReturn,
    ], $user(1));
    wr_assert_status($r, 400, 'invalid_request', '恶意/越权 returnPath 被拒绝: ' . json_encode($badReturn));
}

echo "== 跨账号归属 ==\n";
wr_assert_status(wr_request($app, 'GET', '/api/v1/user/wallet/recharge/orders/' . $orderNo1, null, $user(2)), 404, 'not_found', '他人订单详情不可读');
wr_assert_status(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo1 . '/payment', [], $user(2)), 404, 'not_found', '他人订单不可发起支付');
wr_assert_status(wr_request($app, 'GET', '/api/v1/user/wallet/recharge/orders', null, null), 403, null, '未登录不能读充值记录');

echo "== 合法付款精确入账、回调重放 ==\n";
$pay = wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo1 . '/payment', [], $user(1)), '发起充值支付');
wr_assert_same('payment_required', $pay['state'], '返回受控收银台状态');
wr_assert(str_starts_with($pay['checkoutUrl'], 'http://127.0.0.1:9/checkout/'), '收银台 URL 由服务端校验');
$attempt1 = wr_attempt($orderNo1);
$paymentId1 = (string) $attempt1->payment_id;
$payments[$paymentId1]['status'] = 'paid';
$payments[$paymentId1]['paid_at'] = gmdate('c');
$cb = wr_callback($app, wr_callback_payload($orderNo1, 500, 'ch_txn_001'));
wr_assert_same(200, $cb['status'], '合法回调被接受');
wr_assert_same(1500, wr_balance(1), '余额从 1000 精确增加到 1500');
wr_assert_same(1, wr_ledger_count(), '只产生一条充值流水');
$ledger = DB::table('v2_wallet_recharge_ledger')->first();
wr_assert_same(1000, (int) $ledger->balance_before_cents, '流水记录变更前余额');
wr_assert_same(1500, (int) $ledger->balance_after_cents, '流水记录变更后余额');
wr_assert_same('recharge', (string) $ledger->source, '流水来源为 recharge');
wr_assert_same('ch_txn_001', (string) $ledger->channel_txn_id, '流水保存渠道交易号');
wr_assert_same('paid', (string) wr_order($orderNo1)->status, '订单状态 paid');
$again = wr_callback($app, wr_callback_payload($orderNo1, 500, 'ch_txn_001'));
wr_assert_same(200, $again['status'], '重复回调安全回放');
wr_assert_same(1500, wr_balance(1), '重复回调不重复加余额');
wr_assert_same(1, wr_ledger_count(), '重复回调不新增流水');
$payments[$paymentId1]['status'] = 'pending';
wr_assert_same(503, wr_callback($app, wr_callback_payload($orderNo1, 500, 'ch_txn_001'))['status'], '完成后上游 pending 不得回退');
$payments[$paymentId1]['status'] = 'failed';
wr_assert_same(503, wr_callback($app, wr_callback_payload($orderNo1, 500, 'ch_txn_001'))['status'], '完成后上游 failed 不得回退');
wr_assert_same('paid', (string) wr_order($orderNo1)->status, '完成后订单保持 paid');
wr_assert_same(1500, wr_balance(1), '完成后乱序状态不扣余额');
$payments[$paymentId1]['status'] = 'paid';

echo "== 验签、金额、币种、状态 fail-closed ==\n";
$orderNo2 = wr_assert_success(wr_create_order($app, $user(1), 300, 'idem-order-002'), '创建第二笔充值单')['orderNo'];
$pay2 = wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo2 . '/payment', [], $user(1)), '发起第二笔支付');
$attempt2 = wr_attempt($orderNo2);
$paymentId2 = (string) $attempt2->payment_id;
$payments[$paymentId2]['status'] = 'paid';
$bad = wr_callback_payload($orderNo2, 300, 'ch_txn_bad');
$bad['sign'] = str_repeat('a', 64);
wr_assert_status(wr_callback($app, $bad), 401, 'invalid_signature', '假签名不入账');
$expired = wr_callback_payload($orderNo2, 300, 'ch_txn_old', time() - 901);
wr_assert_status(wr_callback($app, $expired), 401, 'invalid_timestamp', '过期时间戳不入账');
wr_assert_status(wr_callback($app, wr_callback_payload($orderNo2, 301, 'ch_txn_amt')), 409, 'verification_failed', '金额篡改不入账');
wr_assert_status(wr_callback($app, wr_callback_payload('WRUNKNOWN00000001', 300, 'ch_txn_unknown')), 404, 'not_found', '未知订单不入账');
wr_assert_same(1500, wr_balance(1), '以上失败均未改余额');
wr_assert_same(1, wr_ledger_count(), '以上失败均未新增流水');

$payments[$paymentId2]['status'] = 'pending';
wr_assert_status(wr_callback($app, wr_callback_payload($orderNo2, 300, 'ch_txn_pending')), 503, 'payment_unknown', '上游未确认成功时 fail-closed');
$payments[$paymentId2]['status'] = 'paid';
$payments[$paymentId2]['currency'] = 'USD';
wr_assert_status(wr_callback($app, wr_callback_payload($orderNo2, 300, 'ch_txn_currency')), 409, 'verification_failed', '上游币种错误不入账');
$payments[$paymentId2]['currency'] = 'CNY';
$payments[$paymentId2]['payment_id'] = 'pay_other';
wr_assert_status(wr_callback($app, wr_callback_payload($orderNo2, 300, 'ch_txn_payid')), 409, 'verification_failed', '上游支付单号不匹配不入账');
$payments[$paymentId2]['payment_id'] = $paymentId2;

wr_assert_same(1500, wr_balance(1), '错误币种/支付单号仍未改余额');

echo "== 跨订单复用同一渠道交易号 ==\n";
wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo2, 300, 'ch_txn_001'))['status'], '第二单回调已留存');
wr_assert_same(1500, wr_balance(1), '渠道交易号已用于其他订单时不重复入账');
wr_assert_same('review', (string) wr_order($orderNo2)->status, '第二单进入人工核实');
$ex = DB::table('v2_wallet_recharge_exceptions')->where('order_id', (int) wr_order($orderNo2)->id)->first();
wr_assert($ex !== null, '额外款项进入异常记录');

echo "== 查单补账与浏览器返回 ==\n";
$orderNo3 = wr_assert_success(wr_create_order($app, $user(3), 700, 'idem-order-003'), '创建查单补账单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo3 . '/payment', [], $user(3)), '发起查单补账单支付');
$attempt3 = wr_attempt($orderNo3);
$payments[(string) $attempt3->payment_id]['status'] = 'paid';
$refreshed = wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo3 . '/refresh', [], $user(3)), '查单补账成功');
wr_assert_same('paid', $refreshed['status'], '查单把订单推进到 paid');
wr_assert_same(3700, wr_balance(3), '查单补账只增加一次');
wr_assert_same(2, wr_ledger_count(), '查单补账新增一条流水');
$orderNo4 = wr_assert_success(wr_create_order($app, $user(3), 200, 'idem-order-004'), '创建未付款单')['orderNo'];
$returnOnly = wr_request($app, 'GET', '/api/v1/user/wallet/recharge/orders/' . $orderNo4 . '?success=1&status=paid', null, $user(3));
wr_assert_same('pending', $returnOnly['body']['data']['status'], '浏览器 return 参数不能改订单状态');
wr_assert_same(3700, wr_balance(3), '浏览器 return 不入账');

echo "== 换渠道：旧付款延迟成功 + 两渠道成功 ==\n";
$orderNo5 = wr_assert_success(wr_create_order($app, $user(4), 400, 'idem-order-005'), '创建换渠道订单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo5 . '/payment', [], $user(4)), '发起渠道一');
$a1 = wr_attempt($orderNo5);
$payments[(string) $a1->payment_id]['status'] = 'expired';
$second = wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo5 . '/payment', [], $user(4)), '旧尝试明确过期后重建渠道二');
$a2 = wr_attempt($orderNo5 . 'R2');
wr_assert($a2 !== null && (int) $a2->attempt_no === 2, '第二付款尝试创建成功');
$payments[(string) $a2->payment_id]['status'] = 'paid';
wr_assert_same(200, wr_callback($app, wr_callback_payload((string) $a2->out_trade_no, 400, 'ch_txn_new'))['status'], '渠道二成功回调');
wr_assert_same(4400, wr_balance(4), '渠道二入账一次');
$payments[(string) $a1->payment_id]['status'] = 'paid';
wr_assert_same(200, wr_callback($app, wr_callback_payload((string) $a1->out_trade_no, 400, 'ch_txn_late'))['status'], '渠道一延迟成功回调被留存');
wr_assert_same(4400, wr_balance(4), '两渠道都成功仍只入账一次');
$dup = DB::table('v2_wallet_recharge_exceptions')->where('order_id', (int) wr_order($orderNo5)->id)->where('kind', 'duplicate_payment')->first();
wr_assert($dup !== null, '额外付款进入 duplicate_payment 异常队列');
wr_assert_same('paid', (string) wr_order($orderNo5)->status, '已入账订单保持 paid，不回退');

echo "== 终态后迟到付款、封禁用户 ==\n";
wr_seed_user(6, 100);
$orderNo6 = wr_assert_success(wr_create_order($app, $user(6), 300, 'idem-order-006'), '创建终态测试单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo6 . '/payment', [], $user(6)), '发起终态测试支付');
$a6 = wr_attempt($orderNo6);
DB::table('v2_wallet_recharge_orders')->where('order_no', $orderNo6)->update(['status' => 'cancelled']);
$payments[(string) $a6->payment_id]['status'] = 'paid';
wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo6, 300, 'ch_txn_late_cancel'))['status'], '取消后迟到付款被留存');
wr_assert_same(100, wr_balance(6), '取消订单的迟到付款不入账');
wr_assert_same('review', (string) wr_order($orderNo6)->status, '迟到付款订单进入 review');
wr_assert(DB::table('v2_wallet_recharge_exceptions')->where('order_id', (int) wr_order($orderNo6)->id)->where('kind', 'payment_after_terminal')->exists(), '迟到付款进入异常队列');

wr_seed_user(7, 700);
$orderNo7 = wr_assert_success(wr_create_order($app, $user(7), 300, 'idem-order-007'), '创建封禁测试单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo7 . '/payment', [], $user(7)), '发起封禁测试支付');
$a7 = wr_attempt($orderNo7);
$payments[(string) $a7->payment_id]['status'] = 'paid';
DB::table('v2_user')->where('id', 7)->update(['banned' => 1]);
wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo7, 300, 'ch_txn_banned'))['status'], '封禁用户付款被留存');
wr_assert_same(700, wr_balance(7), '封禁用户付款不给余额');
wr_assert_same('review', (string) wr_order($orderNo7)->status, '封禁用户订单进入 review');
wr_assert(DB::table('v2_wallet_recharge_exceptions')->where('order_id', (int) wr_order($orderNo7)->id)->where('kind', 'recharge_user_banned')->exists(), '封禁用户付款进入异常队列');

wr_seed_user(12, 1200);
$orderNo12 = wr_assert_success(wr_create_order($app, $user(12), 300, 'idem-order-012'), '创建已删除用户测试单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo12 . '/payment', [], $user(12)), '发起已删除用户测试支付');
$a12 = wr_attempt($orderNo12);
$payments[(string) $a12->payment_id]['status'] = 'paid';
DB::table('v2_user')->where('id', 12)->delete();
wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo12, 300, 'ch_txn_deleted'))['status'], '已删除用户付款被留存');
wr_assert_same(0, (int) DB::table('v2_user')->where('id', 12)->count(), '用户仍不存在');
wr_assert_same('review', (string) wr_order($orderNo12)->status, '已删除用户订单进入 review');
wr_assert(DB::table('v2_wallet_recharge_exceptions')->where('order_id', (int) wr_order($orderNo12)->id)->where('kind', 'recharge_user_missing')->exists(), '已删除用户付款进入异常队列，不转给他人');

echo "== 事务故障注入：余额/流水完整回滚 ==\n";
wr_seed_user(8, 800);
$orderNo8 = wr_assert_success(wr_create_order($app, $user(8), 250, 'idem-order-008'), '创建故障注入单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo8 . '/payment', [], $user(8)), '发起故障注入支付');
$a8 = wr_attempt($orderNo8);
$payments[(string) $a8->payment_id]['status'] = 'paid';

DB::statement("CREATE TRIGGER wr_abort_ledger BEFORE INSERT ON v2_wallet_recharge_ledger BEGIN SELECT RAISE(ABORT, 'injected ledger failure'); END");
$failed = wr_callback($app, wr_callback_payload($orderNo8, 250, 'ch_txn_abort_ledger'));
wr_assert_same(503, $failed['status'], '流水插入故障时回调不假成功');
wr_assert_same(800, wr_balance(8), '流水插入故障后余额回滚');
wr_assert_same(0, (int) DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderNo8)->count(), '流水插入故障后无残留流水');
DB::statement('DROP TRIGGER wr_abort_ledger');

DB::statement("CREATE TRIGGER wr_abort_order BEFORE UPDATE ON v2_wallet_recharge_orders WHEN NEW.status = 'paid' BEGIN SELECT RAISE(ABORT, 'injected order failure'); END");
$failed2 = wr_callback($app, wr_callback_payload($orderNo8, 250, 'ch_txn_abort_order'));
wr_assert_same(503, $failed2['status'], '订单完成更新故障时回调不假成功');
wr_assert_same(800, wr_balance(8), '订单更新故障后余额回滚');
wr_assert_same(0, (int) DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderNo8)->count(), '订单更新故障后流水回滚');
DB::statement('DROP TRIGGER wr_abort_order');

wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo8, 250, 'ch_txn_abort_retry'))['status'], '故障解除后同笔付款可重试入账');
wr_assert_same(1050, wr_balance(8), '故障解除后只入账一次');

echo "== 上游超时/坏响应、数据库不可写 fail-closed ==\n";
wr_seed_user(11, 1100);
$orderNo11 = wr_assert_success(wr_create_order($app, $user(11), 200, 'idem-order-011'), '创建上游故障单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo11 . '/payment', [], $user(11)), '发起上游故障单支付');
$a11 = wr_attempt($orderNo11);
$payments[(string) $a11->payment_id]['status'] = 'paid';
wr_fake_pay(fn () => throw new RuntimeException('simulated upstream timeout'));
$upTimeout = wr_callback($app, wr_callback_payload($orderNo11, 200, 'ch_txn_up_timeout'));
wr_assert_same(503, $upTimeout['status'], '上游超时回调不假成功');
wr_assert_same(1100, wr_balance(11), '上游超时未改余额');
wr_fake_pay(fn () => Http::response('not-json', 200));
$upBad = wr_callback($app, wr_callback_payload($orderNo11, 200, 'ch_txn_up_bad'));
wr_assert_same(503, $upBad['status'], '上游坏响应回调不假成功');
wr_assert_same(1100, wr_balance(11), '上游坏响应未改余额');
$fake();
DB::statement('PRAGMA query_only = ON');
$dbDown = wr_callback($app, wr_callback_payload($orderNo11, 200, 'ch_txn_db_down'));
wr_assert_same(503, $dbDown['status'], '数据库不可写时回调 fail-closed');
wr_assert_same(1100, wr_balance(11), '数据库不可写时余额不变');
wr_assert_same(0, (int) DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderNo11)->count(), '数据库不可写时无流水');
DB::statement('PRAGMA query_only = OFF');
$dbRetry = wr_callback($app, wr_callback_payload($orderNo11, 200, 'ch_txn_db_retry'));
wr_assert_same(200, $dbRetry['status'], '数据库恢复后同笔回调可重试');
wr_assert_same(1300, wr_balance(11), '数据库恢复后只入账一次');

echo "== 开关语义：停止新单不影响已创建付款 ==\n";
$orderNo9 = wr_assert_success(wr_create_order($app, $user(8), 200, 'idem-order-009'), '开关关闭前创建订单')['orderNo'];
wr_assert_success(wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders/' . $orderNo9 . '/payment', [], $user(8)), '开关关闭前发起支付');
$a9 = wr_attempt($orderNo9);
$payments[(string) $a9->payment_id]['status'] = 'paid';
config(['wallet_recharge.enabled' => false]);
app()->forgetInstance(\App\Services\WalletRecharge\WalletRechargeService::class);
$closed = null;
try {
    wr_service()->createOrder(wr_user(8), ['amountCents' => 200, 'idempotencyKey' => 'idem-order-closed']);
} catch (\App\Exceptions\WalletRechargeException $e) {
    $closed = $e;
}
wr_assert($closed !== null && $closed->httpStatus() === 503 && $closed->errorCode() === 'recharge_disabled', '关闭开关后拒绝新单');
wr_assert_same(false, wr_service()->capability(wr_user(8))['enabled'], '能力状态同步关闭');
wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo9, 200, 'ch_txn_after_close'))['status'], '关闭新单后既有真实付款仍可入账');
wr_assert_same(1250, wr_balance(8), '关闭新单不影响已创建付款入账');
config(['wallet_recharge.enabled' => true]);
app()->forgetInstance(\App\Services\WalletRecharge\WalletRechargeService::class);

echo "== 凭据缺失 fail-closed ==\n";
config(['wallet_recharge.pay.secret_file' => '/nonexistent/wallet-recharge-secret']);
app()->forgetInstance(\App\Services\WalletRecharge\WalletRechargeService::class);
$noSecret = null;
try {
    wr_service()->createOrder(wr_user(8), ['amountCents' => 200, 'idempotencyKey' => 'idem-order-nosecret']);
} catch (\App\Exceptions\WalletRechargeException $e) {
    $noSecret = $e;
}
wr_assert($noSecret !== null && $noSecret->errorCode() === 'recharge_disabled' && $noSecret->data()['reason'] === 'pay_not_configured', '缺少支付凭据拒绝新单');
// 用旧 secret 构造合法签名，但服务端凭据缺失，必须拒绝且不入账。
wr_seed_user(9, 900);
DB::table('v2_user')->where('id', 9)->update(['balance' => 900]);
wr_assert_same(900, wr_balance(9), '凭据缺失测试起始余额');
config(['wallet_recharge.pay.secret_file' => $boot['keyFile']]);
app()->forgetInstance(\App\Services\WalletRecharge\WalletRechargeService::class);

echo "== 手续费与对账 ==\n";
config([
    'wallet_recharge.fee_bps' => 100,
    'wallet_recharge.fee_fixed_cents' => 20,
    'wallet_recharge.fee_bearer' => 'customer',
]);
app()->forgetInstance(\App\Services\WalletRecharge\WalletRechargeService::class);
wr_seed_user(10, 1000);
$feeCreated = wr_service()->createOrder(wr_user(10), ['amountCents' => 500, 'idempotencyKey' => 'idem-order-010']);
$orderNo10 = $feeCreated['orderNo'];
$o10 = wr_order($orderNo10);
wr_assert_same(25, (int) $o10->fee_cents, '手续费 1% + 20 分 = 25 分');
wr_assert_same(525, (int) $o10->payable_cents, '客户承担手续费时应付 525 分');
wr_service()->startPayment(wr_user(10), $orderNo10);
$a10 = wr_attempt($orderNo10);
$payments[(string) $a10->payment_id]['status'] = 'paid';
wr_assert_same(200, wr_callback($app, wr_callback_payload($orderNo10, 525, 'ch_txn_fee'))['status'], '手续费订单按应付金额回调');
wr_assert_same(1500, wr_balance(10), '手续费不进余额，只入账本金 500');
$feeLedger = DB::table('v2_wallet_recharge_ledger')->where('order_no', $orderNo10)->first();
wr_assert_same(500, (int) $feeLedger->amount_cents, '流水本金 500 分');
wr_assert_same(25, (int) $feeLedger->fee_cents, '流水手续费单独记录 25 分');

$day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
DB::table('v2_cloud_wallet_ledger')->insert([
    'idempotency_key' => 'cloud-refund-not-recharge',
    'request_hash' => str_repeat('c', 64),
    'user_id' => 10,
    'order_no' => 'CLOUD-ORDER',
    'kind' => 'refund',
    'amount_cents' => 999,
    'currency' => 'CNY',
    'balance_after_cents' => 1500,
    'debit_id' => null,
    'source' => 'cloud',
    'reason' => 'probe',
    'created_at' => time(),
]);
$report = wr_service()->reconcileDay($day);
$rechargeCredited = (int) DB::table('v2_wallet_recharge_ledger')->sum('amount_cents');
wr_assert_same($rechargeCredited, $report['creditedTotalCents'], '对账入账合计只统计 recharge 流水，不混入 cloud 退款');
wr_assert($report['feeTotalCents'] >= 25, '对账手续费单列，不混入本金');
wr_assert(count($report['differenceOrders']) >= 1, '对账定位故意制造的未入账差异订单');
wr_assert(count($report['openExceptions']) >= 3, '对账列出未决异常/双付待处理');
DB::table('v2_wallet_recharge_orders')->where('order_no', $orderNo4)->update(['created_at' => time() - 7200]);
$report2 = wr_service()->reconcileDay($day);
wr_assert(count($report2['longPendingOrders']) >= 1, '对账列出长期 pending 订单');

echo "== 迁移回退保护 ==\n";
$migration = require wr_root() . '/database/migrations/2026_10_25_000002_create_wallet_recharge_tables.php';
$threw = false;
try {
    $migration->down();
} catch (RuntimeException $e) {
    $threw = true;
}
wr_assert($threw, '存在资金流水时 migration down 主动拒绝');
wr_assert(DB::getSchemaBuilder()->hasTable('v2_wallet_recharge_ledger'), '拒绝回退后流水表仍存在');

wr_cleanup($boot);
echo "ALL RECHARGE FUNCTIONAL PROBES PASSED\n";
