<?php
/**
 * GET /api/admin/metrics
 * High-level platform counts for the admin dashboard.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

Auth::requireAdmin();
Request::requireMethod('GET');

$pdo = Database::pdo();
$counts = function (string $sql) use ($pdo) {
    return (int)$pdo->query($sql)->fetch()['c'];
};

$now = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS users_total,
        (SELECT COUNT(*) FROM users WHERE status='ACTIVE') AS users_active,
        (SELECT COUNT(*) FROM users WHERE created_at >= (NOW(3) - INTERVAL 7 DAY)) AS users_new_7d,
        (SELECT COUNT(*) FROM quizzes) AS quizzes,
        (SELECT COUNT(*) FROM quiz_sets WHERE status='PUBLISHED') AS quiz_sets,
        (SELECT COUNT(*) FROM quiz_set_attempts) AS attempts,
        (SELECT COUNT(*) FROM quiz_set_attempts WHERE status='COMPLETED') AS attempts_completed,
        (SELECT COUNT(*) FROM question_reports WHERE status='PENDING') AS reports_pending"
)->fetch();

Response::ok([
    'users' => [
        'total'  => (int)$now['users_total'],
        'active' => (int)$now['users_active'],
        'new7d'  => (int)$now['users_new_7d'],
    ],
    'content' => [
        'quizzes'  => (int)$now['quizzes'],
        'quizSets' => (int)$now['quiz_sets'],
    ],
    'usage' => [
        'attempts'          => (int)$now['attempts'],
        'attemptsCompleted' => (int)$now['attempts_completed'],
    ],
    'moderation' => [
        'reportsPending' => (int)$now['reports_pending'],
    ],
]);
