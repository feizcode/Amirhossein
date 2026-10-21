<?php
// An offline HTTP adapter for local integration tests; never included in deployment.
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
$root=dirname(__DIR__);$config=require $root.'/config/local.php';
if(PHP_SAPI!=='cli'||$config['environment']!=='development')exit(1);
while(($line=fgets(STDIN))!==false){
 try{
  $d=json_decode($line,true,512,JSON_THROW_ON_ERROR);$_SERVER['REMOTE_ADDR']='127.0.0.1';
  $cookie=$d['cookie']??'';preg_match('/(?:^|;\s*)kashf_session=([a-zA-Z0-9,-]+)/',$cookie,$m);session_id($m[1]??'');$_SESSION=[];
  $q=(new ServerRequestFactory)->createServerRequest($d['method'],'http://kashf.test'.$d['url']);
  foreach($d['headers']??[]as$k=>$v)$q=$q->withHeader($k,$v);
  $q=$q->withBody((new StreamFactory)->createStream($d['body']??''));
  parse_str(parse_url($d['url'],PHP_URL_QUERY)??'', $query);$q=$q->withQueryParams($query);
  $app=Kashf\Application::build($root);$r=$app->handle($q);$headers=[];foreach($r->getHeaders()as$k=>$v)$headers[$k]=implode(', ',$v);
  $sid=session_id();$headers['Set-Cookie']='kashf_session='.$sid.'; Path=/; HttpOnly; SameSite=Lax';session_write_close();
  fwrite(STDOUT,json_encode(['status'=>$r->getStatusCode(),'headers'=>$headers,'body_base64'=>base64_encode((string)$r->getBody())],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
 }catch(Throwable$e){fwrite(STDOUT,json_encode(['status'=>500,'headers'=>[],'body'=>'Integration adapter error: '.$e->getMessage()])."\n");}
}
