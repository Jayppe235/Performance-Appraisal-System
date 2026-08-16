<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/admin_data.php';
require_once __DIR__.'/../includes/evaluation_cards.php';

$apply=in_array('--apply',$argv,true); $db=db(); $period='2024 APPRAISAL PERIOD';
$hazel=admin_one("SELECT u.id user_id,f.id faculty_id,u.full_name FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name='Hazel Joy M. Gadingan'");
$nero=admin_one("SELECT u.id user_id,f.id faculty_id,u.full_name FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name='Nero L. Hontiveros'");
$riza=admin_one("SELECT u.id user_id,f.id faculty_id FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name='Engr Riza Jean M. Acanto'");
$mark=admin_one("SELECT u.id user_id,f.id faculty_id FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name='Mark Bryan Tenebroso'");
if(!$hazel||!$nero||!$riza||!$mark)throw new RuntimeException('Required CITE accounts were not found.');
$before=admin_all("SELECT * FROM peer_assignments WHERE evaluator_user_id=? AND cycle_name=?",[(int)$hazel['user_id'],$period]);
$summary=['mode'=>$apply?'apply':'dry-run','account'=>$hazel['full_name'],'before'=>$before];
if(!$apply){echo json_encode($summary,JSON_PRETTY_PRINT),PHP_EOL;exit;}
$dir=__DIR__.'/../private-backups';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create backup directory.');
$backup=$dir.'/hazel-2024-completion-'.date('Ymd-His').'.json';file_put_contents($backup,json_encode(['created_at'=>date(DATE_ATOM),'assignments'=>$before],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$actor=(int)(admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1")['id']??0);
$db->beginTransaction();
try{
  $db->prepare("UPDATE peer_assignments SET evaluator_role='teacher',evaluator_role_snapshot='teacher' WHERE evaluator_user_id=? AND cycle_name=? AND COALESCE(is_archived,0)=0")->execute([(int)$hazel['user_id'],$period]);
  $db->prepare("UPDATE peer_assignments SET status='replaced',is_current=0,effective_to=NOW(),is_archived=1,archived_at=NOW(),archived_by=?,replacement_reason='Archived because Hazel was Faculty, not Program Head, in 2024.' WHERE evaluator_user_id=? AND cycle_name=? AND assignment_type='program_head' AND evaluatee_faculty_id<>?")->execute([$actor,(int)$hazel['user_id'],$period,(int)$riza['faculty_id']]);
  $oldPeer=admin_one("SELECT * FROM peer_assignments WHERE evaluator_user_id=? AND cycle_name=? AND assignment_type='peer' AND COALESCE(is_archived,0)=0 LIMIT 1",[(int)$hazel['user_id'],$period]);
  if($oldPeer){$db->prepare("UPDATE peer_assignments SET status='replaced',is_current=0,effective_to=NOW(),is_archived=1,archived_at=NOW(),archived_by=?,replacement_reason='Replaced with a valid 2024 Faculty peer assignment.' WHERE id=?")->execute([$actor,(int)$oldPeer['id']]);}
  $existing=admin_one("SELECT * FROM peer_assignments WHERE evaluator_user_id=? AND cycle_name=? AND evaluatee_faculty_id=? AND assignment_type='peer' LIMIT 1",[(int)$hazel['user_id'],$period,(int)$nero['faculty_id']]);
  if($existing){$peerId=(int)$existing['id'];$db->prepare("UPDATE peer_assignments SET evaluator_role='teacher',questionnaire_type='faculty',status='pending',submitted_at=NULL,is_current=1,effective_to=NULL,is_archived=0,archived_at=NULL,archived_by=NULL,evaluator_role_snapshot='teacher' WHERE id=?")->execute([$peerId]);}
  else{$db->prepare("INSERT INTO peer_assignments(cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,effective_from,is_current,evaluator_name_snapshot,evaluator_role_snapshot,deadline,replacement_reason) VALUES(?,?,?,'teacher','peer','faculty','pending',NOW(),NOW(),1,?,'teacher','2026-08-22','Sample completed 2024 Faculty peer evaluation.')")->execute([$period,(int)$hazel['user_id'],(int)$nero['faculty_id'],$hazel['full_name']]);$peerId=(int)$db->lastInsertId();}
  $periodRow=admin_one('SELECT id FROM appraisal_periods WHERE period_name=?',[$period]);
  if(!$periodRow)throw new RuntimeException('2024 period not found.');
  if($oldPeer){$db->prepare("UPDATE peer_evaluation_assignments SET peer_assignment_id=?,evaluatee_id=?,evaluatee_faculty_id=?,status='completed',is_archived=0,archived_at=NULL,archived_by=NULL WHERE peer_assignment_id=?")->execute([$peerId,(int)$nero['user_id'],(int)$nero['faculty_id'],(int)$oldPeer['id']]);}
  $db->prepare("INSERT INTO peer_evaluation_locks(evaluation_period_id,status,locked_at,locked_by) VALUES(?,'locked',NOW(),?) ON DUPLICATE KEY UPDATE status='locked',locked_at=NOW(),locked_by=VALUES(locked_by)")->execute([(int)$periodRow['id'],$actor]);
  $db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
$peer=admin_one('SELECT * FROM peer_assignments WHERE id=?',[$peerId]);$payload=[];
foreach(dipascaf_form_b_categories() as $ci=>$cat){$answers=[];foreach($cat['questions'] as $qi=>$q)$answers[(string)$q['id']]=3+(($ci+$qi+$peerId)%3);$payload['categories'][]=['category_id'=>(int)$cat['id'],'answers'=>$answers,'evidence'=>[],'behavioral_evidence'=>'SAMPLE DATA: Professional collaboration observed during the 2024 period.','reason_for_rating'=>'SAMPLE DATA added to complete Hazel’s historical peer evaluation.'];}
$payload['sample_data']=true;dipascaf_submit_category_results($peer,(int)$hazel['user_id'],'b',$payload,$period);
$db->prepare('INSERT INTO activity_logs(user_id,description) VALUES(?,?)')->execute([$actor,'Completed Hazel 2024 account with submitted self, Faculty peer, Program Head, and Dean evaluation data; invalid later-role assignments archived.']);
$summary['backup']=$backup;$summary['peer_assignment_id']=$peerId;$summary['after']=admin_all("SELECT pa.id,pa.assignment_type,pa.status,pa.evaluator_role,f.full_name evaluatee FROM peer_assignments pa JOIN faculty f ON f.id=pa.evaluatee_faculty_id WHERE pa.evaluator_user_id=? AND pa.cycle_name=? AND COALESCE(pa.is_archived,0)=0",[(int)$hazel['user_id'],$period]);echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
