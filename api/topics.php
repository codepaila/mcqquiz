<?php
/**
 * GET /api/topics?subject_id=<id>
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\Topic;

Request::requireMethod('GET');

$where = [];
if ($sid = Request::query('subject_id')) $where['subject_id'] = $sid;

$rows = Topic::where($where, ['order' => '`order` ASC, name ASC']);
Response::ok(['data' => $rows]);
