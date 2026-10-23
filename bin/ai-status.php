<?php
declare(strict_types=1);
// Read only; deliberately never prints credentials or calls a model.
if(PHP_SAPI!=='cli') exit(1);
$config=require dirname(__DIR__).'/config/local.php';
$ai=$config['ai_coach'] ?? [];
echo json_encode([
    'enabled'=>(bool)($ai['enabled'] ?? false),
    'provider'=>$ai['provider'] ?? 'not configured',
    'free_only'=>(bool)($ai['free_only'] ?? false),
    'key_present'=>is_string($ai['api_key'] ?? null) && trim($ai['api_key'])!=='',
    'model'=>$ai['model'] ?? null,
    'model_verified'=>(bool)($ai['model_verified'] ?? false),
    'provider_terms_verified'=>(bool)($ai['provider_terms_verified'] ?? false),
    'daily_spend_limit'=>$ai['daily_spend_limit'] ?? null,
    'curl_available'=>function_exists('curl_init'),
    'models_verified'=>(bool)($ai['models_verified'] ?? false),
    'models'=>$ai['models'] ?? [],
    'catalog_verified_at'=>$ai['free_models_verified_at'] ?? null,
    'daily_request_limit'=>$ai['daily_request_limit'] ?? null,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
