#!/usr/bin/env php
<?php
/**
 * Run a full-library sweep from the command line / cron, reusing the exact
 * same run_batch() logic the web UI uses per chunk.
 *
 * Usage:
 *   php cli/process_batch_cli.php --library="Feature Films" --types=poster,art,square,logo [--chunk=25] [--dry-run]
 *
 * Example crontab entry (runs at 3am daily):
 *   0 3 * * * /usr/bin/php /path/to/plex-art-manager/cli/process_batch_cli.php --library="Feature Films" --types=poster,art,square,logo >> /path/to/plex-art-manager/data/cron.log 2>&1
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/batch.php';

$opts = getopt('', ['library:', 'types:', 'chunk::', 'dry-run']);

if (empty($opts['library']) || empty($opts['types'])) {
    fwrite(STDERR, "Usage: php process_batch_cli.php --library=\"Feature Films\" --types=poster,art,square,logo [--chunk=25] [--dry-run]\n");
    exit(1);
}

$libraryName = $opts['library'];
$assetTypes = array_values(array_intersect(explode(',', $opts['types']), array_keys(ASSET_FILENAMES)));
$chunk = isset($opts['chunk']) ? max(1, (int) $opts['chunk']) : 25;
$dryRun = array_key_exists('dry-run', $opts);

if (!$assetTypes) {
    fwrite(STDERR, "No valid asset types given. Choose from: " . implode(', ', array_keys(ASSET_FILENAMES)) . "\n");
    exit(1);
}

try {
    $plex = new PlexClient();
} catch (Throwable $e) {
    fwrite(STDERR, "Config error: {$e->getMessage()}\n");
    exit(1);
}

$sections = $plex->getLibraries();
$sectionId = null;
foreach ($sections as $s) {
    if (strcasecmp($s['title'], $libraryName) === 0) {
        $sectionId = (int) $s['key'];
        break;
    }
}
if (!$sectionId) {
    fwrite(STDERR, "Library \"{$libraryName}\" not found on Plex.\n");
    exit(1);
}

echo "[" . date('c') . "] Starting sweep of \"{$libraryName}\" (section {$sectionId}), types: " . implode(',', $assetTypes) . ($dryRun ? ' [DRY RUN]' : '') . "\n";

$start = 0;
$totals = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'kept_existing' => 0];
$failedTitles = [];

do {
    $result = run_batch($plex, $sectionId, $start, $chunk, $assetTypes, $dryRun);

    foreach ($result['items'] as $item) {
        foreach ($item['assets'] as $assetType => $assetResult) {
            $status = $assetResult['status'] ?? 'failed';
            if (isset($totals[$status])) {
                $totals[$status]++;
            }
            if ($status === 'failed') {
                $failedTitles[] = "{$item['title']} [{$assetType}]: " . ($assetResult['error'] ?? 'unknown');
            }
        }
    }

    echo "  processed {$result['start']}-{$result['nextStart']} of {$result['totalSize']}\n";
    $start = $result['nextStart'];
} while (!$result['done']);

echo "\n" . str_repeat('=', 60) . "\n";
echo "SUMMARY\n";
foreach ($totals as $status => $count) {
    echo "  {$status}: {$count}\n";
}
if ($failedTitles) {
    echo "\nFailed / needs review:\n";
    foreach ($failedTitles as $line) {
        echo "  - {$line}\n";
    }
}
echo str_repeat('=', 60) . "\n";
