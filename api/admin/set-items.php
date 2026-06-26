<?php
/**
 * Manage which quizzes belong to a quiz set, and in what order.
 *
 * GET    /api/admin/set-items?quiz_set_id=<id>     — list with quiz join
 * POST   /api/admin/set-items                      — { quizSetId, quizId, order? }
 * PUT    /api/admin/set-items                      — bulk reorder: { quizSetId, items: [{quizId, order}] }
 * DELETE /api/admin/set-items?id=<id>              — detach by item id
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\QuizSetItem;
use Quiznosis\Models\QuizSet;

Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $setId = (string)Request::query('quiz_set_id', '');
    if ($setId === '') Response::error('quiz_set_id is required', 400);
    $stmt = Database::pdo()->prepare(
        "SELECT i.id, i.quiz_set_id, i.quiz_id, i.`order`,
                q.question, q.difficulty
           FROM quiz_set_items i
           JOIN quizzes q ON q.id = i.quiz_id
          WHERE i.quiz_set_id = ?
          ORDER BY i.`order` ASC"
    );
    $stmt->execute([$setId]);
    Response::ok(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = Request::body();
    $setId  = (string)($body['quizSetId'] ?? '');
    $quizId = (string)($body['quizId']    ?? '');
    if ($setId === '' || $quizId === '') Response::error('quizSetId and quizId are required', 400);
    if (!QuizSet::findById($setId)) Response::notFound('Quiz set not found');

    // duplicate guard
    if (QuizSetItem::firstWhere(['quiz_set_id' => $setId, 'quiz_id' => $quizId])) {
        Response::error('Quiz is already in this set', 409);
    }

    $order = isset($body['order'])
        ? (int)$body['order']
        : (QuizSetItem::count(['quiz_set_id' => $setId]) + 1);

    $row = QuizSetItem::create([
        'quiz_set_id' => $setId,
        'quiz_id'     => $quizId,
        'order'       => $order,
    ]);
    QuizSet::syncTotalQuestions($setId);
    Response::created(['data' => $row]);
}

if ($method === 'PUT') {
    $body = Request::body();
    $setId = (string)($body['quizSetId'] ?? '');
    $items = $body['items'] ?? [];
    if ($setId === '' || !is_array($items)) Response::error('quizSetId and items[] are required', 400);

    Database::transaction(function () use ($setId, $items) {
        foreach ($items as $it) {
            $row = QuizSetItem::firstWhere([
                'quiz_set_id' => $setId,
                'quiz_id'     => (string)($it['quizId'] ?? ''),
            ]);
            if ($row) QuizSetItem::update($row['id'], ['order' => (int)($it['order'] ?? 0)]);
        }
    });
    Response::ok(['success' => true]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    // capture the set id before deleting so we can recount afterwards
    $item = QuizSetItem::findById($id);
    QuizSetItem::deleteById($id);
    if ($item && !empty($item['quiz_set_id'])) {
        QuizSet::syncTotalQuestions($item['quiz_set_id']);
    }
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
