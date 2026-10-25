<?php

declare(strict_types=1);

use App\Services\CloudWallet\CloudWalletTransaction;
use App\Support\Setting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const CW_PROBE_KEY_ID = 'cloud-wallet-probe-key';
const CW_PROBE_SECRET = 'cloud-wallet-probe-secret-0123456789abcdef';

function cw_root(): string
{
    return dirname(__DIR__, 2);
}

function cw_autoload(): void
{
    $root = cw_root();
    $vendor = getenv('XBOARD_VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';
    if (!is_file($vendor)) {
        $vendor = '/home/nianyi778/wsl2/github/Xboard/vendor/autoload.php';
    }
    if (!is_file($vendor)) {
        fwrite(STDERR, "vendor/autoload.php not found; set XBOARD_VENDOR_AUTOLOAD\n");
        exit(2);
    }

    require $vendor;

    spl_autoload_register(function (string $class) use ($root): void {
        foreach (['App\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    }, true, true);
}

/**
 * @return array{app:Application,database:string,keyFile:string}
 */
function cw_boot(array $config = []): array
{
    cw_autoload();
    $root = cw_root();
    $database = tempnam(sys_get_temp_dir(), 'tianji-cloud-wallet-');
    if ($database === false) {
        throw new RuntimeException('failed to create temporary database');
    }
    $keyFile = tempnam(sys_get_temp_dir(), 'tianji-cloud-wallet-key-');
    if ($keyFile === false) {
        throw new RuntimeException('failed to create temporary key file');
    }
    file_put_contents($keyFile, CW_PROBE_SECRET);

    $env = [
        'APP_BASE_PATH' => $root,
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'CACHE_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'log',
        'CLOUD_WALLET_ENABLED' => 'true',
        'CLOUD_WALLET_KEY_ID' => CW_PROBE_KEY_ID,
        'CLOUD_WALLET_KEY_FILE' => $keyFile,
    ];
    foreach ($env as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** @var Application $app */
    $app = require $root . '/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'database.connections.sqlite.database' => $database,
        'cloud_wallet.enabled' => true,
        'cloud_wallet.key_id' => CW_PROBE_KEY_ID,
        'cloud_wallet.key_file' => $keyFile,
    ]);
    foreach ($config as $key => $value) {
        config([$key => $value]);
    }
    DB::purge('sqlite');

    $app->instance(Setting::class, new class extends Setting {
        public function __construct()
        {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    });

    return ['app' => $app, 'database' => $database, 'keyFile' => $keyFile];
}

function cw_init_database(): void
{
    Schema::create('v2_user', function (Blueprint $table): void {
        $table->integer('id', true);
        $table->string('email')->unique();
        $table->string('password')->default('');
        $table->integer('balance')->default(0);
        $table->integer('commission_balance')->default(0);
        $table->boolean('banned')->default(false);
        $table->integer('created_at')->default(0);
        $table->integer('updated_at')->default(0);
    });

    Schema::create('v2_plugins', function (Blueprint $table): void {
        $table->id();
        $table->string('code')->default('');
        $table->boolean('is_enabled')->default(false);
    });

    Schema::create('v2_order', function (Blueprint $table): void {
        $table->integer('id', true);
        $table->integer('user_id')->default(0);
        $table->integer('status')->default(0);
        $table->integer('total_amount')->default(0);
        $table->integer('balance_amount')->default(0);
        $table->integer('created_at')->default(0);
        $table->integer('updated_at')->default(0);
    });

    $migration = require cw_root() . '/database/migrations/2026_10_25_000001_create_cloud_wallet_tables.php';
    $migration->up();
}

function cw_seed_user(int $id, int $balance, bool $banned = false): void
{
    DB::table('v2_user')->insert([
        'id' => $id,
        'email' => 'cloud-wallet-' . $id . '@example.test',
        'password' => '',
        'balance' => $balance,
        'commission_balance' => 0,
        'banned' => $banned ? 1 : 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
}

function cw_balance(int $userId): int
{
    return (int) DB::table('v2_user')->where('id', $userId)->value('balance');
}

/**
 * @return array{status:int,body:array,raw:string,request:array}
 */
function cw_send(
    Application $app,
    string $path,
    mixed $body,
    ?string $keyId = null,
    ?string $secret = null,
    array $options = []
): array {
    $keyId ??= CW_PROBE_KEY_ID;
    $secret ??= CW_PROBE_SECRET;
    $raw = $options['raw'] ?? (is_string($body) ? $body : json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
    $timestamp = (string) ($options['timestamp'] ?? time());
    $nonce = (string) ($options['nonce'] ?? bin2hex(random_bytes(16)));
    $signature = $options['signature'] ?? hash_hmac(
        'sha256',
        implode("\n", [
            'tianji-wallet-v1',
            'POST',
            (string) ($options['signed_path'] ?? $path),
            hash('sha256', $raw),
            $timestamp,
            $nonce,
            $keyId,
        ]),
        $secret
    );
    $query = $options['query'] ?? null;
    $uri = $path . ($query === null ? '' : '?' . $query);
    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIANJI_WALLET_KEY_ID' => $keyId,
        'HTTP_X_TIANJI_WALLET_TIMESTAMP' => $timestamp,
        'HTTP_X_TIANJI_WALLET_NONCE' => $nonce,
        'HTTP_X_TIANJI_WALLET_SIGNATURE' => $signature,
    ];
    foreach (($options['headers'] ?? []) as $name => $value) {
        if ($value === null) {
            unset($server['HTTP_' . strtoupper(str_replace('-', '_', $name))]);
        } else {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
    }

    $request = Request::create($uri, $options['method'] ?? 'POST', [], [], [], $server, $raw);
    $response = $app->make(HttpKernel::class)->handle($request);
    $content = $response->getContent();
    $decoded = json_decode($content, true);

    return [
        'status' => $response->getStatusCode(),
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $content,
        'request' => [
            'path' => $path,
            'raw' => $raw,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
            'keyId' => $keyId,
        ],
    ];
}

function cw_path(string $endpoint): string
{
    return '/api/v1/internal/cloud-wallet/' . $endpoint;
}

function cw_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
    echo "PASS " . $message . "\n";
}

function cw_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'ASSERTION FAILED: ' . $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
        );
    }
    echo "PASS " . $message . "\n";
}

function cw_assert_status(array $response, int $status, ?string $code, string $message): void
{
    cw_assert_same($status, $response['status'], $message . ' HTTP status');
    if ($code !== null) {
        cw_assert_same($code, $response['body']['code'] ?? null, $message . ' code');
        cw_assert_same('fail', $response['body']['status'] ?? null, $message . ' envelope');
        cw_assert(array_key_exists('data', $response['body']) && $response['body']['data'] === null, $message . ' data is null');
    }
    cw_assert(
        !str_contains(strtolower($response['raw']), 'sqlstate')
        && !str_contains(strtolower($response['raw']), 'binding'),
        $message . ' leaks no SQL/bindings'
    );
}

function cw_assert_success(array $response, string $message): array
{
    cw_assert_same(200, $response['status'], $message . ' HTTP status');
    cw_assert_same('success', $response['body']['status'] ?? null, $message . ' envelope');
    return $response['body']['data'] ?? [];
}

function cw_ledger(int $id): ?object
{
    return DB::table('v2_cloud_wallet_ledger')->where('id', $id)->first();
}

function cw_ledger_count(?string $key = null): int
{
    $query = DB::table('v2_cloud_wallet_ledger');
    if ($key !== null) {
        $query->where('idempotency_key', $key);
    }
    return (int) $query->count();
}

function cw_cleanup(array $boot): void
{
    DB::disconnect();
    DB::purge('sqlite');
    foreach ([
        $boot['database'],
        $boot['database'] . '-wal',
        $boot['database'] . '-shm',
        $boot['keyFile'],
    ] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
