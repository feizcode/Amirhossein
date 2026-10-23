<?php
// Standalone: C:\xampp\php\php.exe -n tests/practice-v1-content.php
// Operands below are independently transcribed from prompts, not answers/solutions.
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

function rational(int $n, int $d = 1): string
{
    check($d > 0, 'Positive denominator required');
    $a = abs($n);
    $b = $d;
    while ($b !== 0) {
        [$a, $b] = [$b, $a % $b];
    }
    return intdiv($n, $a) . '/' . intdiv($d, $a);
}

function numericValue(string $text): string
{
    check(preg_match('~^([0-9]+)(?:/([1-9][0-9]*))?$~D', asciiDigits($text), $m) === 1,
        'Expected numeric fraction or integer');
    return rational((int) $m[1], isset($m[2]) ? (int) $m[2] : 1);
}

function optionValues(array $options): array
{
    check(array_keys($options) === range(0, 3), 'Four options required');
    $values = [];
    foreach ($options as $option) {
        check(is_array($option), 'Option object required');
        $keys = array_keys($option);
        sort($keys);
        check($keys === ['id', 'text'], 'Option schema');
        check(is_string($option['id']) && in_array($option['id'], ['a','b','c','d'], true), 'Option ID');
        check(!isset($values[$option['id']]), 'Duplicate option ID');
        check(is_string($option['text']), 'Option text');
        $v = numericValue($option['text']);
        check(!in_array($v, $values, true), 'Mathematically equivalent options');
        $values[$option['id']] = $v;
    }
    return $values;
}

function loadJson(string $path): array
{
    $raw = file_get_contents($path);
    check($raw !== false, 'Cannot read ' . $path);
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    check(is_array($data), 'JSON array required');
    return $data;
}

try {
    $root = dirname(__DIR__);
    $questions = loadJson($root . '/database/practice-v1-extension.json');
    $existing = array_merge(loadJson($root . '/database/practice-prototype.json'),
        loadJson($root . '/database/practice-curriculum-extension.json'));
    check(count($existing) === 22 && count($questions) === 10, '22 existing + 10 new = 32 questions');
    check(array_keys($questions) === range(0, 9), 'Question list required');
    $skills = [
        'mixed_subtraction' => ['math-g1-t1', range(27, 31), 'mixed-subtraction-pair', 'medium', 'multiple_choice'],
        'fraction_product' => ['math-g1-t2', range(32, 35), 'fraction-product-pair', 'medium', 'multiple_choice'],
        'mixed_product' => ['math-g1-t4', range(40, 43), 'mixed-product-pair', 'medium', 'multiple_choice'],
        'compound_elapsed' => ['math-g0-t2', range(10, 13), 'compound-elapsed-pair', 'medium', 'numeric'],
        'place_compare' => ['math-g0-t0', range(2, 5), 'place-compare-pair', 'easy', 'numeric'],
    ];
    // Independently compute exact rational answers and bind operands to prompt text.
    $expected = [
        'mixed-subtraction-01' => ['mixed_subtraction', rational((4 * 3 + 1) * 2 - (1 * 6 + 5), 6), ['۴ و ۱/۳', '۱ و ۵/۶', 'باقی']],
        'mixed-subtraction-02' => ['mixed_subtraction', rational((5 * 4 + 1) * 3 - (2 * 3 + 2) * 4, 12), ['۵ و ۱/۴', '۲ و ۲/۳', 'باقی']],
        'fraction-product-01' => ['fraction_product', rational(2 * 3, 3 * 5), ['۳/۵ لیتر', '۲/۳ آب']],
        'fraction-product-02' => ['fraction_product', rational(3 * 5, 4 * 8), ['۵/۸ کیلوگرم', '۳/۴ برنج']],
        'mixed-product-01' => ['mixed_product', rational((1 * 3 + 2) * (2 * 4 + 1), 3 * 4), ['۱ و ۲/۳', '۲ و ۱/۴', 'ضرب']],
        'mixed-product-02' => ['mixed_product', rational((2 * 2 + 1) * (1 * 5 + 3), 2 * 5), ['۲ و ۱/۲', '۱ و ۳/۵', 'ضرب']],
        'compound-elapsed-01' => ['compound_elapsed', rational((11 * 60 + 20) - (8 * 60 + 45)), ['۸ و ۴۵ دقیقه', '۱۱ و ۲۰ دقیقه', 'همان صبح', 'چند دقیقه']],
        'compound-elapsed-02' => ['compound_elapsed', rational((11 * 60 + 15) - (9 * 60 + 50)), ['۹ و ۵۰ دقیقه', '۱۱ و ۱۵ دقیقه', 'همان صبح', 'چند دقیقه']],
        'place-compare-01' => ['place_compare', rational(abs(7405200 - 7054800)), ['۷٬۴۰۵٬۲۰۰', '۷٬۰۵۴٬۸۰۰', 'بزرگ‌تر', 'کوچک‌تر']],
        'place-compare-02' => ['place_compare', rational(abs(6820300 - 6280900)), ['۶٬۸۲۰٬۳۰۰', '۶٬۲۸۰٬۹۰۰', 'بزرگ‌تر', 'کوچک‌تر']],
    ];
    $ids = [];
    $prompts = [];
    foreach ($existing as $old) {
        $ids[$old['id']] = true;
        $prompts[preg_replace('/\s+/u', '', $old['prompt'])] = true;
    }
    $baseKeys = ['id','subject','topic','skill','group','difficulty','format','prompt','answer','solution',
        'hint','source','rights','review_status','reviewer','curriculum_topic_id','curriculum_pages','curriculum_role'];
    $pairs = [];
    foreach ($questions as $q) {
        check(is_array($q), 'Question object required');
        $id = $q['id'] ?? '';
        check(is_string($id) && isset($expected[$id]), 'Known question ID required');
        check(!isset($ids[$id]), $id . ': duplicate ID');
        $ids[$id] = true;
        [$skill, $value, $anchors] = $expected[$id];
        [$topic, $pages, $group, $difficulty, $format] = $skills[$skill];
        $keys = $baseKeys;
        if ($format === 'multiple_choice') {
            $keys[] = 'options';
        }
        $actualKeys = array_keys($q);
        sort($keys);
        sort($actualKeys);
        check($keys === $actualKeys, $id . ': schema');
        foreach ($baseKeys as $key) {
            if ($key !== 'curriculum_pages') {
                check(is_string($q[$key]) && trim($q[$key]) !== '', $id . ': string ' . $key);
            }
        }
        check($q['subject'] === 'math' && $q['skill'] === $skill, $id . ': subject/skill');
        check($q['group'] === $group && $q['difficulty'] === $difficulty && $q['format'] === $format, $id . ': pair metadata');
        check($q['curriculum_topic_id'] === $topic && $q['curriculum_pages'] === $pages && $q['curriculum_role'] === 'direct', $id . ': printed-page mapping');
        check($q['review_status'] === 'prototype_checked' && $q['reviewer'] === 'automated; human approval pending', $id . ': automated review only');
        check(strpos($q['source'], 'تألیف تازه') !== false && strpos($q['source'], 'knowledge/grade-5-mathematics.en.md') !== false, $id . ': source');
        check(strpos($q['rights'], 'محتوای تألیفی این پروژه') !== false && strpos($q['rights'], 'بازتولید نشده') !== false, $id . ': rights');
        foreach (['prompt','solution','hint'] as $field) {
            check(preg_match('/[\x{0600}-\x{06FF}]/u', $q[$field]) === 1, $id . ': Persian ' . $field);
        }
        $promptKey = preg_replace('/\s+/u', '', $q['prompt']);
        check(!isset($prompts[$promptKey]), $id . ': duplicate prompt');
        $prompts[$promptKey] = true;
        foreach ($anchors as $anchor) {
            check(strpos($q['prompt'], $anchor) !== false, $id . ': operand/operation anchor');
        }
        if ($format === 'numeric') {
            check(preg_match('/^[0-9]+$/D', $q['answer']) === 1, $id . ': integer answer');
            $actual = numericValue($q['answer']);
        } else {
            check(is_array($q['options']), $id . ': options array');
            $values = optionValues($q['options']);
            check(isset($values[$q['answer']]), $id . ': answer option reference');
            $actual = $values[$q['answer']];
            check(count(array_filter($values, static function ($v) use ($value) { return $v === $value; })) === 1, $id . ': unique correct option');
        }
        check($actual === $value, $id . ': independent arithmetic');
        // The worked reference must contain the independently computed result.
        [$n, $d] = explode('/', $value);
        $solution = asciiDigits($q['solution']);
        $literal = $d === '1' ? $n : $value;
        check(strpos($solution, $literal) !== false, $id . ': solution result');
        check(strlen($q['hint']) < 240 && strpos(asciiDigits($q['hint']), $literal) === false, $id . ': short nonleaking hint');
        $pairs[$skill][] = [$actual, $q['answer'], $q['topic']];
    }
    check(count($pairs) === 5 && count($ids) === 32, 'Five new skills; 32 unique IDs');
    foreach ($skills as $skill => $metadata) {
        check(isset($pairs[$skill]) && count($pairs[$skill]) === 2, $skill . ': two questions');
        check($pairs[$skill][0][0] !== $pairs[$skill][1][0], $skill . ': distinct mathematical answers');
        check($pairs[$skill][0][1] !== $pairs[$skill][1][1], $skill . ': distinct answer keys');
        check($pairs[$skill][0][2] === $pairs[$skill][1][2], $skill . ': same pair topic');
    }
    // Negative regression: different spellings of the same rational must be rejected.
    $rejected = false;
    try {
        optionValues([['id'=>'a','text'=>'۱/۲'], ['id'=>'b','text'=>'۲/۴'],
            ['id'=>'c','text'=>'۳/۴'], ['id'=>'d','text'=>'۱/۴']]);
    } catch (RuntimeException $e) {
        $rejected = $e->getMessage() === 'Mathematically equivalent options';
    }
    check($rejected, 'Equivalent-option rejection regression');
    echo "PASS: 10 original questions; five pairs; bank total 32; independent arithmetic, schema, mappings, options, hints and review metadata verified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
