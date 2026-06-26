<?php
/**
 * POST /api/admin/recount-questions
 *
 * One-time maintenance endpoint. Recounts quiz_set_items for every quiz set
 * and rewrites the cached quiz_sets.total_questions column, fixing any sets
 * whose count went stale (e.g. questions added before the auto-sync existed).
 *
 * Admin only. Safe to run repeatedly.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Database;
use Quiznosis\Core\Audit;

$me = Auth::requireAdmin();
Request::requireMethod('POST');

$pdo = Database::pdo();

// Every set with its stored count and its real item count
$rows = $pdo->query(
    "SELECT qs.id, qs.name, qs.total_questions AS stored,
            (SELECT COUNT(*) FROM quiz_set_items qsi WHERE qsi.quiz_set_id = qs.id) AS actual
       FROM quiz_sets qs"
)->fetchAll();

$fixed = [];
$update = $pdo->prepare("UPDATE quiz_sets SET total_questions = ? WHERE id = ?");
foreach ($rows as $r) {
    if ((int)$r['stored'] !== (int)$r['actual']) {
        $update->execute([(int)$r['actual'], $r['id']]);
        $fixed[] = [
            'id'    => $r['id'],
            'name'  => $r['name'],
            'was'   => (int)$r['stored'],
            'now'   => (int)$r['actual'],
        ];
    }
}

Audit::log([
    'user_id'     => $me['id'],
    'action'      => 'QUIZ_SETS_RECOUNTED',
    'entity_type' => 'QUIZ_SET',
    'details'     => ['fixed_count' => count($fixed)],
]);

Response::ok([
    'scanned' => count($rows),
    'fixed'   => count($fixed),
    'details' => $fixed,
]);
