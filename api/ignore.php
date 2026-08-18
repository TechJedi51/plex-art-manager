<?php
require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET with no ratingKey = list mode: every currently-ignored (movie, asset type)
// pair, searchable/sortable/filterable the same way Needs Review is.
if ($method === 'GET' && !isset($_GET['ratingKey'])) {
    $q = trim((string) ($_GET['q'] ?? ''));
    $typesParam = (string) ($_GET['types'] ?? '');
    $types = $typesParam !== '' ? array_values(array_intersect(explode(',', $typesParam), array_keys(ASSET_FILENAMES))) : [];
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $sortColumns = [
        'title'      => 'm.title COLLATE NOCASE',
        'rating_key' => 'il.rating_key',
        'created_at' => 'il.created_at',
    ];
    $sortKey = (string) ($_GET['sort'] ?? 'created_at');
    $sortSql = $sortColumns[$sortKey] ?? $sortColumns['created_at'];
    $dir = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = [];
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
        $where[] = 'il.asset_type IN (' . implode(',', $placeholders) . ')';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $db = get_db();

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM ignore_list il JOIN movies m ON m.rating_key = il.rating_key {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT il.rating_key, il.asset_type, il.note, il.created_at,
               m.title, m.year, m.folder_path
        FROM ignore_list il
        JOIN movies m ON m.rating_key = il.rating_key
        {$whereSql}
        ORDER BY {$sortSql} {$dir}, il.rating_key {$dir}
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
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$ratingKey = (int) ($body['ratingKey'] ?? $_GET['ratingKey'] ?? 0);
$assetType = (string) ($body['assetType'] ?? $_GET['assetType'] ?? '');

if (!$ratingKey || !isset(ASSET_FILENAMES[$assetType])) {
    json_error('ratingKey and a valid assetType are required');
}

if ($method === 'POST') {
    $note = (string) ($body['note'] ?? '');
    $stmt = get_db()->prepare('
        INSERT INTO ignore_list (rating_key, asset_type, note, created_at) VALUES (:rk, :at, :note, :ts)
        ON CONFLICT(rating_key, asset_type) DO UPDATE SET note = :note
    ');
    $stmt->execute(['rk' => $ratingKey, 'at' => $assetType, 'note' => $note, 'ts' => now_iso()]);
    resolve_pending_review($ratingKey, $assetType);
    json_out(['ignored' => true]);
}

if ($method === 'DELETE') {
    $stmt = get_db()->prepare('DELETE FROM ignore_list WHERE rating_key = :rk AND asset_type = :at');
    $stmt->execute(['rk' => $ratingKey, 'at' => $assetType]);
    json_out(['ignored' => false]);
}

json_error('Method not allowed', 405);
