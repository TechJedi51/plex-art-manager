<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$ratingKey = (int) ($_GET['ratingKey'] ?? 0);
if (!$ratingKey) {
    json_error('ratingKey is required');
}

$notes = [];

try {
    $plex = new PlexClient();
} catch (Throwable $e) {
    json_error($e->getMessage(), 502);
}

try {
    $item = $plex->getItemFull($ratingKey);
} catch (Throwable $e) {
    json_error("Plex lookup failed: {$e->getMessage()}", 502);
}

$rawFile = $item['Media'][0]['Part'][0]['file'] ?? null;
$mappedFolder = $plex->itemFolderPath($item); // dirname() + map_path() already applied

$plexInfo = [
    'title'          => $item['title'] ?? null,
    'ratingKey'      => $ratingKey,
    'rawFilePath'    => $rawFile,
    'resolvedFolder' => $mappedFolder,
    'tmdbId'         => $plex->itemTmdbId($item),
    'imdbId'         => $plex->itemImdbId($item),
    'imageUrls'      => [],
];
foreach (array_keys(ASSET_FILENAMES) as $type) {
    $plexInfo['imageUrls'][$type] = $plex->itemAssetUrl($item, $type);
}

if ($rawFile && $mappedFolder !== dirname($rawFile)) {
    $notes[] = 'A Folder Mapping row (Settings page) is rewriting this path (raw Plex path differs from the resolved folder above). Confirm that mapping is still correct.';
}

// --- Filesystem side ---
$fs = [
    'folder'          => $mappedFolder,
    'folderExists'    => $mappedFolder ? is_dir($mappedFolder) : false,
    'folderReadable'  => $mappedFolder ? is_readable($mappedFolder) : false,
    'folderWritable'  => $mappedFolder ? is_writable($mappedFolder) : false,
    'assets'          => [],
    'otherFiles'      => [],
];

if ($mappedFolder && $fs['folderExists']) {
    $videoBasename = $plex->itemVideoBasename($item);
    foreach (ASSET_FILENAMES as $type => $filename) {
        $existing = $type === 'poster'
            ? find_existing_poster($mappedFolder, $filename, $videoBasename)
            : find_existing_file($mappedFolder, $filename);
        $fs['assets'][$type] = $existing ? [
            'expectedFilename' => $filename,
            'foundFilename'    => basename($existing),
            'exists'           => true,
            'sizeBytes'        => filesize($existing),
            'modifiedAt'       => date('c', filemtime($existing)),
        ] : [
            'expectedFilename' => $filename,
            'exists'           => false,
        ];
    }
    $fs['otherFiles'] = list_folder_images($mappedFolder);
} else {
    foreach (ASSET_FILENAMES as $type => $filename) {
        $fs['assets'][$type] = ['expectedFilename' => $filename, 'exists' => false];
    }
}

// --- What user is this PHP process actually running as? ---
$processUser = [
    'scriptOwner' => function_exists('get_current_user') ? get_current_user() : null,
    'processUser' => null,
];
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw = posix_getpwuid(posix_geteuid());
    $processUser['processUser'] = $pw['name'] ?? null;
}

// --- Diagnostic notes ---
if ($mappedFolder === null) {
    $notes[] = "Plex didn't report a file path for this item at all — it may be missing its Media/Part info.";
} elseif (!$fs['folderExists']) {
    $notes[] = "Plex Art Manager cannot see \"{$mappedFolder}\" on disk, even though Plex reports the movie living there. "
        . 'On macOS this is commonly a mount-visibility issue: network/user-mounted volumes under /Volumes are often only '
        . 'visible to the logged-in GUI user session that mounted them, not to a background daemon like this is running as '
        . "a different user (shown below as \"processUser\"). If the folder genuinely doesn't exist, this app will never be "
        . 'able to save artwork for this movie.';
} elseif (!$fs['folderWritable']) {
    $notes[] = "Plex Art Manager can see \"{$mappedFolder}\" but cannot write to it — check folder ownership/permissions for the processUser shown below.";
} else {
    $missing = array_filter($fs['assets'], fn($a) => !$a['exists']);
    if (empty($missing)) {
        $notes[] = 'Folder is visible and writable, and all four asset types already have a file on disk. A Dry Run for this movie should report "Would Update/Match" for each, not "Would Create".';
    } else {
        $notes[] = 'Folder is visible and writable. Missing on disk: ' . implode(', ', array_map(fn($t) => ASSET_LABELS[$t], array_keys($missing))) . '.';
    }
}

json_out([
    'plex'        => $plexInfo,
    'filesystem'  => $fs,
    'processUser' => $processUser,
    'notes'       => $notes,
]);
