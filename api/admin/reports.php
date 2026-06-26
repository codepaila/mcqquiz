<?php
/**
 * GET  /api/admin/reports          — list with filters
 * POST /api/admin/reports          — { id, status, review_notes?, resolution_note? }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Models\QuestionReport;

$me = Auth::requireAdmin();

if (Request::method() === 'GET') {
    $pdo = \Quiznosis\Core\Database::pdo();
    $where  = [];
    $params = [];
    if ($s = Request::query('status')) { $where[] = 'r.status = ?';  $params[] = $s; }
    if ($q = Request::query('quizId')) { $where[] = 'r.quiz_id = ?'; $params[] = $q; }
    if ($qq = trim((string)Request::query('q', ''))) {
        $where[] = '(q.question LIKE ? OR r.description LIKE ?)';
        $params[] = '%' . $qq . '%';
        $params[] = '%' . $qq . '%';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $page    = max(1, (int)Request::query('page', 1));
    $perPage = min(100, max(1, (int)Request::query('per_page', 25)));
    $offset  = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT r.*,
                q.question AS quiz_question,
                q.explanation AS quiz_explanation,
                TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS reporter_name,
                u.email AS reporter_email
           FROM question_reports r
           LEFT JOIN quizzes q ON q.id = r.quiz_id
           LEFT JOIN users u   ON u.id = r.user_id
         $whereSql
         ORDER BY r.reported_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM question_reports r
           LEFT JOIN quizzes q ON q.id = r.quiz_id
         $whereSql"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Status tally (independent of any q/status filter) — for tab badges
    $tallyStmt = $pdo->query(
        "SELECT status, COUNT(*) c FROM question_reports GROUP BY status"
    );
    $tally = ['PENDING'=>0,'UNDER_REVIEW'=>0,'RESOLVED'=>0,'REJECTED'=>0,'DUPLICATE'=>0];
    foreach ($tallyStmt->fetchAll() as $row) { $tally[$row['status']] = (int)$row['c']; }

    Response::ok([
        'data'       => $items,
        'tally'      => $tally,
        'pagination' => [
            'page' => $page, 'per_page' => $perPage, 'total' => $total,
        ],
    ]);
}

Request::requireMethod('POST');

$body = Request::body();
$id     = (string)($body['id'] ?? '');
$status = (string)($body['status'] ?? '');
if ($id === '' || $status === '') Response::error('id and status are required', 400);

$valid = ['PENDING','UNDER_REVIEW','RESOLVED','REJECTED','DUPLICATE'];
if (!in_array($status, $valid, true)) Response::error('Invalid status', 400);

$existing = QuestionReport::findById($id);
if (!$existing) Response::notFound('Report not found');

$patch = [
    'status'         => $status,
    'reviewed_by_id' => $me['id'],
    'reviewed_at'    => gmdate('Y-m-d H:i:s'),
];
if (isset($body['review_notes']))   $patch['review_notes']   = (string)$body['review_notes'];
if (isset($body['resolution_note'])) $patch['resolution_note'] = (string)$body['resolution_note'];
if (in_array($status, ['RESOLVED','REJECTED','DUPLICATE'], true)) {
    $patch['resolved_at'] = gmdate('Y-m-d H:i:s');
}
$updated = QuestionReport::update($id, $patch);

Audit::log([
    'user_id'     => $me['id'],
    'action'      => 'REPORT_REVIEWED',
    'entity_type' => 'QUIZ',
    'entity_id'   => $existing['quiz_id'],
    'details'     => ['reportId' => $id, 'status' => $status],
]);

Response::ok(['data' => $updated]);
