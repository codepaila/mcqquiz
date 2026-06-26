<?php
/**
 * GET /api/quiz/access?quizSetId=<id>
 *
 * Checks whether the signed-in user may attempt a quiz set, using the
 * same centralised QuizAccess::check() logic as quiz/start.php — but
 * with ZERO side-effects (no attempt row is created, no DB writes).
 *
 * Use this from the quiz-detail page to decide whether to show the
 * "Start Quiz" button or the "Unlock / Purchase" button without
 * accidentally spawning phantom IN_PROGRESS attempts.
 *
 * Response 200:
 *   { allowed: true,  via: "free"|"purchase"|"course"|"demo", courseTitle?: string }
 *   { allowed: false, reason: string }
 *
 * Response 401 — not signed in (guest users always get allowed:false on
 *   the frontend anyway, so this only fires for malformed requests).
 * Response 400 — missing quizSetId.
 * Response 404 — quiz set not found.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\QuizAccess;

Request::requireMethod('GET');
$me = Auth::require();

$quizSetId = (string) Request::query('quizSetId', '');
if ($quizSetId === '') {
    Response::error('quizSetId is required', 400);
}

$result = QuizAccess::check($me['id'], $quizSetId);

if (!$result['allowed'] && ($result['reason'] ?? '') === 'Quiz set not found') {
    Response::notFound('Quiz set not found');
}

Response::ok(['data' => $result]);
