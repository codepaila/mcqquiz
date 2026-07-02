<?php
/**
 * Admin · AI Explanation Assistant Settings
 *
 * GET  /api/admin/ai-settings   — fetch current settings (API key masked)
 * POST /api/admin/ai-settings   — save {
 *   provider_label?, api_base_url?, api_key?, model?, enabled?,
 *   prompts?: [ { id?, name, prompt }, ... ]
 * }
 *
 * Settings stored in the `ai_settings` DB table (created manually via the
 * migration SQL at the bottom of models/AiSettings.php — deliberately NOT
 * auto-created at runtime, see that file for why).
 *
 * Generic by design — works with DeepSeek or any other OpenAI-compatible
 * chat completions API by changing api_base_url / model here, no code
 * changes needed. Admin only.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\AiSettings;

Auth::requireAdmin();

// ── GET ───────────────────────────────────────────────────────────────────
if (Request::method() === 'GET') {
    $s   = AiSettings::get();
    $key = (string)($s['api_key'] ?? '');

    Response::ok([
        'data' => [
            'provider_label'   => (string)($s['provider_label'] ?? ''),
            'api_base_url'     => (string)($s['api_base_url'] ?? ''),
            'api_key_set'      => $key !== '',
            'api_key_masked'   => $key !== ''
                ? substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4)
                : '',
            'model'            => (string)($s['model'] ?? ''),
            'prompts'          => $s['prompts'] ?? [],
            'enabled'          => (bool)($s['enabled'] ?? false),
        ],
    ]);
}

// ── POST ──────────────────────────────────────────────────────────────────
if (Request::method() === 'POST') {
    $body = Request::body();
    $patch = [];

    // API key — only update when a non-empty, non-masked value is sent
    // (the masked display value contains '***', so we can tell the admin
    // didn't actually type a new key and just re-saved the form as-is).
    $rawKey = trim((string)($body['api_key'] ?? ''));
    if ($rawKey !== '' && strpos($rawKey, '***') === false) {
        $patch['api_key'] = $rawKey;
    }

    foreach (['provider_label', 'api_base_url', 'model'] as $field) {
        if (array_key_exists($field, $body)) {
            $patch[$field] = trim((string)$body[$field]);
        }
    }

    if (array_key_exists('enabled', $body)) {
        $patch['enabled'] = !empty($body['enabled']) ? 1 : 0;
    }

    if (array_key_exists('prompts', $body) && is_array($body['prompts'])) {
        $patch['prompts'] = $body['prompts'];
    }

    // Always ensure the row exists even if nothing changed (idempotent, INSERT-only)
    AiSettings::get();

    if (!empty($patch)) {
        try {
            AiSettings::save($patch);
        } catch (\Throwable $e) {
            Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    Response::ok(['success' => true, 'message' => 'AI settings saved.']);
}

Response::error('Method not allowed', 405);
