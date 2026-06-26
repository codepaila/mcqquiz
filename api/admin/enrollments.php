<?php
/**
 * Admin · Course Enrollments
 *
 *   GET  /api/admin/enrollments?status=PENDING&course_id=...&page=1&per_page=30
 *        Paginated list with joined user + course info.
 *
 *   POST /api/admin/enrollments
 *        Review an enrollment request.
 *        Body: { id, action: APPROVE|REJECT|CANCEL|SUSPEND|ACTIVATE,
 *                validDays?: int, note?: string }
 *
 *        APPROVE  → status ACTIVE, approved_at=NOW, starts_at=NOW,
 *                   expires_at = NOW + validDays (or NULL = no expiry)
 *        REJECT   → status REJECTED, rejected_at=NOW
 *        CANCEL   → status CANCELLED
 *        SUSPEND  → status SUSPENDED
 *        ACTIVATE → status ACTIVE (re-activate a suspended/expired one)
 *
 * Also creates a NOTIFICATION for the learner + an AUDIT_LOG entry.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Database;
use Quiznosis\Models\Enrollment;
use Quiznosis\Models\Notification;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $perPage = max(1, min(100, (int)Request::query('per_page', 30)));
    $page    = max(1, (int)Request::query('page', 1));
    $offset  = ($page - 1) * $perPage;

    $where = ' WHERE 1=1';
    $params = [];
    if ($s = Request::query('status'))    { $where .= ' AND e.status = ?';    $params[] = $s; }
    if ($c = Request::query('course_id')) { $where .= ' AND e.course_id = ?'; $params[] = $c; }
    if ($u = Request::query('user_id'))   { $where .= ' AND e.user_id = ?';   $params[] = $u; }

    $countStmt = Database::pdo()->prepare("SELECT COUNT(*) c FROM enrollments e" . $where);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];

    $sql = "SELECT e.*,
                   u.email AS user_email, u.first_name AS user_first_name, u.last_name AS user_last_name,
                   c.title AS course_title, c.slug AS course_slug,
                   csp.name AS plan_name
              FROM enrollments e
              JOIN users u   ON u.id = e.user_id
              JOIN courses c ON c.id = e.course_id
              LEFT JOIN course_subscription_plans csp ON csp.id = e.course_subscription_plan_id"
         . $where
         . " ORDER BY e.requested_at DESC LIMIT ? OFFSET ?";
    $stmt = Database::pdo()->prepare($sql);
    $i = 1;
    foreach ($params as $p) $stmt->bindValue($i++, $p);
    $stmt->bindValue($i++, $perPage, \PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, \PDO::PARAM_INT);
    $stmt->execute();

    Response::ok([
        'data'       => $stmt->fetchAll(),
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
    $valid = ['APPROVE', 'REJECT', 'CANCEL', 'SUSPEND', 'ACTIVATE'];
    if (!in_array($action, $valid, true)) {
        Response::error('action must be one of: ' . implode(', ', $valid), 400);
    }

    $enr = Enrollment::findById($id);
    if (!$enr) Response::notFound('Enrollment not found');

    $now = date('Y-m-d H:i:s');
    $patch = ['admin_note' => $note];
    $title = $msg = '';
    $notifType = 'COURSE_ACCESS_APPROVED';

    switch ($action) {
        case 'APPROVE':
            $patch['status']      = 'ACTIVE';
            $patch['approved_at'] = $now;
            $patch['starts_at']   = $now;
            if (!empty($body['validDays'])) {
                $days = (int)$body['validDays'];
                if ($days > 0) $patch['expires_at'] = date('Y-m-d H:i:s', strtotime("+{$days} days"));
            } else {
                $patch['expires_at'] = null;
            }
            $title = 'Enrollment approved';
            $msg   = 'Your course enrollment has been approved. You now have access.';
            $notifType = 'COURSE_ACCESS_APPROVED';
            break;
        case 'REJECT':
            $patch['status']      = 'REJECTED';
            $patch['rejected_at'] = $now;
            $title = 'Enrollment rejected';
            $msg   = 'Your course enrollment request was rejected.' . ($note ? ' Reason: ' . $note : '');
            $notifType = 'COURSE_ACCESS_REJECTED';
            break;
        case 'CANCEL':
            $patch['status'] = 'CANCELLED';
            $title = 'Enrollment cancelled';
            $msg   = 'Your course enrollment was cancelled.' . ($note ? ' ' . $note : '');
            $notifType = 'COURSE_ACCESS_REJECTED';
            break;
        case 'SUSPEND':
            $patch['status'] = 'SUSPENDED';
            $title = 'Enrollment suspended';
            $msg   = 'Your course enrollment has been suspended.' . ($note ? ' ' . $note : '');
            $notifType = 'COURSE_ACCESS_REJECTED';
            break;
        case 'ACTIVATE':
            $patch['status']    = 'ACTIVE';
            $patch['starts_at'] = $enr['starts_at'] ?: $now;
            $title = 'Enrollment activated';
            $msg   = 'Your course enrollment is now active.';
            $notifType = 'COURSE_ACCESS_APPROVED';
            break;
    }

    $updated = Enrollment::update($id, $patch);

    try {
        Notification::create([
            'user_id' => $enr['user_id'],
            'type'    => $notifType,
            'title'   => $title,
            'message' => $msg,
            'status'  => 'UNREAD',
            'sent_at' => $now,
        ]);
    } catch (\Throwable $e) { /* swallow */ }

    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'ENROLLMENT_' . $action,
        'entity_type' => 'ENROLLMENT',
        'entity_id'   => $id,
        'details'     => $patch,
    ]);

    Response::ok(['data' => $updated]);
}

Response::error('Method not allowed', 405);
