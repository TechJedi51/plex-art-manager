<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$q = trim((string) ($_GET['q'] ?? ''));

// Comma-separated asset types to include, e.g. ?types=poster,logo. Empty/absent = all types.
$typesParam = (string) ($_GET['types'] ?? '');
$types = $typesParam !== '' ? array_values(array_intersect(explode(',', $typesParam), array_keys(ASSET_FILENAMES))) : [];

$sortColumns = [
    'title'      => 'm.title COLLATE NOCASE',
    'rating_key' => 'pr.rating_key',
    'created_at' => 'pr.created_at',
];
$sortKey = (string) ($_GET['sort'] ?? 'created_at');
$sortSql = $sortColumns[$sortKey] ?? $sortColumns['created_at'];
$dir = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

$where = ['pr.resolved = 0'];
$params = [];
if ($q !== '') {
    $where[] = 'm.title LIKE :q';
    $params['q'] = '%' . $q . '%';
}
if ($types) {
    $placeholders = [];
    foreach ($types as $i => $t) {
        $key = "type{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $t;
    }
    $where[] = 'pr.asset_type IN (' . implode(',', $placeholders) . ')';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$db = get_db();

$countStmt = $db->prepare("
    SELECT COUNT(*) FROM pending_review pr
    JOIN movies m ON m.rating_key = pr.rating_key
    {$whereSql}
");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $db->prepare("
    SELECT pr.id, pr.rating_key, pr.asset_type, pr.reason, pr.created_at,
           m.title, m.year, m.folder_path
    FROM pending_review pr
    JOIN movies m ON m.rating_key = pr.rating_key
    {$whereSql}
    ORDER BY {$sortSql} {$dir}, pr.id {$dir}
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue(":$k", $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();
foreach ($items as &$item) {
    $item['display_path'] = display_path($item['folder_path']);
}
unset($item);

json_out([
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'sort'   => array_key_exists($sortKey, $sortColumns) ? $sortKey : 'created_at',
    'dir'    => strtolower($dir),
    'items'  => $items,
]);
