<?php
declare(strict_types=1);

/**
 * Background job lifecycle (Sync Library / Batch Process running outside any
 * single HTTP request, via cli/job_worker.php). Only one job may be
 * queued/running at a time - enforced here with BEGIN IMMEDIATE rather than
 * PDO's beginTransaction() (which issues a plain deferred BEGIN), so the
 * "is anything active?" check and the INSERT are atomic even against a
 * concurrent create_job() call from another browser tab/request. SQLite is
 * single-writer, so this is airtight.
 */
function create_job(
    string $type,
    int $sectionId,
    ?string $sectionTitle,
    ?array $assetTypes,
    bool $dryRun,
    int $startPos,
    ?int $stopPos,
    int $chunkSize
): array {
    $db = get_db();
    $db->exec('BEGIN IMMEDIATE');

    $active = $db->query("SELECT id, type FROM jobs WHERE status IN ('queued', 'running') LIMIT 1")->fetch();
    if ($active) {
        $db->exec('ROLLBACK');
        throw new RuntimeException("A {$active['type']} job is already running (#{$active['id']}). Wait for it to finish or cancel it.");
    }

    try {
        $now = now_iso();
        $stmt = $db->prepare('
            INSERT INTO jobs (type, status, section_id, section_title, asset_types_json, dry_run, start_pos, stop_pos, cursor, chunk_size, created_at, updated_at)
            VALUES (:type, \'queued\', :sec, :title, :assets, :dry, :start, :stop, :start, :chunk, :now, :now)
        ');
        $stmt->execute([
            'type'  => $type,
            'sec'   => $sectionId,
            'title' => $sectionTitle,
            'assets' => $assetTypes !== null ? json_encode(array_values($assetTypes)) : null,
            'dry'   => $dryRun ? 1 : 0,
            'start' => $startPos,
            'stop'  => $stopPos,
            'chunk' => $chunkSize,
            'now'   => $now,
        ]);
        $id = (int) $db->lastInsertId();
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    return get_job($id);
}

function get_active_job(): ?array
{
    $row = get_db()->query("SELECT * FROM jobs WHERE status IN ('queued', 'running') ORDER BY id DESC LIMIT 1")->fetch();
    return $row ?: null;
}

/**
 * Paginated/filterable job history for the Logs page's Job History panel -
 * every Sync/Batch run ever started, not just the currently active one.
 * @param array{type?:string,status?:string} $filters
 */
function get_jobs_history(array $filters, int $limit, int $offset): array
{
    $where = [];
    $params = [];
    if (!empty($filters['type'])) {
        $where[] = 'type = :type';
        $params['type'] = $filters['type'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'status = :status';
        $params['status'] = $filters['status'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $db = get_db();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM jobs {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM jobs {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_map('job_to_api', $stmt->fetchAll());

    return ['jobs' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function get_job(int $id): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM jobs WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Worker-only: atomically pick the oldest queued job and mark it running. A
 * job that was cancelled before the worker ever got to it is closed out here
 * without running a single chunk.
 */
function claim_next_job(): ?array
{
    $db = get_db();
    $db->exec('BEGIN IMMEDIATE');

    $row = $db->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$row) {
        $db->exec('ROLLBACK');
        return null;
    }

    $now = now_iso();
    if ((int) $row['cancel_requested'] === 1) {
        $stmt = $db->prepare("UPDATE jobs SET status = 'cancelled', finished_at = :now, updated_at = :now WHERE id = :id");
        $stmt->execute(['now' => $now, 'id' => $row['id']]);
        $db->exec('COMMIT');
        return null;
    }

    $stmt = $db->prepare("UPDATE jobs SET status = 'running', started_at = :now, updated_at = :now WHERE id = :id");
    $stmt->execute(['now' => $now, 'id' => $row['id']]);
    $db->exec('COMMIT');

    $row['status'] = 'running';
    $row['started_at'] = $now;
    return $row;
}

/**
 * Merge per-chunk results into a job's running totals. $countsDelta is a
 * partial map like ['new' => 2, 'failed' => 1] to add onto counts_json.
 * $newRecentItems are prepended to recent_items_json and the list is capped
 * at 50 entries - this is what lets the UI show a live per-item log after a
 * page reload, since the browser no longer accumulates it itself.
 */
function update_job_progress(int $id, int $cursor, ?int $totalSize, array $countsDelta, array $newRecentItems): void
{
    $job = get_job($id);
    if (!$job) {
        return;
    }

    $counts = json_decode($job['counts_json'], true) ?: [];
    foreach ($countsDelta as $status => $n) {
        $counts[$status] = ($counts[$status] ?? 0) + $n;
    }

    $recent = array_merge($newRecentItems, json_decode($job['recent_items_json'], true) ?: []);
    $recent = array_slice($recent, 0, 50);

    $stmt = get_db()->prepare('
        UPDATE jobs SET cursor = :cursor, total_size = :total, counts_json = :counts, recent_items_json = :recent, updated_at = :now
        WHERE id = :id
    ');
    $stmt->execute([
        'cursor' => $cursor,
        'total'  => $totalSize,
        'counts' => json_encode($counts),
        'recent' => json_encode($recent),
        'now'    => now_iso(),
        'id'     => $id,
    ]);
}

function finish_job(int $id, string $status, ?string $error = null): void
{
    $stmt = get_db()->prepare('UPDATE jobs SET status = :status, error = :error, finished_at = :now, updated_at = :now WHERE id = :id');
    $stmt->execute(['status' => $status, 'error' => $error, 'now' => now_iso(), 'id' => $id]);
}

/** Returns false if the job doesn't exist or is already in a terminal state. */
function request_cancel(int $id): bool
{
    $stmt = get_db()->prepare("UPDATE jobs SET cancel_requested = 1, updated_at = :now WHERE id = :id AND status IN ('queued', 'running')");
    $stmt->execute(['now' => now_iso(), 'id' => $id]);
    return $stmt->rowCount() > 0;
}

function is_cancel_requested(int $id): bool
{
    $stmt = get_db()->prepare('SELECT cancel_requested FROM jobs WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return ((int) $stmt->fetchColumn()) === 1;
}

/** Shapes a jobs row for JSON output: decode JSON columns, compute pct, camelCase keys. */
function job_to_api(array $row): array
{
    $total = $row['total_size'] !== null ? (int) $row['total_size'] : null;
    $cursor = (int) $row['cursor'];
    $start = (int) $row['start_pos'];
    $stop = $row['stop_pos'] !== null ? (int) $row['stop_pos'] : $total;
    $denom = $stop !== null ? ($stop - $start) : null;
    $pct = ($denom !== null && $denom > 0) ? min(100, (int) round((($cursor - $start) / $denom) * 100)) : (($row['status'] === 'done') ? 100 : 0);

    return [
        'id'              => (int) $row['id'],
        'type'            => $row['type'],
        'status'          => $row['status'],
        'sectionId'       => (int) $row['section_id'],
        'sectionTitle'    => $row['section_title'],
        'assetTypes'      => $row['asset_types_json'] !== null ? json_decode($row['asset_types_json'], true) : null,
        'dryRun'          => (bool) $row['dry_run'],
        'startPos'        => $start,
        'stopPos'         => $row['stop_pos'] !== null ? (int) $row['stop_pos'] : null,
        'cursor'          => $cursor,
        'totalSize'       => $total,
        'chunkSize'       => (int) $row['chunk_size'],
        'counts'          => json_decode($row['counts_json'], true) ?: [],
        'recentItems'     => json_decode($row['recent_items_json'], true) ?: [],
        'pct'             => $pct,
        'cancelRequested' => (bool) $row['cancel_requested'],
        'error'           => $row['error'],
        'createdAt'       => $row['created_at'],
        'startedAt'       => $row['started_at'],
        'finishedAt'      => $row['finished_at'],
        'updatedAt'       => $row['updated_at'],
    ];
}
