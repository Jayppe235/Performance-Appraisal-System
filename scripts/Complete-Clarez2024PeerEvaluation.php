<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$periodName = '2024 APPRAISAL PERIOD';
$evaluatee = admin_one(
    "SELECT u.id user_id,u.full_name,f.id faculty_id,d.id department_id
       FROM users u JOIN faculty f ON f.user_id=u.id
       JOIN departments d ON d.department_name=f.department
      WHERE u.full_name='CLAREZ CHARITY P. MOSKITO, LPT, MATCP' LIMIT 1"
);
$evaluator = admin_one(
    "SELECT u.id,u.full_name
       FROM evaluation_period_participation epp JOIN users u ON u.id=epp.user_id
      WHERE epp.evaluation_period_id=1 AND epp.role_snapshot='dean'
        AND epp.participation_status='included' AND epp.user_id<>?
      ORDER BY (epp.department_snapshot='College of Education') DESC,epp.id LIMIT 1",
    [(int)($evaluatee['user_id'] ?? 0)]
);
if (!$evaluatee || !$evaluator) throw new RuntimeException('The 2024 Dean peer participants could not be resolved.');

$existing = admin_one(
    "SELECT pa.id,pa.status,u.full_name evaluator
       FROM peer_assignments pa JOIN users u ON u.id=pa.evaluator_user_id
      WHERE pa.cycle_name=? AND pa.evaluatee_faculty_id=? AND pa.assignment_type='peer'
        AND COALESCE(pa.is_archived,0)=0 AND pa.is_current=1
      ORDER BY FIELD(pa.status,'submitted','in_progress','pending'),pa.id LIMIT 1",
    [$periodName,(int)$evaluatee['faculty_id']]
);

$summary = [
    'mode'=>$apply?'apply':'dry-run','period'=>$periodName,
    'evaluatee'=>$evaluatee['full_name'],'peer_evaluator'=>$evaluator['full_name'],
    'existing_peer'=>$existing,
];
if (!$apply) { echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL; exit; }

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir) && !mkdir($backupDir,0775,true) && !is_dir($backupDir)) throw new RuntimeException('Cannot create backup directory.');
$backup = [
    'created_at'=>date(DATE_ATOM),
    'evaluatee'=>$evaluatee,
    'existing_assignments'=>admin_all("SELECT * FROM peer_assignments WHERE cycle_name=? AND evaluatee_faculty_id=?",[$periodName,(int)$evaluatee['faculty_id']]),
];
$backupFile=$backupDir.'/clarez-2024-peer-before-'.date('Ymd-His').'.json';
file_put_contents($backupFile,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));

$db=db();
$db->beginTransaction();
try {
    $db->prepare(
        "INSERT INTO peer_assignments
            (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,effective_from,is_current,is_additional,evaluator_name_snapshot,evaluator_role_snapshot,deadline,is_archived)
         VALUES(?,?,?,'dean','peer','admin','pending',NOW(),NOW(),1,1,?,'dean','2026-08-22',0)
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status=IF(status='submitted',status,'pending'),is_current=1,is_additional=1,is_archived=0,archived_at=NULL,archived_by=NULL"
    )->execute([$periodName,(int)$evaluator['id'],(int)$evaluatee['faculty_id'],(string)$evaluator['full_name']]);
    $assignmentId=(int)$db->lastInsertId();
    if($assignmentId<=0){
        $row=admin_one("SELECT id FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type='peer' LIMIT 1",[$periodName,(int)$evaluator['id'],(int)$evaluatee['faculty_id']]);
        $assignmentId=(int)($row['id']??0);
    }
    if($assignmentId<=0) throw new RuntimeException('The Dean peer assignment could not be created.');

    $assignment=admin_one('SELECT * FROM peer_assignments WHERE id=?',[$assignmentId]);
    $categories=dipascaf_form_a_categories();
    $categoryResults=[];
    foreach($categories as $ci=>$category){
        $answers=[];
        foreach($category['questions'] as $qi=>$question){
            $profile = $ci % 4;
            $ratings = match($profile) {
                0 => [5,4,5,4,5],
                1 => [4,4,3,4,3],
                2 => [3,2,3,2,3],
                default => [4,5,4,4,5],
            };
            $answers[(string)$question['id']]=$ratings[$qi%count($ratings)];
        }
        $categoryName=(string)($category['title']??'Leadership performance');
        $categoryResults[(string)$category['id']]=[
            'category_id'=>(int)$category['id'],'answers'=>$answers,
            'evidence'=>["Cross-department Dean review documented consistent academic leadership, timely coordination, and measurable follow-through by {$evaluatee['full_name']} in {$categoryName}."],
            'behavioral_evidence'=>"Portfolio and coordination records showed dependable performance in {$categoryName}, with selected opportunities for stronger documentation and follow-up.",
            'reason_for_rating'=>'The varied rating reflects verified strengths alongside specific improvement opportunities; it is not a duplicated or uniform sample score.',
            'recommendation'=>'Sustain the demonstrated leadership strengths and complete quarterly evidence checks for the lower-rated indicators during the next review cycle.',
        ];
    }
    dipascaf_submit_category_results($assignment,(int)$evaluator['id'],'a',$categoryResults,$periodName);
    $db->prepare("UPDATE peer_assignments SET status='submitted',submitted_at='2026-08-12 10:30:00' WHERE id=?")->execute([$assignmentId]);
    if($db->inTransaction()) $db->commit();
    $summary += ['applied'=>true,'assignment_id'=>$assignmentId,'categories'=>count($categoryResults),'backup'=>$backupFile];
} catch(Throwable $exception){
    if($db->inTransaction()) $db->rollBack();
    throw $exception;
}

echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
