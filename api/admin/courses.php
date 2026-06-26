<?php
/**
 * Admin · Courses
 *
 * COURSE CRUD
 *   GET    /api/admin/courses                  — list (with material + enrollment counts)
 *   GET    /api/admin/courses?id=...           — single course with its materials
 *   POST   /api/admin/courses                  — create
 *   PATCH  /api/admin/courses                  — update { id, ...fields }
 *   DELETE /api/admin/courses?id=...           — delete (cascades materials)
 *
 * MATERIALS (quiz sets + notes inside a course)
 *   POST   /api/admin/courses?action=add-material
 *          { courseId, type: QUIZ_SET|NOTE, quizSetId?|noteId?, order? }
 *   POST   /api/admin/courses?action=reorder-materials
 *          { courseId, order: [materialId, ...] }
 *   DELETE /api/admin/courses?action=remove-material&materialId=...
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Core\Uploader;
use Quiznosis\Models\Course;
use Quiznosis\Models\CourseMaterial;
use Quiznosis\Models\QuizSet;
use Quiznosis\Models\Note;
use Quiznosis\Models\Profession;
use Quiznosis\Models\ExamType;

$me = Auth::requireAdmin();
$method = Request::method();
$action = Request::query('action', '');

$ACCESS_TYPES = ['OPEN', 'PAID', 'APPROVAL', 'SUBSCRIPTION'];

/* ---------- material sub-actions (POST/DELETE with ?action=) ---------- */
if ($action === 'add-material' && $method === 'POST') {
    $body = Request::body();
    $courseId = (string)($body['courseId'] ?? '');
    $type     = strtoupper((string)($body['type'] ?? ''));
    if (!Course::findById($courseId)) Response::notFound('Course not found');
    if (!in_array($type, ['QUIZ_SET', 'NOTE'], true)) {
        Response::error('type must be QUIZ_SET or NOTE', 400);
    }

    $quizSetId = $noteId = null;
    $refName = '';
    if ($type === 'QUIZ_SET') {
        $quizSetId = (string)($body['quizSetId'] ?? '');
        $qs = QuizSet::findById($quizSetId);
        if (!$qs) Response::error('quizSetId not found', 400);
        $refName = $qs['name'];
        // already attached?
        if (CourseMaterial::firstWhere(['course_id' => $courseId, 'quiz_set_id' => $quizSetId])) {
            Response::error('That quiz set is already in this course', 409);
        }
    } else {
        $noteId = (string)($body['noteId'] ?? '');
        $note = Note::findById($noteId);
        if (!$note) Response::error('noteId not found', 400);
        $refName = $note['title'];
        if (CourseMaterial::firstWhere(['course_id' => $courseId, 'note_id' => $noteId])) {
            Response::error('That note is already in this course', 409);
        }
    }

    // next order value
    $stmt = Database::pdo()->prepare("SELECT COALESCE(MAX(`order`),0)+1 AS n FROM course_materials WHERE course_id=?");
    $stmt->execute([$courseId]);
    $order = isset($body['order']) ? (int)$body['order'] : (int)$stmt->fetch()['n'];

    // course_materials.slug is NOT NULL UNIQUE — derive one
    $slug = Util::slugify($refName) . '-' . substr(Util::objectId(), 0, 6);

    $row = CourseMaterial::create([
        'course_id'   => $courseId,
        'type'        => $type,
        'slug'        => $slug,
        'quiz_set_id' => $quizSetId,
        'note_id'     => $noteId,
        'order'       => $order,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_MATERIAL_ADDED',
        'entity_type'=>'COURSE', 'entity_id'=>$courseId,
    ]);
    Response::created(['data' => $row]);
}

if ($action === 'remove-material' && $method === 'DELETE') {
    $materialId = (string)Request::query('materialId', '');
    if ($materialId === '') Response::error('materialId is required', 400);
    $mat = CourseMaterial::findById($materialId);
    if (!$mat) Response::notFound('Material not found');
    CourseMaterial::deleteById($materialId);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_MATERIAL_REMOVED',
        'entity_type'=>'COURSE', 'entity_id'=>$mat['course_id'],
    ]);
    Response::ok(['success' => true]);
}

if ($action === 'reorder-materials' && $method === 'POST') {
    $body = Request::body();
    $courseId = (string)($body['courseId'] ?? '');
    $order    = $body['order'] ?? [];
    if (!Course::findById($courseId)) Response::notFound('Course not found');
    if (!is_array($order) || !$order) Response::error('order must be a non-empty array', 400);

    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        // two-pass to dodge the (course_id, order) unique constraint
        $tmp = 1000;
        $stmt = $pdo->prepare("UPDATE course_materials SET `order`=? WHERE id=? AND course_id=?");
        foreach ($order as $mid) { $stmt->execute([$tmp++, $mid, $courseId]); }
        $pos = 1;
        foreach ($order as $mid) { $stmt->execute([$pos++, $mid, $courseId]); }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Response::error('Reorder failed: ' . $e->getMessage(), 500);
    }
    Response::ok(['success' => true]);
}

// Toggle is_free_demo on a single material — POST /api/admin/courses?action=set-material-free
// Body: { materialId, isFreeDemo: bool }
if ($action === 'set-material-free' && $method === 'POST') {
    $body       = Request::body();
    $materialId = (string)($body['materialId'] ?? '');
    $isFree     = !empty($body['isFreeDemo']) ? 1 : 0;
    if ($materialId === '') Response::error('materialId is required', 400);
    $mat = CourseMaterial::findById($materialId);
    if (!$mat) Response::notFound('Material not found');

    $upd = Database::pdo()->prepare("UPDATE course_materials SET is_free_demo = ? WHERE id = ?");
    $upd->execute([$isFree, $materialId]);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => $isFree ? 'COURSE_MATERIAL_FREE_ON' : 'COURSE_MATERIAL_FREE_OFF',
        'entity_type' => 'COURSE',
        'entity_id'   => $mat['course_id'],
    ]);
    Response::ok(['success' => true, 'isFreeDemo' => (bool)$isFree]);
}

/* ---------- course CRUD ---------- */
if ($method === 'GET') {
    $id = Request::query('id');
    if ($id) {
        $course = Course::findById((string)$id);
        if (!$course) Response::notFound('Course not found');
        // attach materials with joined names
        $stmt = Database::pdo()->prepare(
            "SELECT cm.*, qs.name AS quiz_set_name, n.title AS note_title
               FROM course_materials cm
               LEFT JOIN quiz_sets qs ON qs.id = cm.quiz_set_id
               LEFT JOIN notes n      ON n.id  = cm.note_id
              WHERE cm.course_id = ?
              ORDER BY cm.`order`"
        );
        $stmt->execute([$id]);
        $course['materials'] = $stmt->fetchAll();
        Response::ok(['data' => $course]);
    }
    $rows = Database::pdo()->query(
        "SELECT c.*, p.name AS profession_name, et.name AS exam_type_name,
                (SELECT COUNT(*) FROM course_materials cm WHERE cm.course_id=c.id) AS materials_count,
                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) AS enrollments_count
           FROM courses c
           LEFT JOIN professions p ON p.id = c.profession_id
           LEFT JOIN exam_types et ON et.id = c.exam_type_id
          ORDER BY c.created_at DESC"
    )->fetchAll();
    Response::ok(['data' => $rows]);
}

if ($action === 'upload-cover' && $method === 'POST') {
    // multipart form: { courseId, cover: <file> }
    $courseId = (string)($_POST['courseId'] ?? Request::query('courseId', ''));
    if ($courseId === '') Response::error('courseId is required', 400);
    $course = Course::findById($courseId);
    if (!$course) Response::notFound('Course not found');
    if (empty($_FILES['cover']) || ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        Response::error('No cover file uploaded', 400);
    }
    try {
        $url = Uploader::save($_FILES['cover'], 'covers');
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 400);
    }
    $updated = Course::update($courseId, ['cover_url' => $url]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_COVER_UPLOADED',
        'entity_type'=>'COURSE', 'entity_id'=>$courseId, 'details'=>['cover_url'=>$url],
    ]);
    Response::ok(['data' => $updated, 'cover_url' => $url]);
}

if ($action === 'remove-cover' && $method === 'POST') {
    $courseId = (string)($_POST['courseId'] ?? Request::query('courseId', '') ?? (Request::body()['courseId'] ?? ''));
    if ($courseId === '') Response::error('courseId is required', 400);
    if (!Course::findById($courseId)) Response::notFound('Course not found');
    $updated = Course::update($courseId, ['cover_url' => null]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_COVER_REMOVED',
        'entity_type'=>'COURSE', 'entity_id'=>$courseId,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)->required('title')->abortIfFails();

    $title = trim((string)$body['title']);
    $slug  = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($title);
    if (Course::firstWhere(['slug' => $slug])) $slug .= '-' . substr(Util::objectId(), 0, 6);

    $access = strtoupper((string)($body['accessType'] ?? 'OPEN'));
    if (!in_array($access, $ACCESS_TYPES, true)) $access = 'OPEN';
    $profId = $body['professionId'] ?? null;
    if ($profId && !Profession::findById((string)$profId)) {
        Response::error('professionId not found', 400);
    }
    $examTypeId = $body['examTypeId'] ?? null;
    if ($examTypeId && !ExamType::findById((string)$examTypeId)) {
        Response::error('examTypeId not found', 400);
    }

    $row = Course::create([
        'title'            => $title,
        'slug'             => $slug,
        'description'      => $body['description'] ?? null,
        'syllabus'         => $body['syllabus'] ?? null,
        'is_public'        => array_key_exists('isPublic', $body) ? (!empty($body['isPublic']) ? 1 : 0) : 1,
        'profession_id'    => $profId ?: null,
        'exam_type_id'     => $examTypeId ?: null,
        'has_subscription' => !empty($body['hasSubscription']) ? 1 : 0,
        'access_type'      => $access,
        'requires_approval'=> !empty($body['requiresApproval']) ? 1 : 0,
    ]);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_CREATED',
        'entity_type'=>'COURSE', 'entity_id'=>$row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    if (!Course::findById($id)) Response::notFound('Course not found');

    $map = [
        'title'=>'title', 'slug'=>'slug', 'description'=>'description', 'syllabus'=>'syllabus',
        'isPublic'=>'is_public', 'professionId'=>'profession_id', 'examTypeId'=>'exam_type_id',
        'hasSubscription'=>'has_subscription', 'accessType'=>'access_type',
        'requiresApproval'=>'requires_approval',
    ];
    $patch = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $body)) continue;
        $v = $body[$k];
        if (in_array($k, ['isPublic','hasSubscription','requiresApproval'], true)) $v = !empty($v) ? 1 : 0;
        if ($k === 'title') $v = trim((string)$v);
        if ($k === 'slug' && $v) $v = Util::slugify((string)$v);
        if ($k === 'accessType') {
            $v = strtoupper((string)$v);
            if (!in_array($v, $ACCESS_TYPES, true)) Response::error('Invalid accessType', 400);
        }
        if ($k === 'professionId' && $v && !Profession::findById((string)$v)) {
            Response::error('professionId not found', 400);
        }
        if ($k === 'examTypeId' && $v && !ExamType::findById((string)$v)) {
            Response::error('examTypeId not found', 400);
        }
        $patch[$col] = $v ?: (in_array($k, ['professionId','examTypeId'], true) ? null : $v);
    }
    if (!$patch) Response::error('No fields to update', 400);

    if (isset($patch['slug'])) {
        $dup = Course::firstWhere(['slug' => $patch['slug']]);
        if ($dup && $dup['id'] !== $id) Response::error('Slug already in use', 409);
    }

    $updated = Course::update($id, $patch);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_UPDATED',
        'entity_type'=>'COURSE', 'entity_id'=>$id, 'details'=>$patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!Course::findById($id)) Response::notFound('Course not found');

    // enrollments cascade-delete via FK; warn if any active learners
    $stmt = Database::pdo()->prepare("SELECT COUNT(*) c FROM enrollments WHERE course_id=? AND status IN ('ACTIVE','APPROVED')");
    $stmt->execute([$id]);
    $active = (int)$stmt->fetch()['c'];
    if ($active > 0 && Request::query('force') !== '1') {
        Response::error("This course has {$active} active enrollment(s). Re-send with force=1 to delete anyway.", 409, ['activeEnrollments' => $active]);
    }

    Course::deleteById($id);
    Audit::log([
        'user_id'=>$me['id'], 'action'=>'COURSE_DELETED',
        'entity_type'=>'COURSE', 'entity_id'=>$id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
