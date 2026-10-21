<?php
declare(strict_types=1);
namespace Kashf;
use PDO;
use InvalidArgumentException;

/** Tehran civil dates and minute-of-day values; never changes learning records. */
final class Schedule
{
    public function __construct(private PDO $db) {}
    public static function schema(bool $mysql): array
    {
        $id=$mysql?'INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $int=$mysql?'INTEGER UNSIGNED':'INTEGER';
        $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $fields="title VARCHAR(190) NOT NULL, subject_id VARCHAR(32) NULL, kind VARCHAR(20) NOT NULL, start_minute INTEGER NOT NULL, end_minute INTEGER NOT NULL";
        return ["CREATE TABLE IF NOT EXISTS schedule_entries (id $id, learner_id $int NOT NULL DEFAULT 1, weekday INTEGER NOT NULL, $fields, effective_from DATE NOT NULL, effective_until DATE NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id), FOREIGN KEY(subject_id) REFERENCES subjects(id))$tail;",
            "CREATE TABLE IF NOT EXISTS schedule_exceptions (id $id, learner_id $int NOT NULL DEFAULT 1, entry_id $int NULL, original_date DATE NOT NULL, occurrence_date DATE NOT NULL, action VARCHAR(12) NOT NULL, $fields, created_at VARCHAR(30) NOT NULL, UNIQUE(entry_id,original_date), FOREIGN KEY(entry_id) REFERENCES schedule_entries(id), FOREIGN KEY(learner_id) REFERENCES learners(id), FOREIGN KEY(subject_id) REFERENCES subjects(id))$tail;"];
    }
    public static function migrate(PDO $db): void
    {
        foreach(self::schema($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') as $sql)$db->exec($sql);
        if(!$db->query('SELECT COUNT(*) FROM schema_versions WHERE version=2')->fetchColumn())Database::insert($db,'schema_versions',['version'=>2,'installed_at'=>gmdate('c')]);
    }
    private static function shift(string $date,int $days): string {return (new \DateTimeImmutable($date))->modify("$days days")->format('Y-m-d');}
    private static function weekday(string $date): int {return ((int)(new \DateTimeImmutable($date))->format('w')+1)%7;}
    private function rows(string $table): array {return $this->db->query("SELECT * FROM $table WHERE learner_id=1 ORDER BY id")->fetchAll();}
    public function occurrences(string $from,string $to): array
    {
        $from=Calendar::parse($from,true);$to=Calendar::parse($to,true);
        if($to<$from||$to>self::shift($from,366))throw new InvalidArgumentException('بازهٔ برنامه حداکثر ۳۶۷ روز است.');
        return $this->expand($from,$to,$this->rows('schedule_entries'),$this->rows('schedule_exceptions'));
    }
    private function expand(string $from,string $to,array $entries,array $exceptions): array
    {
        $skip=[];$out=[];
        foreach($exceptions as $e){if($e['entry_id'])$skip[$e['entry_id'].'/'.$e['original_date']]=true;
            if($e['action']!=='cancel'&&$e['occurrence_date']>=$from&&$e['occurrence_date']<=$to)$out[]=array_merge($e,['start_minute'=>(int)$e['start_minute'],'end_minute'=>(int)$e['end_minute'],'date'=>$e['occurrence_date'],'exception_id'=>(int)$e['id'],'key'=>'x'.$e['id']]);}
        for($d=$from;$d<=$to;$d=self::shift($d,1))foreach($entries as $e){if((int)$e['weekday']===self::weekday($d)&&$d>=$e['effective_from']&&(!$e['effective_until']||$d<=$e['effective_until'])&&!isset($skip[$e['id'].'/'.$d]))$out[]=array_merge($e,['start_minute'=>(int)$e['start_minute'],'end_minute'=>(int)$e['end_minute'],'weekday'=>(int)$e['weekday'],'date'=>$d,'entry_id'=>(int)$e['id'],'original_date'=>$d,'exception_id'=>null,'key'=>'e'.$e['id'].'/'.$d]);}
        usort($out,fn($a,$b)=>[$a['date'],(int)$a['start_minute'],$a['key']]<=>[$b['date'],(int)$b['start_minute'],$b['key']]);return $out;
    }
    private function fields(array $d): array
    {
        $title=trim((string)($d['title']??''));$kind=$d['kind']??'';$subject=($d['subject_id']??null)?:null;
        if($title===''||mb_strlen($title)>190||!in_array($kind,['class','homework','rest','personal','meeting'],true))throw new InvalidArgumentException('عنوان و نوع برنامه معتبر نیست.');
        foreach(['start_minute','end_minute'] as $f)if(filter_var($d[$f]??null,FILTER_VALIDATE_INT)===false)throw new InvalidArgumentException('ساعت معتبر نیست.');
        $start=(int)$d['start_minute'];$end=(int)$d['end_minute'];if($start<0||$end>1440||$end<=$start)throw new InvalidArgumentException('پایان باید بعد از شروع و در همین روز باشد.');
        if($subject!==null){$q=$this->db->prepare('SELECT id FROM subjects WHERE id=?');$q->execute([$subject]);if(!$q->fetch())throw new InvalidArgumentException('درس معتبر نیست.');}
        return ['title'=>$title,'subject_id'=>$subject,'kind'=>$kind,'start_minute'=>$start,'end_minute'=>$end];
    }
    private function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();try{
            // Serialize all schedule writers for this learner, including collision checks.
            $this->db->exec('UPDATE learners SET name=name WHERE id=1');
            $value=$fn();$this->validateCollisions();$this->db->commit();return $value;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function validateCollisions(): void
    {
        $entries=$this->rows('schedule_entries');$exceptions=$this->rows('schedule_exceptions');$dates=[];
        // Recurring intersections need only seven days after each interval boundary.
        foreach($entries as $e){for($i=0;$i<7;$i++)$dates[self::shift($e['effective_from'],$i)]=true;}
        foreach($exceptions as $e){$dates[$e['original_date']]=true;$dates[$e['occurrence_date']]=true;}
        // Also inspect the first unexceptional week after every exception (finite overrides).
        foreach($exceptions as $e)for($i=1;$i<=7;$i++)$dates[self::shift($e['original_date'],$i)]=true;
        foreach(array_keys($dates) as $d){$list=$this->expand($d,$d,$entries,$exceptions);$end=-1;foreach($list as $e){if((int)$e['start_minute']<$end)throw new InvalidArgumentException('این بازه با برنامهٔ دیگری در '.Calendar::jalali($d).' تداخل دارد.');$end=(int)$e['end_minute'];}}
    }
    public function entry(array $d,?int $id=null): int
    {
        $fields=$this->fields($d);$from=Calendar::parse((string)($d['effective_from']??''),true);$until=empty($d['effective_until'])?null:Calendar::parse((string)$d['effective_until'],true);
        $weekday=filter_var($d['weekday']??null,FILTER_VALIDATE_INT);if($weekday===false||$weekday<0||$weekday>6||($until&&$until<$from))throw new InvalidArgumentException('روز یا بازهٔ اثر معتبر نیست.');
        return $this->transaction(function()use($fields,$from,$until,$weekday,$id){
            if($id){$old=$this->findEntry($id);if($from<=$old['effective_from']||($old['effective_until']&&$from>$old['effective_until']))throw new InvalidArgumentException('تاریخ اثر باید پس از شروع نسخهٔ قبلی و در بازهٔ آن باشد.');
                $q=$this->db->prepare('SELECT COUNT(*) FROM schedule_exceptions WHERE entry_id=? AND original_date>=?');$q->execute([$id,$from]);if($q->fetchColumn())throw new InvalidArgumentException('ابتدا استثناهای آیندهٔ این الگو را بازگردانید.');
                $this->db->prepare('UPDATE schedule_entries SET effective_until=? WHERE id=?')->execute([self::shift($from,-1),$id]);}
            return Database::insert($this->db,'schedule_entries',$fields+['learner_id'=>1,'weekday'=>$weekday,'effective_from'=>$from,'effective_until'=>$until,'created_at'=>gmdate('c')]);
        });
    }
    private function findEntry(int $id): array {$q=$this->db->prepare('SELECT * FROM schedule_entries WHERE id=? AND learner_id=1');$q->execute([$id]);$e=$q->fetch();if(!$e)throw new InvalidArgumentException('الگو پیدا نشد.');return $e;}
    public function archive(int $id,string $from): void
    {
        $from=Calendar::parse($from,true);$this->transaction(function()use($id,$from){$e=$this->findEntry($id);if($from<=$e['effective_from'])throw new InvalidArgumentException('تاریخ پایان باید پس از شروع الگو باشد.');$q=$this->db->prepare('SELECT COUNT(*) FROM schedule_exceptions WHERE entry_id=? AND original_date>=?');$q->execute([$id,$from]);if($q->fetchColumn())throw new InvalidArgumentException('ابتدا استثناهای آینده را بازگردانید.');$this->db->prepare('UPDATE schedule_entries SET effective_until=? WHERE id=?')->execute([self::shift($from,-1),$id]);});
    }
    public function exception(array $d,?int $id=null): int
    {
        $action=$d['action']??'';if(!in_array($action,['add','replace','cancel'],true))throw new InvalidArgumentException('نوع استثنا معتبر نیست.');
        $original=Calendar::parse((string)($d['original_date']??''),true);$date=Calendar::parse((string)($d['occurrence_date']??$original),true);
        return $this->transaction(function()use($d,$id,$action,$original,$date){
            $entry=$action==='add'?null:(int)($d['entry_id']??0);$fields=$d;
            if($entry){$e=$this->findEntry($entry);if(self::weekday($original)!==(int)$e['weekday']||$original<$e['effective_from']||($e['effective_until']&&$original>$e['effective_until']))throw new InvalidArgumentException('نوبت اصلی در این تاریخ وجود ندارد.');if($action==='cancel')$fields=$e;}
            elseif($action!=='add')throw new InvalidArgumentException('الگوی اصلی لازم است.');
            $row=$this->fields($fields)+['learner_id'=>1,'entry_id'=>$entry,'original_date'=>$original,'occurrence_date'=>$date,'action'=>$action,'created_at'=>gmdate('c')];
            if($id){$q=$this->db->prepare('DELETE FROM schedule_exceptions WHERE id=? AND learner_id=1');$q->execute([$id]);if(!$q->rowCount())throw new InvalidArgumentException('استثنا پیدا نشد.');}
            if($entry){$q=$this->db->prepare('SELECT id FROM schedule_exceptions WHERE entry_id=? AND original_date=?');$q->execute([$entry,$original]);if($q->fetch())throw new InvalidArgumentException('این نوبت قبلاً استثنا دارد؛ همان استثنا را ویرایش کنید.');}
            return Database::insert($this->db,'schedule_exceptions',$row);
        });
    }
    public function removeException(int $id): void {$this->transaction(function()use($id){$this->db->prepare('DELETE FROM schedule_exceptions WHERE id=? AND learner_id=1')->execute([$id]);});}
    public function confirmDraft(array $draft,string $from): void
    {
        $from=Calendar::parse($from,true);
        $this->transaction(function()use($draft,$from){if($this->rows('schedule_entries'))throw new InvalidArgumentException('پیش‌نویس فقط برای برنامهٔ خالی قابل ورود است.');
            foreach($draft['weekly_entries'] as $e){$minute=static function(string $t):int{[$h,$m]=array_map('intval',explode(':',$t));return $h*60+$m;};$fields=$this->fields($e+['start_minute'=>$minute($e['start']),'end_minute'=>$minute($e['end'])]);Database::insert($this->db,'schedule_entries',$fields+['learner_id'=>1,'weekday'=>$e['weekday'],'effective_from'=>$from,'effective_until'=>null,'created_at'=>gmdate('c')]);}
        });
    }
    public function data(string $from,string $to): array {return ['occurrences'=>$this->occurrences($from,$to),'entries'=>$this->rows('schedule_entries'),'exceptions'=>$this->rows('schedule_exceptions')];}
    public function recommendations(string $date): array
    {
        $date=Calendar::parse($date,true);$tomorrow=self::shift($date,1);$todaySubjects=[];$next=null;
        foreach($this->occurrences($date,min('2100-01-01',self::shift($date,366))) as $e){if($e['kind']!=='class'||!$e['subject_id'])continue;if($e['date']===$date)$todaySubjects[]=$e['subject_id'];elseif(!$next||$next['date']===$e['date']){$next??=['date'=>$e['date'],'subjects'=>[]];$next['subjects'][]=$e['subject_id'];}}
        $q=$this->db->query('SELECT * FROM assignments WHERE learner_id=1 AND archived=0 AND completed_at IS NULL');$tasks=[];
        foreach($q->fetchAll() as $t){$due=$t['due_date'];$rank=$due<$date?0:($due===$date?1:($due===$tomorrow?2:(in_array($t['subject_id'],$next['subjects']??[],true)?3:(in_array($t['subject_id'],$todaySubjects,true)?4:5))));
            $reason=match($rank){0=>'موعد این تکلیف گذشته است.',1=>'موعد این تکلیف امروز است.',2=>'موعد این تکلیف فرداست.',3=>'کلاس بعدی این درس در '.Calendar::jalali($next['date']).' است.',4=>'امروز کلاس این درس را داری.',default=>'تکلیف باز با موعد '.Calendar::jalali($due)};$tasks[]=$t+['rank'=>$rank,'reason'=>$reason];}
        usort($tasks,fn($a,$b)=>[$a['rank'],$a['due_date'],(int)$a['id']]<=>[$b['rank'],$b['due_date'],(int)$b['id']]);return ['tasks'=>$tasks,'next'=>$next,'today_subjects'=>array_values(array_unique($todaySubjects)),'optional_review'=>!$tasks];
    }
}
