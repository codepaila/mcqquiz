<?php
namespace Quiznosis\Core;

use Quiznosis\Models\AuditLog;

/**
 * Audit — port of Next's audit.service. All admin/auth actions funnel here.
 */
class Audit
{
    public static function log(array $entry): void
    {
        $entry['ip_address'] = $entry['ip_address'] ?? Request::ip();
        $entry['user_agent'] = $entry['user_agent'] ?? Request::userAgent();
        // Don't blow up the API if audit insert fails — log to error log instead.
        try {
            AuditLog::create([
                'user_id'     => $entry['user_id']    ?? null,
                'action'      => $entry['action'],
                'entity_type' => $entry['entity_type'],
                'entity_id'   => $entry['entity_id']  ?? null,
                'ip_address'  => $entry['ip_address'],
                'user_agent'  => $entry['user_agent'],
                'details'     => $entry['details']    ?? null,
                'metadata'    => $entry['metadata']   ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Audit] failed to log: ' . $e->getMessage());
        }
    }

    public static function logUserRegistration(string $userId, array $details, ?string $ip = null): void
    {
        self::log([
            'user_id'     => $userId,
            'action'      => 'USER_REGISTERED',
            'entity_type' => 'USER',
            'entity_id'   => $userId,
            'ip_address'  => $ip,
            'details'     => $details,
        ]);
    }

    public static function logEmailVerification(string $userId, bool $success, string $email): void
    {
        self::log([
            'user_id'     => $userId,
            'action'      => 'USER_VERIFIED',
            'entity_type' => 'USER',
            'entity_id'   => $userId,
            'details'     => ['email' => $email, 'success' => $success],
        ]);
    }

    public static function logLogin(string $userId, bool $success, array $extra = []): void
    {
        self::log([
            'user_id'     => $userId,
            'action'      => $success ? 'LOGIN_SUCCESS' : 'LOGIN_FAILED',
            'entity_type' => 'USER',
            'entity_id'   => $userId,
            'details'     => $extra,
        ]);
    }

    public static function logPasswordReset(string $userId, string $stage, string $email): void
    {
        self::log([
            'user_id'     => $userId,
            'action'      => 'PASSWORD_RESET_REQUESTED',
            'entity_type' => 'USER',
            'entity_id'   => $userId,
            'details'     => ['email' => $email, 'stage' => $stage],
        ]);
    }
}
