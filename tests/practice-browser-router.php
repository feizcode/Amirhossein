<?php
/** Disposable browser fixture/router. Never loads repository config/local.php. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Kashf\{Application,Database,Practice};
$source=dirname(__DIR__);
$root=getenv('PRACTICE_BROWSER_ROOT') ?: '';
$approved='C:/Users/afeyz/AppData/Local/Temp/opencode/';
if (!str_starts_with(str_replace('\\','/',$root),$approved.'practice-browser-')) throw new RuntimeException('Unsafe fixture root');
if (PHP_SAPI==='cli') {
    if (($argv[1] ?? '')==='setup') {
        $copy=function(string $from,string $to) use (&$copy): void {
            if (!is_dir($to)) mkdir($to,0700,true);
            foreach (new DirectoryIterator($from) as $entry) {
                if ($entry->isDot()) continue;
                $dest=$to.'/'.$entry->getFilename();
                if ($entry->isDir()) $copy($entry->getPathname(),$dest);
                elseif (!copy($entry->getPathname(),$dest)) throw new RuntimeException('Copy failed');
            }
        };
        mkdir($root.'/config',0700,true);
        $copy($source.'/templates',$root.'/templates');
        $copy($source.'/database',$root.'/database');
        mkdir($root.'/resources',0700,true);
        $manifest=is_file($source.'/resources/manifest.json') ? $source.'/resources/manifest.json' : $source.'/public/assets/build/.vite/manifest.json';
        if (!copy($manifest,$root.'/resources/manifest.json')) throw new RuntimeException('Build manifest missing');
        $config=['environment'=>'development','password_hash'=>password_hash('isolated-practice-password',PASSWORD_DEFAULT),'app_key'=>bin2hex(random_bytes(32)),'secure_cookies'=>false,'session_days'=>7,'practice_prototype_enabled'=>true,'practice_allow_prototype_content'=>true,'database'=>['dsn'=>'sqlite:'.$root.'/practice.sqlite']];
        file_put_contents($root.'/config/local.php',"<?php\nreturn ".var_export($config,true).";\n");
        Database::install(Database::connect($config['database']),json_decode(file_get_contents($root.'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR));
        echo json_encode(['root'=>$root]);
    } elseif (($argv[1] ?? '')==='state') {
        echo json_encode((new Practice(Database::connect(['dsn'=>'sqlite:'.$root.'/practice.sqlite'])))->latest(),JSON_THROW_ON_ERROR);
    }
    exit;
}
// PHP's document root is the disposable directory. Explicitly serve only public assets,
// rejecting traversal, symlinks outside public, and all executable PHP files.
$public=realpath($source.'/public');
$url=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
$file=realpath($public.'/'.$url);
if ($file && str_starts_with($file,$public.DIRECTORY_SEPARATOR) && is_file($file) && !in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['php','phtml','phar'],true)) {
    $types=['css'=>'text/css','js'=>'text/javascript','svg'=>'image/svg+xml','woff2'=>'font/woff2','png'=>'image/png','ico'=>'image/x-icon'];
    header('Content-Type: '.($types[strtolower(pathinfo($file,PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);return;
}
Application::build($root)->run();
