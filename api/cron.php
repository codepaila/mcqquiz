<?php
/**
 * POST /api/cron/run?job=<name>
 *
 * Header: X-Cron-Secret: <secret>   (set CRON_SECRET in env)
 *
 * Jobs:
 *   - purge_sessions     : delete expired sessions
 *   - expire_subscriptions : flip ACTIVE→EXPIRED past end_date, send notif
 *   - purge_audit        : delete audit logs older than 180 days
 *
 * Designed to be triggered by `wget -q -O- --header="X-Cron-Secret: ..."`
 * from a system crontab.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\Session as SessionModel;
use Quiznosis\Models\Subscription;
use Quiznosis\Models\AuditLog;
use Quiznosis\Models\CronJob;
use Quiznosis\Models\Notification;

Request::requireMethod('POST');

$secret = getenv('CRON_SECRET') ?: '';
$incoming = Request::header('X-Cron-Secret') ?? '';
if ($secret === '' || !hash_equals($secret, $incoming)) {
    Response::unauthorized('Bad cron secret');
}

$job = (string)Request::query('job', '');
$validJobs = ['purge_sessions', 'expire_subscriptions', 'purge_audit'];
if (!in_array($job, $validJobs, true)) {
    Response::error('Unknown job. Valid: ' . implode(', ', $validJobs), 400);
}

$startedAt = gmdate('Y-m-d H:i:s');
$jobRow = CronJob::create([
    'job_name'   => $job,
    'status'     => 'RUNNING',
    'started_at' => $startedAt,
]);

$t0 = microtime(true);
$result = [];

try {
    if ($job === 'purge_sessions') {
        $result['deleted'] = SessionModel::purgeExpired();
    }
    elseif ($job === 'expire_subscriptions') {
        $expired = Subscription::expiredActive();
        foreach ($expired as $sub) {
            Subscription::update($sub['id'], ['status' => 'EXPIRED']);
            Notification::create([
                'user_id' => $sub['user_id'],
                'type'    => 'SUBSCRIPTION_EXPIRED',
                'status'  => 'UNREAD',
                'title'   => 'Subscription expired',
                'message' => 'Your subscription has ended. Renew anytime to regain full access.',
                'data'    => ['subscriptionId' => $sub['id']],
            ]);
        }
        $result['expired'] = count($expired);
    }
    elseif ($job === 'purge_audit') {
        $result['deleted'] = AuditLog::purgeOlderThanDays(180);
    }

    CronJob::update($jobRow['id'], [
        'status'       => 'COMPLETED',
        'completed_at' => gmdate('Y-m-d H:i:s'),
        'duration_ms'  => (int)((microtime(true) - $t0) * 1000),
        'metadata'     => $result,
    ]);

    Response::ok(['job' => $job, 'result' => $result]);
}
catch (\Throwable $e) {
    CronJob::update($jobRow['id'], [
        'status'       => 'FAILED',
        'completed_at' => gmdate('Y-m-d H:i:s'),
        'duration_ms'  => (int)((microtime(true) - $t0) * 1000),
        'error'        => $e->getMessage(),
    ]);
    throw $e;
}
