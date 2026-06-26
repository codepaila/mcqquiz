<?php
/**
 * Admin · Topics CRUD
 *
 *   GET    /api/admin/topics?subject_id=...   — list (ordered)
 *   POST   /api/admin/topics                  — create { name, subjectId, order? }
 *   PATCH  /api/admin/topics                  — update { id, ...fields }
 *   DELETE /api/admin/topics?id=...           — delete
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\Topic;
use Quiznosis\Models\Subject;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $sid = Request::query('subject_id');
    $sql = "SELECT t.*, s.name AS subject_name,
                   (SELECT COUNT(*) FROM quiz_sets q WHERE q.topic_id = t.id) AS quiz_sets_count
              FROM topics t
              JOIN subjects s ON s.id = t.subject_id";
    $params = [];
    if ($sid) { $sql .= ' WHERE t.subject_id = ?'; $params[] = $sid; }
    $sql .= ' ORDER BY s.name, t.`order`, t.name';
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    Response::ok(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)
        ->required('name')
        ->required('subjectId')
        ->abortIfFails();

    $name = trim((string)$body['name']);
    $subjectId = (string)$body['subjectId'];
    if (!Subject::findById($subjectId)) Response::error('subjectId not found', 400);

    $slug = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($name);
    // Slug only needs to be unique within the same subject — but we don't have that constraint at DB level,
    // so guard at app level.
    $dup = Topic::firstWhere(['subject_id' => $subjectId, 'slug' => $slug]);
    if ($dup) $slug .= '-' . substr(Util::objectId(), 0, 6);

    $row = Topic::create([
        'name'       => $name,
        'slug'       => $slug,
        'subject_id' => $subjectId,
        'order'      => isset($body['order']) ? (int)$body['order'] : 0,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'TOPIC_CREATED',
        'entity_type'=>'TOPIC', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $existing = Topic::findById($id);
    if (!$existing) Response::notFound('Topic not found');

    $map = ['name'=>'name', 'slug'=>'slug', 'order'=>'order', 'subjectId'=>'subject_id'];
    $patch = [];
    foreach ($map as $k => $col) if (array_key_exists($k, $body)) $patch[$col] = $body[$k];
    if (isset($patch['name'])) $patch['name'] = trim((string)$patch['name']);
    if (isset($patch['slug']) && $patch['slug']) $patch['slug'] = Util::slugify((string)$patch['slug']);
    if (isset($patch['subject_id']) && !Subject::findById((string)$patch['subject_id'])) {
        Response::error('subjectId not found', 400);
    }
    if (!$patch) Response::error('No fields to update', 400);

    $updated = Topic::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'TOPIC_UPDATED',
        'entity_type'=>'TOPIC', 'entity_id'=>$id, 'details'=>$patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!Topic::findById($id)) Response::notFound('Topic not found');

    $stmt = Database::pdo()->prepare(
        "SELECT (SELECT COUNT(*) FROM quiz_sets WHERE topic_id=?) +
                (SELECT COUNT(*) FROM quizzes WHERE topic_id=?) AS c"
    );
    $stmt->execute([$id, $id]);
    $refs = (int)$stmt->fetch()['c'];
    if ($refs > 0) {
        Response::error("Cannot delete — this topic is used by {$refs} item(s).", 409);
    }

    Topic::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'TOPIC_DELETED',
        'entity_type'=>'TOPIC', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
