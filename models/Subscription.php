<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class Subscription extends BaseModel
{
    protected static $table = 'subscriptions';
    protected static $fillable = [
        'user_id','plan_id','status','start_date','end_date','auto_renew',
    ];

    /** Find subscriptions that have expired but are still flagged ACTIVE. */
    public static function expiredActive(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM subscriptions WHERE status = 'ACTIVE' AND end_date < NOW(3)"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
