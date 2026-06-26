<?php
namespace Quiznosis\Core;

class Uploader
{
    public const MAX_BYTES = 5 * 1024 * 1024;          // 5 MB
    public const ALLOWED   = [
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif'  => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Save a $_FILES entry into /storage/uploads/<subdir>/ and return the public URL.
     *
     * @param array  $file    a $_FILES['x'] entry
     * @param string $subdir  e.g. "receipts", "qr"
     * @return string public URL like "/storage/uploads/receipts/abc.jpg"
     * @throws \RuntimeException on any problem
     */
    public static function save(array $file, string $subdir): string
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            // is_uploaded_file is false outside real POST — fall back to file existence
            if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
                throw new \RuntimeException('No file uploaded');
            }
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::errorMessage((int)$file['error']));
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('File too large — max 5 MB');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new \RuntimeException('Unsupported file type: ' . $mime);
        }
        $ext = self::ALLOWED[$mime];

        $subdir  = preg_replace('/[^a-z0-9_-]/i', '', $subdir);
        $rootDir = dirname(__DIR__) . '/storage/uploads/' . $subdir;
        if (!is_dir($rootDir) && !mkdir($rootDir, 0775, true) && !is_dir($rootDir)) {
            throw new \RuntimeException('Could not create upload directory');
        }

        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $rootDir . '/' . $name;
        if (!@rename($file['tmp_name'], $dest) && !@copy($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Could not save uploaded file');
        }
        @chmod($dest, 0644);

        return '/storage/uploads/' . $subdir . '/' . $name;
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
            UPLOAD_ERR_PARTIAL  => 'Upload was interrupted',
            UPLOAD_ERR_NO_FILE  => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION =>
                'Server upload error',
            default => 'Upload failed',
        };
    }
}
