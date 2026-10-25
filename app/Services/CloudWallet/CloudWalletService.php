<?php

namespace App\Services\CloudWallet;

use App\Exceptions\CloudWalletException;
use App\Models\CloudWalletLedger;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CloudWalletService
{
    private const CURRENCY = 'CNY';
    private const TIMEZONE = 'Asia/Shanghai';

    public function balance(array $payload): array
    {
        $userId = $this->positiveInt($payload['userId'] ?? null, 'userId', 2147483647);
        $user = DB::table('v2_user')->where('id', $userId)->first(['id', 'balance']);
        if (!$user) {
            throw CloudWalletException::notFound();
        }

        return [
            'userId' => (int) $user->id,
            'balanceCents' => (int) $user->balance,
            'currency' => self::CURRENCY,
        ];
    }

    public function debit(array $payload): array
    {
        $userId = $this->positiveInt($payload['userId'] ?? null, 'userId', 2147483647);
        $orderNo = $this->stringValue($payload['orderNo'] ?? null, 'orderNo', 64);
        $idempotencyKey = $this->stringValue($payload['idempotencyKey'] ?? null, 'idempotencyKey', 128);
        $amountCents = $this->positiveInt($payload['amountCents'] ?? null, 'amountCents', $this->maxAmount());
        $currency = $this->currency($payload['currency'] ?? null);
        $requestHash = $this->hash([
            'kind' => CloudWalletLedger::KIND_DEBIT,
            'userId' => $userId,
            'orderNo' => $orderNo,
            'idempotencyKey' => $idempotencyKey,
            'amountCents' => $amountCents,
            'currency' => $currency,
        ]);

        try {
            $result = CloudWalletTransaction::run(function () use (
                $userId,
                $orderNo,
                $idempotencyKey,
                $amountCents,
                $currency,
                $requestHash
            ): array {
                $existing = CloudWalletLedger::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->replay($existing, $requestHash);
                }

                $decision = DB::table('v2_cloud_wallet_debit_decisions')
                    ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($decision) return $this->decisionResult($decision, $requestHash);
                // Separate from the key: one successful debit per cloud order.
                if (CloudWalletLedger::where('source', 'cloud')->where('order_no', $orderNo)
                    ->where('kind', 'debit')->exists()) {
                    throw CloudWalletException::idempotencyConflict();
                }
                DB::table('v2_cloud_wallet_debit_decisions')->insert([
                    'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                    'outcome' => 'pending', 'error_code' => null, 'ledger_id' => null, 'created_at' => time(),
                ]);

                $affected = DB::table('v2_user')
                    ->where('id', $userId)
                    ->where('banned', 0)
                    ->where('balance', '>=', $amountCents)
                    ->decrement('balance', $amountCents);

                if ($affected === 0) {
                    try { $this->raiseDebitFailure($userId); }
                    catch (CloudWalletException $e) {
                        DB::table('v2_cloud_wallet_debit_decisions')->where('idempotency_key', $idempotencyKey)
                            ->update(['outcome' => 'rejected', 'error_code' => $e->errorCode()]);
                        // Commit the rejection before returning an error to the caller.
                        return ['_rejection' => $e->errorCode()];
                    }
                }

                $balanceAfter = (int) DB::table('v2_user')->where('id', $userId)->value('balance');

                $ledger = $this->insertLedger([
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'user_id' => $userId,
                    'order_no' => $orderNo,
                    'kind' => CloudWalletLedger::KIND_DEBIT,
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'balance_after_cents' => $balanceAfter,
                    'debit_id' => null,
                    'reason' => null,
                ]);
                DB::table('v2_cloud_wallet_debit_decisions')->where('idempotency_key', $idempotencyKey)
                    ->update(['outcome' => 'accepted', 'ledger_id' => (int) $ledger['id']]);
                return $ledger;
            });
        } catch (UniqueConstraintViolationException $e) {
            $result = $this->resolveDebitRace($idempotencyKey, $requestHash);
        }
        return $this->unwrapDecision($result);
    }

    public function refund(array $payload): array
    {
        $debitId = $this->ledgerId($payload['debitId'] ?? null, 'debitId');
        $idempotencyKey = $this->stringValue($payload['idempotencyKey'] ?? null, 'idempotencyKey', 128);
        $reason = $this->stringValue($payload['reason'] ?? null, 'reason', 255);
        if (trim($reason) === '') throw CloudWalletException::invalidRequest('reason is required');
        $requestHash = $this->hash([
            'kind' => CloudWalletLedger::KIND_REFUND,
            'debitId' => (string) $debitId,
            'idempotencyKey' => $idempotencyKey,
            'reason' => $reason,
        ]);

        try {
            return CloudWalletTransaction::run(function () use (
                $debitId,
                $idempotencyKey,
                $reason,
                $requestHash
            ): array {
                $existing = CloudWalletLedger::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->replay($existing, $requestHash);
                }

                $original = CloudWalletLedger::query()
                    ->where('id', $debitId)
                    ->where('kind', CloudWalletLedger::KIND_DEBIT)
                    ->where('source', CloudWalletLedger::SOURCE_CLOUD)
                    ->first();
                if (!$original) {
                    throw CloudWalletException::notFound();
                }

                $alreadyRefunded = CloudWalletLedger::query()
                    ->where('debit_id', $original->id)
                    ->first();
                if ($alreadyRefunded) {
                    throw CloudWalletException::alreadyRefunded();
                }

                $amountCents = (int) $original->amount_cents;
                $maxAmount = $this->maxAmount();
                $affected = DB::table('v2_user')
                    ->where('id', $original->user_id)
                    ->where('balance', '<=', $maxAmount - $amountCents)
                    ->increment('balance', $amountCents);

                if ($affected === 0) {
                    $user = DB::table('v2_user')->where('id', $original->user_id)->first(['id']);
                    if (!$user) {
                        throw CloudWalletException::notFound();
                    }
                    throw CloudWalletException::balanceOverflow();
                }

                $balanceAfter = (int) DB::table('v2_user')
                    ->where('id', $original->user_id)
                    ->value('balance');

                return $this->insertLedger([
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'user_id' => (int) $original->user_id,
                    'order_no' => (string) $original->order_no,
                    'kind' => CloudWalletLedger::KIND_REFUND,
                    'amount_cents' => $amountCents,
                    'currency' => (string) $original->currency,
                    'balance_after_cents' => $balanceAfter,
                    'debit_id' => (int) $original->id,
                    'reason' => $reason,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->resolveRefundRace($idempotencyKey, $requestHash, $debitId);
        }
    }

    public function lookup(array $payload): array
    {
        $idempotencyKey = $this->stringValue($payload['idempotencyKey'] ?? null, 'idempotencyKey', 128);
        $ledger = CloudWalletLedger::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if (!$ledger) {
            throw CloudWalletException::notFound();
        }

        return $ledger->toContract();
    }

    public function reconcile(array $payload): array
    {
        $day = $this->day($payload['day'] ?? null);
        $afterId = $this->optionalNonNegativeInt($payload['afterId'] ?? null, 'afterId');
        $asOfId = $this->optionalNonNegativeInt($payload['asOfId'] ?? null, 'asOfId');
        $limit = $this->limit($payload['limit'] ?? null);
        [$start, $end] = $this->dayWindow($day);

        if ($asOfId === null) {
            $asOfId = (int) CloudWalletLedger::query()->max('id');
        }

        $query = CloudWalletLedger::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where('id', '<=', $asOfId)
            ->orderBy('id');

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $ledgers = $query->limit($limit + 1)->get();
        $hasMore = $ledgers->count() > $limit;
        if ($hasMore) {
            $ledgers = $ledgers->slice(0, $limit)->values();
        }

        $rows = $ledgers->map(fn (CloudWalletLedger $ledger) => $ledger->toContract())->all();
        $nextAfterId = $hasMore && $ledgers->isNotEmpty()
            ? (int) $ledgers->last()->id
            : null;

        return [
            'day' => $day,
            'timezone' => self::TIMEZONE,
            'asOfId' => (int) $asOfId,
            'rows' => $rows,
            'nextAfterId' => $nextAfterId,
        ];
    }

    private function raiseDebitFailure(int $userId): never
    {
        $user = DB::table('v2_user')->where('id', $userId)->first(['id', 'banned']);
        if (!$user) {
            throw CloudWalletException::notFound();
        }
        if ((int) $user->banned === 1) {
            throw CloudWalletException::userBanned();
        }

        throw CloudWalletException::insufficientFunds();
    }

    private function resolveDebitRace(string $idempotencyKey, string $requestHash): array
    {
        $existing = CloudWalletLedger::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        $decision = DB::table('v2_cloud_wallet_debit_decisions')->where('idempotency_key', $idempotencyKey)->first();
        if ($decision) return $this->decisionResult($decision, $requestHash);
        throw CloudWalletException::idempotencyConflict();
    }

    private function decisionResult(object $decision, string $requestHash): array
    {
        if (!hash_equals($decision->request_hash, $requestHash)) throw CloudWalletException::idempotencyConflict();
        if ($decision->outcome === 'rejected') return ['_rejection' => $decision->error_code];
        if ($decision->outcome !== 'accepted' || !$decision->ledger_id) throw CloudWalletException::walletBusy();
        $ledger = CloudWalletLedger::find($decision->ledger_id);
        if (!$ledger) throw CloudWalletException::walletBusy();
        return $ledger->toContract();
    }

    private function unwrapDecision(array $result): array
    {
        if (!isset($result['_rejection'])) return $result;
        throw match ($result['_rejection']) {
            'insufficient_funds' => CloudWalletException::insufficientFunds(),
            'user_banned' => CloudWalletException::userBanned(),
            'not_found' => CloudWalletException::notFound(),
            default => CloudWalletException::walletUnavailable(),
        };
    }

    private function resolveRefundRace(string $idempotencyKey, string $requestHash, int $debitId): array
    {
        $existing = CloudWalletLedger::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        if (CloudWalletLedger::query()->where('debit_id', $debitId)->exists()) {
            throw CloudWalletException::alreadyRefunded();
        }

        throw CloudWalletException::walletBusy();
    }

    private function replay(CloudWalletLedger $ledger, string $requestHash): array
    {
        if (!hash_equals((string) $ledger->request_hash, $requestHash)) {
            throw CloudWalletException::idempotencyConflict();
        }

        return $ledger->toContract();
    }

    private function insertLedger(array $values): array
    {
        $ledger = new CloudWalletLedger();
        $ledger->forceFill($values + [
            'source' => CloudWalletLedger::SOURCE_CLOUD,
            'created_at' => time(),
        ]);
        $ledger->save();

        return $ledger->toContract();
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function positiveInt(mixed $value, string $field, int $max): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw CloudWalletException::invalidRequest($field . ' must be a positive JSON integer');
        }

        return $value;
    }

    private function optionalNonNegativeInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw CloudWalletException::invalidRequest($field . ' must be a non-negative JSON integer');
        }

        return $value;
    }

    private function ledgerId(mixed $value, string $field): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value) || strlen($value) > 19) {
            throw CloudWalletException::invalidRequest($field . ' must be a positive integer string');
        }

        $id = (int) $value;
        if ($id < 1 || (string) $id !== $value) {
            throw CloudWalletException::invalidRequest($field . ' must be a positive integer string');
        }

        return $id;
    }

    private function stringValue(mixed $value, string $field, int $maxLength, bool $allowEmpty = false): string
    {
        if (!is_string($value) || (!$allowEmpty && $value === '') || strlen($value) > $maxLength) {
            throw CloudWalletException::invalidRequest($field . ' must be a valid string');
        }

        return $value;
    }

    private function currency(mixed $value): string
    {
        if ($value !== self::CURRENCY) {
            throw CloudWalletException::invalidRequest('currency must be CNY');
        }

        return self::CURRENCY;
    }

    private function day(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw CloudWalletException::invalidRequest('day must be YYYY-MM-DD');
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw CloudWalletException::invalidRequest('day must be a valid date');
        }

        return $value;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function dayWindow(string $day): array
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $timezone)->getTimestamp();
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $timezone)
            ->modify('+1 day')
            ->getTimestamp();

        return [$start, $end];
    }

    private function limit(mixed $value): int
    {
        if ($value === null) {
            return (int) config('cloud_wallet.reconcile_default_limit', 100);
        }
        if (!is_int($value) || $value < 1 || $value > $this->maxLimit()) {
            throw CloudWalletException::invalidRequest('limit must be between 1 and ' . $this->maxLimit());
        }

        return $value;
    }

    private function maxAmount(): int
    {
        return (int) config('cloud_wallet.max_amount_cents', 2147483647);
    }

    private function maxLimit(): int
    {
        return (int) config('cloud_wallet.reconcile_max_limit', 500);
    }
}
