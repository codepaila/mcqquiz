<?php
ob_start(); // Buffer any PHP warnings/errors so they don't corrupt JSON output
ini_set('display_errors', '0'); // Safety net: suppress HTML errors before bootstrap loads
/**
 * POST /api/upload-avatar
 * Multipart form: file = image file
 * Returns: { ok: true, url: "/quiznosis/storage/uploads/avatars/xxx.jpg" }
 */
require_once __DIR__ . '/bootstrap.php';

use Quiznosis\Core\Auth;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Method not allowed', 405);

$user = Auth::require();

if (empty($_FILES['file'])) Response::error('No file uploaded', 400);

$file    = $_FILES['file'];
$maxSize = 3 * 1024 * 1024; // 3 MB

if ($file['error'] !== UPLOAD_ERR_OK) Response::error('Upload error: ' . $file['error'], 400);
if ($file['size'] > $maxSize)         Response::error('Image must be under 3MB', 400);

// Validate mime type
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
if (!isset($allowed[$mimeType])) Response::error('Only JPG, PNG, GIF, WEBP images allowed', 400);

$ext     = $allowed[$mimeType];
$dir     = __DIR__ . '/../storage/uploads/avatars/';
$webPath = '/quiznosis/storage/uploads/avatars/';

if (!is_dir($dir)) mkdir($dir, 0775, true);

$pdo = Database::pdo();
$currentRow = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
$currentRow->execute([$user['id']]);
$currentAvatar = $currentRow->fetchColumn();

// Generate unique filename
$filename = $user['id'] . '_' . time() . '.' . $ext;
$dest     = $dir . $filename;

// Resize to max 400x400 using GD (fallback to direct copy if GD not available)
$gdAvailable = extension_loaded('gd');
$resized = false;

if ($gdAvailable) {
    try {
        $src = match($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/gif'  => imagecreatefromgif($file['tmp_name']),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($file['tmp_name']) : null,
            default      => null,
        };
        if ($src) {
            $origW = imagesx($src);
            $origH = imagesy($src);
            $maxDim = 400;
            $ratio  = min($maxDim / $origW, $maxDim / $origH, 1);
            $newW   = (int)($origW * $ratio);
            $newH   = (int)($origH * $ratio);
            $dst    = imagecreatetruecolor($newW, $newH);
            if ($mimeType === 'image/png') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            match($mimeType) {
                'image/jpeg' => imagejpeg($dst, $dest, 85),
                'image/png'  => imagepng($dst, $dest),
                'image/gif'  => imagegif($dst, $dest),
                default      => imagejpeg($dst, $dest, 85),
            };
            imagedestroy($src);
            imagedestroy($dst);
            $resized = true;
        }
    } catch (\Throwable $e) {
        $resized = false;
    }
}

if (!$resized) {
    // GD not available or failed — just move the file as-is
    move_uploaded_file($file['tmp_name'], $dest);
}

$url = $webPath . $filename;

// Save to DB
$pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$url, $user['id']]);

$buffered = ob_get_clean(); // Discard any buffered warnings/errors
// If anything non-empty was buffered (e.g. a PHP notice), log it but don't leak it
if ($buffered !== '') {
    error_log('[upload-avatar] Suppressed output: ' . substr($buffered, 0, 500));
}
Response::ok(['url' => $url]);
