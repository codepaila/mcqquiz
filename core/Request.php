<?php
namespace Quiznosis\Core;

class Request
{
    /** Decoded body — cached per request. */
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) {
            return self::$body = $_POST ?? [];
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return self::$body = [];
        }
        $decoded = json_decode($raw, true);
        return self::$body = is_array($decoded) ? $decoded : [];
    }

    public static function input(string $key, $default = null)
    {
        $body = self::body();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($m === 'POST') {
            $override = $_POST['_method'] ?? null;
            if ($override) {
                $override = strtoupper((string)$override);
                if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                    return $override;
                }
            }
        }
        return $m;
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }

    public static function ip(): string
    {
        $fwd = self::header('X-Forwarded-For');
        if ($fwd) {
            return trim(explode(',', $fwd)[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public static function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    public static function requireMethod(string ...$allowed): void
    {
        if (!in_array(self::method(), $allowed, true)) {
            Response::error('Method not allowed', 405, ['allowed' => $allowed]);
        }
    }
}
