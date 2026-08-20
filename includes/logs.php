<?php
declare(strict_types=1);

/**
 * Narrative activity log backing the Log View screen. debug-level lines are
 * dropped unless the debug_mode setting is on, so turning it off doesn't just
 * hide noise in the UI - it stops writing it. info/warn/error are always
 * written so a job's outcome (or a real error) is never silently lost.
 */
function log_line(?int $jobId, string $level, string $message): void
{
    if ($level === 'debug' && get_setting('debug_mode', '0') !== '1') {
        return;
    }
    $stmt = get_db()->prepare('INSERT INTO logs (job_id, level, message, created_at) VALUES (:job, :level, :msg, :now)');
    $stmt->execute(['job' => $jobId, 'level' => $level, 'msg' => $message, 'now' => now_iso()]);
}

/**
 * @param array{level?:string,jobId?:int} $filters
 */
function get_logs(array $filters, int $limit, int $offset): array
{
    $where = [];
    $params = [];
    if (!empty($filters['level'])) {
        $where[] = 'level = :level';
        $params['level'] = $filters['level'];
    }
    if (!empty($filters['jobId'])) {
        $where[] = 'job_id = :job_id';
        $params['job_id'] = $filters['jobId'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $db = get_db();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM logs {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM logs {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return ['logs' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}
