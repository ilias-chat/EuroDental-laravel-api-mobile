<?php

/**
 * Write .env for CI deploy from environment variables (safe for special chars in passwords).
 */
$required = ['APP_KEY', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

foreach ($required as $name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
}

$appUrl = getenv('APP_URL') ?: 'https://mobile.eurodental.ma';
$assetUrl = getenv('ASSET_URL') ?: 'https://eurodental.ma';
$cors = getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:4200,http://127.0.0.1:4200';

$lines = [
    'APP_NAME="EuroDental Mobile API"',
    'APP_ENV=production',
    'APP_KEY=' . getenv('APP_KEY'),
    'APP_DEBUG=false',
    'APP_URL=' . $appUrl,
    '',
    'LOG_CHANNEL=stack',
    'LOG_LEVEL=error',
    '',
    'DB_CONNECTION=mysql',
    'DB_HOST=' . getenv('DB_HOST'),
    'DB_PORT=' . getenv('DB_PORT'),
    'DB_DATABASE=' . getenv('DB_DATABASE'),
    'DB_USERNAME=' . getenv('DB_USERNAME'),
    'DB_PASSWORD=' . getenv('DB_PASSWORD'),
    '',
    'SESSION_DRIVER=file',
    'SESSION_LIFETIME=120',
    'CACHE_STORE=file',
    'QUEUE_CONNECTION=sync',
    'FILESYSTEM_DISK=local',
    '',
    'ASSET_URL=' . $assetUrl,
    'CORS_ALLOWED_ORIGINS=' . $cors,
    '',
];

file_put_contents(__DIR__ . '/../.env', implode(PHP_EOL, $lines));
echo "Wrote .env for deploy.\n";
