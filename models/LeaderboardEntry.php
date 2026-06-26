<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class LeaderboardEntry extends BaseModel
{
    protected static $table = 'leaderboard_entries';
    protected static $fillable = [
        'leaderboard_id','user_id','score','rank','questions_solved',
        'correct_answers','accuracy','time_spent_min','previous_rank','rank_change',
    ];

    /** Top N entries for a leaderboard, joined with user names. */
    public static function topFor(string $leaderboardId, int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT le.*, u.first_name, u.last_name, u.avatar
             FROM leaderboard_entries le
             JOIN users u ON u.id = le.user_id
             WHERE le.leaderboard_id = ?
             ORDER BY le.`rank` ASC LIMIT ' . (int)$limit
        );
        $stmt->execute([$leaderboardId]);
        return $stmt->fetchAll();
    }
}
