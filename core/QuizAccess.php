<?php
/**
 * Centralized access check: does this user have access to this quiz set?
 *
 * A quiz set is accessible when ANY of these are true:
 *   1. It's not a paid quiz set (is_paid = 0)
 *   2. The user has an active direct purchase of it
 *   3. The user has an active enrollment in a course that contains it as
 *      a material (this is the case that was missing — once you buy/enroll
 *      in the parent course, every quiz inside is unlocked)
 *   4. The course material has is_free_demo = 1 (anyone can attempt it)
 *
 * Returns:
 *   ['allowed' => bool, 'reason' => string|null, 'via' => 'free'|'purchase'|'course'|'demo'|null]
 *
 * The 'via' field is useful for UI/UX (e.g. show "via course: Loksewa
 * Complete" on the result page).
 */
namespace Quiznosis\Core;

use Quiznosis\Core\Database;

class QuizAccess
{
    public static function check(string $userId, string $quizSetId): array
    {
        $pdo = Database::pdo();

        // 1. Free quiz set — always allowed
        $stmt = $pdo->prepare('SELECT id, is_paid FROM quiz_sets WHERE id = ?');
        $stmt->execute([$quizSetId]);
        $set = $stmt->fetch();
        if (!$set) {
            return ['allowed' => false, 'reason' => 'Quiz set not found', 'via' => null];
        }
        if ((int)($set['is_paid'] ?? 0) !== 1) {
            return ['allowed' => true, 'reason' => null, 'via' => 'free'];
        }

        // 2. Direct purchase of this quiz set (active + not expired)
        $stmt = $pdo->prepare(
            "SELECT id FROM purchases
              WHERE user_id = ? AND quiz_set_id = ?
                AND type = 'QUIZ_SET'
                AND status IN ('ACTIVE', 'COMPLETED')
                AND is_active = 1
                AND (valid_until IS NULL OR valid_until > NOW())
              LIMIT 1"
        );
        $stmt->execute([$userId, $quizSetId]);
        if ($stmt->fetchColumn()) {
            return ['allowed' => true, 'reason' => null, 'via' => 'purchase'];
        }

        // 3. Active enrollment in any course that contains this quiz set.
        //    APPROVED or ACTIVE status, expires_at NULL or in the future.
        //    This is the fix for the "bought the course but quiz inside
        //    still asks for purchase" bug.
        $stmt = $pdo->prepare(
            "SELECT e.id, c.title AS course_title
               FROM course_materials cm
               JOIN enrollments e ON e.course_id = cm.course_id
               JOIN courses c     ON c.id = cm.course_id
              WHERE cm.quiz_set_id = ?
                AND cm.type        = 'QUIZ_SET'
                AND e.user_id      = ?
                AND e.status       IN ('APPROVED', 'ACTIVE')
                AND (e.expires_at IS NULL OR e.expires_at > NOW())
              LIMIT 1"
        );
        $stmt->execute([$quizSetId, $userId]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'allowed' => true,
                'reason'  => null,
                'via'     => 'course',
                'courseTitle' => $row['course_title'] ?? null,
            ];
        }

        // 4. is_free_demo course material — gated by column existence so
        //    this works whether or not that migration has been run yet.
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM course_materials
                  WHERE quiz_set_id = ? AND is_free_demo = 1 LIMIT 1"
            );
            $stmt->execute([$quizSetId]);
            if ($stmt->fetchColumn()) {
                return ['allowed' => true, 'reason' => null, 'via' => 'demo'];
            }
        } catch (\PDOException $e) {
            // Column doesn't exist (older DB) — silently skip this check.
            if (strpos($e->getMessage(), 'is_free_demo') === false) throw $e;
        }

        return [
            'allowed' => false,
            'reason'  => 'Purchase or enroll in a course containing this quiz to unlock it.',
            'via'     => null,
        ];
    }
}
