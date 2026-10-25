#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/bin/php}"
PHP_EXT_DIR="${PHP_EXT_DIR:-/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/lib/php/modules}"
export XBOARD_VENDOR_AUTOLOAD="${XBOARD_VENDOR_AUTOLOAD:-/home/nianyi778/wsl2/github/Xboard/vendor/autoload.php}"
export TMPDIR="${TMPDIR:-/home/nianyi778/.cache/tianji-wallet-recharge-run/tmp}"

run_php() {
  "$PHP_BIN" -n -d extension_dir="$PHP_EXT_DIR" -d extension=pdo_sqlite "$@"
}

run_php "$ROOT/tests/WalletRecharge/recharge_functional_probe.php"
run_php "$ROOT/tests/WalletRecharge/recharge_concurrency_probe.php"
