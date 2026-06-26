<?php
/**
 * POST /api/auth/login
 * NextAuth replacement — session login.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Audit;
use Quiznosis\Core\RateLimiter;
use Quiznosis\Models\User;

Request::requireMethod('POST');

if (!RateLimiter::hit('login:' . Request::ip(), 10, 600)) {
    Response::error('Too many login attempts, try again later.', 429);
}

$body = Request::body();
Validator::make($body)
    ->required('email')->email('email')
    ->required('password')
    ->abortIfFails();

$user = User::findByEmailCaseInsensitive((string)$body['email']);

// Generic message on failure to avoid leaking valid emails
$genericFail = function () {
    Response::error('Invalid email or password.', 401);
};

if (!$user || empty($user['password'])) {
    Audit::log([
        'action'      => 'LOGIN_FAILED',
        'entity_type' => 'USER',
        'details'     => ['email' => $body['email'], 'reason' => 'NOT_FOUND'],
    ]);
    $genericFail();
}

if (!Auth::verifyPassword((string)$body['password'], (string)$user['password'])) {
    Audit::logLogin($user['id'], false, ['reason' => 'BAD_PASSWORD']);
    $genericFail();
}

if ($user['status'] === 'PENDING') {
    Response::error('Please verify your email before logging in.', 403, ['code' => 'EMAIL_NOT_VERIFIED']);
}
if ($user['status'] === 'SUSPENDED' || $user['status'] === 'INACTIVE') {
    Response::error('Account is not active.', 403, ['code' => $user['status']]);
}

Auth::login($user);
Audit::logLogin($user['id'], true);

Response::ok([
    'success' => true,
    'message' => 'Logged in.',
    'user'    => User::publicShape($user),
]);
