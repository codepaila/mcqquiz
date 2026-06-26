<?php
/**
 * POST /api/auth/resend-verification
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\App;
use Quiznosis\Core\Mailer;
use Quiznosis\Core\RateLimiter;
use Quiznosis\Models\User;

Request::requireMethod('POST');

$email = trim((string)(Request::input('email') ?? ''));
if (!$email) Response::error('Email is required.', 400);

if (!RateLimiter::hit('resend_verify:' . $email, 3, 600)) {
    Response::error('Please wait before requesting another email.', 429);
}

$user = User::findByEmailCaseInsensitive($email);
// Always respond success-ish to avoid email enumeration.
$genericMsg = 'If your email exists and is unverified, a new verification link has been sent.';

if (!$user || $user['status'] !== 'PENDING') {
    Response::ok(['success' => true, 'message' => $genericMsg]);
}

$token = $user['verification_token'] ?: bin2hex(random_bytes(32));
$expires = gmdate('Y-m-d H:i:s', time() + 86400);
User::update($user['id'], [
    'verification_token' => $token,
    'reset_expires'      => $expires,
]);

$verifyUrl = rtrim((string)App::config('app.url'), '/')
    . '/auth/verify?token=' . urlencode($token)
    . '&email=' . urlencode($user['email']);
$tpl = Mailer::templateWelcome($user['first_name'], $verifyUrl);
Mailer::send($user['email'], '[Resend] ' . $tpl['subject'], $tpl['html']);

Response::ok(['success' => true, 'message' => $genericMsg]);
