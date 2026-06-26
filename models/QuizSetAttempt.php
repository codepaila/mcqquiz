<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class QuizSetAttempt extends BaseModel
{
    protected static $table = 'quiz_set_attempts';
    protected static $fillable = [
        'user_id','quiz_set_id','chosen_mode','start_time','end_time','time_spent_sec','elapsed_seconds',
        'score','raw_score','negative_marks','total_points','percentage','passed',
        'status','device_info','ip_address','attempt_number',
    ];

    /** Load an attempt with responses + minimal quiz_set info needed by submit. */
    public static function findWithDetails(string $id): ?array
    {
        $att = self::findById($id);
        if (!$att) return null;
        $att['responses'] = QuizSetResponse::where(['attempt_id' => $id]);
        $att['quiz_set'] = QuizSet::findById($att['quiz_set_id']);
        return $att;
    }

    /** Most recent attempt number for a (user, set) pair. */
    public static function nextAttemptNumber(string $userId, string $setId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT MAX(attempt_number) AS n FROM quiz_set_attempts WHERE user_id = ? AND quiz_set_id = ?'
        );
        $stmt->execute([$userId, $setId]);
        $n = (int)($stmt->fetch()['n'] ?? 0);
        return $n + 1;
    }
}
