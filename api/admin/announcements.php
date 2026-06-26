<?php
/**
 * Admin · Announcements
 *
 *   GET    /api/admin/announcements              — list all
 *   POST   /api/admin/announcements              — create (DRAFT or PUBLISHED)
 *   PATCH  /api/admin/announcements              — update { id, ...fields }
 *   DELETE /api/admin/announcements?id=...        — delete
 *   POST   /api/admin/announcements  { id, action:"publish" }  — publish a draft
 *
 * Body fields:
 *   title       (required)
 *   body        (required)
 *   audience    GLOBAL | COURSE   (default GLOBAL)
 *   courseId    (required when audience=COURSE)
 *   pinned      (bool)
 *   publish     (bool — if true on create, publish immediately)
 *
 * Publishing an announcement fans out a notification to every target user
 * (all users for GLOBAL, enrolled students for COURSE) so the bell lights up.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\Announcement;
use Quiznosis\Models\Course;
use Quiznosis\Models\Notification;

$me = Auth::requireAdmin();
$method = Request::method();
$pdo = Database::pdo();

/* ---------- LIST ---------- */
if ($method === 'GET') {
    $rows = $pdo->query(
        "SELECT a.*, c.title AS course_title,
                (u.first_name) AS author_first, (u.last_name) AS author_last
           FROM announcements a
           LEFT JOIN courses c ON c.id = a.course_id
           LEFT JOIN users   u ON u.id = a.created_by_id
          ORDER BY a.created_at DESC"
    )->fetchAll();
    Response::ok(['data' => $rows]);
}

/* ---------- CREATE ---------- */
if ($method === 'POST' && Request::input('action') === null) {
    $body = Request::body();
    Validator::make($body)->required('title')->required('body')->abortIfFails();

    $audience = strtoupper((string)($body['audience'] ?? 'GLOBAL'));
    if (!in_array($audience, ['GLOBAL', 'COURSE'], true)) {
        Response::error('audience must be GLOBAL or COURSE', 400);
    }
    $courseId = null;
    if ($audience === 'COURSE') {
        $courseId = (string)($body['courseId'] ?? '');
        if ($courseId === '' || !Course::findById($courseId)) {
            Response::error('A valid courseId is required for a course announcement', 400);
        }
    }

    $publishNow = !empty($body['publish']);
    $row = Announcement::create([
        'title'         => trim((string)$body['title']),
        'body'          => trim((string)$body['body']),
        'audience'      => $audience,
        'course_id'     => $courseId,
        'status'        => $publishNow ? 'PUBLISHED' : 'DRAFT',
        'pinned'        => !empty($body['pinned']) ? 1 : 0,
        'created_by_id' => $me['id'],
        'published_at'  => $publishNow ? date('Y-m-d H:i:s') : null,
    ]);

    if ($publishNow) {
        $sent = fanOutNotifications($row);
        $row['notified'] = $sent;
    }

    Audit::log([
        'user_id' => $me['id'], 'action' => 'ANNOUNCEMENT_CREATED',
        'entity_type' => 'ANNOUNCEMENT', 'entity_id' => $row['id'],
        'details' => ['published' => $publishNow],
    ]);
    Response::created(['data' => $row]);
}

/* ---------- PUBLISH a draft ---------- */
if ($method === 'POST' && Request::input('action') === 'publish') {
    $id = (string)Request::input('id', '');
    $a = $id !== '' ? Announcement::findById($id) : null;
    if (!$a) Response::notFound('Announcement not found');
    if ($a['status'] === 'PUBLISHED') {
        Response::error('Already published', 409);
    }
    $updated = Announcement::update($id, [
        'status'       => 'PUBLISHED',
        'published_at' => date('Y-m-d H:i:s'),
    ]);
    $sent = fanOutNotifications($updated);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'ANNOUNCEMENT_PUBLISHED',
        'entity_type' => 'ANNOUNCEMENT', 'entity_id' => $id,
        'details' => ['notified' => $sent],
    ]);
    Response::ok(['data' => $updated, 'notified' => $sent]);
}

/* ---------- UPDATE ---------- */
if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '' || !Announcement::findById($id)) Response::notFound('Announcement not found');

    $patch = [];
    if (array_key_exists('title', $body))  $patch['title']  = trim((string)$body['title']);
    if (array_key_exists('body', $body))   $patch['body']   = trim((string)$body['body']);
    if (array_key_exists('pinned', $body)) $patch['pinned'] = !empty($body['pinned']) ? 1 : 0;
    if (array_key_exists('audience', $body)) {
        $aud = strtoupper((string)$body['audience']);
        if (!in_array($aud, ['GLOBAL', 'COURSE'], true)) Response::error('Invalid audience', 400);
        $patch['audience'] = $aud;
        if ($aud === 'COURSE') {
            $cid = (string)($body['courseId'] ?? '');
            if ($cid === '' || !Course::findById($cid)) Response::error('Valid courseId required', 400);
            $patch['course_id'] = $cid;
        } else {
            $patch['course_id'] = null;
        }
    }
    if (!$patch) Response::error('No fields to update', 400);

    $updated = Announcement::update($id, $patch);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'ANNOUNCEMENT_UPDATED',
        'entity_type' => 'ANNOUNCEMENT', 'entity_id' => $id,
    ]);
    Response::ok(['data' => $updated]);
}

/* ---------- DELETE ---------- */
if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '' || !Announcement::findById($id)) Response::notFound('Announcement not found');
    Announcement::deleteById($id);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'ANNOUNCEMENT_DELETED',
        'entity_type' => 'ANNOUNCEMENT', 'entity_id' => $id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);

/* ============================================================ */
/**
 * Create a notification for every user the announcement targets.
 * GLOBAL → all users. COURSE → users with an ACTIVE/APPROVED enrollment.
 * Returns how many notifications were created.
 */
function fanOutNotifications(array $a): int
{
    $pdo = \Quiznosis\Core\Database::pdo();
    if ($a['audience'] === 'COURSE' && !empty($a['course_id'])) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT user_id FROM enrollments
              WHERE course_id = ? AND status IN ('ACTIVE','APPROVED')"
        );
        $stmt->execute([$a['course_id']]);
        $userIds = array_column($stmt->fetchAll(), 'user_id');
    } else {
        $userIds = array_column(
            $pdo->query("SELECT id FROM users WHERE status = 'ACTIVE'")->fetchAll(),
            'id'
        );
    }

    $count = 0;
    foreach ($userIds as $uid) {
        try {
            \Quiznosis\Models\Notification::create([
                'user_id' => $uid,
                'type'    => 'ADMIN_NOTIFICATION',
                'status'  => 'UNREAD',
                'title'   => '📢 ' . $a['title'],
                'message' => safeTruncate(strip_tags($a['body']), 240),
                'data'    => ['announcement_id' => $a['id']],
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
            $count++;
        } catch (\Throwable $e) { /* skip one bad row, keep going */ }
    }
    return $count;
}

/**
 * Truncate a string to N chars, preferring mb_substr when the mbstring
 * extension is available, falling back to plain substr otherwise.
 */
function safeTruncate(string $text, int $len): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $len);
    }
    return substr($text, 0, $len);
}
