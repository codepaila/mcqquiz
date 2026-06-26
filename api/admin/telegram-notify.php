<?php
/**
 * Admin · Telegram Notify Dispatcher
 *
 * POST /api/admin/telegram-notify
 *
 * Called by the admin frontend Live Updates poller whenever it detects
 * new activity. The frontend sends a list of new event objects; this
 * endpoint formats and dispatches each one to the admin's Telegram chat.
 *
 * Body: { events: [ { type, title, meta:{} } ] }
 *
 * To avoid duplicate messages across browser sessions, we track the last
 * notified timestamp per event-type in a tiny flat file (notify_state.json)
 * stored in /tmp or a writable data dir. Admin-only.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\TelegramNotifier;
use Quiznosis\Core\App;

Request::requireMethod('POST');
Auth::requireAdmin();

$body   = Request::body();
$events = $body['events'] ?? [];

if (!is_array($events) || empty($events)) {
    Response::ok(['sent' => 0]);
}

// ── Dedup guard ───────────────────────────────────────────────────────────────
// State file stores: { "event_id_hash" => unix_timestamp }
// We skip any event whose hash we've seen in the last 5 minutes.
$stateFile = sys_get_temp_dir() . '/quiznosis_tg_notify_state.json';
$state     = [];
if (file_exists($stateFile)) {
    $raw   = @file_get_contents($stateFile);
    $state = $raw ? (json_decode($raw, true) ?? []) : [];
}

// Prune entries older than 10 minutes
$now = time();
foreach ($state as $k => $ts) {
    if ($now - $ts > 600) unset($state[$k]);
}

// ── Icon map ──────────────────────────────────────────────────────────────────
$iconMap = [
    'user'       => '👤',
    'purchase'   => '💳',
    'enrollment' => '📚',
    'contact'    => '📬',
    'quiz'       => '📝',
    'report'     => '🚩',
    'system'     => '⚙️',
];

$sent   = 0;
$errors = [];

foreach ($events as $event) {
    $type  = (string)($event['type']  ?? 'system');
    $title = (string)($event['title'] ?? 'Admin Alert');
    $meta  = (array) ($event['meta']  ?? []);
    $eventId = (string)($event['id']  ?? '');

    // Build a dedup key from type + id (or type + title hash)
    $dedupKey = md5($type . '|' . ($eventId ?: $title));

    if (isset($state[$dedupKey])) {
        // Already sent recently — skip
        continue;
    }

    $icon = $iconMap[$type] ?? '🔔';

    // Build field list from meta — pretty-print keys
    $fields = [];
    $labelMap = [
        'name'        => 'Name',
        'email'       => 'Email',
        'role'        => 'Role',
        'amount'      => 'Amount',
        'course'      => 'Course',
        'status'      => 'Status',
        'subject'     => 'Subject',
        'message'     => 'Message',
        'user'        => 'User',
        'quiz'        => 'Quiz',
        'score'       => 'Score',
        'description' => 'Description',
        'reason'      => 'Reason',
    ];
    foreach ($meta as $k => $v) {
        $label = $labelMap[$k] ?? ucfirst(str_replace('_', ' ', $k));
        $fields[$label] = $v;
    }

    $message = TelegramNotifier::buildMessage($icon, $title, $fields);
    $ok      = TelegramNotifier::send($message);

    if ($ok) {
        $state[$dedupKey] = $now;
        $sent++;
    } else {
        $errors[] = "Failed to send: {$title}";
    }
}

// Persist updated state
@file_put_contents($stateFile, json_encode($state));

if (!empty($errors) && $sent === 0) {
    Response::error(implode('; ', $errors), 500);
}

Response::ok([
    'sent'   => $sent,
    'errors' => $errors,
]);
