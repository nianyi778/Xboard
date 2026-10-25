<?php

return [
    /*
    | Cloud wallet is disabled by default. The internal routes exist but every
    | request returns 503 wallet_disabled until this is explicitly enabled.
    */
    'enabled' => env('CLOUD_WALLET_ENABLED', false),

    // Private gateway proof, independent of caller HMAC. Production routes
    // remain unreachable via the public Xboard listener, even when disabled.
    'ingress_key_file' => env('CLOUD_WALLET_INGRESS_KEY_FILE', ''),

    /*
    | Dedicated key id sent by the caller in X-Tianji-Wallet-Key-Id. It must
    | match the key material below; PAY_ADMIN or other panel keys are never
    | reused.
    */
    'key_id' => env('CLOUD_WALLET_KEY_ID', ''),

    /*
    | Path to the dedicated secret file. The file must contain at least 32
    | bytes. Supported formats:
    |   1. Raw secret text (newline trimmed), used for the configured key_id.
    |   2. JSON object: {"<keyId>": "<secret>", ...}
    |   3. JSON object: {"keyId": "<keyId>", "secret": "<secret>"}
    | A value prefixed with "base64:" is decoded before length validation.
    */
    'key_file' => env('CLOUD_WALLET_KEY_FILE', storage_path('app/cloud-wallet.key')),

    'clock_skew_seconds' => (int) env('CLOUD_WALLET_CLOCK_SKEW', 60),
    'nonce_ttl_seconds' => (int) env('CLOUD_WALLET_NONCE_TTL', 300),

    'max_amount_cents' => 2147483647,
    'reconcile_timezone' => 'Asia/Shanghai',
    'reconcile_max_limit' => 500,
    'reconcile_default_limit' => 100,
];
