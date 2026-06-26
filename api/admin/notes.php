<?php
/**
 * Admin · Notes (study material — rich text content)
 *
 *   GET    /api/admin/notes?subject_id=...&topic_id=...   — list
 *   GET    /api/admin/notes?id=...                        — single note (full content + lessons)
 *   POST   /api/admin/notes                               — create
 *   PATCH  /api/admin/notes                               — update { id, ...fields }
 *   DELETE /api/admin/notes?id=...                        — delete
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\Note;
use Quiznosis\Models\Subject;
use Quiznosis\Models\Topic;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $id = Request::query('id');
    if ($id) {
        $note = Note::findById((string)$id);
        if (!$note) Response::notFound('Note not found');
        // attached lessons
        $stmt = Database::pdo()->prepare(
            "SELECT nl.id AS link_id, nl.`order`, tl.id, tl.title, tl.slug, tl.status
               FROM note_lessons nl
               JOIN text_lessons tl ON tl.id = nl.lesson_id
              WHERE nl.note_id = ?
              ORDER BY nl.`order`"
        );
        $stmt->execute([$id]);
        $note['lessons'] = $stmt->fetchAll();
        Response::ok(['data' => $note]);
    }

    $sql = "SELECT n.id, n.title, n.slug, n.subject_id, n.topic_id, n.created_at, n.updated_at,
                   s.name AS subject_name, t.name AS topic_name,
                   CHAR_LENGTH(COALESCE(n.content,'')) AS content_length,
                   (SELECT COUNT(*) FROM note_lessons nl WHERE nl.note_id = n.id) AS lessons_count
              FROM notes n
              LEFT JOIN subjects s ON s.id = n.subject_id
              LEFT JOIN topics   t ON t.id = n.topic_id
             WHERE 1=1";
    $params = [];
    if ($sid = Request::query('subject_id')) { $sql .= ' AND n.subject_id = ?'; $params[] = $sid; }
    if ($tid = Request::query('topic_id'))   { $sql .= ' AND n.topic_id = ?';   $params[] = $tid; }
    $sql .= ' ORDER BY n.created_at DESC';
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    Response::ok(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)->required('title')->abortIfFails();

    $title = trim((string)$body['title']);
    $slug  = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($title);
    if (Note::firstWhere(['slug' => $slug])) $slug .= '-' . substr(Util::objectId(), 0, 6);

    $subjectId = $body['subjectId'] ?? null;
    $topicId   = $body['topicId'] ?? null;
    if ($subjectId && !Subject::findById((string)$subjectId)) Response::error('subjectId not found', 400);
    if ($topicId && !Topic::findById((string)$topicId))       Response::error('topicId not found', 400);

    $tags = $body['tags'] ?? [];
    if (!is_array($tags)) $tags = [];

    $row = Note::create([
        'title'      => $title,
        'slug'       => $slug,
        'subject_id' => $subjectId ?: null,
        'topic_id'   => $topicId ?: null,
        'content'    => $body['content'] ?? null,
        'tags'       => $tags,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'NOTE_CREATED',
        'entity_type'=>'NOTE', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    if (!Note::findById($id)) Response::notFound('Note not found');

    $map = [
        'title'=>'title', 'slug'=>'slug', 'content'=>'content',
        'subjectId'=>'subject_id', 'topicId'=>'topic_id', 'tags'=>'tags',
    ];
    $patch = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $body)) continue;
        $v = $body[$k];
        if ($k === 'title') $v = trim((string)$v);
        if ($k === 'slug' && $v) $v = Util::slugify((string)$v);
        if ($k === 'subjectId') { if ($v && !Subject::findById((string)$v)) Response::error('subjectId not found', 400); $v = $v ?: null; }
        if ($k === 'topicId')   { if ($v && !Topic::findById((string)$v))   Response::error('topicId not found', 400);   $v = $v ?: null; }
        if ($k === 'tags' && !is_array($v)) $v = [];
        $patch[$col] = $v;
    }
    if (!$patch) Response::error('No fields to update', 400);

    if (isset($patch['slug'])) {
        $dup = Note::firstWhere(['slug' => $patch['slug']]);
        if ($dup && $dup['id'] !== $id) Response::error('Slug already in use', 409);
    }

    $updated = Note::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'NOTE_UPDATED',
        'entity_type'=>'NOTE', 'entity_id'=>$id,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!Note::findById($id)) Response::notFound('Note not found');

    // course_materials.note_id is ON DELETE SET NULL, note_lessons cascade.
    Note::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'NOTE_DELETED',
        'entity_type'=>'NOTE', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
