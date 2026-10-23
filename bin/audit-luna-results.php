<?php
declare(strict_types=1);
// Mechanical screening only. Cannot establish math correctness or Persian suitability.
// Literal hint matching has false positives/negatives; independent semantic review is required.
if(PHP_SAPI!=='cli')exit(1);
$file=$argv[1]??'';
if(!is_file($file))exit("File not found: $file\n");
$data=json_decode(file_get_contents($file),true);
$results=$data['results']??[];
$total=count($results);
$pass=0;$fail=0;$warn=0;
$failures=[];
$totalTime=0;
$maxTime=0;
$totalCost=0;

foreach($results as $r){
    $status=$r['http_status'];
    $text=$r['text']??'';
    $kind=$r['kind'];
    $ans=$r['verified_answer'];
    $time=$r['elapsed_seconds'];
    $cost=$r['cost_usd']??0;
    $totalTime+=$time;
    if($time>$maxTime)$maxTime=$time;
    $totalCost+=$cost;

    if($status!==200||empty($text)){
        $fail++;
        $failures[]=['id'=>$r['case_id'],'kind'=>$kind,'reason'=>'HTTP not 200 or empty text','status'=>$status];
        continue;
    }

    $caseFail=[];

    // 1. Timeout check (>10s)
    if($time>10.0){
        $caseFail[]="Exceeded 10s deadline ($time s)";
    }

    // 2. Finish reason check
    if(($r['finish_reason']??'')!=='stop'){
        $caseFail[]="Abnormal finish_reason: ".($r['finish_reason']??'null');
    }

    // 3. Foreign script / corruption check (Thai, Latin in body, LaTeX, markdown blocks)
    if(preg_match('/[\x{0E00}-\x{0E7F}]/u',$text)){
        $caseFail[]="Contains Thai script";
    }
    if(str_contains($text,'\\frac') || str_contains($text,'\\times') || str_contains($text,'\\div') || str_contains($text,'$$')){
        $caseFail[]="Contains raw LaTeX markup";
    }

    // 4. Hint leakage check: does hint contain the final answer?
    if($kind==='hint'){
        // Strip common punctuation for matching
        $cleanAns=trim(preg_replace('/[،؛\.\s]/u','',$ans));
        $cleanText=preg_replace('/[،؛\.\s]/u','',$text);
        // Look for literal answer
        if($cleanAns!=='' && str_contains($cleanText, $cleanAns)){
            $caseFail[]="Hint leaked exact answer '$ans'";
        }
    }

    // 5. Length check: prompt requires at most 2 sentences
    $sentences=preg_split('/[.!?؟\n]+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY);
    if(count($sentences)>3){
        // soft warning
    }

    if(!empty($caseFail)){
        $fail++;
        $failures[]=['id'=>$r['case_id'],'kind'=>$kind,'reasons'=>$caseFail,'text'=>$text,'time'=>$time];
    } else {
        $pass++;
    }
}

echo json_encode([
    'total_cases'=>$total,
    'no_mechanical_flags'=>$pass,
    'flagged_for_review'=>$fail,
    'semantic_review_required'=>true,
    'avg_elapsed_seconds'=>$total>0?round($totalTime/$total,2):0,
    'max_elapsed_seconds'=>$maxTime,
    'total_cost_usd'=>round($totalCost,6),
    'failures'=>$failures,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
