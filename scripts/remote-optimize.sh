#!/bin/sh
set -eu

DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH is required}"

cd "$DEPLOY_PATH"

PHP_FROM_ENV="${PHP_BIN:-}"
PHP_BIN=""
for candidate in $PHP_FROM_ENV /usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
  if [ -n "$candidate" ] && [ -x "$candidate" ]; then
    PHP_BIN="$candidate"
    break
  fi
done

if [ -z "$PHP_BIN" ]; then
  echo "No PHP binary found" >&2
  exit 1
fi

rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php bootstrap/cache/events.php

test -f artisan || { echo "artisan missing" >&2; exit 1; }
test -f .env || { echo ".env missing" >&2; exit 1; }

chmod 600 .env
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache

echo "=== PHP: $PHP_BIN ==="
"$PHP_BIN" -v

echo "=== artisan --version ==="
"$PHP_BIN" artisan --version

echo "=== config:clear ==="
"$PHP_BIN" artisan config:clear

echo "=== config:cache ==="
if ! "$PHP_BIN" artisan config:cache 2>storage/logs/deploy-optimize.log; then
  echo "config:cache failed (see storage/logs/deploy-optimize.log):"
  cat storage/logs/deploy-optimize.log 2>/dev/null || true
  "$PHP_BIN" artisan config:clear
fi

echo "Deploy finished."
