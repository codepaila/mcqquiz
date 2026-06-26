<?php
/**
 * GET /api/quiz/results?attemptId=<id>
 * Returns attempt + per-question review + fully-computed analytics summary.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\QuizSetAttempt;
use Quiznosis\Models\QuizSetResponse;
use Quiznosis\Models\QuizSetItem;
use Quiznosis\Models\Quiz;

Request::requireMethod('GET');
$me = Auth::require();

$attemptId = (string)Request::query('attemptId', '');
if ($attemptId === '') Response::error('attemptId is required', 400);

$attempt = QuizSetAttempt::findById($attemptId);
if (!$attempt) Response::notFound('Attempt not found');
if ($attempt['user_id'] !== $me['id'] && $me['role'] !== 'ADMIN') Response::forbidden();

$set   = QuizSet::findById($attempt['quiz_set_id']);
$items = QuizSetItem::where(['quiz_set_id' => $attempt['quiz_set_id']], ['order' => '`order` ASC']);

$quizIds  = array_column($items, 'quiz_id');
$quizzes  = Quiz::loadManyWithOptions($quizIds);
$quizById = [];
foreach ($quizzes as $q) $quizById[$q['id']] = $q;

$responses  = QuizSetResponse::where(['attempt_id' => $attemptId]);
$respByQuiz = [];
foreach ($responses as $r) $respByQuiz[$r['quiz_id']] = $r;

// ── Per-question review ───────────────────────────────────────────────────────
$review  = [];
$correct = 0;
$wrong   = 0;

foreach ($items as $it) {
    $quiz = $quizById[$it['quiz_id']] ?? null;
    if (!$quiz) continue;

    $resp       = $respByQuiz[$it['quiz_id']] ?? null;
    $wasAnswered = $resp !== null && !empty($resp['selected_option_ids']);
    $isCorrect   = $resp ? (int)$resp['is_correct'] === 1 : null;

    if ($wasAnswered && $isCorrect)  $correct++;
    if ($wasAnswered && !$isCorrect) $wrong++;

    $review[] = [
        'order'       => (int)$it['order'],
        'quiz'        => $quiz,
        'response'    => $resp,
        'wasAnswered' => $wasAnswered,
        'isCorrect'   => $isCorrect,
        'timeSec'     => $resp ? (int)($resp['time_spent_sec'] ?? 0) : 0,
    ];
}

// ── Analytics calculations ────────────────────────────────────────────────────
$totalQuestions = count($items);
$attempted      = $correct + $wrong;
$skipped        = $totalQuestions - $attempted;

$marksPerQ      = isset($set['marks_per_question'])
                  ? (float)$set['marks_per_question']
                  : 1.0;
$negMarkPerQ    = (int)$set['enable_negative_marking'] === 1
                  ? (float)($set['negative_mark_per_question'] ?? 0.25)
                  : 0.0;

$positiveMarks  = $correct * $marksPerQ;
$negativeMarks  = $wrong   * $negMarkPerQ;
$finalMarks     = max(0, $positiveMarks - $negativeMarks);
$totalPossible  = $totalQuestions * $marksPerQ;

$percentage     = $totalPossible > 0
                  ? round(($finalMarks / $totalPossible) * 100, 2)
                  : 0.0;
$accuracy       = $attempted > 0
                  ? round(($correct / $attempted) * 100, 2)
                  : 0.0;

$passingScore   = $set['passing_score'] !== null ? (float)$set['passing_score'] : null;
if ($passingScore !== null) {
    $status = $percentage >= $passingScore ? 'PASS' : 'FAIL';
    $passed = $percentage >= $passingScore ? 1 : 0;
} else {
    $status = null;
    $passed = null;
}

Response::ok([
    'data' => [
        'attempt' => $attempt,
        'set'     => $set,
        'review'  => $review,
        'summary' => [
            // counts
            'total'          => $totalQuestions,
            'attempted'      => $attempted,
            'correct'        => $correct,
            'wrong'          => $wrong,
            'skipped'        => $skipped,
            // marks
            'marksPerQ'      => $marksPerQ,
            'negMarkPerQ'    => $negMarkPerQ,
            'positiveMarks'  => round($positiveMarks, 2),
            'negativeMarks'  => round($negativeMarks, 2),
            'finalMarks'     => round($finalMarks, 2),
            'totalPossible'  => round($totalPossible, 2),
            // performance
            'percentage'     => $percentage,
            'accuracy'       => $accuracy,
            'passingScore'   => $passingScore,
            'passed'         => $passed,
            'status'         => $status,
            // time
            'timeSpentSec'   => (int)($attempt['time_spent_sec'] ?? 0),
        ],
    ],
]);
