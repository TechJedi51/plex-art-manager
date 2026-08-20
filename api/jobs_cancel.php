<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int) ($body['id'] ?? 0);
if (!$id) {
    json_error('id is required');
}

$job = get_job($id);
if (!$job) {
    json_error('Job not found', 404);
}
if (!in_array($job['status'], ['queued', 'running'], true)) {
    json_error('Job is not active', 409);
}

// The worker checks this flag between chunks - cancellation isn't instant,
// but it's within one chunk's worth of Plex calls.
request_cancel($id);
json_out(['ok' => true]);
