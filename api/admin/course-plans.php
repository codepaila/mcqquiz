<?php
/**
 * Admin · Course Plans (per-course subscription plans)
 *
 *   GET    /api/admin/course-plans?course_id=...   — list a course's plans
 *   POST   /api/admin/course-plans                 — create
 *   PATCH  /api/admin/course-plans                 — update { id, ...fields }
 *   DELETE /api/admin/course-plans?id=...          — delete
 *
 * Body fields:
 *   courseId       (required on create)
 *   name           (required)
 *   description    (optional)
 *   durationDays   (int > 0)
 *   price          (number >= 0)
 *   currency       (default NPR)
 *   originalPrice  (optional — for showing a strikethrough)
 *   isPopular      (bool)
 *   isActive       (bool, default true)
 *   order          (int)
 *   features       (array of strings)
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\CourseSubscriptionPlan;
use Quiznosis\Models\Course;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $courseId = Request::query('course_id');
    if (!$courseId) Response::error('course_id is required', 400);
    $stmt = Database::pdo()->prepare(
        "SELECT csp.*,
                (SELECT COUNT(*) FROM purchases p
                  WHERE p.course_subscription_plan_id = csp.id
                    AND p.status = 'COMPLETED' AND p.is_active = 1) AS active_purchases
           FROM course_subscription_plans csp
          WHERE csp.course_id = ?
          ORDER BY csp.`order`, csp.price"
    );
    $stmt->execute([$courseId]);
    Response::ok(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)
        ->required('courseId')
        ->required('name')
        ->required('durationDays')
        ->abortIfFails();

    $course = Course::findById((string)$body['courseId']);
    if (!$course) Response::error('courseId not found', 400);

    $duration = (int)$body['durationDays'];
    if ($duration <= 0) Response::error('durationDays must be greater than 0', 400);
    $price = isset($body['price']) ? (float)$body['price'] : 0.0;
    if ($price < 0) Response::error('price cannot be negative', 400);

    $features = $body['features'] ?? [];
    if (!is_array($features)) $features = [];

    $row = CourseSubscriptionPlan::create([
        'course_id'      => $course['id'],
        'name'           => trim((string)$body['name']),
        'description'    => $body['description'] ?? null,
        'duration_days'  => $duration,
        'price'          => $price,
        'currency'       => $body['currency'] ?? 'NPR',
        'original_price' => isset($body['originalPrice']) && $body['originalPrice'] !== ''
                              ? (float)$body['originalPrice'] : null,
        'is_popular'     => !empty($body['isPopular']) ? 1 : 0,
        'is_active'      => array_key_exists('isActive', $body) ? (!empty($body['isActive']) ? 1 : 0) : 1,
        'order'          => isset($body['order']) ? (int)$body['order'] : 0,
        'features'       => $features,
    ]);

    // Mark the course as having subscription plans
    if ((int)$course['has_subscription'] !== 1) {
        Course::update($course['id'], ['has_subscription' => 1]);
    }

    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_PLAN_CREATED',
        'entity_type'=>'COURSE_PLAN', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    if (!CourseSubscriptionPlan::findById($id)) Response::notFound('Plan not found');

    $map = [
        'name'=>'name', 'description'=>'description', 'durationDays'=>'duration_days',
        'price'=>'price', 'currency'=>'currency', 'originalPrice'=>'original_price',
        'isPopular'=>'is_popular', 'isActive'=>'is_active', 'order'=>'order', 'features'=>'features',
    ];
    $patch = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $body)) continue;
        $v = $body[$k];
        if ($k === 'durationDays') { $v = (int)$v; if ($v <= 0) Response::error('durationDays must be > 0', 400); }
        if ($k === 'price')        { $v = (float)$v; if ($v < 0) Response::error('price cannot be negative', 400); }
        if ($k === 'originalPrice') $v = ($v === '' || $v === null) ? null : (float)$v;
        if ($k === 'isPopular' || $k === 'isActive') $v = !empty($v) ? 1 : 0;
        if ($k === 'order') $v = (int)$v;
        if ($k === 'name') $v = trim((string)$v);
        if ($k === 'features' && !is_array($v)) $v = [];
        $patch[$col] = $v;
    }
    if (!$patch) Response::error('No fields to update', 400);

    $updated = CourseSubscriptionPlan::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_PLAN_UPDATED',
        'entity_type'=>'COURSE_PLAN', 'entity_id'=>$id, 'details'=>$patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    $plan = CourseSubscriptionPlan::findById($id);
    if (!$plan) Response::notFound('Plan not found');

    $stmt = Database::pdo()->prepare("SELECT COUNT(*) c FROM purchases WHERE course_subscription_plan_id = ?");
    $stmt->execute([$id]);
    $refs = (int)$stmt->fetch()['c'];
    if ($refs > 0) {
        Response::error("Cannot delete — {$refs} purchase(s) reference this plan. Deactivate it instead.", 409);
    }

    CourseSubscriptionPlan::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_PLAN_DELETED',
        'entity_type'=>'COURSE_PLAN', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
