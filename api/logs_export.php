<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$where = '';
$params = [];
if (!empty($_GET['level'])) {
    $level = (string) $_GET['level'];
    if (!in_array($level, ['debug', 'info', 'warn', 'error'], true)) {
        json_error('level must be one of debug, info, warn, error');
    }
    $where = 'WHERE level = :level';
    $params['level'] = $level;
}

$stmt = get_db()->prepare("SELECT id, created_at, level, job_id, message FROM logs {$where} ORDER BY id ASC");
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plex-art-manager-logs-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Time', 'Level', 'Job ID', 'Message']);
while ($row = $stmt->fetch()) {
    fputcsv($out, [$row['id'], $row['created_at'], $row['level'], $row['job_id'], $row['message']]);
}
fclose($out);
exit;
