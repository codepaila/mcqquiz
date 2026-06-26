<?php
namespace Quiznosis\Models;

class UserDailyStats extends BaseModel
{
    protected static $table = 'user_daily_stats';
    protected static $jsonColumns = ['subject_performance','peak_hours'];
    protected static $fillable = [
        'user_id','date','questions_attempted','correct_answers','study_time_min',
        'tests_completed','average_score','subject_performance','peak_hours',
    ];

    public static function upsertForToday(string $userId, array $patch): array
    {
        $today = date('Y-m-d');
        $existing = self::firstWhere(['user_id' => $userId, 'date' => $today]);
        if ($existing) return self::update($existing['id'], $patch);
        $patch['user_id'] = $userId;
        $patch['date']    = $today;
        return self::create($patch);
    }
}
