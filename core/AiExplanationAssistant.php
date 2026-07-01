<?php
namespace Quiznosis\Core;

use Quiznosis\Models\AiSettings;

class AiExplanationAssistant
{
    public static function improveExplanation(array $question): array
    {
        try {
            // Get settings using your AiSettings model
            $settings = AiSettings::get();
            
            error_log('[AI] Settings loaded successfully');
            error_log('[AI] API Base URL: ' . ($settings['api_base_url'] ?? 'NOT SET'));
            error_log('[AI] Model: ' . ($settings['model'] ?? 'NOT SET'));
            error_log('[AI] Enabled: ' . ($settings['enabled'] ?? '0'));
            error_log('[AI] API Key set: ' . (isset($settings['api_key']) && !empty($settings['api_key']) ? 'YES' : 'NO'));
            
        } catch (\Exception $e) {
            error_log('[AI] Failed to load settings: ' . $e->getMessage());
            return [
                'ok' => false,
                'text' => null,
                'error' => 'Failed to load AI settings: ' . $e->getMessage()
            ];
        }

        if (empty($settings)) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'AI settings not configured. Please set up in Admin → AI Settings.'
            ];
        }

        // Extract settings - using your exact field names from AiSettings model
        $enabled = (bool)($settings['enabled'] ?? false);
        $baseUrl = trim((string)($settings['api_base_url'] ?? ''));
        $apiKey = trim((string)($settings['api_key'] ?? ''));
        $model = trim((string)($settings['model'] ?? ''));
        $template = trim((string)($settings['prompt_template'] ?? ''));

        // Validate settings
        if (!$enabled) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'AI assistant is disabled. Enable it in Admin → AI Settings.'
            ];
        }

        if (empty($baseUrl)) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'No API endpoint configured. Set one in Admin → AI Settings.'
            ];
        }

        if (empty($apiKey)) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'No API key configured. Set one in Admin → AI Settings.'
            ];
        }

        if (empty($model)) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'No model configured. Set one in Admin → AI Settings.'
            ];
        }

        if (empty($template)) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'No prompt template configured. Set one in Admin → AI Settings.'
            ];
        }

        // Build the prompt
        $prompt = self::fillTemplate($template, $question);
        error_log('[AI] Prompt generated, length: ' . strlen($prompt));

        // Prepare API request
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.4,
            'max_tokens' => 500,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        error_log('[AI] Sending request to: ' . $baseUrl);

        // Make the request
        $result = self::makeRequest($baseUrl, $headers, $payload);

        if (!$result['ok']) {
            error_log('[AI] Request failed: ' . ($result['error'] ?? 'Unknown error'));
            return $result;
        }

        // Parse response
        $data = json_decode($result['body'], true);
        
        if (!is_array($data)) {
            error_log('[AI] Invalid JSON response: ' . substr($result['body'], 0, 200));
            return [
                'ok' => false,
                'text' => null,
                'error' => 'AI provider returned invalid response format.'
            ];
        }

        if (isset($data['error'])) {
            $msg = is_array($data['error'])
                ? ($data['error']['message'] ?? json_encode($data['error']))
                : $data['error'];
            error_log('[AI] API error: ' . $msg);
            return [
                'ok' => false,
                'text' => null,
                'error' => 'AI provider error: ' . $msg
            ];
        }

        $text = $data['choices'][0]['message']['content'] ?? null;
        
        if ($text === null || trim($text) === '') {
            error_log('[AI] Empty response: ' . json_encode($data));
            return [
                'ok' => false,
                'text' => null,
                'error' => 'AI provider returned an empty response.'
            ];
        }

        error_log('[AI] Successfully generated explanation');
        return [
            'ok' => true,
            'text' => trim($text),
            'error' => null
        ];
    }

    private static function fillTemplate(string $template, array $question): string
    {
        $optionsText = '';
        $correctText = '(not specified)';
        
        $options = $question['options'] ?? [];
        if (is_array($options)) {
            foreach ($options as $i => $opt) {
                $letter = chr(65 + $i);
                $optText = is_array($opt) ? ($opt['text'] ?? '') : $opt;
                $optionsText .= "{$letter}. " . $optText . "\n";
                if (is_array($opt) && !empty($opt['is_correct'])) {
                    $correctText = "{$letter}. " . $optText;
                }
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

    private static function makeRequest(string $url, array $headers, string $payload): array
    {
        if (function_exists('curl_init')) {
            return self::callViaCurl($url, $headers, $payload);
        }
        
        error_log('[AI] cURL not available, using file_get_contents fallback');
        return self::callViaFileGetContents($url, $headers, $payload);
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
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $body = curl_exec($ch);
        
        if ($body === false) {
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            error_log("[AI] cURL error: $error (HTTP $httpCode)");
            return [
                'ok' => false,
                'text' => null,
                'error' => "Network error: $error"
            ];
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            error_log("[AI] HTTP error $httpCode: " . substr($body, 0, 500));
            return [
                'ok' => false,
                'text' => null,
                'error' => "AI provider returned HTTP $httpCode"
            ];
        }
        
        return ['ok' => true, 'body' => $body, 'error' => null];
    }

    private static function callViaFileGetContents(string $url, array $headers, string $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        
        if ($body === false) {
            $error = error_get_last();
            error_log('[AI] file_get_contents error: ' . ($error['message'] ?? 'Unknown error'));
            return [
                'ok' => false,
                'text' => null,
                'error' => 'Network error: Could not connect to AI provider'
            ];
        }
        
        return ['ok' => true, 'body' => $body, 'error' => null];
    }
}