<?php
/**
 * GET /api/subjects?profession_id=<id>
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\Subject;

Request::requireMethod('GET');

$where = [];
if ($pid = Request::query('profession_id')) $where['profession_id'] = $pid;

$rows = Subject::where($where, ['order' => '`order` ASC, name ASC']);
Response::ok(['data' => $rows]);
