<?php
/**
 * GET /api/quiz/leaderboard?quizSetId=<id>&limit=20
 * Returns the top-scoring attempts on a quiz set.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;

Request::requireMethod('GET');

$setId = (string)Request::query('quizSetId', '');
if ($setId === '') Response::error('quizSetId is required', 400);
$limit = min(100, max(1, (int)Request::query('limit', 20)));

$stmt = Database::pdo()->prepare(
    "SELECT u.id AS user_id, u.first_name, u.last_name, u.avatar,
            s.best_score, s.best_percentage, s.total_attempts, s.last_attempt_date
       FROM user_quiz_set_stats s
       JOIN users u ON u.id = s.user_id
      WHERE s.quiz_set_id = ?
      ORDER BY s.best_percentage DESC, s.total_time_spent ASC
      LIMIT $limit"
);
$stmt->execute([$setId]);
$rows = $stmt->fetchAll();

// Total attempts on this set (across all users) — used by the detail page stat strip.
$tot = Database::pdo()->prepare(
    "SELECT COUNT(*) AS c FROM quiz_set_attempts WHERE quiz_set_id = ?"
);
$tot->execute([$setId]);
$totalAttempts = (int)$tot->fetch()['c'];

$leaderboard = [];
foreach ($rows as $i => $r) {
    $leaderboard[] = [
        'rank'           => $i + 1,
        'user'           => [
            'id'        => $r['user_id'],
            'firstName' => $r['first_name'],
            'lastName'  => $r['last_name'],
            'avatar'    => $r['avatar'],
        ],
        'bestScore'      => (int)$r['best_score'],
        'bestPercentage' => (float)$r['best_percentage'],
        'attempts'       => (int)$r['total_attempts'],
        'lastAttempt'    => $r['last_attempt_date'],
    ];
}

Response::ok([
    'data'          => $leaderboard,
    'totalAttempts' => $totalAttempts,
]);
