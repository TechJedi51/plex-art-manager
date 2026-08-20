<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection to the app's SQLite database, creating the
 * schema on first run. SQLite is plenty for this workload (single-writer,
 * modest read volume) and needs zero separate database server to host.
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    // The job worker (cli/job_worker.php) writes progress every chunk while
    // web requests may also write (cancel, settings, starting a new job) -
    // without a busy timeout, SQLite's default is to fail a write immediately
    // ("database is locked") instead of waiting out a brief writer conflict.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate_db($pdo);

    return $pdo;
}

function migrate_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS movies (
            rating_key         INTEGER PRIMARY KEY,
            library_section_id INTEGER,
            title              TEXT NOT NULL,
            year               INTEGER,
            folder_path        TEXT NOT NULL,
            tmdb_id            INTEGER,
            imdb_id            TEXT,
            added_at           TEXT,
            last_synced_at     TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_movies_title ON movies(title)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_movies_section ON movies(library_section_id)");

    // Every save attempt (success or failure) gets a row here. The "last changed"
    // date shown in the UI is the most recent row per (rating_key, asset_type)
    // whose status is 'new' or 'updated'.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_history (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            rating_key  INTEGER NOT NULL,
            asset_type  TEXT NOT NULL,   -- poster | art | square | logo
            status      TEXT NOT NULL,   -- new | updated | unchanged | failed | kept_existing
            source      TEXT NOT NULL,   -- plex | fanart | tmdb | manual
            filename    TEXT,
            note        TEXT,
            changed_at  TEXT NOT NULL,
            FOREIGN KEY (rating_key) REFERENCES movies(rating_key) ON DELETE CASCADE
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_movie ON asset_history(rating_key, asset_type)");

    // Anything that came back 'failed' from a batch run (and isn't ignored) lands
    // here so the Needs Review screen has a durable worklist independent of any
    // one run's console output.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_review (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            rating_key  INTEGER NOT NULL,
            asset_type  TEXT NOT NULL,
            reason      TEXT,
            created_at  TEXT NOT NULL,
            resolved    INTEGER NOT NULL DEFAULT 0,
            resolved_at TEXT,
            FOREIGN KEY (rating_key) REFERENCES movies(rating_key) ON DELETE CASCADE
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending_unresolved ON pending_review(resolved, rating_key, asset_type)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ignore_list (
            rating_key INTEGER NOT NULL,
            asset_type TEXT NOT NULL,
            note       TEXT,
            created_at TEXT NOT NULL,
            PRIMARY KEY (rating_key, asset_type)
        )
    ");

    // A "job" is one Sync Library or Batch Process run, processed chunk-by-chunk
    // by cli/job_worker.php (a persistent background process, not tied to any
    // HTTP request) so it survives the browser tab closing/reloading. Only one
    // row may be queued/running at a time — enforced in includes/jobs.php's
    // create_job(), not here. cursor/counts_json/recent_items_json are updated
    // after every chunk so a killed worker can resume from the last persisted
    // point (see cli/job_worker.php's startup recovery).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jobs (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            type              TEXT NOT NULL,                 -- sync | batch
            status            TEXT NOT NULL DEFAULT 'queued', -- queued | running | done | failed | cancelled
            section_id        INTEGER NOT NULL,
            section_title     TEXT,
            asset_types_json  TEXT,                            -- batch only; JSON array, NULL for sync
            dry_run           INTEGER NOT NULL DEFAULT 0,
            start_pos         INTEGER NOT NULL DEFAULT 0,
            stop_pos          INTEGER,                          -- NULL = run to end of library
            cursor            INTEGER NOT NULL DEFAULT 0,
            total_size        INTEGER,
            chunk_size        INTEGER NOT NULL DEFAULT 15,
            counts_json       TEXT NOT NULL DEFAULT '{}',
            recent_items_json TEXT NOT NULL DEFAULT '[]',
            cancel_requested  INTEGER NOT NULL DEFAULT 0,
            error             TEXT,
            created_at        TEXT NOT NULL,
            started_at        TEXT,
            finished_at       TEXT,
            updated_at        TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");

    // Narrative log lines for the Log View screen - distinct from jobs' own
    // structured progress columns above. debug-level lines are only written
    // when the debug_mode setting is on (see includes/logs.php); info/warn/error
    // are always written so a job's outcome is never silently lost.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id     INTEGER,
            level      TEXT NOT NULL, -- debug | info | warn | error
            message    TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_created ON logs(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level)");

    // INSERT OR IGNORE is safe to run every time — it only fills in keys that don't
    // exist yet, so upgrading the app (e.g. adding base_path) never touches an
    // existing install's saved values.
    seed_default_settings($pdo);
    migrate_folder_mappings($pdo);
}

/**
 * One-time migration: mapped_folders_json + base_path were combined into a
 * single folder_mappings_json setting (see includes/helpers.php). Runs
 * exactly once per install, guarded by the folder_mappings_migrated flag
 * rather than by checking whether folder_mappings_json is empty - otherwise
 * a user who deliberately clears all their folder mappings back to [] would
 * have them silently reappear from the old settings on the next page load.
 * The old mapped_folders_json/base_path rows are left in place afterward
 * (unused, harmless) rather than deleted.
 */
function migrate_folder_mappings(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->execute(['k' => 'folder_mappings_migrated']);
    if ($stmt->fetchColumn() !== false) {
        return;
    }

    $get = function (string $key) use ($pdo): ?string {
        $s = $pdo->prepare('SELECT value FROM settings WHERE key = :k');
        $s->execute(['k' => $key]);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    };

    $map = json_decode($get('mapped_folders_json') ?? '{}', true);
    $map = is_array($map) ? $map : [];

    $baseRaw = $get('base_path') ?? '';
    $baseDecoded = json_decode($baseRaw, true);
    $bases = (is_array($baseDecoded) && $baseDecoded !== []) ? array_values($baseDecoded) : ($baseRaw !== '' ? [$baseRaw] : []);

    $rows = [];
    foreach ($map as $plexPath => $localPath) {
        $rows[] = ['plexPath' => (string) $plexPath, 'localPath' => (string) $localPath, 'displayPath' => ''];
    }
    // Leftover base_path entries beyond what mapped_folders_json already
    // covered become strip-only rows keyed to themselves - best effort, since
    // the old two settings were never necessarily paired 1:1.
    foreach (array_slice($bases, count($map)) as $extra) {
        $rows[] = ['plexPath' => $extra, 'localPath' => $extra, 'displayPath' => ''];
    }

    if ($rows) {
        $upsert = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('folder_mappings_json', :v) ON CONFLICT(key) DO UPDATE SET value = :v");
        $upsert->execute(['v' => json_encode($rows)]);
    }

    $mark = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
    $mark->execute(['k' => 'folder_mappings_migrated', 'v' => '1']);
}

function seed_default_settings(PDO $pdo): void
{
    $defaults = [
        'plex_url'            => '',
        'plex_token'          => '',
        'fanart_api_key'      => '',
        'tmdb_api_key'        => '',
        'thumb_max_width'     => '100',
        'batch_default_size'  => '25',
        'mapped_folders_json'  => '{}', // superseded by folder_mappings_json (see migrate_folder_mappings()) - kept only so the one-time migration has something to read
        'base_path'            => '',   // superseded by folder_mappings_json - same reason
        'folder_mappings_json' => '[]', // [{plexPath, localPath, displayPath}, ...] - see includes/helpers.php
        'debug_mode'           => '0',  // '1' enables debug-level entries on the Log View screen
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
    foreach ($defaults as $k => $v) {
        $stmt->execute(['k' => $k, 'v' => $v]);
    }
}
