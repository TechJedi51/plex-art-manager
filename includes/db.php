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

    // INSERT OR IGNORE is safe to run every time — it only fills in keys that don't
    // exist yet, so upgrading the app (e.g. adding base_path) never touches an
    // existing install's saved values.
    seed_default_settings($pdo);
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
        'mapped_folders_json' => '{}', // {"host/path": "container/path"} — same idea as the old script
        'base_path'           => '',   // stripped from the front of displayed paths, e.g. "/Volumes/Plex Media/Feature Films"
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
    foreach ($defaults as $k => $v) {
        $stmt->execute(['k' => $k, 'v' => $v]);
    }
}
