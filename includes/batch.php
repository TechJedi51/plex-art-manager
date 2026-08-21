<?php
declare(strict_types=1);

/**
 * Process one page of a library section: for each item, attempt to save each
 * requested asset type. Returns a summary array plus per-item results, shaped
 * for direct JSON output to the frontend's live progress table.
 *
 * This is the single place batch logic lives — both api/process_batch.php
 * (driven by the browser, one chunk per AJAX call) and cli/process_batch_cli.php
 * (driven by cron, looping over the whole library) call this function.
 *
 * @param int[]  $sectionId    Plex library section id
 * @param string[] $assetTypes subset of ['poster','art','square','logo']
 */
function run_batch(PlexClient $plex, int $sectionId, int $start, int $size, array $assetTypes, bool $dryRun = false): array
{
    $page = $plex->getSectionItems($sectionId, $start, $size, 'movie');
    $results = [];

    foreach ($page['items'] as $itemSummary) {
        $ratingKey = (int) $itemSummary['ratingKey'];

        try {
            $item = $plex->getItemFull($ratingKey);
        } catch (Throwable $e) {
            $results[] = [
                'ratingKey'   => $ratingKey,
                'title'       => $itemSummary['title'] ?? "#{$ratingKey}",
                'path'        => null,
                'displayPath' => null,
                'assets'      => array_fill_keys($assetTypes, ['status' => 'failed', 'error' => $e->getMessage()]),
            ];
            continue;
        }

        $title = $item['title'] ?? "#{$ratingKey}";
        $folder = $plex->itemFolderPath($item);
        $tmdbId = $plex->itemTmdbId($item);
        $imdbId = $plex->itemImdbId($item);
        $year = $item['year'] ?? null;

        upsert_movie($ratingKey, $sectionId, $title, $year, $folder, $tmdbId, $imdbId);

        $assetResults = [];
        foreach ($assetTypes as $assetType) {
            $assetResults[$assetType] = process_one_asset($plex, $ratingKey, $title, $folder, $item, $assetType, $dryRun);
        }

        $results[] = [
            'ratingKey'   => $ratingKey,
            'title'       => $title,
            'path'        => $folder,
            'displayPath' => display_path($folder),
            'assets'      => $assetResults,
        ];
    }

    return [
        'sectionId'   => $sectionId,
        'start'       => $start,
        'size'        => $size,
        'totalSize'   => $page['totalSize'],
        'nextStart'   => $start + count($page['items']),
        'done'        => ($start + count($page['items'])) >= $page['totalSize'],
        'dryRun'      => $dryRun,
        'items'       => $results,
    ];
}

/**
 * Save (or dry-run-check) a single asset type for a single movie. Handles the
 * "kept_existing" fallback for --art the same way the original script did,
 * plus pending-review bookkeeping for anything that still needs attention.
 */
function process_one_asset(PlexClient $plex, int $ratingKey, string $title, ?string $folder, array $item, string $assetType, bool $dryRun): array
{
    if (!$folder) {
        $status = 'failed';
        $error = 'No file path on this Plex item (nothing to save alongside)';
        if (!$dryRun) {
            log_asset_history($ratingKey, $assetType, $status, 'plex', null, $error);
            if (!is_ignored($ratingKey, $assetType)) {
                add_pending_review($ratingKey, $assetType, $error);
            }
        }
        return ['status' => $status, 'error' => $error];
    }

    $filename = ASSET_FILENAMES[$assetType];
    $url = $plex->itemAssetUrl($item, $assetType);

    // Posters specifically: Plex also recognizes a poster named after the
    // video file itself (see find_existing_poster()), not just "poster.jpg".
    // If one already exists under that naming, treat it as the current
    // poster and stop here - don't download a fresh "poster.jpg" alongside
    // a file Plex already considers valid artwork for this movie.
    if ($assetType === 'poster') {
        $videoBasename = $plex->itemVideoBasename($item);
        $altExisting = find_existing_poster($folder, $filename, $videoBasename);
        if ($altExisting !== null && basename($altExisting) !== $filename) {
            if ($dryRun) {
                return ['status' => 'would_update_or_match', 'filename' => basename($altExisting)];
            }
            log_asset_history($ratingKey, $assetType, 'unchanged', 'plex', basename($altExisting), 'Recognized existing Plex-named poster file; left as-is');
            resolve_pending_review($ratingKey, $assetType);
            return ['status' => 'unchanged', 'filename' => basename($altExisting)];
        }
    }

    if ($dryRun) {
        if (!is_dir($folder)) {
            return ['status' => 'would_fail', 'error' => "Folder not accessible from Plex Art Manager: {$folder}"];
        }
        if (!$url) {
            return ['status' => 'would_fail', 'error' => 'No ' . $assetType . ' available from Plex'];
        }
        $existing = find_existing_file($folder, $filename);
        return ['status' => $existing ? 'would_update_or_match' : 'would_create'];
    }

    if (!$url) {
        // No source image from Plex at all.
        if ($assetType === 'art') {
            $existing = find_existing_file($folder, $filename);
            if ($existing) {
                log_asset_history($ratingKey, $assetType, 'kept_existing', 'plex', basename($existing), 'No art from Plex; kept existing file');
                resolve_pending_review($ratingKey, $assetType);
                return ['status' => 'kept_existing', 'filename' => basename($existing)];
            }
        }
        $error = "No {$assetType} available from Plex";
        log_asset_history($ratingKey, $assetType, 'failed', 'plex', null, $error);
        if (!is_ignored($ratingKey, $assetType)) {
            add_pending_review($ratingKey, $assetType, $error);
        }
        return ['status' => 'failed', 'error' => $error];
    }

    $error = null;
    $status = save_with_backup($url, $folder, $filename, $error);

    if ($status === 'failed' && $assetType === 'art') {
        $existing = find_existing_file($folder, $filename);
        if ($existing) {
            log_asset_history($ratingKey, $assetType, 'kept_existing', 'plex', basename($existing), "Download failed ({$error}); kept existing file");
            resolve_pending_review($ratingKey, $assetType);
            return ['status' => 'kept_existing', 'filename' => basename($existing), 'note' => "Plex download failed ({$error}); kept existing background."];
        }
    }

    log_asset_history($ratingKey, $assetType, $status, 'plex', $filename, $error);

    if ($status === 'failed') {
        if (!is_ignored($ratingKey, $assetType)) {
            add_pending_review($ratingKey, $assetType, $error ?? 'Download failed');
        }
    } else {
        resolve_pending_review($ratingKey, $assetType);
    }

    return array_filter(['status' => $status, 'filename' => $filename, 'error' => $error]);
}

/**
 * Process one page of a library section, metadata-only (title, path, tmdb/imdb
 * ids) - no image downloads. Same "one page per call" shape as run_batch(), so
 * both api/sync_library.php (one chunk per AJAX call) and cli/job_worker.php
 * (looping until done) can share it.
 */
function run_sync_page(PlexClient $plex, int $sectionId, int $start, int $size): array
{
    $page = $plex->getSectionItems($sectionId, $start, $size, 'movie');

    $items = [];
    foreach ($page['items'] as $summary) {
        $ratingKey = (int) $summary['ratingKey'];
        try {
            $item = $plex->getItemFull($ratingKey);
        } catch (Throwable $e) {
            $items[] = ['ratingKey' => $ratingKey, 'title' => $summary['title'] ?? "#{$ratingKey}", 'ok' => false];
            continue; // skip items Plex can't currently serve full metadata for
        }
        $folder = $plex->itemFolderPath($item);
        $tmdbId = $plex->itemTmdbId($item);
        $imdbId = $plex->itemImdbId($item);
        $title = $item['title'] ?? "#{$ratingKey}";
        upsert_movie($ratingKey, $sectionId, $title, $item['year'] ?? null, $folder, $tmdbId, $imdbId);
        $items[] = ['ratingKey' => $ratingKey, 'title' => $title, 'ok' => true];
    }

    return [
        'sectionId' => $sectionId,
        'start'     => $start,
        'size'      => $size,
        'totalSize' => $page['totalSize'],
        'nextStart' => $start + count($page['items']),
        'done'      => ($start + count($page['items'])) >= $page['totalSize'],
        'synced'    => count($items),
        'items'     => $items,
    ];
}

function upsert_movie(int $ratingKey, int $sectionId, string $title, ?int $year, ?string $folder, ?int $tmdbId, ?string $imdbId): void
{
    $stmt = get_db()->prepare('
        INSERT INTO movies (rating_key, library_section_id, title, year, folder_path, tmdb_id, imdb_id, last_synced_at)
        VALUES (:rk, :sec, :title, :year, :folder, :tmdb, :imdb, :ts)
        ON CONFLICT(rating_key) DO UPDATE SET
            library_section_id = :sec, title = :title, year = :year,
            folder_path = :folder, tmdb_id = :tmdb, imdb_id = :imdb, last_synced_at = :ts
    ');
    $stmt->execute([
        'rk' => $ratingKey, 'sec' => $sectionId, 'title' => $title, 'year' => $year,
        'folder' => $folder, 'tmdb' => $tmdbId, 'imdb' => $imdbId, 'ts' => now_iso(),
    ]);
}
