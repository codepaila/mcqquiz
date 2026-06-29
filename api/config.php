<?php

require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\App;
use Quiznosis\Core\Response;

Response::ok([
    'google_client_id' => App::config('google.client_id'),
    'telegram_bot_username' => App::config('telegram.bot_username'),
]);