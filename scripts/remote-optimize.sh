#!/bin/sh
set -eu

DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH is required}"
DEBUG_LOG="$DEPLOY_PATH/deploy-debug.txt"
PUBLIC_HTML="${PUBLIC_HTML_PATH:-$(dirname "$DEPLOY_PATH")/public_html}"
APP_FOLDER="$(basename "$DEPLOY_PATH")"

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

log "=== deploy $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
log "DEPLOY_PATH=$DEPLOY_PATH"
log "PUBLIC_HTML=$PUBLIC_HTML"
log "APP_FOLDER=$APP_FOLDER"

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

rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php bootstrap/cache/events.php

# Hostinger: site root uses public_html; Laravel app lives in sibling laravel-mobile/
log "=== setup public_html ==="
if [ ! -d "$PUBLIC_HTML" ]; then
  log "ERROR: public_html not found at $PUBLIC_HTML"
  exit 1
fi

INDEX_FILE="$PUBLIC_HTML/index.php"
cat > "$INDEX_FILE" <<EOF
<?php

/**
 * Hostinger document root (public_html) → Laravel public folder.
 */
require __DIR__ . '/../${APP_FOLDER}/public/index.php';
EOF
chmod 644 "$INDEX_FILE"
log "Wrote $INDEX_FILE"

if [ -f "$DEPLOY_PATH/public/.htaccess" ]; then
  cp "$DEPLOY_PATH/public/.htaccess" "$PUBLIC_HTML/.htaccess"
  chmod 644 "$PUBLIC_HTML/.htaccess"
  log "Copied .htaccess to public_html"
fi

# Artisan optimize (best-effort; web can run without config:cache)
if [ -z "$PHP_BIN" ]; then
  log "WARN: no PHP CLI — skipped artisan (public_html is configured)"
  log "Deploy finished OK (web only)"
  exit 0
fi

log "PHP_BIN=$PHP_BIN"
"$PHP_BIN" -v 2>&1 | tee -a "$DEBUG_LOG" || true

log "=== artisan --version ==="
if ! "$PHP_BIN" artisan --version 2>&1 | tee -a "$DEBUG_LOG"; then
  log "WARN: artisan failed — public_html is still configured for HTTP"
  exit 0
fi

log "=== config:clear ==="
"$PHP_BIN" artisan config:clear 2>&1 | tee -a "$DEBUG_LOG" || true

log "=== config:cache ==="
if ! "$PHP_BIN" artisan config:cache 2>&1 | tee -a "$DEBUG_LOG"; then
  log "WARN: config:cache failed"
  "$PHP_BIN" artisan config:clear 2>&1 | tee -a "$DEBUG_LOG" || true
fi

log "Deploy finished OK"
