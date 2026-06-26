<?php
namespace Quiznosis\Core;

use Quiznosis\Models\User;
use Quiznosis\Models\Session as SessionModel;

/**
 * Auth — sessions + bcrypt, mapping the NextAuth flows.
 *
 * Session strategy:
 *   - PHP session holds a session_token cookie.
 *   - The session_token is also stored in `sessions` table with an expires column.
 *   - This dual approach gives server-side revocation while keeping cookie auth simple.
 */
class Auth
{
    private static ?array $currentUser = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = App::config('auth');
        session_name($cfg['session_name']);
        session_set_cookie_params([
            'lifetime' => $cfg['session_lifetime'],
            'path'     => '/',
            'secure'   => (bool)$cfg['cookie_secure'],
            'httponly' => (bool)$cfg['cookie_httponly'],
            'samesite' => $cfg['cookie_samesite'],
        ]);
        session_start();
    }

    public static function hashPassword(string $plain): string
    {
        $cost = (int)(App::config('auth.bcrypt_cost') ?? 12);
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /** Log a user in: create DB session row + populate PHP session. */
    public static function login(array $user): string
    {
        self::start();
        $token = bin2hex(random_bytes(32));
        $ttl   = (int)App::config('auth.session_lifetime');
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);

        SessionModel::create([
            'user_id'       => $user['id'],
            'session_token' => $token,
            'expires'       => $expires,
        ]);

        $_SESSION['user_id']       = $user['id'];
        $_SESSION['session_token'] = $token;
        $_SESSION['role']          = $user['role'];

        self::$currentUser = $user;
        return $token;
    }

    public static function logout(): void
    {
        self::start();
        if (!empty($_SESSION['session_token'])) {
            SessionModel::deleteByToken($_SESSION['session_token']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
        self::$currentUser = null;
    }

    /** Return current user array or null. Validates DB session row. */
    public static function user(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }
        self::start();
        if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) {
            return null;
        }
        $sess = SessionModel::findValidByToken($_SESSION['session_token']);
        if (!$sess || $sess['user_id'] !== $_SESSION['user_id']) {
            self::logout();
            return null;
        }
        $u = User::findById($_SESSION['user_id']);
        if (!$u || $u['status'] !== 'ACTIVE') {
            self::logout();
            return null;
        }
        return self::$currentUser = $u;
    }

    public static function id(): ?string
    {
        $u = self::user();
        return $u['id'] ?? null;
    }

    /** Guards — call at top of any protected endpoint. */
    public static function require(): array
    {
        $u = self::user();
        if (!$u) Response::unauthorized();
        return $u;
    }

    public static function requireRole(string ...$roles): array
    {
        $u = self::require();
        if (!in_array($u['role'], $roles, true)) {
            Response::forbidden('Insufficient role');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        return self::requireRole('ADMIN');
    }
}
