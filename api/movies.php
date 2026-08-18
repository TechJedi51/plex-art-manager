<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$q = trim((string) ($_GET['q'] ?? ''));
$sectionId = isset($_GET['sectionId']) ? (int) $_GET['sectionId'] : null;
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

// Whitelisted sort columns only - never interpolate the raw sort/dir params
// directly into SQL.
$sortColumns = [
    'title'      => 'm.title COLLATE NOCASE',
    'year'       => 'm.year',
    'rating_key' => 'm.rating_key',
];
$sortKey = (string) ($_GET['sort'] ?? 'title');
$sortSql = $sortColumns[$sortKey] ?? $sortColumns['title'];
$dir = strtoupper((string) ($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = 'm.title LIKE :q';
    $params['q'] = '%' . $q . '%';
}
if ($sectionId) {
    $where[] = 'm.library_section_id = :sec';
    $params['sec'] = $sectionId;
}

// Per-asset-type status filter, e.g. ?poster=failed&logo=unchanged. Each is
// independently optional and combines with AND when more than one is given.
$assetSelect = [];
foreach (array_keys(ASSET_FILENAMES) as $type) {
    $expr = effective_status_sql($type);
    $assetSelect[] = "{$expr} AS {$type}_status";

    $filterVal = (string) ($_GET[$type] ?? '');
    if ($filterVal !== '' && in_array($filterVal, EFFECTIVE_STATUSES, true)) {
        $where[] = "({$expr}) = :{$type}_filter";
        $params["{$type}_filter"] = $filterVal;
    }
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$db = get_db();

$countStmt = $db->prepare("SELECT COUNT(*) FROM movies m {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "
    SELECT m.rating_key, m.title, m.year, m.folder_path, m.tmdb_id,
           " . implode(",\n           ", $assetSelect) . "
    FROM movies m
    {$whereSql}
    ORDER BY {$sortSql} {$dir}, m.rating_key {$dir}
    LIMIT :limit OFFSET :offset
";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":$k", $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['display_path'] = display_path($row['folder_path']);
}
unset($row);

json_out([
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'sort'   => array_key_exists($sortKey, $sortColumns) ? $sortKey : 'title',
    'dir'    => strtolower($dir),
    'movies' => $rows,
]);
