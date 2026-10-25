<?php

namespace App\Exceptions;

use RuntimeException;

class CloudWalletException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function invalidRequest(string $message = 'invalid request'): self
    {
        return new self(400, 'invalid_request', $message);
    }

    public static function invalidSignature(string $message = 'invalid signature'): self
    {
        return new self(401, 'invalid_signature', $message);
    }

    public static function invalidTimestamp(string $message = 'invalid timestamp'): self
    {
        return new self(401, 'invalid_timestamp', $message);
    }

    public static function nonceReused(string $message = 'nonce already used'): self
    {
        return new self(409, 'nonce_reused', $message);
    }

    public static function walletDisabled(): self
    {
        return new self(503, 'wallet_disabled', 'wallet is disabled');
    }

    public static function walletUnavailable(): self
    {
        return new self(503, 'wallet_unavailable', 'wallet is unavailable');
    }

    public static function walletBusy(): self
    {
        return new self(503, 'wallet_busy', 'wallet is busy, please retry');
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'resource not found');
    }

    public static function idempotencyConflict(): self
    {
        return new self(409, 'idempotency_conflict', 'idempotency key was used with a different payload');
    }

    public static function insufficientFunds(): self
    {
        return new self(409, 'insufficient_funds', 'insufficient funds');
    }

    public static function userBanned(): self
    {
        return new self(403, 'user_banned', 'user is banned');
    }

    public static function alreadyRefunded(): self
    {
        return new self(409, 'already_refunded', 'debit has already been refunded');
    }

    public static function balanceOverflow(): self
    {
        return new self(409, 'balance_overflow', 'balance would overflow');
    }
}
