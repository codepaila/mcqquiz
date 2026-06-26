<?php
/**
 * Admin · Purchases / Quiz Sets Requests
 *
 *   GET  /api/admin/purchases?status=PENDING&type=QUIZ_SET&page=1&per_page=30
 *        Returns paginated purchases with joined user/quiz_set/plan info.
 *
 *   POST /api/admin/purchases
 *        Review a purchase request.
 *        Body: { id, action: APPROVE|REJECT|REFUND, validDays?: int, note?: string }
 *
 *        APPROVE → status COMPLETED, is_active=1, valid_from=NOW,
 *                  valid_until = NOW + validDays (or NULL for permanent)
 *        REJECT  → status FAILED,    is_active=0
 *        REFUND  → status REFUNDED,  is_active=0
 *
 *        A NOTIFICATION row is created for the user, and an AUDIT_LOG entry is written.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Models\Purchase;
use Quiznosis\Models\Notification;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $filters = [
        'status'  => Request::query('status'),
        'type'    => Request::query('type'),
        'user_id' => Request::query('user_id'),
    ];
    $perPage = max(1, min(100, (int)(Request::query('per_page', 30))));
    $page    = max(1, (int)(Request::query('page', 1)));
    $offset  = ($page - 1) * $perPage;

    $rows  = Purchase::listForAdmin($filters, $perPage, $offset);
    $total = Purchase::countForAdmin($filters);

    Response::ok([
        'data'       => $rows,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $perPage),
        ],
    ]);
}

if ($method === 'POST') {
    $body   = Request::body();
    $id     = (string)($body['id'] ?? '');
    $action = strtoupper((string)($body['action'] ?? ''));
    $note   = isset($body['note']) ? trim((string)$body['note']) : null;

    if ($id === '') Response::error('id is required', 400);
    if (!in_array($action, ['APPROVE', 'REJECT', 'REFUND'], true)) {
        Response::error('action must be APPROVE, REJECT, or REFUND', 400);
    }

    $purchase = Purchase::findById($id);
    if (!$purchase) Response::notFound('Purchase not found');

    $patch = [
        'reviewed_by_id' => $me['id'],
        'reviewed_at'    => date('Y-m-d H:i:s'),
        'review_note'    => $note,
    ];
    $notifTitle = '';
    $notifMsg   = '';

    if ($action === 'APPROVE') {
        $patch['status']     = 'COMPLETED';
        $patch['is_active']  = 1;
        $patch['valid_from'] = date('Y-m-d H:i:s');

        // Default expiry: use admin-supplied validDays if provided, otherwise
        // fall back to the quiz set's own access_days (admin-configurable per
        // set). 0 means "never expires".
        $days = isset($body['validDays']) ? (int)$body['validDays'] : null;
        if ($days === null && $purchase['type'] === 'QUIZ_SET' && !empty($purchase['quiz_set_id'])) {
            $set = \Quiznosis\Models\QuizSet::findById($purchase['quiz_set_id']);
            if ($set && isset($set['access_days'])) $days = (int)$set['access_days'];
        }
        if ($days !== null && $days > 0) {
            $patch['valid_until'] = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        } else {
            $patch['valid_until'] = null;
        }

        $notifTitle = 'Purchase approved';
        $notifMsg   = 'Your purchase request has been approved. You now have access.';
    } elseif ($action === 'REJECT') {
        $patch['status']    = 'FAILED';
        $patch['is_active'] = 0;
        $notifTitle = 'Purchase rejected';
        $notifMsg   = 'Your purchase request was rejected.' . ($note ? ' Reason: ' . $note : '');
    } else { // REFUND
        $patch['status']    = 'REFUNDED';
        $patch['is_active'] = 0;
        $notifTitle = 'Purchase refunded';
        $notifMsg   = 'Your purchase has been refunded.' . ($note ? ' Note: ' . $note : '');
    }

    $updated = Purchase::update($id, $patch);

    // ---- Course purchases: keep the linked enrollment in sync ----
    if ($purchase['type'] === 'COURSE' && !empty($purchase['course_id'])) {
        try {
            $pdo = \Quiznosis\Core\Database::pdo();
            // find the enrollment for this user + course
            $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
            $stmt->execute([$purchase['user_id'], $purchase['course_id']]);
            $enr = $stmt->fetch();
            if ($enr) {
                $now = date('Y-m-d H:i:s');
                if ($action === 'APPROVE') {
                    // expiry from the plan's duration_days, if a plan is attached
                    $expiresAt = null;
                    if (!empty($purchase['course_subscription_plan_id'])) {
                        $p = $pdo->prepare("SELECT duration_days FROM course_subscription_plans WHERE id = ?");
                        $p->execute([$purchase['course_subscription_plan_id']]);
                        $days = (int)($p->fetch()['duration_days'] ?? 0);
                        if ($days > 0) $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
                    }
                    \Quiznosis\Models\Enrollment::update($enr['id'], [
                        'status'      => 'ACTIVE',
                        'approved_at' => $now,
                        'starts_at'   => $now,
                        'expires_at'  => $expiresAt,
                    ]);
                } else { // REJECT / REFUND
                    \Quiznosis\Models\Enrollment::update($enr['id'], [
                        'status'      => 'REJECTED',
                        'rejected_at' => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) { /* swallow — purchase review still succeeds */ }
    }

    // Map action → a valid notifications.type enum value
    $notifType = ($action === 'APPROVE') ? 'PURCHASE_COMPLETED' : 'PURCHASE_FAILED';

    // Notify the user (best-effort — failure shouldn't block the review action)
    try {
        Notification::create([
            'user_id' => $purchase['user_id'],
            'type'    => $notifType,
            'title'   => $notifTitle,
            'message' => $notifMsg,
            'status'  => 'UNREAD',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { /* swallow */ }

    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PURCHASE_' . $action,
        'entity_type' => 'PURCHASE',
        'entity_id'   => $id,
        'details'     => $patch,
    ]);

    Response::ok(['data' => $updated]);
}

Response::error('Method not allowed', 405);
