<?php
/**
 * GET  /api/profile         — current user with profession info
 * PATCH /api/profile        — update firstName, lastName, avatar, professionId
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\User;
use Quiznosis\Models\Profession;

$me = Auth::require();

if (Request::method() === 'GET') {
    $profession = $me['profession_id'] ? Profession::findById($me['profession_id']) : null;
    Response::ok([
        'user'       => User::publicShape($me),
        'profession' => $profession,
    ]);
}

Request::requireMethod('PATCH', 'POST');

$body = Request::body();
$patch = [];
if (isset($body['firstName'])) $patch['first_name'] = trim((string)$body['firstName']);
if (isset($body['lastName']))  $patch['last_name']  = trim((string)$body['lastName']);
if (isset($body['phone']))     $patch['phone']      = trim((string)$body['phone']);
if (isset($body['avatar']))    $patch['avatar']     = (string)$body['avatar'];
if (array_key_exists('professionId', $body)) {
    $pid = $body['professionId'];
    if ($pid && !Profession::findById((string)$pid)) {
        Response::error('Invalid profession', 400);
    }
    $patch['profession_id'] = $pid ?: null;
}

if (empty($patch)) Response::error('No fields to update', 400);

$updated = User::update($me['id'], $patch);
Response::ok(['user' => User::publicShape($updated)]);
