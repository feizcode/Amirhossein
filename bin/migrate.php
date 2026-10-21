<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if(($argv[1]??'')!=='--confirm-backup')exit("Back up database AND private config first. MariaDB DDL commits implicitly.\nUsage: php bin/migrate.php --confirm-backup\n");
$config=require $root.'/config/local.php';
Kashf\Schedule::migrate(Kashf\Database::connect($config['database']));
echo "Schedule migration 2 installed. No seeds or learning records changed.\n";
