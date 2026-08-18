<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$ratingKey = (int) ($_GET['ratingKey'] ?? 0);
if (!$ratingKey) {
    json_error('ratingKey is required');
}

$db = get_db();

$stmt = $db->prepare('SELECT * FROM movies WHERE rating_key = :rk');
$stmt->execute(['rk' => $ratingKey]);
$movie = $stmt->fetch();
if (!$movie) {
    json_error('Movie not found in local cache. Run a Sync or Batch first.', 404);
}

$histStmt = $db->prepare('
    SELECT asset_type, status, source, filename, note, changed_at
    FROM asset_history WHERE rating_key = :rk
    ORDER BY changed_at DESC LIMIT 100
');
$histStmt->execute(['rk' => $ratingKey]);
$history = $histStmt->fetchAll();

$pendingStmt = $db->prepare('
    SELECT asset_type, reason, created_at FROM pending_review
    WHERE rating_key = :rk AND resolved = 0
');
$pendingStmt->execute(['rk' => $ratingKey]);
$pending = $pendingStmt->fetchAll();

$ignoreStmt = $db->prepare('SELECT asset_type, note FROM ignore_list WHERE rating_key = :rk');
$ignoreStmt->execute(['rk' => $ratingKey]);
$ignored = $ignoreStmt->fetchAll();

$files = $movie['folder_path'] ? list_folder_images($movie['folder_path']) : [];
$movie['display_path'] = display_path($movie['folder_path']);

json_out([
    'movie'   => $movie,
    'history' => $history,
    'pending' => $pending,
    'ignored' => $ignored,
    'files'   => $files, // frontend builds thumbnail URLs via api/image.php?ratingKey=&file=
]);
