<?php
/**
 * GET /api/lessons?id=...  — read a single lesson's full content.
 *
 * Access rule: the lesson must belong to a note that sits inside a course in
 * which the signed-in user has an ACTIVE/APPROVED enrollment. Admins bypass.
 * Only PUBLISHED lessons are readable by students.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\TextLesson;

Request::requireMethod('GET');
$me = Auth::require();

$id = (string)Request::query('id', '');
if ($id === '') Response::error('id is required', 400);

$lesson = TextLesson::findById($id);
if (!$lesson) Response::notFound('Lesson not found');

$isAdmin = ($me['role'] ?? '') === 'ADMIN';

if (!$isAdmin) {
    if ($lesson['status'] !== 'PUBLISHED') {
        Response::notFound('Lesson not found');
    }
    // lesson -> note_lessons -> course_materials -> enrollments
    // OR the course_materials row is marked is_free_demo = 1
    $stmt = Database::pdo()->prepare(
        "SELECT COUNT(*) AS c
           FROM note_lessons nl
           JOIN course_materials cm ON cm.note_id = nl.note_id
           LEFT JOIN enrollments e
                  ON e.course_id = cm.course_id
                 AND e.user_id   = ?
                 AND e.status IN ('ACTIVE','APPROVED')
                 AND (e.expires_at IS NULL OR e.expires_at > NOW(3))
          WHERE nl.lesson_id = ?
            AND (e.id IS NOT NULL OR cm.is_free_demo = 1)"
    );
    $stmt->execute([$me['id'], $id]);
    if ((int)$stmt->fetch()['c'] === 0) {
        Response::error('You need an active course enrollment to read this lesson.', 403, [
            'code' => 'ENROLLMENT_REQUIRED',
        ]);
    }
}

// Enrich the response with the parent note + ordered siblings so the
// reader UI can build prev/next navigation and show a breadcrumb.
$siblings = [];
$note     = null;
try {
    $pdo = Database::pdo();
    // Find the note this lesson belongs to (a lesson can technically appear
    // in multiple notes; we pick the first by order).
    $noteStmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, nl.`order` AS this_order
           FROM note_lessons nl JOIN notes n ON n.id = nl.note_id
          WHERE nl.lesson_id = ? ORDER BY nl.`order` ASC LIMIT 1"
    );
    $noteStmt->execute([$id]);
    $note = $noteStmt->fetch() ?: null;

    if ($note) {
        // All published lessons in this note, in order
        $sibStmt = $pdo->prepare(
            "SELECT tl.id, tl.title, tl.slug, nl.`order`
               FROM note_lessons nl JOIN text_lessons tl ON tl.id = nl.lesson_id
              WHERE nl.note_id = ? AND tl.status = 'PUBLISHED'
              ORDER BY nl.`order` ASC"
        );
        $sibStmt->execute([$note['id']]);
        $siblings = $sibStmt->fetchAll();
    }
} catch (\Throwable $e) { /* keep the response working even if join fails */ }

$lesson['note']     = $note ? ['id' => $note['id'], 'title' => $note['title'], 'slug' => $note['slug']] : null;
$lesson['siblings'] = $siblings;

// Resolve the course this lesson lives in (lesson -> note -> course_materials).
// Used by the reader to render a "Back to Course" button.
$lesson['course'] = null;
if ($note) {
    try {
        $courseStmt = Database::pdo()->prepare(
            "SELECT c.id, c.title
               FROM course_materials cm JOIN courses c ON c.id = cm.course_id
              WHERE cm.note_id = ? AND cm.type = 'NOTE'
              ORDER BY c.created_at DESC LIMIT 1"
        );
        $courseStmt->execute([$note['id']]);
        $c = $courseStmt->fetch();
        if ($c) $lesson['course'] = ['id' => $c['id'], 'title' => $c['title']];
    } catch (\Throwable $e) { /* graceful */ }
}

Response::ok(['data' => $lesson]);
