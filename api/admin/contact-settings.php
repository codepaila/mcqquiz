<?php
/**
 * GET  /api/admin/contact-settings  — fetch contact settings (public + admin)
 * POST /api/admin/contact-settings  — save (admin only)
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

$method = Request::method();
$pdo    = Database::pdo();

$defaults = [
    'whatsapp_url'     => 'https://wa.me/message/6KL7YKWGT227O1',
    'whatsapp_label'   => 'Chat with us instantly',
    'telegram_url'     => 'https://t.me/quiznosis',
    'telegram_label'   => 'Join our community',
    'instagram_url'    => 'https://www.instagram.com/quiz_nosis',
    'instagram_label'  => 'Follow updates & tips',
    'email_address'    => 'support@quiznosis.com',
    'email_label'      => 'support@quiznosis.com',
    'urgent_title'     => 'Immediate Help',
    'urgent_text'      => 'For urgent course access issues or technical problems, WhatsApp gives you the fastest response — usually within minutes.',
    'urgent_btn_label' => 'Chat on WhatsApp',
    'response_time'    => 'Typically responds within a few hours',
    'show_whatsapp'    => 1,
    'show_telegram'    => 1,
    'show_instagram'   => 1,
    'show_email'       => 1,
];

// ── GET — public + admin ──────────────────────────────────
if ($method === 'GET') {
    try {
        $row = $pdo->query("SELECT * FROM contact_settings WHERE id = 1")->fetch();
        if (!$row) {
            // Table exists but no row yet — return defaults
            Response::ok(['data' => $defaults]);
        }
        // Merge with defaults so missing columns always have values
        $data = array_merge($defaults, array_filter((array)$row, fn($v) => $v !== null));
        Response::ok(['data' => $data]);
    } catch (\Throwable $e) {
        // Table doesn't exist yet — return defaults silently
        Response::ok(['data' => $defaults]);
    }
}

// ── POST — admin only ─────────────────────────────────────
if ($method === 'POST') {
    Auth::requireAdmin();

    $body = Request::body();

    $allowed = [
        'whatsapp_url',   'whatsapp_label',
        'telegram_url',   'telegram_label',
        'instagram_url',  'instagram_label',
        'email_address',  'email_label',
        'urgent_title',   'urgent_text',   'urgent_btn_label',
        'response_time',
        'show_whatsapp',  'show_telegram', 'show_instagram', 'show_email',
    ];

    // Ensure row exists
    try {
        $exists = $pdo->query("SELECT id FROM contact_settings WHERE id = 1")->fetchColumn();
        if (!$exists) {
            $pdo->exec("INSERT INTO contact_settings (id) VALUES (1)");
        }
    } catch (\Throwable $e) {
        Response::error('contact_settings table not found. Please run the migration SQL.', 500);
    }

    $set = []; $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $body)) {
            $set[]    = "`$col` = ?";
            $params[] = ($body[$col] === '' ? null : $body[$col]);
        }
    }
    if (empty($set)) Response::error('Nothing to update', 400);

    $pdo->prepare("UPDATE contact_settings SET " . implode(', ', $set) . " WHERE id = 1")
        ->execute($params);

    $row  = $pdo->query("SELECT * FROM contact_settings WHERE id = 1")->fetch();
    $data = array_merge($defaults, array_filter((array)$row, fn($v) => $v !== null));

    Response::ok(['data' => $data, 'message' => 'Contact settings saved.', 'success' => true]);
}

Response::error('Method not allowed', 405);
