<?php
/**
 * GET    /api/admin/quizzes              — list with filters
 * POST   /api/admin/quizzes              — create with options[]
 * PATCH  /api/admin/quizzes              — update
 * DELETE /api/admin/quizzes              — ?id=<id>
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Database;
use Quiznosis\Core\Util;
use Quiznosis\Models\Quiz;
use Quiznosis\Models\QuizOption;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    // GET ?id=X — single quiz including its options. Used by the admin Edit
    // modal so previously-saved options actually populate the form.
    if ($id = (string)Request::query('id', '')) {
        $row = Quiz::findWithOptions($id);
        if (!$row) Response::notFound('Quiz not found');
        Response::ok(['data' => $row]);
    }

    // Filterable list: search (q), subject, topic, difficulty, exam_type
    // (indirect, via the quiz sets the question is in), quiz_set membership,
    // and a "used in N sets" count via subquery.
    $q          = trim((string)Request::query('q', ''));
    $subjectId  = (string)Request::query('subject_id', '');
    $topicId    = (string)Request::query('topic_id', '');
    $difficulty = (string)Request::query('difficulty', '');
    $examTypeId = (string)Request::query('exam_type_id', '');
    $quizSetId  = (string)Request::query('quiz_set_id', '');
    $page       = max(1, (int)Request::query('page', 1));
    $perPage    = min(100, max(1, (int)Request::query('per_page', 25)));

    $clauses = [];
    $params  = [];

    if ($q !== '')          { $clauses[] = "qz.question LIKE ?";                          $params[] = '%' . $q . '%'; }
    if ($subjectId !== '')  { $clauses[] = "qz.subject_id = ?";                           $params[] = $subjectId; }
    if ($topicId !== '')    { $clauses[] = "qz.topic_id = ?";                             $params[] = $topicId; }
    if ($difficulty !== '') { $clauses[] = "qz.difficulty = ?";                           $params[] = $difficulty; }

    // exam_type — match any set that this question appears in
    if ($examTypeId !== '') {
        $clauses[] = "qz.id IN (
            SELECT qsi.quiz_id FROM quiz_set_items qsi
              JOIN quiz_sets qs ON qs.id = qsi.quiz_set_id
             WHERE qs.exam_type_id = ?
        )";
        $params[] = $examTypeId;
    }

    // quiz_set_id — only questions inside this specific set
    if ($quizSetId !== '') {
        $clauses[] = "qz.id IN (SELECT quiz_id FROM quiz_set_items WHERE quiz_set_id = ?)";
        $params[] = $quizSetId;
    }

    $whereSql = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

    $pdo = \Quiznosis\Core\Database::pdo();

    // Total count for pagination
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM quizzes qz $whereSql");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    // Page of rows + usage count subquery + subject/topic names for the table.
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT qz.*,
                   s.name AS subject_name,
                   t.name AS topic_name,
                   (SELECT COUNT(*) FROM quiz_set_items qsi WHERE qsi.quiz_id = qz.id) AS usage_count
              FROM quizzes qz
              LEFT JOIN subjects s ON s.id = qz.subject_id
              LEFT JOIN topics   t ON t.id = qz.topic_id
            $whereSql
             ORDER BY qz.created_at DESC
             LIMIT $offset, $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['usage_count'] = (int)$r['usage_count'];
    unset($r);

    Response::ok([
        'data'       => $rows,
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
    ]);
}

if ($method === 'POST') {
    $body = Request::body();
    if (empty($body['question']) || empty($body['difficulty'])) {
        Response::error('question and difficulty are required', 400);
    }
    if (!in_array($body['difficulty'], ['EASY','MEDIUM','HARD'], true)) {
        Response::error('Invalid difficulty', 400);
    }
    $options = $body['options'] ?? [];
    if (!is_array($options) || count($options) < 2) {
        Response::error('At least two options are required', 400);
    }
    $hasCorrect = false;
    foreach ($options as $o) if (!empty($o['isCorrect'])) { $hasCorrect = true; break; }
    if (!$hasCorrect) Response::error('At least one option must be marked correct', 400);

    $quiz = Database::transaction(function () use ($body, $options) {
        $q = Quiz::create([
            'question'    => (string)$body['question'],
            'explanation' => $body['explanation'] ?? null,
            'difficulty'  => $body['difficulty'],
            'subject_id'  => $body['subjectId']  ?? null,
            'topic_id'    => $body['topicId']    ?? null,
            'book_id'     => $body['bookId']     ?? null,
            'tags'        => $body['tags']       ?? [],
        ]);
        foreach (array_values($options) as $i => $opt) {
            QuizOption::create([
                'quiz_id'    => $q['id'],
                'text'       => (string)($opt['text'] ?? ''),
                'is_correct' => !empty($opt['isCorrect']) ? 1 : 0,
                'order'      => $i + 1,
            ]);
        }
        return $q;
    });

    Audit::log([
        'user_id' => $me['id'], 'action' => 'QUIZ_CREATED',
        'entity_type' => 'QUIZ', 'entity_id' => $quiz['id'],
    ]);
    Response::created(['data' => Quiz::findWithOptions($quiz['id'])]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $existing = Quiz::findById($id);
    if (!$existing) Response::notFound('Quiz not found');

    $map = [
        'question' => 'question', 'explanation' => 'explanation', 'difficulty' => 'difficulty',
        'subjectId' => 'subject_id', 'topicId' => 'topic_id', 'bookId' => 'book_id', 'tags' => 'tags',
    ];
    $patch = [];
    foreach ($map as $k => $col) if (array_key_exists($k, $body)) $patch[$col] = $body[$k];

    Database::transaction(function () use ($id, $patch, $body) {
        if ($patch) Quiz::update($id, $patch);
        if (array_key_exists('options', $body) && is_array($body['options'])) {
            // Replace options atomically.
            QuizOption::deleteWhere(['quiz_id' => $id]);
            foreach (array_values($body['options']) as $i => $opt) {
                QuizOption::create([
                    'quiz_id'    => $id,
                    'text'       => (string)($opt['text'] ?? ''),
                    'is_correct' => !empty($opt['isCorrect']) ? 1 : 0,
                    'order'      => $i + 1,
                ]);
            }
        }
    });

    Audit::log([
        'user_id' => $me['id'], 'action' => 'QUIZ_UPDATED',
        'entity_type' => 'QUIZ', 'entity_id' => $id,
    ]);
    Response::ok(['data' => Quiz::findWithOptions($id)]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    Quiz::deleteById($id);
    Audit::log([
        'user_id' => $me['id'], 'action' => 'QUIZ_DELETED',
        'entity_type' => 'QUIZ', 'entity_id' => $id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
