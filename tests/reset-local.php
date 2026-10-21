<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';$config=require $root.'/config/local.php';
if($config['environment']!=='development'||$config['database']['dsn']!=='sqlite:'.$root.'/work/development.sqlite')exit("Refusing to reset non-test database.\n");
$db=Kashf\Database::connect($config['database']);$db->exec('PRAGMA foreign_keys=OFF');foreach(array_reverse([...Kashf\Backup::TABLES,'login_attempts'])as$t)$db->exec('DROP TABLE IF EXISTS '.$t);$db->exec('PRAGMA foreign_keys=ON');Kashf\Database::install($db,json_decode(file_get_contents($root.'/database/catalog.json'),true));echo "Disposable local database reset.\n";
