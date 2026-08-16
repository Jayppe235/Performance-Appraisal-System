<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$db = db();
$hazel = admin_one("SELECT f.id faculty_id,u.id user_id,u.full_name FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name='Hazel Joy M. Gadingan'");
$riza = admin_one("SELECT f.id faculty_id,u.id user_id,u.full_name FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name='Engr Riza Jean M. Acanto'");
if (!$hazel || !$riza) throw new RuntimeException('Hazel or Riza account/faculty record was not found.');

$stale = admin_all(
    "SELECT pa.* FROM peer_assignments pa
     WHERE pa.cycle_name IN ('2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD')
       AND pa.evaluatee_faculty_id=? AND pa.assignment_type='program_head'
       AND COALESCE(pa.is_archived,0)=0 AND pa.is_current=1",
    [(int)$hazel['faculty_id']]
);
$summary = ['mode'=>$apply?'apply':'dry-run','stale_assignment_ids'=>array_map(fn($r)=>(int)$r['id'],$stale),'replacements'=>[]];
if (!$apply) { echo json_encode($summary, JSON_PRETTY_PRINT), PHP_EOL; exit; }

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir,0770,true) && !is_dir($backupDir)) throw new RuntimeException('Cannot create backup directory.');
$ids = array_map(fn($r)=>(int)$r['id'],$stale);
$marks = $ids ? implode(',',array_fill(0,count($ids),'?')) : 'NULL';
$backup = ['created_at'=>date(DATE_ATOM),'assignments'=>$stale,'form_a_results'=>$ids?admin_all("SELECT * FROM pmas_form_a_category_results WHERE assignment_id IN ($marks)",$ids):[]];
$backupPath = $backupDir . '/hazel-to-riza-historical-cards-' . date('Ymd-His') . '.json';
file_put_contents($backupPath,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$actor=(int)(admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1")['id'] ?? 0);

foreach ($stale as $old) {
    $replacement = admin_one(
        "SELECT * FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type='program_head' AND COALESCE(is_archived,0)=0 LIMIT 1",
        [$old['cycle_name'],(int)$old['evaluator_user_id'],(int)$riza['faculty_id']]
    );
    $db->beginTransaction();
    try {
        if (!$replacement) {
            $db->prepare("INSERT INTO peer_assignments
                (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,effective_from,is_current,is_additional,evaluator_name_snapshot,evaluator_role_snapshot,deadline,replacement_reason)
                SELECT cycle_name,evaluator_user_id,?,evaluator_role,'program_head','admin','pending',NOW(),NOW(),1,is_additional,u.full_name,evaluator_role,deadline,?
                FROM peer_assignments pa JOIN users u ON u.id=pa.evaluator_user_id WHERE pa.id=?")
                ->execute([(int)$riza['faculty_id'],'Corrected target: Riza was the BSIT Program Head for this evaluation period.',(int)$old['id']]);
            $replacement = admin_one('SELECT * FROM peer_assignments WHERE id=?',[(int)$db->lastInsertId()]);
        }
        $db->prepare("UPDATE peer_assignments SET status='replaced',is_current=0,effective_to=NOW(),replaced_by_assignment_id=?,replacement_reason=?,is_archived=1,archived_at=NOW(),archived_by=? WHERE id=?")
            ->execute([(int)$replacement['id'],'Historical correction: Hazel was Faculty; Riza was BSIT Program Head for this period.',$actor,(int)$old['id']]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

    $replacement = admin_one('SELECT * FROM peer_assignments WHERE id=?',[(int)$replacement['id']]);
    if ((string)$replacement['status'] !== 'submitted') {
        $payload=[];
        foreach(dipascaf_form_a_categories() as $ci=>$category){
            $answers=[];
            foreach($category['questions'] as $qi=>$question) $answers[(string)$question['id']]=3+(($ci+$qi+(int)$replacement['id'])%3);
            $payload[(string)$category['id']]=['answers'=>$answers,'evidence'=>[],'behavioral_evidence'=>'SAMPLE DATA: Historical Program Head performance evidence.','reason_for_rating'=>'SAMPLE DATA added for the corrected period-specific assignment.'];
        }
        dipascaf_submit_category_results($replacement,(int)$replacement['evaluator_user_id'],'a',$payload,(string)$replacement['cycle_name']);
    }
    $summary['replacements'][]=['old_id'=>(int)$old['id'],'new_id'=>(int)$replacement['id'],'period'=>$old['cycle_name'],'evaluator_user_id'=>(int)$old['evaluator_user_id'],'evaluatee'=>$riza['full_name']];
}
$db->prepare("UPDATE peer_assignments SET is_current=1,effective_to=NULL,replacement_reason=COALESCE(replacement_reason,'Confirmed as the period-specific BSIT Program Head assignment.') WHERE cycle_name IN ('2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD') AND evaluatee_faculty_id=? AND assignment_type='program_head' AND COALESCE(is_archived,0)=0")
   ->execute([(int)$riza['faculty_id']]);
$db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([$actor,'Replaced active 2024-2025 Hazel Program Head evaluation cards with Riza; preserved old records as archived history and added sample submitted data.']);
$summary['backup']=$backupPath;
$summary['active_riza_cards']=admin_all("SELECT pa.id,pa.cycle_name,pa.evaluator_user_id,pa.status,u.full_name evaluatee FROM peer_assignments pa JOIN faculty f ON f.id=pa.evaluatee_faculty_id JOIN users u ON u.id=f.user_id WHERE pa.cycle_name IN ('2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD') AND pa.assignment_type='program_head' AND pa.evaluatee_faculty_id=? AND COALESCE(pa.is_archived,0)=0",[(int)$riza['faculty_id']]);
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
