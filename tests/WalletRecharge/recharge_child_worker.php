<?php

declare(strict_types=1);

use App\Exceptions\WalletRechargeException;
use App\Services\CloudWallet\CloudWalletService;

require __DIR__ . '/Support.php';

$jobFile = $argv[1] ?? '';
if (!is_file($jobFile)) {
    fwrite(STDERR, "job file missing\n");
    exit(2);
}
$job = json_decode((string) file_get_contents($jobFile), true);
if (!is_array($job)) {
    fwrite(STDERR, "invalid job\n");
    exit(2);
}

$boot = wr_boot_paths(
    (string) $job['database'],
    (string) $job['keyFile'],
    (string) $job['cloudKeyFile'],
    (array) ($job['config'] ?? [])
);

$payment = (array) $job['payment'];
wr_fake_pay(function ($request) use ($payment) {
    return wr_pay_json([
        'payment_id' => $payment['payment_id'],
        'out_trade_no' => $payment['out_trade_no'],
        'status' => $payment['status'] ?? 'paid',
        'amount' => $payment['amount'],
        'currency' => 'CNY',
        'paid_at' => gmdate('c'),
    ]);
});

file_put_contents((string) $job['ready_file'], '1');
$startAt = (float) $job['start_at'];
while (!is_file((string) $job['start_file'])) {
    if (microtime(true) > $startAt + 30) {
        fwrite(STDERR, "barrier timeout\n");
        exit(3);
    }
    usleep(1000);
}

$result = ['action' => $job['action'], 'ok' => false, 'state' => null, 'code' => null, 'message' => null];
for ($attempt = 1; $attempt <= 6; $attempt++) {
    try {
        if ($job['action'] === 'callback') {
            $result['state'] = wr_service()->handleNotify($job['payload']);
            $result['ok'] = true;
        } elseif ($job['action'] === 'refresh') {
            $result['state'] = wr_service()->refreshOrder(wr_user((int) $job['user_id']), (string) $job['order_no']);
            $result['ok'] = true;
        } elseif ($job['action'] === 'cloud_debit') {
            $result['state'] = (new CloudWalletService())->debit([
                'userId' => (int) $job['user_id'],
                'orderNo' => (string) $job['order_no'],
                'idempotencyKey' => (string) $job['idempotency_key'],
                'amountCents' => (int) $job['amount_cents'],
                'currency' => 'CNY',
            ]);
            $result['ok'] = true;
        } else {
            throw new RuntimeException('unknown action');
        }
        break;
    } catch (WalletRechargeException $e) {
        $result['code'] = $e->errorCode();
        $result['message'] = $e->getMessage();
    } catch (Throwable $e) {
        $result['code'] = 'exception';
        $result['message'] = get_class($e) . ': ' . $e->getMessage();
    }
    if (!in_array($result['code'], ['recharge_busy', 'wallet_busy', 'payment_unknown'], true)) {
        break;
    }
    usleep(40_000 * $attempt);
}

file_put_contents((string) $job['result_file'], json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
