<?php
/**
 * Telegram Login Widget callback
 *
 * GET  /api/auth/telegram?id=…&first_name=…&hash=…&…
 * POST /api/auth/telegram  { id, first_name, last_name, username, photo_url, auth_date, hash }
 *
 * Flow:
 *   1. Verify HMAC-SHA256 hash using bot token (Telegram spec).
 *   2. Check auth_date is not older than 24 hours.
 *   3. Upsert user row (matched by accounts.provider_account_id = telegram_id).
 *   4. Start a session and return user.
 *
 * Requires config:
 *   telegram.bot_token  — set in config.local.php
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Core\Util;
use Quiznosis\Core\App;
use Quiznosis\Models\User;

// Accept both GET (widget redirect) and POST (JS widget callback)
if (!in_array(Request::method(), ['GET', 'POST'], true)) {
    Response::error('Method not allowed', 405);
}

$botToken = App::config('telegram.bot_token') ?? (getenv('TELEGRAM_BOT_TOKEN') ?: '');
if (!$botToken) {
    Response::error('Telegram login is not configured on this server.', 503);
}

// ── Collect fields from GET or POST ────────────────────────
$data = Request::method() === 'POST' ? Request::body() : $_GET;

$required = ['id', 'first_name', 'auth_date', 'hash'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        Response::error("Missing field: $field", 400);
    }
}

$receivedHash = (string)$data['hash'];

// ── 1. Verify Telegram HMAC hash ───────────────────────────
// Build data-check string: all fields except hash, sorted alphabetically, joined by \n
$checkFields = [];
foreach ($data as $k => $v) {
    if ($k === 'hash') continue;
    $checkFields[] = "$k=$v";
}
sort($checkFields);
$checkString = implode("\n", $checkFields);

// Secret key = SHA-256 of the bot token (NOT hex2bin for this step)
$secretKey   = hash('sha256', $botToken, true);
$expectedHash = hash_hmac('sha256', $checkString, $secretKey);

if (!hash_equals($expectedHash, strtolower($receivedHash))) {
    Response::error('Invalid Telegram auth hash. Request may have been tampered with.', 401);
}

// ── 2. Check freshness (max 24 hours) ──────────────────────
$authDate = (int)$data['auth_date'];
if (time() - $authDate > 86400) {
    Response::error('Telegram auth data has expired. Please try again.', 401);
}

// ── 3. Upsert user ─────────────────────────────────────────
$pdo          = Database::pdo();
$telegramId   = (string)$data['id'];
$firstName    = trim((string)($data['first_name'] ?? ''));
$lastName     = trim((string)($data['last_name']  ?? ''));
$username     = trim((string)($data['username']   ?? ''));
$photoUrl     = trim((string)($data['photo_url']  ?? ''));

// Look up existing account link
$stmt = $pdo->prepare(
    "SELECT a.user_id FROM accounts a
      WHERE a.provider = 'telegram' AND a.provider_account_id = ?
      LIMIT 1"
);
$stmt->execute([$telegramId]);
$existingLink = $stmt->fetchColumn();

if ($existingLink) {
    // Existing Telegram user — fetch their user row
    $user = User::findById($existingLink);
    if (!$user) {
        Response::error('Account not found. Please contact support.', 404);
    }
    if (in_array($user['status'], ['SUSPENDED', 'INACTIVE'], true)) {
        Response::error('Your account has been suspended. Please contact support.', 403);
    }

    // Update avatar if Telegram provides one and we don't have one
    if ($photoUrl && empty($user['avatar'])) {
        $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")
            ->execute([$photoUrl, $user['id']]);
        $user['avatar'] = $photoUrl;
    }
} else {
    // New user — create account row + user row
    $userId    = Util::objectId();
    $accountId = Util::objectId();

    // Use telegram_id as a placeholder email (unique, never receives mail)
    $placeholderEmail = "tg_{$telegramId}@telegram.quiznosis.local";

    // Check if email already taken (edge case)
    $existing = User::findByEmailCaseInsensitive($placeholderEmail);
    if ($existing) {
        // Re-link
        $pdo->prepare(
            "INSERT IGNORE INTO accounts (id, user_id, type, provider, provider_account_id)
             VALUES (?, ?, 'oauth', 'telegram', ?)"
        )->execute([Util::objectId(), $existing['id'], $telegramId]);
        $user = $existing;
    } else {
        // Create fresh user
        $pdo->prepare(
            "INSERT INTO users
                (id, email, first_name, last_name, avatar, role, status, email_verified, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, 'STUDENT', 'ACTIVE', NOW(), NOW(), NOW())"
        )->execute([
            $userId,
            $placeholderEmail,
            $firstName ?: 'Telegram',
            $lastName  ?: null,
            $photoUrl  ?: null,
        ]);

        // Link in accounts table
        $pdo->prepare(
            "INSERT INTO accounts (id, user_id, type, provider, provider_account_id, scope)
             VALUES (?, ?, 'oauth', 'telegram', ?, ?)"
        )->execute([
            $accountId,
            $userId,
            $telegramId,
            $username ?: null,
        ]);

        $user = User::findById($userId);
    }
}

// ── 4. Start session ───────────────────────────────────────
Auth::login($user);

// ── 5. Respond ─────────────────────────────────────────────
// If GET (widget redirect), redirect to dashboard
if (Request::method() === 'GET') {
    $next = htmlspecialchars($_GET['next'] ?? '');
    $redirect = $next ?: '/dashboard.html';
    header('Location: ' . $redirect);
    exit;
}

Response::ok([
    'success' => true,
    'message' => 'Signed in with Telegram.',
    'user'    => User::publicShape($user),
]);
