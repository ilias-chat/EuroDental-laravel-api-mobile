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
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

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

CHECK_FILE="$PUBLIC_HTML/check.php"
cat > "$CHECK_FILE" <<'PHP'
<?php
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__ . '/../APP_FOLDER_PLACEHOLDER';
echo 'PHP ' . PHP_VERSION . "\n";
echo "root=$root\n";
if (!is_dir($root)) { echo "ERROR: app dir missing\n"; exit(1); }
try {
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "DB: OK\nLaravel: " . $app->version() . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
PHP
sed -i "s/APP_FOLDER_PLACEHOLDER/${APP_FOLDER}/g" "$CHECK_FILE"
chmod 644 "$CHECK_FILE"
log "Wrote $CHECK_FILE (visit /check.php for deploy errors)"

{
  if [ -f "$DEPLOY_PATH/scripts/hostinger-public_html.htaccess" ]; then
    cat "$DEPLOY_PATH/scripts/hostinger-public_html.htaccess"
  fi
  if [ -f "$DEPLOY_PATH/public/.htaccess" ]; then
    cat "$DEPLOY_PATH/public/.htaccess"
  fi
} > "$PUBLIC_HTML/.htaccess"
chmod 644 "$PUBLIC_HTML/.htaccess"
log "Wrote public_html/.htaccess (PHP 8.2 + Laravel rewrite)"

touch "$DEPLOY_PATH/storage/logs/laravel.log" 2>/dev/null || true
chmod -R 777 "$DEPLOY_PATH/storage" 2>/dev/null || true

# Artisan optimize (best-effort; web can run without config:cache)
if [ -z "$PHP_BIN" ]; then
  log "WARN: no PHP CLI — skipped artisan (public_html is configured)"
  log "Deploy finished OK (web only)"
  exit 0
fi

log "PHP_BIN=$PHP_BIN"
"$PHP_BIN" -v 2>&1 | tee -a "$DEBUG_LOG" || true

log "=== package:discover ==="
"$PHP_BIN" artisan package:discover --ansi 2>&1 | tee -a "$DEBUG_LOG" || true

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

log "=== database connection test ==="
if ! "$PHP_BIN" artisan db:show 2>&1 | tee -a "$DEBUG_LOG"; then
  log "WARN: db:show failed — check GitHub secrets DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD (Hostinger MySQL)"
fi

log "Deploy finished OK"
