<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class QuizSetResponse extends BaseModel
{
    protected static $table = 'quiz_set_responses';
    protected static $jsonColumns = ['selected_option_ids'];
    protected static $fillable = [
        'attempt_id','quiz_id','selected_option_ids','is_correct',
        'points_earned','time_spent_sec','answered_at','is_marked','negative_marks_deducted',
    ];

    /** Upsert a response — used during quiz attempt to save per-question state. */
    public static function upsert(string $attemptId, string $quizId, array $data): array
    {
        $existing = self::firstWhere(['attempt_id' => $attemptId, 'quiz_id' => $quizId]);
        if ($existing) {
            return self::update($existing['id'], $data);
        }
        $data['attempt_id'] = $attemptId;
        $data['quiz_id']    = $quizId;
        return self::create($data);
    }
}
