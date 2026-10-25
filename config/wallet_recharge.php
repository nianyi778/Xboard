<?php

return [
    /*
    | 平台余额主动充值 P1。
    |
    | 默认全部关闭：没有显式开关、金额上下限和支付凭据时，创建订单直接拒绝。
    | 关闭新单入口不会影响已创建真实付款的验签、查询和入账；回调依赖下面
    | 的支付凭据可用，二者语义独立。
    */
    'enabled' => env('WALLET_RECHARGE_ENABLED', false),

    // 生产金额上下限必须由运营/Codex 按支付配置定版，代码不设默认业务值。
    'min_amount_cents' => env('WALLET_RECHARGE_MIN_CENTS'),
    'max_amount_cents' => env('WALLET_RECHARGE_MAX_CENTS'),

    // 手续费默认 0；bearer=customer 时客户支付本金+手续费，platform 时平台承担。
    'fee_bps' => env('WALLET_RECHARGE_FEE_BPS', 0),
    'fee_fixed_cents' => env('WALLET_RECHARGE_FEE_FIXED_CENTS', 0),
    'fee_bearer' => env('WALLET_RECHARGE_FEE_BEARER', 'customer'),

    'currency' => 'CNY',
    'timezone' => 'Asia/Shanghai',
    'absolute_max_cents' => 2147483647,

    'return_path_prefixes' => [
        '/wallet/recharge',
        '/cloud/orders/',
        '/cloud/instances/',
        '/orders/',
        '/subscription',
    ],

    'pay' => [
        'base_url' => env('WALLET_RECHARGE_PAY_BASE_URL', ''),
        'app_id' => env('WALLET_RECHARGE_PAY_APP_ID', ''),
        // 支付方 app_secret 只从独立文件读取，禁止写入环境变量明文或代码。
        'secret_file' => env('WALLET_RECHARGE_PAY_SECRET_FILE', ''),
        'notify_url' => env('WALLET_RECHARGE_PAY_NOTIFY_URL', ''),
        'panel_url' => env('WALLET_RECHARGE_PANEL_URL', ''),
        'timeout_seconds' => (int) env('WALLET_RECHARGE_PAY_TIMEOUT', 10),
        'expires_minutes' => (int) env('WALLET_RECHARGE_EXPIRES_MINUTES', 40),
    ],

    'callback_clock_skew_seconds' => (int) env('WALLET_RECHARGE_CALLBACK_SKEW', 900),
    'reconcile_default_limit' => 500,
];
