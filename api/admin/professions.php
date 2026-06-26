<?php
/**
 * Admin · Professions CRUD
 *
 *   GET    /api/admin/professions             — list (ordered)
 *   POST   /api/admin/professions             — create { name, description?, image?, order? }
 *   PATCH  /api/admin/professions             — update { id, ...fields }
 *   DELETE /api/admin/professions?id=...      — delete
 *
 * Notes
 *   - `slug` is auto-derived from name on create, can be overridden on update.
 *   - Deleting a profession cascades NULL on subjects/exam_types/quiz_sets via FK.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\Profession;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    // Include a count of subjects and quiz sets per profession for context
    $rows = Database::pdo()->query(
        "SELECT p.*,
                (SELECT COUNT(*) FROM subjects s WHERE s.profession_id = p.id) AS subjects_count,
                (SELECT COUNT(*) FROM quiz_sets q WHERE q.profession_id = p.id) AS quiz_sets_count
           FROM professions p
          ORDER BY `order`, name"
    )->fetchAll();
    Response::ok(['data' => $rows]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)
        ->required('name')
        ->abortIfFails();

    $name = trim((string)$body['name']);
    $slug = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($name);
    if (Profession::firstWhere(['slug' => $slug])) {
        $slug .= '-' . substr(Util::objectId(), 0, 6);
    }
    if (Profession::firstWhere(['name' => $name])) {
        Response::error('A profession with that name already exists', 409);
    }

    $row = Profession::create([
        'name'        => $name,
        'slug'        => $slug,
        'description' => $body['description'] ?? null,
        'image'       => $body['image'] ?? null,
        'order'       => isset($body['order']) ? (int)$body['order'] : 0,
    ]);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PROFESSION_CREATED',
        'entity_type' => 'PROFESSION',
        'entity_id'   => $row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $existing = Profession::findById($id);
    if (!$existing) Response::notFound('Profession not found');

    $map = ['name'=>'name','description'=>'description','image'=>'image','order'=>'order','slug'=>'slug'];
    $patch = [];
    foreach ($map as $k => $col) {
        if (array_key_exists($k, $body)) $patch[$col] = $body[$k];
    }
    if (isset($patch['name'])) $patch['name'] = trim((string)$patch['name']);
    if (isset($patch['slug']) && $patch['slug'])  $patch['slug'] = Util::slugify((string)$patch['slug']);
    if (!$patch) Response::error('No fields to update', 400);

    // Duplicate guard
    if (isset($patch['name'])) {
        $dup = Profession::firstWhere(['name' => $patch['name']]);
        if ($dup && $dup['id'] !== $id) Response::error('A profession with that name already exists', 409);
    }
    if (isset($patch['slug'])) {
        $dup = Profession::firstWhere(['slug' => $patch['slug']]);
        if ($dup && $dup['id'] !== $id) Response::error('Slug already in use', 409);
    }

    $updated = Profession::update($id, $patch);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PROFESSION_UPDATED',
        'entity_type' => 'PROFESSION',
        'entity_id'   => $id,
        'details'     => $patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    $existing = Profession::findById($id);
    if (!$existing) Response::notFound('Profession not found');

    Profession::deleteById($id);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PROFESSION_DELETED',
        'entity_type' => 'PROFESSION',
        'entity_id'   => $id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
