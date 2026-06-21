<?php

/**
 * Write .env for CI deploy from environment variables (safe for special chars in passwords).
 */
function envLine(string $key, string $value): string
{
    if ($value === '' || preg_match('/[\s#"\';\\\\]/', $value)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return $key . '="' . $escaped . '"';
    }

    return $key . '=' . $value;
}

$required = ['APP_KEY', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

foreach ($required as $name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
}

$appKey = trim(getenv('APP_KEY'));
$appKey = preg_replace('/^APP_KEY=/', '', $appKey);
$appKey = trim($appKey, " \t\n\r\0\x0B\"'");
if (! str_starts_with($appKey, 'base64:')) {
    fwrite(STDERR, "APP_KEY must start with base64: (paste only the key value in GitHub secrets)\n");
    exit(1);
}
$decoded = base64_decode(substr($appKey, 7), true);
if ($decoded === false || strlen($decoded) !== 32) {
    fwrite(STDERR, "APP_KEY must decode to 32 bytes. Check the secret value.\n");
    exit(1);
}

$appUrl = getenv('APP_URL') ?: 'https://mobile.eurodental.ma';
$assetUrl = getenv('ASSET_URL') ?: 'https://eurodental.ma';
$publicStorageRoot = getenv('PUBLIC_STORAGE_ROOT') ?: '';
$cors = getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:8100,http://127.0.0.1:8100,http://localhost:4200,http://127.0.0.1:4200,https://localhost,capacitor://localhost';

$lines = [
    'APP_NAME="EuroDental Mobile API"',
    'APP_ENV=production',
    envLine('APP_KEY', $appKey),
    'APP_DEBUG=false',
    envLine('APP_URL', $appUrl),
    '',
    'LOG_CHANNEL=stack',
    'LOG_LEVEL=error',
    '',
    'DB_CONNECTION=mysql',
    envLine('DB_HOST', getenv('DB_HOST')),
    envLine('DB_PORT', getenv('DB_PORT')),
    envLine('DB_DATABASE', getenv('DB_DATABASE')),
    envLine('DB_USERNAME', getenv('DB_USERNAME')),
    envLine('DB_PASSWORD', getenv('DB_PASSWORD')),
    '',
    'SESSION_DRIVER=file',
    'SESSION_LIFETIME=120',
    'CACHE_STORE=file',
    'QUEUE_CONNECTION=sync',
    'FILESYSTEM_DISK=local',
    '',
    envLine('ASSET_URL', $assetUrl),
    envLine('CORS_ALLOWED_ORIGINS', $cors),
    '',
];

if ($publicStorageRoot !== '') {
    $lines[] = envLine('PUBLIC_STORAGE_ROOT', $publicStorageRoot);
    $lines[] = '';
}

file_put_contents(__DIR__ . '/../.env', implode(PHP_EOL, $lines));
echo "Wrote .env for deploy.\n";
