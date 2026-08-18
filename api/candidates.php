<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/providers/fanart.php';
require_once __DIR__ . '/../includes/providers/tmdb.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$ratingKey = (int) ($_GET['ratingKey'] ?? 0);
$assetType = (string) ($_GET['assetType'] ?? '');
if (!$ratingKey || !isset(ASSET_FILENAMES[$assetType])) {
    json_error('ratingKey and a valid assetType are required');
}

$stmt = get_db()->prepare('SELECT tmdb_id, title FROM movies WHERE rating_key = :rk');
$stmt->execute(['rk' => $ratingKey]);
$movie = $stmt->fetch();
if (!$movie) {
    json_error('Movie not found in local cache. Run a Sync or Batch first.', 404);
}

$tmdbId = $movie['tmdb_id'];
if (!$tmdbId) {
    // Not cached yet — try a live lookup so this still works right after a fresh batch run.
    try {
        $plex = new PlexClient();
        $item = $plex->getItemFull($ratingKey);
        $tmdbId = $plex->itemTmdbId($item);
        if ($tmdbId) {
            get_db()->prepare('UPDATE movies SET tmdb_id = :t WHERE rating_key = :rk')->execute(['t' => $tmdbId, 'rk' => $ratingKey]);
        }
    } catch (Throwable $e) {
        // fall through — we'll report no tmdb id below
    }
}

if (!$tmdbId) {
    json_out([
        'candidates' => [],
        'note' => 'This movie has no TMDB id in Plex\'s metadata, so it can\'t be matched against Fanart.tv/TMDB for candidates.',
    ]);
}

$fanart = new FanartProvider();
$tmdb = new TmdbProvider();

$candidates = array_merge(
    $fanart->getCandidates((int) $tmdbId, $assetType),
    $tmdb->getCandidates((int) $tmdbId, $assetType)
);

$note = null;
if ($assetType === 'square') {
    $note = 'Neither Fanart.tv nor TMDB has a dedicated "square art" category for movies, so this list will usually be empty. Consider a manual upload for square art.';
}
if (!$fanart->isConfigured() && !$tmdb->isConfigured()) {
    $note = 'No Fanart.tv or TMDB API key is set — add one on the Settings page to see candidate images here.';
}

json_out(['candidates' => $candidates, 'note' => $note, 'tmdbId' => $tmdbId]);
