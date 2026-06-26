<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class Session extends BaseModel
{
    protected static $table = 'sessions';
    protected static $fillable = ['user_id','session_token','expires'];

    public static function findValidByToken(string $token): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM sessions WHERE session_token = ? AND expires > NOW(3) LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function deleteByToken(string $token): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM sessions WHERE session_token = ?');
        $stmt->execute([$token]);
    }

    public static function purgeExpired(): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM sessions WHERE expires < NOW(3)');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
