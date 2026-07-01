<?php
/**
 * bootstrap.php — included at the top of every endpoint.
 * Sets up autoload, config, CORS, and JSON error responses for uncaught throws.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/autoload.php';

use Quiznosis\Core\App;
use Quiznosis\Core\Response;

App::boot();

// --- CORS (open by default; tighten in prod) -----------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Convert uncaught throwables to 500 JSON ----------------------------
set_exception_handler(function (\Throwable $e): void {
    error_log('[Quiznosis] Unhandled: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $debug = (bool)App::config('app.debug');
    Response::error(
        $debug ? $e->getMessage() : 'Internal server error',
        500,
        $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : []
    );
});

set_error_handler(function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
