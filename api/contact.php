<?php
/**
 * Contact Messages API
 *
 * Public:
 *   POST /api/contact          — submit a contact message (no auth required)
 *
 * Admin only:
 *   GET  /api/contact          — list messages with filters & pagination
 *   POST /api/contact          — admin actions: { id, action: 'reply'|'archive'|'trash'|'unread' }
 *   DELETE /api/contact?id=…   — permanently delete a message
 *
 * Table: contact_messages
 *   id, name, email, phone, subject, message,
 *   status (UNREAD|READ|REPLIED|ARCHIVED),
 *   reply_text, replied_at, replied_by_id,
 *   ip_address, user_agent, created_at
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;

$method = Request::method();
$pdo    = Database::pdo();

/* ──────────────────────────────────────────────────────────
   Public POST — submit a contact form message
   ────────────────────────────────────────────────────────── */
if ($method === 'POST' && !isset(Request::body()['action']) && !isset(Request::body()['id'])) {
    // Only treat as public submission if no 'id' or 'action' field in body
    $body = Request::body();

    $v = new Validator($body);
    $v->required('name');
    $v->required('email')->email('email');
    $v->required('subject');
    $v->required('message');
    if ($v->fails()) Response::error($v->firstError(), 422);

    $id = \Quiznosis\Core\Util::objectId();

    $stmt = $pdo->prepare(
        "INSERT INTO contact_messages
            (id, name, email, phone, subject, message, status, ip_address, user_agent, created_at)
         VALUES
            (?, ?, ?, ?, ?, ?, 'UNREAD', ?, ?, NOW())"
    );
    $stmt->execute([
        $id,
        trim($body['name']),
        strtolower(trim($body['email'])),
        trim($body['phone'] ?? ''),
        trim($body['subject']),
        trim($body['message']),
        $_SERVER['REMOTE_ADDR'] ?? '',
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    // Notify all active admins (best-effort)
    try {
        $admins = $pdo->query("SELECT id FROM users WHERE role='ADMIN' AND status='ACTIVE'")->fetchAll();
        foreach ($admins as $adm) {
            $nid = \Quiznosis\Core\Util::objectId();
            $pdo->prepare(
                "INSERT INTO notifications (id, user_id, type, status, title, message, data, sent_at, created_at, updated_at)
                 VALUES (?, ?, 'ADMIN_NOTIFICATION', 'UNREAD', ?, ?, ?, NOW(3), NOW(3), NOW(3))"
            )->execute([
                $nid,
                $adm['id'],
                'New contact: ' . mb_substr(trim($body['name']), 0, 60),
                mb_substr(trim($body['message']), 0, 200),
                json_encode(['contact_id' => $id, 'subject' => trim($body['subject'])]),
            ]);
        }
    } catch (\Throwable $e) { /* swallow */ }

    Response::ok(['message' => "Message received. We'll be in touch soon.", 'id' => $id, 'success' => true], 201);
}

/* ──────────────────────────────────────────────────────────
   Admin-only routes below — require ADMIN role
   ────────────────────────────────────────────────────────── */
$me = Auth::requireAdmin();

/* ---------- LIST (admin) ---------- */
if ($method === 'GET') {
    $status  = Request::query('status', '');   // UNREAD|READ|REPLIED|ARCHIVED|'' for all
    $subject = Request::query('subject', '');  // subject filter slug
    $q       = trim((string)Request::query('q', ''));
    $page    = max(1, (int)Request::query('page', 1));
    $perPage = min(100, max(1, (int)Request::query('perPage', 25)));
    $offset  = ($page - 1) * $perPage;

    $where  = ["m.status != 'DELETED'"];
    $params = [];

    if ($status) {
        $where[]  = 'm.status = ?';
        $params[] = strtoupper($status);
    }
    if ($subject) {
        $where[]  = 'subject = ?';
        $params[] = $subject;
    }
    if ($q !== '') {
        $where[]  = '(name LIKE ? OR email LIKE ? OR message LIKE ?)';
        $like     = '%' . $q . '%';
        $params   = array_merge($params, [$like, $like, $like]);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $items = $pdo->prepare(
        "SELECT m.*,
                TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS replied_by_name
           FROM contact_messages m
           LEFT JOIN users u ON u.id = m.replied_by_id
         $whereSql
         ORDER BY
           CASE m.status WHEN 'UNREAD' THEN 0 WHEN 'READ' THEN 1 ELSE 2 END,
           m.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $items->execute($params);
    $rows = $items->fetchAll();

    // Total for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages m $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Status tally for tab badges
    $tally = ['UNREAD' => 0, 'READ' => 0, 'REPLIED' => 0, 'ARCHIVED' => 0];
    foreach ($pdo->query(
        "SELECT status, COUNT(*) c FROM contact_messages WHERE status != 'DELETED' GROUP BY status"
    )->fetchAll() as $row) {
        if (isset($tally[$row['status']])) {
            $tally[$row['status']] = (int)$row['c'];
        }
    }

    Response::ok([
        'data'  => $rows,
        'tally' => $tally,
        'pagination' => [
            'page'     => $page,
            'perPage'  => $perPage,
            'total'    => $total,
        ],
    ]);
}

/* ---------- ACTIONS (admin) ---------- */
if ($method === 'POST') {
    $body   = Request::body();
    $id     = trim((string)($body['id'] ?? ''));
    $action = trim((string)($body['action'] ?? ''));

    if (!$id) Response::error('id is required', 400);

    // Fetch message
    $msg = $pdo->prepare("SELECT * FROM contact_messages WHERE id = ? AND status != 'DELETED'");
    $msg->execute([$id]);
    $msg = $msg->fetch();
    if (!$msg) Response::error('Message not found', 404);

    if ($action === 'read') {
        if ($msg['status'] === 'UNREAD') {
            $pdo->prepare("UPDATE contact_messages SET status = 'READ' WHERE id = ?")
                ->execute([$id]);
        }
        Response::ok(['message' => 'Marked as read']);
    }

    if ($action === 'unread') {
        $pdo->prepare("UPDATE contact_messages SET status = 'UNREAD' WHERE id = ?")
            ->execute([$id]);
        Response::ok(['message' => 'Marked as unread']);
    }

    if ($action === 'archive') {
        $pdo->prepare("UPDATE contact_messages SET status = 'ARCHIVED' WHERE id = ?")
            ->execute([$id]);
        Response::ok(['message' => 'Archived']);
    }

    if ($action === 'reply') {
        $replyText = trim((string)($body['replyText'] ?? ''));
        if (!$replyText) Response::error('Reply text is required', 422);

        // Mark replied
        $pdo->prepare(
            "UPDATE contact_messages
                SET status = 'REPLIED', reply_text = ?, replied_at = NOW(), replied_by_id = ?
              WHERE id = ?"
        )->execute([$replyText, $me['id'], $id]);

        // Optional: send an email here via PHP mail() or a mail library
        // mail($msg['email'], 'Re: ' . $msg['subject'], $replyText);

        Response::ok(['message' => 'Reply saved']);
    }

    if ($action === 'trash') {
        $pdo->prepare("UPDATE contact_messages SET status = 'DELETED' WHERE id = ?")
            ->execute([$id]);
        Response::ok(['message' => 'Trashed']);
    }

    Response::error('Unknown action', 400);
}

/* ---------- DELETE (admin hard delete) ---------- */
if ($method === 'DELETE') {
    $id = trim((string)Request::query('id', ''));
    if (!$id) Response::error('id is required', 400);

    $pdo->prepare("DELETE FROM contact_messages WHERE id = ?")->execute([$id]);
    Response::ok(['message' => 'Deleted permanently']);
}

Response::error('Method not allowed', 405);
