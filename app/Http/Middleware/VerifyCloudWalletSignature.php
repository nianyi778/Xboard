<?php

namespace App\Http\Middleware;

use App\Exceptions\CloudWalletException;
use App\Services\CloudWallet\CloudWalletKeyStore;
use App\Services\CloudWallet\CloudWalletTransaction;
use App\Support\CloudWalletResponse;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

class VerifyCloudWalletSignature
{
    private const VERSION = 'tianji-wallet-v1';

    public function __construct(private readonly CloudWalletKeyStore $keyStore)
    {
    }

    public function handle(Request $request, Closure $next): JsonResponse
    {
        try {
            if (!(bool) config('cloud_wallet.enabled', false)) {
                throw CloudWalletException::walletDisabled();
            }

            if (strtoupper($request->getMethod()) !== 'POST') {
                throw CloudWalletException::invalidRequest('only POST is allowed');
            }

            if ($request->query->count() > 0 || $request->getQueryString() !== null) {
                throw CloudWalletException::invalidRequest('query parameters are not allowed');
            }

            $keyId = trim((string) $request->headers->get('X-Tianji-Wallet-Key-Id', ''));
            $timestamp = trim((string) $request->headers->get('X-Tianji-Wallet-Timestamp', ''));
            $nonce = trim((string) $request->headers->get('X-Tianji-Wallet-Nonce', ''));
            $signature = trim((string) $request->headers->get('X-Tianji-Wallet-Signature', ''));

            if ($keyId === '' || strlen($keyId) > 128) {
                throw CloudWalletException::invalidSignature('missing key id');
            }
            if (!preg_match('/^\d{1,10}$/', $timestamp)) {
                throw CloudWalletException::invalidTimestamp('timestamp must be epoch seconds');
            }
            if (!preg_match('/^[0-9a-fA-F]{32}$/', $nonce)) {
                throw CloudWalletException::invalidSignature('nonce must be 32 hex characters');
            }
            if (!preg_match('/^[0-9a-fA-F]{64}$/', $signature)) {
                throw CloudWalletException::invalidSignature('signature must be hex HMAC SHA256');
            }

            $clockSkew = (int) config('cloud_wallet.clock_skew_seconds', 60);
            if (abs(time() - (int) $timestamp) > $clockSkew) {
                throw CloudWalletException::invalidTimestamp('timestamp is outside the allowed window');
            }

            $secret = $this->keyStore->secretFor($keyId);
            if ($secret === null) {
                throw CloudWalletException::invalidSignature('unknown key id');
            }

            $canonical = implode("\n", [
                self::VERSION,
                'POST',
                $request->getPathInfo() ?: '/',
                hash('sha256', $request->getContent()),
                $timestamp,
                $nonce,
                $keyId,
            ]);
            $expected = hash_hmac('sha256', $canonical, $secret);
            if (!hash_equals($expected, strtolower($signature))) {
                throw CloudWalletException::invalidSignature();
            }

            $this->consumeNonce($keyId, $nonce);

            return $next($request);
        } catch (CloudWalletException $e) {
            return CloudWalletResponse::fail($e->httpStatus(), $e->errorCode(), $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return CloudWalletResponse::fail(409, 'nonce_reused', 'nonce already used');
        } catch (QueryException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof PDOException && CloudWalletTransaction::isBusy($previous)) {
                return CloudWalletResponse::fail(503, 'wallet_busy', 'wallet is busy, please retry');
            }

            return CloudWalletResponse::fail(500, 'internal_error', 'internal error');
        } catch (Throwable) {
            return CloudWalletResponse::fail(500, 'internal_error', 'internal error');
        }
    }

    private function consumeNonce(string $keyId, string $nonce): void
    {
        $now = time();
        $ttl = max(1, (int) config('cloud_wallet.nonce_ttl_seconds', 300));
        $normalized = strtolower($nonce);

        DB::transaction(function () use ($keyId, $normalized, $now, $ttl): void {
            DB::table('v2_cloud_wallet_nonce')
                ->where('expires_at', '<', $now)
                ->delete();

            DB::table('v2_cloud_wallet_nonce')->insert([
                'nonce' => $normalized,
                'key_id' => $keyId,
                'created_at' => $now,
                'expires_at' => $now + $ttl,
            ]);
        });
    }
}
