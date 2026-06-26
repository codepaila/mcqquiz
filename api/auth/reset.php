<?php
/**
 * POST /api/auth/reset
 * Body: { token, email, password }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Core\Audit;
use Quiznosis\Models\User;
use Quiznosis\Models\VerificationToken;

Request::requireMethod('POST');

$body = Request::body();
Validator::make($body)
    ->required('token')
    ->required('email')->email('email')
    ->required('password')
    ->strongPassword('password')
    ->abortIfFails();

$email = trim((string)$body['email']);
$token = trim((string)$body['token']);

$vt = VerificationToken::findActive($email, $token);
if (!$vt || strtotime($vt['expires']) < time()) {
    Response::error('Invalid or expired reset link.', 400);
}

$user = User::findByEmailCaseInsensitive($email);
if (!$user) {
    Response::error('Account not found.', 404);
}

Database::transaction(function () use ($user, $body, $email) {
    User::update($user['id'], [
        'password'      => Auth::hashPassword((string)$body['password']),
        'reset_expires' => null,
    ]);
    VerificationToken::purgeForIdentifier($email);
});

Audit::log([
    'user_id'     => $user['id'],
    'action'      => 'PASSWORD_RESET_COMPLETED',
    'entity_type' => 'USER',
    'entity_id'   => $user['id'],
    'details'     => ['email' => $email],
]);

Response::ok(['success' => true, 'message' => 'Password updated. You can now log in.']);
