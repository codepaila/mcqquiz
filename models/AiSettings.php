<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

/**
 * AiSettings — single-row settings table for the admin "Improve with AI"
 * explanation-editing feature in admin-content.html.
 *
 * Deliberately generic (endpoint URL + API key + model + prompt template,
 * all editable from the admin UI) rather than hardcoded to one provider,
 * so it works with DeepSeek today and any other OpenAI-compatible chat
 * completions API later just by changing these settings — no code changes.
 *
 * Table: ai_settings (id=1, always one row)
 * Columns: id, provider_label, api_base_url, api_key, model,
 *          prompt_template, enabled, created_at, updated_at
 *
 * Run the migration SQL below once to create the table:
 *
 *   CREATE TABLE IF NOT EXISTS `ai_settings` (
 *     `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
 *     `provider_label`   VARCHAR(60)      DEFAULT NULL,
 *     `api_base_url`     VARCHAR(255)     DEFAULT NULL,
 *     `api_key`          VARCHAR(255)     DEFAULT NULL,
 *     `model`            VARCHAR(100)     DEFAULT NULL,
 *     `prompt_template`  TEXT             DEFAULT NULL,
 *     `enabled`          TINYINT(1)       NOT NULL DEFAULT 0,
 *     `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     PRIMARY KEY (`id`)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
class AiSettings
{
    private const TABLE = 'ai_settings';

    // Sensible out-of-the-box default for DeepSeek's OpenAI-compatible API.
    // All of these are just the *initial* row values — fully editable after.
    private const DEFAULT_BASE_URL = 'https://api.deepseek.com/chat/completions';
    private const DEFAULT_MODEL    = 'deepseek-chat';
    private const DEFAULT_PROMPT   = <<<'PROMPT'
You are helping an admin improve a medical exam quiz explanation. Rewrite the explanation to be clearer, more complete, and well-structured using Markdown (use **bold** for key terms, bullet points for lists, etc). Keep it medically accurate and concise — do not invent facts not implied by the question and correct answer. Return ONLY the improved explanation text, with no preamble.

Question: {{question}}

Options:
{{options}}

Correct answer: {{correct_answer}}

Current explanation (may be empty):
{{explanation}}
PROMPT;

    /** Get the settings row, auto-creating it and the table if needed. */
    public static function get(): array
    {
        $pdo = Database::pdo();
        self::ensureTable($pdo);

        $row = $pdo->query("SELECT * FROM " . self::TABLE . " WHERE id = 1")->fetch();
        if (!$row) {
            $stmt = $pdo->prepare(
                "INSERT INTO " . self::TABLE . "
                    (id, provider_label, api_base_url, model, prompt_template, enabled)
                 VALUES (1, 'DeepSeek', ?, ?, ?, 0)"
            );
            $stmt->execute([self::DEFAULT_BASE_URL, self::DEFAULT_MODEL, self::DEFAULT_PROMPT]);
            $row = $pdo->query("SELECT * FROM " . self::TABLE . " WHERE id = 1")->fetch();
        }
        return $row ?: [];
    }

    /** Save / patch the single settings row. */
    public static function save(array $patch): array
    {
        self::get(); // ensure row + table exists

        $allowed = ['provider_label', 'api_base_url', 'api_key', 'model', 'prompt_template', 'enabled'];
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
            CREATE TABLE IF NOT EXISTS `ai_settings` (
                `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `provider_label`   VARCHAR(60)      DEFAULT NULL,
                `api_base_url`     VARCHAR(255)     DEFAULT NULL,
                `api_key`          VARCHAR(255)     DEFAULT NULL,
                `model`            VARCHAR(100)     DEFAULT NULL,
                `prompt_template`  TEXT             DEFAULT NULL,
                `enabled`          TINYINT(1)       NOT NULL DEFAULT 0,
                `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
