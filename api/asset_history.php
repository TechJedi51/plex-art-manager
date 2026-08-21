<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$q = trim((string) ($_GET['q'] ?? ''));
$assetType = (string) ($_GET['assetType'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$source = (string) ($_GET['source'] ?? '');
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$sortColumns = [
    'changed_at' => 'ah.changed_at',
    'title'      => 'm.title COLLATE NOCASE',
];
$sortKey = (string) ($_GET['sort'] ?? 'changed_at');
$sortSql = $sortColumns[$sortKey] ?? $sortColumns['changed_at'];
$dir = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

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

$db = get_db();

$countStmt = $db->prepare("
    SELECT COUNT(*) FROM asset_history ah LEFT JOIN movies m ON m.rating_key = ah.rating_key {$whereSql}
");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $db->prepare("
    SELECT ah.id, ah.rating_key, ah.asset_type, ah.status, ah.source, ah.filename, ah.note, ah.changed_at,
           m.title, m.year
    FROM asset_history ah
    LEFT JOIN movies m ON m.rating_key = ah.rating_key
    {$whereSql}
    ORDER BY {$sortSql} {$dir}, ah.id {$dir}
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue(":$k", $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

json_out([
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'sort'   => array_key_exists($sortKey, $sortColumns) ? $sortKey : 'changed_at',
    'dir'    => strtolower($dir),
    'items'  => $items,
]);
