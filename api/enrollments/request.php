<?php
/**
 * POST /api/enrollments/request — student requests enrollment in a course.
 * Body (JSON): { courseId, note? }
 *
 * Creates a PENDING enrollment. If the course's access type doesn't require
 * approval AND is OPEN, the enrollment is activated immediately.
 * Blocks if the user already has a non-rejected enrollment for the course.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\Course;
use Quiznosis\Models\Enrollment;
use Quiznosis\Models\Notification;
use Quiznosis\Models\User;

$me = Auth::require();
Request::requireMethod('POST');

$body     = Request::body();
$courseId = (string)($body['courseId'] ?? '');
$note     = isset($body['note']) ? trim((string)$body['note']) : null;

if ($courseId === '') Response::error('courseId is required', 400);

$course = Course::findById($courseId);
if (!$course || (int)$course['is_public'] !== 1) Response::notFound('Course not found');

// Already enrolled / requested?
$pdo = Database::pdo();
$stmt = $pdo->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
$stmt->execute([$me['id'], $courseId]);
$existing = $stmt->fetch();
if ($existing) {
    $st = $existing['status'];
    if (in_array($st, ['PENDING', 'APPROVED', 'ACTIVE'], true)) {
        Response::error('You already have a ' . strtolower($st) . ' enrollment for this course.', 409, [
            'code'   => 'ALREADY_ENROLLED',
            'status' => $st,
        ]);
    }
    // REJECTED / CANCELLED / EXPIRED — allow a fresh request by reusing the row
}

$now           = date('Y-m-d H:i:s');
$needsApproval = (int)$course['requires_approval'] === 1 || $course['access_type'] !== 'OPEN';
$status        = $needsApproval ? 'PENDING' : 'ACTIVE';

$payload = [
    'user_id'           => $me['id'],
    'course_id'         => $courseId,
    'status'            => $status,
    'request_note'      => $note,
    'requested_at'      => $now,
    'requires_approval' => $needsApproval ? 1 : 0,
    'access_type'       => $course['access_type'],
];
if ($status === 'ACTIVE') {
    $payload['approved_at'] = $now;
    $payload['starts_at']   = $now;
}

if ($existing) {
    $enrollment = Enrollment::update($existing['id'], $payload);
} else {
    $enrollment = Enrollment::create($payload);
}

// Notify admins of a new pending request
if ($status === 'PENDING') {
    try {
        $admins = User::where(['role' => 'ADMIN'], ['limit' => 50]);
        $who = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['email'];
        foreach ($admins as $a) {
            Notification::create([
                'user_id' => $a['id'],
                'type'    => 'ADMIN_NOTIFICATION',
                'title'   => 'New course enrollment request',
                'message' => sprintf('%s requested enrollment in "%s".', $who, $course['title']),
                'status'  => 'UNREAD',
                'sent_at' => $now,
            ]);
        }
    } catch (\Throwable $e) { /* swallow */ }
}

Response::created([
    'data'    => $enrollment,
    'message' => $status === 'ACTIVE'
        ? 'You are enrolled — enjoy the course!'
        : 'Enrollment requested. An admin will review it shortly.',
]);
