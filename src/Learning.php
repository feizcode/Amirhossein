<?php
declare(strict_types=1);
namespace Kashf;
use PDO;
use InvalidArgumentException;

final class Learning
{
    public function __construct(private PDO $db){}
    private function rows(string $sql,array $args=[]):array{$s=$this->db->prepare($sql);$s->execute($args);return$s->fetchAll();}
    private function one(string $sql,array $args=[]):array{$rows=$this->rows($sql,$args);if(!$rows)throw new InvalidArgumentException('این مورد پیدا نشد.');return$rows[0];}
    public function state(string $role):array
    {
        $subjects=$this->rows('SELECT * FROM subjects ORDER BY position');$catalog=[];
        foreach($subjects as &$s){$gs=$this->rows('SELECT id,name FROM chapters WHERE subject_id=? ORDER BY position',[$s['id']]);foreach($gs as &$g)$g['topics']=$this->rows('SELECT id,name FROM topics WHERE chapter_id=? ORDER BY position',[$g['id']]);unset($g);$catalog[$s['id']]=$gs;$s['total']=array_sum(array_map(fn($g)=>count($g['topics']),$gs));$s['done']=[0,0,0];}unset($s);
        $resources=$this->rows('SELECT id,subject_id AS subject,resource_key AS id_key,name,archived FROM resources ORDER BY id');
        foreach($resources as &$r){$rid=(int)$r['id'];$r['id']=$r['id_key'];unset($r['id_key']);$r['database_id']=$rid;$r['archived']=(bool)$r['archived'];$r['topics']=$this->rows('SELECT topic_key AS id,title AS name FROM resource_topics WHERE resource_id=? ORDER BY position',[$rid]);}unset($r);
        $logs=$this->rows('SELECT a.id,r.subject_id AS subject,r.resource_key AS source,rt.topic_key AS topic_id,rt.title AS topic,a.note,a.kind,a.completed,a.activity_date AS date FROM activities a JOIN resource_topics rt ON rt.id=a.resource_topic_id JOIN resources r ON r.id=rt.resource_id WHERE a.deleted_at IS NULL ORDER BY a.activity_date DESC,a.id DESC');
        $completions=[];$seen=[];foreach($logs as &$l){$l['id']=(string)$l['id'];$l['completed']=(bool)$l['completed'];$key=$l['subject'].'|'.$l['source'].'|'.$l['topic_id'];if($l['completed']&&!isset($seen[$key])){$completions[]=['subject'=>$l['subject'],'source'=>$l['source'],'topic'=>$l['topic_id']];$seen[$key]=true;}}unset($l);
        $tasks=$this->rows('SELECT id,subject_id AS subject,title,description,due_date AS due,completed_at FROM assignments WHERE archived=0 ORDER BY due_date,id');foreach($tasks as &$t){$t['id']=(string)$t['id'];$t['done']=$t['completed_at']!==null;}unset($t);
        $extras=$this->rows('SELECT id,subject_id AS subject,title,report,reference_text AS reference,entry_date AS date FROM extra_studies ORDER BY entry_date DESC,id DESC');
        $challenges=$this->rows("SELECT id,COALESCE(subject_id,'general') AS subject,title,report,entry_date AS date FROM challenges ORDER BY entry_date DESC,id DESC");foreach($extras as &$x)$x['id']=(string)$x['id'];unset($x);foreach($challenges as &$x)$x['id']=(string)$x['id'];unset($x);
        return ['version'=>1,'role'=>$role,'page'=>$role==='child'?'diary':'overview','subject'=>'math','source'=>'school','period'=>'current_week','today'=>Calendar::today(),'catalogs'=>$catalog,'subjects'=>$subjects,'resources'=>$resources,'completions'=>$completions,'customSources'=>array_values(array_filter($resources,fn($r)=>!in_array($r['id'],['school','khs','ai'],true))),'logs'=>$logs,'tasks'=>$tasks,'extras'=>$extras,'challenges'=>$challenges,'ranges'=>['current_week'=>Calendar::range('current_week'),'week'=>Calendar::range('week'),'month'=>Calendar::range('month')]];
    }
    private function text(array $d,string $key,int $max,bool $required=true):string
    {
        if(isset($d[$key])&&!is_string($d[$key]))throw new InvalidArgumentException('مقدار متنی معتبر نیست.');$v=trim((string)($d[$key]??''));if(($required&&$v==='')||mb_strlen($v)>$max)throw new InvalidArgumentException('لطفاً '.$this->label($key).' را درست و کوتاه‌تر وارد کن.');return$v;
    }
    private function label(string $key):string{return['title'=>'عنوان','report'=>'گزارش','note'=>'یادداشت','name'=>'نام منبع','description'=>'توضیح','reference'=>'منبع مطالعه'][$key]??$key;}
    private function subject(array $d,bool $general=false):?string{$s=(string)($d['subject']??'');if($general&&$s==='general')return null;$this->one('SELECT id FROM subjects WHERE id=?',[$s]);return$s;}
    private function update(string $table,int $id,array $row):void{$this->one('SELECT id FROM '.$table.' WHERE id=?',[$id]);$sql='UPDATE '.$table.' SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($row))).' WHERE id=?';$this->db->prepare($sql)->execute([...array_values($row),$id]);}
    public function activity(array $d,?int $id=null):int
    {
        $s=$this->subject($d);$source=(string)($d['source']??'');$topic=(string)($d['topic']??'');
        $rt=$this->one('SELECT rt.id,r.archived FROM resource_topics rt JOIN resources r ON r.id=rt.resource_id WHERE r.subject_id=? AND r.resource_key=? AND rt.topic_key=?',[$s,$source,$topic]);
        if($rt['archived'])throw new InvalidArgumentException('این منبع بایگانی شده است.');
        $kind=(string)($d['kind']??'practice');if(!in_array($kind,['practice','reading','review'],true))throw new InvalidArgumentException('نوع فعالیت معتبر نیست.');
        $row=['resource_topic_id'=>(int)$rt['id'],'activity_date'=>Calendar::parse((string)($d['date']??'')),'kind'=>$kind,'note'=>$this->text($d,'note',1600,false),'completed'=>!empty($d['complete'])?1:0,'updated_at'=>gmdate('c')];
        $taskId=isset($d['task'])&&$d['task']!==''?(int)$d['task']:null;
        $this->db->beginTransaction();try{if($taskId){$task=$this->one('SELECT subject_id FROM assignments WHERE id=? AND archived=0',[$taskId]);if($task['subject_id']!==$s)throw new InvalidArgumentException('درس تکلیف با فعالیت یکسان نیست.');if(!empty($d['complete']))$this->db->prepare('UPDATE assignments SET completed_at=?,updated_at=? WHERE id=?')->execute([gmdate('c'),gmdate('c'),$taskId]);$row['completed']=0;}
            if($id){$this->one('SELECT id FROM activities WHERE id=? AND deleted_at IS NULL',[$id]);$this->update('activities',$id,$row);}else{$id=Database::insert($this->db,'activities',$row+['created_at'=>gmdate('c')]);}$this->db->commit();return$id;
        }catch(\Throwable $e){$this->db->rollBack();throw$e;}
    }
    public function deleteActivity(int $id):void
    {
        $this->one('SELECT id FROM activities WHERE id=? AND deleted_at IS NULL', [$id]);
        $this->update('activities', $id, ['deleted_at'=>gmdate('c'), 'updated_at'=>gmdate('c')]);
    }
    public function assignment(array $d,?int $id=null):int
    {
        $row=['subject_id'=>$this->subject($d),'title'=>$this->text($d,'title',190),'description'=>$this->text($d,'description',1600,false),'due_date'=>Calendar::parse((string)($d['due']??''),true),'updated_at'=>gmdate('c')];if($id){$this->update('assignments',$id,$row);return$id;}return Database::insert($this->db,'assignments',$row+['created_at'=>gmdate('c')]);
    }
    public function deleteAssignment(int $id):void
    {
        $this->one('SELECT id FROM assignments WHERE id=? AND archived=0', [$id]);
        $this->update('assignments', $id, ['archived'=>1, 'updated_at'=>gmdate('c')]);
    }
    public function record(string $type,array $d,?int $id=null):int
    {
        $table=$type==='extra'?'extra_studies':'challenges';$original=$id?$this->one('SELECT entry_date FROM '.$table.' WHERE id=?',[$id]):null;$row=['subject_id'=>$this->subject($d,$type==='challenge'),'title'=>$this->text($d,'title',190),'report'=>$this->text($d,'report',4000),'entry_date'=>$original['entry_date']??Calendar::parse((string)($d['date']??'')),'updated_at'=>gmdate('c')];if($type==='extra')$row['reference_text']=$this->text($d,'reference',255,false);if($id){$this->update($table,$id,$row);return$id;}return Database::insert($this->db,$table,$row+['created_at'=>gmdate('c')]);
    }
    public function deleteChallenge(int $id):void
    {
        $this->one('SELECT id FROM challenges WHERE id=?', [$id]);
        $this->db->prepare('DELETE FROM challenges WHERE id=?')->execute([$id]);
    }
    public function resource(array $d):int
    {
        $s=$this->subject($d);$name=$this->text($d,'title',190);$existing=$this->rows('SELECT id FROM resources WHERE subject_id=? AND name=? AND archived=0',[$s,$name]);if($existing)throw new InvalidArgumentException('این منبع از قبل وجود دارد.');
        $shared=!empty($d['shared']);$lines=[];if(!$shared){$raw=$this->text($d,'topics',12000);$lines=array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/u',$raw)))));if(count($lines)>150)throw new InvalidArgumentException('حداکثر ۱۵۰ موضوع وارد کنید.');foreach($lines as$l)if(mb_strlen($l)>255)throw new InvalidArgumentException('عنوان موضوع بیش از اندازه بلند است.');}
        $this->db->beginTransaction();try{$rid=Database::insert($this->db,'resources',['subject_id'=>$s,'resource_key'=>'new-'.bin2hex(random_bytes(8)),'name'=>$name,'created_at'=>gmdate('c')]);$this->update('resources',$rid,['resource_key'=>'custom-'.$rid]);$ts=$shared?$this->rows('SELECT t.id,t.name FROM topics t JOIN chapters c ON c.id=t.chapter_id WHERE c.subject_id=? ORDER BY c.position,t.position',[$s]):array_map(fn($name,$i)=>['id'=>'custom-'.$rid.'-'.$i,'name'=>$name],$lines,array_keys($lines));foreach($ts as$i=>$t)Database::insert($this->db,'resource_topics',['resource_id'=>$rid,'topic_key'=>$t['id'],'title'=>$t['name'],'position'=>$i]);$this->db->commit();return$rid;}catch(\Throwable$e){$this->db->rollBack();throw$e;}
    }
    public function updateResource(int $id, array $d):void
    {
        $res=$this->one('SELECT id,subject_id,resource_key,name,archived FROM resources WHERE id=?', [$id]);
        if($res['archived'])throw new InvalidArgumentException('منبع بایگانی‌شده قابل ویرایش نیست.');
        $name=$this->text($d,'title',190);
        $dup=$this->rows('SELECT id FROM resources WHERE subject_id=? AND name=? AND id!=? AND archived=0',[$res['subject_id'],$name,$id]);
        if($dup)throw new InvalidArgumentException('منبع دیگری با این نام وجود دارد.');
        $this->db->beginTransaction();
        try{
            $this->update('resources',$id,['name'=>$name]);
            if(isset($d['topics'])&&is_string($d['topics'])){
                $raw=trim($d['topics']);
                if($raw!==''){
                    $lines=array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/u',$raw)))));
                    if(count($lines)>150)throw new InvalidArgumentException('حداکثر ۱۵۰ موضوع وارد کنید.');
                    foreach($lines as$l)if(mb_strlen($l)>255)throw new InvalidArgumentException('عنوان موضوع بیش از اندازه بلند است.');
                    $existingTopics=$this->rows('SELECT id,topic_key,title,position FROM resource_topics WHERE resource_id=? ORDER BY position,id',[$id]);
                    $common=min(count($existingTopics),count($lines));
                    for($i=0;$i<$common;$i++){
                        $this->db->prepare('UPDATE resource_topics SET title=?,position=? WHERE id=?')->execute([$lines[$i],$i,$existingTopics[$i]['id']]);
                    }
                    if(count($lines)>count($existingTopics)){
                        for($i=$common;$i<count($lines);$i++){
                            Database::insert($this->db,'resource_topics',['resource_id'=>$id,'topic_key'=>'custom-'.$id.'-'.bin2hex(random_bytes(4)),'title'=>$lines[$i],'position'=>$i]);
                        }
                    }elseif(count($lines)<count($existingTopics)){
                        for($i=count($lines);$i<count($existingTopics);$i++){
                            $tid=(int)$existingTopics[$i]['id'];
                            $hasAct=(int)$this->rows('SELECT COUNT(*) AS c FROM activities WHERE resource_topic_id=?',[$tid])[0]['c'];
                            if($hasAct===0){$this->db->prepare('DELETE FROM resource_topics WHERE id=?')->execute([$tid]);}
                        }
                    }
                }
            }
            $this->db->commit();
        }catch(\Throwable$e){$this->db->rollBack();throw$e;}
    }
    public function archive(int $id):void{$r=$this->one('SELECT subject_id FROM resources WHERE id=? AND archived=0',[$id]);$n=(int)$this->rows('SELECT COUNT(*) AS n FROM resources WHERE subject_id=? AND archived=0',[$r['subject_id']])[0]['n'];if($n<2)throw new InvalidArgumentException('حداقل یک منبع فعال برای هر درس لازم است.');$this->update('resources',$id,['archived'=>1]);}
    public function complete(int $id,bool $done):void{$this->update('assignments',$id,['completed_at'=>$done?gmdate('c'):null,'updated_at'=>gmdate('c')]);}
    public function report(string $period):array{$s=$this->state('parent');$r=Calendar::range($period);$in=fn($x)=>$x['date']>=$r['start']&&$x['date']<=$r['end'];return['range'=>$r,'logs'=>array_values(array_filter($s['logs'],$in)),'extras'=>array_values(array_filter($s['extras'],$in)),'challenges'=>array_values(array_filter($s['challenges'],$in)),'assignments'=>array_values(array_filter($s['tasks'],fn($t)=>$t['completed_at']&&substr((new \DateTimeImmutable($t['completed_at']))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d'),0,10)>=$r['start']&&substr((new \DateTimeImmutable($t['completed_at']))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d'),0,10)<=$r['end']))];}
}
