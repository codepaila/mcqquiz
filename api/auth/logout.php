<?php
/**
 * POST /api/auth/logout
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;

Request::requireMethod('POST');
Auth::logout();
Response::ok(['success' => true, 'message' => 'Logged out.']);
