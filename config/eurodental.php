<?php

/**
 * Main EuroDental app (eurodental.ma) — shared DB + public uploads.
 * Mobile API stores files on the main app's disk so https://eurodental.ma/storage/... works.
 */
$siblingStorage = base_path('../laravel-eurodental/storage/app/public');

$defaultStorageRoot = storage_path('app/public');
if (is_dir($siblingStorage)) {
    $defaultStorageRoot = $siblingStorage;
}

return [
    'asset_base' => 'https://eurodental.ma',

    'public_storage_root' => env('PUBLIC_STORAGE_ROOT', $defaultStorageRoot),
];
