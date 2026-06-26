<?php
/**
 * GET /api/enrollments/mine?status=ACTIVE — the current user's course enrollments,
 * with joined course info. Most recent first.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

$me = Auth::require();
Request::requireMethod('GET');

$sql = "SELECT e.*, c.title AS course_title, c.slug AS course_slug,
               c.description AS course_description, c.access_type
          FROM enrollments e
          JOIN courses c ON c.id = e.course_id
         WHERE e.user_id = ?";
$params = [$me['id']];
if ($status = Request::query('status')) {
    $sql .= ' AND e.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY e.requested_at DESC';

$stmt = Database::pdo()->prepare($sql);
$stmt->execute($params);
Response::ok(['data' => $stmt->fetchAll()]);
