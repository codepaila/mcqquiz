<?php
/**
 * POST /api/quiz/answer
 * Body: { attemptId, quizId, selectedOptionIds: [], isMarked?, timeSpentSec? }
 *
 * Upserts a response. Computes is_correct + points_earned + negative marks
 * based on the quiz's correct option set and the set's negative-marking config.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\QuizSetAttempt;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\QuizOption;
use Quiznosis\Models\QuizSetResponse;

Request::requireMethod('POST');
$me = Auth::require();

$body = Request::body();
$attemptId = (string)($body['attemptId'] ?? '');
$quizId    = (string)($body['quizId'] ?? '');
$selected  = $body['selectedOptionIds'] ?? [];
if (!is_array($selected)) $selected = [];
$timeSpent = isset($body['timeSpentSec']) ? (int)$body['timeSpentSec'] : null;
$isMarked  = !empty($body['isMarked']);

if ($attemptId === '' || $quizId === '') {
    Response::error('attemptId and quizId are required', 400);
}

$attempt = QuizSetAttempt::findById($attemptId);
if (!$attempt) Response::notFound('Attempt not found');
if ($attempt['user_id'] !== $me['id']) Response::forbidden();
if ($attempt['status'] !== 'IN_PROGRESS') {
    Response::error('Attempt is not in progress.', 409);
}

$set = QuizSet::findById($attempt['quiz_set_id']);
if (!$set) Response::notFound('Quiz set not found');

// Determine correctness — strict set equality between selected and correct option ids.
$correctOpts = QuizOption::where(['quiz_id' => $quizId, 'is_correct' => 1]);
$correctIds  = array_column($correctOpts, 'id');
sort($correctIds);
$sel = array_values(array_unique(array_map('strval', $selected)));
sort($sel);

$isCorrect = !empty($correctIds) && $sel === $correctIds;
$pointsEarned = $isCorrect ? 1 : 0;
$negDeduct = 0;

// Honour the student's per-session negative-marking toggle (enableNeg sent
// from quiz-attempt.html). Fall back to the set's admin-configured flag so
// older clients and API consumers still work correctly.
$clientEnableNeg = isset($body['enableNeg']) ? (bool)$body['enableNeg'] : null;
$negEnabled = $clientEnableNeg !== null
    ? $clientEnableNeg
    : ((int)$set['enable_negative_marking'] === 1);

if (!$isCorrect && !empty($sel) && $negEnabled) {
    $negDeduct = (float)($set['negative_mark_per_question'] ?? 0.25);
}

$response = QuizSetResponse::upsert($attemptId, $quizId, [
    'selected_option_ids'     => $sel,
    'is_correct'              => $isCorrect ? 1 : 0,
    'points_earned'           => $pointsEarned,
    'time_spent_sec'          => $timeSpent,
    'answered_at'             => gmdate('Y-m-d H:i:s'),
    'is_marked'               => $isMarked ? 1 : 0,
    'negative_marks_deducted' => $negDeduct,
]);

// In PRACTICE mode we reveal correctness + explanation immediately so the
// student learns as they go. In MODEL_TEST we reveal nothing.
// Use the attempt's chosen_mode (set on start) so a student's pre-start
// choice is honoured regardless of the quiz set's admin-configured mode.
$effectiveMode = $attempt['chosen_mode'] ?: ($set['mode'] ?? '');
$isPractice = $effectiveMode === 'PRACTICE';
$payload = [
    'data'   => $response,
    'reveal' => $isPractice,
];
if ($isPractice) {
    $quiz = \Quiznosis\Models\Quiz::findById($quizId);
    $payload['feedback'] = [
        'isCorrect'    => $isCorrect,
        'correctIds'   => $correctIds,
        'explanation'  => $quiz['explanation'] ?? null,
    ];
}

Response::ok($payload);

