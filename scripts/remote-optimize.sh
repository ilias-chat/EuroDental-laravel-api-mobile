#!/bin/sh
set -eu

DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH is required}"
DEBUG_LOG="$DEPLOY_PATH/deploy-debug.txt"

cd "$DEPLOY_PATH"

PHP_FROM_ENV="${PHP_BIN:-}"
PHP_BIN=""
for candidate in $PHP_FROM_ENV /usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
  if [ -n "$candidate" ] && [ -x "$candidate" ]; then
    PHP_BIN="$candidate"
    break
  fi
done

: > "$DEBUG_LOG"

log() {
  echo "$@" | tee -a "$DEBUG_LOG"
}

if [ -z "$PHP_BIN" ]; then
  log "ERROR: No PHP binary found"
  exit 1
fi

log "=== deploy optimize $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
log "DEPLOY_PATH=$DEPLOY_PATH"
log "PHP_BIN=$PHP_BIN"

log "=== php -v ==="
"$PHP_BIN" -v 2>&1 | tee -a "$DEBUG_LOG" || true

log "=== php -m ==="
"$PHP_BIN" -m 2>&1 | tee -a "$DEBUG_LOG" || true

rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php bootstrap/cache/events.php

if [ ! -f artisan ]; then
  log "ERROR: artisan missing"
  exit 1
fi

if [ ! -f .env ]; then
  log "ERROR: .env missing"
  exit 1
fi

chmod 600 .env
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache

log "=== artisan --version ==="
if ! "$PHP_BIN" artisan --version 2>&1 | tee -a "$DEBUG_LOG"; then
  log "ERROR: artisan --version failed"
  exit 1
fi

log "=== config:clear ==="
if ! "$PHP_BIN" artisan config:clear 2>&1 | tee -a "$DEBUG_LOG"; then
  log "ERROR: config:clear failed"
  exit 1
fi

log "=== config:cache ==="
if ! "$PHP_BIN" artisan config:cache 2>&1 | tee -a "$DEBUG_LOG"; then
  log "WARN: config:cache failed, continuing with uncached config"
  "$PHP_BIN" artisan config:clear 2>&1 | tee -a "$DEBUG_LOG" || true
fi

log "Deploy finished OK"
