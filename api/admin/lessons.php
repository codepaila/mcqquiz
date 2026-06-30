<?php
/**
 * Admin · Lessons (text_lessons — individual lesson pages)
 *
 * LESSON CRUD
 *   GET    /api/admin/lessons?subject_id=...&status=...   — list
 *   GET    /api/admin/lessons?id=...                      — single lesson (full content)
 *   POST   /api/admin/lessons                             — create
 *   PATCH  /api/admin/lessons                             — update { id, ...fields }
 *   DELETE /api/admin/lessons?id=...                      — delete
 *
 * NOTE ATTACHMENT (a note groups ordered lessons)
 *   POST   /api/admin/lessons?action=attach-to-note       { noteId, lessonId, order? }
 *   DELETE /api/admin/lessons?action=detach-from-note&linkId=...
 *   POST   /api/admin/lessons?action=reorder-note-lessons { noteId, order: [linkId, ...] }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\TextLesson;
use Quiznosis\Models\Note;
use Quiznosis\Models\NoteLesson;
use Quiznosis\Models\Subject;
use Quiznosis\Models\Topic;

$me = Auth::requireAdmin();
$method = Request::method();
$action = Request::query('action', '');

$STATUSES = ['DRAFT', 'PUBLISHED', 'ARCHIVED'];

/* ---------- note attachment sub-actions ---------- */
if ($action === 'attach-to-note' && $method === 'POST') {
    $body = Request::body();
    $noteId   = (string)($body['noteId'] ?? '');
    $lessonId = (string)($body['lessonId'] ?? '');
    if (!Note::findById($noteId))       Response::error('noteId not found', 400);
    if (!TextLesson::findById($lessonId)) Response::error('lessonId not found', 400);
    if (NoteLesson::firstWhere(['note_id' => $noteId, 'lesson_id' => $lessonId])) {
        Response::error('That lesson is already attached to this note', 409);
    }
    $stmt = Database::pdo()->prepare("SELECT COALESCE(MAX(`order`),0)+1 AS n FROM note_lessons WHERE note_id=?");
    $stmt->execute([$noteId]);
    $order = isset($body['order']) ? (int)$body['order'] : (int)$stmt->fetch()['n'];

    $row = NoteLesson::create(['note_id'=>$noteId, 'lesson_id'=>$lessonId, 'order'=>$order]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'LESSON_ATTACHED',
        'entity_type'=>'NOTE', 'entity_id'=>$noteId,
    ]);
    Response::created(['data' => $row]);
}

if ($action === 'detach-from-note' && $method === 'DELETE') {
    $linkId = (string)Request::query('linkId', '');
    if ($linkId === '') Response::error('linkId is required', 400);
    $link = NoteLesson::findById($linkId);
    if (!$link) Response::notFound('Link not found');
    NoteLesson::deleteById($linkId);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'LESSON_DETACHED',
        'entity_type'=>'NOTE', 'entity_id'=>$link['note_id'],
    ]);
    Response::ok(['success' => true]);
}

// Reorder all lessons attached to a note — POST /api/admin/lessons?action=reorder-note-lessons
// Body: { noteId, order: [linkId, linkId, ...] }  (full ordered list of note_lessons.id)
if ($action === 'reorder-note-lessons' && $method === 'POST') {
    $body = Request::body();
    $noteId = (string)($body['noteId'] ?? '');
    $order  = $body['order'] ?? [];
    if (!Note::findById($noteId)) Response::notFound('Note not found');
    if (!is_array($order) || !$order) Response::error('order must be a non-empty array', 400);

    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        // two-pass to dodge the (note_id, order) unique constraint
        $tmp = 1000;
        $stmt = $pdo->prepare("UPDATE note_lessons SET `order`=? WHERE id=? AND note_id=?");
        foreach ($order as $linkId) { $stmt->execute([$tmp++, $linkId, $noteId]); }
        $pos = 1;
        foreach ($order as $linkId) { $stmt->execute([$pos++, $linkId, $noteId]); }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Response::error('Reorder failed: ' . $e->getMessage(), 500);
    }
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'NOTE_LESSONS_REORDERED',
        'entity_type'=>'NOTE', 'entity_id'=>$noteId,
    ]);
    Response::ok(['success' => true]);
}

/* ---------- lesson CRUD ---------- */
if ($method === 'GET') {
    $id = Request::query('id');
    if ($id) {
        $lesson = TextLesson::findById((string)$id);
        if (!$lesson) Response::notFound('Lesson not found');
        Response::ok(['data' => $lesson]);
    }
    $sql = "SELECT tl.id, tl.title, tl.slug, tl.subject_id, tl.topic_id, tl.status,
                   tl.created_at, tl.updated_at,
                   s.name AS subject_name, t.name AS topic_name,
                   CHAR_LENGTH(COALESCE(tl.content,'')) AS content_length
              FROM text_lessons tl
              LEFT JOIN subjects s ON s.id = tl.subject_id
              LEFT JOIN topics   t ON t.id = tl.topic_id
             WHERE 1=1";
    $params = [];
    if ($sid = Request::query('subject_id')) { $sql .= ' AND tl.subject_id = ?'; $params[] = $sid; }
    if ($st  = Request::query('status'))     { $sql .= ' AND tl.status = ?';     $params[] = $st; }
    $sql .= ' ORDER BY tl.created_at DESC';
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    Response::ok(['data' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)->required('title')->required('content')->abortIfFails();

    $title = trim((string)$body['title']);
    $slug  = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($title);
    if (TextLesson::firstWhere(['slug' => $slug])) $slug .= '-' . substr(Util::objectId(), 0, 6);

    $status = strtoupper((string)($body['status'] ?? 'PUBLISHED'));
    if (!in_array($status, $STATUSES, true)) $status = 'PUBLISHED';

    $subjectId = $body['subjectId'] ?? null;
    $topicId   = $body['topicId'] ?? null;
    if ($subjectId && !Subject::findById((string)$subjectId)) Response::error('subjectId not found', 400);
    if ($topicId && !Topic::findById((string)$topicId))       Response::error('topicId not found', 400);

    $tags = $body['tags'] ?? [];
    if (!is_array($tags)) $tags = [];

    $row = TextLesson::create([
        'title'      => $title,
        'slug'       => $slug,
        'content'    => (string)$body['content'],
        'subject_id' => $subjectId ?: null,
        'topic_id'   => $topicId ?: null,
        'tags'       => $tags,
        'status'     => $status,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'LESSON_CREATED',
        'entity_type'=>'LESSON', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    if (!TextLesson::findById($id)) Response::notFound('Lesson not found');

    $map = [
        'title'=>'title', 'slug'=>'slug', 'content'=>'content', 'status'=>'status',
        'subjectId'=>'subject_id', 'topicId'=>'topic_id', 'tags'=>'tags',
    ];
    $patch = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $body)) continue;
        $v = $body[$k];
        if ($k === 'title') $v = trim((string)$v);
        if ($k === 'slug' && $v) $v = Util::slugify((string)$v);
        if ($k === 'status') {
            $v = strtoupper((string)$v);
            if (!in_array($v, $STATUSES, true)) Response::error('Invalid status', 400);
        }
        if ($k === 'subjectId') { if ($v && !Subject::findById((string)$v)) Response::error('subjectId not found', 400); $v = $v ?: null; }
        if ($k === 'topicId')   { if ($v && !Topic::findById((string)$v))   Response::error('topicId not found', 400);   $v = $v ?: null; }
        if ($k === 'tags' && !is_array($v)) $v = [];
        $patch[$col] = $v;
    }
    if (!$patch) Response::error('No fields to update', 400);

    if (isset($patch['slug'])) {
        $dup = TextLesson::firstWhere(['slug' => $patch['slug']]);
        if ($dup && $dup['id'] !== $id) Response::error('Slug already in use', 409);
    }

    $updated = TextLesson::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'LESSON_UPDATED',
        'entity_type'=>'LESSON', 'entity_id'=>$id,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!TextLesson::findById($id)) Response::notFound('Lesson not found');

    // note_lessons FK has no cascade — clean the links first
    $stmt = Database::pdo()->prepare("DELETE FROM note_lessons WHERE lesson_id = ?");
    $stmt->execute([$id]);

    TextLesson::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'LESSON_DELETED',
        'entity_type'=>'LESSON', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
