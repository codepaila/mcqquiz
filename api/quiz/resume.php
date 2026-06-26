<?php
/**
 * GET /api/quiz/resume?attemptId=...
 *
 * Returns a PAUSED attempt belonging to the current user, plus every saved
 * response, so the quiz-attempt page can rebuild exactly where the student
 * left off (selections, marked flags, elapsed time).
 *
 * Calling resume flips the attempt back to IN_PROGRESS.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\QuizSetAttempt;
use Quiznosis\Models\QuizSetResponse;
use Quiznosis\Models\QuizSet;

Request::requireMethod('GET');
$me = Auth::require();

$attemptId = (string)Request::query('attemptId', '');
if ($attemptId === '') Response::error('Attempt ID is required', 400);

$attempt = QuizSetAttempt::findById($attemptId);
if (!$attempt) Response::notFound('Attempt not found');
if ($attempt['user_id'] !== $me['id']) Response::forbidden();

// Idempotent: PAUSED attempts get flipped to IN_PROGRESS, but if the
// attempt is already IN_PROGRESS we just return its current state so
// the frontend can keep loading. Only truly terminal states (COMPLETED,
// TIMED_OUT, ABANDONED) get a 409.
if ($attempt['status'] !== 'PAUSED' && $attempt['status'] !== 'IN_PROGRESS') {
    Response::error('This attempt cannot be resumed (already ' . strtolower($attempt['status']) . ')', 409);
}

$set = QuizSet::findById($attempt['quiz_set_id']);
if (!$set) Response::notFound('Quiz set not found');

// All saved responses for this attempt, keyed by quiz_id.
$rows = QuizSetResponse::where(['attempt_id' => $attemptId]);
$responses = [];
foreach ($rows as $r) {
    $selected = $r['selected_option_ids'];
    if (is_string($selected)) {
        $decoded  = json_decode($selected, true);
        $selected = is_array($decoded) ? $decoded : [];
    }
    $responses[$r['quiz_id']] = [
        'selectedOptionIds' => $selected ?: [],
        'isMarked'          => (int)($r['is_marked'] ?? 0) === 1,
    ];
}

// Flip back to in-progress so the timer + answering work again.
// Flip back to in-progress so the timer + answering work again.
// Skip the update if already IN_PROGRESS to keep the call idempotent.
if ($attempt['status'] === 'PAUSED') {
    QuizSetAttempt::update($attemptId, ['status' => 'IN_PROGRESS']);
}

Response::ok([
    'data' => [
        'attemptId'      => $attemptId,
        'quizSetId'      => $attempt['quiz_set_id'],
        'elapsedSeconds' => (int)($attempt['elapsed_seconds'] ?? 0),
        'durationMin'    => $set['duration_minutes'] !== null ? (int)$set['duration_minutes'] : null,
        'mode'           => $set['mode'],
        'responses'      => $responses,
    ],
]);
