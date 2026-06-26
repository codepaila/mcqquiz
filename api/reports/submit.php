<?php
/**
 * POST /api/reports/submit
 * Port of src/app/api/reports/submit/route.ts
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Models\Quiz;
use Quiznosis\Models\QuestionReport;

Request::requireMethod('POST');
$me = Auth::require();

$body = Request::body();
$quizId      = (string)($body['quizId'] ?? '');
$reason      = (string)($body['reason'] ?? '');
$description = (string)($body['description'] ?? '');
if ($quizId === '' || $reason === '' || $description === '') {
    Response::error('Missing required fields', 400);
}

$validReasons = [
    'INCORRECT_ANSWER','TYPO_OR_GRAMMAR','MISLEADING_QUESTION','AMBIGUOUS_OPTIONS',
    'DUPLICATE_QUESTION','INAPPROPRIATE_CONTENT','OUTDATED_INFORMATION','OTHER',
];
if (!in_array($reason, $validReasons, true)) {
    Response::error('Invalid report reason', 400);
}

// One report per (user, quiz) — schema enforces but we want a friendly error.
$existing = QuestionReport::firstWhere(['user_id' => $me['id'], 'quiz_id' => $quizId]);
if ($existing) {
    Response::error('You have already reported this question', 400);
}

if (!Quiz::findById($quizId)) {
    Response::notFound('Question not found');
}

$ip = Request::ip();
$ua = Request::userAgent();
$report = QuestionReport::create([
    'user_id'            => $me['id'],
    'quiz_id'            => $quizId,
    'reason'             => $reason,
    'description'        => $description,
    'selected_option_id' => $body['selectedOptionId'] ?? null,
    'suggested_text'     => $body['suggestedText']    ?? null,
    'reference_text'     => $body['referenceText']    ?? null,
    'ip_address'         => $ip,
    'user_agent'         => substr($ua, 0, 500),
    'metadata'           => [
        'reportedFrom' => 'quiz-runner',
        'timestamp'    => gmdate('c'),
    ],
]);

Audit::log([
    'user_id'     => $me['id'],
    'action'      => 'REPORT_CREATED',
    'entity_type' => 'QUIZ',
    'entity_id'   => $quizId,
    'details'     => ['reportId' => $report['id'], 'reason' => $reason],
]);

Response::ok([
    'success'  => true,
    'message'  => 'Report submitted successfully',
    'reportId' => $report['id'],
]);
