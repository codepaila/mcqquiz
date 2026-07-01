<?php
/**
 * Admin · AI Explanation Assistant — generate / improve
 *
 * POST /api/admin/ai-improve
 * Body: {
 *   question: string,
 *   options: [ { text: string, isCorrect: bool }, ... ],
 *   explanation: string   // current text, may be empty — improving an
 *                         // empty explanation just generates one from
 *                         // scratch using the question + correct answer
 * }
 *
 * Returns: { data: { text: string } }
 *
 * Takes the question/options/explanation directly from the client rather
 * than looking up a saved question by id, so this works for brand-new
 * questions that haven't been saved yet too, and always reflects whatever
 * the admin currently has typed in the form (not a possibly-stale DB copy).
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\AiExplanationAssistant;

Auth::requireAdmin();
Request::requireMethod('POST');

$body = Request::body();

$questionText = trim((string)($body['question'] ?? ''));
if ($questionText === '') {
    Response::error('Question text is required before using the AI assistant.', 400);
}

$options = [];
foreach (($body['options'] ?? []) as $opt) {
    $text = trim((string)($opt['text'] ?? ''));
    if ($text === '') continue;
    $options[] = ['text' => $text, 'is_correct' => !empty($opt['isCorrect'])];
}

$result = AiExplanationAssistant::improveExplanation([
    'text'        => $questionText,
    'options'     => $options,
    'explanation' => (string)($body['explanation'] ?? ''),
]);

if (!$result['ok']) {
    Response::error($result['error'] ?? 'AI request failed.', 502);
}

Response::ok(['data' => ['text' => $result['text']]]);
