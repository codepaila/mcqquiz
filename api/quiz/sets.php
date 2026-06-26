<?php
/**
 * GET /api/quiz/sets
 * Query: ?mode=&subject_id=&topic_id=&profession_id=&exam_type_id=&q=&page=1&per_page=20
 *
 * Returns paginated PUBLISHED quiz sets with joined taxonomy names,
 * attempt counts and average score. Public — anyone can list.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;

Request::requireMethod('GET');

$mode       = Request::query('mode');
$subject    = Request::query('subject_id');
$topic      = Request::query('topic_id');
$profession = Request::query('profession_id');
$examType   = Request::query('exam_type_id');
$access     = Request::query('access');               // FREE | PREMIUM
$q          = trim((string)Request::query('q', ''));
$page       = max(1, (int)Request::query('page', 1));
$perPage    = min(100, max(1, (int)Request::query('per_page', 100)));
$offset     = ($page - 1) * $perPage;

$where  = ["qs.status = 'PUBLISHED'"];
$params = [];

if ($mode)       { $where[] = 'qs.mode = :mode';            $params[':mode'] = $mode; }
if ($subject)    { $where[] = 'qs.subject_id = :sub';       $params[':sub']  = $subject; }
if ($topic)      { $where[] = 'qs.topic_id = :top';         $params[':top']  = $topic; }
if ($profession) { $where[] = 'qs.profession_id = :prof';   $params[':prof'] = $profession; }
if ($examType)   { $where[] = 'qs.exam_type_id = :et';      $params[':et']   = $examType; }
if ($access === 'FREE')    { $where[] = 'qs.is_paid = 0'; }
if ($access === 'PREMIUM') { $where[] = 'qs.is_paid = 1'; }
if ($q !== '')   { $where[] = '(qs.name LIKE :q OR qs.description LIKE :q)'; $params[':q'] = '%' . $q . '%'; }

$whereSql = 'WHERE ' . implode(' AND ', $where);

$pdo = Database::pdo();
$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM quiz_sets qs $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['c'];

$stmt = $pdo->prepare(
    "SELECT qs.id, qs.name, qs.slug, qs.description, qs.mode, qs.duration_minutes,
            qs.passing_score, qs.subject_id, qs.topic_id, qs.profession_id, qs.exam_type_id,
            qs.total_questions, qs.is_paid, qs.price, qs.currency,
            qs.enable_negative_marking, qs.negative_mark_per_question, qs.created_at,
            s.name  AS subject_name,
            p.name  AS profession_name,
            et.name AS exam_type_name,
            (SELECT COUNT(*) FROM quiz_set_attempts a WHERE a.quiz_set_id = qs.id) AS attempts_count,
            (SELECT ROUND(AVG(a.percentage)) FROM quiz_set_attempts a
              WHERE a.quiz_set_id = qs.id AND a.status = 'COMPLETED') AS avg_score
       FROM quiz_sets qs
       LEFT JOIN subjects   s  ON s.id  = qs.subject_id
       LEFT JOIN professions p ON p.id  = qs.profession_id
       LEFT JOIN exam_types  et ON et.id = qs.exam_type_id
       $whereSql
       ORDER BY qs.created_at DESC
       LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

Response::ok([
    'data'       => $rows,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / max(1,$perPage)),
    ],
]);
