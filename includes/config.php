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
ini_set('log_errors', '1');
// php://stderr lands in `docker logs` alongside nginx/php-fpm output (see
// docker/supervisord.conf) - no extra volume or log file to go find.
ini_set('error_log', 'php://stderr');
date_default_timezone_set('America/Los_Angeles');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('CACHE_DIR', APP_ROOT . '/cache');
define('THUMB_CACHE_DIR', CACHE_DIR . '/thumbs');
define('DB_PATH', DATA_DIR . '/app.sqlite');

// Bump this on meaningful changes - shown in the sidebar so it's obvious at a
// glance whether a given browser/deploy is actually running the latest code.
define('APP_VERSION', '1.13.0');

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
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/logs.php';

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
    // 5xx here means a caught Throwable from a real failure (Plex unreachable, a
    // download or save that failed, a locked database, etc.) got turned into a
    // clean JSON response instead of propagating to the uncaught-exception
    // handler below - log it here too so it isn't lost. Ordinary 4xx validation
    // errors (missing/bad params) are expected and left out to avoid noise.
    if ($status >= 500) {
        log_line(null, 'error', $message);
    }
    json_out(['error' => $message], $status);
}

// Uncaught exceptions in an api/*.php endpoint would otherwise produce a raw,
// non-JSON PHP error page (display_errors is off, see above) - the frontend's
// api() helper can't parse that, so it falls back to a generic "Request
// failed (500)" with zero detail (e.g. apply_candidate.php, which has no
// try/catch of its own). This catches anything that slips past an endpoint's
// own error handling, turns it into a real JSON error response, and logs it
// both to docker logs (error_log, as above) and to the Logs page (log_line())
// so a failure like this is never a total mystery. CLI-only (job_worker.php,
// process_batch_cli.php) skip this - there's no HTTP response to send there,
// and job_worker.php already has its own comprehensive per-job error handling.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e) {
        error_log('Uncaught exception: ' . $e);
        try {
            log_line(null, 'error', 'Uncaught exception: ' . $e->getMessage());
        } catch (Throwable $loggingFailed) {
            // Don't let a failure to log mask the real error below.
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        exit;
    });
}
