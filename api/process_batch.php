<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/batch.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$sectionId = (int) ($body['sectionId'] ?? 0);
$start = max(0, (int) ($body['start'] ?? 0));
$size = min(100, max(1, (int) ($body['size'] ?? 25)));
$dryRun = (bool) ($body['dryRun'] ?? false);
$assetTypes = array_values(array_intersect(
    (array) ($body['assetTypes'] ?? []),
    ['poster', 'art', 'square', 'logo']
));

if (!$sectionId) {
    json_error('sectionId is required');
}
if (!$assetTypes) {
    json_error('At least one asset type (poster/art/square/logo) is required');
}

try {
    $plex = new PlexClient();
    $result = run_batch($plex, $sectionId, $start, $size, $assetTypes, $dryRun);
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

json_out($result);
