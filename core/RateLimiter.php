<?php
namespace Quiznosis\Core;

/**
 * RateLimiter — disk-backed token-window limiter. Production deployments
 * should swap this for Redis, but for a LAMP host this avoids extra deps.
 */
class RateLimiter
{
    private static function path(string $key): string
    {
        $dir = sys_get_temp_dir() . '/qz_rl';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir . '/' . hash('sha256', $key);
    }

    /**
     * Returns true if allowed, false if over the cap.
     * Uses a sliding window: first-request timestamp resets after $windowSec.
     */
    public static function hit(string $key, int $max, int $windowSec): bool
    {
        $file = self::path($key);
        $now  = time();
        $fp   = fopen($file, 'c+');
        if (!$fp) return true;          // fail-open
        flock($fp, LOCK_EX);
        $data = stream_get_contents($fp);
        $state = $data ? json_decode($data, true) : null;

        if (!$state || ($now - ($state['first'] ?? 0)) > $windowSec) {
            $state = ['first' => $now, 'count' => 1];
        } else {
            $state['count']++;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        flock($fp, LOCK_UN);
        fclose($fp);

        return $state['count'] <= $max;
    }
}
