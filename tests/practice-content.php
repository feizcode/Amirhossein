<?php
declare(strict_types=1);

// Standalone, read-only prototype checks; no application bootstrap or database needed.
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function numericValue(string $text): float
{
    $text = strtr(trim($text), [
        '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
        '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
    ]);
    if (preg_match('/^([0-9]+)\/([1-9][0-9]*)$/D', $text, $parts)) {
        return (float)$parts[1] / (float)$parts[2];
    }
    check(preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $text) === 1, 'Invalid numeric text: '.$text);
    return (float)$text;
}

function nextTerm(int $start, int $firstIncrease, int $position): int
{
    for ($step = 0; $step < $position - 1; $step++) {
        $start += $firstIncrease + $step;
    }
    return $start;
}

function countPairs(int $cards): int
{
    // Enumerate distinct unordered pairs rather than copying the supplied solutions.
    $pairs = [];
    for ($left = 0; $left < $cards; $left++) {
        for ($right = $left + 1; $right < $cards; $right++) {
            $pairs[] = [$left, $right];
        }
    }
    return count($pairs);
}

// Independently specified operands from each prompt, not answers read from the bank.
$expected = [
    'fraction-add-01' => 1 / 4 + 3 / 8,
    'fraction-add-02' => 1 / 3 + 1 / 6,
    'fraction-quantity-01' => 24 / 4 * 3,
    'fraction-quantity-02' => 35 / 5 * 2,
    'pattern-01' => nextTerm(2, 3, 5),
    'pattern-02' => nextTerm(4, 2, 5),
    'perimeter-01' => array_sum([9, 4, 9, 4]),
    'perimeter-02' => array_sum([8, 5, 8, 5]),
    'logical-count-01' => countPairs(4),
    'logical-count-02' => countPairs(5),
];
$expectedGroups = [
    'fraction-addition-pair' => ['fraction_addition', ['fraction-add-01', 'fraction-add-02']],
    'fraction-quantity-pair' => ['fraction_of_quantity', ['fraction-quantity-01', 'fraction-quantity-02']],
    'patterns-pair' => ['growing_differences', ['pattern-01', 'pattern-02']],
    'perimeter-pair' => ['rectangle_perimeter', ['perimeter-01', 'perimeter-02']],
    'logical-counting-pair' => ['unordered_pairs', ['logical-count-01', 'logical-count-02']],
];
$required = ['id', 'subject', 'topic', 'skill', 'group', 'difficulty', 'format', 'prompt',
    'answer', 'solution', 'hint', 'source', 'rights', 'review_status', 'reviewer'];

try {
    $json = file_get_contents(__DIR__.'/../database/practice-prototype.json');
    check($json !== false, 'Bank must be readable');
    $bank = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    check(is_array($bank) && array_keys($bank) === range(0, 9), 'Bank must be a list of exactly 10 questions');
    $seen = [];
    $prompts = [];
    $groups = [];
    foreach ($bank as $question) {
        check(is_array($question), 'Question must be an object');
        foreach ($required as $field) {
            check(isset($question[$field]) && is_string($question[$field]) && trim($question[$field]) !== '', 'Missing/non-string/empty field: '.$field);
        }
        $id = $question['id'];
        check(!isset($seen[$id]), 'Duplicate id: '.$id);
        $seen[$id] = true;
        check(array_key_exists($id, $expected), 'Unexpected id: '.$id);
        check(!isset($prompts[$question['prompt']]), 'Duplicate prompt: '.$id);
        $prompts[$question['prompt']] = true;
        check(preg_match('/[\x{0600}-\x{06FF}]/u', $question['prompt']) === 1, 'Prompt must contain Persian: '.$id);
        check($question['subject'] === 'math', 'Subject: '.$id);
        check(in_array($question['difficulty'], ['easy', 'medium', 'challenge'], true), 'Difficulty: '.$id);
        check(in_array($question['format'], ['numeric', 'multiple_choice'], true), 'Format: '.$id);
        check($question['review_status'] === 'prototype_checked', 'Machine-checked status required: '.$id);
        check($question['reviewer'] === 'automated; human approval pending', 'Must not claim human approval: '.$id);
        $group = $question['group'];
        check(isset($expectedGroups[$group]), 'Unexpected group: '.$id);
        check($question['skill'] === $expectedGroups[$group][0], 'Group skill mismatch: '.$id);
        $groups[$group][] = $id;
        if ($question['format'] === 'multiple_choice') {
            check(isset($question['options']) && is_array($question['options']), 'Options required: '.$id);
            $options = $question['options'];
            check(count($options) >= 2 && array_keys($options) === range(0, count($options) - 1), 'Options must be a list: '.$id);
            $optionIds = [];
            $values = [];
            $texts = [];
            $correctIds = [];
            foreach ($options as $option) {
                check(is_array($option) && isset($option['id'], $option['text']) && is_string($option['id']) && is_string($option['text']), 'Option schema: '.$id);
                check(trim($option['id']) !== '' && trim($option['text']) !== '', 'Empty option: '.$id);
                check(!in_array($option['id'], $optionIds, true), 'Duplicate option id: '.$id);
                check(!in_array(trim($option['text']), $texts, true), 'Duplicate option text: '.$id);
                $optionIds[] = $option['id'];
                $texts[] = trim($option['text']);
                $value = numericValue($option['text']);
                foreach ($values as $previous) {
                    check(abs($previous - $value) > 1e-9, 'Mathematically equivalent options: '.$id);
                }
                $values[] = $value;
                if (abs($value - $expected[$id]) < 1e-9) {
                    $correctIds[] = $option['id'];
                }
            }
            check(in_array($question['answer'], $optionIds, true), 'Answer must reference an option: '.$id);
            check(count($correctIds) === 1 && $correctIds[0] === $question['answer'], 'Independent MC answer mismatch: '.$id);
        } else {
            check(!array_key_exists('options', $question), 'Numeric questions must omit options: '.$id);
            check(is_numeric($question['answer']), 'Numeric answer string required: '.$id);
            check(abs((float)$question['answer'] - $expected[$id]) < 1e-9, 'Independent numeric answer mismatch: '.$id);
        }
    }
    check(count($seen) === count($expected) && !array_diff_key($expected, $seen), 'Every expected id must occur');
    check(count($groups) === 5, 'Exactly five related groups required');
    foreach ($expectedGroups as $group => [$skill, $ids]) {
        $actual = $groups[$group] ?? [];
        sort($actual);
        sort($ids);
        check(count($actual) === 2 && $actual === $ids, 'Pair membership: '.$group);
    }
    echo "PASS $checks practice-content checks; 10 answers independently verified; human approval pending\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: '.$error->getMessage()."\n");
    exit(1);
}
