<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if(count($argv)!==3||$argv[2]!=='--confirm'){fwrite(STDERR,"Usage: php bin/restore.php /private/path/backup.json.gz --confirm\nThis replaces project learning data. Take a current backup first.\n");exit(1);}
$config=require $root.'/config/local.php';$db=Kashf\Database::connect($config['database']);
Kashf\Backup::restore($db,$argv[1]);echo "Project data restored.\n";
