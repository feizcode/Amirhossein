<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Kashf\{Backup,Database};

$checks=0;
function same(mixed $actual,mixed $expected,string $message):void
{
    global $checks;
    $checks++;
    if($actual!==$expected)throw new RuntimeException($message.' expected '.json_encode($expected).' got '.json_encode($actual));
}
function snapshot(PDO $db,array $tables):array
{
    $rows=[];
    foreach($tables as $table)$rows[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
    return $rows;
}
$tempRoot=PHP_OS_FAMILY==='Windows'?getenv('LOCALAPPDATA').'/Temp/opencode':sys_get_temp_dir();
$directory=$tempRoot.'/kashf-practice-backup-'.bin2hex(random_bytes(8));
mkdir($directory,0700,true);
try {
    $db=Database::connect(['dsn'=>'sqlite:'.$directory.'/test.sqlite']);
    $catalog=json_decode(file_get_contents(dirname(__DIR__).'/database/catalog.json'),true,512,JSON_THROW_ON_ERROR);
    Database::install($db,$catalog);
    // Keep this test usable while the practice migration is being integrated.
    $db->exec('CREATE TABLE IF NOT EXISTS practice_sessions (id VARCHAR(64) PRIMARY KEY, learner_id INTEGER NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL, state_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id))');
    $db->exec('CREATE TABLE IF NOT EXISTS practice_reviews (id VARCHAR(64) PRIMARY KEY, learner_id INTEGER NOT NULL DEFAULT 1, origin_question VARCHAR(80) NOT NULL, skill VARCHAR(80) NOT NULL, group_key VARCHAR(80) NOT NULL, stage INTEGER NOT NULL, due_date VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, history_json TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id))');
    $db->exec("INSERT OR IGNORE INTO schema_versions(version,installed_at) VALUES(3,'2026-10-22T00:00:00+00:00')");
    $now='2026-10-22T01:00:00+00:00';
    Database::insert($db,'practice_sessions',['id'=>'session-1','learner_id'=>1,'status'=>'active','state_json'=>json_encode(['question'=>'تمرین','answers'=>[1,2],'position'=>1],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);
    Database::insert($db,'practice_reviews',['id'=>'review-1','learner_id'=>1,'origin_question'=>'question-1','skill'=>'addition','group_key'=>'math:addition','stage'=>2,'due_date'=>'2026-10-24','status'=>'due','history_json'=>'[{"correct":false},{"correct":true}]','updated_at'=>$now]);
    Database::insert($db,'schedule_entries',['learner_id'=>1,'weekday'=>1,'title'=>'Class','subject_id'=>'math','kind'=>'class','start_minute'=>480,'end_minute'=>540,'effective_from'=>'2026-10-22','effective_until'=>null,'created_at'=>$now]);
    $expected=snapshot($db,Backup::TABLES);
    $backup=new Backup($db,$directory);
    $backup->daily();
    $path=$backup->path($backup->files()[0]['name']);
    $data=json_decode(gzdecode(file_get_contents($path)),true,512,JSON_THROW_ON_ERROR);
    same($data['format'],'kashf-backup-v3','New backup format');
    same(array_keys($data['tables']),Backup::TABLES,'V3 dependency order');
    same($data['tables'],$expected,'All persisted rows exported');
    $db->exec("UPDATE practice_sessions SET state_json='{}'");
    $db->exec('DELETE FROM practice_reviews');
    Backup::restore($db,$path);
    same(snapshot($db,Backup::TABLES),$expected,'V3 roundtrip restores every table and migration timestamp');

    foreach(['kashf-backup-v2'=>Backup::V2_TABLES,'kashf-backup-v1'=>Backup::V1_TABLES] as $format=>$tables){
        Backup::restore($db,$path);
        $legacy=['format'=>$format,'tables'=>array_intersect_key($expected,array_flip($tables))];
        $maxVersion=$format==='kashf-backup-v1'?1:2;
        $legacy['tables']['schema_versions']=array_values(array_filter($legacy['tables']['schema_versions'],fn($row)=>(int)$row['version']<=$maxVersion));
        $legacyPath=$directory.'/legacy.json.gz';
        file_put_contents($legacyPath,gzencode(json_encode($legacy,JSON_THROW_ON_ERROR)));
        Backup::restore($db,$legacyPath);
        same($db->query('SELECT * FROM practice_sessions')->fetchAll(),[],$format.' clears sessions');
        same($db->query('SELECT * FROM practice_reviews')->fetchAll(),[],$format.' clears reviews');
        same($db->query('SELECT version FROM schema_versions ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),[1,2,3],$format.' marks installed migrations');
        foreach($tables as $table){
            if($table!=='schema_versions')same($db->query('SELECT * FROM '.$table)->fetchAll(),$expected[$table],$format.' preserves '.$table);
        }
        if($maxVersion===1){
            same($db->query('SELECT * FROM schedule_entries')->fetchAll(),[],'V1 clears schedule entries');
            same($db->query('SELECT * FROM schedule_exceptions')->fetchAll(),[],'V1 clears schedule exceptions');
        }
    }
    // A mislabeled or incomplete format must not erase existing data.
    Backup::restore($db,$path);
    $invalid=$data;
    unset($invalid['tables']['practice_reviews']);
    file_put_contents($directory.'/invalid.json.gz',gzencode(json_encode($invalid,JSON_THROW_ON_ERROR)));
    $rejected=false;
    try{Backup::restore($db,$directory.'/invalid.json.gz');}catch(RuntimeException){$rejected=true;}
    same($rejected,true,'Incomplete V3 rejected');
    same(snapshot($db,Backup::TABLES),$expected,'Invalid backup leaves database intact');
    echo "PASS $checks practice backup checks\n";
} finally {
    unset($backup);
    $db=null;
    foreach(glob($directory.'/*') as $file)unlink($file);
    if(is_file($directory.'/.lock'))unlink($directory.'/.lock');
    rmdir($directory);
}
