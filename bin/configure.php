<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$output=$root.'/config/local.php';
if(!is_dir($root.'/work'))mkdir($root.'/work',0700,true);
if(isset($argv[1])&&str_starts_with($argv[1],'--output=')){$requested=substr($argv[1],9);$parent=realpath(dirname($requested));if(!$parent||!in_array($parent,[$root.'/work',$root.'\\work',$root.'/config',$root.'\\config'],true))exit("Output must be inside private work/ or config/.\n");$output=$parent.DIRECTORY_SEPARATOR.basename($requested);}
if(is_file($output)){fwrite(STDERR,"Configuration exists; edit it privately instead.\n");exit(1);}
function ask(string $label):string{echo $label.': ';return trim((string)fgets(STDIN));}
fwrite(STDOUT,"Run this only in a PRIVATE terminal. Secret entries may be visible in your terminal.\n");
$dbUser=ask('Database user');$dbPassword=ask('Database password');$familyPassword=ask('Family password (12+ characters)');
if(mb_strlen($familyPassword)<12||strlen($familyPassword)>72)exit("Use at least 12 characters and at most 72 UTF-8 bytes.\n");
if($dbUser===''||$dbPassword===''||ask('Confirm family password')!==$familyPassword)exit("Missing database credentials or passwords did not match.\n");
$config=['environment'=>'production','database'=>['dsn'=>'mysql:host=localhost;dbname=feizaiir_amir;charset=utf8mb4','user'=>$dbUser,'password'=>$dbPassword],'password_hash'=>password_hash($familyPassword,PASSWORD_DEFAULT),'app_key'=>bin2hex(random_bytes(32)),'secure_cookies'=>true,'session_days'=>7];
file_put_contents($output,"<?php\nreturn ".var_export($config,true).";\n");chmod($output,0600);echo "Private configuration created.\n";
