<?php
/**
 * GET /api/notes?id=...                        — read a single note's full content + its lessons.
 * GET /api/notes?course_id=...&q=keyword       — search notes in a course (title-only by default,
 *                                                add &content=1 to also search note body)
 *
 * Access rule: the note must belong to at least one course in which the
 * signed-in user has an ACTIVE/APPROVED enrollment. Admins bypass.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\Note;

Request::requireMethod('GET');
$me = Auth::require();   // must be signed in to read content

// ============================================================
// SEARCH MODE — list notes in a course matching a query.
// Lighter response than single-note mode: no full content body, no lessons.
// ============================================================
$courseIdQuery = (string)Request::query('course_id', '');
if ($courseIdQuery !== '') {
    $q             = trim((string)Request::query('q', ''));
    $searchContent = (string)Request::query('content', '') === '1';
    $isAdmin       = ($me['role'] ?? '') === 'ADMIN';
    $pdo           = Database::pdo();

    // Access check: user must have active enrollment in this course
    $hasEnrollment = true;
    if (!$isAdmin) {
        $accStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM enrollments
              WHERE user_id = ? AND course_id = ?
                AND status IN ('ACTIVE','APPROVED')
                AND (expires_at IS NULL OR expires_at > NOW(3))"
        );
        $accStmt->execute([$me['id'], $courseIdQuery]);
        $hasEnrollment = (int)$accStmt->fetchColumn() > 0;
        if (!$hasEnrollment) {
            // Allow listing if the course has any free-demo notes;
            // results will be filtered to demos only further down.
            $demoStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM course_materials
                  WHERE course_id = ? AND type = 'NOTE' AND is_free_demo = 1"
            );
            $demoStmt->execute([$courseIdQuery]);
            if ((int)$demoStmt->fetchColumn() === 0) {
                Response::error('Enrollment required for this course', 403, ['code' => 'ENROLLMENT_REQUIRED']);
            }
        }
    }

    // Build the WHERE conditions safely
    $clauses = ['cm.course_id = ?', "cm.type = 'NOTE'"];
    $params  = [$courseIdQuery];
    // Non-enrolled users see only free-demo notes
    if (!$isAdmin && !$hasEnrollment) {
        $clauses[] = 'cm.is_free_demo = 1';
    }
    if ($q !== '') {
        if ($searchContent) {
            $clauses[] = '(n.title LIKE ? OR n.content LIKE ?)';
            $params[]  = '%' . $q . '%';
            $params[]  = '%' . $q . '%';
        } else {
            $clauses[] = 'n.title LIKE ?';
            $params[]  = '%' . $q . '%';
        }
    }
    $whereSql = 'WHERE ' . implode(' AND ', $clauses);

    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.slug, n.subject_id,
                s.name AS subject_name, cm.`order` AS material_order,
                (SELECT COUNT(*) FROM note_lessons nl
                   JOIN text_lessons tl ON tl.id = nl.lesson_id
                  WHERE nl.note_id = n.id AND tl.status = 'PUBLISHED'
                ) AS lesson_count
           FROM course_materials cm
           JOIN notes n ON n.id = cm.note_id
           LEFT JOIN subjects s ON s.id = n.subject_id
         $whereSql
          ORDER BY cm.`order` ASC
          LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['lesson_count'] = (int)$r['lesson_count'];
    unset($r);

    Response::ok(['data' => $rows, 'query' => $q, 'searchedContent' => $searchContent]);
}

// ============================================================
// SINGLE-NOTE MODE — full content + lessons (original behavior)
// ============================================================
$id = (string)Request::query('id', '');
if ($id === '') Response::error('id is required', 400);

$note = Note::findById($id);
if (!$note) Response::notFound('Note not found');

$pdo = Database::pdo();
$isAdmin = ($me['role'] ?? '') === 'ADMIN';

if (!$isAdmin) {
    // Is this note inside a course the user has active access to,
    // OR is it explicitly marked as a free demo in any course?
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c
           FROM course_materials cm
           LEFT JOIN enrollments e
                  ON e.course_id = cm.course_id
                 AND e.user_id   = ?
                 AND e.status IN ('ACTIVE','APPROVED')
                 AND (e.expires_at IS NULL OR e.expires_at > NOW(3))
          WHERE cm.note_id = ?
            AND (e.id IS NOT NULL OR cm.is_free_demo = 1)"
    );
    $stmt->execute([$me['id'], $id]);
    if ((int)$stmt->fetch()['c'] === 0) {
        Response::error('You need an active course enrollment to read this note.', 403, [
            'code' => 'ENROLLMENT_REQUIRED',
        ]);
    }
}

// Joined lessons (only PUBLISHED ones for students)
$lessonSql =
    "SELECT tl.id, tl.title, tl.slug, nl.`order`
       FROM note_lessons nl
       JOIN text_lessons tl ON tl.id = nl.lesson_id
      WHERE nl.note_id = ?" . ($isAdmin ? '' : " AND tl.status = 'PUBLISHED'") .
    " ORDER BY nl.`order`";
$stmt = $pdo->prepare($lessonSql);
$stmt->execute([$id]);
$note['lessons'] = $stmt->fetchAll();

// Parent course this note belongs to (for "Back to course" link).
// A note can technically belong to multiple courses via course_materials;
// pick the most recently created. Used by note.html to wire the back link
// to course-detail.html#notes (with the Notes tab pre-selected).
$note['course']        = null;
$note['courseNotes']   = [];   // sibling notes in the same course
try {
    $courseStmt = $pdo->prepare(
        "SELECT c.id, c.title
           FROM course_materials cm JOIN courses c ON c.id = cm.course_id
          WHERE cm.note_id = ? AND cm.type = 'NOTE'
          ORDER BY c.created_at DESC LIMIT 1"
    );
    $courseStmt->execute([$id]);
    $c = $courseStmt->fetch();
    if ($c) {
        $note['course'] = ['id' => $c['id'], 'title' => $c['title']];

        // Does this user have enrollment access to the course?
        $hasAccess = $isAdmin;
        if (!$hasAccess) {
            $accStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM enrollments
                  WHERE user_id = ? AND course_id = ?
                    AND status IN ('ACTIVE','APPROVED')
                    AND (expires_at IS NULL OR expires_at > NOW(3))"
            );
            $accStmt->execute([$me['id'], $c['id']]);
            $hasAccess = (int)$accStmt->fetchColumn() > 0;
        }

        // Sibling notes — all notes in the same course (or just free-demo
        // ones if the user isn't enrolled), with per-note lesson count +
        // subject info, ordered like course-detail's tree.
        $sibSql =
            "SELECT n.id, n.title, n.slug, n.subject_id,
                    s.name AS subject_name, cm.`order` AS material_order,
                    cm.is_free_demo,
                    (SELECT COUNT(*) FROM note_lessons nl
                       JOIN text_lessons tl ON tl.id = nl.lesson_id
                      WHERE nl.note_id = n.id AND tl.status = 'PUBLISHED'
                    ) AS lesson_count
               FROM course_materials cm
               JOIN notes n ON n.id = cm.note_id
               LEFT JOIN subjects s ON s.id = n.subject_id
              WHERE cm.course_id = ? AND cm.type = 'NOTE'" .
            ($hasAccess ? '' : ' AND cm.is_free_demo = 1') .
            " ORDER BY cm.`order` ASC";
        $sibStmt = $pdo->prepare($sibSql);
        $sibStmt->execute([$c['id']]);
        $note['courseNotes'] = array_map(function($r) {
            $r['is_free_demo'] = (int)$r['is_free_demo'];
            $r['lesson_count'] = (int)$r['lesson_count'];
            return $r;
        }, $sibStmt->fetchAll());
    }
} catch (\Throwable $e) { /* graceful */ }

Response::ok(['data' => $note]);
