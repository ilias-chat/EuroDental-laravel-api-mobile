#!/usr/bin/env bash
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${PHP_BIN:=/usr/bin/php}"

cd "$DEPLOY_PATH"

# Strip Windows line endings if present (CRLF-safe deploy)
sed -i 's/\r$//' scripts/remote-optimize.sh 2>/dev/null || true

rm -f bootstrap/cache/config.php bootstrap/cache/routes*.php bootstrap/cache/events.php

if [ ! -f artisan ]; then
  echo "artisan not found in $DEPLOY_PATH" >&2
  exit 1
fi

if [ ! -f .env ]; then
  echo ".env not found in $DEPLOY_PATH" >&2
  exit 1
fi

chmod 600 .env
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || chmod -R 775 storage bootstrap/cache

echo "=== PHP ==="
"$PHP_BIN" -v
echo "=== Artisan ==="
"$PHP_BIN" artisan --version

echo "=== config:clear ==="
"$PHP_BIN" artisan config:clear

echo "=== config:cache ==="
"$PHP_BIN" artisan config:cache

echo "=== route:cache (optional) ==="
"$PHP_BIN" artisan route:cache || echo "route:cache skipped"

echo "=== view:cache (optional) ==="
"$PHP_BIN" artisan view:cache || echo "view:cache skipped"

echo "Deploy finished."
