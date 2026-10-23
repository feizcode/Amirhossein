<?php
declare(strict_types=1);
/** Public catalog only. No credentials or learner information are read/sent. */
if (PHP_SAPI!=='cli') exit(1);
try {
    $context=stream_context_create(['http'=>['timeout'=>15,'follow_location'=>0,'ignore_errors'=>false],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $raw=@file_get_contents('https://openrouter.ai/api/v1/models',false,$context,0,4000000);
    if ($raw===false) throw new RuntimeException('Could not fetch public OpenRouter catalog.');
    $catalog=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $free=[];
    foreach ($catalog['data'] ?? [] as $model) {
        $id=$model['id'] ?? '';
        $prices=$model['pricing'] ?? [];
        if (!is_string($id) || !str_ends_with($id,':free') || !isset($prices['prompt'],$prices['completion'])) continue;
        $zero=true;
        foreach ($prices as $price) if (!is_numeric($price) || (float)$price!==0.0) $zero=false;
        if ($zero) $free[$id]=$prices;
    }
    $requested=array_slice($argv,1);
    if (!$requested) {
        echo "Public catalog models with explicit :free ID and all listed prices zero (not quality approval):\n";
        foreach(array_keys($free) as $id) echo $id.PHP_EOL;
        echo "\nRun again with 1–3 exact IDs to print a verification configuration block.\n";
        exit(0);
    }
    if (count($requested)>3 || count(array_unique($requested))!==count($requested)) throw new RuntimeException('Choose 1–3 distinct model IDs.');
    $selected=[];
    foreach($requested as $id) {
        if (!isset($free[$id])) throw new RuntimeException('Requested model is missing, has unknown prices, or is not exclusively free: '.$id);
        $selected[$id]=$free[$id];
    }
    $settings=['models'=>$requested,'free_models_verified_at'=>gmdate('c'),'free_model_prices'=>$selected];
    echo "// Pricing/catalog verification only. Review Persian hint quality before enabling models_verified.\n";
    foreach($settings as $key=>$value) echo var_export($key,true).' => '.var_export($value,true).",\n";
} catch (Throwable $e) { fwrite(STDERR,'Verification failed: '.$e->getMessage().PHP_EOL);exit(1); }
