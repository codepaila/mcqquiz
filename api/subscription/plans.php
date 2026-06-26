<?php
/**
 * GET /api/subscription/plans — list active subscription plans (public).
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\SubscriptionPlan;

Request::requireMethod('GET');

$rows = SubscriptionPlan::where(['is_active' => 1], ['order' => 'price ASC']);
Response::ok(['data' => $rows]);
