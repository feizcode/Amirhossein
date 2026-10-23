<?php
declare(strict_types=1);
/** Explicit local selection; never activates AI or approves content/provider terms. */
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--confirm')exit("Usage: php bin/select-luna.php --confirm\nSelects disabled Luna configuration using the existing private key; never prints it.\n");
try{
    $root=dirname(__DIR__);$path=$root.'/config/local.php';
    $config=require $path;
    $key=getenv('GAPGPT_API_KEY')?:'';
    if($key===''){
        $raw=(string)file_get_contents($root.'/config/apis.txt');
        if(!preg_match('/\bsk-[A-Za-z0-9_-]{12,}/',$raw,$m))throw new RuntimeException();
        $key=$m[0];unset($raw,$m);
    }
    $config['ai_coach']=[
        'enabled'=>false,'provider'=>'gapgpt','free_only'=>false,
        'provider_terms_verified'=>false,'model_verified'=>true,
        'model'=>'gpt-6-luna','endpoint'=>'https://api.gapgpt.app/v1/chat/completions',
        'api_key'=>$key,'input_price_per_million'=>0.10,'output_price_per_million'=>0.50,
        'daily_spend_limit'=>0.01,'daily_request_limit'=>10,
        'max_input_tokens'=>8192,'max_output_tokens'=>512,'timeout_seconds'=>10,
    ];
    // Stage privately then atomically replace. Never create a public config backup.
    $tmp=$path.'.tmp';
    if(file_put_contents($tmp,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false)throw new RuntimeException();
    if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException();}
    echo "Selected gpt-6-luna via GapGPT. Runtime disabled; provider terms and content approval still required. No secret printed.\n";
}catch(Throwable $e){fwrite(STDERR,"Selection failed safely; inspect private settings locally.\n");exit(1);}
