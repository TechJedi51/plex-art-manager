<?php
declare(strict_types=1);

function now_iso(): string
{
    return (new DateTime('now'))->format('Y-m-d\TH:i:s');
}

/**
 * Both map_path() and display_path() read the same folder_mappings_json
 * setting - a JSON array of {plexPath, localPath, displayPath} rows, set on
 * the Settings page under "Folder Mapping". One row can serve one or both
 * purposes: plexPath -> localPath rewrites a path the way Plex reports it
 * into the path Plex Art Manager's own process actually sees on disk (only needed
 * when they differ, e.g. Plex reached through a different Docker bind mount
 * or network share mount point); localPath -> displayPath is purely cosmetic,
 * swapping the on-disk prefix for a friendlier label in the UI. A setup with
 * no path differences and no cosmetic renaming needs zero rows at all.
 */
function get_folder_mappings(): array
{
    $rows = json_decode(get_setting('folder_mappings_json', '[]') ?? '[]', true);
    return is_array($rows) ? $rows : [];
}

function map_path(string $filePath): string
{
    foreach (get_folder_mappings() as $row) {
        $plexPath = rtrim((string) ($row['plexPath'] ?? ''), '/');
        if ($plexPath === '') {
            continue;
        }
        $len = strlen($plexPath);
        if (str_starts_with($filePath, $plexPath)
            && (strlen($filePath) === $len || $filePath[$len] === '/')) {
            $localPath = rtrim((string) ($row['localPath'] ?? ''), '/');
            return $localPath . substr($filePath, $len);
        }
    }
    return $filePath;
}

/**
 * Swaps a matching localPath prefix for its displayPath label, e.g. localPath
 * "/plex-movies1" with displayPath "Feature Films" turns
 * "/plex-movies1/1995-1999/10 Things I Hate About You (1999)" into
 * "Feature Films/1995-1999/10 Things I Hate About You (1999)". An empty
 * displayPath just strips the prefix instead (the old base_path behavior).
 * Trailing slashes on either side don't matter. Falls back to the full path
 * if no row's localPath matches the start of the path.
 */
function display_path(?string $fullPath): ?string
{
    if ($fullPath === null || $fullPath === '') {
        return $fullPath;
    }
    foreach (get_folder_mappings() as $row) {
        $base = rtrim((string) ($row['localPath'] ?? ''), '/');
        if ($base === '') {
            continue;
        }
        $len = strlen($base);
        if (str_starts_with($fullPath, $base)
            && (strlen($fullPath) === $len || $fullPath[$len] === '/')) {
            $display = rtrim((string) ($row['displayPath'] ?? ''), '/');
            $suffix = substr($fullPath, $len);
            if ($display === '') {
                return $suffix === '' ? '/' : $suffix;
            }
            return $display . $suffix;
        }
    }
    return $fullPath;
}


function find_existing_file(string $dir, string $filename): ?string
{
    $exact = rtrim($dir, '/') . '/' . $filename;
    if (is_file($exact)) {
        return $exact;
    }
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    foreach (['.jpg', '.jpeg', '.png', '.webp'] as $ext) {
        $candidate = rtrim($dir, '/') . '/' . $stem . $ext;
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * List image files that currently live in a movie's folder, for the detail view.
 * Skips the temp/download-in-progress prefix used below.
 */
function list_folder_images(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $exts = ['jpg', 'jpeg', 'png', 'webp'];
    $out = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '_tmp_')) {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $out[] = $entry;
        }
    }
    sort($out);
    return $out;
}

/**
 * Poster-specific version of find_existing_file() that also recognizes Plex's
 * OTHER documented poster naming convention: a poster named after the movie's
 * own video file rather than a fixed "poster.ext" name. Confirmed against
 * Plex's current official docs (support.plex.tv "Local Media Assets - Movies"):
 *   - Single poster: "{video basename}.ext" (no suffix) is valid.
 *   - Multiple posters: "{video basename}-1.ext", "-2.ext", ... numbered from 1.
 * This is read-only recognition - it never renames or touches these files,
 * it just stops the app from treating an already-Plex-standard-named poster
 * as missing and downloading a duplicate "poster.jpg" alongside it.
 * $videoBasename should come from PlexClient::itemVideoBasename().
 */
function find_existing_poster(string $dir, string $filename, ?string $videoBasename): ?string
{
    $existing = find_existing_file($dir, $filename);
    if ($existing !== null || $videoBasename === null || $videoBasename === '') {
        return $existing;
    }

    $exts = ['.jpg', '.jpeg', '.png', '.webp', '.tbn'];

    // Bare "{video basename}.ext" - valid for a single poster.
    foreach ($exts as $ext) {
        $candidate = rtrim($dir, '/') . '/' . $videoBasename . $ext;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    // Numbered "{video basename}-1.ext", "-2.ext", ... - Plex's convention
    // for multiple posters. We only need to know *a* poster exists, so stop
    // at the first match; capped at 9 as a sane upper bound.
    for ($n = 1; $n <= 9; $n++) {
        foreach ($exts as $ext) {
            $candidate = rtrim($dir, '/') . "/{$videoBasename}-{$n}{$ext}";
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * SQL expression computing one asset type's "effective status" for a movie:
 * an unresolved pending_review entry always shows as 'failed' (matches the
 * existing UI priority), otherwise it's the most recent asset_history status
 * for that type, or 'none' if it's never been processed at all. $assetType
 * must only ever come from array_keys(ASSET_FILENAMES) - a fixed whitelist,
 * never raw user input - since it's interpolated directly into the SQL.
 */
function effective_status_sql(string $assetType): string
{
    return "
        CASE
            WHEN EXISTS (
                SELECT 1 FROM ignore_list il
                WHERE il.rating_key = m.rating_key AND il.asset_type = '{$assetType}'
            ) THEN 'ignored'
            WHEN EXISTS (
                SELECT 1 FROM pending_review pr
                WHERE pr.rating_key = m.rating_key AND pr.asset_type = '{$assetType}' AND pr.resolved = 0
            ) THEN 'failed'
            ELSE COALESCE(
                (SELECT ah.status FROM asset_history ah
                    WHERE ah.rating_key = m.rating_key AND ah.asset_type = '{$assetType}'
                    ORDER BY ah.changed_at DESC, ah.id DESC LIMIT 1),
                'none'
            )
        END
    ";
}

/** Statuses effective_status_sql() can ever produce - used to validate filter input. */
const EFFECTIVE_STATUSES = ['new', 'updated', 'unchanged', 'kept_existing', 'failed', 'ignored', 'none'];

/**
 * Download $sourceUrl (already token-authenticated) to $dir/$filename, backing
 * up an existing, different file with today's date first. Mirrors
 * save_posters.py's save_with_backup() exactly, including its return values.
 *
 * Returns one of: 'new', 'updated', 'unchanged', 'failed'.
 * On failure, $errorOut is populated with a short reason.
 */
function save_with_backup(string $sourceUrl, string $dir, string $filename, ?string &$errorOut = null): string
{
    if (!is_dir($dir)) {
        // Deliberately NOT auto-creating this folder. If the real cause is a
        // mount-visibility mismatch (the web app's process can't see a path
        // your user session can, e.g. a network volume mounted per-user on
        // macOS), silently mkdir()'ing here would create a brand new,
        // disconnected local folder instead of surfacing the real problem —
        // and then quietly "succeed" while writing to the wrong place
        // entirely. Use the Diagnostics page to confirm folder access.
        $errorOut = "Folder does not exist or is not visible to Plex Art Manager: {$dir}";
        return 'failed';
    }
    if (!is_writable($dir)) {
        $errorOut = "Folder exists but is not writable by Plex Art Manager's process: {$dir}";
        return 'failed';
    }

    $existingFile = rtrim($dir, '/') . '/' . $filename;
    $tempFile = rtrim($dir, '/') . '/_tmp_' . $filename;

    if (!download_url_to_file($sourceUrl, $tempFile, $errorOut)) {
        @unlink($tempFile);
        return 'failed';
    }

    if (is_file($existingFile)) {
        if (md5_file($existingFile) === md5_file($tempFile)) {
            @unlink($tempFile);
            return 'unchanged';
        }

        $today = date('Y-m-d');
        $stem = pathinfo($existingFile, PATHINFO_FILENAME);
        $suffix = pathinfo($existingFile, PATHINFO_EXTENSION);
        $backupPath = "{$dir}/{$stem}_{$today}.{$suffix}";
        $counter = 1;
        while (is_file($backupPath)) {
            $backupPath = "{$dir}/{$stem}_{$today}_{$counter}.{$suffix}";
            $counter++;
        }

        if (!rename($existingFile, $backupPath)) {
            $errorOut = 'Could not rename existing file for backup (permissions?)';
            @unlink($tempFile);
            return 'failed';
        }
        rename($tempFile, $existingFile);
        return 'updated';
    }

    rename($tempFile, $existingFile);
    return 'new';
}

/**
 * Stream-download a URL to a local file using cURL. Kept separate from
 * save_with_backup so upload_override.php can skip straight to the compare
 * step with an already-local file.
 */
function download_url_to_file(string $url, string $destPath, ?string &$errorOut = null): bool
{
    // Opening a file on a CIFS-mounted network share can fail transiently
    // (a brief SMB hiccup) even moments after Diagnostics confirmed the
    // folder writable. Retry a few times with a short pause before treating
    // it as a real failure - this is local and cheap, unlike retrying a full
    // download, so a few attempts cost nothing if the folder genuinely isn't
    // writable (they'll all fail fast) but rescue the common transient case.
    $fp = false;
    $attempts = 3;
    for ($i = 1; $i <= $attempts; $i++) {
        $fp = @fopen($destPath, 'w');
        if ($fp !== false) {
            break;
        }
        if ($i < $attempts) {
            usleep(300000); // 300ms
        }
    }
    if ($fp === false) {
        $errorOut = "Could not open $destPath for writing (tried {$attempts} times - if the folder is otherwise writable, this was likely a transient network-share hiccup)";
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'plex-art-manager/1.0',
    ]);
    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode >= 400) {
        $errorOut = $curlErr ?: "HTTP $httpCode";
        return false;
    }
    if (filesize($destPath) === 0) {
        $errorOut = 'Downloaded file was empty';
        return false;
    }
    return true;
}

/** Record one asset save attempt in asset_history. */
function log_asset_history(int $ratingKey, string $assetType, string $status, string $source, ?string $filename = null, ?string $note = null): void
{
    $stmt = get_db()->prepare('
        INSERT INTO asset_history (rating_key, asset_type, status, source, filename, note, changed_at)
        VALUES (:rk, :at, :st, :src, :fn, :note, :ts)
    ');
    $stmt->execute([
        'rk' => $ratingKey, 'at' => $assetType, 'st' => $status, 'src' => $source,
        'fn' => $filename, 'note' => $note, 'ts' => now_iso(),
    ]);
}

function add_pending_review(int $ratingKey, string $assetType, string $reason): void
{
    $db = get_db();
    // Don't duplicate an already-open entry for the same movie/asset.
    $check = $db->prepare('SELECT id FROM pending_review WHERE rating_key = :rk AND asset_type = :at AND resolved = 0');
    $check->execute(['rk' => $ratingKey, 'at' => $assetType]);
    if ($check->fetchColumn()) {
        return;
    }
    $stmt = $db->prepare('
        INSERT INTO pending_review (rating_key, asset_type, reason, created_at, resolved)
        VALUES (:rk, :at, :reason, :ts, 0)
    ');
    $stmt->execute(['rk' => $ratingKey, 'at' => $assetType, 'reason' => $reason, 'ts' => now_iso()]);
}

function resolve_pending_review(int $ratingKey, string $assetType): void
{
    $stmt = get_db()->prepare('
        UPDATE pending_review SET resolved = 1, resolved_at = :ts
        WHERE rating_key = :rk AND asset_type = :at AND resolved = 0
    ');
    $stmt->execute(['rk' => $ratingKey, 'at' => $assetType, 'ts' => now_iso()]);
}

function is_ignored(int $ratingKey, string $assetType): bool
{
    $stmt = get_db()->prepare('SELECT 1 FROM ignore_list WHERE rating_key = :rk AND asset_type = :at');
    $stmt->execute(['rk' => $ratingKey, 'at' => $assetType]);
    return (bool) $stmt->fetchColumn();
}
