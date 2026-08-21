<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$stmt = get_db()->query('
    SELECT ah.id, ah.changed_at, m.title, m.year, ah.rating_key, ah.asset_type, ah.status, ah.source, ah.filename, ah.note
    FROM asset_history ah
    LEFT JOIN movies m ON m.rating_key = ah.rating_key
    ORDER BY ah.id ASC
');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plex-art-manager-asset-history-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Time', 'Title', 'Year', 'Plex ID', 'Asset Type', 'Status', 'Source', 'Filename', 'Note']);
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['id'],
        $row['changed_at'],
        $row['title'] ?? '',
        $row['year'] ?? '',
        $row['rating_key'],
        ASSET_LABELS[$row['asset_type']] ?? $row['asset_type'],
        $row['status'],
        $row['source'],
        $row['filename'] ?? '',
        $row['note'] ?? '',
    ]);
}
fclose($out);
exit;
