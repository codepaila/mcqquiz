<?php
namespace Quiznosis\Core;

/**
 * Response — JSON helpers. Mirrors NextResponse.json shape used in the source.
 */
class Response
{
    public static function json($payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($payload = []): void
    {
        if (!is_array($payload)) {
            $payload = ['data' => $payload];
        }
        $payload['success'] = $payload['success'] ?? true;
        self::json($payload, 200);
    }

    public static function created(array $payload = []): void
    {
        $payload['success'] = true;
        self::json($payload, 201);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        $payload = array_merge(['error' => true, 'message' => $message], $extra);
        self::json($payload, $status);
    }

    public static function unauthorized(string $msg = 'Unauthorized'): void
    {
        self::error($msg, 401);
    }

    public static function forbidden(string $msg = 'Forbidden'): void
    {
        self::error($msg, 403);
    }

    public static function notFound(string $msg = 'Not found'): void
    {
        self::error($msg, 404);
    }
}
