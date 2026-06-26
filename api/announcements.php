<?php
/**
 * GET /api/announcements
 *
 * Returns PUBLISHED announcements visible to the current user:
 *   - all GLOBAL announcements
 *   - COURSE announcements for courses the user is enrolled in (ACTIVE/APPROVED)
 *
 * Signed-out visitors see GLOBAL announcements only.
 * Pinned announcements sort first, then newest.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

Request::requireMethod('GET');

$me  = Auth::user();
$pdo = Database::pdo();

$courseIds = [];
if ($me) {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT course_id FROM enrollments
          WHERE user_id = ? AND status IN ('ACTIVE','APPROVED')"
    );
    $stmt->execute([$me['id']]);
    $courseIds = array_column($stmt->fetchAll(), 'course_id');
}

// Build the WHERE: GLOBAL always, plus COURSE rows for enrolled courses
$where  = ["a.status = 'PUBLISHED'"];
$cond   = ["a.audience = 'GLOBAL'"];
$params = [];
if ($courseIds) {
    $ph = implode(',', array_fill(0, count($courseIds), '?'));
    $cond[] = "(a.audience = 'COURSE' AND a.course_id IN ($ph))";
    $params = $courseIds;
}
$where[] = '(' . implode(' OR ', $cond) . ')';
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.body, a.audience, a.course_id, a.pinned, a.published_at,
            c.title AS course_title
       FROM announcements a
       LEFT JOIN courses c ON c.id = a.course_id
       $whereSql
       ORDER BY a.pinned DESC, a.published_at DESC"
);
$stmt->execute($params);

Response::ok(['data' => $stmt->fetchAll()]);
