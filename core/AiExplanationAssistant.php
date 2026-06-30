<?php
namespace Quiznosis\Core;

use Quiznosis\Models\AiSettings;

/**
 * AiExplanationAssistant
 *
 * Sends a question + options + correct answer + current explanation to an
 * AI provider's chat-completions endpoint and returns improved explanation
 * text. Built against the OpenAI-compatible request/response shape, which
 * DeepSeek (and most other hosted LLM APIs) implement, so switching
 * providers is just a matter of changing the endpoint/key/model in the
 * admin AI Settings page — no code changes needed here.
 *
 * Uses cURL rather than file_get_contents(): some hosts (seen previously
 * with this project's Telegram integration on InfinityFree/free.nf) block
 * outbound file_get_contents() over HTTPS, or have allow_url_fopen
 * disabled, while cURL still works. If cURL isn't available at all, falls
 * back to file_get_contents() so this still works in that uncommon case.
 */
class AiExplanationAssistant
{
    /**
     * @param array $question  ['text' => string, 'options' => [['text'=>..,'is_correct'=>bool], ...], 'explanation' => string]
     * @return array ['ok' => bool, 'text' => string|null, 'error' => string|null]
     */
    public static function improveExplanation(array $question): array
    {
        $settings = AiSettings::get();
        $enabled  = (bool)($settings['enabled'] ?? false);
        $baseUrl  = trim((string)($settings['api_base_url'] ?? ''));
        $apiKey   = trim((string)($settings['api_key'] ?? ''));
        $model    = trim((string)($settings['model'] ?? ''));
        $template = (string)($settings['prompt_template'] ?? '');

        if (!$enabled)      return ['ok' => false, 'text' => null, 'error' => 'AI assistant is disabled. Enable it in Admin → AI Settings.'];
        if ($baseUrl === '') return ['ok' => false, 'text' => null, 'error' => 'No API endpoint configured. Set one in Admin → AI Settings.'];
        if ($apiKey === '')  return ['ok' => false, 'text' => null, 'error' => 'No API key configured. Set one in Admin → AI Settings.'];
        if ($model === '')   return ['ok' => false, 'text' => null, 'error' => 'No model configured. Set one in Admin → AI Settings.'];
        if ($template === '') return ['ok' => false, 'text' => null, 'error' => 'No prompt template configured. Set one in Admin → AI Settings.'];

        $prompt = self::fillTemplate($template, $question);

        $payload = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        $result = function_exists('curl_init')
            ? self::callViaCurl($baseUrl, $headers, $payload)
            : self::callViaFileGetContents($baseUrl, $headers, $payload);

        if (!$result['ok']) return $result;

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'text' => null, 'error' => 'AI provider returned a non-JSON response.'];
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : $data['error'];
            return ['ok' => false, 'text' => null, 'error' => 'AI provider error: ' . $msg];
        }

        // OpenAI-compatible chat completions response shape.
        $text = $data['choices'][0]['message']['content'] ?? null;
        if ($text === null || trim($text) === '') {
            return ['ok' => false, 'text' => null, 'error' => 'AI provider returned an empty response.'];
        }

        return ['ok' => true, 'text' => trim($text), 'error' => null];
    }

    /** Replace {{question}}, {{options}}, {{correct_answer}}, {{explanation}} in the template. */
    private static function fillTemplate(string $template, array $question): string
    {
        $optionsText = '';
        $correctText = '(not specified)';
        foreach (($question['options'] ?? []) as $i => $opt) {
            $letter = chr(65 + $i); // A, B, C, ...
            $optionsText .= "{$letter}. " . ($opt['text'] ?? '') . "\n";
            if (!empty($opt['is_correct'])) {
                $correctText = "{$letter}. " . ($opt['text'] ?? '');
            }
        }

        return str_replace(
            ['{{question}}', '{{options}}', '{{correct_answer}}', '{{explanation}}'],
            [
                $question['text'] ?? '',
                trim($optionsText) ?: '(no options provided)',
                $correctText,
                $question['explanation'] ?? '',
            ],
            $template
        );
    }

    private static function callViaCurl(string $url, array $headers, string $payload): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'text' => null, 'error' => 'Network error reaching the AI provider: ' . $err];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            return ['ok' => false, 'text' => null, 'error' => "AI provider returned HTTP {$httpCode}: " . substr($body, 0, 300)];
        }
        return ['ok' => true, 'body' => $body, 'error' => null];
    }

    private static function callViaFileGetContents(string $url, array $headers, string $payload): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers) . "\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content'       => $payload,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'text' => null, 'error' => 'Network error reaching the AI provider (server could not connect).'];
        }
        return ['ok' => true, 'body' => $body, 'error' => null];
    }
}
