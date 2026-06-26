<?php
/**
 * /api/index.php
 * Health check + endpoint directory. The .htaccess routes /api/foo/bar
 * to /api/foo/bar.php directly; this only handles /api/ itself.
 */
require_once __DIR__ . '/../bootstrap.php';

use Quiznosis\Core\Response;
use Quiznosis\Core\App;

Response::ok([
    'app'     => App::config('app.name'),
    'env'     => App::config('app.env'),
    'time'    => gmdate('c'),
    'message' => 'Quiznosis API is running',
    'routes'  => [
        'auth' => [
            'POST   /api/auth/register',
            'POST   /api/auth/login',
            'POST   /api/auth/logout',
            'GET    /api/auth/me',
            'POST   /api/auth/verify',
            'POST   /api/auth/resend-verification',
            'POST   /api/auth/forgot',
            'POST   /api/auth/reset',
            'POST   /api/auth/change-password',
        ],
        'quiz' => [
            'GET    /api/quiz/sets',
            'GET    /api/quiz/set',
            'POST   /api/quiz/start',
            'POST   /api/quiz/answer',
            'POST   /api/quiz/submit',
            'GET    /api/quiz/results',
            'GET    /api/quiz/leaderboard',
        ],
        'taxonomy' => [
            'GET    /api/professions',
            'GET    /api/subjects',
            'GET    /api/topics',
        ],
        'user' => [
            'GET    /api/profile',
            'PATCH  /api/profile',
            'GET    /api/dashboard',
            'GET    /api/notifications',
            'POST   /api/notifications',
            'POST   /api/reports/submit',
            'GET    /api/subscription/plans',
            'GET    /api/subscription/mine',
        ],
        'admin' => [
            'GET/POST   /api/admin/users',
            'GET/POST/PATCH/DELETE /api/admin/quiz-sets',
            'GET/POST/PATCH/DELETE /api/admin/quizzes',
            'GET/POST/PUT/DELETE   /api/admin/set-items',
            'GET/POST   /api/admin/reports',
            'GET        /api/admin/metrics',
        ],
        'cron' => [
            'POST /api/cron/run?job=purge_sessions|expire_subscriptions|purge_audit',
        ],
    ],
]);
