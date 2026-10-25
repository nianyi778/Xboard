<?php

namespace App\Services\CloudWallet;

use App\Exceptions\CloudWalletException;

class CloudWalletKeyStore
{
    public function secretFor(string $keyId): ?string
    {
        $configuredKeyId = trim((string) config('cloud_wallet.key_id', ''));
        $path = (string) config('cloud_wallet.key_file', '');

        if ($keyId === '' || $path === '' || !is_file($path) || !is_readable($path)) {
            throw CloudWalletException::walletUnavailable();
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw CloudWalletException::walletUnavailable();
        }

        $contents = trim($contents);
        if ($contents === '') {
            throw CloudWalletException::walletUnavailable();
        }

        $keys = $this->decodeKeys($contents, $configuredKeyId);
        if ($keys === null) {
            throw CloudWalletException::walletUnavailable();
        }
        if (!array_key_exists($keyId, $keys)) {
            return null;
        }

        $secret = $this->decodeSecret((string) $keys[$keyId]);
        if ($secret === null || strlen($secret) < 32) {
            throw CloudWalletException::walletUnavailable();
        }

        return $secret;
    }

    /**
     * @return array<string, string>|null
     */
    private function decodeKeys(string $contents, string $configuredKeyId): ?array
    {
        if (!str_starts_with($contents, '{')) {
            return $configuredKeyId === '' ? null : [$configuredKeyId => $contents];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw CloudWalletException::walletUnavailable();
        }

        if (isset($decoded['keys']) && is_array($decoded['keys'])) {
            return array_map('strval', $decoded['keys']);
        }

        if (isset($decoded['keyId'], $decoded['secret'])) {
            return [(string) $decoded['keyId'] => (string) $decoded['secret']];
        }

        $keys = [];
        foreach ($decoded as $id => $secret) {
            if (is_string($id) && is_string($secret)) {
                $keys[$id] = $secret;
            }
        }

        return $keys === [] ? null : $keys;
    }

    private function decodeSecret(string $secret): ?string
    {
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            return $decoded === false ? null : $decoded;
        }

        return $secret;
    }
}
