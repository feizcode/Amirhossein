<?php
declare(strict_types=1);

// Standalone, exclusively injected transports: this test never makes a network request.
require __DIR__.'/../src/AiCoach.php';
use Kashf\AiCoach;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function database(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE learners (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $db->exec("INSERT INTO learners VALUES (1,'private learner')");
    AiCoach::migrate($db);
    AiCoach::migrate($db);
    return $db;
}
function config(): array
{
    return ['enabled'=>true,'model_verified'=>true,'model'=>'verified-test-model','endpoint'=>'https://api.example.com/v1/chat/completions',
        'api_key'=>'private-test-key','input_price_per_million'=>1.0,'output_price_per_million'=>2.0,
        'daily_request_limit'=>3,'daily_spend_limit'=>1.0,'max_input_tokens'=>4096,'max_output_tokens'=>128,'timeout_seconds'=>1];
}
function freeConfig(): array
{
    $models = ['test/primary:free','test/secondary:free','test/third:free'];
    return array_replace(config(), ['provider'=>'openrouter','free_only'=>true,'models_verified'=>true,
        'models'=>$models,'endpoint'=>'https://openrouter.ai/api/v1/chat/completions',
        'free_models_verified_at'=>gmdate('c'),
        'free_model_prices'=>array_fill_keys($models, ['prompt'=>'0','completion'=>'0','request'=>'0']),
        'input_price_per_million'=>0,'output_price_per_million'=>0,'daily_spend_limit'=>0]);
}
function freeResponse(?string $model): array
{
    return ['status'=>200,'body'=>json_encode(['model'=>$model,
        'choices'=>[['finish_reason'=>'stop','message'=>['content'=>'Approved mock explanation.']]],
        'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]])];
}
function gapgptConfig(): array
{
    return array_replace(config(), [
        'provider'=>'gapgpt',
        'free_only'=>false,
        'provider_terms_verified'=>true,
        'model_verified'=>true,
        'model'=>'gpt-6-luna',
        'endpoint'=>'https://api.gapgpt.app/v1/chat/completions',
        'input_price_per_million'=>0.10,
        'output_price_per_million'=>0.50,
        'max_output_tokens'=>512,
    ]);
}
function gapgptResponse(?string $model, string $content, ?float $cost = null): array
{
    $usage = ['prompt_tokens'=>100,'completion_tokens'=>20];
    if ($cost !== null) $usage['cost'] = $cost;
    return ['status'=>200,'body'=>json_encode([
        'model'=>$model,
        'choices'=>[['finish_reason'=>'stop','message'=>['content'=>$content]]],
        'usage'=>$usage,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
}
$q = ['prompt'=>'What is 2 + 2?', 'answer'=>'4', 'solution'=>'Add two and two to obtain four.', 'hint'=>'Count two more.', 'review_status'=>'approved',
    'learner_name'=>'NEVER SEND ME','student_answer'=>'NEVER SEND THIS','id'=>'private-question-id'];
$calls = 0;
$good = function (string $endpoint, array $payload, string $key, int $timeout) use (&$calls): array {
    $calls++;
    check($endpoint === config()['endpoint'] && $key === 'private-test-key' && $timeout === 1, 'Transport configuration');
    check($payload['reasoning_effort'] === 'low' && $payload['max_completion_tokens'] === 128, 'Bounded low reasoning');
    check($payload['model'] === 'verified-test-model', 'Exact model');
    if (isset($payload['messages'][0]['content'])) check(str_contains($payload['messages'][0]['content'],'plain Persian text') && str_contains($payload['messages'][0]['content'],'کسر بزرگ‌تر از واحد'), 'Child-friendly plain Persian terminology');
    $input = json_decode($payload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
    check(array_keys($input) === ['task','question','answer','approved_solution'], 'Minimal content allowlist');
    check(!str_contains(json_encode($payload), 'NEVER') && !str_contains(json_encode($payload), 'private-question-id'), 'No identifiers or student answer');
    return ['status'=>200,'body'=>json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'A short approved explanation.']]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]])];
};
$never = function (): array { throw new RuntimeException('Forbidden transport invocation'); };

try {
    $db = database();
    $before = $calls;
    $result = (new AiCoach($db, [], $good))->assist($q, 'hint');
    check($result === ['text'=>$q['hint'],'source'=>'stored','status'=>'disabled'] && $calls === $before, 'Disabled default');
    $result = (new AiCoach($db, config(), $good))->assist(array_replace($q,['review_status'=>'prototype_checked']), 'explanation');
    check($result['status'] === 'unapproved' && $result['text'] === $q['solution'] && $calls === $before, 'Prototype never sent live');
    foreach (['draft','rejected',''] as $review) {
        check((new AiCoach($db,config(),$good))->assist(array_replace($q,['review_status'=>$review]),'hint')['status'] === 'unapproved', 'Reject non-approved');
    }
    $badConfig = [
        ['model_verified'=>false],['model'=>''],['model'=>' padded '],['api_key'=>''],['api_key'=>"key\r\ninjected"],
        ['endpoint'=>'http://api.example.com/v1'],['endpoint'=>'https://localhost/v1'],['endpoint'=>'https://127.0.0.1/v1'],
        ['endpoint'=>'https://10.0.0.2/v1'],['endpoint'=>'https://192.168.1.3/v1'],['endpoint'=>'https://[::1]/v1'],
        ['endpoint'=>'https://169.254.169.254/v1'],['endpoint'=>'https://private.internal/v1'],['endpoint'=>'https://user:pass@api.example.com/v1'],
        ['endpoint'=>'https://api.example.com/v1?secret=x'],['endpoint'=>'https://api.example.com:444/v1'],
        ['input_price_per_million'=>null],['output_price_per_million'=>0],['input_price_per_million'=>-1],['output_price_per_million'=>INF],
        ['daily_request_limit'=>0],['daily_request_limit'=>'3'],['daily_spend_limit'=>0],['max_output_tokens'=>99999],['max_input_tokens'=>0],['timeout_seconds'=>0],
    ];
    foreach ($badConfig as $patch) {
        check((new AiCoach($db,array_replace(config(),$patch),$good))->assist($q,'hint')['status'] === 'configuration', 'Fail closed config: '.json_encode($patch, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
    check($calls === $before && (int)$db->query('SELECT COUNT(*) FROM practice_ai_usage')->fetchColumn() === 0, 'Gates neither call nor reserve');
    check((new AiCoach($db,config(),$good))->assist($q,'other')['status'] === 'invalid_task', 'Task allowlist');
    check((new AiCoach($db,config(),$good))->assist(array_replace($q,['prompt'=>'']),'hint')['status'] === 'invalid_input', 'Reject missing content');
    check((new AiCoach($db,config(),$good))->assist(array_replace($q,['prompt'=>"\xFF"]),'hint')['status'] === 'invalid_input', 'Reject malformed UTF-8');
    check((new AiCoach($db,config(),$good))->assist(array_replace($q,['prompt'=>str_repeat('x',4096)]),'hint')['status'] === 'input_limit', 'Input bounded before reservation');
    $coach = new AiCoach($db,config(),$good);
    check($coach->assist($q,'hint')['source'] === 'ai', 'Successful live mock');
    $row = $db->query('SELECT * FROM practice_ai_usage')->fetch(PDO::FETCH_ASSOC);
    check($row['status'] === 'completed' && abs((float)$row['actual_cost'] - 0.000140) < 0.00000001, 'Validated usage cost');
    check((float)$row['reserved_cost'] >= (float)$row['actual_cost'] && strlen($row['id']) <= 64, 'Conservative reservation');
    $serialized = json_encode($row);
    check(!str_contains($serialized,'private-test-key') && !str_contains($serialized,'What is') && !str_contains($serialized,'private learner'), 'Usage log contains no content or credentials');
    $coach->assist($q,'explanation');
    $coach->assist($q,'hint');
    $before = $calls;
    check($coach->assist($q,'hint')['status'] === 'daily_limit' && $calls === $before, 'Request quota includes all reservations');

    $db = database();
    $cheap = array_replace(config(),['daily_spend_limit'=>0.000001]);
    check((new AiCoach($db,$cheap,$good))->assist($q,'hint')['status'] === 'daily_limit', 'Worst case budget enforced');
    check((int)$db->query('SELECT COUNT(*) FROM practice_ai_usage')->fetchColumn() === 0, 'Over-budget does not reserve');

    $db = database();
    (new AiCoach($db,config(),$good))->assist($q,'hint');
    $reserved = (float)$db->query('SELECT reserved_cost FROM practice_ai_usage')->fetchColumn();
    $budget = array_replace(config(),['daily_spend_limit'=>$reserved * 1.5]);
    $before = $calls;
    check((new AiCoach($db,$budget,$good))->assist($q,'hint')['status'] === 'daily_limit' && $calls === $before, 'Settled spend remains conservatively reserved');
    $db->exec("UPDATE practice_ai_usage SET status='error',actual_cost=NULL");
    check((new AiCoach($db,$budget,$good))->assist($q,'hint')['status'] === 'daily_limit', 'Errors retain spend reservation');

    $responses = [
        ['status'=>429,'body'=>'private error'], ['status'=>500,'body'=>'server down'], ['status'=>200,'body'=>'{broken'],
        ['status'=>200,'body'=>'{}'], ['status'=>200,'body'=>str_repeat('x',65537)],
    ];
    foreach ([
        ['finish_reason'=>'length','message'=>['content'=>'truncated']],
        ['finish_reason'=>'stop','message'=>['content'=>'']],
        ['finish_reason'=>'stop','message'=>['content'=>str_repeat('x',16385)]],
    ] as $choice) $responses[] = ['status'=>200,'body'=>json_encode(['choices'=>[$choice],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>10]])];
    foreach ([null,[],['prompt_tokens'=>0,'completion_tokens'=>1],['prompt_tokens'=>1,'completion_tokens'=>129],['prompt_tokens'=>99999,'completion_tokens'=>1],['prompt_tokens'=>'10','completion_tokens'=>1]] as $usage) {
        $responses[] = ['status'=>200,'body'=>json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'text']]],'usage'=>$usage])];
    }
    foreach ($responses as $response) {
        $db = database();
        $result = (new AiCoach($db,config(),fn()=>$response))->assist($q,'explanation');
        check($result === ['text'=>$q['solution'],'source'=>'stored','status'=>'error'], 'Malformed/provider errors return stored solution');
        $row = $db->query('SELECT * FROM practice_ai_usage')->fetch(PDO::FETCH_ASSOC);
        check($row['status'] === 'error' && $row['actual_cost'] === null && (float)$row['reserved_cost'] > 0, 'Error reservation retained, no invented actual usage');
    }
    $db = database();
    $single = array_replace(config(),['daily_request_limit'=>1]);
    $throws = new AiCoach($db,$single,function () { throw new RuntimeException('secret provider exception'); });
    check($throws->assist($q,'hint')['status'] === 'error', 'Transport exception sanitized');
    check($throws->assist($q,'hint')['status'] === 'daily_limit', 'Errors count toward quota');

    $db = database();
    $timeout = new AiCoach($db,config(),function () use ($good): array { usleep(1100000); return $good(config()['endpoint'], ['model'=>'verified-test-model','reasoning_effort'=>'low','max_completion_tokens'=>128,'messages'=>[[],['content'=>json_encode(['task'=>'hint','question'=>'x','answer'=>'y','approved_solution'=>'z'])]]], 'private-test-key', 1); });
    check($timeout->assist($q,'hint')['status'] === 'error', 'Timeout fails closed');

    // A transport represents an overlapping request after the first reservation commits.
    $db = database();
    $overlapCalls = 0;
    $outer = new AiCoach($db,$single,function () use ($db,$single,$q,&$overlapCalls): array {
        $inner = new AiCoach($db,$single,function () use (&$overlapCalls): array { $overlapCalls++; return []; });
        check($inner->assist($q,'hint')['status'] === 'daily_limit', 'In-flight reservation visible to competing request');
        throw new RuntimeException('simulated upstream failure');
    });
    $outer->assist($q,'hint');
    check($overlapCalls === 0 && (int)$db->query('SELECT COUNT(*) FROM practice_ai_usage')->fetchColumn() === 1, 'No quota oversubscription');
    // Old day reservations do not prevent requests today.
    $db->exec("UPDATE practice_ai_usage SET day_key='2000-01-01'");
    check((new AiCoach($db,$single,$good))->assist($q,'hint')['source'] === 'ai', 'UTC daily rollover');
    $db = database();
    $db->exec('DELETE FROM learners');
    check((new AiCoach($db,config(),$good))->assist($q,'hint')['status'] === 'error', 'Missing lock row fails closed');
    $db = database();
    $db->beginTransaction();
    check((new AiCoach($db,config(),$good))->assist($q,'hint')['status'] === 'error' && $db->inTransaction(), 'Do not interfere with caller transaction');
    $db->rollBack();
    // OpenRouter handles the priority chain natively in one bounded HTTP call.
    $db = database();
    $freeCalls = 0;
    $freeGood = function (string $endpoint, array $payload, string $key, int $timeout) use (&$freeCalls): array {
        $freeCalls++;
        check($endpoint === freeConfig()['endpoint'] && $key === config()['api_key'] && $timeout === 1, 'Free transport exact endpoint/auth/timeout');
        check($payload['models'] === freeConfig()['models'] && !isset($payload['model']), 'Native priority models only');
        check($payload['max_tokens'] === 128 && !isset($payload['max_completion_tokens']) && !isset($payload['reasoning_effort']), 'Free token bound without reasoning override');
        check($payload['provider']['max_price'] === ['prompt'=>0,'completion'=>0,'request'=>0], 'Documented provider zero price ceiling');
        check(!str_contains(json_encode($payload), 'private-test-key'), 'Key absent from payload');
        return freeResponse('test/secondary:free');
    };
    $free = new AiCoach($db,freeConfig(),$freeGood);
    check($free->assist($q,'hint')['source'] === 'ai' && $freeCalls === 1, 'Native fallback succeeds in one call with zero spend limit');
    $row = $db->query('SELECT * FROM practice_ai_usage')->fetch(PDO::FETCH_ASSOC);
    check($row['model'] === 'test/secondary:free' && $row['status'] === 'completed' && (float)$row['actual_cost'] === 0.0 && (float)$row['reserved_cost'] === 0.0, 'Final free model logged and zero usage settled');
    $free->assist($q,'hint');
    $free->assist($q,'explanation');
    check($free->assist($q,'hint')['status'] === 'daily_limit' && $freeCalls === 3, 'Zero-cost reservations still count toward daily quota');
    check(!str_contains(json_encode($db->query('SELECT * FROM practice_ai_usage')->fetchAll(PDO::FETCH_ASSOC)),config()['api_key']), 'Free logs never contain key');

    $badFree = [
        ['provider'=>'openai'],['endpoint'=>'https://openrouter.ai/api/v1/chat/completions?x=1'],
        ['endpoint'=>'https://openrouter.ai:443/api/v1/chat/completions'],['endpoint'=>'https://api.example.com/v1/chat/completions'],
        ['models_verified'=>false],['models'=>[]],['models'=>['test/a:free','test/b']],
        ['models'=>['openrouter/free']],['models'=>['openrouter/auto:free']],['models'=>['~test/a:free']],
        ['models'=>['test/a:free:nitro']],['models'=>['test/a:free','test/a:free']],
        ['models'=>array_fill(0,4,'test/a:free')],['models'=>[['test/a:free']]],['models'=>['x'=>'test/a:free']],
        ['free_models_verified_at'=>null],['free_models_verified_at'=>'now'],
        ['free_models_verified_at'=>gmdate('c',time()-7*86400-10)],['free_models_verified_at'=>time()+3600],
        ['free_model_prices'=>[]],['free_model_prices'=>['test/primary:free'=>['prompt'=>0]]],
        ['free_model_prices'=>array_fill_keys(freeConfig()['models'],['prompt'=>0,'completion'=>0.01])],
        ['free_model_prices'=>array_fill_keys(freeConfig()['models'],['prompt'=>0,'completion'=>0,'request'=>1])],
        ['free_model_prices'=>array_fill_keys(freeConfig()['models'],['prompt'=>0,'completion'=>0,'image'=>-1])],
        ['input_price_per_million'=>1],['daily_spend_limit'=>-1],['api_key'=>"secret\r\ninjected"],
    ];
    foreach ($badFree as $patch) {
        $db = database();
        check((new AiCoach($db,array_replace(freeConfig(),$patch),$never))->assist($q,'hint')['status'] === 'configuration', 'Free config fails closed');
        check((int)$db->query('SELECT COUNT(*) FROM practice_ai_usage')->fetchColumn() === 0, 'Invalid free config never reserves');
    }
    $db = database();
    check((new AiCoach($db,freeConfig(),$never))->assist(array_replace($q,['review_status'=>'prototype_checked']),'hint')['status'] === 'unapproved', 'Free mode preserves approval gate');
    foreach ([null,'test/secondary','test/paid','unknown/model:free','private-test-key'] as $reported) {
        $db = database();
        $result = (new AiCoach($db,freeConfig(),fn()=>freeResponse($reported)))->assist($q,'explanation');
        check($result === ['text'=>$q['solution'],'source'=>'stored','status'=>'error'], 'Unknown or paid reported model falls back without exposing body');
        $row = $db->query('SELECT * FROM practice_ai_usage')->fetch(PDO::FETCH_ASSOC);
        check($row['status'] === 'error' && $row['actual_cost'] === null && $row['model'] === 'test/primary:free', 'Unknown model never persisted as final');
    }
    $db = database();
    $charged = freeResponse('test/primary:free');
    $chargedBody = json_decode($charged['body'],true);
    $chargedBody['usage']['cost'] = 0.01;
    $charged['body'] = json_encode($chargedBody);
    check((new AiCoach($db,freeConfig(),fn()=>$charged))->assist($q,'hint')['status'] === 'error', 'Reported nonzero cost fails closed');
    foreach ([401,403,429,500] as $status) {
        $db = database();
        $coach = new AiCoach($db,array_replace(freeConfig(),['daily_request_limit'=>1]),fn()=>['status'=>$status,'body'=>'private-test-key']);
        check($coach->assist($q,'hint') === ['text'=>$q['hint'],'source'=>'stored','status'=>'error'], 'Free auth/provider errors sanitized');
        check($coach->assist($q,'hint')['status'] === 'daily_limit', 'Free errors retain request reservation');
    }
    $db = database();
    $coach = new AiCoach($db,freeConfig(),function (): array { throw new RuntimeException('private-test-key'); });
    check($coach->assist($q,'hint')['status'] === 'error', 'Free request exception sanitized');
    $db = database();
    $coach = new AiCoach($db,freeConfig(),function (): array { usleep(1100000); return freeResponse('test/primary:free'); });
    check($coach->assist($q,'hint')['status'] === 'error', 'Free chain has single timeout budget');
    // Selected Luna contract: every request below uses an injected offline transport.
    $gq = array_replace($q, ['prompt'=>'حاصل ۲ + ۲ چیست؟','answer'=>'۴',
        'solution'=>'دو را با دو جمع می‌کنیم تا چهار به دست آید.',
        'hint'=>'دو تا دیگر بشمار.','approved_by'=>'reviewer','approved_at'=>'2026-10-23T00:00:00Z']);
    $hintText = 'از دو شروع کن و دو تا دیگر بشمار.';
    $explanationText = 'چون دو را با دو جمع می‌کنیم، حاصل ۴ می‌شود.';
    $gapCalls = 0;
    $gapGood = function (string $endpoint, array $payload, string $key, int $timeout) use (&$gapCalls,$hintText): array {
        $gapCalls++;
        check($endpoint === 'https://api.gapgpt.app/v1/chat/completions' && $key === 'private-test-key' && $timeout === 1, 'GapGPT exact endpoint/auth/deadline');
        check(array_keys($payload) === ['model','max_tokens','messages'] && $payload['model'] === 'gpt-6-luna' && $payload['max_tokens'] === 512, 'GapGPT proven payload: no reasoning override');
        $input = json_decode($payload['messages'][1]['content'],true);
        check(array_keys($input) === ['task','question','answer','approved_solution'], 'GapGPT never sends approval metadata or identifiers');
        return gapgptResponse('openai/gpt-6-luna',$hintText,0.000020);
    };
    $db = database();
    $coach = new AiCoach($db,gapgptConfig(),$gapGood);
    check($coach->assist($gq,'hint') === ['text'=>$hintText,'source'=>'ai','status'=>'completed'], 'Approved Luna sample mock succeeds');
    $row = $db->query('SELECT * FROM practice_ai_usage')->fetch(PDO::FETCH_ASSOC);
    check($row['model'] === 'openai/gpt-6-luna' && (float)$row['actual_cost'] === 0.000020 && (float)$row['reserved_cost'] > 0.000020, 'Luna records returned model and positive bounded expense');
    check((new AiCoach(database(),gapgptConfig(),fn()=>gapgptResponse('gpt-6-luna',$explanationText)))->assist($gq,'explanation')['source'] === 'ai', 'Luna configured response alias and calculated cost accepted');
    foreach ([
        ['enabled'=>false],['provider_terms_verified'=>false],['provider_terms_verified'=>'true'],['model_verified'=>false],
        ['model'=>'openai/gpt-6-luna'],['endpoint'=>'https://api.gapgpt.app/v1/chat/completions?x=1'],
        ['endpoint'=>'https://api.gapgpt.app:443/v1/chat/completions'],['free_only'=>true],['free_only'=>null],
        ['input_price_per_million'=>0],['input_price_per_million'=>0.11],['output_price_per_million'=>0.51],
        ['input_price_per_million'=>NAN],['output_price_per_million'=>'unknown'],['max_output_tokens'=>128],
    ] as $patch) {
        $db = database();
        $result = (new AiCoach($db,array_replace(gapgptConfig(),$patch),$never))->assist($gq,'hint');
        check($result['status'] === (isset($patch['enabled']) ? 'disabled' : 'configuration'), 'Luna configuration gate');
        check((int)$db->query('SELECT COUNT(*) FROM practice_ai_usage')->fetchColumn() === 0, 'Luna gates do not reserve');
    }
    foreach (['approved_by','approved_at'] as $field) {
        foreach ([null,'',123] as $value) {
            check((new AiCoach(database(),gapgptConfig(),$never))->assist(array_replace($gq,[$field=>$value]),'hint')['status'] === 'unapproved', 'Luna requires approval audit fields');
        }
    }
    // Explicit beta is narrow and truthful: no approval/terms metadata is promoted.
    $betaConfig = array_replace(gapgptConfig(), ['beta_enabled'=>true,
        'beta_acknowledged_at'=>gmdate('Y-m-d\TH:i:s\Z'), 'provider_terms_verified'=>false]);
    $prototype = array_replace($gq, ['review_status'=>'prototype_checked','approved_by'=>null,'approved_at'=>null]);
    $originalPrototype = $prototype;
    $originalBetaConfig = $betaConfig;
    $betaCalls = 0;
    $betaGood = function ($endpoint, $payload) use (&$betaCalls,$hintText): array {
        $betaCalls++;
        $input = json_decode($payload['messages'][1]['content'],true);
        check(array_keys($input) === ['task','question','answer','prototype_solution'], 'Beta does not label prototype solution approved');
        check(!str_contains($payload['messages'][0]['content'],'approved'), 'Beta prompt makes no approval claim');
        return gapgptResponse('gpt-6-luna',$hintText);
    };
    check((new AiCoach(database(),$betaConfig,$betaGood))->assist($prototype,'hint')['source'] === 'ai', 'Explicit beta allows checked prototype without human approval or verified terms');
    check($betaCalls === 1 && $prototype === $originalPrototype && $betaConfig === $originalBetaConfig
        && $betaConfig['provider_terms_verified'] === false && $prototype['approved_by'] === null, 'Beta preserves false flags and original audit metadata');
    foreach (['draft','rejected','','unchecked'] as $review) {
        check((new AiCoach(database(),$betaConfig,$never))->assist(array_replace($prototype,['review_status'=>$review]),'hint')['status'] === 'unapproved', 'Beta never permits unchecked content');
    }
    foreach ([['beta_enabled'=>false],['beta_enabled'=>'true'],['beta_acknowledged_at'=>null],
        ['beta_acknowledged_at'=>'now'],['beta_acknowledged_at'=>'2026-02-30T00:00:00Z'],
        ['beta_acknowledged_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)]] as $patch) {
        $invalidBeta = array_replace($betaConfig,$patch);
        check((new AiCoach(database(),$invalidBeta,$never))->assist($prototype,'hint')['status'] === 'unapproved', 'Beta requires boolean opt-in and exact real nonfuture timestamp');
        check((new AiCoach(database(),$invalidBeta,$never))->assist($gq,'hint')['status'] === 'configuration', 'Invalid beta cannot bypass terms gate');
    }
    check((new AiCoach(database(),$betaConfig,$never))->assist(array_replace($gq,['approved_by'=>null]),'hint')['status'] === 'unapproved', 'Beta does not excuse incomplete approved audit records');
    foreach ([config(),freeConfig()] as $standard) {
        $standard = array_replace($standard,['beta_enabled'=>true,'beta_acknowledged_at'=>$betaConfig['beta_acknowledged_at']]);
        check((new AiCoach(database(),$standard,$never))->assist($prototype,'hint')['status'] === 'unapproved', 'Beta exception GapGPT only');
    }
    foreach ([['model_verified'=>false],['daily_request_limit'=>0],['endpoint'=>'https://api.example.com/v1']] as $patch) {
        check((new AiCoach(database(),array_replace($betaConfig,$patch),$never))->assist($prototype,'hint')['status'] === 'configuration', 'Beta retains normal safety configuration gates');
    }
    check((new AiCoach(database(),$betaConfig,fn()=>['status'=>500,'body'=>'error']))->assist($prototype,'hint') === ['text'=>$prototype['hint'],'source'=>'stored','status'=>'error'], 'Beta failure is explicitly stored, not AI');
    $badGap = [
        ['status'=>200,'body'=>'{broken'], ['status'=>200,'body'=>'{}'], ['status'=>429,'body'=>'secret'],
        gapgptResponse(null,$hintText),gapgptResponse('other/model',$hintText),
        gapgptResponse('openai/gpt-6-luna',$hintText,-0.1),gapgptResponse('openai/gpt-6-luna',$hintText,0),
        gapgptResponse('openai/gpt-6-luna',$hintText,1),gapgptResponse('openai/gpt-6-luna',$hintText,0.000021),
    ];
    foreach ([null,'unknown',[],true] as $cost) {
        $response = gapgptResponse('gpt-6-luna',$hintText);
        $body = json_decode($response['body'],true);
        $body['usage']['cost'] = $cost;
        $response['body'] = json_encode($body);
        $badGap[] = $response;
    }
    foreach (['شروع کن A','شروع کن Ж','شروع کن ก','دو kg را جمع کن.','**دو تا بشمار**','\\frac{2}{3}',
        'حاصل ۴ است.','حاصل 4 است.','حاصل ٤ است.'] as $text) {
        $badGap[] = gapgptResponse('gpt-6-luna',$text);
    }
    foreach ($badGap as $response) {
        $db = database();
        $coach = new AiCoach($db,array_replace(gapgptConfig(),['daily_request_limit'=>1]),fn()=>$response);
        check($coach->assist($gq,'hint') === ['text'=>$gq['hint'],'source'=>'stored','status'=>'error'], 'Luna malformed/cost/model/script/markup/leak falls back');
        $row = $db->query('SELECT * FROM practice_ai_usage')->fetch(PDO::FETCH_ASSOC);
        check($row['status'] === 'error' && $row['actual_cost'] === null && (float)$row['reserved_cost'] > 0, 'Luna errors retain positive spend reservation');
        check($coach->assist($gq,'hint')['status'] === 'daily_limit', 'Luna errors count toward daily requests, no retry');
    }
    foreach (['۴','پاسخ برابر با چهار است.','پاسخ برابر با ۴ است.'] as $text) {
        check((new AiCoach(database(),gapgptConfig(),fn()=>gapgptResponse('gpt-6-luna',$text)))->assist($gq,'explanation')['status'] === 'error', 'Answer-only explanation falls back');
    }
    check((new AiCoach(database(),gapgptConfig(),fn()=>gapgptResponse('gpt-6-luna','از ۲۴ شروع کن و دو تا بشمار.')))->assist($gq,'hint')['source'] === 'ai', 'Literal 4 does not match 24');
    foreach (['۱٬۲۳۴','1,234','١٢٣٤'] as $text) {
        $thousands = array_replace($gq,['answer'=>'1234']);
        check((new AiCoach(database(),gapgptConfig(),fn()=>gapgptResponse('gpt-6-luna','حاصل '.$text.' است.')))->assist($thousands,'hint')['status'] === 'error', 'Normalize digit scripts and thousands separators for literal leaks');
    }
    $db = database();
    $coach = new AiCoach($db,gapgptConfig(),$gapGood);
    $coach->assist($gq,'hint');
    $reserved = (float)$db->query('SELECT reserved_cost FROM practice_ai_usage')->fetchColumn();
    $budget = array_replace(gapgptConfig(),['daily_spend_limit'=>$reserved*1.5]);
    check((new AiCoach($db,$budget,$never))->assist($gq,'hint')['status'] === 'daily_limit', 'Luna nonzero daily spend reservation enforced');
    $db->exec("UPDATE practice_ai_usage SET status='error',actual_cost=NULL");
    check((new AiCoach($db,$budget,$never))->assist($gq,'hint')['status'] === 'daily_limit', 'Luna errors retain daily expense budget');
    echo "AI coach: {$checks} checks passed (mock transport only).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
    exit(1);
}
