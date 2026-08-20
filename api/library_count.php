<?php
require_once __DIR__ . '/../includes/config.php';

// Backs the "All (####)" option on the Batch Process screen - a cheap
// size=1 request just to read Plex's totalSize for the section. This is a
// display-only number; api/jobs_start.php re-resolves the real total itself
// when an "All movies" job actually starts, rather than trusting this value.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$sectionId = (int) ($_GET['sectionId'] ?? 0);
if (!$sectionId) {
    json_error('sectionId is required');
}

try {
    $plex = new PlexClient();
    $page = $plex->getSectionItems($sectionId, 0, 1, 'movie');
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

json_out(['sectionId' => $sectionId, 'total' => (int) $page['totalSize']]);
