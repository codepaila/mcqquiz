<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class User extends BaseModel
{
    protected static $table = 'users';
    protected static $jsonColumns = ['backup_codes'];
    protected static $fillable = [
        'email','password','first_name','last_name','avatar','role','status',
        'email_verified','verification_code','verification_token','reset_expires',
        'two_factor_enabled','two_factor_secret','backup_codes','profession_id',
    ];

    public static function findByEmailCaseInsensitive(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();
        return $row ? self::decodeRow($row) : null;
    }

    /** Return the user shape that the API exposes to clients (no secrets). */
    public static function publicShape(array $u): array
    {
        return [
            'id'         => $u['id'],
            'email'      => $u['email'],
            'firstName'  => $u['first_name'],
            'lastName'   => $u['last_name'],
            'avatar'     => $u['avatar'] ?? null,
            'role'       => $u['role'],
            'status'     => $u['status'],
            'emailVerified' => $u['email_verified'] ?? null,
        ];
    }
}
