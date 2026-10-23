<?php
// Standalone: C:\xampp\php\php.exe -n tests/practice-extension.php
// Expectations are calculated from independently transcribed problem operands,
// never from the supplied answers or solution text. No application bootstrap.
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function asciiDigits(string $text): string
{
    return strtr($text, array_combine(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        ['0','1','2','3','4','5','6','7','8','9']
    ));
}

function gcd(int $a, int $b): int
{
    while ($b !== 0) {
        [$a, $b] = [$b, $a % $b];
    }
    return abs($a);
}

function rational(int $n, int $d = 1): string
{
    check($d > 0, 'Denominator must be positive');
    $g = gcd($n, $d);
    return intdiv($n, $g) . '/' . intdiv($d, $g);
}

function numericValue(string $text): string
{
    $text = asciiDigits($text);
    check(preg_match('~^([0-9]+)(?:/([1-9][0-9]*))?$~D', $text, $m) === 1,
        'Option must contain only an integer or fraction: ' . $text);
    return rational((int) $m[1], isset($m[2]) ? (int) $m[2] : 1);
}

function loadJson(string $path): array
{
    $raw = file_get_contents($path);
    check($raw !== false, 'Cannot read ' . $path);
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    check(is_array($data), 'Expected JSON array: ' . $path);
    return $data;
}

try {
    check(PHP_INT_SIZE >= 8, '64-bit PHP required for billion arithmetic');
    $root = dirname(__DIR__);
    $questions = loadJson($root . '/database/practice-curriculum-extension.json');
    $prototype = loadJson($root . '/database/practice-prototype.json');
    check(array_keys($questions) === range(0, 11), 'Exactly 12 questions required');

    // Fixed curriculum expectations use printed pages, not PDF offsets.
    $skills = [
        'place_value' => ['math-g0-t0', range(2, 5), 'place-value-pair', 'easy', 'numeric'],
        'billion' => ['math-g0-t1', range(6, 9), 'billion-pair', 'easy', 'numeric'],
        'compound_time' => ['math-g0-t2', range(10, 13), 'compound-time-pair', 'medium', 'numeric'],
        'improper_fraction' => ['math-g1-t0', range(22, 26), 'improper-fraction-pair', 'easy', 'multiple_choice'],
        'mixed_addition' => ['math-g1-t1', range(27, 31), 'mixed-addition-pair', 'medium', 'multiple_choice'],
        'fraction_division' => ['math-g1-t3', range(36, 39), 'fraction-division-pair', 'medium', 'numeric'],
    ];
    // [skill, independently computed value, prompt operand anchors]
    $expected = [
        'place-value-01' => ['place_value', rational((intdiv(7382416, 100000) % 10) * 100000), ['۷۳۸۲۴۱۶', 'رقم ۳']],
        'place-value-02' => ['place_value', rational((intdiv(6528194, 100000) % 10) * 100000), ['۶۵۲۸۱۹۴', 'رقم ۵']],
        'billion-01' => ['billion', rational(2 * 1000 * 1000000 + 300 * 1000000), ['دو میلیارد و سیصد میلیون']],
        'billion-02' => ['billion', rational(4 * 1000 * 1000000 + 70 * 1000000), ['چهار میلیارد و هفتاد میلیون']],
        'compound-time-01' => ['compound_time', rational(60 + 45 + 2 * 60 + 30), ['۱ ساعت و ۴۵ دقیقه', '۲ ساعت و ۳۰ دقیقه', 'چند دقیقه']],
        'compound-time-02' => ['compound_time', rational(2 * 60 + 50 + 60 + 35), ['۲ ساعت و ۵۰ دقیقه', '۱ ساعت و ۳۵ دقیقه', 'چند دقیقه']],
        'improper-fraction-01' => ['improper_fraction', rational(2 * 5 + 3, 5), ['۲ و ۳/۵']],
        'improper-fraction-02' => ['improper_fraction', rational(3 * 7 + 2, 7), ['۳ و ۲/۷']],
        'mixed-addition-01' => ['mixed_addition', rational((1 * 4 + 3) * 3 + (2 * 3 + 2) * 4, 12), ['۱ و ۳/۴', '۲ و ۲/۳']],
        'mixed-addition-02' => ['mixed_addition', rational((2 * 6 + 5) * 2 + (1 * 4 + 3) * 3, 12), ['۲ و ۵/۶', '۱ و ۳/۴']],
        'fraction-division-01' => ['fraction_division', rational(3 * 8, 4 * 1), ['۳/۴ لیتر', '۱/۸ لیتر']],
        'fraction-division-02' => ['fraction_division', rational(5 * 6, 6 * 1), ['۵/۶ لیتر', '۱/۶ لیتر']],
    ];
    $baseKeys = array_keys($prototype[0]);
    $baseKeys = array_values(array_diff($baseKeys, ['options']));
    $ids = [];
    $prompts = [];
    foreach ($prototype as $old) {
        $ids[$old['id']] = true;
        $prompts[preg_replace('/\s+/u', '', $old['prompt'])] = true;
    }
    $pairs = [];
    foreach ($questions as $q) {
        check(is_array($q), 'Question must be an object');
        $id = $q['id'] ?? '';
        check(is_string($id) && isset($expected[$id]), 'Unknown question ID');
        check(!isset($ids[$id]), 'Duplicate question ID: ' . $id);
        $ids[$id] = true;
        [$skill, $value, $anchors] = $expected[$id];
        [$topic, $pages, $group, $difficulty, $format] = $skills[$skill];
        $keys = array_merge($baseKeys, ['curriculum_topic_id', 'curriculum_pages', 'curriculum_role']);
        if ($format === 'multiple_choice') {
            $keys[] = 'options';
        }
        $actualKeys = array_keys($q);
        sort($keys);
        sort($actualKeys);
        check($keys === $actualKeys, $id . ': schema keys');
        foreach ($keys as $key) {
            if (!in_array($key, ['options', 'curriculum_pages'], true)) {
                check(is_string($q[$key]) && trim($q[$key]) !== '', $id . ': nonempty string ' . $key);
            }
        }
        check($q['subject'] === 'math' && $q['skill'] === $skill, $id . ': subject/skill');
        check($q['group'] === $group && $q['difficulty'] === $difficulty && $q['format'] === $format, $id . ': pair metadata');
        check($q['curriculum_topic_id'] === $topic && $q['curriculum_pages'] === $pages && $q['curriculum_role'] === 'direct', $id . ': curriculum mapping');
        check($q['review_status'] === 'prototype_checked' && $q['reviewer'] === 'automated; human approval pending', $id . ': review status');
        check(strpos($q['source'], 'تألیف تازه') !== false && strpos($q['source'], 'knowledge/grade-5-mathematics.en.md') !== false, $id . ': original source declaration');
        check(strpos($q['rights'], 'محتوای تألیفی این پروژه') !== false && strpos($q['rights'], 'بازتولید نشده') !== false, $id . ': rights declaration');
        check(preg_match('/[\x{0600}-\x{06FF}]/u', $q['prompt']) === 1, $id . ': Persian prompt');
        $promptKey = preg_replace('/\s+/u', '', $q['prompt']);
        check(!isset($prompts[$promptKey]), $id . ': duplicate prompt');
        $prompts[$promptKey] = true;
        foreach ($anchors as $anchor) {
            check(strpos($q['prompt'], $anchor) !== false, $id . ': operand mismatch');
        }
        check(strlen($q['hint']) < 240, $id . ': hint should be short');
        if ($format === 'numeric') {
            check(preg_match('/^[0-9]+$/D', $q['answer']) === 1, $id . ': integer answer');
            $actual = numericValue($q['answer']);
        } else {
            check(is_array($q['options']) && array_keys($q['options']) === range(0, 3), $id . ': four options');
            $optionIds = [];
            $values = [];
            foreach ($q['options'] as $option) {
                check(is_array($option) && count($option) === 2 && isset($option['id'], $option['text']), $id . ': option schema');
                check(is_string($option['id']) && in_array($option['id'], ['a', 'b', 'c', 'd'], true), $id . ': option ID');
                check(!isset($optionIds[$option['id']]), $id . ': duplicate option ID');
                check(is_string($option['text']), $id . ': option text');
                $v = numericValue($option['text']);
                check(!in_array($v, $values, true), $id . ': mathematically equivalent options');
                $values[] = $v;
                $optionIds[$option['id']] = $v;
            }
            check(isset($optionIds[$q['answer']]), $id . ': answer references an option');
            $actual = $optionIds[$q['answer']];
            check(count(array_filter($values, static function ($v) use ($value) { return $v === $value; })) === 1, $id . ': exactly one correct option');
        }
        check($actual === $value, $id . ': independent arithmetic expectation');
        // Hints should not contain the full numeric answer (including Persian digits).
        $answerParts = explode('/', $value);
        $hint = asciiDigits($q['hint']);
        $answerLiteral = $answerParts[1] === '1' ? $answerParts[0] : $value;
        check(strpos($hint, $answerLiteral) === false, $id . ': answer leaking hint');
        $pairs[$skill][] = $actual;
    }
    check(count($pairs) === 6, 'Exactly six skills');
    foreach ($skills as $skill => $_) {
        check(isset($pairs[$skill]) && count($pairs[$skill]) === 2, $skill . ': exactly two questions');
        check(count(array_unique($pairs[$skill])) === 2, $skill . ': distinct mathematical answers within pair');
    }
    echo "PASS: 12 questions; six curriculum-aligned pairs; schema, arithmetic, uniqueness, mappings, rights and review statuses verified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
