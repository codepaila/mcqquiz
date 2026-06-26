<?php
/**
 * POST /api/quiz/start
 * Body: { quizSetId }
 *
 * Creates a new QuizSetAttempt in IN_PROGRESS state. If the user already has
 * an IN_PROGRESS attempt on this set, resume it instead of creating a duplicate.
 *
 * Paid quiz sets require an active approved purchase (COMPLETED + is_active + not expired).
 * Admins bypass the check.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\QuizSetAttempt;
use Quiznosis\Models\Purchase;

Request::requireMethod('POST');
$me = Auth::require();

$quizSetId = (string)Request::input('quizSetId', '');
if ($quizSetId === '') Response::error('quizSetId is required', 400);

// Student's choice from the pre-start screen. Falls back to the quiz set's
// admin-configured mode. Stored on the attempt so answer.php and submit.php
// can honour it instead of always using the quiz set's mode.
$set = QuizSet::findById($quizSetId);
if (!$set) Response::notFound('Quiz set not found');

// === Access gate: free OR direct purchase OR course enrollment OR demo ===
$access = \Quiznosis\Core\QuizAccess::check($me['id'], $quizSetId);

if (!$access['allowed']) {
    Response::error(
        $access['reason'] ?? 'You do not have access to this quiz set.',
        403,
        ['code' => 'UNPAID_OR_NOT_ENROLLED']
    );
}
$reqMode = strtoupper((string)Request::input('mode', ''));
if ($reqMode === 'TEST') $reqMode = 'MODEL_TEST';
$chosenMode = in_array($reqMode, ['PRACTICE','MODEL_TEST'], true) ? $reqMode : $set['mode'];

// Resume existing in-progress attempt if present
$open = QuizSetAttempt::firstWhere([
    'user_id'     => $me['id'],
    'quiz_set_id' => $quizSetId,
    'status'      => 'IN_PROGRESS',
]);
if ($open) {
    Response::ok(['data' => $open, 'resumed' => true]);
}

// Detect whether the chosen_mode migration has been applied. If not, we still
// create the attempt (without the column) and inform the caller via a header
// so the feature degrades gracefully instead of erroring on every Start.
$pdo = \Quiznosis\Core\Database::pdo();
$hasChosenMode = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM quiz_set_attempts LIKE 'chosen_mode'")->fetch();
    $hasChosenMode = (bool)$col;
} catch (\Throwable $e) { /* assume not present */ }

$attemptData = [
    'user_id'        => $me['id'],
    'quiz_set_id'    => $quizSetId,
    'start_time'     => gmdate('Y-m-d H:i:s'),
    'status'         => 'IN_PROGRESS',
    'attempt_number' => QuizSetAttempt::nextAttemptNumber($me['id'], $quizSetId),
    'ip_address'     => Request::ip(),
    'device_info'    => substr(Request::userAgent(), 0, 500),
];
if ($hasChosenMode) {
    $attemptData['chosen_mode'] = $chosenMode;
}

$attempt = QuizSetAttempt::create($attemptData);

Response::created(['data' => $attempt, 'resumed' => false]);
