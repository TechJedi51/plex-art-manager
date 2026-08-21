<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

// Same filters as the Asset History panel on the Logs page, so "Export" downloads
// exactly what's currently shown - all optional, an unfiltered request exports everything.
$q = trim((string) ($_GET['q'] ?? ''));
$assetType = (string) ($_GET['assetType'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$source = (string) ($_GET['source'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = 'm.title LIKE :q';
    $params['q'] = '%' . $q . '%';
}
if ($assetType !== '') {
    if (!isset(ASSET_FILENAMES[$assetType])) {
        json_error('assetType is not valid');
    }
    $where[] = 'ah.asset_type = :at';
    $params['at'] = $assetType;
}
if ($status !== '') {
    $where[] = 'ah.status = :st';
    $params['st'] = $status;
}
if ($source !== '') {
    $where[] = 'ah.source = :src';
    $params['src'] = $source;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = get_db()->prepare("
    SELECT ah.id, ah.changed_at, m.title, m.year, ah.rating_key, ah.asset_type, ah.status, ah.source, ah.filename, ah.note
    FROM asset_history ah
    LEFT JOIN movies m ON m.rating_key = ah.rating_key
    {$whereSql}
    ORDER BY ah.id ASC
");
$stmt->execute($params);

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
