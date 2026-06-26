<?php
/**
 * POST /api/auth/forgot
 * Port of src/app/api/auth/forgot/route.ts
 *
 * Always returns success-ish to prevent email enumeration.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\App;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Mailer;
use Quiznosis\Core\Validator;
use Quiznosis\Models\User;
use Quiznosis\Models\VerificationToken;

Request::requireMethod('POST', 'DELETE');

if (Request::method() === 'DELETE') {
    // Invalidate a specific token
    $token = $_GET['token'] ?? null;
    $email = $_GET['email'] ?? null;
    if (!$token || !$email) {
        Response::error('Token and email are required', 400);
    }
    VerificationToken::deleteByToken((string)$token);
    Response::ok(['message' => 'Reset token has been invalidated.']);
}

$body = Request::body();
Validator::make($body)
    ->required('email', 'Email address is required')
    ->email('email', 'Invalid email format')
    ->abortIfFails();

$ip = Request::ip();
$ua = Request::userAgent();
$email = (string)$body['email'];
$genericMsg = 'If an account exists with this email, you will receive a password reset link.';

$user = User::findByEmailCaseInsensitive($email);
if (!$user) {
    Audit::log([
        'action'      => 'PASSWORD_RESET_REQUESTED',
        'entity_type' => 'USER',
        'ip_address'  => $ip,
        'user_agent'  => $ua,
        'details'     => ['email' => $email, 'status' => 'USER_NOT_FOUND'],
    ]);
    usleep(500 * 1000); // mitigate timing attacks
    Response::ok(['message' => $genericMsg]);
}

VerificationToken::purgeExpiredFor($user['email']);

$recent = VerificationToken::findFreshFor($user['email']);
if ($recent) {
    $age = time() - strtotime($recent['created_at']);
    if ($age < 5 * 60) {
        Response::ok([
            'message' => 'A password reset link has already been sent recently. Please check your email or wait a few minutes.',
        ]);
    }
}

$token = bin2hex(random_bytes(16));
$ttl   = (int)App::config('auth.reset_token_ttl');
$expires = gmdate('Y-m-d H:i:s', time() + $ttl);

VerificationToken::create([
    'identifier' => $user['email'],
    'token'      => $token,
    'expires'    => $expires,
    'created_at' => gmdate('Y-m-d H:i:s'),
]);

$resetUrl = rtrim((string)App::config('app.url'), '/')
    . '/auth/reset?token=' . urlencode($token)
    . '&email=' . urlencode($user['email']);
$tpl = Mailer::templatePasswordReset($user['first_name'] ?: 'User', $resetUrl, 1);

Audit::logPasswordReset($user['id'], 'requested', $user['email']);

$result = Mailer::send($user['email'], $tpl['subject'], $tpl['html'],
    'password-reset-' . $user['id'] . '-' . time());

if (!($result['success'] ?? false)) {
    VerificationToken::deleteByToken($token);
    Response::error('Failed to send password reset email. Please try again later.', 500);
}

Response::ok([
    'message'   => 'Password reset link has been sent to your email address.',
    'expiresAt' => gmdate('c', time() + $ttl),
]);
