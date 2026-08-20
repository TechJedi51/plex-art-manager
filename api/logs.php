<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$filters = [];
if (!empty($_GET['level'])) {
    $level = (string) $_GET['level'];
    if (!in_array($level, ['debug', 'info', 'warn', 'error'], true)) {
        json_error('level must be one of debug, info, warn, error');
    }
    $filters['level'] = $level;
}
if (!empty($_GET['jobId'])) {
    $filters['jobId'] = (int) $_GET['jobId'];
}

json_out(get_logs($filters, $limit, $offset));
