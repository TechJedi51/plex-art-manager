<?php
require_once __DIR__ . '/../includes/config.php';

try {
    $plex = new PlexClient();
    $sections = $plex->getLibraries();
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

$out = [];
foreach ($sections as $s) {
    $out[] = [
        'id'    => (int) $s['key'],
        'title' => $s['title'],
        'type'  => $s['type'], // movie, show, artist, ...
    ];
}

json_out(['libraries' => $out]);
