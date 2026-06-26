<?php
/**
 * GET /api/professions
 * Port of src/app/api/professons/route.ts (note: source has a typo "professons")
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\Profession;

Request::requireMethod('GET');
$rows = Profession::where([], ['order' => '`order` ASC, name ASC']);
// Return only id + name to mirror source behavior
$slim = array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $rows);
Response::ok(['data' => $slim]);
