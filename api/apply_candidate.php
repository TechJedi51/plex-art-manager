<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$ratingKey = (int) ($body['ratingKey'] ?? 0);
$assetType = (string) ($body['assetType'] ?? '');
$imageUrl = (string) ($body['imageUrl'] ?? '');
$source = (string) ($body['source'] ?? 'manual');

if (!$ratingKey || !isset(ASSET_FILENAMES[$assetType]) || $imageUrl === '') {
    json_error('ratingKey, a valid assetType, and imageUrl are required');
}
if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    json_error('imageUrl is not a valid URL');
}

$stmt = get_db()->prepare('SELECT folder_path, title FROM movies WHERE rating_key = :rk');
$stmt->execute(['rk' => $ratingKey]);
$movie = $stmt->fetch();
if (!$movie || !$movie['folder_path']) {
    json_error('Movie folder is unknown. Run a Sync or Batch first.', 404);
}

$filename = ASSET_FILENAMES[$assetType];
$error = null;
$status = save_with_backup($imageUrl, $movie['folder_path'], $filename, $error);

if ($status === 'failed') {
    json_error("Could not download the chosen image: " . ($error ?? 'unknown error'), 502);
}

log_asset_history($ratingKey, $assetType, $status, $source, $filename, 'Applied from candidate picker');
resolve_pending_review($ratingKey, $assetType);
log_line(null, 'info', 'Applied ' . $source . ' candidate for ' . ASSET_LABELS[$assetType] . ' — "' . $movie['title'] . "\" (#{$ratingKey}) — {$status}");

json_out(['status' => $status, 'filename' => $filename]);
