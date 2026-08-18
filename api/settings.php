<?php
require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $all = get_all_settings();
    $out = [];
    foreach ($all as $k => $v) {
        $out[$k] = in_array($k, SECRET_SETTINGS, true) ? mask_secret($v) : $v;
    }
    // Let the frontend know whether secrets are actually set, without ever sending them in full.
    $out['_configured'] = [
        'plex'   => (bool) get_setting('plex_url') && (bool) get_setting('plex_token'),
        'fanart' => (bool) get_setting('fanart_api_key'),
        'tmdb'   => (bool) get_setting('tmdb_api_key'),
    ];
    json_out($out);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_error('Invalid JSON body');
    }

    $allowed = ['plex_url', 'plex_token', 'fanart_api_key', 'tmdb_api_key', 'thumb_max_width', 'batch_default_size', 'mapped_folders_json', 'base_path'];
    $toSave = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $val = (string) $body[$key];
        // A masked value (e.g. "••••abcd") came back unchanged from the UI — don't overwrite the real secret with it.
        if (in_array($key, SECRET_SETTINGS, true) && str_contains($val, '•')) {
            continue;
        }
        if ($key === 'mapped_folders_json') {
            json_decode($val); // validate
            if (json_last_error() !== JSON_ERROR_NONE) {
                json_error('mapped_folders_json must be valid JSON');
            }
        }
        if ($key === 'thumb_max_width' && (!ctype_digit($val) || (int) $val < 20 || (int) $val > 2000)) {
            json_error('thumb_max_width must be a number between 20 and 2000');
        }
        $toSave[$key] = $val;
    }

    set_settings($toSave);

    // Bust cached thumbnails if the max width changed, so old sizes don't linger.
    if (isset($toSave['thumb_max_width'])) {
        foreach (glob(THUMB_CACHE_DIR . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    json_out(['saved' => true]);
}

json_error('Method not allowed', 405);
