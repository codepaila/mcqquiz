<?php
/**
 * GET  /api/notifications        — list
 * POST /api/notifications        — { id, action: "mark_read" | "archive" }
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\Notification;

$me = Auth::require();

if (Request::method() === 'GET') {
    $status = Request::query('status');           // UNREAD / READ / ARCHIVED
    $limit  = min(100, max(1, (int)Request::query('limit', 30)));
    $where = ['user_id' => $me['id']];
    if ($status) $where['status'] = $status;
    $items = Notification::where($where, ['order' => 'sent_at DESC', 'limit' => $limit]);
    Response::ok([
        'data'        => $items,
        'unreadCount' => Notification::unreadCount($me['id']),
    ]);
}

Request::requireMethod('POST');
$id     = (string)Request::input('id', '');
$action = (string)Request::input('action', '');
if ($id === '' || $action === '') Response::error('id and action are required', 400);

$n = Notification::findById($id);
if (!$n || $n['user_id'] !== $me['id']) Response::notFound('Notification not found');

if ($action === 'mark_read') {
    Notification::markRead($id, $me['id']);
} elseif ($action === 'archive') {
    Notification::update($id, ['status' => 'ARCHIVED']);
} else {
    Response::error('Unknown action', 400);
}

Response::ok(['success' => true]);
