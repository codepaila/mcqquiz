<?php
/**
 * GET /api/exam-types?profession_id=...
 * Public read of exam-type taxonomy (used by quiz filters etc.).
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Database;

Request::requireMethod('GET');
$pid = Request::query('profession_id');
$sql = 'SELECT id, name, slug, profession_id FROM exam_types';
$params = [];
if ($pid) { $sql .= ' WHERE profession_id = ?'; $params[] = $pid; }
$sql .= ' ORDER BY `order`, name';
$stmt = Database::pdo()->prepare($sql);
$stmt->execute($params);
Response::ok(['data' => $stmt->fetchAll()]);
