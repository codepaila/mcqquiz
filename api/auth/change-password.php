<?php
/**
 * POST /api/auth/change-password
 * Body: { currentPassword, newPassword }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Audit;
use Quiznosis\Models\User;

Request::requireMethod('POST');
$me = Auth::require();

$body = Request::body();
Validator::make($body)
    ->required('currentPassword')
    ->required('newPassword')
    ->strongPassword('newPassword')
    ->abortIfFails();

if (!Auth::verifyPassword((string)$body['currentPassword'], (string)$me['password'])) {
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PASSWORD_CHANGE_FAILED',
        'entity_type' => 'USER',
        'entity_id'   => $me['id'],
    ]);
    Response::error('Current password is incorrect.', 401);
}

User::update($me['id'], ['password' => Auth::hashPassword((string)$body['newPassword'])]);
Audit::log([
    'user_id'     => $me['id'],
    'action'      => 'PASSWORD_CHANGED',
    'entity_type' => 'USER',
    'entity_id'   => $me['id'],
]);

Response::ok(['success' => true, 'message' => 'Password changed.']);
