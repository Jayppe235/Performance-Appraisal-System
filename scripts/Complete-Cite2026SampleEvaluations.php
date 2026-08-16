<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/admin_data.php';
require_once __DIR__.'/../includes/evaluation_cards.php';

$apply=in_array('--apply',$argv,true);$period='2026 ACADEMIC YEAR';
foreach($argv as $argument){if(str_starts_with($argument,'--period='))$period=trim(substr($argument,9));}
if(!in_array($period,['2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD','2026 ACADEMIC YEAR'],true))throw new RuntimeException('Unsupported evaluation period.');
$db=db();
$rows=admin_all("SELECT pa.*,f.full_name evaluatee_name,f.department,f.user_id evaluatee_user_id,f.program_code,u.full_name evaluator_name,COALESCE(epp.role_snapshot,eu.role) evaluatee_role,COALESCE(epp.department_snapshot,f.department) period_department,COALESCE(epp.program_snapshot,f.program_code) period_program FROM peer_assignments pa JOIN faculty f ON f.id=pa.evaluatee_faculty_id JOIN users u ON u.id=pa.evaluator_user_id LEFT JOIN users eu ON eu.id=f.user_id LEFT JOIN appraisal_periods ap ON ap.period_name=pa.cycle_name LEFT JOIN evaluation_period_participation epp ON epp.evaluation_period_id=ap.id AND epp.user_id=eu.id WHERE pa.cycle_name=? AND COALESCE(pa.is_archived,0)=0 AND pa.is_current=1 AND pa.status IN('pending','in_progress','reopened') AND COALESCE(epp.department_snapshot,f.department)='College of Information Technology and Engineering' ORDER BY period_program,pa.id",[$period]);
$summary=['mode'=>$apply?'apply':'dry-run','period'=>$period,'sample_data'=>true,'remaining_count'=>count($rows),'by_program'=>[]];
foreach($rows as $r){$p=(string)($r['period_program']?:$r['program_code']?:'Unassigned');$summary['by_program'][$p]=($summary['by_program'][$p]??0)+1;}
if(!$apply){echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;}
$dir=__DIR__.'/../private-backups';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create backup directory.');
$ids=array_map(fn($r)=>(int)$r['id'],$rows);$marks=$ids?implode(',',array_fill(0,count($ids),'?')):'NULL';
$backup=['created_at'=>date(DATE_ATOM),'period'=>$period,'assignments'=>$rows,'form_a'=>$ids?admin_all("SELECT * FROM pmas_form_a_category_results WHERE assignment_id IN($marks)",$ids):[],'form_b'=>$ids?admin_all("SELECT * FROM pmas_form_b_category_results WHERE assignment_id IN($marks)",$ids):[],'self'=>$ids?admin_all("SELECT * FROM pmas_self_evaluations WHERE assignment_id IN($marks)",$ids):[]];
$path=$dir.'/cite-all-sample-completion-'.preg_replace('/[^0-9A-Za-z]+/','-',strtolower($period)).'-'.date('Ymd-His').'.json';file_put_contents($path,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

$completed=[];
foreach($rows as $index=>$a){
 if((string)$a['assignment_type']==='self'){
  $score=3.55+(($index%8)*0.08);$answers=['_sample_data'=>true,'_notice'=>'SAMPLE DATA entered to complete the CITE 2026 demonstration records.','sample_rating'=>round($score,2)];$employee=['name'=>$a['evaluatee_name'],'department'=>$a['period_department'],'program'=>$a['period_program'],'appraisalPeriod'=>$period,'sampleData'=>true];
  $db->prepare("INSERT INTO pmas_self_evaluations(assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at) VALUES(?,?,?,?,?,'self',1,?,?,?,?,?,?,?,?,?,'submitted',NOW()) ON DUPLICATE KEY UPDATE role=VALUES(role),department=VALUES(department),evaluation_period=VALUES(evaluation_period),employee_info=VALUES(employee_info),answers_json=VALUES(answers_json),raw_payload_json=VALUES(raw_payload_json),form_payload_json=VALUES(form_payload_json),questionnaire_snapshot=VALUES(questionnaire_snapshot),performance_outputs_score=VALUES(performance_outputs_score),performance_factors_score=VALUES(performance_factors_score),overall_rating=VALUES(overall_rating),performance_level=VALUES(performance_level),status='submitted',submitted_at=NOW()")->execute([(int)$a['id'],(int)$a['evaluatee_user_id'],(string)$a['evaluatee_role'],(string)$a['period_department'],$period,json_encode($employee,JSON_THROW_ON_ERROR),json_encode($answers,JSON_THROW_ON_ERROR),json_encode($answers,JSON_THROW_ON_ERROR),json_encode(['sample_data'=>true],JSON_THROW_ON_ERROR),json_encode(['sample_data'=>true],JSON_THROW_ON_ERROR),$score,$score,$score,'Very Satisfactory']);
  $db->prepare("UPDATE peer_assignments SET status='submitted',submitted_at=NOW() WHERE id=?")->execute([(int)$a['id']]);
 }else{
  $form=(string)$a['questionnaire_type']==='admin'?'a':'b';$categories=$form==='a'?dipascaf_form_a_categories():dipascaf_form_b_categories();$items=[];
  foreach($categories as $ci=>$cat){$answers=[];foreach($cat['questions'] as $qi=>$q)$answers[(string)$q['id']]=3+(($index+$ci+$qi)%3);$item=['category_id'=>(int)$cat['id'],'answers'=>$answers,'evidence'=>[],'behavioral_evidence'=>'SAMPLE DATA: Consistent performance and professional collaboration observed.','reason_for_rating'=>'SAMPLE DATA added to complete the remaining CITE 2026 record.'];if($form==='a')$items[(string)$cat['id']]=$item;else$items[]=$item;}
  dipascaf_submit_category_results($a,(int)$a['evaluator_user_id'],$form,$form==='a'?$items:['sample_data'=>true,'categories'=>$items],$period);
 }
 $completed[]=(int)$a['id'];
}
$actor=(int)(admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1")['id']??0);$db->prepare('INSERT INTO activity_logs(user_id,description) VALUES(?,?)')->execute([$actor,'Completed all remaining active CITE evaluations for '.$period.' with clearly labeled sample data.']);
$summary['applied']=true;$summary['backup']=$path;$summary['completed_assignment_ids']=$completed;$summary['remaining_after']=admin_count("SELECT COUNT(*) FROM peer_assignments pa JOIN faculty f ON f.id=pa.evaluatee_faculty_id LEFT JOIN users eu ON eu.id=f.user_id LEFT JOIN appraisal_periods ap ON ap.period_name=pa.cycle_name LEFT JOIN evaluation_period_participation epp ON epp.evaluation_period_id=ap.id AND epp.user_id=eu.id WHERE pa.cycle_name=? AND COALESCE(pa.is_archived,0)=0 AND pa.is_current=1 AND pa.status IN('pending','in_progress','reopened') AND COALESCE(epp.department_snapshot,f.department)='College of Information Technology and Engineering'",[$period]);echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
