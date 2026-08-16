<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$db = db();
$periodNames = ['2024 APPRAISAL PERIOD', '2025 APPRAISAL PERIOD'];
$riza = admin_one("SELECT u.id user_id,u.full_name,f.id faculty_id FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.id=30 AND u.full_name='Engr Riza Jean M. Acanto'");
$hazel = admin_one("SELECT u.id user_id,u.full_name,f.id faculty_id FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.id=34 AND u.full_name='Hazel Joy M. Gadingan'");
if (!$riza || !$hazel) throw new RuntimeException('Expected Riza and Hazel records were not found.');

$summary = ['mode'=>$apply ? 'apply' : 'dry-run', 'records'=>[]];
foreach ($periodNames as $periodName) {
    $period = admin_one('SELECT id,date_end FROM appraisal_periods WHERE period_name=?', [$periodName]);
    if (!$period) throw new RuntimeException("Missing period: {$periodName}");
    $assignment = admin_one(
        "SELECT * FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=?
         AND assignment_type='program_head' AND COALESCE(is_archived,0)=0 ORDER BY is_current DESC,id DESC LIMIT 1",
        [$periodName,(int)$riza['user_id'],(int)$hazel['faculty_id']]
    );
    $summary['records'][] = ['period'=>$periodName,'assignment_id'=>(int)($assignment['id'] ?? 0),'status'=>$assignment['status'] ?? 'missing'];
}
if (!$apply) { echo json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL; exit; }

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir,0770,true) && !is_dir($backupDir)) throw new RuntimeException('Cannot create backup directory.');
$backupPath = $backupDir . '/hazel-historical-phsc-' . date('Ymd-His') . '.json';
$existing = admin_all(
    "SELECT * FROM peer_assignments WHERE cycle_name IN (?,?) AND evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type='program_head'",
    [$periodNames[0],$periodNames[1],(int)$riza['user_id'],(int)$hazel['faculty_id']]
);
$ids = array_map(static fn(array $row): int=>(int)$row['id'], $existing);
$results = $ids === [] ? [] : admin_all('SELECT * FROM pmas_form_b_category_results WHERE assignment_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')', $ids);
file_put_contents($backupPath,json_encode(['created_at'=>date(DATE_ATOM),'assignments'=>$existing,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

$filled = [];
foreach ($periodNames as $periodName) {
    $period = admin_one('SELECT id,date_end FROM appraisal_periods WHERE period_name=?', [$periodName]);
    $assignment = admin_one(
        "SELECT * FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=?
         AND assignment_type='program_head' AND COALESCE(is_archived,0)=0 ORDER BY is_current DESC,id DESC LIMIT 1",
        [$periodName,(int)$riza['user_id'],(int)$hazel['faculty_id']]
    );
    if (!$assignment) {
        $stmt=$db->prepare("INSERT INTO peer_assignments
            (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,effective_from,is_current,is_additional,evaluator_name_snapshot,evaluator_role_snapshot,deadline,replacement_reason)
            VALUES (?, ?, ?, 'program_head', 'program_head', 'faculty', 'pending', NOW(), NOW(), 1, 0, ?, 'program_head', ?, ?)");
        $stmt->execute([$periodName,(int)$riza['user_id'],(int)$hazel['faculty_id'],(string)$riza['full_name'],(string)$period['date_end'],'Historical repair: Riza was the BSIT Program Head and Hazel was faculty.']);
        $assignment = admin_one('SELECT * FROM peer_assignments WHERE id=?', [(int)$db->lastInsertId()]);
    }
    $hasResults = admin_count("SELECT COUNT(*) FROM pmas_form_b_category_results WHERE assignment_id=? AND status='completed' AND COALESCE(is_archived,0)=0", [(int)$assignment['id']]) > 0;
    if (!$hasResults) {
        $items=[];
        foreach(dipascaf_form_b_categories() as $ci=>$category){
            $answers=[];
            foreach($category['questions'] as $qi=>$question) $answers[(string)$question['id']]=3+(($ci+$qi+(int)$assignment['id'])%3);
            $items[]=['category_id'=>(int)$category['id'],'answers'=>$answers,'evidence'=>[],'behavioral_evidence'=>'SAMPLE DATA: Historical BSIT Program Head evaluation evidence.','reason_for_rating'=>'SAMPLE DATA added to restore the completed historical report.'];
        }
        dipascaf_submit_category_results($assignment,(int)$riza['user_id'],'b',['sample_data'=>true,'categories'=>$items],$periodName);
    }
    $filled[]=['period'=>$periodName,'assignment_id'=>(int)$assignment['id'],'result'=>'submitted'];
}
$actor=(int)(admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1")['id'] ?? 0);
$db->prepare('INSERT INTO activity_logs(user_id,description) VALUES(?,?)')->execute([$actor,'Restored sample PH/SC submissions for Riza evaluating Hazel as BSIT faculty in the 2024 and 2025 historical periods.']);
$summary['backup']=$backupPath;
$summary['filled']=$filled;
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
