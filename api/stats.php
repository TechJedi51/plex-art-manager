<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$db = get_db();

$totalMovies = (int) $db->query('SELECT COUNT(*) FROM movies')->fetchColumn();
$totalPending = (int) $db->query('SELECT COUNT(*) FROM pending_review WHERE resolved = 0')->fetchColumn();

$pendingByType = $db->query("
    SELECT asset_type, COUNT(*) as c FROM pending_review WHERE resolved = 0 GROUP BY asset_type
")->fetchAll(PDO::FETCH_KEY_PAIR);

$recentChanges = $db->query("
    SELECT ah.asset_type, ah.status, ah.source, ah.changed_at, m.title
    FROM asset_history ah
    JOIN movies m ON m.rating_key = ah.rating_key
    WHERE ah.status IN ('new','updated')
    ORDER BY ah.changed_at DESC
    LIMIT 20
")->fetchAll();

json_out([
    'totalMovies'    => $totalMovies,
    'totalPending'   => $totalPending,
    'pendingByType'  => $pendingByType,
    'recentChanges'  => $recentChanges,
    'plexConfigured' => (bool) get_setting('plex_url') && (bool) get_setting('plex_token'),
]);
