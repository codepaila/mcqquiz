<?php
/**
 * POST /api/auth/google
 * Exchange a Google ID-token (from the frontend) for a Quiznosis session.
 *
 * Flow:
 *  1. Frontend gets a credential (id_token) from Google's GSI library.
 *  2. Frontend POSTs { id_token } here.
 *  3. We verify the token against Google's tokeninfo endpoint (no SDK needed).
 *  4. Find or create the user (status = ACTIVE, email_verified = now).
 *  5. Log them in with the standard Auth::login() so the same session cookie
 *     mechanism applies everywhere else.
 *
 * To enable:
 *  - Set GOOGLE_CLIENT_ID in your .env (same value you paste into the GSI
 *    data-client_id attribute on the frontend).
 *  - No client secret is needed for this flow.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\App;
use Quiznosis\Core\Database;
use Quiznosis\Core\Audit;
use Quiznosis\Core\RateLimiter;
use Quiznosis\Models\User;
use Quiznosis\Models\UserDailyStats;

Request::requireMethod('POST');

// Rate-limit: 20 attempts / 10 min per IP
if (!RateLimiter::hit('google_oauth:' . Request::ip(), 20, 600)) {
    Response::error('Too many requests. Please wait and try again.', 429);
}

$body    = Request::body();
$idToken = trim((string)($body['id_token'] ?? ''));

if (!$idToken) {
    Response::error('Missing id_token.', 400);
}

// --- Verify the token with Google's tokeninfo endpoint -------------------
// This is a lightweight verification; for high-traffic production you can
// switch to the google/apiclient PHP library or verify the JWT locally.
$googleUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
$raw = @file_get_contents($googleUrl, false, $ctx);

if ($raw === false) {
    Response::error('Could not reach Google to verify your sign-in. Please try again.', 502);
}

$payload = json_decode($raw, true);

if (empty($payload) || isset($payload['error_description'])) {
    Response::error('Invalid Google sign-in token. Please try again.', 401);
}

// Confirm the token was issued for OUR app (prevents token-substitution attacks)
$clientId = App::config('google.client_id') ?? getenv('GOOGLE_CLIENT_ID');
if ($clientId && ($payload['aud'] ?? '') !== $clientId) {
    Response::error('Token audience mismatch.', 401);
}

$email     = strtolower(trim((string)($payload['email'] ?? '')));
$firstName = trim((string)($payload['given_name']  ?? $payload['name'] ?? 'User'));
$lastName  = trim((string)($payload['family_name'] ?? ''));
$avatar    = $payload['picture'] ?? null;
$googleSub = $payload['sub'] ?? null; // unique Google user ID

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Google did not provide a valid email address.', 400);
}
if (empty($payload['email_verified']) || $payload['email_verified'] === 'false') {
    Response::error('Your Google account email is not verified.', 403);
}

// --- Find or create the user --------------------------------------------
$user = User::findByEmailCaseInsensitive($email);

if ($user) {
    // Existing user — allow regardless of registration method.
    // Optionally refresh avatar if it was blank.
    if ($avatar && empty($user['avatar'])) {
        Database::pdo()
            ->prepare('UPDATE users SET avatar = ? WHERE id = ?')
            ->execute([$avatar, $user['id']]);
        $user['avatar'] = $avatar;
    }
    // If account is suspended / inactive, reject
    if (in_array($user['status'], ['SUSPENDED', 'INACTIVE'], true)) {
        Response::error('Account is not active.', 403, ['code' => $user['status']]);
    }
    // Activate PENDING accounts that come through Google (email already verified by Google)
    if ($user['status'] === 'PENDING') {
        Database::pdo()
            ->prepare("UPDATE users SET status = 'ACTIVE', email_verified = NOW() WHERE id = ?")
            ->execute([$user['id']]);
        $user['status']         = 'ACTIVE';
        $user['email_verified'] = date('Y-m-d H:i:s');
    }

    Audit::log([
        'action'      => 'LOGIN_GOOGLE',
        'entity_type' => 'USER',
        'entity_id'   => $user['id'],
        'details'     => ['email' => $email, 'method' => 'GOOGLE'],
    ]);
} else {
    // New user — register automatically (Google-verified email, no password needed)
    $user = Database::transaction(function () use (
        $email, $firstName, $lastName, $avatar, $googleSub
    ) {
        $u = User::create([
            'email'              => $email,
            'password'           => '',           // no password for Google-only accounts
            'first_name'         => $firstName ?: 'User',
            'last_name'          => $lastName ?: null,
            'avatar'             => $avatar,
            'role'               => 'STUDENT',
            'status'             => 'ACTIVE',     // Google already verified the email
            'email_verified'     => gmdate('Y-m-d H:i:s'),
            'verification_token' => null,
            'reset_expires'      => null,
            'backup_codes'       => [],
            'profession_id'      => null,
        ]);
        Audit::logUserRegistration($u['id'], [
            'email'              => $u['email'],
            'role'               => $u['role'],
            'firstName'          => $u['first_name'],
            'registrationMethod' => 'GOOGLE',
        ], Request::ip());
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
}

Auth::login($user);

Response::ok([
    'success' => true,
    'message' => 'Signed in with Google.',
    'user'    => User::publicShape($user),
    'isNew'   => empty($user['created_at']) ? false : (strtotime($user['created_at']) > time() - 5),
]);
