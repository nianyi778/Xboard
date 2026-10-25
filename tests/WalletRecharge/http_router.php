<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/Support.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$raw = file_get_contents('php://input') ?: '';
$storeFile = (string) getenv('WR_PAY_STORE');
$baseUrl = rtrim((string) getenv('WR_BASE_URL'), '/');

function pay_store(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : [];
}

function pay_save(string $file, array $store): void
{
    file_put_contents($file, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function json_out(array $body, int $status = 200): void
{
    http_response_code($status);
    header('content-type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/__pay/api/payments' && $method === 'POST') {
    $body = json_decode($raw, true) ?: [];
    $outTradeNo = (string) ($body['out_trade_no'] ?? '');
    if ($outTradeNo === '') {
        json_out(['status' => 'fail', 'message' => 'missing out_trade_no', 'data' => null], 400);
    }
    $store = pay_store($storeFile);
    foreach ($store as $p) {
        if (($p['out_trade_no'] ?? '') === $outTradeNo) {
            json_out(['status' => 'success', 'data' => [
                'payment_id' => $p['payment_id'],
                'checkout_url' => $baseUrl . '/checkout/' . $p['payment_id'],
            ]]);
        }
    }
    $paymentId = 'pay_' . substr(hash('sha256', $outTradeNo), 0, 20);
    $store[$paymentId] = [
        'payment_id' => $paymentId,
        'out_trade_no' => $outTradeNo,
        'status' => 'pending',
        'amount' => (int) ($body['amount'] ?? 0),
        'currency' => (string) ($body['currency'] ?? 'CNY'),
        'paid_at' => null,
    ];
    pay_save($storeFile, $store);
    json_out(['status' => 'success', 'data' => [
        'payment_id' => $paymentId,
        'checkout_url' => $baseUrl . '/checkout/' . $paymentId,
    ]]);
}

if (preg_match('~^/__pay/api/payments/([A-Za-z0-9_-]+)$~', $path, $m) && $method === 'GET') {
    $store = pay_store($storeFile);
    $p = $store[$m[1]] ?? null;
    if (!$p) {
        json_out(['status' => 'fail', 'message' => 'not found', 'data' => null], 404);
    }
    json_out(['status' => 'success', 'data' => $p]);
}

if ($path === '/__test/pay-status' && $method === 'POST') {
    $body = json_decode($raw, true) ?: [];
    $store = pay_store($storeFile);
    $id = (string) ($body['payment_id'] ?? '');
    if (!isset($store[$id])) {
        json_out(['status' => 'fail', 'message' => 'not found', 'data' => null], 404);
    }
    $store[$id]['status'] = (string) ($body['status'] ?? 'paid');
    $store[$id]['paid_at'] = $store[$id]['status'] === 'paid' ? gmdate('c') : null;
    pay_save($storeFile, $store);
    json_out(['status' => 'success', 'data' => $store[$id]]);
}

$boot = wr_boot_paths(
    (string) getenv('WR_DB'),
    (string) getenv('WR_KEY'),
    (string) getenv('WR_CLOUD_KEY')
);
config([
    'wallet_recharge.pay.base_url' => rtrim((string) (getenv('WR_PAY_BASE_URL') ?: ($baseUrl . '/__pay')), '/'),
    'wallet_recharge.pay.notify_url' => $baseUrl . '/api/v1/guest/wallet-recharge/notify/tianji',
    'wallet_recharge.pay.panel_url' => rtrim((string) getenv('WR_PANEL_URL'), '/'),
]);

$testUser = (int) ($_SERVER['HTTP_X_TEST_USER'] ?? 0);
if ($testUser > 0 && str_starts_with($path, '/api/v1/user/')) {
    $user = wr_user($testUser);
    Auth::guard('sanctum')->setUser($user);
    Auth::guard('api')->setUser($user);
}

$server = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $server[$key] = $value;
    }
}
$server['HTTP_ACCEPT'] = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
$server['CONTENT_TYPE'] = $_SERVER['CONTENT_TYPE'] ?? 'application/json';

$request = Request::create(
    $_SERVER['REQUEST_URI'] ?? $path,
    $method,
    [],
    [],
    [],
    $server,
    $raw
);
$response = $boot['app']->make(HttpKernel::class)->handle($request);
$response->send();
