<?php
namespace Quiznosis\Models;

class UserQuizSetStats extends BaseModel
{
    protected static $table = 'user_quiz_set_stats';
    protected static $jsonColumns = ['performance_trend'];
    protected static $fillable = [
        'user_id','quiz_set_id','total_attempts','completed_attempts',
        'best_score','best_percentage','average_score','average_percentage',
        'total_time_spent','last_attempt_date','first_attempt_date',
        'current_streak','best_streak','days_attempted','performance_trend',
    ];

    public static function upsertFor(string $userId, string $setId, array $patch): array
    {
        $existing = self::firstWhere(['user_id' => $userId, 'quiz_set_id' => $setId]);
        if ($existing) return self::update($existing['id'], $patch);
        $patch['user_id'] = $userId;
        $patch['quiz_set_id'] = $setId;
        return self::create($patch);
    }
}
