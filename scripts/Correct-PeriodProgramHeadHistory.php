<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';

$apply = in_array('--apply', $argv, true);
$db = db();
$people = [
    'riza'=>['id'=>30,'name'=>'Engr Riza Jean M. Acanto'],
    'mark'=>['id'=>31,'name'=>'Mark Bryan Tenebroso'],
    'hazel'=>['id'=>34,'name'=>'Hazel Joy M. Gadingan'],
];
$historicalOnly = in_array('--historical-only', $argv, true);
$periodNames = $historicalOnly
    ? ['2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD']
    : ['2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD','2026 ACADEMIC YEAR'];

foreach ($people as $person) {
    $row = admin_one('SELECT id,full_name,role FROM users WHERE id=:id', ['id'=>$person['id']]);
    if (!$row || strcasecmp(trim((string)$row['full_name']), $person['name']) !== 0) {
        throw new RuntimeException("User {$person['id']} did not match {$person['name']}; stopped without changes.");
    }
}
$periods = [];
foreach ($periodNames as $name) {
    $period = admin_one('SELECT id,period_name,date_end FROM appraisal_periods WHERE period_name=:name', ['name'=>$name]);
    if (!$period) throw new RuntimeException("Evaluation period {$name} was not found.");
    $periods[$name] = $period;
}
$bsit = admin_one("SELECT id,department_id,program_code FROM programs WHERE UPPER(program_code)='BSIT' AND is_active=1 LIMIT 1");
$bscpe = admin_one("SELECT id,department_id,program_code FROM programs WHERE UPPER(program_code)='BSCPE' AND is_active=1 LIMIT 1");
if (!$bsit || !$bscpe || (int)$bsit['department_id'] !== (int)$bscpe['department_id']) {
    throw new RuntimeException('The active BSIT/BSCpE program records were not found in the same department.');
}
$department = admin_one('SELECT id,department_name FROM departments WHERE id=:id', ['id'=>(int)$bsit['department_id']]);
if (!$department) throw new RuntimeException('CITE department was not found.');

$history = [
    '2024 APPRAISAL PERIOD'=>[
        ['key'=>'riza','role'=>'program_head','program'=>$bsit],
        ['key'=>'mark','role'=>'program_head','program'=>$bscpe],
        ['key'=>'hazel','role'=>'teacher','program'=>$bsit],
    ],
    '2025 APPRAISAL PERIOD'=>[
        ['key'=>'riza','role'=>'program_head','program'=>$bsit],
        ['key'=>'mark','role'=>'program_head','program'=>$bscpe],
        ['key'=>'hazel','role'=>'teacher','program'=>$bsit],
    ],
    '2026 ACADEMIC YEAR'=>[
        ['key'=>'riza','role'=>'program_head','program'=>$bscpe],
        ['key'=>'mark','role'=>'dean','program'=>$bscpe],
        ['key'=>'hazel','role'=>'program_head','program'=>$bsit],
    ],
];
$history = array_intersect_key($history, array_flip($periodNames));

$summary = ['mode'=>$apply?'apply':'dry-run','master_accounts_unchanged'=>true,'history'=>[]];
foreach ($history as $periodName=>$assignments) {
    foreach ($assignments as $assignment) {
        $summary['history'][] = [
            'period'=>$periodName,'user'=>$people[$assignment['key']]['name'],
            'role'=>$assignment['role'],'program'=>$assignment['program']['program_code'],
        ];
    }
}
if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) throw new RuntimeException('Unable to create backup directory.');
$backupPath = $backupDir . '/period-program-head-history-' . date('Ymd-His') . '.json';
$periodIds = array_map(static fn(array $row): int=>(int)$row['id'], array_values($periods));
$periodMarks = implode(',', array_fill(0,count($periodIds),'?'));
$userIds = array_column($people,'id');
$userMarks = implode(',',array_fill(0,count($userIds),'?'));
$facultyIds = array_map('intval', array_column(admin_all("SELECT id FROM faculty WHERE user_id IN ($userMarks)",$userIds),'id'));
$facultyMarks = implode(',',array_fill(0,count($facultyIds),'?'));
$backup = [
    'created_at'=>date(DATE_ATOM),
    'participation'=>admin_all("SELECT * FROM evaluation_period_participation WHERE evaluation_period_id IN ($periodMarks) AND user_id IN ($userMarks)",[...$periodIds,...$userIds]),
    'program_heads'=>admin_all("SELECT * FROM evaluation_period_program_heads WHERE evaluation_period_id IN ($periodMarks) AND (user_id IN ($userMarks) OR program_id IN (?,?))",[...$periodIds,...$userIds,(int)$bsit['id'],(int)$bscpe['id']]),
    'assignments'=>admin_all("SELECT * FROM peer_assignments WHERE cycle_name IN (?,?,?) AND (evaluator_user_id IN ($userMarks) OR evaluatee_faculty_id IN ($facultyMarks))",[...$periodNames,...$userIds,...$facultyIds]),
];
file_put_contents($backupPath,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

$actor = admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1");
$actorId = (int)($actor['id'] ?? 0);
$db->beginTransaction();
try {
    $update = $db->prepare(
        "UPDATE evaluation_period_participation SET role_snapshot=?,department_id=?,program_id=?,
         department_snapshot=?,program_snapshot=?,assignment_source='admin',needs_review=0,
         program_head_slot=?,notes=?,changed_by_user_id=? WHERE evaluation_period_id=? AND user_id=?"
    );
    foreach ($history as $periodName=>$assignments) {
        $periodId=(int)$periods[$periodName]['id'];
        foreach ($assignments as $assignment) {
            $isHead=$assignment['role']==='program_head';
            $facultyId = (int)(admin_one('SELECT id FROM faculty WHERE user_id=:user_id LIMIT 1', ['user_id'=>$people[$assignment['key']]['id']])['id'] ?? 0);
            $db->prepare(
                "INSERT IGNORE INTO evaluation_period_participation
                 (evaluation_period_id,user_id,faculty_id,participation_status,work_status,employment_status,changed_by_user_id)
                 VALUES (?, ?, ?, 'included', 'active', 'active', ?)"
            )->execute([$periodId,$people[$assignment['key']]['id'],$facultyId ?: null,$actorId]);
            $update->execute([
                $assignment['role'],(int)$department['id'],(int)$assignment['program']['id'],
                (string)$department['department_name'],(string)$assignment['program']['program_code'],
                $isHead?(int)$assignment['program']['id']:null,
                'Period-specific role corrected without changing the master account role.',
                $actorId,$periodId,$people[$assignment['key']]['id'],
            ]);
            if (!admin_one('SELECT id FROM evaluation_period_participation WHERE evaluation_period_id=:period_id AND user_id=:user_id', ['period_id'=>$periodId,'user_id'=>$people[$assignment['key']]['id']])) {
                throw new RuntimeException("Unable to create participation row for {$assignment['key']} in {$periodName}.");
            }
        }
    }
    $db->prepare("DELETE FROM evaluation_period_program_heads WHERE evaluation_period_id IN ($periodMarks) AND program_id IN (?,?)")
       ->execute([...$periodIds,(int)$bsit['id'],(int)$bscpe['id']]);
    $insertHead=$db->prepare(
        "INSERT INTO evaluation_period_program_heads
         (evaluation_period_id,user_id,department_id,program_id,is_primary,is_lead_evaluator,
          co_head_authorized,authorized_by_user_id,assignment_source,lead_program_slot)
         VALUES (?,?,?,?,1,1,0,?,'admin',?)"
    );
    foreach ($history as $periodName=>$assignments) {
        foreach ($assignments as $assignment) {
            if ($assignment['role']!=='program_head') continue;
            $insertHead->execute([(int)$periods[$periodName]['id'],$people[$assignment['key']]['id'],(int)$department['id'],(int)$assignment['program']['id'],$actorId,(int)$assignment['program']['id']]);
        }
    }
    $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([
        $actorId,
        'Corrected period-specific Program Head history: Riza/Mark for 2024-2025 and Hazel/Riza for 2026; master account roles unchanged.'
    ]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

$generated=[];
foreach ($periods as $period) {
    $generated[$period['period_name']]=dipascaf_upsert_required_assignments_for_period(
        (string)$period['period_name'],(string)$period['date_end']
    );
}

function seed_sample_assignment(array $assignment, string $periodName): void {
    if ((string)$assignment['assignment_type']==='self') {
        $user=admin_one('SELECT u.id,u.full_name,COALESCE(epp.role_snapshot,u.role) role,COALESCE(epp.department_snapshot,u.department) department FROM users u LEFT JOIN evaluation_period_participation epp ON epp.user_id=u.id AND epp.evaluation_period_id=(SELECT id FROM appraisal_periods WHERE period_name=:period) WHERE u.id=:id',['period'=>$periodName,'id'=>(int)$assignment['evaluator_user_id']]);
        $score=3.6+(((int)$assignment['id']%8)/20);
        $answers=['_sample_data'=>true,'_notice'=>'SAMPLE DATA for period-role history validation.','sample_rating'=>round($score,2)];
        db()->prepare("INSERT INTO pmas_self_evaluations (assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at) VALUES (?,?,?,?,?,'self',1,?,?,?,?,?,?,?,?,?,'submitted',NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),role=VALUES(role),department=VALUES(department),evaluation_period=VALUES(evaluation_period),answers_json=VALUES(answers_json),raw_payload_json=VALUES(raw_payload_json),overall_rating=VALUES(overall_rating),performance_outputs_score=VALUES(performance_outputs_score),performance_factors_score=VALUES(performance_factors_score),performance_level=VALUES(performance_level),status='submitted',submitted_at=NOW()")
          ->execute([(int)$assignment['id'],(int)$user['id'],(string)$user['role'],(string)$user['department'],$periodName,json_encode(['name'=>$user['full_name'],'sampleData'=>true]),json_encode($answers),json_encode($answers),json_encode(['sample_data'=>true]),json_encode(['sample_data'=>true]),$score,$score,$score,'Very Satisfactory']);
        db()->prepare("UPDATE peer_assignments SET status='submitted',submitted_at=COALESCE(submitted_at,NOW()) WHERE id=?")->execute([(int)$assignment['id']]);
        return;
    }
    $form=(string)$assignment['questionnaire_type']==='admin'?'a':'b';
    $categories=$form==='a'?dipascaf_form_a_categories():dipascaf_form_b_categories();
    $items=[];
    foreach($categories as $ci=>$category){$answers=[];foreach($category['questions'] as $qi=>$question)$answers[(string)$question['id']]=3+(($ci+$qi+(int)$assignment['id'])%3);$item=['category_id'=>(int)$category['id'],'answers'=>$answers,'evidence'=>[],'behavioral_evidence'=>'SAMPLE DATA: Consistent performance observed for period-role history validation.','reason_for_rating'=>'SAMPLE DATA entered to complete the corrected historical evaluation record.'];if($form==='a')$items[(string)$category['id']]=$item;else$items[]=$item;}
    $payload=$form==='a'?$items:['sample_data'=>true,'categories'=>$items];
    dipascaf_submit_category_results($assignment,(int)$assignment['evaluator_user_id'],$form,$payload,$periodName);
}

$filled=[];
foreach ($periodNames as $periodName) {
    $rows=admin_all("SELECT pa.* FROM peer_assignments pa WHERE pa.cycle_name=? AND pa.status IN ('pending','in_progress','reopened') AND COALESCE(pa.is_archived,0)=0 AND (pa.evaluator_user_id IN ($userMarks) OR pa.evaluatee_faculty_id IN ($facultyMarks))",[$periodName,...$userIds,...$facultyIds]);
    foreach($rows as $row){seed_sample_assignment($row,$periodName);$filled[]=['id'=>(int)$row['id'],'period'=>$periodName,'type'=>$row['assignment_type']];}
}

$summary['applied']=true;
$summary['backup']=$backupPath;
$summary['generated']=$generated;
$summary['sample_records_filled']=$filled;
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
