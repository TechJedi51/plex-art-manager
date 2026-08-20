<?php
// Deliberately does NOT depend on Plex connectivity or any request params -
// unlike diagnostics.php (which needs a ratingKey and a reachable Plex
// server), this just answers "is the app itself alive" so it's useful when
// the page is loading blank and you're not sure if the problem is PHP, the
// DB, disk permissions, or the frontend JS. Safe to curl directly:
//   curl http://<host>:8080/api/health.php
require_once __DIR__ . '/../includes/config.php';

$checks = [
    'appVersion'    => APP_VERSION,
    'phpVersion'    => PHP_VERSION,
    'extensions'    => [
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'gd'         => extension_loaded('gd'),
        'curl'       => extension_loaded('curl'),
        'fileinfo'   => extension_loaded('fileinfo'),
    ],
    'dataDir'       => ['path' => DATA_DIR, 'writable' => is_writable(DATA_DIR)],
    'cacheDir'      => ['path' => CACHE_DIR, 'writable' => is_writable(CACHE_DIR)],
    'db'            => ['path' => DB_PATH, 'connected' => false, 'error' => null],
];

try {
    get_db();
    $checks['db']['connected'] = true;
} catch (Throwable $e) {
    $checks['db']['error'] = $e->getMessage();
}

json_out($checks);
