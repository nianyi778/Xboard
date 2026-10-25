<?php

declare(strict_types=1);

require __DIR__ . '/Support.php';

use Illuminate\Support\Facades\DB;

const CW_MAX_AMOUNT = 2147483647;

$boot = cw_boot();
$app = $boot['app'];

try {
    cw_init_database();
    cw_seed_user(1, 1000);
    cw_seed_user(2, 1000);
    cw_seed_user(3, CW_MAX_AMOUNT - 100);
    cw_seed_user(4, 1000, true);
    cw_seed_user(5, 100);

    // Default-off switch is checked before any signature work.
    config(['cloud_wallet.enabled' => false]);
    $response = cw_send($app, cw_path('balance'), ['userId' => 1], null, null, [
        'headers' => [
            'X-Tianji-Wallet-Key-Id' => null,
            'X-Tianji-Wallet-Timestamp' => null,
            'X-Tianji-Wallet-Nonce' => null,
            'X-Tianji-Wallet-Signature' => null,
        ],
    ]);
    cw_assert_status($response, 503, 'wallet_disabled', 'disabled wallet rejects without signature');
    config(['cloud_wallet.enabled' => true]);

    $response = cw_send($app, cw_path('balance'), ['userId' => 1], null, null, ['query' => 'debug=1']);
    cw_assert_status($response, 400, 'invalid_request', 'query parameters are rejected');

    $response = cw_send($app, cw_path('balance'), ['userId' => 1], null, null, [
        'signature' => str_repeat('0', 64),
    ]);
    cw_assert_status($response, 401, 'invalid_signature', 'tampered signature is rejected');

    $response = cw_send($app, cw_path('balance'), ['userId' => 1], null, null, [
        'timestamp' => (string) (time() - 61),
    ]);
    cw_assert_status($response, 401, 'invalid_timestamp', 'expired timestamp is rejected');

    $response = cw_send($app, cw_path('balance'), ['userId' => 1], null, null, [
        'nonce' => 'not-a-32-hex-nonce',
    ]);
    cw_assert_status($response, 401, 'invalid_signature', 'malformed nonce is rejected');

    $response = cw_send($app, cw_path('balance'), ['userId' => 1], null, null, [
        'headers' => ['X-Tianji-Wallet-Signature' => null],
    ]);
    cw_assert_status($response, 401, 'invalid_signature', 'missing signature is rejected');

    $response = cw_send($app, cw_path('balance'), ['userId' => 1], 'unknown-key');
    cw_assert_status($response, 401, 'invalid_signature', 'unknown key id is rejected');

    $configuredKeyFile = config('cloud_wallet.key_file');
    config(['cloud_wallet.key_file' => '/nonexistent/cloud-wallet.key']);
    $response = cw_send($app, cw_path('balance'), ['userId' => 1]);
    cw_assert_status($response, 503, 'wallet_unavailable', 'missing key file is a controlled 503');
    config(['cloud_wallet.key_file' => $configuredKeyFile]);

    $response = cw_send($app, cw_path('debit'), ['userId' => 1], null, null, [
        'signed_path' => cw_path('balance'),
    ]);
    cw_assert_status($response, 401, 'invalid_signature', 'signature bound to another path is rejected');

    $response = cw_send($app, cw_path('balance'), ['userId' => 1]);
    $data = cw_assert_success($response, 'balance is readable with a valid signature');
    cw_assert_same(1000, $data['balanceCents'] ?? null, 'balance value');
    cw_assert_same('CNY', $data['currency'] ?? null, 'balance currency');

    $response = cw_send($app, cw_path('balance'), ['userId' => 999]);
    cw_assert_status($response, 404, 'not_found', 'balance for missing user');

    $debitPayload = [
        'userId' => 1,
        'orderNo' => 'ORDER-1',
        'idempotencyKey' => 'idem-debit-1',
        'amountCents' => 300,
        'currency' => 'CNY',
    ];
    $debit = cw_send($app, cw_path('debit'), $debitPayload);
    $debitLedger = cw_assert_success($debit, 'debit succeeds');
    cw_assert_same('idem-debit-1', $debitLedger['idempotencyKey'] ?? null, 'debit idempotency key');
    cw_assert_same('debit', $debitLedger['kind'] ?? null, 'debit kind');
    cw_assert_same(300, $debitLedger['amountCents'] ?? null, 'debit amount');
    cw_assert_same(700, $debitLedger['balanceAfterCents'] ?? null, 'debit balance after');
    cw_assert_same(null, $debitLedger['debitId'] ?? null, 'debit debitId is null');
    cw_assert_same('ORDER-1', $debitLedger['orderNo'] ?? null, 'debit order number');
    cw_assert_same(700, cw_balance(1), 'balance is persisted');
    cw_assert((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($debitLedger['createdAt'] ?? '')), 'createdAt is UTC ISO');

    $replaySameNonce = cw_send($app, cw_path('debit'), $debitPayload, null, null, [
        'timestamp' => $debit['request']['timestamp'],
        'nonce' => $debit['request']['nonce'],
        'signature' => $debit['request']['signature'],
    ]);
    cw_assert_status($replaySameNonce, 409, 'nonce_reused', 'same nonce replay is rejected');

    $replayNewNonce = cw_send($app, cw_path('debit'), $debitPayload);
    $replayLedger = cw_assert_success($replayNewNonce, 'same idempotency payload with a fresh nonce succeeds');
    cw_assert_same($debitLedger['id'] ?? null, $replayLedger['id'] ?? null, 'replay returns same ledger id');
    cw_assert_same(700, cw_balance(1), 'replay does not debit twice');

    $conflictPayload = $debitPayload;
    $conflictPayload['amountCents'] = 301;
    $response = cw_send($app, cw_path('debit'), $conflictPayload);
    cw_assert_status($response, 409, 'idempotency_conflict', 'same key with different payload conflicts');
    cw_assert_same(700, cw_balance(1), 'conflict does not change balance');

    $response = cw_send($app, cw_path('debit'), [
        'userId' => 1,
        'orderNo' => 'ORDER-2',
        'idempotencyKey' => 'idem-insufficient',
        'amountCents' => 800,
        'currency' => 'CNY',
    ]);
    cw_assert_status($response, 409, 'insufficient_funds', 'insufficient debit is rejected');
    cw_assert_same(700, cw_balance(1), 'insufficient debit leaves balance unchanged');
    cw_assert_same(0, cw_ledger_count('idem-insufficient'), 'insufficient debit writes no ledger');

    $response = cw_send($app, cw_path('debit'), [
        'userId' => 4,
        'orderNo' => 'ORDER-BANNED',
        'idempotencyKey' => 'idem-banned',
        'amountCents' => 100,
        'currency' => 'CNY',
    ]);
    cw_assert_status($response, 403, 'user_banned', 'banned user debit is rejected');

    $bannedDebit = cw_send($app, cw_path('debit'), [
        'userId' => 2,
        'orderNo' => 'ORDER-BANNED-REFUND',
        'idempotencyKey' => 'idem-banned-refund-debit',
        'amountCents' => 200,
        'currency' => 'CNY',
    ]);
    $bannedDebitLedger = cw_assert_success($bannedDebit, 'debit before ban succeeds');
    DB::table('v2_user')->where('id', 2)->update(['banned' => 1]);
    $bannedRefund = cw_send($app, cw_path('refund'), [
        'debitId' => $bannedDebitLedger['id'],
        'idempotencyKey' => 'idem-banned-refund',
        'reason' => 'banned user refund',
    ]);
    $bannedRefundLedger = cw_assert_success($bannedRefund, 'refund to a banned user is allowed');
    cw_assert_same(1000, cw_balance(2), 'banned user refund restores balance');
    cw_assert_same('refund', $bannedRefundLedger['kind'] ?? null, 'banned refund kind');

    $refundPayload = [
        'debitId' => $debitLedger['id'],
        'idempotencyKey' => 'idem-refund-1',
        'reason' => 'customer request',
    ];
    $refund = cw_send($app, cw_path('refund'), $refundPayload);
    $refundLedger = cw_assert_success($refund, 'refund succeeds');
    cw_assert_same('refund', $refundLedger['kind'] ?? null, 'refund kind');
    cw_assert_same(300, $refundLedger['amountCents'] ?? null, 'refund uses original amount');
    cw_assert_same($debitLedger['id'], $refundLedger['debitId'] ?? null, 'refund references original debit');
    cw_assert_same('ORDER-1', $refundLedger['orderNo'] ?? null, 'refund keeps original order number');
    cw_assert_same(1000, cw_balance(1), 'refund restores balance');
    cw_assert_same(1000, $refundLedger['balanceAfterCents'] ?? null, 'refund balance after');

    $refundReplay = cw_send($app, cw_path('refund'), $refundPayload);
    $refundReplayLedger = cw_assert_success($refundReplay, 'same refund payload replays');
    cw_assert_same($refundLedger['id'] ?? null, $refundReplayLedger['id'] ?? null, 'refund replay returns same ledger');
    cw_assert_same(1000, cw_balance(1), 'refund replay does not credit twice');

    $refundConflict = $refundPayload;
    $refundConflict['reason'] = 'different reason';
    $response = cw_send($app, cw_path('refund'), $refundConflict);
    cw_assert_status($response, 409, 'idempotency_conflict', 'refund reason participates in idempotency conflict');

    $secondRefundKey = $refundPayload;
    $secondRefundKey['idempotencyKey'] = 'idem-refund-2';
    $response = cw_send($app, cw_path('refund'), $secondRefundKey);
    cw_assert_status($response, 409, 'already_refunded', 'different key cannot refund the same debit twice');
    cw_assert_same(1000, cw_balance(1), 'double refund leaves balance unchanged');
    cw_assert_same(1, (int) DB::table('v2_cloud_wallet_ledger')->where('debit_id', $debitLedger['id'])->count(), 'one refund row per debit');

    $response = cw_send($app, cw_path('refund'), [
        'debitId' => '999999',
        'idempotencyKey' => 'idem-refund-missing',
        'reason' => 'missing',
    ]);
    cw_assert_status($response, 404, 'not_found', 'refund of unknown debit is not found');

    $response = cw_send($app, cw_path('lookup'), ['idempotencyKey' => 'missing-key']);
    cw_assert_status($response, 404, 'not_found', 'lookup of unknown key is not found');

    $lookup = cw_send($app, cw_path('lookup'), ['idempotencyKey' => 'idem-debit-1']);
    $lookupLedger = cw_assert_success($lookup, 'lookup returns ledger');
    cw_assert_same($debitLedger['id'] ?? null, $lookupLedger['id'] ?? null, 'lookup returns the original ledger');

    $extraDebit = cw_send($app, cw_path('debit'), [
        'userId' => 5,
        'orderNo' => 'ORDER-EXTRA',
        'idempotencyKey' => 'idem-extra-debit',
        'amountCents' => 40,
        'currency' => 'CNY',
    ]);
    $extraDebitLedger = cw_assert_success($extraDebit, 'extra-field refund fixture debit');
    $extraRefund = cw_send($app, cw_path('refund'), [
        'debitId' => $extraDebitLedger['id'],
        'idempotencyKey' => 'idem-extra-refund',
        'reason' => 'ignore injected fields',
        'userId' => 999,
        'amountCents' => 1,
    ]);
    $extraRefundLedger = cw_assert_success($extraRefund, 'refund ignores injected user and amount fields');
    cw_assert_same(40, $extraRefundLedger['amountCents'] ?? null, 'refund amount always comes from original debit');
    cw_assert_same(5, $extraRefundLedger['userId'] ?? null, 'refund user always comes from original debit');
    cw_assert_same(100, cw_balance(5), 'refund credits the original user only');

    $invalidPayloads = [
        'userId zero' => ['userId' => 0, 'orderNo' => 'O', 'idempotencyKey' => 'bad-1', 'amountCents' => 1, 'currency' => 'CNY'],
        'amount zero' => ['userId' => 1, 'orderNo' => 'O', 'idempotencyKey' => 'bad-3', 'amountCents' => 0, 'currency' => 'CNY'],
        'amount negative' => ['userId' => 1, 'orderNo' => 'O', 'idempotencyKey' => 'bad-4', 'amountCents' => -1, 'currency' => 'CNY'],
        'amount float' => ['userId' => 1, 'orderNo' => 'O', 'idempotencyKey' => 'bad-5', 'amountCents' => 1.5, 'currency' => 'CNY'],
        'amount string' => ['userId' => 1, 'orderNo' => 'O', 'idempotencyKey' => 'bad-6', 'amountCents' => '1', 'currency' => 'CNY'],
        'amount too large' => ['userId' => 1, 'orderNo' => 'O', 'idempotencyKey' => 'bad-7', 'amountCents' => CW_MAX_AMOUNT + 1, 'currency' => 'CNY'],
        'currency wrong' => ['userId' => 1, 'orderNo' => 'O', 'idempotencyKey' => 'bad-8', 'amountCents' => 1, 'currency' => 'USD'],
    ];
    foreach ($invalidPayloads as $label => $payload) {
        $response = cw_send($app, cw_path('debit'), $payload);
        cw_assert_status($response, 400, 'invalid_request', 'strict amount/user validation: ' . $label);
    }
    $rawFloatUser = '{"userId":1.0,"orderNo":"O","idempotencyKey":"bad-float-user","amountCents":1,"currency":"CNY"}';
    $response = cw_send($app, cw_path('debit'), $rawFloatUser, null, null, ['raw' => $rawFloatUser]);
    cw_assert_status($response, 400, 'invalid_request', 'strict amount/user validation: userId 1.0');

    $rawFloatAmount = '{"userId":1,"orderNo":"O","idempotencyKey":"bad-float-amount","amountCents":1.0,"currency":"CNY"}';
    $response = cw_send($app, cw_path('debit'), $rawFloatAmount, null, null, ['raw' => $rawFloatAmount]);
    cw_assert_status($response, 400, 'invalid_request', 'strict amount/user validation: amountCents 1.0');

    cw_assert_same(1000, cw_balance(1), 'invalid payloads leave balance unchanged');

    $response = cw_send($app, cw_path('debit'), '{bad json', null, null, ['raw' => '{bad json']);
    cw_assert_status($response, 400, 'invalid_request', 'invalid JSON body is rejected');

    $overflowDebit = cw_send($app, cw_path('debit'), [
        'userId' => 3,
        'orderNo' => 'ORDER-OVERFLOW',
        'idempotencyKey' => 'idem-overflow-debit',
        'amountCents' => 50,
        'currency' => 'CNY',
    ]);
    $overflowLedger = cw_assert_success($overflowDebit, 'overflow fixture debit succeeds');
    DB::table('v2_user')->where('id', 3)->update(['balance' => CW_MAX_AMOUNT - 10]);
    $response = cw_send($app, cw_path('refund'), [
        'debitId' => $overflowLedger['id'],
        'idempotencyKey' => 'idem-overflow-refund',
        'reason' => 'would overflow',
    ]);
    cw_assert_status($response, 409, 'balance_overflow', 'refund that would overflow is rejected');
    cw_assert_same(CW_MAX_AMOUNT - 10, cw_balance(3), 'overflow refund leaves balance unchanged');
    cw_assert_same(0, cw_ledger_count('idem-overflow-refund'), 'overflow refund writes no ledger');

    DB::statement(
        "CREATE TRIGGER cw_fail_ledger BEFORE INSERT ON v2_cloud_wallet_ledger "
        . "WHEN NEW.idempotency_key = 'idem-rollback' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END"
    );
    $before = cw_balance(1);
    $response = cw_send($app, cw_path('debit'), [
        'userId' => 1,
        'orderNo' => 'ORDER-ROLLBACK',
        'idempotencyKey' => 'idem-rollback',
        'amountCents' => 100,
        'currency' => 'CNY',
    ]);
    cw_assert_status($response, 500, 'internal_error', 'mid-transaction ledger failure is generic');
    DB::statement('DROP TRIGGER cw_fail_ledger');
    cw_assert_same($before, cw_balance(1), 'mid-transaction failure rolls balance back');
    cw_assert_same(0, cw_ledger_count('idem-rollback'), 'mid-transaction failure writes no ledger');
    cw_assert_same(0, DB::transactionLevel(), 'transaction level is clean after rollback');
    cw_assert(!DB::connection()->getPdo()->inTransaction(), 'PDO is not left in a transaction');

    // Reconcile uses a fixed Asia/Shanghai day window.
    $day = '2026-10-24';
    $timezone = new DateTimeZone('Asia/Shanghai');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $timezone)->getTimestamp();
    DB::table('v2_cloud_wallet_ledger')->update(['created_at' => $start + 3600]);
    $ledgerCount = cw_ledger_count();
    cw_assert($ledgerCount >= 4, 'reconcile fixture has multiple ledger rows');

    $reconcile = cw_send($app, cw_path('reconcile'), ['day' => $day, 'limit' => 2]);
    $page1 = cw_assert_success($reconcile, 'reconcile page one succeeds');
    cw_assert_same($day, $page1['day'] ?? null, 'reconcile echoes day');
    cw_assert_same('Asia/Shanghai', $page1['timezone'] ?? null, 'reconcile timezone');
    cw_assert_same(2, count($page1['rows'] ?? []), 'reconcile respects limit');
    $asOfId = (int) ($page1['asOfId'] ?? 0);
    cw_assert($asOfId >= (int) ($page1['rows'][1]['id'] ?? 0), 'reconcile asOfId covers page rows');
    cw_assert_same((int) ($page1['rows'][1]['id'] ?? 0), $page1['nextAfterId'] ?? null, 'reconcile returns nextAfterId');

    $page2Response = cw_send($app, cw_path('reconcile'), [
        'day' => $day,
        'limit' => 2,
        'asOfId' => $asOfId,
        'afterId' => $page1['nextAfterId'],
    ]);
    $page2 = cw_assert_success($page2Response, 'reconcile page two succeeds');
    cw_assert_same($asOfId, $page2['asOfId'] ?? null, 'reconcile keeps frozen asOfId');
    cw_assert((int) ($page2['rows'][0]['id'] ?? 0) > (int) ($page1['rows'][1]['id'] ?? 0), 'reconcile afterId advances');

    $freezeDebit = cw_send($app, cw_path('debit'), [
        'userId' => 1,
        'orderNo' => 'ORDER-FREEZE',
        'idempotencyKey' => 'idem-freeze',
        'amountCents' => 10,
        'currency' => 'CNY',
    ]);
    $freezeLedger = cw_assert_success($freezeDebit, 'post-reconcile debit succeeds');
    DB::table('v2_cloud_wallet_ledger')->where('id', $freezeLedger['id'])->update(['created_at' => $start + 7200]);
    $frozenResponse = cw_send($app, cw_path('reconcile'), ['day' => $day, 'asOfId' => $asOfId, 'limit' => 500]);
    $frozen = cw_assert_success($frozenResponse, 'reconcile with frozen asOfId succeeds');
    $frozenIds = array_map(static fn (array $row) => $row['id'], $frozen['rows'] ?? []);
    cw_assert(!in_array($freezeLedger['id'], $frozenIds, true), 'frozen asOfId excludes later ledger rows');

    $outsideDayResponse = cw_send($app, cw_path('reconcile'), ['day' => $day, 'limit' => 500]);
    $outsideDay = cw_assert_success($outsideDayResponse, 'reconcile fresh freeze succeeds');
    $freshIds = array_map(static fn (array $row) => $row['id'], $outsideDay['rows'] ?? []);
    cw_assert(in_array($freezeLedger['id'], $freshIds, true), 'fresh reconcile includes the later ledger row');

    $response = cw_send($app, cw_path('reconcile'), ['day' => $day, 'limit' => 501]);
    cw_assert_status($response, 400, 'invalid_request', 'reconcile limit above 500 is rejected');
    $response = cw_send($app, cw_path('reconcile'), ['day' => '2026-02-30']);
    cw_assert_status($response, 400, 'invalid_request', 'invalid calendar day is rejected');
    $response = cw_send($app, cw_path('reconcile'), ['day' => $day, 'afterId' => -1]);
    cw_assert_status($response, 400, 'invalid_request', 'negative afterId is rejected');

    $migration = require cw_root() . '/database/migrations/2026_10_25_000001_create_cloud_wallet_tables.php';
    $refused = false;
    try {
        $migration->down();
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'Refusing to roll back');
    }
    cw_assert($refused, 'migration rollback refuses to delete existing ledger rows');
    cw_assert(cw_ledger_count() > 0, 'ledger rows still exist after refused rollback');
    $migration->up();
    cw_assert(true, 'migration up is idempotent while tables exist');

    echo "\ncloud wallet HTTP probe: ALL PASS\n";
} finally {
    cw_cleanup($boot);
}
