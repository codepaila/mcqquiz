<?php
/**
 * POST /api/quiz/pause
 * Body: { attemptId, elapsedSeconds }
 *
 * Pauses an in-progress attempt: records how many seconds have elapsed so far
 * and sets status to PAUSED. The student's answers are already saved per-question
 * by answer.php, so nothing else needs storing. The attempt can later be resumed
 * via /api/quiz/resume.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\QuizSetAttempt;

Request::requireMethod('POST');
$me = Auth::require();

$attemptId = (string)Request::input('attemptId', '');
if ($attemptId === '') Response::error('Attempt ID is required', 400);

$attempt = QuizSetAttempt::findById($attemptId);
if (!$attempt) Response::notFound('Attempt not found');
if ($attempt['user_id'] !== $me['id']) Response::forbidden();

if ($attempt['status'] !== 'IN_PROGRESS') {
    Response::error('Only an in-progress attempt can be paused', 409);
}

// Trust the client's elapsed counter but clamp it to a sane range:
// never negative, never more than wall-clock time since the attempt started.
$elapsed   = (int)Request::input('elapsedSeconds', 0);
$wallClock = max(0, time() - strtotime($attempt['start_time']));
$elapsed   = max(0, min($elapsed, $wallClock));

QuizSetAttempt::update($attemptId, [
    'status'          => 'PAUSED',
    'elapsed_seconds' => $elapsed,
]);

// Verify the status actually changed. If the database still has the old status
// enum (migration.sql not run), MySQL truncates 'PAUSED' and the attempt would
// silently stay IN_PROGRESS — which breaks Resume. Fail loudly instead.
$check = QuizSetAttempt::findById($attemptId);
if (!$check || $check['status'] !== 'PAUSED') {
    Response::error(
        'Could not pause: the database is missing the PAUSED status. '
        . 'Run migration.sql once in phpMyAdmin, then try again.',
        500
    );
}

Response::ok([
    'success'        => true,
    'message'        => 'Quiz paused',
    'elapsedSeconds' => $elapsed,
]);
