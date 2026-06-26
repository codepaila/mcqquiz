<?php
/**
 * Admin · Subjects CRUD
 *
 *   GET    /api/admin/subjects?profession_id=...   — list, ordered, with topic count
 *   POST   /api/admin/subjects                     — create { name, professionId?, order? }
 *   PATCH  /api/admin/subjects                     — update { id, ...fields }
 *   DELETE /api/admin/subjects?id=...              — delete
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\Subject;
use Quiznosis\Models\Profession;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $pid = Request::query('profession_id');
    $sql = "SELECT s.*, p.name AS profession_name,
                   (SELECT COUNT(*) FROM topics t WHERE t.subject_id = s.id) AS topics_count,
                   (SELECT COUNT(*) FROM quiz_sets q WHERE q.subject_id = s.id) AS quiz_sets_count
              FROM subjects s
              LEFT JOIN professions p ON p.id = s.profession_id";
    $params = [];
    if ($pid) { $sql .= ' WHERE s.profession_id = ?'; $params[] = $pid; }
    $sql .= ' ORDER BY s.`order`, s.name';
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    Response::ok(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)
        ->required('name')
        ->abortIfFails();

    $name = trim((string)$body['name']);
    $slug = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($name);
    if (Subject::firstWhere(['slug' => $slug])) $slug .= '-' . substr(Util::objectId(), 0, 6);
    if (Subject::firstWhere(['name' => $name])) {
        Response::error('A subject with that name already exists', 409);
    }
    $profId = $body['professionId'] ?? null;
    if ($profId && !Profession::findById((string)$profId)) {
        Response::error('professionId not found', 400);
    }

    $row = Subject::create([
        'name'          => $name,
        'slug'          => $slug,
        'profession_id' => $profId ?: null,
        'order'         => isset($body['order']) ? (int)$body['order'] : 0,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'SUBJECT_CREATED',
        'entity_type'=>'SUBJECT', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $existing = Subject::findById($id);
    if (!$existing) Response::notFound('Subject not found');

    $map = [
        'name'=>'name', 'slug'=>'slug', 'order'=>'order',
        'professionId'=>'profession_id',
    ];
    $patch = [];
    foreach ($map as $k => $col) if (array_key_exists($k, $body)) $patch[$col] = $body[$k];

    if (isset($patch['name'])) $patch['name'] = trim((string)$patch['name']);
    if (isset($patch['slug']) && $patch['slug']) $patch['slug'] = Util::slugify((string)$patch['slug']);
    if (isset($patch['profession_id']) && $patch['profession_id']) {
        if (!Profession::findById((string)$patch['profession_id'])) {
            Response::error('professionId not found', 400);
        }
    } elseif (array_key_exists('profession_id', $patch)) {
        $patch['profession_id'] = null;
    }
    if (!$patch) Response::error('No fields to update', 400);

    if (isset($patch['name'])) {
        $dup = Subject::firstWhere(['name' => $patch['name']]);
        if ($dup && $dup['id'] !== $id) Response::error('Name already in use', 409);
    }
    if (isset($patch['slug'])) {
        $dup = Subject::firstWhere(['slug' => $patch['slug']]);
        if ($dup && $dup['id'] !== $id) Response::error('Slug already in use', 409);
    }

    $updated = Subject::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'SUBJECT_UPDATED',
        'entity_type'=>'SUBJECT', 'entity_id'=>$id, 'details'=>$patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!Subject::findById($id)) Response::notFound('Subject not found');

    // Block delete if topics or quiz sets reference it (clearer than a cryptic FK error)
    $stmt = Database::pdo()->prepare("SELECT
        (SELECT COUNT(*) FROM topics WHERE subject_id=?) +
        (SELECT COUNT(*) FROM quiz_sets WHERE subject_id=?) +
        (SELECT COUNT(*) FROM quizzes WHERE subject_id=?) AS c");
    $stmt->execute([$id, $id, $id]);
    $refs = (int)$stmt->fetch()['c'];
    if ($refs > 0) {
        Response::error("Cannot delete — this subject is used by {$refs} item(s). Reassign or delete those first.", 409);
    }

    Subject::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'SUBJECT_DELETED',
        'entity_type'=>'SUBJECT', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
