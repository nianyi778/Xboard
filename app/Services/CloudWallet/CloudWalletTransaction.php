<?php

namespace App\Services\CloudWallet;

use App\Exceptions\CloudWalletException;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * SQLite has deferred transactions and lockForUpdate() is a no-op. Take the
 * write reservation before any business SELECT so a later write cannot fail
 * with SQLITE_BUSY_SNAPSHOT. MySQL keeps the normal transaction path and relies
 * on the atomic conditional UPDATE plus unique constraints.
 */
class CloudWalletTransaction
{
    public const SQLITE_LOCK_WAIT_MS = 3000;
    public const SQLITE_RESERVE_SQL = 'UPDATE v2_cloud_wallet_ledger SET id = id WHERE 0';

    public static function run(Closure $callback): mixed
    {
        return self::runOn(DB::connection(), $callback);
    }

    public static function runOn(ConnectionInterface $connection, Closure $callback): mixed
    {
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
                        throw new \RuntimeException('Failed to reserve cloud wallet write lock.');
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
                throw CloudWalletException::walletBusy();
            }
            throw $e;
        }
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
