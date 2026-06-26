<?php
/**
 * POST /api/purchases/request — student submits a purchase request.
 *
 * Multipart form-data — ONE of:
 *   quizSetId      → buy access to a single paid quiz set
 *   coursePlanId   → subscribe to a course via one of its plans
 * Plus:
 *   transactionId  (optional, recommended)
 *   note           (optional buyer note)
 *   receipt        (file — image or PDF, up to 5MB)
 *
 * Creates a PENDING purchase an admin reviews. For a course plan it also
 * creates/updates a PENDING enrollment linked to that purchase.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Uploader;
use Quiznosis\Core\Database;
use Quiznosis\Models\Purchase;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\Course;
use Quiznosis\Models\CourseSubscriptionPlan;
use Quiznosis\Models\Enrollment;
use Quiznosis\Models\Notification;
use Quiznosis\Models\User;

$me = Auth::require();
Request::requireMethod('POST');

$quizSetId    = isset($_POST['quizSetId'])    ? trim((string)$_POST['quizSetId'])    : '';
$coursePlanId = isset($_POST['coursePlanId']) ? trim((string)$_POST['coursePlanId']) : '';

if ($quizSetId === '' && $coursePlanId === '') {
    Response::error('Provide either quizSetId or coursePlanId', 400);
}
if ($quizSetId !== '' && $coursePlanId !== '') {
    Response::error('Provide only one of quizSetId or coursePlanId', 400);
}

$txnId = isset($_POST['transactionId']) ? trim((string)$_POST['transactionId']) : null;
if ($txnId === '') $txnId = null;
$note  = isset($_POST['note']) ? trim((string)$_POST['note']) : null;

// Shared receipt upload
$receiptUrl = null;
if (!empty($_FILES['receipt']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $receiptUrl = Uploader::save($_FILES['receipt'], 'receipts');
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 400);
    }
}
$now = date('Y-m-d H:i:s');

/* ================= QUIZ SET PURCHASE ================= */
if ($quizSetId !== '') {
    $set = QuizSet::findById($quizSetId);
    if (!$set) Response::notFound('Quiz set not found');
    if ((int)$set['is_paid'] !== 1) {
        Response::error('This quiz set is free — no purchase needed.', 400);
    }
    if (Purchase::userHasQuizSetAccess($me['id'], $quizSetId)) {
        Response::error('You already have access to this quiz set.', 409);
    }
    if (Purchase::userHasPendingForQuizSet($me['id'], $quizSetId)) {
        Response::error('You already have a pending request for this quiz set.', 409, ['code' => 'PENDING_EXISTS']);
    }

    $row = Purchase::create([
        'user_id'        => $me['id'],
        'type'           => 'QUIZ_SET',
        'status'         => 'PENDING',
        'quiz_set_id'    => $quizSetId,
        'amount'         => $set['price'] ?? 0,
        'currency'       => $set['currency'] ?? 'NPR',
        'payment_method' => 'MANUAL',
        'transaction_id' => $txnId,
        'receipt_url'    => $receiptUrl,
        'receipt_notes'  => $note,
        'metadata'       => ['source' => 'manual_request'],
        'is_active'      => 0,
        'valid_from'     => $now,
    ]);
    notifyAdmins($me, 'New quiz set purchase request', sprintf('%s requested "%s".', whoIs($me), $set['name']));
    Response::created(['data' => $row]);
}

/* ================= COURSE PLAN PURCHASE ================= */
$plan = CourseSubscriptionPlan::findById($coursePlanId);
if (!$plan || (int)$plan['is_active'] !== 1) Response::notFound('Course plan not found');

$course = Course::findById($plan['course_id']);
if (!$course) Response::notFound('Course not found');

// Already actively enrolled?
$pdo = Database::pdo();
$stmt = $pdo->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
$stmt->execute([$me['id'], $course['id']]);
$enrollment = $stmt->fetch() ?: null;
if ($enrollment && in_array($enrollment['status'], ['ACTIVE', 'APPROVED'], true)) {
    Response::error('You are already enrolled in this course.', 409, ['code' => 'ALREADY_ENROLLED']);
}

// Pending purchase already?
$stmt = $pdo->prepare(
    "SELECT COUNT(*) c FROM purchases
      WHERE user_id = ? AND course_subscription_plan_id = ? AND status = 'PENDING'"
);
$stmt->execute([$me['id'], $coursePlanId]);
if ((int)$stmt->fetch()['c'] > 0) {
    Response::error('You already have a pending request for this plan.', 409, ['code' => 'PENDING_EXISTS']);
}

$purchase = Purchase::create([
    'user_id'                     => $me['id'],
    'type'                        => 'COURSE',
    'status'                      => 'PENDING',
    'course_id'                   => $course['id'],
    'course_subscription_plan_id' => $coursePlanId,
    'amount'                      => $plan['price'] ?? 0,
    'currency'                    => $plan['currency'] ?? 'NPR',
    'payment_method'              => 'MANUAL',
    'transaction_id'              => $txnId,
    'receipt_url'                 => $receiptUrl,
    'receipt_notes'               => $note,
    'metadata'                    => ['source' => 'manual_request', 'plan_name' => $plan['name']],
    'is_active'                   => 0,
    'valid_from'                  => $now,
]);

// Create / refresh a PENDING enrollment linked to this purchase
$enrollPayload = [
    'user_id'                     => $me['id'],
    'course_id'                   => $course['id'],
    'status'                      => 'PENDING',
    'request_note'                => $note,
    'requested_at'                => $now,
    'requires_approval'           => 1,
    'access_type'                 => 'SUBSCRIPTION',
    'purchase_id'                 => $purchase['id'],
    'course_subscription_plan_id' => $coursePlanId,
];
if ($enrollment) {
    Enrollment::update($enrollment['id'], $enrollPayload);
} else {
    Enrollment::create($enrollPayload);
}

notifyAdmins($me, 'New course subscription request',
    sprintf('%s subscribed to "%s" (%s plan).', whoIs($me), $course['title'], $plan['name']));

Response::created([
    'data'    => $purchase,
    'message' => 'Subscription requested. An admin will verify your payment and approve access.',
]);

/* ---- helpers ---- */
function whoIs(array $u): string
{
    return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['email'];
}
function notifyAdmins(array $me, string $title, string $message): void
{
    try {
        $admins = User::where(['role' => 'ADMIN'], ['limit' => 50]);
        foreach ($admins as $a) {
            Notification::create([
                'user_id' => $a['id'],
                'type'    => 'ADMIN_NOTIFICATION',
                'title'   => $title,
                'message' => $message,
                'status'  => 'UNREAD',
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Throwable $e) { /* swallow */ }
}
