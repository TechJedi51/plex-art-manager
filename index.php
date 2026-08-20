<?php
require_once __DIR__ . '/includes/config.php';
// Just bootstraps the DB/schema on first load; the page itself is a static shell — all data comes via AJAX.
// A blank page on load almost always means a fatal error happened here, before
// any HTML was sent - display_errors is off in production (see config.php), so
// without this the browser gets nothing and the cause only exists in `docker
// logs` as an uncaught-exception line. Catch it and show something instead.
try {
    get_db();
} catch (Throwable $e) {
    error_log('Bootstrap failure: ' . $e);
    http_response_code(500);
    ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Plex Art Manager - Error</title></head>
<body style="font-family: sans-serif; max-width: 640px; margin: 4rem auto; line-height: 1.5;">
<h1>Plex Art Manager failed to start</h1>
<p>The app hit an error while setting up its database. Full details were written to the container's log - run:</p>
<pre style="background:#eee; padding:1rem; overflow:auto;">docker logs plex-art-manager --tail 50</pre>
<p>Common causes: <code>data/</code> isn't writable by the container (PUID/PGID mismatch), or the SQLite file is corrupted.</p>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plex Art Manager</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div id="shell">
    <div id="sidebar">
        <div class="brand">
            Plex Art<span>Manager</span>
            <div class="brand-version">v<?= htmlspecialchars(APP_VERSION) ?></div>
        </div>
        <nav class="nav-top">
            <div class="nav-item" data-route="#/dashboard" onclick="location.hash='#/dashboard'"><span class="icon-mask icon-mask-dashboard"></span> Dashboard</div>
            <div class="nav-item" data-route="#/batch" onclick="location.hash='#/batch'"><span class="icon-mask icon-mask-batch"></span> Batch Process</div>
            <div class="nav-item" data-route="#/movies" onclick="location.hash='#/movies'"><span class="icon-mask icon-mask-movies"></span> Movies</div>
            <div class="nav-item" data-route="#/review" onclick="location.hash='#/review'"><span class="icon-mask icon-mask-review"></span> Needs Review</div>
            <div class="nav-item" data-route="#/diagnostics" onclick="location.hash='#/diagnostics'"><span class="icon-mask icon-mask-diagnostics"></span> Diagnostics</div>
        </nav>
        <nav class="nav-bottom">
            <div class="nav-item" data-route="#/logs" onclick="location.hash='#/logs'"><span class="icon-mask icon-mask-logs"></span> Logs</div>
            <div class="nav-item" data-route="#/settings" onclick="location.hash='#/settings'"><span class="icon-mask icon-mask-settings"></span> Settings</div>
            <div class="nav-item" data-route="#/help" onclick="location.hash='#/help'"><span class="icon-mask icon-mask-help"></span> Help</div>
        </nav>
    </div>
    <div id="main">
        <div id="toolbar"></div>
        <div id="content"></div>
    </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
