<?php
/**
 * Admin · AI Explanation Assistant — generate / improve
 *
 * POST /api/admin/ai-improve
 * Body: {
 *   question: string,
 *   options: [ { text: string, isCorrect: bool }, ... ],
 *   explanation: string   // current text, may be empty
 * }
 *
 * Returns: { data: { text: string } }
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\AiExplanationAssistant;

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client
ini_set('log_errors', 1);

try {
    // Use the correct Auth method - requireAdmin() handles everything
    Auth::requireAdmin();
    
    // Check request method
    Request::requireMethod('POST');

    $body = Request::body();
    
    // Validate required fields
    $questionText = trim((string)($body['question'] ?? ''));
    if ($questionText === '') {
        Response::error('Question text is required before using the AI assistant.', 400);
    }

    // Parse and validate options
    $options = [];
    if (!empty($body['options']) && is_array($body['options'])) {
        foreach ($body['options'] as $opt) {
            $text = trim((string)($opt['text'] ?? ''));
            if ($text === '') continue;
            $options[] = [
                'text' => $text,
                'is_correct' => !empty($opt['isCorrect'])
            ];
        }
    }

    if (count($options) < 2) {
        Response::error('Need at least 2 options with text.', 400);
    }

    $currentExplanation = (string)($body['explanation'] ?? '');
    
    // Log the request for debugging
    error_log("[AI] Processing improvement request. Question: " . substr($questionText, 0, 50) . "...");
    error_log("[AI] Options count: " . count($options));
    error_log("[AI] Has explanation: " . (strlen($currentExplanation) > 0 ? 'Yes' : 'No'));

    // Call the AI assistant
    $result = AiExplanationAssistant::improveExplanation([
        'text' => $questionText,
        'options' => $options,
        'explanation' => $currentExplanation,
    ]);

    if (!$result['ok']) {
        $errorMsg = $result['error'] ?? 'AI request failed.';
        error_log("[AI] Error: " . $errorMsg);
        Response::error($errorMsg, 502);
    }

    // Ensure we have a valid response
    if (empty($result['text'])) {
        $result['text'] = 'No explanation generated. Please try again.';
    }

    Response::ok(['data' => ['text' => $result['text']]]);
    
} catch (Exception $e) {
    error_log("[AI] Exception: " . $e->getMessage());
    error_log("[AI] Stack trace: " . $e->getTraceAsString());
    Response::error('AI service error: ' . $e->getMessage(), 500);
}