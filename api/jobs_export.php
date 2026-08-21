<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$where = [];
$params = [];
if (!empty($_GET['type'])) {
    $type = (string) $_GET['type'];
    if (!in_array($type, ['sync', 'batch'], true)) {
        json_error('type must be sync or batch');
    }
    $where[] = 'type = :type';
    $params['type'] = $type;
}
if (!empty($_GET['status'])) {
    $status = (string) $_GET['status'];
    if (!in_array($status, ['queued', 'running', 'done', 'failed', 'cancelled'], true)) {
        json_error('status must be one of queued, running, done, failed, cancelled');
    }
    $where[] = 'status = :status';
    $params['status'] = $status;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = get_db()->prepare("SELECT * FROM jobs {$whereSql} ORDER BY id ASC");
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plex-art-manager-jobs-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Type', 'Status', 'Library', 'Asset Types', 'Dry Run', 'Start', 'Stop', 'Total Size', 'Results', 'Error', 'Created', 'Started', 'Finished']);
while ($row = $stmt->fetch()) {
    $counts = json_decode($row['counts_json'] ?? '{}', true) ?: [];
    $countsStr = implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($counts), $counts));
    $assetTypes = $row['asset_types_json'] !== null ? implode(',', json_decode($row['asset_types_json'], true) ?: []) : '';
    fputcsv($out, [
        $row['id'],
        $row['type'],
        $row['status'],
        $row['section_title'],
        $assetTypes,
        $row['dry_run'] ? 'yes' : 'no',
        $row['start_pos'],
        $row['stop_pos'],
        $row['total_size'],
        $countsStr,
        $row['error'],
        $row['created_at'],
        $row['started_at'],
        $row['finished_at'],
    ]);
}
fclose($out);
exit;
