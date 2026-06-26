<?php
/**
 * POST /api/auth/verify
 * Port of src/app/api/auth/verify/route.ts
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;
use Quiznosis\Core\Audit;
use Quiznosis\Models\User;
use Quiznosis\Models\VerificationToken;
use Quiznosis\Models\Notification;

Request::requireMethod('POST');

$body = Request::body();
$token = trim((string)($body['token'] ?? ''));
$email = trim((string)($body['email'] ?? ''));

if ($token === '' || $email === '') {
    Response::json(['success' => false, 'message' => 'Invalid request'], 400);
}

// Verify via verification_tokens OR direct user.verification_token (register uses the latter).
$user = User::findByEmailCaseInsensitive($email);
$validViaVT = VerificationToken::findActive($email, $token);
$validViaUser = $user && ($user['verification_token'] ?? null) === $token
    && (!$user['reset_expires'] || strtotime($user['reset_expires']) > time());

if (!$validViaVT && !$validViaUser) {
    Audit::log([
        'action'      => 'USER_UPDATED',
        'entity_type' => 'USER',
        'details'     => ['email' => $email, 'action' => 'VERIFICATION_FAILED', 'reason' => 'Invalid or expired token'],
    ]);
    Response::json(['success' => false, 'message' => 'Invalid or expired token'], 400);
}

if (!$user) {
    Response::json(['success' => false, 'message' => 'User not found'], 404);
}

Database::transaction(function () use ($user, $email) {
    User::update($user['id'], [
        'email_verified'     => gmdate('Y-m-d H:i:s'),
        'status'             => 'ACTIVE',
        'verification_token' => null,
        'reset_expires'      => null,
    ]);
    VerificationToken::purgeForIdentifier($email);
});

Audit::logEmailVerification($user['id'], true, $email);

Notification::create([
    'user_id' => $user['id'],
    'type'    => 'SYSTEM_ALERT',
    'status'  => 'UNREAD',
    'title'   => 'Welcome to Quiznosis!',
    'message' => 'Your email has been verified successfully. Start your learning journey now!',
    'data'    => ['action' => 'DASHBOARD_REDIRECT'],
]);

Response::ok(['success' => true, 'message' => 'Email verified successfully']);
