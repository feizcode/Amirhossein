<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$m=json_decode((string)file_get_contents($root.'/outputs/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$secrets=[];
foreach(['config/apis.txt','config/local.php'] as $p){
    $raw=(string)file_get_contents($root.'/'.$p);
    preg_match_all('/\bsk-[A-Za-z0-9_-]{12,}|AIza[A-Za-z0-9_-]{20,}|AQ\.[A-Za-z0-9_-]{20,}/',$raw,$matches);
    foreach($matches[0] as $s)$secrets[]=$s;
}
foreach($m['artifacts'] as $a){
    $path=$root.'/outputs/'.$a['file'];
    if(!hash_equals($a['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Artifact hash mismatch');
    $z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('Cannot open zip');
    for($i=0;$i<$z->numFiles;$i++){
        $n=$z->getNameIndex($i);
        if(preg_match('~(?:^|/)(?:local\.php|apis\.txt|\.env|[^/]+\.sqlite)$~i',$n))throw new RuntimeException('Private file included');
        if(preg_match('~\.(?:php|txt|json|md|js|cjs|html|sql)$~i',$n)){
            $text=$z->getFromIndex($i);
            foreach($secrets as $s)if(str_contains($text,$s))throw new RuntimeException('Credential included');
        }
    }
    $z->close();echo $a['file'].': PASS hashes, private-path exclusion and known sk-key scan'.PHP_EOL;
}
echo "Limited audit only: not an exhaustive secret scanner.\n";
