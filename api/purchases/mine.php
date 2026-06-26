<?php
/**
 * GET /api/purchases/mine?status=PENDING|COMPLETED|FAILED|REFUNDED
 * Returns the current user's purchases, most recent first.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Models\Purchase;

$me = Auth::require();
Request::requireMethod('GET');

$status = Request::query('status');
$rows = Purchase::listForUser($me['id'], $status ? (string)$status : null);
Response::ok(['data' => $rows]);
