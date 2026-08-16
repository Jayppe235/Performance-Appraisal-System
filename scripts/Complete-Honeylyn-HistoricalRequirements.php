<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply=in_array('--apply',$argv,true); $db=db(); $userId=138; $facultyId=136;
$periods=$db->query('SELECT id,period_name,date_start AS start_date,date_end AS end_date FROM appraisal_periods WHERE id IN (1,5,6) ORDER BY id')->fetchAll();
if(count($periods)!==3) throw new RuntimeException('The expected 2024, 2025, and 2026 periods were not found. Nothing was changed.');
$summary=['mode'=>$apply?'apply':'dry-run','employee'=>'HONEYLYN M. MAHINAY, LPT, Ed.D.','sample_data'=>true,'periods'=>array_column($periods,'period_name')];
if(!$apply){echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;}

$backupDir=__DIR__.'/../private-backups';
if(!is_dir($backupDir)&&!mkdir($backupDir,0770,true)&&!is_dir($backupDir)) throw new RuntimeException('Cannot create backup directory.');
$names=array_column($periods,'period_name'); $marks=implode(',',array_fill(0,count($names),'?'));
$backup=['created_at'=>date(DATE_ATOM),'assignments'=>admin_all("SELECT * FROM peer_assignments WHERE cycle_name IN ($marks) AND evaluatee_faculty_id=?",[...$names,$facultyId]),'self_evaluations'=>admin_all("SELECT * FROM pmas_self_evaluations WHERE evaluation_period IN ($marks) AND user_id=?",[...$names,$userId])];
$backupPath=$backupDir.'/honeylyn-historical-requirements-before-'.date('Ymd-His').'.json';
file_put_contents($backupPath,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

$insert=$db->prepare("INSERT INTO peer_assignments(cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,effective_from,is_current,is_additional,evaluator_name_snapshot,evaluator_role_snapshot,deadline,is_archived)
 SELECT ?,u.id,? ,u.role,?,'admin','pending',?, ?,1,?,u.full_name,u.role,?,0 FROM users u WHERE u.id=?
 ON DUPLICATE KEY UPDATE questionnaire_type='admin',status='pending',effective_from=VALUES(effective_from),effective_to=NULL,is_current=1,is_additional=VALUES(is_additional),replaced_by_assignment_id=NULL,replacement_reason=NULL,evaluator_name_snapshot=VALUES(evaluator_name_snapshot),evaluator_role_snapshot=VALUES(evaluator_role_snapshot),deadline=VALUES(deadline),is_archived=0,archived_at=NULL,archived_by=NULL");
$completed=[];
foreach($periods as $periodIndex=>$period){
 $cycle=(string)$period['period_name']; $assigned=(string)($period['start_date']?:'2026-01-01').' 08:00:00'; $deadline=(string)($period['end_date']?:$period['start_date']);
 $eligible=admin_one("SELECT epp.user_id FROM evaluation_period_participation epp WHERE epp.evaluation_period_id=? AND epp.user_id<>? AND epp.department_snapshot='College of Education' AND epp.participation_status='included' AND epp.work_status='active' AND epp.employment_status IN ('active','newly_added') ORDER BY epp.user_id LIMIT 1",[(int)$period['id'],$userId]);
 $periodPeer=(int)($eligible['user_id']??0); if($periodPeer<=0) throw new RuntimeException("No eligible peer evaluator exists for {$cycle}.");
 foreach([[$periodPeer,'peer',1],[$userId,'self',0]] as [$evaluator,$type,$additional]) $insert->execute([$cycle,$facultyId,$type,$assigned,$assigned,$additional,$deadline,$evaluator]);
 $rows=admin_all("SELECT pa.*,u.full_name evaluator_name FROM peer_assignments pa JOIN users u ON u.id=pa.evaluator_user_id WHERE pa.cycle_name=? AND pa.evaluatee_faculty_id=? AND pa.assignment_type IN ('peer','self') AND pa.is_current=1 AND COALESCE(pa.is_archived,0)=0",[$cycle,$facultyId]);
 foreach($rows as $assignmentIndex=>$assignment){
  $payload=[];
  foreach(dipascaf_form_a_categories() as $categoryIndex=>$category){$answers=[];foreach($category['questions'] as $questionIndex=>$question)$answers[(string)$question['id']]=4+(($periodIndex+$assignmentIndex+$categoryIndex+$questionIndex)%2);$payload[(string)$category['id']]=['category_id'=>(int)$category['id'],'answers'=>$answers,'evidence'=>[],'behavioral_evidence'=>'SAMPLE DATA: Demonstrates collaborative academic leadership, professional communication, and dependable follow-through.','reason_for_rating'=>'SAMPLE DATA: Continue strengthening measurable department analytics and succession planning.','recommendation'=>'SAMPLE DATA: Sustain effective leadership and document department-level improvement outcomes.'];}
  dipascaf_submit_category_results($assignment,(int)$assignment['evaluator_user_id'],'a',$payload,$cycle); $completed[]=(int)$assignment['id'];
 }
 $self=admin_one("SELECT id FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type='self' AND is_current=1 AND COALESCE(is_archived,0)=0 LIMIT 1",[$cycle,$userId,$facultyId]);
 $info=json_encode(['name'=>'HONEYLYN M. MAHINAY, LPT, Ed.D.','positionTitle'=>'Dean','department'=>'College of Education','appraisalPeriod'=>$cycle,'sampleData'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $answers=json_encode(['personalStrengths'=>'SAMPLE DATA: Collaborative leadership, sound judgment, and consistent academic support.','overallSelfRating'=>'Exceeds Expectations','ratingBasis'=>'SAMPLE DATA: Completed academic coordination, faculty support, and quality-improvement responsibilities.','furtherContribution'=>'SAMPLE DATA: Expand evidence-based planning, mentoring, and department analytics.','improvementPlans'=>[['area'=>'Department analytics','actionPlan'=>'SAMPLE DATA: Maintain a monthly evidence dashboard and review progress with program teams.','timeFrame'=>'Within 6 months']], 'comments'=>'SAMPLE DATA: Historical demonstration self-evaluation.','_sample_data'=>true,'_sample_period'=>$cycle],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $db->prepare("INSERT INTO pmas_self_evaluations(assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at) VALUES(?,?,'dean','College of Education',?,'form_a_dean',1,?,?,?,?,?,4.45,4.45,4.45,'Exceeds Expectations','submitted',NOW()) ON DUPLICATE KEY UPDATE employee_info=VALUES(employee_info),answers_json=VALUES(answers_json),raw_payload_json=VALUES(raw_payload_json),form_payload_json=VALUES(form_payload_json),questionnaire_snapshot=VALUES(questionnaire_snapshot),overall_rating=4.45,performance_level='Exceeds Expectations',status='submitted',submitted_at=NOW(),reopened_at=NULL,reopened_by=NULL,reopened_reason=NULL")->execute([(int)$self['id'],$userId,$cycle,$info,$answers,$answers,json_encode(['sample_data'=>true,'period'=>$cycle],JSON_THROW_ON_ERROR),json_encode(['form_type'=>'form_a_dean','revision'=>1,'period'=>$cycle],JSON_THROW_ON_ERROR)]);
}
$invalidPeerIds=admin_all("SELECT pa.id FROM peer_assignments pa JOIN appraisal_periods ap ON ap.period_name=pa.cycle_name WHERE pa.evaluatee_faculty_id=? AND pa.assignment_type='peer' AND ap.id IN (1,5,6) AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp WHERE epp.evaluation_period_id=ap.id AND epp.user_id=pa.evaluator_user_id AND epp.participation_status='included' AND epp.work_status='active' AND epp.employment_status IN ('active','newly_added'))",[$facultyId]);
foreach($invalidPeerIds as $invalid){$id=(int)$invalid['id'];$db->prepare("UPDATE peer_assignments SET is_archived=1,archived_at=NOW(),status='replaced' WHERE id=?")->execute([$id]);$db->prepare('UPDATE pmas_form_a_category_results SET is_archived=1 WHERE assignment_id=?')->execute([$id]);}
$summary['applied']=true;$summary['backup']=$backupPath;$summary['completed_assignment_ids']=$completed;
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
