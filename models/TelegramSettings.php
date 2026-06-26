<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

/**
 * TelegramSettings — single-row settings table for admin Telegram notifications.
 *
 * Table: telegram_settings (id=1, always one row)
 * Columns: id, bot_token, notify_chat_id, notify_enabled, created_at, updated_at
 *
 * Run the migration SQL below once to create the table:
 *
 *   CREATE TABLE IF NOT EXISTS `telegram_settings` (
 *     `id`              TINYINT UNSIGNED NOT NULL DEFAULT 1,
 *     `bot_token`       VARCHAR(255)     DEFAULT NULL,
 *     `notify_chat_id`  VARCHAR(64)      DEFAULT NULL,
 *     `notify_enabled`  TINYINT(1)       NOT NULL DEFAULT 0,
 *     `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     PRIMARY KEY (`id`)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
class TelegramSettings
{
    private const TABLE = 'telegram_settings';

    /** Get the settings row, auto-creating it and the table if needed. */
    public static function get(): array
    {
        $pdo = Database::pdo();

        // Auto-create table if it doesn't exist yet
        self::ensureTable($pdo);

        $row = $pdo->query("SELECT * FROM " . self::TABLE . " WHERE id = 1")->fetch();
        if (!$row) {
            $pdo->exec("INSERT INTO " . self::TABLE . " (id, notify_enabled) VALUES (1, 0)");
            $row = $pdo->query("SELECT * FROM " . self::TABLE . " WHERE id = 1")->fetch();
        }
        return $row ?: [];
    }

    /** Save / patch the single settings row. */
    public static function save(array $patch): array
    {
        self::get(); // ensure row + table exists

        $allowed = ['bot_token', 'notify_chat_id', 'notify_enabled'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $patch)) {
                $set[]    = "`{$col}` = ?";
                $params[] = $patch[$col] === '' ? null : $patch[$col];
            }
        }

        if (empty($set)) return self::get();

        $params[] = 1; // WHERE id = 1
        Database::pdo()
            ->prepare("UPDATE " . self::TABLE . " SET " . implode(', ', $set) . " WHERE id = ?")
            ->execute($params);

        return self::get();
    }

    /** Create the table if it doesn't already exist. */
    private static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `telegram_settings` (
                `id`              TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `bot_token`       VARCHAR(255)     DEFAULT NULL,
                `notify_chat_id`  VARCHAR(64)      DEFAULT NULL,
                `notify_enabled`  TINYINT(1)       NOT NULL DEFAULT 0,
                `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
