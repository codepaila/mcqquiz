<?php
/**
 * Admin · Subscription Plans CRUD
 *
 *   GET    /api/admin/subscription-plans                — list (ordered, with purchase counts)
 *   POST   /api/admin/subscription-plans                — create
 *   PATCH  /api/admin/subscription-plans                — update { id, ...fields }
 *   DELETE /api/admin/subscription-plans?id=...         — delete (blocked if used by purchases)
 *
 * Body fields:
 *   name           (required, 120 char limit enforced by DB)
 *   tier           (FREE | BASIC | PREMIUM | ENTERPRISE)
 *   durationDays   (int, >0)
 *   price          (number; 0 for free)
 *   isActive       (bool; defaults true)
 *   slug           (optional, auto from name)
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Util;
use Quiznosis\Core\Validator;
use Quiznosis\Core\Database;
use Quiznosis\Models\SubscriptionPlan;

$me = Auth::requireAdmin();
$method = Request::method();

$ALLOWED_TIERS = ['FREE', 'BASIC', 'PREMIUM', 'ENTERPRISE'];

if ($method === 'GET') {
    $rows = Database::pdo()->query(
        "SELECT sp.*,
                (SELECT COUNT(*) FROM purchases p
                  WHERE p.subscription_plan_id = sp.id AND p.status = 'COMPLETED' AND p.is_active = 1) AS active_purchases
           FROM subscription_plans sp
          ORDER BY FIELD(sp.tier,'FREE','BASIC','PREMIUM','ENTERPRISE'), sp.price"
    )->fetchAll();
    Response::ok(['data' => $rows]);
}

if ($method === 'POST') {
    $body = Request::body();
    Validator::make($body)
        ->required('name')
        ->required('tier')
        ->in('tier', $ALLOWED_TIERS)
        ->required('durationDays')
        ->abortIfFails();

    $duration = (int)$body['durationDays'];
    if ($duration <= 0) Response::error('durationDays must be greater than 0', 400);

    $price = isset($body['price']) ? (float)$body['price'] : 0.0;
    if ($price < 0) Response::error('price cannot be negative', 400);

    $name = trim((string)$body['name']);
    $slug = !empty($body['slug']) ? Util::slugify((string)$body['slug']) : Util::slugify($name);
    if (SubscriptionPlan::firstWhere(['slug' => $slug])) {
        $slug .= '-' . substr(Util::objectId(), 0, 6);
    }

    $row = SubscriptionPlan::create([
        'name'          => $name,
        'slug'          => $slug,
        'tier'          => $body['tier'],
        'duration_days' => $duration,
        'price'         => $price,
        'is_active'     => array_key_exists('isActive', $body) ? (!empty($body['isActive']) ? 1 : 0) : 1,
    ]);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'SUBSCRIPTION_PLAN_CREATED',
        'entity_type' => 'SUBSCRIPTION_PLAN',
        'entity_id'   => $row['id'],
    ]);
    Response::created(['data' => $row]);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $body = Request::body();
    $id = (string)($body['id'] ?? '');
    if ($id === '') Response::error('id is required', 400);
    $existing = SubscriptionPlan::findById($id);
    if (!$existing) Response::notFound('Subscription plan not found');

    $map = [
        'name'         => 'name',
        'slug'         => 'slug',
        'tier'         => 'tier',
        'durationDays' => 'duration_days',
        'price'        => 'price',
        'isActive'     => 'is_active',
    ];
    $patch = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $body)) continue;
        $val = $body[$k];
        if ($k === 'isActive')      $val = !empty($val) ? 1 : 0;
        if ($k === 'durationDays')  $val = (int)$val;
        if ($k === 'price')         $val = (float)$val;
        if ($k === 'tier' && !in_array($val, $ALLOWED_TIERS, true)) {
            Response::error('Invalid tier', 400);
        }
        if ($k === 'name') $val = trim((string)$val);
        if ($k === 'slug' && $val) $val = Util::slugify((string)$val);
        $patch[$col] = $val;
    }
    if (!$patch) Response::error('No fields to update', 400);

    if (isset($patch['slug'])) {
        $dup = SubscriptionPlan::firstWhere(['slug' => $patch['slug']]);
        if ($dup && $dup['id'] !== $id) Response::error('Slug already in use', 409);
    }

    $updated = SubscriptionPlan::update($id, $patch);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'SUBSCRIPTION_PLAN_UPDATED',
        'entity_type' => 'SUBSCRIPTION_PLAN',
        'entity_id'   => $id,
        'details'     => $patch,
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'DELETE') {
    $id = (string)Request::query('id', '');
    if ($id === '') Response::error('id is required', 400);
    if (!SubscriptionPlan::findById($id)) Response::notFound('Subscription plan not found');

    $stmt = Database::pdo()->prepare("SELECT COUNT(*) AS c FROM purchases WHERE subscription_plan_id = ?");
    $stmt->execute([$id]);
    $refs = (int)$stmt->fetch()['c'];
    if ($refs > 0) {
        Response::error("Cannot delete — {$refs} purchase(s) reference this plan. Deactivate it instead.", 409);
    }

    SubscriptionPlan::deleteById($id);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'SUBSCRIPTION_PLAN_DELETED',
        'entity_type' => 'SUBSCRIPTION_PLAN',
        'entity_id'   => $id,
    ]);
    Response::ok(['success' => true]);
}

Response::error('Method not allowed', 405);
