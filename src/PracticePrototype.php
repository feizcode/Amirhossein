<?php
declare(strict_types=1);
namespace Kashf;

/** Deliberately isolated pilot state, not production learning evidence. */
final class PracticePrototype
{
    public static function questions(string $root): array
    {
        $bank = json_decode((string)file_get_contents($root.'/database/practice-prototype.json'), true, 512, JSON_THROW_ON_ERROR);
        $bank = isset($bank['questions']) ? $bank['questions'] : $bank;
        $extension = $root.'/database/practice-curriculum-extension.json';
        if (is_file($extension)) $bank = array_merge($bank, json_decode((string)file_get_contents($extension), true, 512, JSON_THROW_ON_ERROR));
        $v1 = $root.'/database/practice-v1-extension.json';
        if (is_file($v1)) $bank = array_merge($bank, json_decode((string)file_get_contents($v1), true, 512, JSON_THROW_ON_ERROR));
        return array_values(array_filter($bank,fn($q)=>in_array($q['review_status'] ?? '', ['prototype_checked','approved'],true)));
    }

    /** Explicit curriculum links, not a numeric lesson frontier or evidence of mastery. */
    public static function eligibility(array $questions, array $learning): array
    {
        $links = [
            'fraction_addition' => ['math-g1-t1'],
            'fraction_of_quantity' => ['math-g1-t2'],
            'growing_differences' => ['math-g0-t3'],
            'place_value' => ['math-g0-t0'],
            'billion' => ['math-g0-t1'],
            'compound_time' => ['math-g0-t2'],
            'improper_fraction' => ['math-g1-t0'],
            'mixed_addition' => ['math-g1-t1'],
            'fraction_division' => ['math-g1-t3'],
            'mixed_subtraction' => ['math-g1-t1'],
            'fraction_product' => ['math-g1-t2'],
            'mixed_product' => ['math-g1-t4'],
            'compound_elapsed' => ['math-g0-t2'],
            'place_compare' => ['math-g0-t0'],
        ];
        $studied = [];
        $labels = [];
        foreach ($learning['resources'] ?? [] as $resource) {
            if ($resource['subject'] !== 'math' || $resource['id'] !== 'school' || $resource['archived']) continue;
            foreach ($resource['topics'] as $topic) $labels[$topic['id']] = $topic['name'];
        }
        foreach ($learning['logs'] ?? [] as $log) {
            if ($log['subject'] === 'math' && $log['source'] === 'school' && isset($labels[$log['topic_id']])) {
                $studied[$log['topic_id']] ??= $labels[$log['topic_id']];
            }
        }
        foreach ($learning['completions'] ?? [] as $completion) {
            if ($completion['subject'] === 'math' && $completion['source'] === 'school' && isset($labels[$completion['topic']])) {
                $studied[$completion['topic']] ??= $labels[$completion['topic']];
            }
        }
        $eligible = [];
        $skills = [];
        // Most recent recorded topic is suggested first, if this prototype covers it.
        foreach ($studied as $topicId => $title) foreach ($links as $skill => $topics) {
            if (!in_array($topicId, $topics, true)) continue;
            foreach ($questions as $question) {
                if ($question['skill'] === $skill && !isset($eligible[$question['id']])) {
                    $eligible[$question['id']] = $question;
                    $skills[$skill] = $title;
                }
            }
        }
        $coverage = [];
        foreach ($studied as $topicId => $title) {
            $count = 0;
            foreach ($eligible as $question) if (in_array($topicId, $links[$question['skill']] ?? [], true)) $count++;
            $coverage[] = ['topic'=>$title, 'count'=>$count];
        }
        return ['questions'=>array_values($eligible), 'skills'=>$skills, 'studied'=>array_values($studied), 'coverage'=>$coverage];
    }

    public static function act(array &$state, array $questions, array $input): void
    {
        $action = (string)($input['action'] ?? '');
        if ($action === 'start') {
            if (!empty($state) && empty($state['finished'])) return; // Resume, never replace an unfinished session.
            $skill = (string)($input['skill'] ?? '');
            $current = array_values(array_filter($questions, fn($q) => $q['skill'] === $skill));
            if (count($current) < 2) throw new \InvalidArgumentException('برای این مبحث هنوز دو سؤال متناسب آماده نشده است.');
            $others = array_values(array_filter($questions, fn($q) => $q['skill'] !== $skill));
            $short = ($input['duration'] ?? '') === '5';
            $selected = [$current[0], $current[1]];
            if (!$short && count($others) > 1) $selected[] = array_shift($others);
            if ($others) $selected[] = $others[count($others)-1];
            $state = ['ids'=>array_column($selected, 'id'), 'optional_id'=>$others ? $others[count($others)-1]['id'] : null, 'index'=>0, 'records'=>[], 'finished'=>false, 'token'=>bin2hex(random_bytes(16))];
            return;
        }
        if (!$state || $state['finished']) return;
        // Rotating per-render token makes double clicks and stale back-button submissions harmless.
        if (!hash_equals($state['token'], (string)($input['token'] ?? ''))) return;
        $id = $state['ids'][$state['index']];
        $q = array_values(array_filter($questions, fn($q) => $q['id'] === $id))[0];
        $r = $state['records'][$id] ?? ['attempts'=>[], 'hint'=>false, 'solution'=>false, 'done'=>false, 'correct'=>false];
        if ($action === 'hint' && !$r['done']) $r['hint'] = true;
        if ($action === 'solution') { $r['solution'] = true; $r['done'] = true; }
        if (in_array($action, ['answer','unknown'], true) && !$r['done']) {
            $answer = trim((string)($input['answer'] ?? ''));
            $answer = strtr($answer, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
            if ($action === 'answer' && ($answer === '' || strlen($answer)>80)) throw new \InvalidArgumentException('پاسخ کوتاهت را وارد کن یا «هنوز نمی‌دانم» را بزن.');
            $correct = $action === 'answer' && ($q['format'] === 'numeric'
                ? preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/D', $answer) && (float)$answer === (float)$q['answer']
                : $answer === (string)$q['answer']);
            $r['attempts'][] = ['answer'=>$action === 'unknown' ? null : $answer, 'correct'=>(bool)$correct, 'hint'=>$r['hint']];
            $r['correct'] = (bool)$correct;
            $r['done'] = (bool)$correct;
        }
        $skip = $action === 'skip' && $id === ($state['optional_id'] ?? null) && !$r['attempts'] && !$r['solution'];
        if (!$skip) $state['records'][$id] = $r;
        else unset($state['records'][$id]);
        if (($action === 'next' && $r['done']) || $skip) {
            $state['index']++;
            $state['finished'] = $state['index'] >= count($state['ids']);
        }
        $state['token'] = bin2hex(random_bytes(16));
    }

    public static function outcome(array $r): string
    {
        if (!$r['correct']) return 'needs';
        return empty($r['attempts'][0]['hint']) && count($r['attempts']) === 1 ? 'independent' : 'help';
    }
}
