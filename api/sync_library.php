<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/batch.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$sectionId = (int) ($body['sectionId'] ?? 0);
$start = max(0, (int) ($body['start'] ?? 0));
$size = min(100, max(1, (int) ($body['size'] ?? 50)));

if (!$sectionId) {
    json_error('sectionId is required');
}

try {
    $plex = new PlexClient();
    $page = $plex->getSectionItems($sectionId, $start, $size, 'movie');
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

// Sync is metadata-only (title, path, tmdb id) — no image downloads — so it's
// safe and fast to run over a whole library. One full-item fetch per movie is
// still required to get the file path and tmdb/imdb ids from Guid[].
$items = [];
foreach ($page['items'] as $summary) {
    $ratingKey = (int) $summary['ratingKey'];
    try {
        $item = $plex->getItemFull($ratingKey);
    } catch (Throwable $e) {
        $items[] = ['ratingKey' => $ratingKey, 'title' => $summary['title'] ?? "#{$ratingKey}", 'ok' => false];
        continue; // skip items Plex can't currently serve full metadata for
    }
    $folder = $plex->itemFolderPath($item);
    $tmdbId = $plex->itemTmdbId($item);
    $imdbId = $plex->itemImdbId($item);
    $title = $item['title'] ?? "#{$ratingKey}";
    upsert_movie($ratingKey, $sectionId, $title, $item['year'] ?? null, $folder, $tmdbId, $imdbId);
    $items[] = ['ratingKey' => $ratingKey, 'title' => $title, 'ok' => true];
}

json_out([
    'sectionId' => $sectionId,
    'start'     => $start,
    'size'      => $size,
    'totalSize' => $page['totalSize'],
    'nextStart' => $start + count($page['items']),
    'done'      => ($start + count($page['items'])) >= $page['totalSize'],
    'synced'    => count($page['items']),
    'items'     => $items,
]);
