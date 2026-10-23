<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if(($argv[1]??'')!=='--confirm-backup')exit("Back up database AND private config first. MariaDB DDL commits implicitly.\nUsage: php bin/migrate.php --confirm-backup\n");
$config=require $root.'/config/local.php';
$db=Kashf\Database::connect($config['database']);
Kashf\Schedule::migrate($db);
Kashf\Practice::migrate($db);
Kashf\AiCoach::migrate($db);
echo "Migrations installed: schedule, practice, and AI usage. No learning records changed.\n";
