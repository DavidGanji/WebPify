<?php
// خطا تو خروجی نیاد که هدر تصویر خراب نشه
error_reporting(E_ALL);
ini_set('display_errors', 0);

// آدرس فایلی که از .htaccess اومده
$src = $_GET['src'] ?? '';

if (!$src || strpos($src, '..') !== false) {
    http_response_code(404);
    exit('Not allowed');
}

// مسیر فیزیکی روی سرور
$sourcePath = $_SERVER['DOCUMENT_ROOT'] . $src;

if (!file_exists($sourcePath)) {
    http_response_code(404);
    exit('File not found');
}

// بررسی پشتیبانی WebP در مرورگر
$acceptsWebp = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;

$ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

// اگر مرورگر webp بلد نیست → همون تصویر اصلی رو بده
if (!$acceptsWebp) {
    $info = getimagesize($sourcePath);
    if ($info && isset($info['mime'])) {
        header('Content-Type: ' . $info['mime']);
    } else {
        header('Content-Type: image/jpeg');
    }
    readfile($sourcePath);
    exit;
}

// لود تصویر بر اساس فرمت
switch ($ext) {
    case 'jpg':
    case 'jpeg':
        $img = imagecreatefromjpeg($sourcePath);
        break;
    case 'png':
        $img = imagecreatefrompng($sourcePath);
        // شفافیت
        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        break;
    case 'gif':
        $img = imagecreatefromgif($sourcePath);
        break;
    default:
        // اگر فرمت ناشناخته بود، همون اصلی رو بده
        $info = getimagesize($sourcePath);
        header('Content-Type: ' . $info['mime']);
        readfile($sourcePath);
        exit;
}

if (!$img) {
    http_response_code(500);
    exit('Error loading image');
}

// هدر WebP و کش مرورگر (اختیاری)
header('Content-Type: image/webp');
// مثلا ۳۰ روز کش مرورگر
header('Cache-Control: public, max-age=2592000');

// خروجی مستقیم WebP بدون ذخیره روی دیسک
imagewebp($img, null, 80); // کیفیت 0–100
imagedestroy($img);
exit;
