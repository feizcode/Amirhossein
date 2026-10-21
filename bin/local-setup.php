<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if(is_file($root.'/config/local.php')){fwrite(STDERR,"Configuration already exists; no changes made.\n");exit(1);}
foreach(['work','storage/backups','storage/sessions','storage/logs','storage/cache'] as $dir)if(!is_dir($root.'/'.$dir))mkdir($root.'/'.$dir,0700,true);
$password=bin2hex(random_bytes(12));
$config=['environment'=>'development','database'=>['dsn'=>'sqlite:'.$root.'/work/development.sqlite'],'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'app_key'=>bin2hex(random_bytes(32)),'secure_cookies'=>false,'session_days'=>7];
file_put_contents($root.'/config/local.php',"<?php\nreturn ".var_export($config,true).";\n");chmod($root.'/config/local.php',0600);
file_put_contents($root.'/work/test-credentials.json',json_encode(['password'=>$password]));chmod($root.'/work/test-credentials.json',0600);
Kashf\Database::install(Kashf\Database::connect($config['database']),json_decode(file_get_contents($root.'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR));
echo "Local configuration and empty database created. Temporary credentials are in work/test-credentials.json.\n";
