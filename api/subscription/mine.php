<?php
/**
 * GET /api/subscription/mine — the current user's active subscription, or null.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;

Request::requireMethod('GET');

$me = Auth::require();
$stmt = Database::pdo()->prepare(
    "SELECT s.*, p.name AS plan_name, p.tier, p.price
       FROM subscriptions s
       JOIN subscription_plans p ON p.id = s.plan_id
      WHERE s.user_id = ? AND s.status = 'ACTIVE' AND s.end_date > NOW(3)
      ORDER BY s.end_date DESC
      LIMIT 1"
);
$stmt->execute([$me['id']]);
$current = $stmt->fetch();

Response::ok(['data' => $current ?: null]);
