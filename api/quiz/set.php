<?php
/**
 * GET /api/quiz/set?id=<setId>  (or ?slug=<slug>)
 *
 * Returns the quiz set + items + each item's quiz + options.
 *
 * Answer-leak protection:
 *   - MODEL_TEST sets: is_correct and explanation are ALWAYS stripped.
 *   - PRACTICE sets: is_correct + explanation are included so the attempt page
 *     can show immediate feedback.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\QuizSet;

Request::requireMethod('GET');

$id   = Request::query('id');
$slug = Request::query('slug');
if (!$id && !$slug) Response::error('id or slug is required', 400);

$set = $id
    ? QuizSet::findWithQuestions((string)$id)
    : (QuizSet::firstWhere(['slug' => (string)$slug])
        ? QuizSet::findWithQuestions(QuizSet::firstWhere(['slug' => (string)$slug])['id'])
        : null);

if (!$set) Response::notFound('Quiz set not found');

$isPractice = ($set['mode'] ?? '') === 'PRACTICE';

if (!$isPractice) {
    foreach ($set['items'] as &$item) {
        if (!empty($item['quiz'])) {
            unset($item['quiz']['explanation']);
            if (!empty($item['quiz']['options'])) {
                foreach ($item['quiz']['options'] as &$opt) {
                    unset($opt['is_correct']);
                }
                unset($opt);
            }
        }
    }
    unset($item);
}

Response::ok(['data' => $set]);
