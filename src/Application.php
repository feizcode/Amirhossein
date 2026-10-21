<?php
declare(strict_types=1);
namespace Kashf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as HttpResponse;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class Application
{
    public static function json(array $data,int $status=200):Response{$r=new HttpResponse($status);$r->getBody()->write(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return$r->withHeader('Content-Type','application/json; charset=utf-8');}
    public static function build(string $root):\Slim\App
    {
        date_default_timezone_set('UTC');$app=AppFactory::create();$loader=new FilesystemLoader($root.'/templates');$twig=new Environment($loader,['autoescape'=>'html','strict_variables'=>true]);
        $render=static function(string$template,array$data=[],int$status=200)use($twig):Response{$r=new HttpResponse($status);$r->getBody()->write($twig->render($template,$data));return$r->withHeader('Content-Type','text/html; charset=utf-8');};
        $file=$root.'/config/local.php';
        if(!is_file($file)){$app->any('/[{path:.*}]',fn()=>$render('unavailable.twig',[],503));return$app;}
        $config=require$file;
        if(empty($config['password_hash'])||!str_starts_with($config['password_hash'],'$')||strlen($config['app_key']??'')<32){$app->any('/[{path:.*}]',fn()=>$render('unavailable.twig',[],503));return$app;}
        foreach(['sessions','backups','logs','cache']as$dir)if(!is_dir($root.'/storage/'.$dir))mkdir($root.'/storage/'.$dir,0700,true);
        ini_set('display_errors','0');ini_set('log_errors','1');ini_set('error_log',$root.'/storage/logs/php.log');
        if(session_status()!==PHP_SESSION_ACTIVE){ini_set('session.use_strict_mode','1');ini_set('session.gc_maxlifetime',(string)(86400*(int)($config['session_days']??7)));session_save_path($root.'/storage/sessions');session_name('kashf_session');session_set_cookie_params(['lifetime'=>86400*(int)($config['session_days']??7),'path'=>'/','secure'=>(bool)$config['secure_cookies'],'httponly'=>true,'samesite'=>'Lax']);session_start();}
        $_SESSION['csrf']??=bin2hex(random_bytes(32));if(($_SESSION['expires_at']??0)<time())unset($_SESSION['unlocked']);
        try{
            $db=Database::connect($config['database']);
            try{
                $catalogData=json_decode((string)file_get_contents($root.'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR);
                Database::migrate($db,$catalogData);
            }catch(\Throwable$e){error_log('Database migration failed: '.$e->getMessage());}
        }catch(\Throwable$e){error_log('Database connection failed: '.$e->getMessage());$app->any('/[{path:.*}]',fn()=>$render('unavailable.twig',[],503));return$app;}
        $learning=new Learning($db);$backup=new Backup($db,$root.'/storage/backups');
        $assets=self::assets($root);
        $app->get('/login',fn()=>$render('login.twig',['csrf'=>$_SESSION['csrf'],'error'=>'','assets'=>$assets]));
        $app->post('/login',function(Request$q)use($db,$config,$render,$assets):Response{
            $d=(array)$q->getParsedBody();$key=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'local'),$config['app_key']);$s=$db->prepare('SELECT * FROM login_attempts WHERE ip_key=?');$s->execute([$key]);$a=$s->fetch();$blocked=$a&&((int)$a['first_at']>time()-900)&&(int)$a['attempts']>=5;
            if($blocked)return$render('login.twig',['csrf'=>$_SESSION['csrf'],'error'=>'تعداد تلاش‌ها زیاد شده؛ ۱۵ دقیقه بعد دوباره امتحان کن.','assets'=>$assets],429)->withHeader('Retry-After','900');
            $password=(string)($d['password']??'');
            if(strlen($password)>512||!password_verify($password,$config['password_hash'])){
                if($a&&((int)$a['first_at']>time()-900)){$s=$db->prepare('UPDATE login_attempts SET attempts=attempts+1 WHERE ip_key=?');$s->execute([$key]);}
                else{$db->prepare('DELETE FROM login_attempts WHERE ip_key=?')->execute([$key]);try{Database::insert($db,'login_attempts',['ip_key'=>$key,'attempts'=>1,'first_at'=>time()]);}catch(\PDOException$e){if((string)$e->getCode()!=='23000')throw$e;}}
                return$render('login.twig',['csrf'=>$_SESSION['csrf'],'error'=>'رمز درست نیست؛ دوباره امتحان کن.','assets'=>$assets],422);
            }
            $db->prepare('DELETE FROM login_attempts WHERE ip_key=?')->execute([$key]);$db->prepare('DELETE FROM login_attempts WHERE first_at<?')->execute([time()-86400]);session_regenerate_id(true);$_SESSION['unlocked']=true;$_SESSION['expires_at']=time()+86400*(int)($config['session_days']??7);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['role']='child';return(new HttpResponse(303))->withHeader('Location','/');
        });
        $app->post('/logout',function():Response{$_SESSION=[];session_destroy();setcookie('kashf_session','',['expires'=>time()-3600,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);return(new HttpResponse(303))->withHeader('Location','/login');});
        $app->get('/',fn()=>$render('app.twig',['csrf'=>$_SESSION['csrf'],'assets'=>$assets]));
        $app->get('/api/v1/state',function()use($learning,$backup):Response{$s=$learning->state($_SESSION['role']??'child');try{$backup->daily();$s['backupWarning']=false;}catch(\Throwable$e){error_log('Backup failed: '.$e->getMessage());$s['backupWarning']=true;}return self::json($s);});
        $app->post('/api/v1/context',function(Request$q)use($learning):Response{$role=$q->getParsedBody()['role']??'';if(!in_array($role,['child','parent'],true))throw new \InvalidArgumentException('بخش انتخابی معتبر نیست.');$_SESSION['role']=$role;return self::json($learning->state($role));});
        $guard=static function(string$role):void{if(($_SESSION['role']??'child')!==$role)throw new AccessDenied('این کار از بخش مربوط به خودش انجام می‌شود.');};
        $schedule=new Schedule($db);
        $app->get('/api/v1/schedule',function(Request $q)use($schedule):Response{$p=$q->getQueryParams();return self::json($schedule->data((string)($p['from']??Calendar::today()),(string)($p['to']??Calendar::today())));});
        $app->get('/api/v1/recommendations',function(Request $q)use($schedule):Response{return self::json($schedule->recommendations((string)($q->getQueryParams()['date']??Calendar::today())));});
        $app->get('/api/v1/schedule-draft',function()use($root,$guard):Response{$guard('parent');return self::json(json_decode((string)file_get_contents($root.'/database/schedule-draft.json'),true,512,JSON_THROW_ON_ERROR));});
        $app->post('/api/v1/schedule-draft/confirm',function(Request $q)use($schedule,$root,$guard):Response{$guard('parent');$d=(array)$q->getParsedBody();if(($d['confirmed']??false)!==true)throw new \InvalidArgumentException('تأیید ساعت‌های تقریبی لازم است.');$schedule->confirmDraft(json_decode((string)file_get_contents($root.'/database/schedule-draft.json'),true,512,JSON_THROW_ON_ERROR),(string)($d['effective_from']??''));return self::json(['ok'=>true]);});
        foreach(['schedule-entries'=>'entry','schedule-exceptions'=>'exception'] as $path=>$method){
            $app->post('/api/v1/'.$path,function(Request $q)use($schedule,$guard,$method):Response{$guard('parent');return self::json(['id'=>$schedule->$method((array)$q->getParsedBody())],201);});
            $app->put('/api/v1/'.$path.'/{id:[0-9]+}',function(Request $q,Response $r,array $args)use($schedule,$guard,$method):Response{$guard('parent');return self::json(['id'=>$schedule->$method((array)$q->getParsedBody(),(int)$args['id'])]);});
        }
        $app->post('/api/v1/schedule-entries/{id:[0-9]+}/archive',function(Request $q,Response $r,array $args)use($schedule,$guard):Response{$guard('parent');$schedule->archive((int)$args['id'],(string)($q->getParsedBody()['effective_from']??''));return self::json(['ok'=>true]);});
        $app->delete('/api/v1/schedule-exceptions/{id:[0-9]+}',function(Request $q,Response $r,array $args)use($schedule,$guard):Response{$guard('parent');$schedule->removeException((int)$args['id']);return self::json(['ok'=>true]);});
        $routes=[['activities','child',fn($d,$id)=>$learning->activity($d,$id)],['assignments','parent',fn($d,$id)=>$learning->assignment($d,$id)],['extra-studies','child',fn($d,$id)=>$learning->record('extra',$d,$id)],['challenges','child',fn($d,$id)=>$learning->record('challenge',$d,$id)]];
        foreach($routes as[$path,$role,$action]){
            $app->post('/api/v1/'.$path,function(Request$q)use($action,$guard,$role):Response{$guard($role);return self::json(['id'=>$action((array)$q->getParsedBody(),null)],201);});
            $app->put('/api/v1/'.$path.'/{id:[0-9]+}',function(Request$q,Response$r,array$args)use($action,$guard,$role):Response{$guard($role);return self::json(['id'=>$action((array)$q->getParsedBody(),(int)$args['id'])]);});
        }
        $app->delete('/api/v1/activities/{id:[0-9]+}',function(Request$q,Response$r,array$args)use($learning):Response{$learning->deleteActivity((int)$args['id']);return self::json(['ok'=>true]);});
        $app->delete('/api/v1/assignments/{id:[0-9]+}',function(Request$q,Response$r,array$args)use($learning,$guard):Response{$guard('parent');$learning->deleteAssignment((int)$args['id']);return self::json(['ok'=>true]);});
        $app->delete('/api/v1/challenges/{id:[0-9]+}',function(Request$q,Response$r,array$args)use($learning):Response{$learning->deleteChallenge((int)$args['id']);return self::json(['ok'=>true]);});
        $app->post('/api/v1/assignments/{id:[0-9]+}/complete',function(Request$q,Response$r,array$args)use($learning,$guard):Response{$guard('child');$learning->complete((int)$args['id'],!empty($q->getParsedBody()['complete']));return self::json(['ok'=>true]);});
        $app->post('/api/v1/resources',function(Request$q)use($learning,$guard):Response{$guard('parent');return self::json(['id'=>$learning->resource((array)$q->getParsedBody())],201);});
        $app->put('/api/v1/resources/{id:[0-9]+}',function(Request$q,Response$r,array$args)use($learning,$guard):Response{$guard('parent');$learning->updateResource((int)$args['id'],(array)$q->getParsedBody());return self::json(['ok'=>true]);});
        $app->post('/api/v1/resources/{id:[0-9]+}/archive',function(Request$q,Response$r,array$args)use($learning,$guard):Response{$guard('parent');$learning->archive((int)$args['id']);return self::json(['ok'=>true]);});
        $app->get('/api/v1/reports',function(Request$q)use($learning,$guard):Response{$guard('parent');return self::json($learning->report((string)($q->getQueryParams()['period']??'current_week')));});
        $app->get('/api/v1/backups',function()use($guard,$backup):Response{$guard('parent');$backup->daily();return self::json(['files'=>$backup->files()]);});
        $app->get('/api/v1/backups/{name}',function(Request$q,Response$r,array$args)use($guard,$backup):Response{$guard('parent');$path=$backup->path($args['name']);$r->getBody()->write((string)file_get_contents($path));return$r->withHeader('Content-Type','application/gzip')->withHeader('Content-Disposition','attachment; filename="'.basename($path).'"');});
        $app->addRoutingMiddleware();
        $app->add(function(Request$q,Handler$handler):Response{
            $path=$q->getUri()->getPath();$api=str_starts_with($path,'/api/');$login=$path==='/login';
            if(!$login&&empty($_SESSION['unlocked']))return$api?self::json(['error'=>'برای ادامه وارد شوید.'],401):(new HttpResponse(303))->withHeader('Location','/login');
            if(!in_array($q->getMethod(),['GET','HEAD'],true)){$d=(array)$q->getParsedBody();$csrf=$q->getHeaderLine('X-CSRF-Token')?:($d['_csrf']??'');if(!is_string($csrf)||!hash_equals($_SESSION['csrf'],$csrf))return self::json(['error'=>'نشست تازه شده؛ صفحه را دوباره باز کن.'],419);}
            try{$r=$handler->handle($q);}catch(AccessDenied$e){$r=self::json(['error'=>$e->getMessage()],403);}catch(\InvalidArgumentException$e){$r=self::json(['error'=>$e->getMessage()],422);}
            return$r;
        });
        // Parsing runs before the authentication/CSRF middleware.
        $app->addBodyParsingMiddleware();
        $errors=$app->addErrorMiddleware(false,true,true);$errors->setDefaultErrorHandler(function(Request$q,\Throwable$e,bool$display,bool$log,bool$details)use($render):Response{$status=$e instanceof \Slim\Exception\HttpException?(int)$e->getCode():500;if($status>=500)error_log((string)$e);return str_starts_with($q->getUri()->getPath(),'/api/')?self::json(['error'=>$status===404?'مسیر پیدا نشد.':'ذخیره انجام نشد؛ کمی بعد دوباره امتحان کن.'],$status):$render('unavailable.twig',[],$status);});$app->add(function(Request$q,Handler$handler):Response{$r=$handler->handle($q);return$r->withHeader('Cache-Control','no-store, private')->withHeader('X-Content-Type-Options','nosniff')->withHeader('Referrer-Policy','same-origin')->withHeader('X-Frame-Options','DENY')->withHeader('X-Robots-Tag','noindex, nofollow')->withHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");});return$app;
    }
    private static function assets(string$root):array{$path=is_file($root.'/resources/manifest.json')?$root.'/resources/manifest.json':$root.'/public/assets/build/.vite/manifest.json';$manifest=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$entry=$manifest['resources/app.js'];return['js'=>'/assets/build/'.$entry['file'],'css'=>array_map(fn($s)=>'/assets/build/'.$s,$entry['css']??[])];}
}
final class AccessDenied extends \RuntimeException{}
