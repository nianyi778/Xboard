<?php

namespace App\Exceptions;

use RuntimeException;

class WalletRechargeException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $errorCode,
        string $message,
        private readonly array $data = []
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

    public function data(): array
    {
        return $this->data;
    }

    public static function invalidRequest(string $message = 'invalid request'): self
    {
        return new self(400, 'invalid_request', $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'resource not found');
    }

    public static function forbidden(string $message = 'forbidden'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function disabled(string $reason = 'disabled'): self
    {
        return new self(503, 'recharge_disabled', '充值暂未开放', ['reason' => $reason]);
    }

    public static function idempotencyConflict(): self
    {
        return new self(409, 'idempotency_conflict', 'idempotency key was used with a different payload');
    }

    public static function stateConflict(string $message = 'order state does not allow this operation'): self
    {
        return new self(409, 'state_conflict', $message);
    }

    public static function paymentUnavailable(string $message = 'payment provider is unavailable'): self
    {
        return new self(503, 'payment_unavailable', $message);
    }

    public static function paymentUnknown(string $message = 'payment result is unknown'): self
    {
        return new self(503, 'payment_unknown', $message, ['state' => 'unknown']);
    }

    public static function invalidSignature(): self
    {
        return new self(401, 'invalid_signature', 'invalid signature');
    }

    public static function invalidTimestamp(): self
    {
        return new self(401, 'invalid_timestamp', 'invalid timestamp');
    }

    public static function verificationFailed(string $message = 'payment could not be verified'): self
    {
        return new self(409, 'verification_failed', $message);
    }

    public static function busy(): self
    {
        return new self(503, 'recharge_busy', 'wallet is busy, please retry');
    }
}
