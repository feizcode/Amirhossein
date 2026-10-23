<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$config=require __DIR__.'/../config/local.php';
$db=Kashf\Database::connect($config['database']);
$sql="SELECT rt.topic_key,rt.title,MAX(a.activity_date) AS last_activity,MAX(a.completed) AS completed FROM activities a JOIN resource_topics rt ON rt.id=a.resource_topic_id JOIN resources r ON r.id=rt.resource_id WHERE a.deleted_at IS NULL AND r.subject_id='math' AND r.resource_key='school' AND r.archived=0 GROUP BY rt.topic_key,rt.title ORDER BY last_activity DESC,rt.topic_key";
echo json_encode($db->query($sql)->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
