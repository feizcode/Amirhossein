<?php
declare(strict_types=1);
require __DIR__.'/run.php';
use Kashf\{Schedule,Database,Backup,Calendar};
$db=Database::connect(['dsn'=>'sqlite::memory:']);Database::install($db,$catalog);$s=new Schedule($db);
$entry=['title'=>'ریاضی','kind'=>'class','subject_id'=>'math','weekday'=>0,'start_minute'=>480,'end_minute'=>540,'effective_from'=>'2026-10-17'];
$id=$s->entry($entry);eq(count($s->occurrences('2026-10-17','2026-10-24')),2,'Weekly repeats');
rejects(fn()=>$s->entry($entry),'Collision rejected');rejects(fn()=>$s->entry(array_replace($entry,['end_minute'=>480])),'Bad time');
$x=$s->exception(['action'=>'replace','entry_id'=>$id,'original_date'=>'2026-10-17','occurrence_date'=>'2026-10-18']+$entry);
eq(count($s->occurrences('2026-10-17','2026-10-17')),0,'Moved original removed');eq(count($s->occurrences('2026-10-18','2026-10-18')),1,'Moved destination exists');eq(count($s->occurrences('2026-10-24','2026-10-24')),1,'Next week untouched');
$new=$s->entry(array_replace($entry,['effective_from'=>'2026-10-24','start_minute'=>600,'end_minute'=>660]),$id);eq((int)$s->occurrences('2026-10-18','2026-10-18')[0]['start_minute'],480,'History preserved');eq((int)$s->occurrences('2026-10-31','2026-10-31')[0]['start_minute'],600,'New version repeats');
$s->exception(['action'=>'cancel','entry_id'=>$new,'original_date'=>'2026-10-24']);eq($s->recommendations('2026-10-23')['next']['date'],'2026-10-31','Friday skips cancelled Saturday');
$l=new Kashf\Learning($db);$l->assignment(['subject'=>'science','title'=>'today','due'=>'2026-10-23']);$l->assignment(['subject'=>'math','title'=>'next','due'=>'2026-11-01']);eq($s->recommendations('2026-10-23')['tasks'][0]['title'],'today','Due today outranks next class');eq($s->recommendations('2026-10-23')['tasks'][1]['rank'],3,'Next class reason');
$dir=dirname(__DIR__).'/work/schedule-backup-'.bin2hex(random_bytes(4));mkdir($dir);$b=new Backup($db,$dir);$b->daily();$path=$b->path($b->files()[0]['name']);$saved=$s->data('2026-10-17','2026-10-31');Backup::restore($db,$path);eq($s->data('2026-10-17','2026-10-31'),$saved,'V2 roundtrip');
$v1=json_decode(gzdecode(file_get_contents($path)),true);$v1['format']='kashf-backup-v1';unset($v1['tables']['schedule_entries'],$v1['tables']['schedule_exceptions']);$v1['tables']['schema_versions']=array_values(array_filter($v1['tables']['schema_versions'],fn($r)=>(int)$r['version']!==2));file_put_contents($path,gzencode(json_encode($v1)));Backup::restore($db,$path);eq(count($s->data('2026-10-17','2026-10-31')['entries']),0,'V1 restores empty schedule');eq((int)$db->query('SELECT COUNT(*) FROM schema_versions WHERE version=2')->fetchColumn(),1,'V1 migration marker');
$s->confirmDraft(json_decode(file_get_contents(dirname(__DIR__).'/database/schedule-draft.json'),true),'2026-10-17');eq(count($s->data('2026-10-17','2026-10-23')['entries']),39,'Draft confirmation imports 39 entries');eq((int)$db->query('SELECT COUNT(*) FROM assignments')->fetchColumn(),2,'Seed creates no tasks');rejects(fn()=>$s->confirmDraft([],Calendar::today()),'Duplicate seed rejected');
foreach(glob($dir.'/*')as$f)unlink($f);unlink($dir.'/.lock');rmdir($dir);
echo "PASS $checks cumulative schedule/domain checks\n";
