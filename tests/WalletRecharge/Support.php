<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\WalletRecharge\WalletRechargeConfig;
use App\Services\WalletRecharge\WalletRechargeService;
use App\Support\Setting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const WR_PROBE_APP_ID = 'wr_probe_app';
const WR_PROBE_SECRET = 'wallet-recharge-probe-secret-0123456789abcdef';

function wr_root(): string
{
    return dirname(__DIR__, 2);
}

function wr_autoload(): void
{
    $root = wr_root();
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
 * @return array{app:Application,database:string,keyFile:string,cloudKeyFile:string}
 */
function wr_boot(array $config = []): array
{
    $database = tempnam(sys_get_temp_dir(), 'tianji-recharge-');
    $keyFile = tempnam(sys_get_temp_dir(), 'tianji-recharge-key-');
    $cloudKeyFile = tempnam(sys_get_temp_dir(), 'tianji-recharge-cloud-key-');
    if ($database === false || $keyFile === false || $cloudKeyFile === false) {
        throw new RuntimeException('failed to create temporary files');
    }
    file_put_contents($keyFile, WR_PROBE_SECRET);
    file_put_contents($cloudKeyFile, 'cloud-wallet-probe-secret-0123456789abcdef');

    return wr_boot_paths($database, $keyFile, $cloudKeyFile, $config);
}

/**
 * 多进程探针共享同一个 SQLite 文件和密钥文件时使用。
 *
 * @return array{app:Application,database:string,keyFile:string,cloudKeyFile:string}
 */
function wr_boot_paths(string $database, string $keyFile, string $cloudKeyFile, array $config = []): array
{
    wr_autoload();
    $root = wr_root();

    $env = [
        'APP_BASE_PATH' => $root,
        'APP_ENV' => 'testing',
        // 隔离 HTTP 探针用 php -S；跳过 Octane tick 的 HTTP-only 启动分支。
        'APP_RUNNING_IN_CONSOLE' => 'true',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:' . base64_encode(str_repeat('b', 32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'CACHE_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'log',
        'CLOUD_WALLET_ENABLED' => 'true',
        'CLOUD_WALLET_KEY_ID' => 'cloud-wallet-probe-key',
        'CLOUD_WALLET_KEY_FILE' => $cloudKeyFile,
        'WALLET_RECHARGE_ENABLED' => 'true',
        'WALLET_RECHARGE_MIN_CENTS' => '100',
        'WALLET_RECHARGE_MAX_CENTS' => '100000',
        'WALLET_RECHARGE_FEE_BPS' => '0',
        'WALLET_RECHARGE_FEE_FIXED_CENTS' => '0',
        'WALLET_RECHARGE_FEE_BEARER' => 'customer',
        'WALLET_RECHARGE_PAY_BASE_URL' => 'http://127.0.0.1:9',
        'WALLET_RECHARGE_PAY_APP_ID' => WR_PROBE_APP_ID,
        'WALLET_RECHARGE_PAY_SECRET_FILE' => $keyFile,
        'WALLET_RECHARGE_PAY_NOTIFY_URL' => 'http://127.0.0.1:9/api/v1/guest/wallet-recharge/notify/tianji',
        'WALLET_RECHARGE_PANEL_URL' => 'https://panel.test',
    ];
    foreach ($env as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** @var Application $app */
    $app = require $root . '/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $app->register(\Laravel\Sanctum\SanctumServiceProvider::class);

    config(array_merge([
        'database.connections.sqlite.database' => $database,
        'auth.guards.sanctum' => ['driver' => 'sanctum', 'provider' => 'users'],
        'cloud_wallet.enabled' => true,
        'cloud_wallet.key_id' => 'cloud-wallet-probe-key',
        'cloud_wallet.key_file' => $cloudKeyFile,
        'wallet_recharge.enabled' => true,
        'wallet_recharge.min_amount_cents' => 100,
        'wallet_recharge.max_amount_cents' => 100000,
        'wallet_recharge.fee_bps' => 0,
        'wallet_recharge.fee_fixed_cents' => 0,
        'wallet_recharge.fee_bearer' => 'customer',
        'wallet_recharge.pay.base_url' => 'http://127.0.0.1:9',
        'wallet_recharge.pay.app_id' => WR_PROBE_APP_ID,
        'wallet_recharge.pay.secret_file' => $keyFile,
        'wallet_recharge.pay.notify_url' => 'http://127.0.0.1:9/api/v1/guest/wallet-recharge/notify/tianji',
        'wallet_recharge.pay.panel_url' => 'https://panel.test',
    ], $config));
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

    return ['app' => $app, 'database' => $database, 'keyFile' => $keyFile, 'cloudKeyFile' => $cloudKeyFile];
}

function wr_init_database(): void
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

    $cloud = require wr_root() . '/database/migrations/2026_10_25_000001_create_cloud_wallet_tables.php';
    $cloud->up();
    $recharge = require wr_root() . '/database/migrations/2026_10_25_000002_create_wallet_recharge_tables.php';
    $recharge->up();
}

function wr_seed_user(int $id, int $balance, bool $banned = false): void
{
    DB::table('v2_user')->insert([
        'id' => $id,
        'email' => 'recharge-' . $id . '@example.test',
        'password' => '',
        'balance' => $balance,
        'commission_balance' => 0,
        'banned' => $banned ? 1 : 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
}

function wr_user(int $id): User
{
    /** @var User $user */
    $user = User::query()->findOrFail($id);
    return $user;
}

function wr_balance(int $userId): int
{
    return (int) DB::table('v2_user')->where('id', $userId)->value('balance');
}

function wr_service(): WalletRechargeService
{
    return new WalletRechargeService(WalletRechargeConfig::fromConfig());
}

/**
 * @return array{status:int,body:array,raw:string}
 */
function wr_request(Application $app, string $method, string $path, mixed $body = null, ?User $user = null): array
{
    // 单进程探针复用同一 Application；生产每个请求是独立容器。清掉路由缓存的
    // 控制器/服务实例，才能真实观察配置开关在下一个请求生效。
    $app->forgetInstance(\App\Http\Controllers\V1\User\WalletRechargeController::class);
    $app->forgetInstance(\App\Http\Controllers\V1\Guest\WalletRechargeNotifyController::class);
    $app->forgetInstance(\App\Services\WalletRecharge\WalletRechargeService::class);
    if ($user !== null) {
        Auth::guard('sanctum')->setUser($user);
        Auth::guard('api')->setUser($user);
    } else {
        Auth::forgetGuards();
    }
    $raw = is_string($body) ? $body : json_encode($body ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ];
    $request = Request::create($path, $method, [], [], [], $server, $raw);
    $response = $app->make(HttpKernel::class)->handle($request);
    $content = $response->getContent();
    $decoded = json_decode($content, true);

    return [
        'status' => $response->getStatusCode(),
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $content,
    ];
}

function wr_create_order(Application $app, User $user, int $amount, ?string $key = null, ?string $returnPath = null): array
{
    return wr_request($app, 'POST', '/api/v1/user/wallet/recharge/orders', [
        'amountCents' => $amount,
        'idempotencyKey' => $key ?? ('probe-' . bin2hex(random_bytes(12))),
        'returnPath' => $returnPath,
    ], $user);
}

function wr_callback_payload(string $orderNo, int $amount, string $txnId, ?int $timestamp = null, ?string $secret = null): array
{
    $timestamp ??= time();
    return [
        'orderNo' => $orderNo,
        'amountCents' => $amount,
        'txnId' => $txnId,
        'method' => 'stripe',
        'timestamp' => $timestamp,
        'sign' => hash_hmac('sha256', $orderNo . '.' . $amount . '.' . $txnId . '.' . $timestamp, $secret ?? WR_PROBE_SECRET),
    ];
}

function wr_callback(Application $app, array $payload): array
{
    return wr_request($app, 'POST', '/api/v1/guest/wallet-recharge/notify/tianji', $payload);
}

function wr_fake_pay(callable $resolver): void
{
    // Http::fake() 会 merge 而不是替换旧 stub；探针要能切换故障注入，先换新 Factory。
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(function ($request) use ($resolver) {
        return $resolver($request);
    });
}

function wr_pay_json(array $data, int $status = 200): mixed
{
    return Http::response(['status' => 'success', 'data' => $data], $status, ['content-type' => 'application/json']);
}

function wr_pay_fail(string $message = 'fail', int $status = 403): mixed
{
    return Http::response(['status' => 'fail', 'code' => 'forbidden', 'message' => $message, 'data' => null], $status, ['content-type' => 'application/json']);
}

function wr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
    echo "PASS " . $message . "\n";
}

function wr_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'ASSERTION FAILED: ' . $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
        );
    }
    echo "PASS " . $message . "\n";
}

function wr_assert_status(array $response, int $status, ?string $code, string $message): void
{
    wr_assert_same($status, $response['status'], $message . ' HTTP status');
    wr_assert_same('fail', $response['body']['status'] ?? null, $message . ' envelope');
    if ($code !== null) {
        wr_assert_same($code, $response['body']['code'] ?? null, $message . ' code');
    }
    wr_assert(
        !str_contains(strtolower($response['raw']), 'sqlstate')
        && !str_contains(strtolower($response['raw']), 'binding')
        && !str_contains(strtolower($response['raw']), WR_PROBE_SECRET),
        $message . ' leaks no SQL/bindings/secret'
    );
}

function wr_assert_success(array $response, string $message): array
{
    wr_assert_same(200, $response['status'], $message . ' HTTP status');
    wr_assert_same('success', $response['body']['status'] ?? null, $message . ' envelope');
    return $response['body']['data'] ?? [];
}

function wr_order(string $orderNo): ?object
{
    return DB::table('v2_wallet_recharge_orders')->where('order_no', $orderNo)->first();
}

function wr_attempt(string $outTradeNo): ?object
{
    return DB::table('v2_wallet_recharge_attempts')->where('out_trade_no', $outTradeNo)->first();
}

function wr_ledger_count(): int
{
    return (int) DB::table('v2_wallet_recharge_ledger')->count();
}

function wr_cleanup(array $boot): void
{
    DB::disconnect();
    DB::purge('sqlite');
    foreach ([
        $boot['database'], $boot['database'] . '-wal', $boot['database'] . '-shm',
        $boot['keyFile'], $boot['cloudKeyFile'],
    ] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
