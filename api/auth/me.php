<?php
/**
 * GET /api/auth/me — returns the current session user, or {user: null}.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\User;

Request::requireMethod('GET');
$u = Auth::user();
Response::ok([
    'authenticated' => (bool)$u,
    'user'          => $u ? User::publicShape($u) : null,
]);
