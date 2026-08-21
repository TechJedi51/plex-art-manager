<?php
declare(strict_types=1);

/**
 * Narrative activity log backing the Log View screen. debug-level lines are
 * dropped unless the debug_mode setting is on, so turning it off doesn't just
 * hide noise in the UI - it stops writing it. info/warn/error are always
 * written so a job's outcome (or a real error) is never silently lost.
 *
 * A line that still can't be written after a few short retries (the database
 * is locked and stays locked) is queued to disk (see LOG_QUEUE_PATH) instead
 * of being dropped, and picked back up the next time anything touches
 * logging - see flush_log_queue().
 */
define('LOG_QUEUE_PATH', DATA_DIR . '/logs_queue.jsonl');

function log_line(?int $jobId, string $level, string $message): void
{
    if ($level === 'debug' && get_setting('debug_mode', '0') !== '1') {
        return;
    }

    flush_log_queue();

    $row = ['job_id' => $jobId, 'level' => $level, 'message' => $message, 'created_at' => now_iso()];

    // This is often called to record the very error a locked database just
    // caused (e.g. an uncaught PDOException from a write that lost a race
    // with the background job worker) - PRAGMA busy_timeout already waited
    // out a normal conflict before that write failed, so a log line landing
    // here means it's still contended, or was a fresh conflict of its own.
    // A few short retries catches the common case where the other writer's
    // transaction finishes a moment later.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if (write_log_row($row)) {
            return;
        }
        if ($attempt < 3) {
            usleep(200000 * $attempt); // 200ms, then 400ms
        }
    }

    // Still locked after retrying - don't lose the line, queue it for
    // flush_log_queue() to pick up on the next call instead.
    queue_log_row($row);
}

/** One INSERT attempt, no retry. Returns success; logs (to stderr) and returns false on any DB error. */
function write_log_row(array $row): bool
{
    try {
        $stmt = get_db()->prepare('INSERT INTO logs (job_id, level, message, created_at) VALUES (:job, :level, :msg, :now)');
        $stmt->execute(['job' => $row['job_id'], 'level' => $row['level'], 'msg' => $row['message'], 'now' => $row['created_at']]);
        return true;
    } catch (PDOException $e) {
        error_log('log_line: write failed (' . $e->getMessage() . ')');
        return false;
    }
}

function queue_log_row(array $row): void
{
    $fh = @fopen(LOG_QUEUE_PATH, 'a');
    if (!$fh) {
        error_log('log_line: could not open queue file, log line lost: ' . json_encode($row));
        return;
    }
    flock($fh, LOCK_EX);
    fwrite($fh, json_encode($row) . "\n");
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Drains data/logs_queue.jsonl back into the logs table. There's no persistent
 * worker for the web app itself (each request is a fresh php-fpm process), so
 * "the next request that touches logging" is what picks a backlog back up,
 * rather than a dedicated cron/daemon - called from log_line() (so the very
 * next log line drains it) and get_logs() (so opening the Logs page does too,
 * even if nothing new gets logged for a while).
 *
 * Stops at the first still-failing row rather than retrying every row on
 * every call - if the database is genuinely still locked, there's no point
 * paying that cost repeatedly; the next call tries again from where this one
 * left off. Malformed lines (should never happen - only this file writes
 * this file) are dropped with a warning rather than blocking the queue forever.
 */
function flush_log_queue(): void
{
    if (!is_file(LOG_QUEUE_PATH) || filesize(LOG_QUEUE_PATH) === 0) {
        return;
    }

    $fh = @fopen(LOG_QUEUE_PATH, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) {
            fclose($fh);
        }
        return;
    }

    $lines = [];
    rewind($fh);
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    $remaining = [];
    $stuck = false;
    foreach ($lines as $line) {
        if ($stuck) {
            $remaining[] = $line;
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['level'], $row['message'], $row['created_at'])) {
            error_log('flush_log_queue: dropping malformed queued line: ' . $line);
            continue;
        }
        $row += ['job_id' => null];
        if (!write_log_row($row)) {
            $stuck = true; // still locked - keep this and everything after it queued
            $remaining[] = $line;
        }
    }

    ftruncate($fh, 0);
    rewind($fh);
    if ($remaining) {
        fwrite($fh, implode("\n", $remaining) . "\n");
    }
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * @param array{level?:string,jobId?:int} $filters
 */
function get_logs(array $filters, int $limit, int $offset): array
{
    flush_log_queue();

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

    return ['logs' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'queuedPending' => is_file(LOG_QUEUE_PATH) ? filesize(LOG_QUEUE_PATH) > 0 : false];
}
