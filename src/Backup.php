<?php
declare(strict_types=1);
namespace Kashf;
use PDO;
use RuntimeException;

final class Backup
{
    public const V1_TABLES=['schema_versions','learners','subjects','chapters','topics','resources','resource_topics','activities','assignments','extra_studies','challenges'];
    public const TABLES=[...self::V1_TABLES,'schedule_entries','schedule_exceptions'];
    public function __construct(private PDO $db,private string $directory){}
    public function daily(): void
    {
        $path=$this->directory.'/kashf-'.Calendar::today().'.json.gz';if(is_file($path))return;
        $lock=fopen($this->directory.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return;
        try{if(is_file($path))return;$data=['format'=>'kashf-backup-v2','created_at'=>gmdate('c'),'tables'=>[]];$this->db->beginTransaction();try{foreach(self::TABLES as $t)$data['tables'][$t]=$this->db->query('SELECT * FROM '.$t)->fetchAll();$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$tmp=$path.'.tmp';if(file_put_contents($tmp,gzencode($json,9),LOCK_EX)===false)throw new RuntimeException('Backup write failed');chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('Backup rename failed');$files=glob($this->directory.'/kashf-*.json.gz');rsort($files);foreach(array_slice($files,7)as$f)unlink($f);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function files():array{$files=glob($this->directory.'/kashf-*.json.gz');rsort($files);return array_map(fn($f)=>['name'=>basename($f),'bytes'=>filesize($f),'date'=>substr(basename($f),6,10)],$files);}
    public function path(string $name):string{if(!preg_match('/^kashf-\d{4}-\d{2}-\d{2}\.json\.gz$/',$name)||!is_file($this->directory.'/'.$name))throw new RuntimeException('پشتیبان پیدا نشد.');return$this->directory.'/'.$name;}
    public static function restore(PDO $db,string $path): void
    {
        $content=gzdecode((string)file_get_contents($path));if($content===false)throw new RuntimeException('Invalid gzip backup');$data=json_decode($content,true,512,JSON_THROW_ON_ERROR);$v1=($data['format']??'')==='kashf-backup-v1';if(!in_array($data['format']??'',['kashf-backup-v1','kashf-backup-v2'],true)||array_keys($data['tables']??[])!==($v1?self::V1_TABLES:self::TABLES))throw new RuntimeException('Invalid backup format');
        if($v1){$data['tables']['schedule_entries']=[];$data['tables']['schedule_exceptions']=[];$data['tables']['schema_versions']=array_values(array_filter($data['tables']['schema_versions'],fn($r)=>(int)$r['version']!==2));$data['tables']['schema_versions'][]=['version'=>2,'installed_at'=>gmdate('c')];}
        $db->beginTransaction();try{foreach(array_reverse(self::TABLES)as$t)$db->exec('DELETE FROM '.$t);foreach(self::TABLES as$t)foreach($data['tables'][$t]as$row)Database::insert($db,$t,$row);$db->commit();}catch(\Throwable $e){$db->rollBack();throw$e;}
    }
}
