<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

class QuizSet extends BaseModel
{
    protected static $table = 'quiz_sets';
    protected static $jsonColumns = ['tags'];
    protected static $fillable = [
        'name','slug','description','mode','duration_minutes','passing_score','status',
        'subject_id','profession_id','topic_id','book_id','exam_type_id','tags','total_questions',
        'is_paid','price','currency','sku','enable_negative_marking','negative_mark_per_question',
        'access_days',
    ];

    /** Load a quiz set with its items joined to quiz + options. */
    public static function findWithQuestions(string $id): ?array
    {
        $set = self::findById($id);
        if (!$set) return null;

        $items = QuizSetItem::where(['quiz_set_id' => $id], ['order' => '`order` ASC']);
        $quizIds = array_column($items, 'quiz_id');
        $quizzes = Quiz::loadManyWithOptions($quizIds);

        // index quizzes by id, preserve item order
        $byId = [];
        foreach ($quizzes as $q) $byId[$q['id']] = $q;
        $set['items'] = array_map(function ($it) use ($byId) {
            $it['quiz'] = $byId[$it['quiz_id']] ?? null;
            return $it;
        }, $items);

        return $set;
    }

    /** Return a count of items in the set (used by quiz/submit scoring). */
    public static function itemCount(string $setId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS c FROM quiz_set_items WHERE quiz_set_id = ?'
        );
        $stmt->execute([$setId]);
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    /**
     * Recount the set's questions and write the cached total_questions column.
     * Call this whenever items are added to or removed from a set so the
     * cached count never goes stale. Returns the fresh count.
     */
    public static function syncTotalQuestions(string $setId): int
    {
        $count = self::itemCount($setId);
        $stmt = Database::pdo()->prepare(
            'UPDATE quiz_sets SET total_questions = ? WHERE id = ?'
        );
        $stmt->execute([$count, $setId]);
        return $count;
    }
}
