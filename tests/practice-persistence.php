<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Kashf\{Database,Practice,PracticePrototype,Calendar};
function verify(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=Database::connect(['dsn'=>'sqlite::memory:']);
Database::install($db,json_decode(file_get_contents(__DIR__.'/../database/catalog.json'),true));
$p=new Practice($db);$bank=PracticePrototype::questions(dirname(__DIR__));
$q=array_values(array_filter($bank,fn($q)=>$q['skill']==='fraction_of_quantity'));
$s=$p->act($q,['action'=>'start','skill'=>'fraction_of_quantity','duration'=>'5']);
verify(count($s['ids'])===2,'Short inventory');
$token=$s['token'];$s=$p->act($q,['action'=>'unknown','token'=>$token]);
verify(count($p->reviews())===1,'Unknown queues review');
$s=$p->act($q,['action'=>'unknown','token'=>$token]);
verify(count($s['records'][$q[0]['id']]['attempts'])===1,'Duplicate ignored');
$p=new Practice($db);verify($p->current()===$s,'Survives new service/login');
$s=$p->act($q,['action'=>'hint','token'=>$s['token']]);
$s=$p->act($q,['action'=>'answer','token'=>$s['token'],'answer'=>$q[0]['answer']]);
verify(PracticePrototype::outcome($s['records'][$q[0]['id']])==='help','Assistance retained');
$s=$p->act($q,['action'=>'next','token'=>$s['token']]);
$s=$p->act($q,['action'=>'answer','token'=>$s['token'],'answer'=>$q[1]['answer']]);
$s=$p->act($q,['action'=>'next','token'=>$s['token']]);
verify($s['finished'] && !$p->current(),'Completed persists');
verify($p->report()['sessions']===1 && $p->report()['independent']===1 && $p->report()['help']===1,'Factual dashboard');
$review=$p->reviews()[0];
for($stage=1;$stage<=3;$stage++) {
    $db->exec("UPDATE practice_reviews SET due_date='2000-01-01'");
    $s=$p->act($q,['action'=>'start','skill'=>'fraction_of_quantity']);
    verify($s['ids'][0]!==$review['origin_question'] && isset($s['review_links'][$s['ids'][0]]),'Similar review first');
    $first=array_column($s['questions'],null,'id')[$s['ids'][0]];
    $s=$p->act($q,['action'=>'answer','token'=>$s['token'],'answer'=>$first['answer']]);
    $row=$db->query('SELECT * FROM practice_reviews')->fetch();
    verify((int)$row['stage']===$stage,'Stage advances once');
    $s=$p->act($q,['action'=>'next','token'=>$s['token']]);
    while(!$s['finished']) {
        $question=$s['questions'][$s['index']];
        $s=$p->act($q,['action'=>'answer','token'=>$s['token'],'answer'=>$question['answer']]);
        $s=$p->act($q,['action'=>'next','token'=>$s['token']]);
    }
}
verify(!$p->reviews(),'Three successful similar reviews graduate');
// Assistance on a later review resets its stage and never demonstrates independent learning.
$db->exec("UPDATE practice_reviews SET status='pending',stage=2,due_date='2000-01-01'");
$s=$p->act($q,['action'=>'start','skill'=>'fraction_of_quantity','duration'=>'5']);
$s=$p->act($q,['action'=>'hint','token'=>$s['token']]);
$reviewQuestion=$s['questions'][0];
$s=$p->act($q,['action'=>'answer','token'=>$s['token'],'answer'=>$reviewQuestion['answer']]);
$reset=$db->query('SELECT * FROM practice_reviews')->fetch();
verify((int)$reset['stage']===0 && $reset['status']==='pending','Helped review resets stage');
verify($reset['due_date']===(new DateTimeImmutable(Calendar::today()))->modify('+2 days')->format('Y-m-d'),'Helped review is due in two days');
$s=$p->act($q,['action'=>'next','token'=>$s['token']]);
while(!$s['finished']) {
    $question=$s['questions'][$s['index']];
    $s=$p->act($q,['action'=>'answer','token'=>$s['token'],'answer'=>$question['answer']]);
    $s=$p->act($q,['action'=>'next','token'=>$s['token']]);
}
verify($p->recommendations($q)===[],'One struggled encounter must not trigger adaptation');
$seed=$p->latest();
for($i=0;$i<2;$i++) {
    $seed['records']=[];
    foreach($q as $question) $seed['records'][$question['id']]=['attempts'=>[['answer'=>null,'correct'=>false,'hint'=>false]],'hint'=>false,'solution'=>true,'done'=>true,'correct'=>false];
    Database::insert($db,'practice_sessions',['id'=>'adaptation-'.$i,'learner_id'=>1,'status'=>'completed','state_json'=>json_encode($seed),'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')]);
}
$recommendations=$p->recommendations($q);
verify(count($recommendations)===1 && $recommendations[0]['sessions']>=2 && $recommendations[0]['skill']==='fraction_of_quantity','Repeated struggles suggest eligible easier practice');
verify($p->recommendations([])[0]['skill']===null,'Never recommend an unstudied prerequisite');
echo "PASS durable sessions, duplicate actions, assistance, similar reviews, intervals, parent counts and repeated-encounter adaptation\n";
