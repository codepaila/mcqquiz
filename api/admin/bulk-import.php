<?php
/**
 * POST /api/admin/bulk-import
 *
 * Body:
 *   {
 *     "format":   "text" | "json",            (required)
 *     "content":  "...",                       (required — raw text or JSON string/array)
 *     "dryRun":   true | false,                (optional, default false — validate only)
 *     "attachToSetId": "<quiz_set_id>",        (optional — also attach to a set)
 *     "defaultDifficulty": "EASY|MEDIUM|HARD", (optional, default "MEDIUM")
 *     "defaultSubjectId":  "<id>",             (optional)
 *     "defaultTopicId":    "<id>",             (optional)
 *     "defaultBookId":     "<id>",             (optional)
 *     "defaultTags":       ["..."]             (optional)
 *   }
 *
 * Behavior:
 *   1. Parse content using QuizImporter (text or JSON).
 *   2. If any parse errors → 400 with full per-row error list, do NOT insert anything.
 *   3. If dryRun=true → return the parsed structure without writing.
 *   4. Otherwise wrap inserts in a single transaction; rollback if any DB error.
 *
 * Response:
 *   {
 *     "success":  true,
 *     "imported": 15,
 *     "skipped":  0,
 *     "errors":   [],
 *     "questions": [ {id, question, ...}, ... ]
 *   }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Database;
use Quiznosis\Core\QuizImporter;
use Quiznosis\Models\Quiz;
use Quiznosis\Models\QuizOption;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\QuizSetItem;

Request::requireMethod('POST');
$me = Auth::requireAdmin();

$body = Request::body();

$format  = (string)($body['format'] ?? '');
$content = $body['content'] ?? null;
$dryRun  = !empty($body['dryRun']);

if (!in_array($format, ['text', 'json'], true)) {
    Response::error('"format" must be "text" or "json"', 400);
}
if ($content === null || (is_string($content) && trim($content) === '')) {
    Response::error('"content" is required', 400);
}

$difficulty = strtoupper((string)($body['defaultDifficulty'] ?? 'MEDIUM'));
if (!in_array($difficulty, ['EASY','MEDIUM','HARD'], true)) {
    Response::error('"defaultDifficulty" must be EASY, MEDIUM, or HARD', 400);
}

$subjectId    = $body['defaultSubjectId'] ?? null;
$topicId      = $body['defaultTopicId']   ?? null;
$bookId       = $body['defaultBookId']    ?? null;
$tags         = is_array($body['defaultTags'] ?? null) ? $body['defaultTags'] : [];
$attachToSetId = $body['attachToSetId']   ?? null;

// Validate set if provided (do it once, not per row).
$setRow = null;
if ($attachToSetId) {
    $setRow = QuizSet::findById((string)$attachToSetId);
    if (!$setRow) Response::error('attachToSetId: quiz set not found', 404);
}

// --- Parse -----------------------------------------------------------
$parsed = $format === 'text'
    ? QuizImporter::parseText((string)$content)
    : QuizImporter::parseJson($content);

$errors = $parsed['errors'];
$questions = $parsed['questions'];

// If there are any parse errors, refuse to import anything — better UX than partial.
if (!empty($errors)) {
    Response::error(
        sprintf('%d question(s) had parse errors. Nothing imported.', count($errors)),
        400,
        [
            'errors'       => $errors,
            'parsedCount'  => count($questions),
            'rejectedCount' => count($errors),
        ]
    );
}

if (empty($questions)) {
    Response::error('No questions found in the input.', 400);
}

// Dry run — return what would be imported, but don't write.
if ($dryRun) {
    Response::ok([
        'success'   => true,
        'dryRun'    => true,
        'wouldImport' => count($questions),
        'questions' => $questions,
    ]);
}

// --- Insert ---------------------------------------------------------
$result = Database::transaction(function () use (
    $questions, $difficulty, $subjectId, $topicId, $bookId, $tags, $setRow
) {
    $created = [];
    $nextOrder = $setRow ? (QuizSetItem::count(['quiz_set_id' => $setRow['id']]) + 1) : null;

    foreach ($questions as $q) {
        $quiz = Quiz::create([
            'question'    => $q['question'],
            'explanation' => $q['explanation'],
            'difficulty'  => $difficulty,
            'subject_id'  => $subjectId ?: null,
            'topic_id'    => $topicId   ?: null,
            'book_id'     => $bookId    ?: null,
            'tags'        => $tags,
        ]);
        foreach ($q['options'] as $i => $opt) {
            QuizOption::create([
                'quiz_id'    => $quiz['id'],
                'text'       => $opt['text'],
                'is_correct' => $opt['isCorrect'] ? 1 : 0,
                'order'      => $i + 1,
            ]);
        }
        if ($setRow) {
            QuizSetItem::create([
                'quiz_set_id' => $setRow['id'],
                'quiz_id'     => $quiz['id'],
                'order'       => $nextOrder++,
            ]);
        }
        $created[] = [
            'id'       => $quiz['id'],
            'question' => $quiz['question'],
        ];
    }
    return $created;
});

// Keep the set's cached question count in sync after a bulk attach.
if ($setRow) {
    QuizSet::syncTotalQuestions($setRow['id']);
}

Audit::log([
    'user_id'     => $me['id'],
    'action'      => 'QUIZ_BULK_IMPORTED',
    'entity_type' => 'QUIZ',
    'details'     => [
        'count'          => count($result),
        'format'         => $format,
        'attachToSetId'  => $attachToSetId,
        'difficulty'     => $difficulty,
    ],
]);

Response::ok([
    'success'   => true,
    'imported'  => count($result),
    'skipped'   => 0,
    'attached'  => $setRow ? count($result) : 0,
    'setName'   => $setRow['name'] ?? null,
    'questions' => $result,
]);
