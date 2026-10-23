<?php
declare(strict_types=1);
namespace Kashf;
use PDO;

/** Durable single-learner practice. JSON snapshots retain the exact content and attempt evidence. */
final class Practice
{
    public function __construct(private PDO $db) {}

    public static function schema(bool $mysql): array
    {
        $tail=$mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        return [
            'CREATE TABLE IF NOT EXISTS practice_sessions (id VARCHAR(64) PRIMARY KEY, learner_id INTEGER NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL, state_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL)'.$tail.';',
            'CREATE TABLE IF NOT EXISTS practice_reviews (id VARCHAR(64) PRIMARY KEY, learner_id INTEGER NOT NULL DEFAULT 1, origin_question VARCHAR(80) NOT NULL, skill VARCHAR(80) NOT NULL, group_key VARCHAR(80) NOT NULL, stage INTEGER NOT NULL, due_date VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, history_json TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL)'.$tail.';',
        ];
    }

    public static function migrate(PDO $db): void
    {
        foreach (self::schema($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') as $sql) $db->exec($sql);
        if (!$db->query('SELECT COUNT(*) FROM schema_versions WHERE version=3')->fetchColumn()) Database::insert($db,'schema_versions',['version'=>3,'installed_at'=>gmdate('c')]);
    }

    private function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll();
    }

    public function pauseUnapproved(): void
    {
        $state=$this->current();
        if (!$state) return;
        foreach ($state['questions'] as $question) {
            if (($question['review_status'] ?? '')!=='approved' || empty($question['approved_by']) || empty($question['approved_at'])) {
                $this->db->prepare("UPDATE practice_sessions SET status='paused' WHERE id=?")->execute([$state['session_id']]);
                break;
            }
        }
    }

    public function current(): array
    {
        $rows=$this->rows("SELECT state_json FROM practice_sessions WHERE learner_id=1 AND status='active' ORDER BY created_at DESC");
        return $rows ? json_decode($rows[0]['state_json'],true,512,JSON_THROW_ON_ERROR) : [];
    }

    public function storeAssistance(string $sessionId,string $questionId,string $kind,array $result): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('UPDATE learners SET name=name WHERE id=1');
            $rows=$this->rows('SELECT state_json FROM practice_sessions WHERE id=? AND learner_id=1',[$sessionId]);
            if ($rows) {
                $state=json_decode($rows[0]['state_json'],true,512,JSON_THROW_ON_ERROR);
                if (isset($state['records'][$questionId])) {
                    $state['records'][$questionId]['coach'][$kind]=$result;
                    $this->db->prepare('UPDATE practice_sessions SET state_json=?,updated_at=? WHERE id=?')->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$sessionId]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack();throw $e; }
    }

    public function latest(): array
    {
        $rows=$this->rows('SELECT state_json FROM practice_sessions WHERE learner_id=1 ORDER BY updated_at DESC,created_at DESC');
        return $rows ? json_decode($rows[0]['state_json'],true,512,JSON_THROW_ON_ERROR) : [];
    }

    public function reviews(): array
    {
        $rows=$this->rows("SELECT * FROM practice_reviews WHERE learner_id=1 AND status='pending' ORDER BY due_date,id");
        foreach ($rows as &$row) {
            $history=json_decode($row['history_json'],true,512,JSON_THROW_ON_ERROR);
            $row['topic']=$history[0]['topic'] ?? $row['skill'];
        }
        unset($row);
        return $rows;
    }

    private function after(int $days): string
    {
        return (new \DateTimeImmutable(Calendar::today(),new \DateTimeZone('Asia/Tehran')))->modify('+'.$days.' days')->format('Y-m-d');
    }

    /** A DB row lock plus rotating action token serializes repeated/concurrent answer requests. */
    public function act(array $eligible,array $input): array
    {
        $this->db->beginTransaction();
        try {
            // Lock a pre-existing learner row even when no session exists yet.
            $this->db->exec('UPDATE learners SET name=name WHERE id=1');
            $state=$this->current();
            if (($input['action'] ?? '')==='start') {
                if (!$state) {
                    PracticePrototype::act($state,$eligible,$input);
                    $state['session_id']=bin2hex(random_bytes(16));
                    $state['review_links']=[];
                    foreach ($this->reviews() as $review) {
                        if ($review['due_date']>Calendar::today()) continue;
                        $similar=array_values(array_filter($eligible,fn($q)=>$q['group']===$review['group_key'] && $q['id']!==$review['origin_question']));
                        if (!$similar) continue;
                        $variant=$similar[0];
                        $state['ids']=array_values(array_filter($state['ids'],fn($id)=>$id!==$variant['id']));
                        array_unshift($state['ids'],$variant['id']);
                        // Keep the session short; a review replaces an ordinary slot.
                        $limit=($input['duration'] ?? '')==='5' ? 3 : 4;
                        if (count($state['ids'])>$limit) array_pop($state['ids']);
                        $state['review_links'][$variant['id']]=$review['id'];
                        if ($state['optional_id']===$variant['id']) $state['optional_id']=null;
                        break;
                    }
                    if (!in_array($state['optional_id'],$state['ids'],true)) $state['optional_id']=null;
                    $byId=array_column($eligible,null,'id');
                    $state['questions']=array_map(fn($id)=>$byId[$id],$state['ids']);
                    Database::insert($this->db,'practice_sessions',['id'=>$state['session_id'],'learner_id'=>1,'status'=>'active','state_json'=>json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')]);
                }
            } elseif ($state) {
                $before=$state;
                PracticePrototype::act($state,$state['questions'],$input);
                if ($before!==$state) {
                    $id=$before['ids'][$before['index']];
                    $record=$state['records'][$id] ?? null;
                    $question=array_column($state['questions'],null,'id')[$id];
                    if ($record && ($before['records'][$id] ?? null)!==$record) $this->recordReview($state,$question,$record,$input['action'] ?? '');
                    $this->db->prepare('UPDATE practice_sessions SET status=?,state_json=?,updated_at=? WHERE id=?')->execute([$state['finished']?'completed':'active',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$state['session_id']]);
                }
            }
            $this->db->commit();return $state;
        } catch (\Throwable $e) { $this->db->rollBack();throw $e; }
    }

    private function recordReview(array $state,array $q,array $r,string $action): void
    {
        $linked=$state['review_links'][$q['id']] ?? null;
        $reviewId=$linked ?? hash('sha256','1|'.$q['id']);
        $existing=$this->rows('SELECT * FROM practice_reviews WHERE id=?',[$reviewId]);
        $needs=$r['hint'] || $r['solution'] && !$r['correct'] || count($r['attempts'])>1 || ($r['attempts'] && !$r['attempts'][0]['correct']);
        if (!$linked && !$needs) return;
        $old=$existing[0] ?? null;
        $history=$old ? json_decode($old['history_json'],true,512,JSON_THROW_ON_ERROR) : [];
        // Only answer/help events are learning evidence; navigation is not another encounter.
        if (!in_array($action,['answer','unknown','hint','solution'],true)) return;
        $history[]=['session'=>$state['session_id'],'question'=>$q['id'],'action'=>$action,'topic'=>$q['topic'],'record'=>$r,'at'=>gmdate('c')];
        $success=$linked && $r['correct'] && PracticePrototype::outcome($r)==='independent';
        // Do not advance a review again when viewing a solution after success.
        if ($old && $old['status']==='completed' && !$needs) return;
        $stage=$old ? (int)$old['stage'] : 0;
        if ($success && $action==='answer') $stage++;
        elseif ($needs && !$success) $stage=0;
        $status=$success && $stage>=3 ? 'completed' : 'pending';
        $due=$success ? $this->after($stage===1?7:14) : ($old && !$needs ? $old['due_date'] : $this->after(2));
        $row=['id'=>$reviewId,'learner_id'=>1,'origin_question'=>$old['origin_question'] ?? $q['id'],'skill'=>$q['skill'],'group_key'=>$q['group'],'stage'=>$stage,'due_date'=>$due,'status'=>$status,'history_json'=>json_encode($history,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'updated_at'=>gmdate('c')];
        if (!$old) Database::insert($this->db,'practice_reviews',$row);
        else { unset($row['id']); $sets=implode(',',array_map(fn($key)=>$key.'=?',array_keys($row)));$this->db->prepare('UPDATE practice_reviews SET '.$sets.' WHERE id=?')->execute([...array_values($row),$reviewId]); }
    }

    /** Require three struggled question encounters across two sessions, never one wrong answer. */
    public function recommendations(array $eligible): array
    {
        $evidence=[];
        foreach ($this->rows('SELECT id,state_json FROM practice_sessions WHERE learner_id=1 ORDER BY created_at DESC') as $row) {
            $s=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
            $byId=array_column($s['questions'],null,'id');
            foreach ($s['records'] as $id=>$r) {
                if (!isset($byId[$id]) || PracticePrototype::outcome($r)==='independent') continue;
                if (!$r['attempts'] && !$r['hint'] && !$r['solution']) continue;
                $skill=$byId[$id]['skill'];
                $evidence[$skill]['encounters']=($evidence[$skill]['encounters'] ?? 0)+1;
                $evidence[$skill]['sessions'][$row['id']]=true;
                $evidence[$skill]['topic']=$byId[$id]['topic'];
            }
        }
        $prerequisites=['mixed_addition'=>'fraction_addition','mixed_subtraction'=>'fraction_addition','fraction_product'=>'fraction_of_quantity','mixed_product'=>'fraction_product','fraction_division'=>'fraction_of_quantity','fraction_of_quantity'=>'improper_fraction'];
        $result=[];
        foreach ($evidence as $skill=>$data) {
            if ($data['encounters']<3 || count($data['sessions'])<2) continue;
            $easy=array_values(array_filter($eligible,fn($q)=>$q['skill']===$skill && $q['difficulty']==='easy'));
            $prior=array_values(array_filter($eligible,fn($q)=>$q['skill']===($prerequisites[$skill] ?? '')));
            $candidate=$easy[0] ?? $prior[0] ?? null;
            $result[]=['topic'=>$data['topic'],'encounters'=>$data['encounters'],'sessions'=>count($data['sessions']),
                'skill'=>$candidate['skill'] ?? null,'suggestion'=>$candidate['topic'] ?? null];
        }
        return $result;
    }

    public function report(): array
    {
        $result=['sessions'=>0,'independent'=>0,'help'=>0,'needs'=>0,'recent'=>[]];
        foreach ($this->rows('SELECT status,state_json,updated_at FROM practice_sessions WHERE learner_id=1 ORDER BY updated_at DESC') as $row) {
            $s=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
            if ($row['status']==='completed') $result['sessions']++;
            $counts=['independent'=>0,'help'=>0,'needs'=>0];
            foreach ($s['records'] as $r) { $key=PracticePrototype::outcome($r);$result[$key]++;$counts[$key]++; }
            if (count($result['recent'])<10) $result['recent'][]=['status'=>$row['status'],'date'=>$row['updated_at'],'counts'=>$counts];
        }
        return $result;
    }
}
