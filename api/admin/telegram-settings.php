<?php
/**
 * Admin · Telegram Notification Settings
 *
 * GET  /api/admin/telegram-settings            — fetch current settings
 * POST /api/admin/telegram-settings            — save { bot_token?, notify_chat_id, notify_enabled }
 * POST /api/admin/telegram-settings { action:"test" }  — send a test message
 *
 * Settings stored in the `telegram_settings` DB table (auto-created on first request).
 * Admin only.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\TelegramNotifier;
use Quiznosis\Models\TelegramSettings;

Auth::requireAdmin();

// ── GET ───────────────────────────────────────────────────────────────────────
if (Request::method() === 'GET') {
    $s     = TelegramSettings::get();
    $token = (string)($s['bot_token'] ?? '');

    Response::ok([
        'data' => [
            'bot_token_set'    => $token !== '',
            'bot_token_masked' => $token !== ''
                ? substr($token, 0, 6) . str_repeat('*', max(0, strlen($token) - 10)) . substr($token, -4)
                : '',
            'notify_chat_id'   => (string)($s['notify_chat_id'] ?? ''),
            'notify_enabled'   => (bool)($s['notify_enabled'] ?? false),
        ],
    ]);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (Request::method() === 'POST') {
    $body = Request::body();

    // ── Test ──────────────────────────────────────────────────────────────────
    if (($body['action'] ?? '') === 'test') {
        $result = TelegramNotifier::test();
        if ($result['ok']) {
            Response::ok(['success' => true, 'message' => 'Test message sent! Check your Telegram.']);
        }
        Response::error($result['error'] ?? 'Test failed.', 400);
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    $patch = [];

    // Bot token — only update when a non-empty, non-masked value is sent
    $rawToken = trim((string)($body['bot_token'] ?? ''));
    if ($rawToken !== '' && strpos($rawToken, '***') === false) {
        $patch['bot_token'] = $rawToken;
    }

    // chat_id — always save when key present (even empty string to clear it)
    if (array_key_exists('notify_chat_id', $body)) {
        $patch['notify_chat_id'] = trim((string)$body['notify_chat_id']);
    }

    // enabled — always save when key present
    if (array_key_exists('notify_enabled', $body)) {
        $patch['notify_enabled'] = !empty($body['notify_enabled']) ? 1 : 0;
    }

    // Always ensure the row exists even if nothing changed (idempotent)
    TelegramSettings::get();

    if (!empty($patch)) {
        try {
            TelegramSettings::save($patch);
        } catch (\Throwable $e) {
            Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    Response::ok(['success' => true, 'message' => 'Telegram settings saved.']);
}

Response::error('Method not allowed', 405);
