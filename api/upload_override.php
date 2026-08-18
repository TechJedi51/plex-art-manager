<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$ratingKey = (int) ($_POST['ratingKey'] ?? 0);
$assetType = (string) ($_POST['assetType'] ?? '');

if (!$ratingKey || !isset(ASSET_FILENAMES[$assetType])) {
    json_error('ratingKey and a valid assetType are required');
}
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('No file uploaded, or upload failed');
}

$allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['file']['tmp_name']);
if (!isset($allowedMime[$mime])) {
    json_error('Only JPEG, PNG, or WebP images are accepted');
}

$stmt = get_db()->prepare('SELECT folder_path FROM movies WHERE rating_key = :rk');
$stmt->execute(['rk' => $ratingKey]);
$folder = $stmt->fetchColumn();
if (!$folder) {
    json_error('Movie folder is unknown. Run a Sync or Batch first.', 404);
}

$filename = ASSET_FILENAMES[$assetType];
$existingFile = rtrim($folder, '/') . '/' . $filename;
$tmpUpload = $_FILES['file']['tmp_name'];

if (is_file($existingFile)) {
    if (md5_file($existingFile) === md5_file($tmpUpload)) {
        json_out(['status' => 'unchanged', 'filename' => $filename]);
    }
    $today = date('Y-m-d');
    $stem = pathinfo($existingFile, PATHINFO_FILENAME);
    $suffix = pathinfo($existingFile, PATHINFO_EXTENSION);
    $backupPath = "{$folder}/{$stem}_{$today}.{$suffix}";
    $counter = 1;
    while (is_file($backupPath)) {
        $backupPath = "{$folder}/{$stem}_{$today}_{$counter}.{$suffix}";
        $counter++;
    }
    rename($existingFile, $backupPath);
    $status = 'updated';
} else {
    $status = 'new';
}

if (!move_uploaded_file($tmpUpload, $existingFile)) {
    json_error('Could not save the uploaded file (check folder permissions)', 500);
}

log_asset_history($ratingKey, $assetType, $status, 'manual', $filename, 'Manually uploaded');
resolve_pending_review($ratingKey, $assetType);

json_out(['status' => $status, 'filename' => $filename]);
