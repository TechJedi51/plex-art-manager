<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/batch.php';

// Sync is metadata-only (title, path, tmdb id) - no image downloads - so it's
// safe and fast to run over a whole library. Kept as a standalone endpoint
// (not just used via a background job) since it's useful to trigger a single
// chunk directly. See run_sync_page() in includes/batch.php for the actual
// work; cli/job_worker.php calls the same function for background runs.
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
    $result = run_sync_page($plex, $sectionId, $start, $size);
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

json_out($result);
