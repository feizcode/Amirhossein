<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$raw=(string)file_get_contents(dirname(__DIR__).'/config/apis.txt');
$rows=[];
foreach(preg_split('/\R/u',$raw) as $line){
    if(trim($line)==='')continue;
    preg_match_all('~https://[a-zA-Z0-9.-]+(?:/[a-zA-Z0-9_./-]*)?~',$line,$urls);
    $labels=[];
    foreach(['gemini','google','gapgpt','baseurl','api_key','API KEY','model','جمینای'] as $label)if(stripos($line,$label)!==false)$labels[]=$label;
    $rows[]=['labels'=>$labels,'public_urls'=>$urls[0],'has_sk_key'=>(bool)preg_match('/\bsk-[A-Za-z0-9_-]{12,}/',$line),'has_google_style_key'=>(bool)preg_match('/(?:AIza[A-Za-z0-9_-]{20,}|AQ\.[A-Za-z0-9_-]{20,})/',$line)];
}
echo json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
