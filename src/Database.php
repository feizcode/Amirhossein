<?php
declare(strict_types=1);
namespace Kashf;
use PDO;

final class Database
{
    public static function connect(array $config): PDO
    {
        $pdo = new PDO($config['dsn'], $config['user'] ?? null, $config['password'] ?? null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("SET time_zone = '+00:00'");
            // Shared hosting may default to 10s, shorter than an outbound coach request.
            // Session-only: do not change the server-wide database timeout.
            $pdo->exec('SET SESSION wait_timeout = 60');
        }
        else { $pdo->exec('PRAGMA foreign_keys=ON'); }
        return $pdo;
    }
    public static function schema(bool $mysql = true): array
    {
        $id = $mysql ? 'INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $int = $mysql ? 'INTEGER UNSIGNED' : 'INTEGER';
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            "schema_versions (version INTEGER PRIMARY KEY, installed_at VARCHAR(30) NOT NULL)",
            "learners (id $int PRIMARY KEY, name VARCHAR(100) NOT NULL)",
            "subjects (id VARCHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL, icon VARCHAR(40) NOT NULL, position INTEGER NOT NULL)",
            "chapters (id VARCHAR(64) PRIMARY KEY, subject_id VARCHAR(32) NOT NULL, name VARCHAR(190) NOT NULL, position INTEGER NOT NULL, FOREIGN KEY(subject_id) REFERENCES subjects(id))",
            "topics (id VARCHAR(64) PRIMARY KEY, chapter_id VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, FOREIGN KEY(chapter_id) REFERENCES chapters(id))",
            "resources (id $id, subject_id VARCHAR(32) NOT NULL, resource_key VARCHAR(64) NOT NULL, name VARCHAR(190) NOT NULL, archived INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(30) NOT NULL, UNIQUE(subject_id,resource_key), FOREIGN KEY(subject_id) REFERENCES subjects(id))",
            "resource_topics (id $id, resource_id $int NOT NULL, topic_key VARCHAR(80) NOT NULL, title VARCHAR(255) NOT NULL, position INTEGER NOT NULL, UNIQUE(resource_id,topic_key), FOREIGN KEY(resource_id) REFERENCES resources(id))",
            "activities (id $id, learner_id $int NOT NULL DEFAULT 1, resource_topic_id $int NOT NULL, activity_date DATE NOT NULL, kind VARCHAR(20) NOT NULL, note TEXT NOT NULL, completed INTEGER NOT NULL DEFAULT 0, deleted_at VARCHAR(30) NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id), FOREIGN KEY(resource_topic_id) REFERENCES resource_topics(id))",
            "assignments (id $id, learner_id $int NOT NULL DEFAULT 1, subject_id VARCHAR(32) NOT NULL, title VARCHAR(190) NOT NULL, description TEXT NOT NULL, due_date DATE NOT NULL, completed_at VARCHAR(30) NULL, archived INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id), FOREIGN KEY(subject_id) REFERENCES subjects(id))",
            "extra_studies (id $id, learner_id $int NOT NULL DEFAULT 1, subject_id VARCHAR(32) NOT NULL, title VARCHAR(190) NOT NULL, report TEXT NOT NULL, reference_text VARCHAR(255) NOT NULL, entry_date DATE NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id), FOREIGN KEY(subject_id) REFERENCES subjects(id))",
            "challenges (id $id, learner_id $int NOT NULL DEFAULT 1, subject_id VARCHAR(32) NULL, title VARCHAR(190) NOT NULL, report TEXT NOT NULL, entry_date DATE NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY(learner_id) REFERENCES learners(id), FOREIGN KEY(subject_id) REFERENCES subjects(id))",
            "login_attempts (ip_key VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, first_at INTEGER NOT NULL)",
        ];
        return array_map(fn($t)=>'CREATE TABLE IF NOT EXISTS '.$t.$tail.';', $tables);
    }
    public static function migrate(PDO $db, array $catalog): void
    {
        try {
            if ((int)$db->query("SELECT COUNT(id) FROM subjects WHERE id='kangaroo'")->fetchColumn() === 0) {
                $pos = (int)$db->query('SELECT COALESCE(MAX(position), 5) + 1 FROM subjects')->fetchColumn();
                $db->beginTransaction();
                try {
                    self::insert($db, 'subjects', ['id'=>'kangaroo', 'name'=>'کانگورو', 'icon'=>'puzzle', 'position'=>$pos]);
                    if (!empty($catalog['kangaroo'])) {
                        foreach ($catalog['kangaroo'] as $gi => $g) {
                            self::insert($db, 'chapters', ['id'=>$g['id'], 'subject_id'=>'kangaroo', 'name'=>$g['name'], 'position'=>$gi]);
                            foreach ($g['topics'] as $ti => $t) {
                                self::insert($db, 'topics', ['id'=>$t['id'], 'chapter_id'=>$g['id'], 'name'=>$t['name'], 'position'=>$ti]);
                            }
                        }
                        $rid = self::insert($db, 'resources', ['subject_id'=>'kangaroo', 'resource_key'=>'school', 'name'=>'کتاب مسابقات کانگورو', 'created_at'=>gmdate('c')]);
                        $position = 0;
                        foreach ($catalog['kangaroo'] as $g) {
                            foreach ($g['topics'] as $t) {
                                self::insert($db, 'resource_topics', ['resource_id'=>$rid, 'topic_key'=>$t['id'], 'title'=>$t['name'], 'position'=>$position++]);
                            }
                        }
                        $aid = self::insert($db, 'resources', ['subject_id'=>'kangaroo', 'resource_key'=>'ai', 'name'=>'تمرین با هوش مصنوعی', 'created_at'=>gmdate('c')]);
                        $position = 0;
                        foreach ($catalog['kangaroo'] as $g) {
                            foreach ($g['topics'] as $t) {
                                self::insert($db, 'resource_topics', ['resource_id'=>$aid, 'topic_key'=>$t['id'], 'title'=>$t['name'], 'position'=>$position++]);
                            }
                        }
                    }
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollBack();
                    error_log('Kangaroo migration failed: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('Kangaroo check failed: ' . $e->getMessage());
        }
        try {
            Schedule::migrate($db);
            Practice::migrate($db);
            AiCoach::migrate($db);
        } catch (\Throwable) {
            // Ignore if DDL is not permitted for this user
        }
    }
    public static function install(PDO $db, array $catalog): void
    {
        foreach (self::schema($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') as $sql) { $db->exec($sql); }
        Schedule::migrate($db);
        Practice::migrate($db);
        AiCoach::migrate($db);
        if ($db->query('SELECT COUNT(*) FROM schema_versions WHERE version=1')->fetchColumn()) {
            self::migrate($db, $catalog);
            return;
        }
        $meta=[['math','ریاضی','calculator'],['persian','فارسی','book-open'],['science','علوم','flask-conical'],['social','مطالعات اجتماعی','globe'],['religion','هدیه‌های آسمان','sun'],['quran','قرآن','book-marked'],['kangaroo','کانگورو','puzzle']];
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO learners(id,name) VALUES(1,'امیرحسین')");
            foreach($meta as $pos=>[$s,$name,$icon]) {
                if ((int)$db->query("SELECT COUNT(*) FROM subjects WHERE id='$s'")->fetchColumn() === 0) {
                    self::insert($db,'subjects',['id'=>$s,'name'=>$name,'icon'=>$icon,'position'=>$pos]);
                }
                if (!empty($catalog[$s])) {
                    foreach($catalog[$s] as $gi=>$g){
                        self::insert($db,'chapters',['id'=>$g['id'],'subject_id'=>$s,'name'=>$g['name'],'position'=>$gi]);
                        foreach($g['topics'] as $ti=>$t){
                            self::insert($db,'topics',['id'=>$t['id'],'chapter_id'=>$g['id'],'name'=>$t['name'],'position'=>$ti]);
                        }
                    }
                }
                $sources = ['school' => $s === 'kangaroo' ? 'کتاب مسابقات کانگورو' : 'کتاب مدرسه'];
                if(in_array($s,['math','persian','science'],true)){$sources['khs']='خیلی سبز';}
                $sources['ai']='تمرین با هوش مصنوعی';
                foreach($sources as $key=>$title){
                    $rid=self::insert($db,'resources',['subject_id'=>$s,'resource_key'=>$key,'name'=>$title,'created_at'=>gmdate('c')]);
                    $position=0;
                    if (!empty($catalog[$s])) {
                        foreach($catalog[$s] as $g) {
                            foreach($g['topics'] as $t) {
                                self::insert($db,'resource_topics',['resource_id'=>$rid,'topic_key'=>$t['id'],'title'=>$t['name'],'position'=>$position++]);
                            }
                        }
                    }
                }
            }
            self::insert($db,'schema_versions',['version'=>1,'installed_at'=>gmdate('c')]);
            $db->commit();
        } catch(\Throwable $e){$db->rollBack();throw $e;}
    }
    public static function insert(PDO $db,string $table,array $row): int
    {
        $keys=array_keys($row);foreach([$table,...$keys]as$identifier)if(!is_string($identifier)||!preg_match('/^[a-z_][a-z0-9_]*$/D',$identifier))throw new \InvalidArgumentException('Invalid database identifier');$db->prepare('INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($row));return(int)$db->lastInsertId();
    }
}
