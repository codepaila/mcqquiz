<?php
/**
 * GET /api/course-plans?course_id=...  — public list of a course's ACTIVE plans.
 * Used on the course detail page so students can pick a plan to subscribe.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;

Request::requireMethod('GET');

$courseId = Request::query('course_id');
if (!$courseId) Response::error('course_id is required', 400);

$stmt = Database::pdo()->prepare(
    "SELECT id, course_id, name, description, duration_days, price, currency,
            original_price, is_popular, `order`, features
       FROM course_subscription_plans
      WHERE course_id = ? AND is_active = 1
      ORDER BY `order`, price"
);
$stmt->execute([$courseId]);
$rows = $stmt->fetchAll();

// Decode the features JSON column for each row
foreach ($rows as &$r) {
    $r['features'] = $r['features'] ? (json_decode($r['features'], true) ?: []) : [];
}
unset($r);

Response::ok(['data' => $rows]);
