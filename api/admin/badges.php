<?php
/**
 * GET /api/admin/badges
 *
 * Returns unread/pending counts for admin sidebar badges.
 * Lightweight — single query per count, cached in PHP APCu if available.
 * Called by the admin frontend every 30 seconds for live updates.
 *
 * Response:
 * {
 *   "ok": true,
 *   "data": {
 *     "contact":      5,   ← unread contact messages
 *     "purchases":    3,   ← pending purchase approvals
 *     "reports":      2,   ← pending quiz reports
 *     "enrollments":  1    ← pending enrollment requests
 *   }
 * }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Auth;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;
use Quiznosis\Core\Request;

Request::requireMethod('GET');
Auth::requireAdmin();

$pdo = Database::pdo();

$row = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM contact_messages  WHERE status = 'UNREAD')                        AS contact,
        (SELECT COUNT(*) FROM purchases         WHERE status = 'PENDING')                        AS purchases,
        (SELECT COUNT(*) FROM question_reports  WHERE status = 'PENDING')                        AS reports,
        (SELECT COUNT(*) FROM enrollments       WHERE status IN ('PENDING','PENDING_PAYMENT'))   AS enrollments,
        (SELECT COUNT(*) FROM users             WHERE created_at >= NOW() - INTERVAL 24 HOUR
                                                  AND status IN ('ACTIVE','PENDING'))            AS new_users
")->fetch();

Response::ok([
    'data' => [
        'contact'     => (int)($row['contact']     ?? 0),
        'purchases'   => (int)($row['purchases']   ?? 0),
        'reports'     => (int)($row['reports']     ?? 0),
        'enrollments' => (int)($row['enrollments'] ?? 0),
        'new_users'   => (int)($row['new_users']   ?? 0),
    ],
]);
