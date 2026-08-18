<?php
require_once __DIR__ . '/../includes/config.php';

$ratingKey = (int) ($_GET['ratingKey'] ?? 0);
$file = (string) ($_GET['file'] ?? '');
// full=1 serves the original, uncapped, e.g. for a "view full size" link
$full = isset($_GET['full']) && $_GET['full'] === '1';

if (!$ratingKey || $file === '') {
    http_response_code(400);
    exit('Missing ratingKey or file');
}
// No path separators allowed — this must be a bare filename inside the movie's own folder.
if (str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) {
    http_response_code(400);
    exit('Invalid filename');
}

$stmt = get_db()->prepare('SELECT folder_path FROM movies WHERE rating_key = :rk');
$stmt->execute(['rk' => $ratingKey]);
$folder = $stmt->fetchColumn();
if (!$folder) {
    http_response_code(404);
    exit('Movie not found');
}

$requested = realpath(rtrim($folder, '/') . '/' . $file);
$folderReal = realpath($folder);
// Defense in depth: the resolved path must genuinely live inside the movie's own folder.
if ($requested === false || $folderReal === false || !str_starts_with($requested, $folderReal)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
if (!isset($mimeMap[$ext])) {
    http_response_code(415);
    exit('Unsupported file type');
}

if ($full || !function_exists('gd_info')) {
    header('Content-Type: ' . $mimeMap[$ext]);
    header('Cache-Control: private, max-age=3600');
    readfile($requested);
    exit;
}

$maxWidth = (int) (get_setting('thumb_max_width', '100'));
$cacheKey = md5($requested . '|' . filemtime($requested) . '|' . $maxWidth) . '.jpg';
$cachePath = THUMB_CACHE_DIR . '/' . $cacheKey;

if (!is_file($cachePath)) {
    if (!make_thumbnail($requested, $ext, $cachePath, $maxWidth)) {
        // GD failed for some reason (corrupt image, unsupported variant) — fall back to the original.
        header('Content-Type: ' . $mimeMap[$ext]);
        readfile($requested);
        exit;
    }
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
readfile($cachePath);
exit;

function make_thumbnail(string $srcPath, string $ext, string $destPath, int $maxWidth): bool
{
    $src = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($srcPath),
        'png' => @imagecreatefrompng($srcPath),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
        default => false,
    };
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= $maxWidth) {
        // Already small enough — just re-encode as jpg for a consistent cache format.
        $dst = $src;
        $dstW = $srcW;
        $dstH = $srcH;
    } else {
        $dstW = $maxWidth;
        $dstH = (int) round($srcH * ($maxWidth / $srcW));
        $dst = imagecreatetruecolor($dstW, $dstH);
        // Flatten transparency (e.g. logo PNGs) onto white so the jpg cache doesn't go black.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    }

    $ok = imagejpeg($dst, $destPath, 85);
    imagedestroy($src);
    if ($dst !== $src) {
        imagedestroy($dst);
    }
    return $ok;
}
