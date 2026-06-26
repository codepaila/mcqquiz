<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class Quiz extends BaseModel
{
    protected static $table = 'quizzes';
    protected static $jsonColumns = ['tags'];
    protected static $fillable = [
        'question','explanation','difficulty','subject_id','topic_id','book_id','tags',
    ];

    /** Load quiz + its options + reference rows, joined. */
    public static function findWithOptions(string $id): ?array
    {
        $q = self::findById($id);
        if (!$q) return null;
        $q['options'] = QuizOption::where(['quiz_id' => $id], ['order' => '`order` ASC']);
        return $q;
    }

    /** Load many quizzes plus their options. Used by quiz runner. */
    public static function loadManyWithOptions(array $ids): array
    {
        if (empty($ids)) return [];
        $quizzes = self::where(['id' => $ids]);
        if (empty($quizzes)) return [];
        $opts = QuizOption::where(['quiz_id' => $ids], ['order' => '`order` ASC']);
        $byQuiz = [];
        foreach ($opts as $o) $byQuiz[$o['quiz_id']][] = $o;
        foreach ($quizzes as &$q) $q['options'] = $byQuiz[$q['id']] ?? [];
        return $quizzes;
    }
}
