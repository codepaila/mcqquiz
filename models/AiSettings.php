<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;

/**
 * AiSettings — single-row settings table for the admin "Improve with AI"
 * explanation-editing feature in admin-content.html.
 *
 * Deliberately generic (endpoint URL + API key + model, all editable from
 * the admin UI) rather than hardcoded to one provider, so it works with
 * DeepSeek today and any other OpenAI-compatible chat completions API
 * later just by changing these settings — no code changes.
 *
 * `prompts` holds an ordered array of named prompt "tabs":
 *   [ { id: string, name: string, prompt: string }, ... ]
 * Each shows as its own button in the question editor; clicking one runs
 * that specific prompt template against the same API connection.
 *
 * Table: ai_settings (id=1, always one row)
 * Columns: id, provider_label, api_base_url, api_key, model,
 *          prompts, enabled, created_at, updated_at
 *
 * IMPORTANT: this class deliberately never runs CREATE TABLE at runtime.
 * Some hosts restrict DDL privileges for the app's DB user, and an
 * unhandled DDL failure crashes PHP before any response is sent, which
 * shows up to the browser as a bare 502 with no error message. Run the
 * migration SQL manually once (see bottom of this file) instead.
 */
class AiSettings
{
    private const DEFAULT_BASE_URL = 'https://api.deepseek.com/chat/completions';
    private const DEFAULT_MODEL    = 'deepseek-chat';

    private const DEFAULT_PROMPTS = [
        [
            'id'     => 'improve',
            'name'   => 'Improve',
            'prompt' => <<<'PROMPT'
You are helping an admin improve a medical exam quiz explanation. Rewrite the explanation to be clearer, more complete, and well-structured using Markdown (use **bold** for key terms, bullet points for lists, etc). Keep it medically accurate and concise — do not invent facts not implied by the question and correct answer. Return ONLY the improved explanation text, with no preamble.

Question: {{question}}

Options:
{{options}}

Correct answer: {{correct_answer}}

Current explanation (may be empty):
{{explanation}}
PROMPT,
        ],
    ];

    /** Get the settings row. Inserts a default row (DML only, no DDL) if none exists yet. */
    public static function get(): array
    {
        $pdo = Database::pdo();
        $row = $pdo->query("SELECT * FROM `ai_settings` WHERE id = 1")->fetch();

        if (!$row) {
            $stmt = $pdo->prepare(
                "INSERT INTO `ai_settings`
                    (id, provider_label, api_base_url, model, prompts, enabled)
                 VALUES (1, 'DeepSeek', ?, ?, ?, 0)"
            );
            $stmt->execute([
                self::DEFAULT_BASE_URL,
                self::DEFAULT_MODEL,
                json_encode(self::DEFAULT_PROMPTS),
            ]);
            $row = $pdo->query("SELECT * FROM `ai_settings` WHERE id = 1")->fetch();
        }

        if ($row) {
            $prompts = json_decode((string)($row['prompts'] ?? ''), true);
            $row['prompts'] = (is_array($prompts) && !empty($prompts)) ? $prompts : self::DEFAULT_PROMPTS;
        }

        return $row ?: [];
    }

    /** Look up a single prompt by id. Falls back to the first prompt if id is empty/not found. */
    public static function findPrompt(string $promptId): ?array
    {
        $prompts = self::get()['prompts'] ?? [];
        if (empty($prompts)) return null;

        if ($promptId !== '') {
            foreach ($prompts as $p) {
                if (($p['id'] ?? '') === $promptId) return $p;
            }
        }
        return $prompts[0]; // fallback: first configured prompt
    }

    /** Save / patch the single settings row. */
    public static function save(array $patch): array
    {
        self::get(); // ensure row exists (INSERT only, no DDL)

        $set    = [];
        $params = [];

        foreach (['provider_label', 'api_base_url', 'api_key', 'model', 'enabled'] as $col) {
            if (array_key_exists($col, $patch)) {
                $set[]    = "`{$col}` = ?";
                $params[] = $patch[$col] === '' ? null : $patch[$col];
            }
        }

        if (array_key_exists('prompts', $patch) && is_array($patch['prompts'])) {
            $clean = [];
            foreach ($patch['prompts'] as $p) {
                $name   = trim((string)($p['name']   ?? ''));
                $prompt = (string)($p['prompt'] ?? '');
                if ($name === '' || trim($prompt) === '') continue;
                $id = trim((string)($p['id'] ?? ''));
                if ($id === '') $id = substr(md5(uniqid('', true)), 0, 8);
                $clean[] = ['id' => $id, 'name' => $name, 'prompt' => $prompt];
            }
            $set[]    = "`prompts` = ?";
            $params[] = json_encode($clean ?: self::DEFAULT_PROMPTS);
        }

        if (empty($set)) return self::get();

        $params[] = 1; // WHERE id = 1
        Database::pdo()
            ->prepare("UPDATE `ai_settings` SET " . implode(', ', $set) . " WHERE id = ?")
            ->execute($params);

        return self::get();
    }
}

/*
=====================================================================
 MIGRATION — run this once in phpMyAdmin / your MySQL client, before
 uploading this file. Safe to run whether or not the table already
 exists, and whether or not it already has an old prompt_template
 column from an earlier version of this feature.
=====================================================================

-- 1) Create the table if it doesn't exist yet (fresh installs)
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `provider_label`   VARCHAR(60)      DEFAULT NULL,
  `api_base_url`     VARCHAR(255)     DEFAULT NULL,
  `api_key`          VARCHAR(255)     DEFAULT NULL,
  `model`            VARCHAR(100)     DEFAULT NULL,
  `prompts`          JSON             DEFAULT NULL,
  `enabled`          TINYINT(1)       NOT NULL DEFAULT 0,
  `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) If the table already existed from an earlier version (with a
--    single prompt_template column instead of prompts), add the new
--    column. If this errors with "Duplicate column name", that just
--    means it's already there — ignore and move on.
ALTER TABLE `ai_settings` ADD COLUMN `prompts` JSON DEFAULT NULL;

-- 3) If you had an old prompt_template value saved, migrate it into
--    the new prompts array as the first tab, so you don't lose it.
--    Safe to run even if prompt_template doesn't exist / is empty —
--    it just won't match any rows.
UPDATE `ai_settings`
SET `prompts` = JSON_ARRAY(JSON_OBJECT('id','improve','name','Improve','prompt', `prompt_template`))
WHERE (`prompts` IS NULL OR `prompts` = 'null')
  AND `prompt_template` IS NOT NULL
  AND `prompt_template` != '';
*/
