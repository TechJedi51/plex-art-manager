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

    $allowed = ['plex_url', 'plex_token', 'fanart_api_key', 'tmdb_api_key', 'thumb_max_width', 'batch_default_size', 'folder_mappings_json', 'debug_mode'];
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
        if ($key === 'folder_mappings_json') {
            $rows = json_decode($val, true);
            if (!is_array($rows)) {
                json_error('folder_mappings_json must be a JSON array');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !array_key_exists('plexPath', $row) || !array_key_exists('localPath', $row) || !array_key_exists('displayPath', $row)) {
                    json_error('Each folder mapping row needs plexPath, localPath, and displayPath');
                }
            }
        }
        if ($key === 'thumb_max_width' && (!ctype_digit($val) || (int) $val < 20 || (int) $val > 2000)) {
            json_error('thumb_max_width must be a number between 20 and 2000');
        }
        if ($key === 'debug_mode' && !in_array($val, ['0', '1'], true)) {
            json_error('debug_mode must be 0 or 1');
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
