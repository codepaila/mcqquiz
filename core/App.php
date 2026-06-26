<?php
namespace Quiznosis\Core;

use RuntimeException;

/**
 * App — minimal service container holding config + bootstrap helpers.
 */
class App
{
    private static ?array $config = null;

    public static function boot(): void
    {
        if (self::$config !== null) {
            return;
        }
        $path = dirname(__DIR__) . '/config/config.php';
        $local = dirname(__DIR__) . '/config/config.local.php';
        if (!file_exists($path)) {
            throw new RuntimeException("Config file not found at {$path}");
        }
        $cfg = require $path;
        if (file_exists($local)) {
            $cfg = array_replace_recursive($cfg, require $local);
        }
        self::$config = $cfg;
        date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');

        if (($cfg['app']['env'] ?? 'production') === 'production') {
            ini_set('display_errors', '0');
        } else {
            ini_set('display_errors', '1');
        }
        error_reporting(E_ALL);
    }

    /** Read a config value by dot path or full section. */
    public static function config(string $key = null)
    {
        if (self::$config === null) {
            self::boot();
        }
        if ($key === null) {
            return self::$config;
        }
        $parts = explode('.', $key);
        $val = self::$config;
        foreach ($parts as $p) {
            if (!is_array($val) || !array_key_exists($p, $val)) {
                return null;
            }
            $val = $val[$p];
        }
        return $val;
    }
}
