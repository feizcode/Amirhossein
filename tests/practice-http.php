<?php
/** Isolated Application/Slim integration checks. Never loads the repository local config. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Kashf\{Application, Calendar, Database, Practice};
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks'][]=$label;
}
function copyTree(string $from, string $to): void
{
    if (!is_dir($to)) mkdir($to,0700,true);
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot()) continue;
        $dest=$to.'/'.$entry->getFilename();
        if ($entry->isDir()) copyTree($entry->getPathname(),$dest);
        elseif (!copy($entry->getPathname(),$dest)) throw new RuntimeException('Copy failed: '.$dest);
    }
}

$source=dirname(__DIR__);
$temp='C:/Users/afeyz/AppData/Local/Temp/opencode';
if (PHP_SAPI!=='cli' || !is_dir($temp)) throw new RuntimeException('Run CLI with the approved temp directory available.');
$root=$temp.'/practice-http-'.bin2hex(random_bytes(8));
mkdir($root.'/config',0700,true);
copyTree($source.'/templates',$root.'/templates');
copyTree($source.'/database',$root.'/database');
mkdir($root.'/resources',0700,true);
$manifest=is_file($source.'/resources/manifest.json') ? $source.'/resources/manifest.json' : $source.'/public/assets/build/.vite/manifest.json';
if (!is_file($manifest)) throw new RuntimeException('Build assets before running: no asset manifest found.');
copy($manifest,$root.'/resources/manifest.json');
$config=['environment'=>'development','password_hash'=>password_hash('isolated-practice-password',PASSWORD_DEFAULT),'app_key'=>bin2hex(random_bytes(32)),'secure_cookies'=>false,'session_days'=>7,'practice_prototype_enabled'=>true,'practice_allow_prototype_content'=>true,'database'=>['dsn'=>'sqlite:'.$root.'/practice.sqlite']];
$writeConfig=static function() use (&$config,$root): void {
    file_put_contents($root.'/config/local.php',"<?php\nreturn ".var_export($config,true).";\n");
    if (function_exists('opcache_invalidate')) opcache_invalidate($root.'/config/local.php',true);
};
$writeConfig();
$db=Database::connect($config['database']);
Database::install($db,json_decode((string)file_get_contents($root.'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR));
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$checks=[];$sid='';
$request=static function(string $method,string $url,array $data=[],bool $csrf=true,bool $json=false) use ($root,&$sid): array {
    session_id($sid);$_SESSION=[];
    $app=Application::build($root);
    if ($csrf && $method!=='GET') $data['_csrf']=$_SESSION['csrf'];
    $q=(new ServerRequestFactory())->createServerRequest($method,'http://practice.test'.$url);
    parse_str(parse_url($url,PHP_URL_QUERY) ?? '',$query);
    $q=$q->withQueryParams($query);
    if ($method!=='GET') {
        $body=$json ? json_encode($data,JSON_THROW_ON_ERROR) : http_build_query($data);
        $q=$q->withHeader('Content-Type',$json ? 'application/json' : 'application/x-www-form-urlencoded')->withBody((new StreamFactory())->createStream($body));
    }
    $r=$app->handle($q);
    $sid=session_status()===PHP_SESSION_ACTIVE ? session_id() : '';
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    return ['status'=>$r->getStatusCode(),'body'=>(string)$r->getBody(),'location'=>$r->getHeaderLine('Location')];
};
$state=static fn(): array => (new Practice(Database::connect($config['database'])))->current();
try {
    $r=$request('GET','/practice-prototype');
    check($r['status']===303 && $r['location']==='/login','Locked practice redirects to login');
    check($request('GET','/login')['status']===200,'Login template renders with copied manifest');
    check($request('POST','/login',['password'=>'isolated-practice-password'],false)['status']===419,'Login rejects missing CSRF');
    check($request('POST','/login',['password'=>'isolated-practice-password'])['status']===303,'Real password login succeeds');
    check($request('GET','/practice-prototype')['status']===200,'Enabled empty practice page renders');
    $activity=['subject'=>'math','source'=>'school','topic'=>'math-g1-t2','date'=>Calendar::today(),'note'=>'Isolated HTTP fixture','kind'=>'practice','complete'=>true];
    check($request('POST','/api/v1/activities',$activity,true,true)['status']===201,'Seed school math activity through authenticated JSON API');
    $start=['action'=>'start','skill'=>'fraction_of_quantity','duration'=>'5'];
    check($request('POST','/practice-prototype',$start,false)['status']===419 && !$state(),'Missing practice CSRF cannot create a session');
    check($request('POST','/practice-prototype',$start+['_csrf'=>'invalid'],false)['status']===419 && !$state(),'Invalid practice CSRF cannot create a session');
    check($request('POST','/practice-prototype',$start)['status']===303,'Start redirects after persistence');
    $s=$state();$first=$s['questions'][0];
    check(count($s['questions'])>=2 && !$s['finished'],'Start snapshots eligible school questions');
    $r=$request('GET','/practice-prototype');
    check($r['status']===200 && str_contains($r['body'],htmlspecialchars($first['prompt'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'Current question renders');
    $hint=['action'=>'hint','token'=>$s['token']];
    check($request('POST','/practice-prototype',$hint)['status']===303,'Hint POST redirects');
    $s=$state();check($s['records'][$first['id']]['hint'],'Hint evidence persists');
    $request('POST','/practice-prototype',$hint);
    check($state()===$s,'Replayed hint token leaves durable state unchanged');
    $answer=['action'=>'answer','token'=>$s['token'],'answer'=>(string)$first['answer']];
    check($request('POST','/practice-prototype',$answer)['status']===303,'Answer POST redirects');
    $s=$state();$record=$s['records'][$first['id']];
    check($record['correct'] && $record['hint'] && count($record['attempts'])===1,'Correct answer retains hint evidence');
    $request('POST','/practice-prototype',$answer);
    check($state()===$s,'Replayed answer does not append attempts or rotate token');
    $beforeLogout=$sid;
    check($request('POST','/logout')['status']===303,'Logout succeeds');
    check($request('GET','/practice-prototype')['location']==='/login','Logged-out practice is locked');
    $request('GET','/login');
    check($request('POST','/login',['password'=>'isolated-practice-password'])['status']===303 && $sid!==$beforeLogout,'Login creates a different session ID');
    check($request('GET','/practice-prototype')['status']===200 && $state()===$s,'Fresh authenticated session resumes exact durable snapshot');
    $r=$request('GET','/practice-prototype?view=parent');
    check($r['status']===200 && !str_contains($r['body'],'<h1>گزارش تمرین والدین</h1>'),'Child query parameter alone does not open parent report');
    check($request('POST','/api/v1/context',['role'=>'parent'],false,true)['status']===419,'Parent workflow context requires CSRF');
    check($request('POST','/api/v1/context',['role'=>'parent'],true,true)['status']===200,'Explicit parent workflow context accepted');
    $r=$request('GET','/practice-prototype?view=parent');
    check($r['status']===200 && str_contains($r['body'],'<h1>گزارش تمرین والدین</h1>'),'Parent workflow renders report');
    check(str_contains($r['body'],'پاسخ صحیح پس از کمک یا تلاش مجدد: 1'),'Parent report reflects assisted answer');
    $config['practice_allow_prototype_content']=false;$writeConfig();
    $r=$request('GET','/practice-prototype');
    check($r['status']===200 && !str_contains($r['body'],'name="token"'),'Release gate hides unfinished unapproved content');
    check(!$state(),'Unapproved active session pauses without deleting evidence');
    $config['practice_prototype_enabled']=false;$writeConfig();
    check($request('GET','/practice-prototype')['status']===404,'Disabled feature GET returns 404');
    check($request('POST','/practice-prototype',$start)['status']===404,'Disabled feature valid-CSRF POST returns 404');
    check(!$state(),'Disabled feature does not restart paused practice');
    foreach ($checks as $label) fwrite(STDOUT,'PASS '.$label.PHP_EOL);
    fwrite(STDOUT,'PASS '.count($checks).' isolated Application HTTP checks'.PHP_EOL.'Fixture retained: '.$root.PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL after '.count($checks).' checks: '.$e->getMessage().PHP_EOL.'Fixture/logs: '.$root.PHP_EOL);
    exit(1);
}
