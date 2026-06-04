#!/usr/bin/env bash
set -euxo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${PHP_BIN:=/usr/bin/php}"

cd "$DEPLOY_PATH"

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
chmod -R ug+rwx storage bootstrap/cache

"$PHP_BIN" -v
"$PHP_BIN" artisan --version
"$PHP_BIN" artisan package:discover --ansi
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo "Deploy finished."
