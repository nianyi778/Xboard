#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/bin/php}"
PHP_EXT_DIR="${PHP_EXT_DIR:-/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/lib/php/modules}"

run_php() {
  "$PHP_BIN" -n -d extension_dir="$PHP_EXT_DIR" -d extension=pdo_sqlite "$@"
}

run_php "$ROOT/tests/CloudWallet/cloud_wallet_http_probe.php"
run_php "$ROOT/tests/CloudWallet/cloud_wallet_concurrency_probe.php"
run_php "$ROOT/tests/CloudWallet/legacy_balance_guard_probe.php"

run_php "$ROOT/tests/CloudWallet/private_ingress_probe.php"
