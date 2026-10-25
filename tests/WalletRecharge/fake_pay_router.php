<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$raw = file_get_contents('php://input') ?: '';
$storeFile = (string) getenv('WR_PAY_STORE');
$baseUrl = rtrim((string) getenv('WR_PAY_PUBLIC_URL'), '/');

function store(string $file): array
{
    if (!is_file($file)) return [];
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function save(string $file, array $store): void
{
    file_put_contents($file, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function out(array $body, int $status = 200): void
{
    http_response_code($status);
    header('content-type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/api/payments' && $method === 'POST') {
    $body = json_decode($raw, true) ?: [];
    $outTradeNo = (string) ($body['out_trade_no'] ?? '');
    if ($outTradeNo === '') out(['status' => 'fail', 'message' => 'missing out_trade_no', 'data' => null], 400);
    $store = store($storeFile);
    foreach ($store as $p) {
        if (($p['out_trade_no'] ?? '') === $outTradeNo) {
            out(['status' => 'success', 'data' => [
                'payment_id' => $p['payment_id'],
                'checkout_url' => $baseUrl . '/checkout/' . $p['payment_id'],
            ]]);
        }
    }
    $id = 'pay_' . substr(hash('sha256', $outTradeNo), 0, 20);
    $store[$id] = [
        'payment_id' => $id,
        'out_trade_no' => $outTradeNo,
        'status' => 'pending',
        'amount' => (int) ($body['amount'] ?? 0),
        'currency' => (string) ($body['currency'] ?? 'CNY'),
        'paid_at' => null,
    ];
    save($storeFile, $store);
    out(['status' => 'success', 'data' => [
        'payment_id' => $id,
        'checkout_url' => $baseUrl . '/checkout/' . $id,
    ]]);
}

if (preg_match('~^/api/payments/([A-Za-z0-9_-]+)$~', $path, $m) && $method === 'GET') {
    $store = store($storeFile);
    $p = $store[$m[1]] ?? null;
    if (!$p) out(['status' => 'fail', 'message' => 'not found', 'data' => null], 404);
    out(['status' => 'success', 'data' => $p]);
}

if ($path === '/__test/pay-status' && $method === 'POST') {
    $body = json_decode($raw, true) ?: [];
    $store = store($storeFile);
    $id = (string) ($body['payment_id'] ?? '');
    if (!isset($store[$id])) out(['status' => 'fail', 'message' => 'not found', 'data' => null], 404);
    $store[$id]['status'] = (string) ($body['status'] ?? 'paid');
    $store[$id]['paid_at'] = $store[$id]['status'] === 'paid' ? gmdate('c') : null;
    save($storeFile, $store);
    out(['status' => 'success', 'data' => $store[$id]]);
}

out(['status' => 'fail', 'message' => 'not found', 'data' => null], 404);
