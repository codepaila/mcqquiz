<?php
/**
 * Admin · AI Explanation Assistant — generate / improve
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\AiExplanationAssistant;

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Temporarily show errors
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_errors.log');

// Log every request
error_log('[AI-Improve] ====== NEW REQUEST ======');
error_log('[AI-Improve] Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('[AI-Improve] URI: ' . $_SERVER['REQUEST_URI']);

try {
    // Check if user is logged in
    if (!Auth::isLoggedIn()) {
        error_log('[AI-Improve] ERROR: User not logged in');
        Response::error('Authentication required', 401);
        exit;
    }
    
    // Check if user is admin
    if (!Auth::isAdmin()) {
        error_log('[AI-Improve] ERROR: User is not admin');
        Response::error('Admin access required', 403);
        exit;
    }
    
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log('[AI-Improve] ERROR: Invalid method - ' . $_SERVER['REQUEST_METHOD']);
        Response::error('POST method required', 405);
        exit;
    }

    // Get request body
    $body = Request::body();
    error_log('[AI-Improve] Request body keys: ' . json_encode(array_keys($body)));
    
    // Validate required fields
    $questionText = trim((string)($body['question'] ?? ''));
    if ($questionText === '') {
        error_log('[AI-Improve] ERROR: No question text');
        Response::error('Question text is required before using the AI assistant.', 400);
        exit;
    }

    // Parse options
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
        error_log('[AI-Improve] ERROR: Not enough options - ' . count($options));
        Response::error('Need at least 2 options with text.', 400);
        exit;
    }

    $currentExplanation = (string)($body['explanation'] ?? '');
    
    error_log('[AI-Improve] Question: ' . substr($questionText, 0, 50) . '...');
    error_log('[AI-Improve] Options count: ' . count($options));
    error_log('[AI-Improve] Explanation length: ' . strlen($currentExplanation));

    // Call the AI assistant
    error_log('[AI-Improve] Calling AiExplanationAssistant...');
    $result = AiExplanationAssistant::improveExplanation([
        'text' => $questionText,
        'options' => $options,
        'explanation' => $currentExplanation,
    ]);

    error_log('[AI-Improve] Result: ' . ($result['ok'] ? 'SUCCESS' : 'FAILED'));
    if (!$result['ok']) {
        error_log('[AI-Improve] Error: ' . ($result['error'] ?? 'Unknown error'));
        Response::error($result['error'] ?? 'AI request failed.', 502);
        exit;
    }

    if (empty($result['text'])) {
        error_log('[AI-Improve] WARNING: Empty response text');
        $result['text'] = 'No explanation generated. Please try again.';
    }

    error_log('[AI-Improve] Response text length: ' . strlen($result['text']));
    Response::ok(['data' => ['text' => $result['text']]]);
    
} catch (Exception $e) {
    error_log('[AI-Improve] EXCEPTION: ' . $e->getMessage());
    error_log('[AI-Improve] Stack trace: ' . $e->getTraceAsString());
    Response::error('Server error: ' . $e->getMessage(), 500);
}

error_log('[AI-Improve] ====== REQUEST COMPLETE ======');