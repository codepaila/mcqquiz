<?php
/**
 * Admin · Exam Types CRUD
 *
 *   GET    /api/admin/exam-types?profession_id=...  — list
 *   POST   /api/admin/exam-types                    — create { name, professionId?, description?, order? }
 *   PATCH  /api/admin/exam-types                    — update { id, ...fields }
 *   DELETE /api/admin/exam-types?id=...             — delete
 *
 * Examples: "Loksewa Section Officer", "MBBS Entrance", "IELTS", "JEE Main".
 * A quiz set's `exam_type_id` column lets one set belong to one exam type.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\ExamType;
use Quiznosis\Models\Profession;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $pid = Request::query('profession_id');
    $sql = "SELECT e.*, p.name AS profession_name,
                   (SELECT COUNT(*) FROM quiz_sets q WHERE q.exam_type_id = e.id) AS quiz_sets_count
              FROM exam_types e
              LEFT JOIN professions p ON p.id = e.profession_id";
    $params = [];
    if ($pid) { $sql .= ' WHERE e.profession_id = ?'; $params[] = $pid; }
    $sql .= ' ORDER BY e.`order`, e.name';
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
    if (ExamType::firstWhere(['slug' => $slug])) $slug .= '-' . substr(Util::objectId(), 0, 6);
    if (ExamType::firstWhere(['name' => $name])) {
        Response::error('An exam type with that name already exists', 409);
    }
    $profId = $body['professionId'] ?? null;
    if ($profId && !Profession::findById((string)$profId)) {
        Response::error('professionId not found', 400);
    }

    $row = ExamType::create([
        'name'          => $name,
        'slug'          => $slug,
        'description'   => $body['description'] ?? null,
        'profession_id' => $profId ?: null,
        'order'         => isset($body['order']) ? (int)$body['order'] : 0,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'EXAM_TYPE_CREATED',
        'entity_type'=>'EXAM_TYPE', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $existing = ExamType::findById($id);
    if (!$existing) Response::notFound('Exam type not found');

    $map = [
        'name'=>'name', 'slug'=>'slug', 'order'=>'order',
        'description'=>'description', 'professionId'=>'profession_id',
    ];
    $patch = [];
    foreach ($map as $k => $col) if (array_key_exists($k, $body)) $patch[$col] = $body[$k];
    if (isset($patch['name'])) $patch['name'] = trim((string)$patch['name']);
    if (isset($patch['slug']) && $patch['slug']) $patch['slug'] = Util::slugify((string)$patch['slug']);
    if (isset($patch['profession_id']) && $patch['profession_id'] && !Profession::findById((string)$patch['profession_id'])) {
        Response::error('professionId not found', 400);
    }
    if (!$patch) Response::error('No fields to update', 400);

    if (isset($patch['name'])) {
        $dup = ExamType::firstWhere(['name' => $patch['name']]);
        if ($dup && $dup['id'] !== $id) Response::error('Name already in use', 409);
    }

    $updated = ExamType::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'EXAM_TYPE_UPDATED',
        'entity_type'=>'EXAM_TYPE', 'entity_id'=>$id, 'details'=>$patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!ExamType::findById($id)) Response::notFound('Exam type not found');

    $stmt = Database::pdo()->prepare("SELECT COUNT(*) c FROM quiz_sets WHERE exam_type_id=?");
    $stmt->execute([$id]);
    $refs = (int)$stmt->fetch()['c'];
    if ($refs > 0) {
        Response::error("Cannot delete — {$refs} quiz set(s) use this exam type.", 409);
    }

    ExamType::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'EXAM_TYPE_DELETED',
        'entity_type'=>'EXAM_TYPE', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
