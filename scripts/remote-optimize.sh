#!/bin/sh
set -eu

DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH is required}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

cd "$DEPLOY_PATH"

rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php bootstrap/cache/events.php

test -f artisan || { echo "artisan missing"; exit 1; }
test -f .env || { echo ".env missing"; exit 1; }

chmod 600 .env
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "=== PHP ==="
"$PHP_BIN" -v

echo "=== config:clear ==="
"$PHP_BIN" artisan config:clear

echo "=== config:cache ==="
"$PHP_BIN" artisan config:cache

echo "Deploy finished."
