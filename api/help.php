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
            <p>If Plex isn\'t configured yet (Settings page), this page shows a prompt instead of stats.</p>',
    ],
    [
        'id' => 'batch',
        'title' => 'Batch Process',
        'body' => '
            <p><strong>Library</strong> - which Plex movie section to process.</p>
            <p><strong>Asset Types</strong> - Poster and Background read directly off each Plex item (fast).
            Square Art and Logo require one extra Plex API call per movie the first time they\'re needed, so
            enabling them makes a batch noticeably slower - consider running them as a separate, smaller batch.</p>
            <p><strong>Movies to Process</strong> - total number of movies this run should process, starting
            from Start. Requests to the server are automatically split into small fixed-size chunks behind the
            scenes regardless of this number, so the progress bar and log update frequently even on a large
            run - you don\'t need to tune this for responsiveness, just set it to how many movies you actually
            want processed.</p>
            <p><strong>Start / Stop</strong> - which slice of the library to work through. Leave Stop blank to
            process exactly the number set above, starting at Start; set an explicit Stop to run a bigger range
            in one sitting (e.g. Start 0, Stop 500 works through the first 500 movies).</p>
            <p><strong>Dry Run</strong> - checks what would happen without downloading or writing anything.
            Because it never downloads the candidate image, it can\'t always tell "would replace with something
            different" apart from "would replace with the exact same file" - both show as
            <em>Would Update/Match</em>. Turn Dry Run off to get a definitive answer.</p>
            <p>After a run, the Summary shows totals plus a list of every Changed and Failed item, same as the
            original script\'s console output.</p>',
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
            means. Clicking a row opens that movie\'s detail page. <strong>Sync Library</strong> pulls
            title/path/TMDB id from Plex for the whole library (fast, no image downloads) - useful before your
            first Batch run, or any time titles/paths may have changed in Plex.</p>',
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
            <p>If a movie already has square art registered on the Plex item - however it got there - this app
            will find and save it exactly like any other asset type. What it can\'t currently do is suggest a
            square-art candidate for a movie that has none, since Fanart.tv and TMDB don\'t offer that category.</p>',
    ],
    [
        'id' => 'diagnostics',
        'title' => 'Diagnostics',
        'body' => '
            <p><em>Note: "Plex ID" throughout this app refers to what Plex\'s own API calls the "Rating Key" -
            the same number, just a plainer name. If you\'re ever cross-referencing Plex\'s own documentation,
            API responses, or XML view, "ratingKey" is the field to look for.</em></p>
            <p>Look up one movie by Plex ID (find it via the Movies list URL, <code>#/movies/&lt;ratingKey&gt;</code>,
            or the shortcut button on a movie\'s detail page) and see, side by side: exactly what Plex reports
            for that item, exactly what this app\'s PHP process can see on disk, and what user that process is
            actually running as.</p>
            <p>This is the right first stop any time something looks wrong that shouldn\'t be - a folder
            reported as inaccessible when you know it exists usually points at a permissions or network-mount
            visibility mismatch between the web server\'s user and whatever actually has access to your media,
            not a bug in this app\'s logic.</p>',
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
            <p><strong>Mapped Folders</strong> - only needed if this app sees a different filesystem path than
            Plex reports for the same movie (e.g. Plex running on a different Mac, media reached here via a
            CIFS/SMB mount at a different mount point). JSON object of <code>{"path Plex reports": "path this
            app actually sees"}</code>.</p>
            <p><strong>Base Path</strong> - purely cosmetic. Trims a matching prefix off every displayed path in
            the UI so you see e.g. <code>/1995-1999/Movie (1999)</code> instead of the full mounted path.</p>',
    ],
];

json_out(['sections' => $sections]);
