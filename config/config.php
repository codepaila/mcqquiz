<?php

/**
 * Quiznosis PHP backend — application config.
 * Copy this file to config.local.php and override per environment if needed.
 */
return [
    'app' => [
        'name' => 'Quiznosis',
        'url' => getenv('APP_URL') ?: 'http://localhost/',
        'env' => getenv('APP_ENV') ?: 'development',
        'debug' => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'timezone' => 'Asia/Kathmandu',
        'admin_email' => getenv('ADMIN_EMAIL') ?: null,
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'quiznosis',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: 'root',
        'charset' => 'utf8mb4',
        'ssl' => filter_var(getenv('DB_SSL') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'ssl_ca' => getenv('DB_SSL_CA') ?: __DIR__ . '/ca.pem',
    ],
    'auth' => [
        'session_name' => 'qz_session',
        'session_lifetime' => 60 * 60 * 24 * 14,  // 14 days
        'bcrypt_cost' => 12,
        'verify_token_ttl' => 60 * 60 * 24,  // 24 hours
        'reset_token_ttl' => 60 * 60,  // 1 hour
        'cookie_secure' => false,  // set true in HTTPS prod
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],
    'rate_limit' => [
        'register_window' => 15 * 60,
        'register_max' => 5,
    ],
    'telegram' => [
        // Get this from @BotFather on Telegram after creating your bot.
        // Set via environment variable in production: TELEGRAM_BOT_TOKEN
        'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'bot_username' => getenv('TELEGRAM_BOT_USERNAME') ?: 'QuiznosisBot',
    ],
    'google' => [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
    ],
    'mail' => [
        'driver' => getenv('MAIL_DRIVER') ?: 'log',  // 'log' or 'smtp'
        'from' => getenv('MAIL_FROM') ?: 'noreply@quiznosis.com',
        'from_name' => 'Quiznosis',
        'smtp' => [
            'host' => getenv('SMTP_HOST') ?: '',
            'port' => (int) (getenv('SMTP_PORT') ?: 587),
            'user' => getenv('SMTP_USER') ?: '',
            'pass' => getenv('SMTP_PASS') ?: '',
        ],
    ],
];
