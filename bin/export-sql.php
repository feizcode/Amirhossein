<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$db=Kashf\Database::connect(['dsn'=>'sqlite::memory:']);Kashf\Database::install($db,json_decode(file_get_contents($root.'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR));
$sql="-- Kashf schema v3; MariaDB 10.1+; import ONLY into the new empty project database.\nSET NAMES utf8mb4;\n".implode("\n",[...Kashf\Database::schema(),...Kashf\Schedule::schema(true),...Kashf\Practice::schema(true),...Kashf\AiCoach::schema(true)])."\nSTART TRANSACTION;\n";
foreach(Kashf\Backup::TABLES as $table)foreach($db->query('SELECT * FROM '.$table) as $row){$values=array_map(fn($v)=>$v===null?'NULL':(is_int($v)?(string)$v:'CONVERT(0x'.bin2hex((string)$v).' USING utf8mb4)'),array_values($row));$sql.='INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',$values).");\n";}
$sql.="COMMIT;\n";file_put_contents($root.'/database/001-initial.sql',$sql);echo "database/001-initial.sql generated; no activity data.\n";
