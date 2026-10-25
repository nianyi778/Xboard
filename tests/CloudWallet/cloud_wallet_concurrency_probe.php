<?php

declare(strict_types=1);

require __DIR__ . '/Support.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Run one job per child process against the same SQLite file. The parent
 * disconnects first so no PDO handle is shared across fork.
 *
 * @param array<int,array<string,mixed>> $jobs
 * @return array<int,array<string,mixed>>
 */
function cw_run_jobs(Application $app, array $jobs): array
{
    DB::disconnect();
    DB::purge('sqlite');

    $startAt = microtime(true) + 0.4;
    $files = [];
    $pids = [];

    foreach ($jobs as $index => $job) {
        $file = tempnam(sys_get_temp_dir(), 'cw-job-');
        if ($file === false) {
            throw new RuntimeException('failed to create job result file');
        }
        $files[$index] = $file;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }
        if ($pid === 0) {
            DB::purge('sqlite');
            while (microtime(true) < $startAt) {
                usleep(500);
            }

            try {
                if (($job['type'] ?? 'http') === 'subscription') {
                    $ok = App\Services\OrderWriteTransaction::run(
                        fn () => app(App\Services\UserService::class)->addBalance(
                            (int) $job['userId'],
                            -(int) $job['amountCents']
                        )
                    );
                    $result = ['ok' => $ok === true, 'via' => 'subscription', 'value' => $ok];
                } else {
                    $response = cw_send(
                        $app,
                        cw_path($job['endpoint']),
                        $job['body'],
                        null,
                        null,
                        [
                            'nonce' => $job['nonce'],
                            'timestamp' => $job['timestamp'],
                        ]
                    );
                    $result = [
                        'ok' => $response['status'] === 200,
                        'via' => 'cloud',
                        'status' => $response['status'],
                        'body' => $response['body'],
                    ];
                }
            } catch (Throwable $e) {
                $result = [
                    'ok' => false,
                    'via' => $job['type'] ?? 'http',
                    'error' => get_class($e) . ': ' . $e->getMessage(),
                ];
            }

            file_put_contents($file, json_encode($result));
            exit(0);
        }
        $pids[$index] = $pid;
    }

    foreach ($pids as $index => $pid) {
        pcntl_waitpid($pid, $status);
        $result = json_decode((string) file_get_contents($files[$index]), true);
        if (!is_array($result) || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('child job ' . $index . ' failed');
        }
        $jobs[$index]['result'] = $result;
        @unlink($files[$index]);
    }

    DB::reconnect('sqlite');

    return $jobs;
}

function cw_job(string $endpoint, array $body): array
{
    return [
        'type' => 'http',
        'endpoint' => $endpoint,
        'body' => $body,
        'nonce' => bin2hex(random_bytes(16)),
        'timestamp' => (string) time(),
    ];
}

$overlay = getenv('ORDER_LOCK_OVERLAY')
    ?: '/home/nianyi778/wsl2/github/tianjiv3/deploy/order-lock/OrderWriteTransaction.php';
if (!is_file($overlay)) {
    fwrite(STDERR, "OrderWriteTransaction overlay not found: {$overlay}\n");
    exit(2);
}
$boot = cw_boot();
$app = $boot['app'];

require_once $overlay;
if (!class_exists(App\Services\OrderWriteTransaction::class, false)) {
    fwrite(STDERR, "OrderWriteTransaction overlay did not load\n");
    exit(2);
}

try {
    cw_init_database();
    cw_seed_user(10, 1000);
    cw_seed_user(11, 1000);
    cw_seed_user(12, 1000);
    cw_seed_user(13, 1000);

    // Two different keys debit more than the balance in total.
    $jobs = cw_run_jobs($app, [
        cw_job('debit', [
            'userId' => 10,
            'orderNo' => 'CONCURRENT-A',
            'idempotencyKey' => 'concurrent-a',
            'amountCents' => 700,
            'currency' => 'CNY',
        ]),
        cw_job('debit', [
            'userId' => 10,
            'orderNo' => 'CONCURRENT-B',
            'idempotencyKey' => 'concurrent-b',
            'amountCents' => 700,
            'currency' => 'CNY',
        ]),
    ]);
    $statuses = array_map(static fn (array $job) => $job['result']['status'] ?? null, $jobs);
    sort($statuses);
    cw_assert_same([200, 409], $statuses, 'concurrent debits: exactly one succeeds');
    $statusCodes = array_map(static fn (array $job) => $job['result']['body']['code'] ?? null, $jobs);
    cw_assert(in_array('insufficient_funds', $statusCodes, true), 'concurrent loser reports insufficient_funds');
    cw_assert_same(300, cw_balance(10), 'concurrent debits never overdraw');
    cw_assert_same(1, (int) DB::table('v2_cloud_wallet_ledger')->where('user_id', 10)->count(), 'concurrent debits write one ledger');

    // Same idempotency key, two processes, different nonces.
    $payload = [
        'userId' => 11,
        'orderNo' => 'CONCURRENT-SAME',
        'idempotencyKey' => 'concurrent-same-key',
        'amountCents' => 100,
        'currency' => 'CNY',
    ];
    $jobs = cw_run_jobs($app, [cw_job('debit', $payload), cw_job('debit', $payload)]);
    foreach ($jobs as $job) {
        cw_assert_same(200, $job['result']['status'] ?? null, 'concurrent same key succeeds');
    }
    $ids = array_map(static fn (array $job) => $job['result']['body']['data']['id'] ?? null, $jobs);
    cw_assert_same($ids[0], $ids[1], 'concurrent same key returns the same ledger id');
    cw_assert_same(900, cw_balance(11), 'concurrent same key debits once');
    cw_assert_same(1, cw_ledger_count('concurrent-same-key'), 'concurrent same key writes one ledger');

    // Two different refund keys target the same original debit.
    $debit = cw_send($app, cw_path('debit'), [
        'userId' => 12,
        'orderNo' => 'CONCURRENT-REFUND',
        'idempotencyKey' => 'concurrent-refund-debit',
        'amountCents' => 200,
        'currency' => 'CNY',
    ]);
    $debitLedger = cw_assert_success($debit, 'concurrent refund fixture debit');
    $jobs = cw_run_jobs($app, [
        cw_job('refund', [
            'debitId' => $debitLedger['id'],
            'idempotencyKey' => 'concurrent-refund-a',
            'reason' => 'first',
        ]),
        cw_job('refund', [
            'debitId' => $debitLedger['id'],
            'idempotencyKey' => 'concurrent-refund-b',
            'reason' => 'second',
        ]),
    ]);
    $statuses = array_map(static fn (array $job) => $job['result']['status'] ?? null, $jobs);
    sort($statuses);
    cw_assert_same([200, 409], $statuses, 'concurrent double refund: exactly one succeeds');
    $codes = array_map(static fn (array $job) => $job['result']['body']['code'] ?? null, $jobs);
    cw_assert(in_array('already_refunded', $codes, true), 'concurrent double refund reports already_refunded');
    cw_assert_same(1000, cw_balance(12), 'concurrent double refund credits exactly once');
    cw_assert_same(1, (int) DB::table('v2_cloud_wallet_ledger')->where('debit_id', $debitLedger['id'])->count(), 'concurrent double refund writes one refund row');

    // Actual subscription balance debit (UserService + production order write overlay)
    // races the cloud wallet debit on the same user and SQLite file.
    $subscriptionJob = [
        'type' => 'subscription',
        'userId' => 13,
        'amountCents' => 700,
    ];
    $cloudJob = cw_job('debit', [
        'userId' => 13,
        'orderNo' => 'SUBSCRIPTION-RACE',
        'idempotencyKey' => 'subscription-race',
        'amountCents' => 700,
        'currency' => 'CNY',
    ]);
    $jobs = cw_run_jobs($app, [$subscriptionJob, $cloudJob]);
    $wins = array_values(array_filter($jobs, static fn (array $job) => ($job['result']['ok'] ?? false) === true));
    cw_assert_same(1, count($wins), 'subscription vs cloud: exactly one debit succeeds');
    cw_assert_same(300, cw_balance(13), 'subscription vs cloud leaves the correct balance');
    cw_assert(cw_balance(13) >= 0, 'subscription vs cloud never makes balance negative');
    $via = $wins[0]['result']['via'] ?? null;
    cw_assert(in_array($via, ['subscription', 'cloud'], true), 'subscription vs cloud reports the winning path');
    $cloudLedgerCount = cw_ledger_count('subscription-race');
    cw_assert(
        ($via === 'cloud' && $cloudLedgerCount === 1) || ($via === 'subscription' && $cloudLedgerCount === 0),
        'subscription vs cloud ledger agrees with the winner'
    );

    echo "\ncloud wallet concurrency probe: ALL PASS\n";
} finally {
    cw_cleanup($boot);
}
