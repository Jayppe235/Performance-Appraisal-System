<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$targetCode = 'CN';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--department=')) $targetCode = strtoupper(trim(substr($argument, 13)));
}
$departmentConfig = match ($targetCode) {
    'CED', 'CE' => ['code'=>'CED','name'=>'College of Education','label'=>'Education','program'=>'Education'],
    'CBA' => ['code'=>'CBA','name'=>'College of Business and Accountancy','label'=>'Business and Accountancy','program'=>'Business'],
    'CAS' => ['code'=>'CAS','name'=>'College of Arts and Sciences','label'=>'Arts and Sciences','program'=>'Arts and Sciences'],
    'CCJE' => ['code'=>'CCJE','name'=>'College of Criminal Justice Education','label'=>'Criminal Justice Education','program'=>'Criminal Justice'],
    default => ['code'=>'CN','name'=>'College of Nursing','label'=>'Nursing','program'=>'BSN'],
};
$db = db();
$periodId = 1;
$periodName = '2024 APPRAISAL PERIOD';
$department = $departmentConfig['name'];
$collegeLabel = $departmentConfig['label'];
$defaultProgram = $departmentConfig['program'];

$people = admin_all(
    "SELECT epp.user_id,u.full_name,u.role master_role,epp.role_snapshot,epp.faculty_id,
            epp.program_snapshot,f.position_title
       FROM evaluation_period_participation epp
       JOIN users u ON u.id=epp.user_id
       JOIN faculty f ON f.id=epp.faculty_id
      WHERE epp.evaluation_period_id=? AND epp.department_snapshot=?
        AND epp.participation_status='included' AND epp.work_status='active'
      ORDER BY FIELD(epp.role_snapshot,'dean','program_head','teacher'),u.full_name",
    [$periodId, $department]
);
if ($people === []) throw new RuntimeException("No active {$collegeLabel} participants were found for 2024.");

$assignments = admin_all(
    "SELECT pa.*,f.full_name evaluatee_name,f.user_id evaluatee_user_id,
            COALESCE(epp.role_snapshot,eu.role) evaluatee_role
       FROM peer_assignments pa
       JOIN faculty f ON f.id=pa.evaluatee_faculty_id
       LEFT JOIN users eu ON eu.id=f.user_id
       LEFT JOIN evaluation_period_participation epp
         ON epp.evaluation_period_id=? AND epp.user_id=f.user_id
      WHERE pa.cycle_name=? AND COALESCE(pa.is_archived,0)=0 AND pa.is_current=1
        AND pa.status NOT IN('not_required','reassigned','cancelled','replaced')
        AND COALESCE(epp.department_snapshot,f.department)=?
      ORDER BY pa.id",
    [$periodId,$periodName,$department]
);

$summary = ['mode'=>$apply?'apply':'dry-run','period'=>$periodName,'department'=>$department,
    'participants'=>count($people),'pending_evaluations'=>count($assignments)];
if (!$apply) { echo json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL; exit; }

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir) && !mkdir($backupDir,0775,true) && !is_dir($backupDir)) throw new RuntimeException('Cannot create backup directory.');
$userIds = array_map(static fn(array $p): int => (int)$p['user_id'],$people);
$assignmentIds = array_map(static fn(array $a): int => (int)$a['id'],$assignments);
$um = implode(',',array_fill(0,count($userIds),'?'));
$am = $assignmentIds ? implode(',',array_fill(0,count($assignmentIds),'?')) : 'NULL';
$backup = ['created_at'=>date(DATE_ATOM),'participants'=>$people,'assignments'=>$assignments];
$backup['goals']=admin_all("SELECT * FROM pmas_goals_records WHERE period_id=? AND user_id IN($um)",[$periodId,...$userIds]);
$backup['self']=admin_all("SELECT * FROM pmas_self_evaluations WHERE evaluation_period=? AND user_id IN($um)",[$periodName,...$userIds]);
$backup['form_a']=$assignmentIds?admin_all("SELECT * FROM pmas_form_a_category_results WHERE assignment_id IN($am)",$assignmentIds):[];
$backup['form_b']=$assignmentIds?admin_all("SELECT * FROM pmas_form_b_category_results WHERE assignment_id IN($am)",$assignmentIds):[];
$backupFile=$backupDir.'/'.strtolower($departmentConfig['code']).'-2024-sample-before-'.date('Ymd-His').'.json';
file_put_contents($backupFile,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));

$evidence = [
    'high'=>[
        'Consistently prepared clinically accurate learning materials, responded promptly to student concerns, and maintained complete course and duty records.',
        'Demonstrated dependable clinical supervision, clear safety briefings, constructive feedback, and timely coordination with partner facilities.',
        'Modeled professional nursing conduct, collaborated well with colleagues, and used learner outcomes to improve instruction and remediation.',
    ],
    'average'=>[
        'Met regular teaching and clinical obligations, although some assessment feedback and documentation were submitted close to the deadline.',
        'Maintained acceptable classroom and clinical performance, with opportunities to make consultation schedules and follow-up records more consistent.',
        'Completed assigned outputs at the expected standard but participated only occasionally in research, extension, and committee initiatives.',
    ],
    'low'=>[
        'Several clinical evaluation forms and student feedback notes were submitted late, limiting timely remediation for learners needing support.',
        'Classroom observations showed inconsistent use of simulation, differentiated instruction, and documented follow-up for low-performing students.',
        'Required repeated reminders to complete committee evidence and coordinate schedule changes with students and clinical partners.',
        'Professional development participation was limited and the application of updated nursing standards was not consistently documented in course files.',
    ],
];
$recommendations = [
    'Complete a focused coaching cycle on timely assessment feedback and maintain a weekly evidence tracker reviewed by the program coordinator.',
    'Attend simulation-based education training and demonstrate two redesigned clinical learning activities within the next semester.',
    'Use an early-alert checklist for at-risk students, document every intervention, and review outcomes monthly with the Dean.',
    'Join a nursing research or extension team and complete one documented scholarly or community-health output within twelve months.',
    'Develop a personal workload calendar with milestone checks for course files, clinical documents, and committee deliverables.',
];
if ($departmentConfig['code'] === 'CED') {
    $evidence = [
        'high'=>[
            'Consistently prepared outcomes-based lessons, varied assessments, and inclusive learning activities supported by complete classroom evidence.',
            'Demonstrated strong classroom management, timely feedback, responsive student consultation, and dependable coordination during field-study activities.',
            'Used assessment results to refine instruction, shared teaching resources with colleagues, and contributed actively to program and accreditation work.',
        ],
        'average'=>[
            'Met regular teaching obligations, although some feedback, intervention logs, and course-file updates were completed close to the deadline.',
            'Maintained acceptable classroom performance but used differentiated strategies and learning analytics inconsistently across assigned classes.',
            'Completed expected outputs while participation in research, extension, mentoring, or professional learning remained limited during the period.',
        ],
        'low'=>[
            'Several lesson records, assessment analyses, and student intervention notes were incomplete or late, delaying follow-up for learners needing support.',
            'Classroom observations showed limited differentiation, uneven learner engagement, and insufficient documentation of adjustments based on assessment results.',
            'Required repeated reminders to submit accreditation evidence, committee outputs, and field-study coordination records within agreed timelines.',
            'Professional-development participation was limited and new pedagogical strategies were not consistently demonstrated in lesson plans or classroom practice.',
        ],
    ];
    $recommendations = [
        'Complete instructional coaching on differentiated teaching and submit two redesigned lessons with learner-outcome evidence next semester.',
        'Maintain a weekly course-file and assessment tracker reviewed monthly by the program coordinator.',
        'Implement an early-alert and remediation plan for struggling learners and document intervention outcomes every month.',
        'Join an education research or extension team and complete one classroom-based study, presentation, or community-learning output within twelve months.',
        'Attend assessment-literacy training and prepare item analyses and action notes for every major examination.',
    ];
} elseif (!in_array($departmentConfig['code'], ['CN'], true)) {
    $evidence = [
        'high'=>[
            'Consistently delivered well-prepared instruction, timely assessment feedback, complete course evidence, and responsive student consultation.',
            'Demonstrated dependable coordination, professional conduct, constructive collaboration, and strong follow-through on academic commitments.',
            'Used learner outcomes to improve instruction, contributed actively to quality-assurance work, and shared effective practices with colleagues.',
        ],
        'average'=>[
            'Met regular teaching and service obligations, although some feedback, documentation, and follow-up records were completed close to deadlines.',
            'Maintained acceptable classroom performance with opportunities to use more differentiated strategies and evidence-informed interventions.',
            'Completed expected outputs while participation in scholarship, extension, mentoring, or institutional initiatives remained limited.',
        ],
        'low'=>[
            'Several assessment analyses, consultation notes, and required course records were incomplete or submitted late during the appraisal period.',
            'Classroom evidence showed inconsistent learner engagement, limited differentiation, and insufficient documented follow-up for students needing support.',
            'Required repeated reminders to complete committee outputs, quality-assurance evidence, and agreed administrative deliverables.',
            'Professional-development participation and documented application of updated disciplinary practices were below the expected standard.',
        ],
    ];
    $recommendations = [
        'Complete a focused coaching cycle on assessment feedback and maintain a weekly evidence tracker reviewed by the assigned supervisor.',
        'Redesign two learning activities using differentiated and evidence-informed strategies and document their effect on learner outcomes.',
        'Use an early-alert checklist for at-risk students, record every intervention, and review results monthly with the Dean.',
        'Join a research or extension team and complete one discipline-related scholarly or community output within twelve months.',
        'Develop a workload calendar with milestone checks for course files, committee assignments, and quality-assurance deliverables.',
    ];
}

$db->beginTransaction();
try {
    $evaluationCount=0;
    foreach ($assignments as $index=>$assignment) {
        if ((string)$assignment['assignment_type']==='self') continue;
        $form=(string)$assignment['questionnaire_type']==='admin'?'a':'b';
        $categories=$form==='a'?dipascaf_form_a_categories():dipascaf_form_b_categories();
        $profile=($index%5===0)?'low':(($index%3===0)?'average':'high');
        $items=[];
        foreach ($categories as $ci=>$category) {
            $categoryProfile = $profile === 'high'
                ? ((($index+$ci)%5===0)?'average':'high')
                : ($profile === 'average'
                    ? ((($index+$ci)%6===0)?'low':'average')
                    : ((($index+$ci)%4===0)?'average':'low'));
            $answers=[];
            foreach ($category['questions'] as $qi=>$question) {
                $seed=($index*11+$ci*5+$qi*3)%10;
                $rating=$categoryProfile==='low'?(2+($seed%2)):($categoryProfile==='average'?(($seed%4===0)?3:4):(4+($seed%2)));
                $answers[(string)$question['id']]=$rating;
            }
            $categoryName=(string)($category['title']??'performance area');
            $observationCycle=1+(($index+$ci)%4);
            $ev=$evidence[$categoryProfile][($index+$ci)%count($evidence[$categoryProfile])]
                .' For '.(string)$assignment['evaluatee_name'].', observation cycle '.$observationCycle
                .' specifically documented this pattern under '.$categoryName.'.';
            $reason=$categoryProfile==='low'
                ? 'The rating reflects recurring gaps documented during the period rather than a single incident. Improvement is achievable through structured follow-up and clearer evidence of completion.'
                : ($categoryProfile==='average'
                    ? 'The employee met the core requirement, but consistency, timeliness, and documented impact can still improve.'
                    : 'The rating is supported by consistent outputs, reliable follow-through, and positive student or colleague feedback.');
            $reason.=' The score was calibrated from category evidence set '.(100+$index*9+$ci).' and is specific to '.$categoryName.'.';
            $item=['category_id'=>(int)$category['id'],'answers'=>$answers,'evidence'=>[$ev],
                'behavioral_evidence'=>$ev,'reason_for_rating'=>$reason,
                'recommendation'=>$recommendations[($index+$ci)%count($recommendations)]
                    .' Individual plan for '.(string)$assignment['evaluatee_name'].' in '.$categoryName
                    .': '.(2+(($index+$ci)%5)).' documented checkpoints before '.date('F Y',strtotime('+'.(2+(($index+$ci)%8)).' months'))
                    .'; tracking reference '.(5000+$index*100+$ci).'.'];
            if ($form==='a') $items[(string)$category['id']]=$item; else $items[]=$item;
        }
        dipascaf_submit_category_results($assignment,(int)$assignment['evaluator_user_id'],$form,
            $form==='a'?$items:['sample_data'=>true,'categories'=>$items],$periodName);
        $evaluationCount++;
    }

    $template=admin_one("SELECT id,version,template_json FROM pmas_goals_form_templates WHERE template_key='pmas_form_1' AND is_active=1 ORDER BY version DESC LIMIT 1");
    if (!$template) throw new RuntimeException('Active Goals Record Sheet template is missing.');
    $dean=admin_one("SELECT u.id,u.full_name FROM evaluation_period_deans epd JOIN users u ON u.id=epd.user_id JOIN departments d ON d.id=epd.department_id WHERE epd.evaluation_period_id=? AND d.department_name=? LIMIT 1",[$periodId,$department]);
    $dean ??= admin_one("SELECT u.id,u.full_name FROM users u JOIN departments d ON d.dean_user_id=u.id WHERE d.department_name=? LIMIT 1",[$department]);
    if (!$dean) throw new RuntimeException('A Dean or period Acting Dean must be assigned before generating department records.');
    $vpaa=admin_one("SELECT id,full_name FROM users WHERE role='vpaa' AND is_active=1 ORDER BY id LIMIT 1");
    $goalSql=$db->prepare("INSERT INTO pmas_goals_records(user_id,period_id,template_id,template_version,employee_name,position_title,department,appraisal_period,goals_json,template_snapshot_json,status,reviewer_id,reviewer_name,review_comment,submitted_at,reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,'approved',?,?,?,?,?) ON DUPLICATE KEY UPDATE employee_name=VALUES(employee_name),position_title=VALUES(position_title),department=VALUES(department),appraisal_period=VALUES(appraisal_period),goals_json=VALUES(goals_json),template_snapshot_json=VALUES(template_snapshot_json),status='approved',reviewer_id=VALUES(reviewer_id),reviewer_name=VALUES(reviewer_name),review_comment=VALUES(review_comment),submitted_at=VALUES(submitted_at),reviewed_at=VALUES(reviewed_at)");
    $selfCount=$goalCount=0;
    foreach ($people as $index=>$person) {
        $isDean=(string)$person['master_role']==='dean' || (string)$person['role_snapshot']==='dean';
        $name=(string)$person['full_name'];
        $rating=round([4.62,4.18,3.84,3.46,3.12,2.78][$index%6]-(($index%3)*0.03),2);
        $level=$rating>=4.5?'Exceptional':($rating>=3.75?'Very Satisfactory':($rating>=3?'Satisfactory':'Needs Improvement'));
        $themes = match ($departmentConfig['code']) {
            'CED' => ['Teaching and Learning Quality','Learner Success and Retention','Education Research and Innovation','Community and School Engagement'],
            'CN' => ['Clinical Instruction','Student Success and Retention','Evidence-Based Nursing Practice','Community Health Engagement'],
            default => ['Instructional Quality','Student Success and Retention','Research and Professional Practice','Community and Institutional Engagement'],
        };
        if($isDean)$themes = match ($departmentConfig['code']) {
            'CED' => ['Academic and Practicum Governance','Quality Assurance and Accreditation','Faculty Capability Development','School and Community Partnerships'],
            'CN' => ['Academic and Clinical Governance','Quality Assurance and Accreditation','Faculty Capability Development','Clinical and Community Partnerships'],
            default => ['Academic Governance','Quality Assurance and Accreditation','Faculty Capability Development','Industry and Community Partnerships'],
        };
        $goals=[];
        foreach($themes as $gi=>$theme){
            $target=$departmentConfig['code']==='CED' ? [
                'Deliver outcomes-based lessons using inclusive strategies, varied assessment, and timely evidence-informed feedback.',
                'Implement targeted support for at-risk education students and monitor progress through monthly learner-outcome reviews.',
                'Complete one classroom-based research, instructional innovation, or scholarly dissemination output.',
                'Lead or support a school or community-learning activity with documented outcomes and partner feedback.',
            ][$gi] : ($departmentConfig['code']==='CN' ? [
                'Complete and document all planned clinical learning activities with accurate competency assessment and timely learner feedback.',
                'Implement targeted support for at-risk nursing students and monitor progress through monthly outcome reviews.',
                'Apply current evidence-based nursing standards to instruction and contribute one research, case, or quality-improvement output.',
                'Lead or support a community-health activity with documented participant outcomes and partner feedback.',
            ][$gi] : [
                'Deliver outcomes-based instruction with varied assessment, timely feedback, and complete evidence of learner progress.',
                'Implement targeted support for at-risk students and monitor progress through monthly outcome reviews.',
                'Complete one discipline-related research, innovation, professional-practice, or scholarly dissemination output.',
                'Lead or support an institutional, industry, or community activity with documented outcomes and partner feedback.',
            ][$gi]);
            if($isDean)$target=$departmentConfig['code']==='CED' ? [
                'Conduct quarterly Education program, practicum, and licensure-readiness reviews with documented decisions and action owners.',
                'Close priority accreditation and compliance gaps and reach at least 95% evidence completeness before internal review.',
                'Deliver a differentiated mentoring and development plan based on faculty appraisal and instructional-support needs.',
                'Sustain at least three active school or community partnerships supporting practicum, research, and extension.',
            ][$gi] : ($departmentConfig['code']==='CN' ? [
                'Conduct quarterly Nursing program and clinical-governance reviews with documented decisions, owners, and completion dates.',
                'Close priority accreditation and compliance gaps and reach at least 95% evidence completeness before internal review.',
                'Deliver a differentiated mentoring and development plan based on faculty appraisal and clinical-supervision needs.',
                'Sustain at least three active clinical or community partnerships supporting instruction, research, and service.',
            ][$gi] : [
                'Conduct quarterly academic-governance reviews with documented decisions, responsible owners, and completion dates.',
                'Close priority accreditation and compliance gaps and reach at least 95% evidence completeness before internal review.',
                'Deliver a differentiated mentoring and development plan based on faculty appraisal and learner-outcome needs.',
                'Sustain at least three active institutional, industry, or community partnerships supporting instruction, research, and service.',
            ][$gi]);
            $personalTarget=80+(($index*7+$gi*5)%19);
            $target.=' For '.$name.', the individualized success threshold is '.$personalTarget.'% with '.(2+(($index+$gi)%5)).' documented evidence checkpoints.';
            $goals[]=['keyResultArea'=>$theme,'goalStatement'=>$target,'weight'=>[30,25,25,20][$gi],
                'objective'=>'Strengthen '.strtolower($theme).' through measurable, evidence-based actions.',
                'target'=>$personalTarget.'% completion and '.(2+(($index+$gi)%5)).' verified outputs',
                'timeline'=>['Quarterly review cycle '.(1+($index%4)),'Monthly monitoring beginning '.date('F',mktime(0,0,0,1+($index%12),1)),'By '.date('F Y',mktime(0,0,0,3+(($index+$gi)%8),1,2026)),'Milestone review every '.(4+(($index+$gi)%5)).' weeks'][$gi],
                'accomplishment'=>$name.' completed '.(2+(($index+$gi)%6)).' planned '.strtolower($theme).' outputs and maintained supporting reports, attendance, assessment evidence, and follow-up notes identified as portfolio set '.(202400+$index*10+$gi).'.',
                'standards'=>['exceptional'=>'Exceeds the target by at least 10% with documented impact.','exceeds'=>'Completes the full target ahead of schedule.','meets'=>'Completes the target on schedule at the required quality.','meetsMost'=>'Completes 75–89% with minor gaps.','doesNotMeet'=>'Completes below 75% or leaves major evidence gaps.']];
        }
        $reviewer=$isDean?$vpaa:$dean;
        $submitted=sprintf('2026-08-%02d 09:%02d:00',1+($index%7),10+$index);
        $reviewed=sprintf('2026-08-%02d 14:%02d:00',3+($index%7),10+$index);
        $goalSql->execute([(int)$person['user_id'],$periodId,(int)$template['id'],(int)$template['version'],$name,
            $isDean?'Dean':((string)$person['position_title']?:$collegeLabel.' Faculty'),$department,$periodName,
            json_encode($goals,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)$template['template_json'],
            (int)$reviewer['id'],(string)$reviewer['full_name'],"Approved sample goals are measurable and aligned with {$collegeLabel} priorities.",$submitted,$reviewed]);
        $goalCount++;

        $assignment=admin_one("SELECT id FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type='self' AND COALESCE(is_archived,0)=0 ORDER BY id DESC LIMIT 1",[$periodName,(int)$person['user_id'],(int)$person['faculty_id']]);
        $assignmentId=(int)($assignment['id']??0);
        if($assignmentId<=0){$db->prepare("INSERT INTO peer_assignments(cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,is_current,deadline,submitted_at,is_archived) VALUES(?,?,?,?, 'self','self','submitted',NOW(),1,'2026-08-22',?,0)")->execute([$periodName,(int)$person['user_id'],(int)$person['faculty_id'],$isDean?'dean':'teacher',$submitted]);$assignmentId=(int)$db->lastInsertId();}
        else $db->prepare("UPDATE peer_assignments SET status='submitted',submitted_at=?,questionnaire_type='self' WHERE id=?")->execute([$submitted,$assignmentId]);

        $development=$recommendations[$index%count($recommendations)];
        $extraWork=[
            'facilitated a student-support clinic and prepared follow-up notes for advisees',
            'organized teaching-resource sharing and contributed materials to the departmental repository',
            'served on an institutional committee and completed assigned evidence before the review date',
            'supported a community-learning activity and consolidated participant feedback',
            'mentored pre-service teachers during practicum preparation and reflection sessions',
            'helped review assessment tools and documented recommended revisions',
        ][$index%6];
        $answers=['achievedGoals'=>$goals,'otherAccomplishments'=>$name.' '.$extraWork.' during portfolio cycle '.(1+($index%4)).'.',
            'personalStrengths'=>$isDean?'Strategic academic leadership, collaborative decision-making, quality assurance, and reliable follow-through.':($departmentConfig['code']==='CED'?'Pedagogical competence, learner support, inclusive instruction, professional collaboration, and dependable completion of assigned duties.':($departmentConfig['code']==='CN'?'Clinical competence, learner support, professional collaboration, and dependable completion of assigned duties.':'Disciplinary expertise, learner support, inclusive instruction, professional collaboration, and dependable completion of assigned duties.')),
            'overallSelfRating'=>$level,'ratingBasis'=>"The {$rating}/5 rating for {$name} reflects ".(3+($index%6))." documented teaching, service, leadership, and professional-development outputs reviewed during evidence cycle ".(1+($index%4)).'.',
            'developmentNeeds'=>$departmentConfig['code']==='CED'
                ? [$index%2?'Differentiated and inclusive instruction':'Classroom research writing and scholarly dissemination',$index%3?'Learning analytics and remediation':'Assessment design and timely learner feedback']
                : ($departmentConfig['code']==='CN'
                    ? [$index%2?'Advanced simulation facilitation':'Research writing and evidence-based practice dissemination',$index%3?'Learning analytics and remediation':'Clinical documentation and timely feedback']
                    : [$index%2?'Differentiated and inclusive instruction':'Discipline-based research and scholarly dissemination',$index%3?'Learning analytics and remediation':'Assessment design and timely learner feedback']),
            'improvementPlans'=>[['area'=>'Priority professional development','actionPlan'=>$development,'timeFrame'=>'Within 6 months'],['area'=>'Scholarship and service','actionPlan'=>'Join a collaborative '.$collegeLabel.' research or community project and complete one documented output.','timeFrame'=>'Within 12 months']],
            'comments'=>$rating<3
                ? 'Focused coaching with '.(3+($index%4)).' progress checks is recommended for '.$name.'; each corrective action should be documented before the next review.'
                : $name.' should continue the demonstrated strengths while completing the individualized development milestone scheduled within '.(4+($index%8)).' months.',
            'performanceOutputs'=>$goals,'confirmations'=>['appraisee'=>$name,'appraiser'=>$reviewer['full_name'],'date'=>'2026-08-09'],'_sample_data'=>true];
        $employee=['name'=>$name,'positionTitle'=>$isDean?'Dean':$collegeLabel.' Faculty','department'=>$department,'program'=>(string)($person['program_snapshot']?:$defaultProgram),'appraisalPeriod'=>$periodName,'sampleData'=>true];
        $phStatus='pending';$deanStatus=$isDean?'pending':'approved';$vpaaStatus=$isDean?'approved':'pending';
        $selfSql="INSERT INTO pmas_self_evaluations(assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at,program_head_review_status,dean_review_status,dean_reviewed_by,dean_reviewed_at,dean_review_notes,vpaa_review_status,vpaa_reviewed_by,vpaa_reviewed_at,vpaa_review_notes,final_admin_submission_status,admin_review_status) VALUES(?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,'submitted',?,? ,?,?,?,?,?,?,?,?,'ready_for_admin','pending') ON DUPLICATE KEY UPDATE role=VALUES(role),department=VALUES(department),evaluation_period=VALUES(evaluation_period),employee_info=VALUES(employee_info),answers_json=VALUES(answers_json),raw_payload_json=VALUES(raw_payload_json),form_payload_json=VALUES(form_payload_json),questionnaire_snapshot=VALUES(questionnaire_snapshot),performance_outputs_score=VALUES(performance_outputs_score),performance_factors_score=VALUES(performance_factors_score),overall_rating=VALUES(overall_rating),performance_level=VALUES(performance_level),status='submitted',submitted_at=VALUES(submitted_at),dean_review_status=VALUES(dean_review_status),dean_reviewed_by=VALUES(dean_reviewed_by),dean_reviewed_at=VALUES(dean_reviewed_at),dean_review_notes=VALUES(dean_review_notes),vpaa_review_status=VALUES(vpaa_review_status),vpaa_reviewed_by=VALUES(vpaa_reviewed_by),vpaa_reviewed_at=VALUES(vpaa_reviewed_at),vpaa_review_notes=VALUES(vpaa_review_notes),final_admin_submission_status='ready_for_admin',reopened_at=NULL,reopened_by=NULL,reopened_reason=NULL";
        $db->prepare($selfSql)->execute([$assignmentId,(int)$person['user_id'],$isDean?'dean':'teacher',$department,$periodName,$isDean?'form_a_dean':'form_b_faculty',json_encode($employee,JSON_UNESCAPED_UNICODE),json_encode($answers,JSON_UNESCAPED_UNICODE),json_encode($answers,JSON_UNESCAPED_UNICODE),json_encode(['sample_data'=>true,'goals'=>$goals],JSON_UNESCAPED_UNICODE),json_encode(['period'=>$periodName,'role'=>$isDean?'dean':'teacher'],JSON_UNESCAPED_UNICODE),$rating,$rating,$rating,$level,$submitted,$phStatus,$deanStatus,$isDean?null:(int)$dean['id'],$isDean?null:$reviewed,$isDean?null:'Reviewed and approved for demonstration.',$vpaaStatus,$isDean?(int)$vpaa['id']:null,$isDean?$reviewed:null,$isDean?'Reviewed and approved by VPAA for demonstration.':null]);
        $selfCount++;
    }
    // Category submission uses the application's existing transaction helper,
    // which may commit its own transaction. Commit only when one remains open.
    if ($db->inTransaction()) $db->commit();
    $summary += ['applied'=>true,'completed_evaluations'=>$evaluationCount,'self_evaluations'=>$selfCount,'goal_records'=>$goalCount,'backup'=>$backupFile];
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
} catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
