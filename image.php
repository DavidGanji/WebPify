<?php
declare(strict_types=1);

// Adjustable WebP converter with optional resize, quality, and watermark.
/*
تنظیمات سراسری (فقط همینجا ویرایش کنید؛ GET فقط برای src):
- quality           : کیفیت WebP بین 0 تا 100
- scale             : درصد تغییر اندازه 1 تا 200 (100 یعنی بدون تغییر)
- max_w             : حداکثر عرض پیکسل (0 یعنی نامحدود)
- max_h             : حداکثر ارتفاع پیکسل (0 یعنی نامحدود)
- watermark_text    : متن واترمارک (خالی یعنی بدون واترمارک)
- watermark_opacity : شفافیت واترمارک 0 تا 100
مثال فراخوانی: image.php?src=/uploads/photo.jpg
*/
$CONFIG = [
    'quality'           => 50,
    'scale'             => 80.0,
    'max_w'             => 0,
    'max_h'             => 0,
    'watermark_text'    => '',
    'watermark_opacity' => 0,
];

error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * Safely load the requested file from the document root.
 */
function resolveSourcePath(string $src): string
{
    if ($src === '' || strpos($src, '..') !== false) {
        http_response_code(404);
        exit('Not allowed');
    }

    $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
    $resolved = realpath($root . DIRECTORY_SEPARATOR . ltrim($src, '/\\'));

    if ($resolved === false || strpos($resolved, $root) !== 0 || !is_file($resolved)) {
        http_response_code(404);
        exit('File not found');
    }

    return $resolved;
}

function sendCacheHeaders(int $mtime, string $etag, int $maxAge = 2592000): void
{
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
}

function maybeNotModified(int $mtime, string $etag): void
{
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

    if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
        sendCacheHeaders($mtime, $etag);
        http_response_code(304);
        exit;
    }

    if ($ifModifiedSince) {
        $since = strtotime($ifModifiedSince);
        if ($since !== false && $since >= $mtime) {
            sendCacheHeaders($mtime, $etag);
            http_response_code(304);
            exit;
        }
    }
}

/**
 * Send the original file when WebP is not supported.
 */
function sendOriginal(string $path, int $mtime, string $etag): void
{
    $info = getimagesize($path);
    if ($info && isset($info['mime'])) {
        header('Content-Type: ' . $info['mime']);
    } else {
        header('Content-Type: application/octet-stream');
    }
    sendCacheHeaders($mtime, $etag);
    readfile($path);
    exit;
}

function clampFloat(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function clampInt(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

function loadImage(string $path, string $ext)
{
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $img = imagecreatefromjpeg($path);
            break;
        case 'png':
            $img = imagecreatefrompng($path);
            break;
        case 'gif':
            $img = imagecreatefromgif($path);
            break;
        default:
            sendOriginal($path, time(), '"' . md5($path) . '"');
            exit;
    }

    if (!$img) {
        return false;
    }

    if (in_array($ext, ['png', 'gif'], true)) {
        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);
    }

    return $img;
}

/**
 * Resize by percentage and/or bounding box.
 */
function applyResize($img, float $scalePercent, int $maxW, int $maxH)
{
    $origW = imagesx($img);
    $origH = imagesy($img);

    $targetW = (int)round($origW * ($scalePercent / 100));
    $targetH = (int)round($origH * ($scalePercent / 100));

    if ($maxW > 0 && $targetW > $maxW) {
        $targetW = $maxW;
        $targetH = (int)round($targetW * ($origH / $origW));
    }

    if ($maxH > 0 && $targetH > $maxH) {
        $targetH = $maxH;
        $targetW = (int)round($targetH * ($origW / $origH));
    }

    if ($targetW === $origW && $targetH === $origH) {
        return $img;
    }

    $resized = imagecreatetruecolor($targetW, $targetH);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);

    imagecopyresampled($resized, $img, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);
    imagedestroy($img);

    return $resized;
}

/**
 * Apply a simple text watermark in the bottom-right corner.
 */
function applyWatermark($img, string $text, int $opacity): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }

    $font = 5; // Built-in GD font.
    $margin = 12;
    $width = imagesx($img);
    $height = imagesy($img);
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);

    $x = max($margin, $width - $textWidth - $margin);
    $y = max($margin, $height - $textHeight - $margin);

    $alpha = clampInt($opacity, 0, 100);
    $gdAlpha = (int)round(127 * (1 - ($alpha / 100)));

    //$shadowColor = imagecolorallocatealpha($img, 0, 0, 0, min(127, $gdAlpha + 20));
    //$textColor = imagecolorallocatealpha($img, 255, 255, 255, $gdAlpha);
/////////////////////////////////////////////////////////

// Get average background color luminance
$rgb = imagecolorat($img, $x, $y);
$r = ($rgb >> 16) & 0xFF;
$g = ($rgb >> 8) & 0xFF;
$b = $rgb & 0xFF;

// Luminance formula
$luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);

// If background is bright → use black text, if dark → use white text
if ($luminance > 128) {
    // Background is light → make text dark
    $textColor = imagecolorallocatealpha($img, 0, 0, 0, $gdAlpha);
    $shadowColor = imagecolorallocatealpha($img, 255, 255, 255, min(127, $gdAlpha + 20)); 
} else {
    // Background is dark → make text white
    $textColor = imagecolorallocatealpha($img, 255, 255, 255, $gdAlpha);
    $shadowColor = imagecolorallocatealpha($img, 0, 0, 0, min(127, $gdAlpha + 20));
}



//////////////////////////////////////////////////////
    imagestring($img, $font, $x + 1, $y + 1, $text, $shadowColor);
    imagestring($img, $font, $x, $y, $text, $textColor);
}

$src = $_GET['src'] ?? '';
$sourcePath = resolveSourcePath($src);
$ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
$mtime = filemtime($sourcePath) ?: time();
$etag = '"' . md5($sourcePath . '|' . $mtime . '|' . filesize($sourcePath)) . '"';

maybeNotModified($mtime, $etag);

// $acceptsWebp = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
// if (!$acceptsWebp) {
    // sendOriginal($sourcePath, $mtime, $etag);
// }

$img = loadImage($sourcePath, $ext);
if (!$img) {
    http_response_code(500);
    exit('Error loading image');
}

// مقدارها از تنظیمات سراسری خوانده می‌شوند.
$quality = clampInt((int)$CONFIG['quality'], 0, 100);
$scale = clampFloat((float)$CONFIG['scale'], 1, 200);
$maxW = max(0, (int)$CONFIG['max_w']);
$maxH = max(0, (int)$CONFIG['max_h']);
$watermark = (string)$CONFIG['watermark_text'];
$watermarkOpacity = clampInt((int)$CONFIG['watermark_opacity'], 0, 100);

$img = applyResize($img, $scale, $maxW, $maxH);

if ($watermark !== '') {
    applyWatermark($img, $watermark, $watermarkOpacity);
}

header('Content-Type: image/webp');
sendCacheHeaders($mtime, $etag);
imagewebp($img, null, $quality);
imagedestroy($img);
exit;
