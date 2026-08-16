<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/admin_data.php';

$apply=in_array('--apply',$argv,true);$db=db();
$mark=admin_one("SELECT * FROM users WHERE full_name='Mark Bryan Tenebroso'");
$riza=admin_one("SELECT * FROM users WHERE full_name='Engr Riza Jean M. Acanto'");
$period=admin_one("SELECT * FROM appraisal_periods WHERE period_name='2026 ACADEMIC YEAR'");
$department=admin_one("SELECT * FROM departments WHERE department_name='College of Information Technology and Engineering'");
$program=admin_one("SELECT * FROM programs WHERE UPPER(program_code)='BSCPE' AND department_id=?",[(int)($department['id']??0)]);
if(!$mark||!$riza||!$period||!$department||!$program)throw new RuntimeException('Required Mark/Riza/CITE/2026 records were not found.');
$summary=['mode'=>$apply?'apply':'dry-run','mark_user_id'=>(int)$mark['id'],'master_role'=>$mark['role'],'program_affiliation'=>$mark['program'],'period'=>'2026 ACADEMIC YEAR'];
if(!$apply){echo json_encode($summary,JSON_PRETTY_PRINT),PHP_EOL;exit;}
$dir=__DIR__.'/../private-backups';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create backup directory.');
$path=$dir.'/mark-current-dean-role-'.date('Ymd-His').'.json';file_put_contents($path,json_encode(['created_at'=>date(DATE_ATOM),'user'=>$mark,'participation'=>admin_all('SELECT * FROM evaluation_period_participation WHERE user_id=?',[(int)$mark['id']]),'head_rows'=>admin_all('SELECT * FROM evaluation_period_program_heads WHERE user_id=?',[(int)$mark['id']]),'assignments'=>admin_all("SELECT * FROM peer_assignments WHERE evaluator_user_id=? AND cycle_name='2026 ACADEMIC YEAR'",[(int)$mark['id']])],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$actor=(int)(admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1")['id']??0);
$db->beginTransaction();try{
 $db->prepare("UPDATE users SET role='dean',faculty_designation='Dean',department=?,program='BSCPE' WHERE id=?")->execute([$department['department_name'],(int)$mark['id']]);
 $db->prepare("UPDATE faculty SET position_title='Dean',department=?,program_code='BSCPE' WHERE user_id=?")->execute([$department['department_name'],(int)$mark['id']]);
 $db->prepare('UPDATE departments SET dean_user_id=? WHERE id=?')->execute([(int)$mark['id'],(int)$department['id']]);
 $db->prepare("UPDATE evaluation_period_participation SET role_snapshot='dean',department_id=?,program_id=?,department_snapshot=?,program_snapshot='BSCpE',program_head_slot=NULL,assignment_source='admin',needs_review=0,notes='Current Dean; BSCpE is an affiliation only and does not determine role.',changed_by_user_id=? WHERE evaluation_period_id=? AND user_id=?")->execute([(int)$department['id'],(int)$program['id'],$department['department_name'],$actor,(int)$period['id'],(int)$mark['id']]);
 $db->prepare('DELETE FROM evaluation_period_program_heads WHERE evaluation_period_id=? AND user_id=?')->execute([(int)$period['id'],(int)$mark['id']]);
 $db->prepare("UPDATE programs SET program_head_user_id=? WHERE id=?")->execute([(int)$riza['id'],(int)$program['id']]);
 $db->prepare("UPDATE peer_assignments SET evaluator_role='dean',evaluator_role_snapshot='dean' WHERE cycle_name='2026 ACADEMIC YEAR' AND evaluator_user_id=? AND COALESCE(is_archived,0)=0")->execute([(int)$mark['id']]);
 $db->prepare('INSERT INTO activity_logs(user_id,description) VALUES(?,?)')->execute([$actor,'Enforced Sir Mark as current CITE Dean for 2026; retained BSCpE only as program affiliation and confirmed Ma’am Riza as BSCpE Program Head.']);
 $db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
$summary['backup']=$path;$summary['verified']=admin_one("SELECT u.role,u.faculty_designation,u.program,d.dean_user_id,epp.role_snapshot,epp.program_snapshot,epp.program_head_slot FROM users u JOIN departments d ON d.id=? JOIN evaluation_period_participation epp ON epp.user_id=u.id AND epp.evaluation_period_id=? WHERE u.id=?",[(int)$department['id'],(int)$period['id'],(int)$mark['id']]);echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
