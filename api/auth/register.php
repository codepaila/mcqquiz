<?php
/**
 * POST /api/auth/register
 * Port of src/app/api/auth/register/route.ts
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Validator;
use Quiznosis\Core\RateLimiter;
use Quiznosis\Core\Database;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Mailer;
use Quiznosis\Core\App;
use Quiznosis\Core\Util;
use Quiznosis\Models\User;
use Quiznosis\Models\Profession;
use Quiznosis\Models\UserDailyStats;

Request::requireMethod('POST', 'GET');

if (Request::method() === 'GET') {
    Response::ok([
        'status'    => 'ok',
        'message'   => 'Registration API is running',
        'timestamp' => Util::isoNow(),
    ]);
}

$ip = Request::ip();
$ua = Request::userAgent();

// --- Rate limit: 5 req / 15 min per IP --------------------------------
$rl = App::config('rate_limit');
if (!RateLimiter::hit('register:' . $ip, $rl['register_max'], $rl['register_window'])) {
    Response::error('Too many registration attempts. Please try again later.', 429);
}

$body = Request::body();

$v = Validator::make($body)
    ->required('email', 'Email, password, and first name are required.')
    ->required('password', 'Email, password, and first name are required.')
    ->required('firstName', 'Email, password, and first name are required.')
    ->email('email', 'Invalid email format.')
    ->minLength('password', 8, 'Password must be at least 8 characters.')
    ->strongPassword('password');

$v->abortIfFails();

$email    = strtolower(trim((string)$body['email']));
$firstName = trim((string)$body['firstName']);
$lastName  = isset($body['lastName']) ? trim((string)$body['lastName']) : null;
$professionId = $body['professionId'] ?? null;

if (strlen($firstName) < 2) {
    Response::error('First name must be at least 2 characters.', 400);
}

// --- Duplicate registration cooldown (1 min) --------------------------
if (!RateLimiter::hit('register_dup:' . $email . ':' . $ip, 1, 60)) {
    Response::error('Please wait a moment before trying again.', 429);
}

$existing = User::findByEmailCaseInsensitive($email);
if ($existing) {
    // Same-as-Next behavior: if PENDING and stale, re-send verification.
    if ($existing['status'] === 'PENDING' && !empty($existing['verification_token'])) {
        $age = time() - strtotime($existing['created_at']);
        if ($age > 3600) {
            $verifyUrl = rtrim((string)App::config('app.url'), '/')
                . '/auth/verify?token=' . urlencode($existing['verification_token'])
                . '&email=' . urlencode($existing['email']);
            $tpl = Mailer::templateWelcome($existing['first_name'], $verifyUrl);
            Mailer::send($existing['email'], '[Resend] ' . $tpl['subject'], $tpl['html'],
                'verify_' . $existing['id'] . '_' . time());
            Response::ok([
                'success' => true,
                'message' => 'Verification email has been resent. Please check your email.',
                'resend'  => true,
            ]);
        }
    }
    Response::error(
        'Email already registered. Please try logging in or use a different email.',
        409,
        ['code' => 'EMAIL_EXISTS']
    );
}

if ($professionId !== null && $professionId !== '') {
    if (!Profession::findById((string)$professionId)) {
        Response::error('Invalid profession selected.', 400);
    }
}

$verificationToken = bin2hex(random_bytes(32));
$expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);

$user = Database::transaction(function () use (
    $email, $firstName, $lastName, $professionId, $verificationToken, $expiresAt, $ip
) {
    $u = User::create([
        'email'              => $email,
        'password'           => Auth::hashPassword((string)Request::input('password')),
        'first_name'         => $firstName,
        'last_name'          => $lastName,
        'role'               => 'STUDENT',
        'status'             => 'PENDING',
        'verification_token' => $verificationToken,
        'reset_expires'      => $expiresAt,
        'profession_id'      => $professionId ?: null,
        'backup_codes'       => [],
        'email_verified'     => null,
    ]);
    Audit::logUserRegistration($u['id'], [
        'email'              => $u['email'],
        'role'               => $u['role'],
        'firstName'          => $u['first_name'],
        'registrationMethod' => 'EMAIL',
    ], $ip);
    UserDailyStats::create([
        'user_id'             => $u['id'],
        'date'                => date('Y-m-d'),
        'questions_attempted' => 0,
        'correct_answers'     => 0,
        'study_time_min'      => 0,
        'tests_completed'     => 0,
        'average_score'       => 0,
        'subject_performance' => [],
        'peak_hours'          => [],
    ]);
    return $u;
});

$verifyUrl = rtrim((string)App::config('app.url'), '/')
    . '/auth/verify?token=' . urlencode($verificationToken)
    . '&email=' . urlencode($user['email']);
$tpl = Mailer::templateWelcome($user['first_name'], $verifyUrl);
$emailResult = Mailer::send($user['email'], $tpl['subject'], $tpl['html'],
    'verify_' . $user['id'] . '_' . time());

// Optional admin email notification
$adminEmail = App::config('app.admin_email');
if ($adminEmail) {
    $adminSubject = 'New user registered: ' . $user['email'];
    $adminHtml = '<p>New user signed up.</p><pre>'
        . htmlspecialchars(json_encode(User::publicShape($user), JSON_PRETTY_PRINT))
        . '</pre>';
    Mailer::send($adminEmail, $adminSubject, $adminHtml, 'admin_notify_' . $user['id']);
}

// In-app notification for all active admins
try {
    $pdo     = Database::pdo();
    $admins  = $pdo->query("SELECT id FROM users WHERE role='ADMIN' AND status='ACTIVE'")->fetchAll();
    $fullName = trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''));
    foreach ($admins as $adm) {
        $nid = Util::objectId();
        $pdo->prepare(
            "INSERT INTO notifications
                (id, user_id, type, status, title, message, data, sent_at, created_at, updated_at)
             VALUES (?, ?, 'ADMIN_NOTIFICATION', 'UNREAD', ?, ?, ?, NOW(3), NOW(3), NOW(3))"
        )->execute([
            $nid,
            $adm['id'],
            'New user registered: ' . $fullName,
            $user['email'],
            json_encode([
                'user_id'   => $user['id'],
                'email'     => $user['email'],
                'full_name' => $fullName,
            ]),
        ]);
    }
} catch (\Throwable $e) { /* swallow — registration must not fail */ }

Response::json([
    'success'   => true,
    'message'   => 'Account created successfully! Please check your email to verify your account.',
    'user'      => User::publicShape($user),
    'emailSent' => (bool)($emailResult['success'] ?? false),
], 201);
