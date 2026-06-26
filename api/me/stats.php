<?php
/**
 * GET /api/me/stats — Aggregate statistics for the current user.
 *
 * Returns everything the standalone Statistics page needs in one shot:
 *   - Top-line totals (attempts, completed, avg %, study time, best %)
 *   - Last 60 days daily activity (for the trend chart)
 *   - Per-subject performance (top 20)
 *   - Mode breakdown (timed vs practice counts)
 *   - Pass/fail count for completed attempts
 *
 * Reusable across the dashboard widget AND the dedicated stats page.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

Request::requireMethod('GET');
$me  = Auth::require();
$pdo = Database::pdo();

// 1. Top-line totals across ALL attempts (including in-progress).
//    `best_pct` is the highest score on a completed attempt; null if none.
$totalsStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_attempts,
        SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='COMPLETED' AND passed=1 THEN 1 ELSE 0 END) AS passed_cnt,
        AVG(CASE WHEN status='COMPLETED' THEN percentage END) AS avg_pct,
        MAX(CASE WHEN status='COMPLETED' THEN percentage END) AS best_pct,
        SUM(time_spent_sec) AS total_time
       FROM quiz_set_attempts WHERE user_id = ?"
);
$totalsStmt->execute([$me['id']]);
$row = $totalsStmt->fetch();

// 2. Last 60 days of daily activity, gap-filled client-side (sparse rows
//    are fine — the chart maps date -> value).
$daily = $pdo->prepare(
    "SELECT date, questions_attempted, correct_answers, tests_completed,
            study_time_min, average_score
       FROM user_daily_stats
      WHERE user_id = ? AND date >= (CURRENT_DATE - INTERVAL 60 DAY)
      ORDER BY date ASC"
);
$daily->execute([$me['id']]);

// 3. Per-subject performance (top 20 by # attempted).
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
      ORDER BY attempted DESC LIMIT 20"
);
$bySubject->execute([$me['id']]);

// 4. Breakdown by quiz set mode (MODEL_TEST vs PRACTICE).
$byMode = $pdo->prepare(
    "SELECT s.mode AS mode, COUNT(a.id) AS cnt,
            AVG(CASE WHEN a.status='COMPLETED' THEN a.percentage END) AS avg_pct
       FROM quiz_set_attempts a
       JOIN quiz_sets s ON s.id = a.quiz_set_id
      WHERE a.user_id = ?
      GROUP BY s.mode"
);
$byMode->execute([$me['id']]);

Response::ok([
    'data' => [
        'totals' => [
            'attempts'  => (int)($row['total_attempts'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'passed'    => (int)($row['passed_cnt'] ?? 0),
            'avgPct'    => $row['avg_pct']  !== null ? (float)$row['avg_pct']  : null,
            'bestPct'   => $row['best_pct'] !== null ? (float)$row['best_pct'] : null,
            'studySec'  => (int)($row['total_time'] ?? 0),
        ],
        'dailyStats'         => $daily->fetchAll(),
        'subjectPerformance' => array_map(function ($r) {
            $att = (int)$r['attempted']; $cor = (int)$r['correct'];
            return [
                'subjectId'   => $r['subject_id'],
                'subjectName' => $r['subject_name'],
                'attempted'   => $att,
                'correct'     => $cor,
                'accuracy'    => $att > 0 ? round(($cor / $att) * 100, 2) : 0,
            ];
        }, $bySubject->fetchAll()),
        'modeBreakdown' => $byMode->fetchAll(),
    ],
]);
