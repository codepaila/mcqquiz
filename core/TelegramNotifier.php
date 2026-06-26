<?php
namespace Quiznosis\Core;

use Quiznosis\Models\TelegramSettings;

/**
 * TelegramNotifier
 *
 * Sends admin alert messages to a Telegram chat via Bot API.
 * Settings are read from the telegram_settings DB table.
 *
 * Usage:
 *   TelegramNotifier::send("🆕 New user registered: John Doe");
 */
class TelegramNotifier
{
    /** Send a plain-text or HTML message to the admin chat. */
    public static function send(string $text, string $parseMode = 'HTML'): bool
    {
        $settings = TelegramSettings::get();
        $token    = $settings['bot_token']      ?? '';
        $chatId   = $settings['notify_chat_id'] ?? '';
        $enabled  = (bool)($settings['notify_enabled'] ?? false);

        if (!$enabled || $token === '' || $chatId === '') {
            return false;
        }

        $url     = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content'       => $payload,
                'timeout'       => 6,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            error_log('[TelegramNotifier] HTTP request failed');
            return false;
        }

        $data = json_decode($result, true);
        if (!($data['ok'] ?? false)) {
            error_log('[TelegramNotifier] API error: ' . ($data['description'] ?? $result));
            return false;
        }

        return true;
    }

    /**
     * Build a formatted admin notification message.
     */
    public static function buildMessage(string $icon, string $title, array $fields = []): string
    {
        $appName = App::config('app.name') ?? 'Quiznosis';
        $lines   = ["<b>{$icon} {$title}</b>", "<i>{$appName} Admin Alert</i>", ""];

        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "<b>{$label}:</b> " . htmlspecialchars((string)$value, ENT_XML1);
            }
        }

        $lines[] = "";
        $lines[] = "🕐 " . date('Y-m-d H:i:s T');

        return implode("\n", $lines);
    }

    /**
     * Test the bot config — returns ['ok'=>bool, 'error'=>string|null].
     */
    public static function test(): array
    {
        $settings = TelegramSettings::get();
        $token    = $settings['bot_token']      ?? '';
        $chatId   = $settings['notify_chat_id'] ?? '';

        if ($token === '') return ['ok' => false, 'error' => 'Bot token is not configured.'];
        if ($chatId === '') return ['ok' => false, 'error' => 'Admin chat ID is not configured.'];

        // Temporarily force send even if disabled
        $url     = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = json_encode([
            'chat_id'    => $chatId,
            'text'       => "✅ <b>Quiznosis Admin Alerts</b> are now active!\n\nThis is a test notification from your admin panel.",
            'parse_mode' => 'HTML',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content'       => $payload,
                'timeout'       => 6,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) return ['ok' => false, 'error' => 'Network error — check server can reach api.telegram.org'];

        $data = json_decode($result, true);
        if (!($data['ok'] ?? false)) {
            $desc = $data['description'] ?? 'Unknown error';
            return ['ok' => false, 'error' => "Telegram API: {$desc}"];
        }

        return ['ok' => true, 'error' => null];
    }
}
