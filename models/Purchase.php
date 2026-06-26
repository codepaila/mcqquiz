<?php

namespace Quiznosis\Models;

require_once __DIR__ . '/BaseModel.php';

use Quiznosis\Core\Database;

class Purchase extends BaseModel
{
    protected static $table = 'purchases';

    protected static $jsonColumns = ['metadata'];

    protected static $fillable = [
        'user_id',
        'type',
        'status',
        'course_id',
        'course_subscription_plan_id',
        'quiz_set_id',
        'subscription_plan_id',
        'amount',
        'currency',
        'payment_method',
        'transaction_id',
        'order_id',
        'metadata',
        'receipt_url',
        'receipt_notes',
        'reviewed_by_id',
        'reviewed_at',
        'review_note',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    /**
     * Does the given user currently have access to a specific paid quiz set?
     * Access = COMPLETED + is_active=1 + (valid_until IS NULL OR valid_until > NOW).
     */
    public static function userHasQuizSetAccess(string $userId, string $quizSetId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c FROM purchases
              WHERE user_id = ?
                AND quiz_set_id = ?
                AND type = 'QUIZ_SET'
                AND status = 'COMPLETED'
                AND is_active = 1
                AND (valid_until IS NULL OR valid_until > NOW(3))"
        );
        $stmt->execute([$userId, $quizSetId]);
        return (int)$stmt->fetch()['c'] > 0;
    }

    /**
     * Does the user have a still-PENDING request for this quiz set?
     */
    public static function userHasPendingForQuizSet(string $userId, string $quizSetId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c FROM purchases
              WHERE user_id = ? AND quiz_set_id = ? AND type = 'QUIZ_SET' AND status = 'PENDING'"
        );
        $stmt->execute([$userId, $quizSetId]);
        return (int)$stmt->fetch()['c'] > 0;
    }

    /**
     * Is this quiz set part of at least one course?
     */
    public static function quizSetBelongsToCourse(string $quizSetId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c FROM course_materials
              WHERE quiz_set_id = ? AND type = 'QUIZ_SET'"
        );
        $stmt->execute([$quizSetId]);
        return (int)$stmt->fetch()['c'] > 0;
    }

    /**
     * Does the user have an ACTIVE (not expired, not suspended) enrollment
     * in any course that contains this quiz set?
     */
    public static function userHasCourseAccessToQuizSet(string $userId, string $quizSetId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c
               FROM course_materials cm
               JOIN enrollments e ON e.course_id = cm.course_id
              WHERE cm.quiz_set_id = ?
                AND cm.type = 'QUIZ_SET'
                AND e.user_id = ?
                AND e.status IN ('ACTIVE','APPROVED')
                AND (e.expires_at IS NULL OR e.expires_at > NOW(3))"
        );
        $stmt->execute([$quizSetId, $userId]);
        return (int)$stmt->fetch()['c'] > 0;
    }

    /**
     * Does the user have a PENDING enrollment for a course containing this quiz set?
     */
    public static function userHasPendingCourseForQuizSet(string $userId, string $quizSetId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c
               FROM course_materials cm
               JOIN enrollments e ON e.course_id = cm.course_id
              WHERE cm.quiz_set_id = ?
                AND cm.type = 'QUIZ_SET'
                AND e.user_id = ?
                AND e.status = 'PENDING'"
        );
        $stmt->execute([$quizSetId, $userId]);
        return (int)$stmt->fetch()['c'] > 0;
    }

    /**
     * The current user's purchase history, with joined info.
     */
    public static function listForUser(string $userId, ?string $status = null): array
    {
        $sql = "SELECT p.*, qs.name AS quiz_set_name, qs.slug AS quiz_set_slug,
                       qs.total_questions AS quiz_set_questions, qs.mode AS quiz_set_mode,
                       sp.name AS subscription_plan_name,
                       c.title AS course_title, c.slug AS course_slug
                  FROM purchases p
                  LEFT JOIN quiz_sets qs ON qs.id = p.quiz_set_id
                  LEFT JOIN subscription_plans sp ON sp.id = p.subscription_plan_id
                  LEFT JOIN courses c ON c.id = p.course_id
                 WHERE p.user_id = ?";
        $params = [$userId];
        if ($status) { $sql .= ' AND p.status = ?'; $params[] = $status; }
        $sql .= ' ORDER BY p.created_at DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        // Compute expiry flag client-friendly: a purchase is expired iff
        // valid_until is set and in the past. Permanent purchases have
        // valid_until = NULL and are never expired.
        $nowTs = time();
        foreach ($rows as &$r) {
            $r['expired'] = !empty($r['valid_until']) && strtotime($r['valid_until']) < $nowTs;
        }
        return $rows;
    }

    /**
     * Admin: paginated list of purchases with joined user and resource info.
     */
    public static function listForAdmin(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $sql = "SELECT p.*,
                       u.email AS user_email, u.first_name AS user_first_name, u.last_name AS user_last_name,
                       qs.name AS quiz_set_name, qs.slug AS quiz_set_slug, qs.price AS quiz_set_price,
                       sp.name AS subscription_plan_name,
                       c.title AS course_title, c.slug AS course_slug,
                       csp.name AS plan_name,
                       rv.email AS reviewer_email
                  FROM purchases p
                  JOIN users u ON u.id = p.user_id
                  LEFT JOIN quiz_sets qs ON qs.id = p.quiz_set_id
                  LEFT JOIN subscription_plans sp ON sp.id = p.subscription_plan_id
                  LEFT JOIN courses c ON c.id = p.course_id
                  LEFT JOIN course_subscription_plans csp ON csp.id = p.course_subscription_plan_id
                  LEFT JOIN users rv ON rv.id = p.reviewed_by_id
                 WHERE 1=1";
        $params = [];
        if (!empty($filters['status'])) { $sql .= ' AND p.status = ?';  $params[] = $filters['status']; }
        if (!empty($filters['type']))   { $sql .= ' AND p.type   = ?';  $params[] = $filters['type']; }
        if (!empty($filters['user_id'])){ $sql .= ' AND p.user_id = ?'; $params[] = $filters['user_id']; }
        $sql .= ' ORDER BY p.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = Database::pdo()->prepare($sql);
        // Bind manually so LIMIT/OFFSET are ints (PDO emulation quirk).
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, is_int($p) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Total count matching admin filters (for pagination).
     */
    public static function countForAdmin(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) AS c FROM purchases p WHERE 1=1";
        $params = [];
        if (!empty($filters['status'])) { $sql .= ' AND p.status = ?';  $params[] = $filters['status']; }
        if (!empty($filters['type']))   { $sql .= ' AND p.type   = ?';  $params[] = $filters['type']; }
        if (!empty($filters['user_id'])){ $sql .= ' AND p.user_id = ?'; $params[] = $filters['user_id']; }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['c'];
    }
}
