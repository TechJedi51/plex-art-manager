<?php
require_once __DIR__ . '/../includes/config.php';

// Queues a Sync Library or Batch Process run for cli/job_worker.php to pick
// up in the background - this endpoint itself does no Plex/file work beyond
// (for allMovies) resolving the current library size, so it returns fast.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

const BATCH_CHUNK_SIZE = 15;
const SYNC_CHUNK_SIZE = 20;

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($body['type'] ?? '');
$sectionId = (int) ($body['sectionId'] ?? 0);
$sectionTitle = isset($body['sectionTitle']) ? (string) $body['sectionTitle'] : null;

if (!in_array($type, ['sync', 'batch'], true)) {
    json_error('type must be "sync" or "batch"');
}
if (!$sectionId) {
    json_error('sectionId is required');
}

try {
    $plex = new PlexClient();
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

if ($type === 'sync') {
    try {
        $job = create_job('sync', $sectionId, $sectionTitle, null, false, 0, null, SYNC_CHUNK_SIZE);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 409);
    }
    json_out(['job' => job_to_api($job)], 201);
}

// type === 'batch'
$assetTypes = array_values(array_intersect(
    (array) ($body['assetTypes'] ?? []),
    ['poster', 'art', 'square', 'logo']
));
if (!$assetTypes) {
    json_error('At least one asset type (poster/art/square/logo) is required');
}
$dryRun = (bool) ($body['dryRun'] ?? false);
$allMovies = (bool) ($body['allMovies'] ?? false);

if ($allMovies) {
    // Never trust a client-cached count as the source of truth for how many
    // movies the job will process - resolve it fresh from Plex right now.
    try {
        $page = $plex->getSectionItems($sectionId, 0, 1, 'movie');
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }
    $start = 0;
    $stop = (int) $page['totalSize'];
} else {
    $start = max(0, (int) ($body['start'] ?? 0));
    $stop = isset($body['stop']) && $body['stop'] !== '' ? max($start, (int) $body['stop']) : null;
}

try {
    $job = create_job('batch', $sectionId, $sectionTitle, $assetTypes, $dryRun, $start, $stop, BATCH_CHUNK_SIZE);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 409);
}
json_out(['job' => job_to_api($job)], 201);
