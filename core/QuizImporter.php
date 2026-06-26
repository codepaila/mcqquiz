<?php
namespace Quiznosis\Core;

/**
 * QuizImporter — parse bulk quiz uploads into a normalised structure.
 *
 * Two formats supported:
 *
 *  1. TEXT format (line-based, human-friendly):
 *     1. Question text
 *     A. Option 1
 *     B. Option 2
 *     C. Option 3
 *     D. Option 4
 *     2                          <- 1-based answer index (1..4)
 *     Explanation (optional, may span multiple lines until next question)
 *
 *  2. JSON format (array of objects):
 *     [
 *       {
 *         "question": "...",
 *         "options": {"A":"...","B":"...","C":"...","D":"..."},
 *         "correct_answer": "B",
 *         "explanation": "..."   // optional
 *       },
 *       ...
 *     ]
 *
 * Both parsers return the same normalised shape:
 *
 *   [
 *     [
 *       'question'    => string,
 *       'options'     => [ ['text' => string, 'isCorrect' => bool], ... ] (4 items),
 *       'explanation' => string|null,
 *       '_lineNo'     => int|null,    // text format only, for error reporting
 *     ],
 *     ...
 *   ]
 *
 * Errors collected separately:
 *   [
 *     ['index' => 0, 'line' => 12, 'message' => 'Missing answer number'],
 *     ...
 *   ]
 */
class QuizImporter
{
    /**
     * Parse text format. Returns ['questions' => [...], 'errors' => [...]].
     */
    public static function parseText(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);

        $questions = [];
        $errors    = [];

        // Build a list of "blocks", each starting at a line matching ^\d+\.\s+.
        // We only treat a digit-period line as a NEW question header when an
        // option line ("A. ..." / "A) ..." / "(A) ...") appears within the
        // next few non-blank lines. Otherwise the digit-period line belongs
        // to whatever the current state is (e.g. an ordered list inside the
        // explanation: "1. Asia\n2. Africa\n...").
        $optionPattern = '/^\s*\(?[A-Da-d]\)?[\.\):\-]\s+\S/';

        $startsNewQuestion = function(int $idx) use ($lines, $optionPattern) {
            // Look ahead up to 8 non-blank lines for the first option marker.
            // If we find one before another digit-period header, this is a
            // real new question.
            $seen = 0;
            for ($j = $idx + 1; $j < count($lines) && $seen < 8; $j++) {
                $t = trim($lines[$j]);
                if ($t === '') continue;
                $seen++;
                if (preg_match($optionPattern, $lines[$j])) return true;
                // Another digit-period header before an option — bail out;
                // the original was likely a list item, not a question header.
                if (preg_match('/^\s*\d+\.\s+\S/', $lines[$j])) return false;
            }
            return false;
        };

        $blocks = [];
        $current = null;
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            if (preg_match('/^\s*(\d+)\.\s+(.+)$/', $line, $m)) {
                // Only treat this as a question header if it's followed by an
                // option block; otherwise fall through and add to current.
                $isHeader = ($current === null) ? true : $startsNewQuestion($idx);
                if ($isHeader) {
                    if ($current !== null) $blocks[] = $current;
                    $current = [
                        'startLine'    => $lineNo,
                        'questionText' => trim($m[2]),
                        'rest'         => [],
                    ];
                    continue;
                }
            }
            if ($current !== null) {
                $current['rest'][] = ['line' => $lineNo, 'text' => $line];
            }
        }
        if ($current !== null) $blocks[] = $current;

        // Parse each block
        foreach ($blocks as $i => $block) {
            $parsed = self::parseTextBlock($block, $i);
            if (isset($parsed['error'])) {
                $errors[] = [
                    'index'   => $i,
                    'line'    => $parsed['line'] ?? $block['startLine'],
                    'message' => $parsed['error'],
                    'preview' => self::shorten($block['questionText'], 80),
                ];
            } else {
                $questions[] = $parsed;
            }
        }

        return ['questions' => $questions, 'errors' => $errors];
    }

    /** Parse one question block from the text format. */
    private static function parseTextBlock(array $block, int $index): array
    {
        $optionMap = []; // letter => ['text' => ..., 'line' => N]
        $answerNum = null;
        $answerLine = null;
        $explanationLines = [];
        $state = 'options';   // options -> answer -> explanation

        foreach ($block['rest'] as $entry) {
            $line    = $entry['text'];
            $trim    = trim($line);
            $lineNo  = $entry['line'];

            if ($trim === '') {
                if ($state === 'explanation') $explanationLines[] = '';
                continue;
            }

            if ($state === 'options') {
                // Match "A. text", "A) text", "A: text", "(A) text"  — case-insensitive
                if (preg_match('/^\s*\(?([A-Da-d])\)?[\.\):\-]\s*(.+)$/', $line, $m)) {
                    $letter = strtoupper($m[1]);
                    if (isset($optionMap[$letter])) {
                        return ['error' => "Duplicate option '$letter'", 'line' => $lineNo];
                    }
                    $optionMap[$letter] = ['text' => trim($m[2]), 'line' => $lineNo];
                    if (count($optionMap) === 4) {
                        $state = 'answer';
                    }
                    continue;
                }
                return [
                    'error' => 'Expected option line like "A. ..."; got: ' . self::shorten($trim, 60),
                    'line'  => $lineNo,
                ];
            }

            if ($state === 'answer') {
                // Must be a single integer 1..4, possibly with surrounding whitespace.
                if (preg_match('/^\s*([1-4])\s*$/', $line, $m)) {
                    $answerNum  = (int)$m[1];
                    $answerLine = $lineNo;
                    $state = 'explanation';
                    continue;
                }
                // Some users may write a letter (B) here — accept gracefully
                if (preg_match('/^\s*\(?([A-Da-d])\)?\s*$/', $line, $m)) {
                    $answerNum  = ord(strtoupper($m[1])) - 64; // A=1 .. D=4
                    $answerLine = $lineNo;
                    $state = 'explanation';
                    continue;
                }
                return [
                    'error' => 'Expected answer number (1-4); got: ' . self::shorten($trim, 60),
                    'line'  => $lineNo,
                ];
            }

            if ($state === 'explanation') {
                $explanationLines[] = $line;
                continue;
            }
        }

        // Validate completeness
        if (count($optionMap) < 4) {
            return [
                'error' => 'Need exactly 4 options (A–D); found ' . count($optionMap),
                'line'  => $block['startLine'],
            ];
        }
        foreach (['A','B','C','D'] as $L) {
            if (!isset($optionMap[$L])) {
                return [
                    'error' => "Missing option '$L'",
                    'line'  => $block['startLine'],
                ];
            }
            if ($optionMap[$L]['text'] === '') {
                return [
                    'error' => "Empty text for option '$L'",
                    'line'  => $optionMap[$L]['line'],
                ];
            }
        }
        if ($answerNum === null) {
            return [
                'error' => 'Missing answer number after options',
                'line'  => $block['startLine'],
            ];
        }
        if ($block['questionText'] === '') {
            return [
                'error' => 'Empty question text',
                'line'  => $block['startLine'],
            ];
        }

        // Build normalised options
        $options = [];
        $letters = ['A','B','C','D'];
        foreach ($letters as $i => $L) {
            $options[] = [
                'text'      => $optionMap[$L]['text'],
                'isCorrect' => ($i + 1) === $answerNum,
            ];
        }

        $explanation = trim(implode("\n", $explanationLines));
        if ($explanation === '') $explanation = null;

        return [
            'question'    => $block['questionText'],
            'options'     => $options,
            'explanation' => $explanation,
            '_lineNo'     => $block['startLine'],
        ];
    }

    /**
     * Parse JSON format. Accepts either a JSON string or an already-decoded array.
     */
    public static function parseJson($input): array
    {
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'questions' => [],
                    'errors'    => [['index' => 0, 'message' => 'Invalid JSON: ' . json_last_error_msg()]],
                ];
            }
        } else {
            $decoded = $input;
        }
        if (!is_array($decoded) || (isset($decoded[0]) === false && !empty($decoded))) {
            // either not an array, or an object (not a list)
            if (is_array($decoded) && !empty($decoded) && !array_is_list($decoded)) {
                // single object — wrap into a list for convenience
                $decoded = [$decoded];
            } else {
                return [
                    'questions' => [],
                    'errors'    => [['index' => 0, 'message' => 'JSON must be an array of question objects']],
                ];
            }
        }

        $questions = [];
        $errors    = [];
        foreach ($decoded as $i => $item) {
            $parsed = self::parseJsonItem($item, $i);
            if (isset($parsed['error'])) {
                $errors[] = [
                    'index'   => $i,
                    'message' => $parsed['error'],
                    'preview' => self::shorten(is_array($item) ? ($item['question'] ?? '(no question)') : '(invalid)', 80),
                ];
            } else {
                $questions[] = $parsed;
            }
        }

        return ['questions' => $questions, 'errors' => $errors];
    }

    private static function parseJsonItem($item, int $index): array
    {
        if (!is_array($item)) {
            return ['error' => 'Item must be an object'];
        }
        $q = trim((string)($item['question'] ?? ''));
        if ($q === '') return ['error' => 'Empty or missing "question"'];

        $opts = $item['options'] ?? null;
        if (!is_array($opts)) {
            return ['error' => '"options" must be an array of 4 strings, or an object with A/B/C/D keys'];
        }

        // Accept two formats:
        //   NEW: { "options": ["a","b","c","d"], "correct_option_id": 0|1|2|3 }   (zero-based)
        //   OLD: { "options": {"A":"a","B":"b","C":"c","D":"d"}, "correct_answer": "A"|"B"|... }
        $isList = array_keys($opts) === range(0, count($opts) - 1);

        if ($isList) {
            // New array-based format
            if (count($opts) !== 4) {
                return ['error' => 'Need exactly 4 options in "options" array; got ' . count($opts)];
            }
            $built = [];
            foreach (['A','B','C','D'] as $i => $L) {
                $txt = trim((string)$opts[$i]);
                if ($txt === '') return ['error' => "Empty text for option index $i"];
                $built[$L] = $txt;
            }
            if (!array_key_exists('correct_option_id', $item)) {
                return ['error' => '"correct_option_id" is required when "options" is an array'];
            }
            $idx = $item['correct_option_id'];
            if (!is_int($idx) && !ctype_digit((string)$idx)) {
                return ['error' => '"correct_option_id" must be an integer 0..3'];
            }
            $idx = (int)$idx;
            if ($idx < 0 || $idx > 3) {
                return ['error' => "\"correct_option_id\" must be 0..3, got $idx"];
            }
            $ansLetter = ['A','B','C','D'][$idx];
        } else {
            // Legacy object-with-letter-keys format
            $built = [];
            foreach (['A','B','C','D'] as $L) {
                if (!isset($opts[$L])) return ['error' => "Missing option '$L'"];
                $txt = trim((string)$opts[$L]);
                if ($txt === '') return ['error' => "Empty text for option '$L'"];
                $built[$L] = $txt;
            }
            $ans = strtoupper(trim((string)($item['correct_answer'] ?? '')));
            if (!in_array($ans, ['A','B','C','D'], true)) {
                return ['error' => "'correct_answer' must be one of A, B, C, D — got '" . ($item['correct_answer'] ?? 'null') . "'"];
            }
            $ansLetter = $ans;
        }

        $options = [];
        foreach (['A','B','C','D'] as $L) {
            $options[] = [
                'text'      => $built[$L],
                'isCorrect' => $L === $ansLetter,
            ];
        }

        $explanation = isset($item['explanation']) ? trim((string)$item['explanation']) : '';
        if ($explanation === '') $explanation = null;

        return [
            'question'    => $q,
            'options'     => $options,
            'explanation' => $explanation,
            '_lineNo'     => null,
        ];
    }

    /** Trim a string to N chars with ellipsis. */
    private static function shorten(string $s, int $n): string
    {
        $s = str_replace(["\n", "\r"], ' ', $s);
        // Prefer multibyte if available, else byte-safe substr
        if (function_exists('mb_strlen')) {
            if (mb_strlen($s) <= $n) return $s;
            return mb_substr($s, 0, $n) . '…';
        }
        if (strlen($s) <= $n) return $s;
        return substr($s, 0, $n) . '…';
    }
}
