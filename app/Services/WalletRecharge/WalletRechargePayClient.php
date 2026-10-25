<?php

namespace App\Services\WalletRecharge;

use App\Exceptions\WalletRechargeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * tianji-pay 已核验协议的最小客户端。
 *
 * 源码依据（只读）：/home/nianyi778/wsl2/github/tianji-pay
 *   apps/core/src/api.ts   POST /api/payments, GET /api/payments/:id
 *   apps/core/src/sign.ts  入站 HMAC 签名串
 *
 * 只使用本仓需要的两个动作；不臆造插件或未核实的响应字段。
 */
final class WalletRechargePayClient
{
    public function __construct(private readonly WalletRechargeConfig $cfg)
    {
    }

    /**
     * @return array{payment_id:string,checkout_url:string}
     */
    public function createPayment(string $outTradeNo, int $amountCents, string $subject, string $returnUrl): array
    {
        $secret = $this->secretOrFail();
        $timestamp = (string) time();
        $sign = hash_hmac('sha256', $outTradeNo . '.' . $amountCents . '.' . WalletRechargeConfig::CURRENCY . '.' . $timestamp, $secret);

        $payload = [
            'out_trade_no' => $outTradeNo,
            'amount' => $amountCents,
            'currency' => WalletRechargeConfig::CURRENCY,
            'subject' => $subject,
            'notify_url' => $this->cfg->notifyUrl,
            'return_url' => $returnUrl,
            'expires_in' => $this->cfg->expiresMinutes * 60,
        ];

        $response = $this->send('post', '/api/payments', $payload, [
            'X-Pay-App' => $this->cfg->payAppId,
            'X-Pay-Timestamp' => $timestamp,
            'X-Pay-Sign' => $sign,
        ]);

        if ($response['status'] === 0) {
            throw WalletRechargeException::paymentUnknown();
        }
        if ($response['status'] !== 200) {
            throw WalletRechargeException::paymentUnavailable('创建支付单被上游拒绝');
        }

        $data = $response['data'];
        $paymentId = $data['payment_id'] ?? null;
        $checkoutUrl = $data['checkout_url'] ?? null;
        if (!is_string($paymentId) || !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $paymentId)
            || !is_string($checkoutUrl) || $checkoutUrl === '') {
            throw WalletRechargeException::paymentUnavailable('支付单响应无效');
        }
        $this->assertCheckoutUrl($checkoutUrl);

        return ['payment_id' => $paymentId, 'checkout_url' => $checkoutUrl];
    }

    /**
     * @return array{payment_id:string,out_trade_no:string,status:string,amount:int,currency:string,paid_at:?string}
     */
    public function getPayment(string $paymentId): array
    {
        $secret = $this->secretOrFail();
        $timestamp = (string) time();
        $sign = hash_hmac('sha256', 'status.' . $paymentId . '.' . $timestamp, $secret);

        $response = $this->send('get', '/api/payments/' . rawurlencode($paymentId), null, [
            'X-Pay-App' => $this->cfg->payAppId,
            'X-Pay-Timestamp' => $timestamp,
            'X-Pay-Sign' => $sign,
        ]);

        if ($response['status'] === 0) {
            throw WalletRechargeException::paymentUnknown();
        }
        if ($response['status'] !== 200) {
            throw WalletRechargeException::paymentUnavailable('查询支付单被上游拒绝');
        }

        $data = $response['data'];
        $required = ['payment_id', 'out_trade_no', 'status', 'amount', 'currency'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw WalletRechargeException::paymentUnavailable('支付单查询响应无效');
            }
        }
        if (!is_string($data['payment_id']) || !is_string($data['out_trade_no']) || !is_string($data['status'])
            || !is_int($data['amount']) || !is_string($data['currency'])) {
            throw WalletRechargeException::paymentUnavailable('支付单查询响应字段类型无效');
        }

        return [
            'payment_id' => $data['payment_id'],
            'out_trade_no' => $data['out_trade_no'],
            'status' => $data['status'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'paid_at' => is_string($data['paid_at'] ?? null) ? $data['paid_at'] : null,
        ];
    }

    private function secretOrFail(): string
    {
        $secret = $this->cfg->secret();
        if ($secret === null) {
            throw WalletRechargeException::disabled('pay_not_configured');
        }

        return $secret;
    }

    /**
     * @return array{status:int,data:array}
     */
    private function send(string $method, string $path, ?array $payload, array $headers): array
    {
        try {
            $request = Http::withHeaders(array_merge([
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ], $headers))
                ->withOptions(['allow_redirects' => false])
                ->timeout($this->cfg->payTimeoutSeconds);

            $response = $method === 'get'
                ? $request->get($this->cfg->payBaseUrl . $path)
                : $request->post($this->cfg->payBaseUrl . $path, $payload ?? []);
        } catch (ConnectionException) {
            return ['status' => 0, 'data' => []];
        } catch (Throwable) {
            return ['status' => 0, 'data' => []];
        }

        $status = $response->status();
        $json = $response->json();
        if (!is_array($json) || !isset($json['status'])) {
            // 200 且 body 不可解析也不能当付款成功。
            return ['status' => 0, 'data' => []];
        }
        if ($json['status'] === 'fail') {
            return ['status' => $status, 'data' => is_array($json['data'] ?? null) ? $json['data'] : []];
        }
        if ($json['status'] !== 'success') {
            return ['status' => 0, 'data' => []];
        }
        $data = $json['data'] ?? null;
        if (!is_array($data)) {
            return ['status' => 0, 'data' => []];
        }

        return ['status' => $status, 'data' => $data];
    }

    private function assertCheckoutUrl(string $checkoutUrl): void
    {
        $base = parse_url($this->cfg->payBaseUrl);
        $checkout = parse_url($checkoutUrl);
        if (!is_array($base) || !is_array($checkout)) {
            throw WalletRechargeException::paymentUnavailable('收银台地址无效');
        }
        $baseScheme = strtolower((string) ($base['scheme'] ?? ''));
        $scheme = strtolower((string) ($checkout['scheme'] ?? ''));
        $allowedScheme = $baseScheme === 'http' && $this->isLoopback((string) ($base['host'] ?? '')) ? ['http', 'https'] : ['https'];
        if (!in_array($scheme, $allowedScheme, true)) {
            throw WalletRechargeException::paymentUnavailable('收银台地址协议无效');
        }
        if (($checkout['host'] ?? '') !== ($base['host'] ?? '')
            || (int) ($checkout['port'] ?? 0) !== (int) ($base['port'] ?? 0)
            || ($scheme === 'https' && $baseScheme !== 'https' && !$this->isLoopback((string) ($base['host'] ?? '')))
        ) {
            throw WalletRechargeException::paymentUnavailable('收银台地址不在支付域');
        }
    }

    private function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
