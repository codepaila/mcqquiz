<?php
/**
 * GET /api/me/attempts — Paged list of the current user's quiz attempts.
 *
 * Query params:
 *   page     int, 1-indexed (default 1)
 *   pageSize int, 1..50 (default 20)
 *   status   optional filter: COMPLETED | IN_PROGRESS | PAUSED | TIMED_OUT | ABANDONED
 *
 * Response shape:
 *   { success: true, data: { items: [...], total: N, page, pageSize } }
 *
 * Each item carries the quiz set name + mode so the frontend can render
 * a row without extra lookups. `start_time` is the de-facto sort key.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

Request::requireMethod('GET');
$me = Auth::require();
$pdo = Database::pdo();

$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)($_GET['pageSize'] ?? 20);
$pageSize = max(1, min(50, $pageSize));
$offset   = ($page - 1) * $pageSize;

$status = $_GET['status'] ?? null;
$allowedStatus = ['COMPLETED','IN_PROGRESS','PAUSED','TIMED_OUT','ABANDONED'];
if ($status !== null && !in_array($status, $allowedStatus, true)) $status = null;

$where  = 'a.user_id = ?';
$params = [$me['id']];
if ($status) { $where .= ' AND a.status = ?'; $params[] = $status; }

// Total count (for paging meta)
$cnt = $pdo->prepare("SELECT COUNT(*) FROM quiz_set_attempts a WHERE $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

// Page of attempts. Joins quiz_sets for name + mode + total_points.
$sql = "SELECT a.id, a.quiz_set_id, a.start_time, a.end_time, a.time_spent_sec,
               a.score, a.total_points, a.percentage, a.passed, a.status,
               a.attempt_number,
               s.name AS quiz_set_name, s.mode AS quiz_set_mode,
               s.total_questions
          FROM quiz_set_attempts a
          JOIN quiz_sets s ON s.id = a.quiz_set_id
         WHERE $where
         ORDER BY a.start_time DESC
         LIMIT $pageSize OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

Response::ok(['data' => [
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'pageSize' => $pageSize,
]]);
