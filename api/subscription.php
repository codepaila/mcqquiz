<?php
/**
 * GET /api/subscription/plans            — list active plans (public)
 * GET /api/subscription/mine             — current user's active subscription
 * Note: actual payment integration is intentionally out of scope; this just
 * exposes plan metadata and the current state.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Models\SubscriptionPlan;

Request::requireMethod('GET');

$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';

if (str_ends_with($path, '/plans')) {
    $rows = SubscriptionPlan::where(['is_active' => 1], ['order' => 'price ASC']);
    Response::ok(['data' => $rows]);
}

// /mine
$me = Auth::require();
$stmt = Database::pdo()->prepare(
    "SELECT s.*, p.name AS plan_name, p.tier, p.price
       FROM subscriptions s
       JOIN subscription_plans p ON p.id = s.plan_id
      WHERE s.user_id = ? AND s.status='ACTIVE' AND s.end_date > NOW(3)
      ORDER BY s.end_date DESC LIMIT 1"
);
$stmt->execute([$me['id']]);
$current = $stmt->fetch();
Response::ok(['data' => $current ?: null]);
