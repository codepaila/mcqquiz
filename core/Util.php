<?php
namespace Quiznosis\Core;

class Util
{
    /**
     * Generate a 24-char hex id (same shape as MongoDB ObjectId) so we can
     * keep referential identity compatible with the old data.
     */
    public static function objectId(): string
    {
        // 4-byte timestamp + 5-byte random + 3-byte counter, hex-encoded
        static $counter = null;
        if ($counter === null) {
            $counter = random_int(0, 0xFFFFFF);
        }
        $counter = ($counter + 1) & 0xFFFFFF;

        $ts = pack('N', time());
        $rand = random_bytes(5);
        $cnt = substr(pack('N', $counter), 1);          // 3 bytes
        return bin2hex($ts . $rand . $cnt);
    }

    public static function slugify(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? $text;
        $text = trim($text, '-');
        if (function_exists('iconv')) {
            $conv = @iconv('utf-8', 'us-ascii//TRANSLIT', $text);
            if ($conv !== false) {
                $text = $conv;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('~[^-\w]+~', '', $text) ?? $text;
        return $text !== '' ? $text : 'item';
    }

    public static function isValidEmail(string $email): bool
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /** Enforce password rule from Next register route. */
    public static function isStrongPassword(string $pwd): bool
    {
        if (strlen($pwd) < 8) return false;
        return (bool)preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $pwd);
    }

    public static function nowMs(): string
    {
        $t = microtime(true);
        $ms = sprintf('%03d', (int)(($t - floor($t)) * 1000));
        return date('Y-m-d H:i:s', (int)$t) . '.' . $ms;
    }

    public static function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** Safe array picker — only allow listed keys through. */
    public static function only(array $arr, array $keys): array
    {
        return array_intersect_key($arr, array_flip($keys));
    }
}
