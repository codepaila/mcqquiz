<?php
/**
 * GET /api/dashboard
 * Aggregate dashboard data for the current user: streak, recent attempts,
 * subject performance, etc. Used by the dashboard page.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\User;

Request::requireMethod('GET');
$me = Auth::require();

$pdo = Database::pdo();

// 1. Top-line numbers
$totals = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_attempts,
        SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
        AVG(CASE WHEN status='COMPLETED' THEN percentage END) AS avg_pct,
        SUM(time_spent_sec) AS total_time
       FROM quiz_set_attempts WHERE user_id = ?"
);
$totals->execute([$me['id']]);
$row = $totals->fetch();

// 2. Recent 10 attempts
$recent = $pdo->prepare(
    "SELECT a.id, a.quiz_set_id, a.score, a.percentage, a.status, a.start_time, a.end_time,
            s.name AS quiz_set_name, s.mode
       FROM quiz_set_attempts a
       JOIN quiz_sets s ON s.id = a.quiz_set_id
      WHERE a.user_id = ?
      ORDER BY a.start_time DESC LIMIT 10"
);
$recent->execute([$me['id']]);

// 3. Daily streak — last 30 days
$daily = $pdo->prepare(
    "SELECT date, questions_attempted, correct_answers, tests_completed, study_time_min, average_score
       FROM user_daily_stats
      WHERE user_id = ? AND date >= (CURRENT_DATE - INTERVAL 30 DAY)
      ORDER BY date ASC"
);
$daily->execute([$me['id']]);

// 4. Subject performance — join responses → quizzes → subjects
$bySubject = $pdo->prepare(
    "SELECT sj.id AS subject_id, sj.name AS subject_name,
            COUNT(r.id) AS attempted,
            SUM(CASE WHEN r.is_correct = 1 THEN 1 ELSE 0 END) AS correct
       FROM quiz_set_responses r
       JOIN quiz_set_attempts a ON a.id = r.attempt_id
       JOIN quizzes q ON q.id = r.quiz_id
       JOIN subjects sj ON sj.id = q.subject_id
      WHERE a.user_id = ?
      GROUP BY sj.id, sj.name
      ORDER BY attempted DESC LIMIT 12"
);
$bySubject->execute([$me['id']]);

Response::ok([
    'user' => User::publicShape($me),
    'totals' => [
        'attempts'  => (int)($row['total_attempts'] ?? 0),
        'completed' => (int)($row['completed'] ?? 0),
        'avgPct'    => $row['avg_pct'] !== null ? (float)$row['avg_pct'] : null,
        'studySec'  => (int)($row['total_time'] ?? 0),
    ],
    'recentAttempts'    => $recent->fetchAll(),
    'dailyStats'        => $daily->fetchAll(),
    'subjectPerformance' => array_map(function ($r) {
        $att = (int)$r['attempted'];
        $cor = (int)$r['correct'];
        return [
            'subjectId'   => $r['subject_id'],
            'subjectName' => $r['subject_name'],
            'attempted'   => $att,
            'correct'     => $cor,
            'accuracy'    => $att > 0 ? round(($cor / $att) * 100, 2) : 0,
        ];
    }, $bySubject->fetchAll()),
]);
