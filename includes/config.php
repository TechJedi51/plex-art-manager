<?php
/**
 * Core bootstrap. Every entry point (index.php, api/*.php, cli/*.php) includes this.
 *
 * Nothing sensitive lives in this file — Plex tokens and API keys are stored in the
 * `settings` table (see includes/settings.php) and edited from the Settings page.
 * That keeps credentials out of version control if you put this project in git.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak PHP errors/paths into JSON API responses
date_default_timezone_set('America/Los_Angeles');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('CACHE_DIR', APP_ROOT . '/cache');
define('THUMB_CACHE_DIR', CACHE_DIR . '/thumbs');
define('DB_PATH', DATA_DIR . '/app.sqlite');

// Bump this on meaningful changes - shown in the sidebar so it's obvious at a
// glance whether a given browser/deploy is actually running the latest code.
define('APP_VERSION', '1.7.0');

// data/ and cache/ must be writable by the php-fpm user (e.g. `chown -R www-data:www-data data cache`
// or on macOS, the user php-fpm runs as — see README.md).
foreach ([DATA_DIR, CACHE_DIR, THUMB_CACHE_DIR] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        http_response_code(500);
        die("Cannot create required directory: $dir. Check permissions.");
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/plex.php';

/**
 * Standard JSON response helper for api/*.php endpoints.
 */
function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_out(['error' => $message], $status);
}
