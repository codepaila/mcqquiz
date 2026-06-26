<?php
/**
 * GET    /api/admin/quiz-sets         — list (same filters as public list, no PUBLISHED filter)
 * POST   /api/admin/quiz-sets         — create
 * PATCH  /api/admin/quiz-sets         — { id, ...fields }
 * DELETE /api/admin/quiz-sets         — ?id=<id>
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Models\QuizSet;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $rows = QuizSet::where([], ['order' => 'created_at DESC', 'limit' => 200]);
    Response::ok(['data' => $rows]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)
        ->required('name')
        ->required('mode')
        ->in('mode', ['MODEL_TEST','PRACTICE'])
        ->abortIfFails();

    $slug = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify((string)$body['name']);
    $existing = QuizSet::firstWhere(['slug' => $slug]);
    if ($existing) $slug .= '-' . substr(Util::objectId(), 0, 6);

    $row = QuizSet::create([
        'name'                       => trim((string)$body['name']),
        'slug'                       => $slug,
        'description'                => $body['description'] ?? null,
        'mode'                       => $body['mode'],
        'duration_minutes'           => $body['durationMinutes'] ?? null,
        'passing_score'              => $body['passingScore']    ?? null,
        'status'                     => $body['status']          ?? 'PUBLISHED',
        'subject_id'                 => $body['subjectId']       ?? null,
        'profession_id'              => $body['professionId']    ?? null,
        'topic_id'                   => $body['topicId']         ?? null,
        'exam_type_id'               => $body['examTypeId']      ?? null,
        'book_id'                    => $body['bookId']          ?? null,
        'tags'                       => $body['tags']            ?? [],
        'total_questions'            => $body['totalQuestions']  ?? null,
        'is_paid'                    => !empty($body['isPaid']) ? 1 : 0,
        'price'                      => $body['price']           ?? null,
        'currency'                   => $body['currency']        ?? 'NPR',
        'enable_negative_marking'    => !empty($body['enableNegativeMarking']) ? 1 : 0,
        'negative_mark_per_question' => $body['negativeMarkPerQuestion'] ?? 0.25,
        'access_days'                => isset($body['accessDays']) ? max(0, (int)$body['accessDays']) : 365,
    ]);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'QUIZ_SET_CREATED',
        'entity_type' => 'QUIZ_SET', 'entity_id' => $row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $set = QuizSet::findById($id);
    if (!$set) Response::notFound('Quiz set not found');

    // Map camelCase → snake_case for the columns admins can edit
    $map = [
        'name' => 'name', 'description' => 'description', 'mode' => 'mode',
        'durationMinutes' => 'duration_minutes', 'passingScore' => 'passing_score',
        'status' => 'status', 'subjectId' => 'subject_id', 'topicId' => 'topic_id',
        'examTypeId' => 'exam_type_id',
        'professionId' => 'profession_id', 'bookId' => 'book_id', 'tags' => 'tags',
        'totalQuestions' => 'total_questions', 'isPaid' => 'is_paid', 'price' => 'price',
        'currency' => 'currency', 'enableNegativeMarking' => 'enable_negative_marking',
        'negativeMarkPerQuestion' => 'negative_mark_per_question',
        'accessDays' => 'access_days',
    ];
    $patch = [];
    foreach ($map as $k => $col) if (array_key_exists($k, $body)) $patch[$col] = $body[$k];
    if (!$patch) Response::error('No fields to update', 400);

    $updated = QuizSet::update($id, $patch);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'QUIZ_SET_UPDATED',
        'entity_type' => 'QUIZ_SET', 'entity_id' => $id, 'details' => $patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    $set = QuizSet::findById($id);
    if (!$set) Response::notFound('Quiz set not found');
    QuizSet::deleteById($id);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'QUIZ_SET_DELETED',
        'entity_type' => 'QUIZ_SET', 'entity_id' => $id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
