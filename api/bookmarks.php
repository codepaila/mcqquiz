<?php
/**
 * /api/bookmarks
 *   POST {quizId}       — toggle the bookmark for that quiz, returns
 *                          { bookmarked: bool, totalCount: int }.
 *   GET  ?count=1       — just the count: { count: int }.
 *   GET  ?status=1&ids=a,b,c — { status: { quizId: bool, ... } } for the asked IDs.
 *   GET  ?practice=1    — questions for a practice session of bookmarked quizzes,
 *                          shaped like a quiz set:
 *                          { id:'bookmarks', name:'Bookmark Practice',
 *                            mode:'PRACTICE', items:[{quiz, options}, ...] }.
 *   GET  (no params)    — paginated list: { data:[{quiz, options, explanation, ...}],
 *                          total, page, pageSize }.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Core\Util;
use Quiznosis\Models\QuizBookmark;
use Quiznosis\Models\Quiz;
use Quiznosis\Models\QuizOption;

$me  = Auth::require();
$pdo = Database::pdo();

if (Request::method() === 'POST') {
    $quizId = (string)Request::input('quizId', '');
    if ($quizId === '') Response::error('quizId is required', 400);

    $quiz = Quiz::findById($quizId);
    if (!$quiz) Response::notFound('Quiz not found');

    $existing = QuizBookmark::firstWhere([
        'user_id' => $me['id'],
        'quiz_id' => $quizId,
    ]);

    if ($existing) {
        QuizBookmark::deleteById($existing['id']);
        $bookmarked = false;
    } else {
        QuizBookmark::create([
            'user_id' => $me['id'],
            'quiz_id' => $quizId,
        ]);
        $bookmarked = true;
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM quiz_bookmarks WHERE user_id = ?");
    $cnt->execute([$me['id']]);
    $totalCount = (int)$cnt->fetchColumn();

    Response::ok(['bookmarked' => $bookmarked, 'totalCount' => $totalCount]);
}

// --- GET branches ---

if (Request::query('count') === '1') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_bookmarks WHERE user_id = ?");
    $stmt->execute([$me['id']]);
    Response::ok(['count' => (int)$stmt->fetchColumn()]);
}

if (Request::query('status') === '1') {
    $ids = array_filter(array_map('trim', explode(',', (string)Request::query('ids', ''))));
    $status = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT quiz_id FROM quiz_bookmarks WHERE user_id = ? AND quiz_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$me['id']], $ids));
        $saved = array_column($stmt->fetchAll(), 'quiz_id');
        foreach ($ids as $id) $status[$id] = in_array($id, $saved, true);
    }
    Response::ok(['status' => $status]);
}

// Common loader for both practice + list — pulls the user's bookmarked quizzes
// with their options + explanation, ordered newest-first.
function loadBookmarkedQuizzes(\PDO $pdo, string $userId, ?int $limit = null, int $offset = 0): array {
    $sql = "SELECT b.quiz_id, b.created_at AS bookmarked_at
            FROM quiz_bookmarks b
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC";
    if ($limit !== null) $sql .= " LIMIT $offset, $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    if (!$rows) return [];

    $quizIds = array_column($rows, 'quiz_id');
    $placeholders = implode(',', array_fill(0, count($quizIds), '?'));

    // Quizzes
    $qStmt = $pdo->prepare(
        "SELECT q.id, q.question, q.explanation, q.difficulty, q.subject_id, q.topic_id,
                 s.name AS subject_name, t.name AS topic_name
           FROM quizzes q
           LEFT JOIN subjects s ON s.id = q.subject_id
           LEFT JOIN topics   t ON t.id = q.topic_id
          WHERE q.id IN ($placeholders)"
    );
    $qStmt->execute($quizIds);
    $quizMap = [];
    foreach ($qStmt->fetchAll() as $q) $quizMap[$q['id']] = $q;

    // Options
    $oStmt = $pdo->prepare(
        "SELECT id, quiz_id, text, is_correct, `order`
         FROM quiz_options WHERE quiz_id IN ($placeholders)
         ORDER BY quiz_id, `order` ASC"
    );
    $oStmt->execute($quizIds);
    $optsByQuiz = [];
    foreach ($oStmt->fetchAll() as $o) {
        $o['is_correct'] = (int)$o['is_correct'];
        $optsByQuiz[$o['quiz_id']][] = $o;
    }

    $out = [];
    foreach ($rows as $row) {
        $qid = $row['quiz_id'];
        if (!isset($quizMap[$qid])) continue;
        $q = $quizMap[$qid];
        // Nest options under quiz to match the shape of /api/quiz/set so the
        // quiz-attempt page can render bookmark practice the same way.
        $q['options'] = $optsByQuiz[$qid] ?? [];
        $out[] = [
            'quiz'          => $q,
            'options'       => $q['options'],   // kept at item level too for bookmarks.html
            'bookmarked_at' => $row['bookmarked_at'],
        ];
    }
    return $out;
}

if (Request::query('practice') === '1') {
    $items = loadBookmarkedQuizzes($pdo, $me['id']);
    if (!$items) Response::error('You have no bookmarks to practice yet.', 404);

    // Shape like a quiz set so quiz-attempt.html can consume it unchanged.
    Response::ok([
        'data' => [
            'id'                          => 'bookmarks',
            'name'                        => 'Bookmark Practice',
            'description'                 => 'Practising your saved questions',
            'mode'                        => 'PRACTICE',
            'duration_minutes'            => null,
            'passing_score'               => null,
            'enable_negative_marking'     => 0,
            'negative_mark_per_question'  => 0,
            'total_questions'             => count($items),
            'is_paid'                     => 0,
            'items'                       => $items,
            'isBookmarkPractice'          => true,
        ],
    ]);
}

// Default GET — paginated list
$page     = max(1, (int)Request::query('page', 1));
$pageSize = min(100, max(1, (int)Request::query('pageSize', 20)));
$offset   = ($page - 1) * $pageSize;

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_bookmarks WHERE user_id = ?");
$cntStmt->execute([$me['id']]);
$total = (int)$cntStmt->fetchColumn();

$items = loadBookmarkedQuizzes($pdo, $me['id'], $pageSize, $offset);

Response::ok([
    'data'     => $items,
    'total'    => $total,
    'page'     => $page,
    'pageSize' => $pageSize,
]);
