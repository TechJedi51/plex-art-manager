<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$filters = [];
if (!empty($_GET['type'])) {
    $type = (string) $_GET['type'];
    if (!in_array($type, ['sync', 'batch'], true)) {
        json_error('type must be sync or batch');
    }
    $filters['type'] = $type;
}
if (!empty($_GET['status'])) {
    $status = (string) $_GET['status'];
    if (!in_array($status, ['queued', 'running', 'done', 'failed', 'cancelled'], true)) {
        json_error('status must be one of queued, running, done, failed, cancelled');
    }
    $filters['status'] = $status;
}

json_out(get_jobs_history($filters, $limit, $offset));
