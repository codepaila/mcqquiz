<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class AuditLog extends BaseModel
{
    protected static $table = 'audit_logs';
    protected static $jsonColumns = ['details','metadata'];
    protected static $fillable = [
        'user_id','action','entity_type','entity_id',
        'ip_address','user_agent','details','metadata',
    ];

    public static function purgeOlderThanDays(int $days): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM audit_logs WHERE created_at < (NOW(3) - INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
