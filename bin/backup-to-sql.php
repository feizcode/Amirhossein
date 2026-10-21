<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if(count($argv)!==3)exit("Usage: php bin/backup-to-sql.php input.json.gz output.sql\n");
$db=Kashf\Database::connect(['dsn'=>'sqlite::memory:']);Kashf\Database::install($db,json_decode(file_get_contents($root.'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR));Kashf\Backup::restore($db,$argv[1]);
$sql="-- Kashf RESTORE: replaces project data. Back up the current database first.\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n";
foreach(array_reverse(Kashf\Backup::TABLES)as$t)$sql.='DELETE FROM '.$t.";\n";
foreach(Kashf\Backup::TABLES as$t)foreach($db->query('SELECT * FROM '.$t)as$row){$values=array_map(fn($v)=>$v===null?'NULL':(is_int($v)?(string)$v:'CONVERT(0x'.bin2hex((string)$v).' USING utf8mb4)'),array_values($row));$sql.='INSERT INTO '.$t.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',$values).");\n";}
$sql.="COMMIT;\n";if(is_file($argv[2]))exit("Output exists; no changes made.\n");file_put_contents($argv[2],$sql);chmod($argv[2],0600);echo "Private restore SQL created. Import only into the project database.\n";
