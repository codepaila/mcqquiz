<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class Notification extends BaseModel
{
    protected static $table = 'notifications';
    protected static $jsonColumns = ['data'];
    protected static $fillable = [
        'user_id','type','status','title','message','data',
        'read_at','sent_at','expires_at','priority',
    ];

    public static function markRead(string $id, string $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE notifications SET status='READ', read_at=NOW(3) WHERE id=? AND user_id=?"
        );
        return $stmt->execute([$id, $userId]);
    }

    public static function unreadCount(string $userId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND status='UNREAD'"
        );
        $stmt->execute([$userId]);
        return (int)($stmt->fetch()['c'] ?? 0);
    }
}
