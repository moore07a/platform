<?php
// init.php - common bootstrap for all pages when included
// Use __DIR__-based includes; this file should be required using an absolute path from each script when possible.
if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', __DIR__);
if (!defined('ASSET_VERSION')) define('ASSET_VERSION', '2024.06.01');

if (!function_exists('versioned_asset')) {
    function versioned_asset(string $path): string
    {
        $delimiter = strpos($path, '?') === false ? '?' : '&';
        return $path . $delimiter . 'v=' . ASSET_VERSION;
    }
}
if (!isset($pdo)) {
    // try to load config.php which should create $pdo
    if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
}
?>