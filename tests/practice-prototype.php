<?php
declare(strict_types=1);
require __DIR__.'/../src/PracticePrototype.php';
use Kashf\PracticePrototype as P;
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Practice flow check failed'); }
$q=P::questions(dirname(__DIR__)); $s=[];
P::act($s,$q,['action'=>'start','skill'=>$q[0]['skill'],'duration'=>'5']);
check(count($s['ids'])===3 && count(array_unique($s['ids']))===3);
$original=$s; P::act($s,$q,['action'=>'start','skill'=>$q[2]['skill']]); check($s===$original);
$token=$s['token'];
P::act($s,$q,['action'=>'answer','token'=>$token,'answer'=>$q[0]['answer']]);
check(P::outcome($s['records'][$q[0]['id']])==='independent');
P::act($s,$q,['action'=>'answer','token'=>$token,'answer'=>$q[0]['answer']]);
check(count($s['records'][$q[0]['id']]['attempts'])===1);
P::act($s,$q,['action'=>'solution','token'=>$s['token']]);
check(P::outcome($s['records'][$q[0]['id']])==='independent');
P::act($s,$q,['action'=>'next','token'=>$s['token']]); check($s['index']===1);
P::act($s,$q,['action'=>'unknown','token'=>$s['token']]);
P::act($s,$q,['action'=>'hint','token'=>$s['token']]);
P::act($s,$q,['action'=>'answer','token'=>$s['token'],'answer'=>$q[1]['answer']]);
check(P::outcome($s['records'][$q[1]['id']])==='help');
P::act($s,$q,['action'=>'next','token'=>$s['token']]);
$skippedId=$s['ids'][$s['index']];
P::act($s,$q,['action'=>'skip','token'=>$s['token']]); check($s['finished']);
check(!isset($s['records'][$skippedId]));
$s=[]; P::act($s,$q,['action'=>'start','skill'=>$q[2]['skill'],'duration'=>'15']);
check(count($s['ids'])===4);
P::act($s,$q,['action'=>'answer','token'=>$s['token'],'answer'=>'۱۸']);
check($s['records'][$q[2]['id']]['correct']);
$s=[]; P::act($s,$q,['action'=>'start','skill'=>$q[0]['skill']]);
P::act($s,$q,['action'=>'solution','token'=>$s['token']]);
check(P::outcome($s['records'][$q[0]['id']])==='needs');
$learning=['resources'=>[['subject'=>'math','id'=>'school','archived'=>false,'topics'=>[['id'=>'math-g1-t2','name'=>'ضرب کسرها'],['id'=>'math-g0-t3','name'=>'الگوها']]]], 'logs'=>[['subject'=>'math','source'=>'school','topic_id'=>'math-g1-t2']], 'completions'=>[]];
$eligible=P::eligibility($q,$learning);
check(count($eligible['questions'])===4 && array_keys($eligible['skills'])===['fraction_of_quantity','fraction_product']);
$bounded=[]; P::act($bounded,$eligible['questions'],['action'=>'start','skill'=>'fraction_of_quantity','duration'=>'5']);
check(count($bounded['ids'])===3 && $bounded['optional_id']!==null);
$learning['logs'][0]['source']='khs'; check(P::eligibility($q,$learning)['questions']===[]);
$learning['completions']=[['subject'=>'math','source'=>'school','topic'=>'math-g0-t3']];
check(count(P::eligibility($q,$learning)['questions'])===2);
check(P::eligibility($q,[])['questions']===[]);
$pathTopics=[['id'=>'math-g0-t0','name'=>'عددنویسی'],['id'=>'math-g1-t3','name'=>'تقسیم کسرها'],['id'=>'math-g2-t0','name'=>'نسبت']];
$path=['resources'=>[['subject'=>'math','id'=>'school','archived'=>false,'topics'=>$pathTopics]],'logs'=>[
    ['subject'=>'math','source'=>'school','topic_id'=>'math-g1-t3'],
    ['subject'=>'math','source'=>'school','topic_id'=>'math-g0-t0'],
    ['subject'=>'math','source'=>'school','topic_id'=>'math-g2-t0'],
]];
$mapped=P::eligibility($q,$path);
check(array_keys($mapped['skills'])===['fraction_division','place_value','place_compare']);
check(count($mapped['questions'])===6 && $mapped['coverage'][2]['count']===0);
$path['resources'][0]['archived']=true; check(P::eligibility($q,$path)['questions']===[]);
check(count(array_unique(array_column($q,'id')))===count($q));
require __DIR__.'/../vendor/autoload.php';
$twig=new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__.'/../templates'),['strict_variables'=>true]);
$base=['csrf'=>'test','assets'=>['css'=>[],'js'=>'/test.js'],'skills'=>array_unique(array_column($q,'skill')),'error'=>'','groups'=>['independent'=>[],'help'=>[],'needs'=>[]]];
foreach ([[], $s, array_replace($s,['finished'=>true])] as $view) {
    $current=$view && !$view['finished'] ? $q[0] : null;
    check(str_contains($twig->render('practice-prototype.twig',$base+['state'=>$view,'question'=>$current,'record'=>$current ? ($view['records'][$q[0]['id']] ?? null) : null]), 'تمرین امروز'));
}
echo "Practice prototype flow and Twig rendering checks passed.\n";
