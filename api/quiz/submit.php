<?php
/**
 * POST /api/quiz/submit
 * Port of src/app/api/quiz/submit/route.ts
 * Body: { attemptId }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\QuizSetAttempt;
use Quiznosis\Models\QuizSetResponse;
use Quiznosis\Models\UserQuizSetStats;

Request::requireMethod('POST');
$me = Auth::require();

$attemptId = (string)Request::input('attemptId', '');
if ($attemptId === '') Response::error('Attempt ID is required', 400);

$attempt = QuizSetAttempt::findById($attemptId);
if (!$attempt) Response::notFound('Attempt not found');
if ($attempt['user_id'] !== $me['id']) Response::forbidden();

$set = QuizSet::findById($attempt['quiz_set_id']);
if (!$set) Response::notFound('Quiz set not found');

// Already-completed early exit (mirrors Next behavior).
if ($attempt['status'] !== 'IN_PROGRESS') {
    Response::ok([
        'success'     => true,
        'message'     => 'Attempt already completed',
        'redirectUrl' => '/quiz/results/' . $attemptId,
    ]);
}

$responses = QuizSetResponse::where(['attempt_id' => $attemptId]);
$total     = QuizSet::itemCount($set['id']);
$correct   = 0;
$negTotal  = 0.0;  // sum of per-question negative_marks_deducted (honours student's session toggle)
foreach ($responses as $r) {
    if ((int)$r['is_correct'] === 1) $correct++;
    $negTotal += (float)($r['negative_marks_deducted'] ?? 0);
}

// Scoring config from quiz set
$marksPerQ   = isset($set['marks_per_question']) ? (float)$set['marks_per_question'] : 1.0;
$negMarkPerQ = isset($set['negative_mark_per_question']) ? (float)$set['negative_mark_per_question'] : 0.25;

$positiveMarks = $correct * $marksPerQ;
// Use stored per-question deductions so the student's session-level toggle is respected
$negativeMarks = $negTotal;
$finalMarks    = max(0.0, $positiveMarks - $negativeMarks);
$totalPossible = $total * $marksPerQ;

// Prefer client-reported elapsed (accurate even after pauses/resumes).
// Fall back to wall-clock only if the client didn't send it.
// Fix: use Request::input() — $body was never defined as a variable in this file.
$clientElapsedRaw = Request::input('elapsedSec');
$clientElapsed    = $clientElapsedRaw !== null ? (int)$clientElapsedRaw : null;
$wallClock        = max(0, time() - strtotime($attempt['start_time']));
$timeSpent        = ($clientElapsed !== null && $clientElapsed > 0 && $clientElapsed <= $wallClock + 60)
                    ? $clientElapsed
                    : $wallClock;

// Use the attempt's chosen_mode (set on start) so a student's pre-start mode
// choice is honoured. Falls back to the quiz set's admin mode for older
// attempts that pre-date the chosen_mode column.
$effectiveMode = $attempt['chosen_mode'] ?? null;
if (!$effectiveMode) $effectiveMode = $set['mode'];

if ($effectiveMode === 'PRACTICE') {
    // Practice attempts are also scored so they appear on the leaderboard /
    // Top Scorers, just like timed tests.
    $percentage = $totalPossible > 0 ? round(($finalMarks / $totalPossible) * 100, 3) : 0;
    $passed     = $set['passing_score'] !== null ? ($percentage >= (float)$set['passing_score']) : null;
    $scoreInt   = (int)round($finalMarks);

    Database::transaction(function () use ($attemptId, $me, $set, $timeSpent, $total, $correct, $scoreInt, $negativeMarks, $percentage, $passed) {
        QuizSetAttempt::update($attemptId, [
            'status'         => 'COMPLETED',
            'end_time'       => gmdate('Y-m-d H:i:s'),
            'time_spent_sec' => $timeSpent,
            'total_points'   => $total,
            'raw_score'      => $correct,
            'negative_marks' => $negativeMarks,
            'score'          => $scoreInt,
            'percentage'     => $percentage,
            'passed'         => $passed === null ? null : ($passed ? 1 : 0),
        ]);
        syncUserQuizSetStats($me['id'], $set['id'], $scoreInt, $percentage, $timeSpent);
    });

    Response::ok([
        'success'     => true,
        'message'     => 'Practice completed',
        'redirectUrl' => '/quiz/results/' . $attemptId,
        'score'       => $scoreInt,
        'percentage'  => $percentage,
    ]);
}

// MODEL_TEST scoring
$percentage = $totalPossible > 0 ? round(($finalMarks / $totalPossible) * 100, 3) : 0;
$passed     = $set['passing_score'] !== null ? ($percentage >= (float)$set['passing_score']) : null;
$scoreInt   = (int)round($finalMarks);

Database::transaction(function () use ($attemptId, $me, $set, $timeSpent, $total, $correct, $scoreInt, $negativeMarks, $percentage, $passed) {
    QuizSetAttempt::update($attemptId, [
        'status'         => 'COMPLETED',
        'end_time'       => gmdate('Y-m-d H:i:s'),
        'time_spent_sec' => $timeSpent,
        'total_points'   => $total,
        'raw_score'      => $correct,
        'negative_marks' => $negativeMarks,
        'score'          => $scoreInt,
        'percentage'     => $percentage,
        'passed'         => $passed === null ? null : ($passed ? 1 : 0),
    ]);

    // Maintain UserQuizSetStats roll-up.
    syncUserQuizSetStats($me['id'], $set['id'], $scoreInt, $percentage, $timeSpent);
});

Response::ok([
    'success'     => true,
    'message'     => 'Quiz submitted successfully',
    'redirectUrl' => '/quiz/results/' . $attemptId,
    'score'       => $scoreInt,
    'percentage'  => $percentage,
    'passed'      => $passed,
]);

/**
 * Insert or update the UserQuizSetStats roll-up for a finished attempt.
 * Used by BOTH practice and timed-test submissions so every completed
 * attempt counts toward the leaderboard / Top Scorers.
 *
 * NB: top-level functions do not inherit file-level `use` imports, so the
 * model class is referenced fully-qualified here.
 */
function syncUserQuizSetStats(string $userId, string $setId, int $score, float $percentage, int $timeSpent): void
{
    $Stats = \Quiznosis\Models\UserQuizSetStats::class;
    $existing = $Stats::firstWhere([
        'user_id'     => $userId,
        'quiz_set_id' => $setId,
    ]);
    $now = gmdate('Y-m-d H:i:s');
    if ($existing) {
        $newComplete = (int)$existing['completed_attempts'] + 1;
        $Stats::update($existing['id'], [
            'total_attempts'     => (int)$existing['total_attempts'] + 1,
            'completed_attempts' => $newComplete,
            'best_score'         => max((int)$existing['best_score'], $score),
            'best_percentage'    => max((float)$existing['best_percentage'], $percentage),
            'average_score'      => ((float)$existing['average_score']      * (int)$existing['completed_attempts'] + $score)      / max(1, $newComplete),
            'average_percentage' => ((float)$existing['average_percentage'] * (int)$existing['completed_attempts'] + $percentage) / max(1, $newComplete),
            'total_time_spent'   => (int)$existing['total_time_spent'] + $timeSpent,
            'last_attempt_date'  => $now,
        ]);
    } else {
        $Stats::create([
            'user_id'            => $userId,
            'quiz_set_id'        => $setId,
            'total_attempts'     => 1,
            'completed_attempts' => 1,
            'best_score'         => $score,
            'best_percentage'    => $percentage,
            'average_score'      => $score,
            'average_percentage' => $percentage,
            'total_time_spent'   => $timeSpent,
            'first_attempt_date' => $now,
            'last_attempt_date'  => $now,
        ]);
    }
}
