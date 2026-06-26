<?php
/**
 * GET   /api/admin/users          — list with filters
 * PATCH /api/admin/users          — { id, status?, role? }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Database;
use Quiznosis\Models\User;

$me = Auth::requireAdmin();

if (Request::method() === 'GET') {
    $page    = max(1, (int)Request::query('page', 1));
    $perPage = min(100, max(1, (int)Request::query('per_page', 25)));
    $offset  = ($page - 1) * $perPage;
    $q       = trim((string)Request::query('q', ''));
    $role    = Request::query('role');
    $status  = Request::query('status');

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(email LIKE :q OR first_name LIKE :q OR last_name LIKE :q)';
        $params[':q'] = "%$q%";
    }
    if ($role)   { $where[] = 'role = :r';   $params[':r'] = $role; }
    if ($status) { $where[] = 'status = :s'; $params[':s'] = $status; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $pdo = Database::pdo();
    $count = $pdo->prepare("SELECT COUNT(*) AS c FROM users $whereSql");
    $count->execute($params);
    $total = (int)$count->fetch()['c'];

    $stmt = $pdo->prepare(
        "SELECT id, email, first_name, last_name, role, status, email_verified, created_at
           FROM users $whereSql
           ORDER BY created_at DESC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    Response::ok([
        'data'       => $stmt->fetchAll(),
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
    ]);
}

Request::requireMethod('PATCH', 'POST');

$body = Request::body();
$id = (string)($body['id'] ?? '');
if ($id === '') Response::error('id is required', 400);
if ($id === $me['id']) Response::error('Cannot modify your own account here.', 400);

$user = User::findById($id);
if (!$user) Response::notFound('User not found');

$patch = [];
if (isset($body['status'])) {
    if (!in_array($body['status'], ['PENDING','ACTIVE','INACTIVE','SUSPENDED'], true)) {
        Response::error('Invalid status', 400);
    }
    $patch['status'] = $body['status'];
}
if (isset($body['role'])) {
    if (!in_array($body['role'], ['STUDENT','INSTRUCTOR','ADMIN'], true)) {
        Response::error('Invalid role', 400);
    }
    $patch['role'] = $body['role'];
}
if (!$patch) Response::error('No fields to update', 400);

$updated = User::update($id, $patch);
Audit::log([
    'user_id'     => $me['id'],
    'action'      => 'USER_UPDATED',
    'entity_type' => 'USER',
    'entity_id'   => $id,
    'details'     => $patch,
]);
Response::ok(['data' => User::publicShape($updated)]);
