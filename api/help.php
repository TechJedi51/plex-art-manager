<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

// Static documentation content, returned as JSON and rendered/searched
// client-side. Keep this updated whenever a feature changes - it's meant to
// track the app, not describe some earlier version of it.
$sections = [
    [
        'id' => 'overview',
        'title' => 'Overview',
        'body' => '
            <p>Plex Art Manager audits and fills in movie artwork - Poster, Background, Square Art, and Logo -
            across a Plex library, using Plex\'s own API as the source of truth for what exists, and your
            movie folders on disk as the destination. It mirrors the naming convention the original
            <code>save_posters.py</code> script used: <code>poster.jpg</code>, <code>background.jpg</code>,
            <code>square.jpg</code>, <code>logo.png</code> saved directly alongside each movie file, with
            automatic dated backups whenever an existing file is about to be replaced.</p>
            <p>Typical workflow: <strong>Sync Library</strong> (Movies page) to pull titles/paths in, then
            <strong>Batch Process</strong> to actually check/save artwork, then use <strong>Needs Review</strong>
            and <strong>Diagnostics</strong> to chase down anything that didn\'t resolve cleanly.</p>',
    ],
    [
        'id' => 'dashboard',
        'title' => 'Dashboard',
        'body' => '
            <p>A snapshot: how many movies are tracked, how many asset problems are outstanding (overall and
            broken down by Poster/Background/Square Art/Logo), and a feed of the most recent successful
            changes. Every stat tile is a link - <strong>Movies Tracked</strong> jumps to the Movies list,
            the <strong>Needs Review</strong> tile and each per-type "Missing" tile jump to Needs Review,
            pre-filtered to that asset type where applicable.</p>
            <p>If a Sync or Batch job is currently running in the background, a status card appears above the
            stats with a live progress bar and a link to the screen that has the full detail and Stop button -
            it disappears once nothing is running. <strong>Recent Changes</strong> is pulled straight from the
            database, so it reflects every Batch run\'s results (including ones finished in the background)
            and survives page reloads and even closing the browser entirely.</p>
            <p>If Plex isn\'t configured yet (Settings page), this page shows a prompt instead of stats.</p>',
    ],
    [
        'id' => 'batch',
        'title' => 'Batch Process',
        'body' => '
            <p>Batch runs as a background job, not a page you have to keep open - once started, it keeps going
            even if you close the tab or reload the page. Reopening this screen (or the Dashboard) while a job
            is running reattaches to it automatically and shows live progress; only one Sync or Batch job can be
            active at a time, so starting one while the other is running is blocked with a clear message until
            it finishes.</p>
            <p><strong>Library</strong> - which Plex movie section to process.</p>
            <p><strong>Asset Types</strong> - Poster and Background read directly off each Plex item (fast).
            Square Art and Logo require one extra Plex API call per movie the first time they\'re needed, so
            enabling them makes a batch noticeably slower - consider running them as a separate, smaller batch.</p>
            <p><strong>Movies to Process</strong> - choose <strong>Custom amount</strong> (a total count starting
            from Start, or an explicit Start/Stop range) or <strong>All movies in this library</strong>, which
            shows the current library size and processes every movie in it. The actual count is re-checked
            against Plex the moment the job starts, so it\'s always accurate even if the library changed since
            you loaded the page. Either way, work happens in small fixed-size chunks behind the scenes, so
            progress updates stay frequent even on a large run.</p>
            <p><strong>Dry Run</strong> - checks what would happen without downloading or writing anything.
            Because it never downloads the candidate image, it can\'t always tell "would replace with something
            different" apart from "would replace with the exact same file" - both show as
            <em>Would Update/Match</em>. Turn Dry Run off to get a definitive answer.</p>
            <p>After a run, the Summary shows totals plus a list of the most recent Changed and Failed items
            (capped to the latest 50 of each on a very large run) - see the <strong>Logs</strong> page for a
            complete, filterable history instead.</p>',
    ],
    [
        'id' => 'movies',
        'title' => 'Movies',
        'body' => '
            <p>A searchable, sortable, filterable cache of every movie that\'s been through a Sync or a Batch
            run (movies never touched by either won\'t appear here yet).</p>
            <p><strong>Search</strong> matches on title. <strong>Sort</strong> covers Title, Year, and Plex ID,
            each with an ascending/descending toggle; the active column shows a small arrow indicating
            direction. <strong>Filters</strong> let you narrow the list by each asset type\'s current status
            independently (e.g. show only movies where Logo = Failed, or where Poster = Ignored) - multiple
            filters combine together.</p>
            <p>Each asset column is headed by a small icon (Poster/Background/Square Art/Logo - hover any of
            them for a label) and shows one status symbol per movie - see the legend below for what each one
            means. Clicking a row opens that movie\'s detail page.</p>
            <p><strong>Sync Library</strong> pulls title/path/TMDB id from Plex for the whole library (fast, no
            image downloads) - useful before your first Batch run, or any time titles/paths may have changed in
            Plex. Like Batch, it runs as a background job that survives closing the tab, and only one Sync or
            Batch job can be active at a time.</p>',
    ],
    [
        'id' => 'legend',
        'title' => 'Status Symbols',
        'body' => '
            <p>🆕 <strong>New</strong> - downloaded and saved for the first time.</p>
            <p>🔄 <strong>Updated</strong> - an existing file was replaced (the old one is automatically backed
            up with today\'s date before being overwritten).</p>
            <p>🟡 <strong>Unchanged</strong> - a file already exists and matches what Plex currently has, or was
            just checked and confirmed correct.</p>
            <p>🟠 <strong>Kept Existing</strong> - Plex had no image to offer at all for this asset, but a local
            file was already there, so it was left alone rather than reported as a failure. The difference from
            Unchanged: Unchanged means Plex and the local file agree; Kept Existing means Plex had nothing to
            compare against, so the local file survived by default rather than by confirmation.</p>
            <p>⛔️ <strong>Failed</strong> - needs attention. Shows up on the Needs Review page with a reason.</p>
            <p>🔕 <strong>Ignored</strong> - manually excluded from Needs Review for this movie/asset type. Stays
            this way until un-ignored, regardless of Sync or future Batch runs - see the Ignored tab on Needs
            Review.</p>
            <p>➖ <strong>Not Checked</strong> - this movie has never been processed for this asset type yet.</p>',
    ],
    [
        'id' => 'review',
        'title' => 'Needs Review',
        'body' => '
            <p>Every asset that came back Failed from a run, and hasn\'t been resolved since. Search, sort
            (Movie, Plex ID, or Since), and filter by asset type work the same way as the Movies page.</p>
            <p><strong>Find Candidates</strong> queries Fanart.tv and/or TMDB (whichever API keys are set in
            Settings) for alternative images and lets you pick one to save directly. Note neither service has a
            dedicated "square art" category for movies, so Square Art candidates will usually come back empty -
            see the Help section on Square Art below. For Logo candidates specifically, a Light/Dark Background
            toggle in the picker lets you preview against both, since logos are often transparent and can be
            hard to judge against the wrong background.</p>
            <p><strong>Ignore</strong> is offered directly inside the candidate picker when no candidates are
            found - the idea being you check what\'s available before deciding to ignore, rather than ignoring
            blind from the list. It removes an item from this list without saving anything - useful for a
            movie that genuinely has no artwork available anywhere and you don\'t want it cluttering this list
            on every future batch run. Ignoring is permanent and independent of Sync - it\'s stored separately
            and checked on every batch run, so it isn\'t reset by syncing or by any future run finding the same
            problem again. Switch to the <strong>Ignored</strong> tab at the top of this page to see everything
            currently ignored, with the same search/sort/filter tools, and an Un-ignore button to reverse it.</p>',
    ],
    [
        'id' => 'square-art',
        'title' => 'About Square Art',
        'body' => '
            <p>Square Art is used on movie/show detail screens in the Plex iOS and Android apps. Unlike
            Poster/Background/Logo, no metadata agent (not TMDB, not Plex\'s own agents) supplies it
            automatically - there is no default online source. It\'s always either uploaded manually through
            the Plex Web App, or produced by a third-party tool (e.g. Poster Tools\' "Square Lab") that crops an
            existing backdrop into a square and pushes it directly to your Plex server.</p>
            <p>If a movie already has square art registered on the Plex item - however it got there - Plex Art
            Manager will find and save it exactly like any other asset type. What it can\'t currently do is suggest a
            square-art candidate for a movie that has none, since Fanart.tv and TMDB don\'t offer that category.</p>',
    ],
    [
        'id' => 'diagnostics',
        'title' => 'Diagnostics',
        'body' => '
            <p><em>Note: "Plex ID" throughout Plex Art Manager refers to what Plex\'s own API calls the "Rating Key" -
            the same number, just a plainer name. If you\'re ever cross-referencing Plex\'s own documentation,
            API responses, or XML view, "ratingKey" is the field to look for.</em></p>
            <p>Look up one movie by Plex ID (find it via the Movies list URL, <code>#/movies/&lt;ratingKey&gt;</code>,
            or the shortcut button on a movie\'s detail page) and see, side by side: exactly what Plex reports
            for that item, exactly what Plex Art Manager\'s PHP process can see on disk, and what user that process is
            actually running as.</p>
            <p>This is the right first stop any time something looks wrong that shouldn\'t be - a folder
            reported as inaccessible when you know it exists usually points at a permissions or network-mount
            visibility mismatch between the web server\'s user and whatever actually has access to your media,
            not a bug in Plex Art Manager\'s logic.</p>',
    ],
    [
        'id' => 'logs',
        'title' => 'Logs',
        'body' => '
            <p>Three tabs, each filterable, paginated, and independently exportable as CSV (the button always
            downloads whatever the current filters show):</p>
            <p><strong>Activity Log</strong> - Sync/Batch job starts, finishes, cancellations, and failures;
            every manual Ignore/Un-ignore, upload override, and candidate image applied from Needs Review or a
            movie\'s detail page; plus any real application error (Plex unreachable, a failed download/save, a
            locked database, etc.) - anything that reaches a 5xx response gets logged here automatically, not
            just uncaught crashes. Filter by level (Debug / Info / Warn / Error); each entry shows which job it
            belongs to, if any.</p>
            <p><strong>Debug Mode</strong> (Settings page, off by default) adds a lot more detail to the
            Activity Log - a line for every chunk a Sync or Batch job processes, not just the start/finish
            summary. Leave it off for normal use; turn it on temporarily if you need to see exactly what a job
            did step by step. Info, Warn, and Error entries are always recorded regardless of this setting, so
            a job\'s outcome is never lost even with Debug Mode off.</p>
            <p>If the database is briefly locked (e.g. a manual action racing a background job\'s writes) right
            when an activity-log line would be written, it\'s queued to disk instead of dropped, and appears
            automatically as soon as the database frees up - a banner on the Activity Log tab says so while
            anything is still queued.</p>
            <p><strong>Asset History</strong> - the full per-movie, per-asset save/skip/failure record from
            every Sync, Batch, manual upload, and candidate apply ever run - the same data shown (capped to the
            most recent 100) on each movie\'s detail page, but complete and searchable here. Filter by movie
            title, asset type, status, and source (Plex / Fanart.tv / TMDB / Manual).</p>
            <p><strong>Job History</strong> - every Sync/Batch run ever started, not just whichever one is
            currently active elsewhere in the app - status, library, asset types, dry-run flag, result counts,
            timing, and the error if it failed. Filter by type (Sync/Batch) and status.</p>',
    ],
    [
        'id' => 'cron',
        'title' => 'Cron / Unattended Sweeps',
        'body' => '
            <p><code>cli/process_batch_cli.php</code> runs a full-library sweep from the command line, reusing
            the exact same batch logic (<code>run_batch()</code>) as the web UI - it\'s meant to be driven by
            cron for unattended, scheduled runs rather than needing the browser open.</p>
            <pre><code>php cli/process_batch_cli.php --library="Movies" --types=poster,art,square,logo [--chunk=25] [--dry-run]</code></pre>
            <p><strong>--library</strong> - the exact Plex library section title (case-insensitive).
            <strong>--types</strong> - a comma-separated subset of <code>poster,art,square,logo</code>.
            <strong>--chunk</strong> - optional, items processed per internal page (default 25).
            <strong>--dry-run</strong> - optional, checks without downloading or writing anything.</p>
            <p>Example crontab entry - poster+background daily, square/logo weekly since they\'re slower (each
            requires one extra Plex API call per movie):</p>
            <pre><code>0 3 * * * php /path/to/plex-art-manager/cli/process_batch_cli.php --library="Movies" --types=poster,art >> /path/to/plex-art-manager/data/cron.log 2>&1
0 4 * * 0 php /path/to/plex-art-manager/cli/process_batch_cli.php --library="Movies" --types=square,logo >> /path/to/plex-art-manager/data/cron.log 2>&1</code></pre>
            <p>Inside the Docker image, run it via <code>docker compose exec plex-art-manager php cli/process_batch_cli.php ...</code>
            from the host\'s own cron instead of installing cron inside the container. Output (a summary of
            new/updated/unchanged/failed counts, plus a list of any failed titles) goes to stdout/stderr, so
            redirect it to a log file as shown above if you want a persistent record - each run also still
            writes its own asset-level results to <strong>Asset History</strong> and a start/finish line to
            <strong>Logs</strong> the same as a browser-driven Batch run does.</p>',
    ],
    [
        'id' => 'settings',
        'title' => 'Settings',
        'body' => '
            <p><strong>Plex URL / Plex Token</strong> - your Plex Media Server\'s base URL and an auth token.</p>
            <p><strong>Fanart.tv API Key / TMDB API Key</strong> - optional, only needed for the "Find
            Candidates" feature on Needs Review / a movie\'s detail page.</p>
            <p><strong>Thumbnail Max Width</strong> - images shown on a movie\'s detail page are capped to this
            width (server-side resize with on-disk caching when the GD extension is available; otherwise the
            original file is served as-is and just visually constrained by the browser).</p>
            <p><strong>Default Batch Size</strong> - the value pre-filled into Batch Process\'s Movies to Process field.</p>
            <p><strong>Folder Mapping</strong> - one row per independent mount root, with three fields:
            <strong>Path in Plex</strong> (the folder path exactly as Plex reports it), <strong>Local Path</strong>
            (what Plex Art Manager\'s own process actually sees on disk for that same folder - only different from Path
            in Plex if, say, Plex runs on a different machine or the two reach the media through different mount
            points), and <strong>Display Path</strong> (a friendly label shown in the UI in place of that
            folder\'s real path - purely cosmetic). If there\'s no real path difference, set Path in Plex and
            Local Path to the same value and use Display Path just for the friendlier label, e.g. Path in Plex
            <code>/Volumes/Plex Media/Feature Films</code>, Local Path <code>/plex-movies1</code>, Display Path
            <code>Feature Films</code> turns <code>/plex-movies1/1995-1999/Movie (1999)</code> into
            <code>Feature Films/1995-1999/Movie (1999)</code> in the UI. Leave Display Path blank on a row to
            just strip that folder\'s prefix instead of relabeling it. Use "+ Add Folder" for setups with more
            than one independent mount root; remove a row entirely if you don\'t need it.</p>
            <p><strong>Debug Mode</strong> - see the Logs page above.</p>',
    ],
];

json_out(['sections' => $sections]);
