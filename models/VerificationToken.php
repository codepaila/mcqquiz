<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class VerificationToken extends BaseModel
{
    protected static $table = 'verification_tokens';
    protected static $fillable = ['identifier','token','expires','created_at'];

    public static function findActive(string $identifier, string $token): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM verification_tokens
             WHERE identifier = ? AND token = ? LIMIT 1'
        );
        $stmt->execute([$identifier, $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findFreshFor(string $identifier): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM verification_tokens
             WHERE identifier = ? AND expires > NOW(3)
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function purgeForIdentifier(string $identifier): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM verification_tokens WHERE identifier = ?');
        $stmt->execute([$identifier]);
        return $stmt->rowCount();
    }

    public static function purgeExpiredFor(string $identifier): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM verification_tokens WHERE identifier = ? AND expires < NOW(3)'
        );
        $stmt->execute([$identifier]);
        return $stmt->rowCount();
    }

    public static function deleteByToken(string $token): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM verification_tokens WHERE token = ?');
        $stmt->execute([$token]);
    }
}
