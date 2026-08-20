<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

if (isset($_GET['id'])) {
    $job = get_job((int) $_GET['id']);
    if (!$job) {
        json_error('Job not found', 404);
    }
    json_out(['job' => job_to_api($job)]);
}

// No id: return whichever job is currently queued/running, if any - used by
// the Batch/Movies/Dashboard screens to detect and reattach to an
// already-in-progress job on page load.
$job = get_active_job();
json_out(['job' => $job ? job_to_api($job) : null]);
