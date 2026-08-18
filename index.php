<?php
require_once __DIR__ . '/includes/config.php';
// Just bootstraps the DB/schema on first load; the page itself is a static shell — all data comes via AJAX.
get_db();
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
        <nav>
            <div class="nav-item" data-route="#/dashboard" onclick="location.hash='#/dashboard'"><span class="icon">&#9673;</span> Dashboard</div>
            <div class="nav-item" data-route="#/batch" onclick="location.hash='#/batch'"><span class="icon">&#9658;</span> Batch Process</div>
            <div class="nav-item" data-route="#/movies" onclick="location.hash='#/movies'"><span class="icon">&#127916;</span> Movies</div>
            <div class="nav-item" data-route="#/review" onclick="location.hash='#/review'"><span class="icon">&#9888;</span> Needs Review</div>
            <div class="nav-item" data-route="#/diagnostics" onclick="location.hash='#/diagnostics'"><span class="icon">&#128269;</span> Diagnostics</div>
            <div class="nav-item" data-route="#/settings" onclick="location.hash='#/settings'"><span class="icon">&#9881;</span> Settings</div>
            <div class="nav-item" data-route="#/help" onclick="location.hash='#/help'"><span class="icon">&#10067;</span> Help</div>
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
