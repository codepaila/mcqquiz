<?php
/**
 * Public · Courses
 *
 *   GET /api/courses                 — list public courses (catalog)
 *   GET /api/courses?id=...           — one course + its materials.
 *                                       Material content is only included if the
 *                                       signed-in user has an ACTIVE enrollment.
 *
 * The response always carries an `access` block telling the frontend what the
 * current viewer can do: { enrolled, status, canViewContent }.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\Course;

Request::requireMethod('GET');
$pdo = Database::pdo();
$me  = Auth::user();   // null if not signed in

$id = Request::query('id');

if ($id) {
    $course = Course::findById((string)$id);
    if (!$course || (int)$course['is_public'] !== 1) {
        Response::notFound('Course not found');
    }

    // Viewer's enrollment state for this course
    $enrollment = null;
    if ($me) {
        $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
        $stmt->execute([$me['id'], $course['id']]);
        $enrollment = $stmt->fetch() ?: null;
    }
    $isAdmin        = $me && ($me['role'] ?? '') === 'ADMIN';
    $activeStatuses = ['ACTIVE', 'APPROVED'];
    $canViewContent = $isAdmin
        || ($enrollment && in_array($enrollment['status'], $activeStatuses, true)
            && (empty($enrollment['expires_at']) || strtotime($enrollment['expires_at']) > time()));

    // Materials — always list titles/types; include content reference only when allowed.
    // question_count is computed live from quiz_set_items so it never goes stale.
    $stmt = $pdo->prepare(
        "SELECT cm.id, cm.type, cm.`order`, cm.quiz_set_id, cm.note_id, cm.is_free_demo,
                qs.name AS quiz_set_name, qs.slug AS quiz_set_slug,
                qs.duration_minutes, qs.total_questions, qs.subject_id AS qs_subject_id,
                (SELECT COUNT(*) FROM quiz_set_items qsi WHERE qsi.quiz_set_id = qs.id) AS question_count,
                qs_subj.name AS quiz_set_subject_name,
                n.title AS note_title, n.slug AS note_slug
           FROM course_materials cm
           LEFT JOIN quiz_sets qs ON qs.id = cm.quiz_set_id
           LEFT JOIN subjects qs_subj ON qs_subj.id = qs.subject_id
           LEFT JOIN notes n      ON n.id  = cm.note_id
          WHERE cm.course_id = ?
          ORDER BY cm.`order`"
    );
    $stmt->execute([$course['id']]);
    $materials = $stmt->fetchAll();
    // Normalize is_free_demo to int for the response
    foreach ($materials as &$m) { $m['is_free_demo'] = (int)$m['is_free_demo']; }
    unset($m);

    // Enrich NOTE materials with their subject + ordered lessons. The course
    // detail view groups notes by subject and shows nested lessons, so we
    // hydrate that here in one round trip.
    $noteIds = array_filter(array_column($materials, 'note_id'));
    $subjectsById = [];
    $lessonsByNote = [];
    if ($noteIds) {
        $ph = implode(',', array_fill(0, count($noteIds), '?'));

        // Subject id per note + subject names (one extra query, small)
        $subj = $pdo->prepare(
            "SELECT n.id AS note_id, n.subject_id, s.name AS subject_name
               FROM notes n
               LEFT JOIN subjects s ON s.id = n.subject_id
              WHERE n.id IN ($ph)"
        );
        $subj->execute(array_values($noteIds));
        $noteSubjects = [];
        foreach ($subj->fetchAll() as $r) {
            $noteSubjects[$r['note_id']] = [
                'subject_id'   => $r['subject_id'],
                'subject_name' => $r['subject_name'],
            ];
            if ($r['subject_id'] && !isset($subjectsById[$r['subject_id']])) {
                $subjectsById[$r['subject_id']] = $r['subject_name'];
            }
        }

        // Lessons per note, ordered. We only expose id/title for the tree;
        // full content is fetched on /api/courses/lessons/:id.
        $les = $pdo->prepare(
            "SELECT nl.note_id, tl.id, tl.title, tl.slug, nl.`order`
               FROM note_lessons nl
               JOIN text_lessons tl ON tl.id = nl.lesson_id
              WHERE nl.note_id IN ($ph)
                AND tl.status = 'PUBLISHED'
              ORDER BY nl.note_id, nl.`order` ASC"
        );
        $les->execute(array_values($noteIds));
        foreach ($les->fetchAll() as $r) {
            $lessonsByNote[$r['note_id']][] = [
                'id'    => $r['id'],
                'title' => $r['title'],
                'slug'  => $r['slug'],
                'order' => (int)$r['order'],
            ];
        }

        // Stitch onto each NOTE material
        foreach ($materials as &$m) {
            if ($m['type'] === 'NOTE' && !empty($m['note_id'])) {
                $sub = $noteSubjects[$m['note_id']] ?? ['subject_id'=>null,'subject_name'=>null];
                $m['subject_id']   = $sub['subject_id'];
                $m['subject_name'] = $sub['subject_name'];
                $m['lessons']      = $lessonsByNote[$m['note_id']] ?? [];
            }
        }
        unset($m);
    }

    // profession name
    $profName = null;
    if (!empty($course['profession_id'])) {
        $p = $pdo->prepare("SELECT name FROM professions WHERE id = ?");
        $p->execute([$course['profession_id']]);
        $profName = $p->fetch()['name'] ?? null;
    }

    $course['profession_name'] = $profName;
    $course['materials']       = $materials;

    // How many users currently have ACTIVE/APPROVED enrollment for this course?
    $enrolledStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM enrollments
          WHERE course_id = ? AND status IN ('ACTIVE','APPROVED')
            AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $enrolledStmt->execute([$course['id']]);
    $course['enrolledCount'] = (int)$enrolledStmt->fetchColumn();

    // For the Active Plan card, look up the matching active purchase + its plan
    $planInfo = null;
    if ($enrollment && !empty($enrollment['user_id'])) {
        $p = $pdo->prepare(
            "SELECT p.id AS purchase_id, p.course_subscription_plan_id,
                    p.amount, p.currency, p.created_at AS purchase_date, csp.name AS plan_name, csp.duration_days
               FROM purchases p
               LEFT JOIN course_subscription_plans csp ON csp.id = p.course_subscription_plan_id
              WHERE p.user_id = ? AND p.course_id = ? AND p.status = 'ACTIVE'
              ORDER BY p.created_at DESC LIMIT 1"
        );
        try {
            $p->execute([$enrollment['user_id'], $course['id']]);
            $planInfo = $p->fetch() ?: null;
        } catch (\Throwable $e) { /* plans table may not exist on older DBs */ }
    }

    // Fetch pending purchase plan id for the "view pending" link
    $pendingPurchaseId  = null;
    $pendingPlanId      = null;
    if ($me && $enrollment && ($enrollment['status'] ?? '') === 'PENDING') {
        try {
            $pp = $pdo->prepare(
                "SELECT id, course_subscription_plan_id FROM purchases
                  WHERE user_id = ? AND course_id = ? AND status = 'PENDING'
                  ORDER BY created_at DESC LIMIT 1"
            );
            $pp->execute([$me['id'], $course['id']]);
            $pendingRow = $pp->fetch();
            if ($pendingRow) {
                $pendingPurchaseId = $pendingRow['id'];
                $pendingPlanId     = $pendingRow['course_subscription_plan_id'];
            }
        } catch (\Throwable $e) {}
    }

    $course['access'] = [
        'signedIn'        => (bool)$me,
        'enrolled'        => (bool)$enrollment,
        'status'          => $enrollment['status'] ?? null,
        'canViewContent'  => (bool)$canViewContent,
        'expiresAt'       => $enrollment['expires_at'] ?? null,
        'startsAt'        => $enrollment['starts_at'] ?? null,
        'pendingPurchaseId' => $pendingPurchaseId,
        'pendingPlanId'     => $pendingPlanId,
        'plan'           => $planInfo ? [
            'name'         => $planInfo['plan_name'] ?: 'Standard plan',
            'amount'       => $planInfo['amount'] ?? null,
            'currency'     => $planInfo['currency'] ?? null,
            'purchaseDate' => $planInfo['purchase_date'] ?? null,
            'durationDays' => isset($planInfo['duration_days']) ? (int)$planInfo['duration_days'] : null,
        ] : null,
    ];

    // Lazy: if the user's enrollment expires within 7 days, ensure a
    // SUBSCRIPTION_EXPIRING_SOON notification exists. De-dup by checking
    // for one created in the last 24h.
    if ($me && $enrollment && !empty($enrollment['expires_at'])) {
        $expiresTs = strtotime($enrollment['expires_at']);
        $secondsLeft = $expiresTs - time();
        if ($secondsLeft > 0 && $secondsLeft <= 7 * 86400) {
            $daysLeft = max(1, (int)ceil($secondsLeft / 86400));
            try {
                $exists = $pdo->prepare(
                    "SELECT id FROM notifications
                      WHERE user_id = ? AND type = 'SUBSCRIPTION_EXPIRING_SOON'
                        AND status <> 'ARCHIVED'
                        AND sent_at > (NOW() - INTERVAL 1 DAY)
                        AND data LIKE ?
                      LIMIT 1"
                );
                $needle = '%"course_id":"' . $course['id'] . '"%';
                $exists->execute([$me['id'], $needle]);
                if (!$exists->fetchColumn()) {
                    $nid = \Quiznosis\Core\Util::objectId();
                    $ins = $pdo->prepare(
                        "INSERT INTO notifications
                          (id, user_id, type, status, title, message, data, priority)
                         VALUES (?, ?, 'SUBSCRIPTION_EXPIRING_SOON', 'UNREAD', ?, ?, ?, 10)"
                    );
                    $title = 'Your course access expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
                    $msg   = 'Access to "' . $course['title'] . '" expires soon. Renew now to keep your progress.';
                    $data  = json_encode(['course_id' => $course['id'], 'days_left' => $daysLeft]);
                    $ins->execute([$nid, $me['id'], $title, $msg, $data]);
                }
            } catch (\Throwable $e) { /* don't break the page if notifications table is missing */ }
        }
    }

    Response::ok(['data' => $course]);
}

/* ---- catalog list ---- */
$rows = $pdo->query(
    "SELECT c.id, c.title, c.slug, c.description, c.cover_url, c.access_type, c.requires_approval,
            c.profession_id, p.name AS profession_name,
            c.exam_type_id, et0.name AS exam_type_name,
            (SELECT COUNT(*) FROM course_materials cm WHERE cm.course_id = c.id AND cm.type='QUIZ_SET') AS quiz_sets_count,
            (SELECT COUNT(*) FROM course_materials cm WHERE cm.course_id = c.id AND cm.type='NOTE')     AS notes_count,
            (SELECT COUNT(DISTINCT e.user_id) FROM enrollments e
              WHERE e.course_id = c.id AND e.status IN ('ACTIVE','APPROVED')
                AND (e.expires_at IS NULL OR e.expires_at > NOW())) AS enrolled_count
       FROM courses c
       LEFT JOIN professions p ON p.id = c.profession_id
       LEFT JOIN exam_types et0 ON et0.id = c.exam_type_id
      WHERE c.is_public = 1
      ORDER BY c.created_at DESC"
)->fetchAll();

// Annotate each course with the distinct exam types it covers, for the
// Exam Type filter chip strip on the catalog page. This is the union of:
//   1. The course's own explicit exam_type_id (set directly by the admin
//      on the course form — the reliable, primary signal).
//   2. The exam types of whichever quiz sets happen to be bundled inside
//      it (kept as a fallback/extra signal for courses that haven't had
//      an explicit exam type set, or that legitimately span more than one).
if ($rows) {
    $courseIds = array_column($rows, 'id');
    $place = implode(',', array_fill(0, count($courseIds), '?'));
    $etStmt = $pdo->prepare(
        "SELECT cm.course_id, qs.exam_type_id, et.name AS exam_type_name
           FROM course_materials cm
           JOIN quiz_sets qs ON qs.id = cm.quiz_set_id
           LEFT JOIN exam_types et ON et.id = qs.exam_type_id
          WHERE cm.course_id IN ($place)
            AND cm.type = 'QUIZ_SET'
            AND qs.exam_type_id IS NOT NULL
          GROUP BY cm.course_id, qs.exam_type_id"
    );
    $etStmt->execute($courseIds);
    $etByCourse = [];
    foreach ($etStmt->fetchAll() as $r) {
        $cid = $r['course_id'];
        if (!isset($etByCourse[$cid])) $etByCourse[$cid] = [];
        $etByCourse[$cid][] = ['id' => $r['exam_type_id'], 'name' => $r['exam_type_name']];
    }
    foreach ($rows as &$c) {
        $merged = $etByCourse[$c['id']] ?? [];
        if ($c['exam_type_id'] && !in_array($c['exam_type_id'], array_column($merged, 'id'), true)) {
            $merged[] = ['id' => $c['exam_type_id'], 'name' => $c['exam_type_name']];
        }
        $c['exam_types'] = $merged;
    }
    unset($c);
}

// If signed in, annotate each with the viewer's enrollment status
if ($me && $rows) {
    $stmt = $pdo->prepare("SELECT course_id, status FROM enrollments WHERE user_id = ?");
    $stmt->execute([$me['id']]);
    $mineByCourse = [];
    foreach ($stmt->fetchAll() as $e) $mineByCourse[$e['course_id']] = $e['status'];
    foreach ($rows as &$c) {
        $c['my_enrollment_status'] = $mineByCourse[$c['id']] ?? null;
    }
    unset($c);
}

Response::ok(['data' => $rows]);
