<?php

namespace App\Services\WalletRecharge;

use App\Exceptions\WalletRechargeException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * 充值的写入事务。
 *
 * SQLite 的 lockForUpdate() 是空操作，因此与既有 CloudWalletTransaction 一致：
 * 在事务内、任何业务 SELECT 之前先做零行 UPDATE 取得写保留锁，避免
 * SQLITE_BUSY_SNAPSHOT。MySQL 保持普通事务，依赖行锁/条件 UPDATE/唯一约束。
 *
 * 死锁只做有界重试（最多 3 次），且整段闭包必须幂等；退款/入账仍由唯一约束
 * 与订单号保证最终只生效一次。
 */
final class WalletRechargeTransaction
{
    public const SQLITE_LOCK_WAIT_MS = 3000;
    public const SQLITE_RESERVE_SQL = 'UPDATE v2_wallet_recharge_orders SET id = id WHERE 0';
    public const MAX_ATTEMPTS = 3;

    public static function run(Closure $callback): mixed
    {
        return self::withRetry(fn () => self::once($callback));
    }

    private static function once(Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            return $connection->transaction($callback);
        }

        try {
            return $connection->transaction(function () use ($connection, $callback) {
                $pdo = $connection->getPdo();
                $previous = self::readBusyTimeout($pdo);
                self::setBusyTimeout($pdo, self::SQLITE_LOCK_WAIT_MS);
                try {
                    $statement = $pdo->query(self::SQLITE_RESERVE_SQL);
                    if ($statement === false) {
                        throw new \RuntimeException('Failed to reserve wallet recharge write lock.');
                    }
                    $statement->closeCursor();

                    return $callback();
                } finally {
                    if ($previous !== null) {
                        self::setBusyTimeout($pdo, $previous);
                    }
                }
            });
        } catch (PDOException $e) {
            if (self::isBusy($e)) {
                throw WalletRechargeException::busy();
            }
            throw $e;
        }
    }

    private static function withRetry(Closure $callback): mixed
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $callback();
            } catch (WalletRechargeException $e) {
                // 余额忙已经是有界等待后的结果，不再套一层重试。
                throw $e;
            } catch (UniqueConstraintViolationException $e) {
                throw $e;
            } catch (QueryException $e) {
                if (!self::isDeadlock($e) || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
                $last = $e;
                usleep(20_000 * $attempt);
            }
        }

        throw $last ?? new \RuntimeException('wallet recharge transaction failed');
    }

    public static function isBusy(PDOException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 5 || $driverCode === 6) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'database is busy');
    }

    public static function isDeadlock(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 1213 || $driverCode === 1205 || $driverCode === 40001) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }

    private static function readBusyTimeout(PDO $pdo): ?int
    {
        try {
            $value = $pdo->query('PRAGMA busy_timeout')->fetchColumn();

            return is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setBusyTimeout(PDO $pdo, int $milliseconds): void
    {
        $pdo->exec('PRAGMA busy_timeout = ' . max(0, $milliseconds));
    }
}
