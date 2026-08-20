#!/usr/bin/env php
<?php
/**
 * Persistent background worker (run as a supervisord program, see
 * docker/supervisord.conf) that picks up queued jobs from the `jobs` table
 * and runs them chunk-by-chunk, independent of any HTTP request - this is
 * what lets Sync Library / Batch Process keep running after the browser tab
 * that started them is closed. The browser only ever polls job status via
 * api/jobs_status.php; it never drives the loop itself (that's the old
 * behavior this replaces - see includes/batch.php's run_batch()/
 * run_sync_page(), the same functions cli/process_batch_cli.php and
 * api/process_batch.php use for a single chunk).
 *
 * Only one job can ever be 'running' at a time (enforced in
 * includes/jobs.php's create_job()), so on startup, any job still marked
 * 'running' can only be left over from a previous instance of this same
 * script dying mid-chunk (OOM, `docker restart`, a manual kill). Requeuing it
 * lets it resume from its last persisted cursor - safe because every write
 * in the chunk path is idempotent (save_with_backup() md5-compares before
 * overwriting, upsert_movie()/add_pending_review()/resolve_pending_review()
 * are all upserts), so at worst the last partially-completed page gets
 * reprocessed, never corrupted or duplicated.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/batch.php';

get_db()->exec("UPDATE jobs SET status = 'queued', updated_at = '" . now_iso() . "' WHERE status = 'running'");

while (true) {
    $job = claim_next_job();
    if (!$job) {
        usleep(2_000_000);
        continue;
    }

    log_line($job['id'], 'info', "Job #{$job['id']} ({$job['type']}) started - {$job['section_title']}");

    try {
        $plex = new PlexClient();
        $cursor = (int) $job['cursor'];
        $stopPos = $job['stop_pos'] !== null ? (int) $job['stop_pos'] : null;
        $chunkSize = (int) $job['chunk_size'];
        $assetTypes = $job['asset_types_json'] !== null ? json_decode($job['asset_types_json'], true) : null;
        $dryRun = (bool) $job['dry_run'];

        while (true) {
            if (is_cancel_requested($job['id'])) {
                finish_job($job['id'], 'cancelled');
                log_line($job['id'], 'info', "Job #{$job['id']} cancelled");
                continue 2;
            }

            $size = $chunkSize;
            if ($stopPos !== null) {
                $size = min($size, max(0, $stopPos - $cursor));
            }
            if ($size <= 0) {
                break;
            }

            $result = $job['type'] === 'batch'
                ? run_batch($plex, (int) $job['section_id'], $cursor, $size, $assetTypes, $dryRun)
                : run_sync_page($plex, (int) $job['section_id'], $cursor, $size);

            [$countsDelta, $recentItems] = $job['type'] === 'batch'
                ? summarize_batch_page($result)
                : summarize_sync_page($result);

            $cursor = $result['nextStart'];
            update_job_progress($job['id'], $cursor, $result['totalSize'], $countsDelta, $recentItems);

            if (get_setting('debug_mode', '0') === '1') {
                log_line($job['id'], 'debug', "Job #{$job['id']} processed {$result['start']}-{$result['nextStart']} of {$result['totalSize']}");
            }

            if ($result['done'] || ($stopPos !== null && $cursor >= $stopPos)) {
                break;
            }
        }

        finish_job($job['id'], 'done');
        $finalCounts = json_decode(get_job($job['id'])['counts_json'], true) ?: [];
        $summary = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($finalCounts), $finalCounts));
        log_line($job['id'], 'info', "Job #{$job['id']} finished - {$summary}");
    } catch (Throwable $e) {
        finish_job($job['id'], 'failed', $e->getMessage());
        log_line($job['id'], 'error', "Job #{$job['id']} failed: {$e->getMessage()}");
        error_log("job_worker: job #{$job['id']} failed: {$e}");
    }
}

/**
 * Same "what counts as a change worth showing" logic assets/js/app.js's old
 * runBatchLoop() used client-side - only non-'unchanged' asset outcomes get
 * counted/logged, matching totals aggregation that already ignored dry-run
 * would_* statuses.
 */
function summarize_batch_page(array $result): array
{
    $counts = [];
    $recentItems = [];
    foreach ($result['items'] as $item) {
        foreach ($item['assets'] as $assetType => $r) {
            $status = $r['status'] ?? 'failed';
            if (in_array($status, ['new', 'updated', 'unchanged', 'failed', 'kept_existing'], true)) {
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            if (in_array($status, ['new', 'updated', 'would_create', 'would_update_or_match', 'failed', 'would_fail'], true)) {
                $recentItems[] = [
                    'title'     => $item['title'],
                    'path'      => $item['displayPath'] ?? $item['path'],
                    'assetType' => $assetType,
                    'status'    => $status,
                    'error'     => $r['error'] ?? null,
                ];
            }
        }
    }
    return [$counts, $recentItems];
}

function summarize_sync_page(array $result): array
{
    $counts = ['synced' => 0, 'skipped' => 0];
    $recentItems = [];
    foreach ($result['items'] as $item) {
        if ($item['ok']) {
            $counts['synced']++;
        } else {
            $counts['skipped']++;
            $recentItems[] = [
                'title'  => $item['title'],
                'status' => 'skipped',
                'error'  => "Plex couldn't serve full metadata for #{$item['ratingKey']} right now",
            ];
        }
    }
    return [$counts, $recentItems];
}
