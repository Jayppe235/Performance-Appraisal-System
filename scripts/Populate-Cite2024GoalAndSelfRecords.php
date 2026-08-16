<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = db();
$periodId = 1;
$periodName = '2024 APPRAISAL PERIOD';
$department = 'College of Information Technology and Engineering';

$period = $db->prepare('SELECT id, period_name FROM appraisal_periods WHERE id=? AND period_name=?');
$period->execute([$periodId, $periodName]);
if (!$period->fetch()) {
    throw new RuntimeException('The exact 2024 appraisal period was not found. No records were changed.');
}

$db->exec("CREATE TABLE IF NOT EXISTS pmas_goals_records (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, period_id INT NOT NULL,
 template_id INT UNSIGNED NULL, template_version INT UNSIGNED NULL,
 employee_name VARCHAR(190) NOT NULL, position_title VARCHAR(190) NOT NULL DEFAULT '',
 department VARCHAR(190) NOT NULL DEFAULT '', appraisal_period VARCHAR(190) NOT NULL DEFAULT '',
 goals_json LONGTEXT NOT NULL, template_snapshot_json LONGTEXT NULL,
 status ENUM('draft','submitted','under_review','approved','returned','reopened') NOT NULL DEFAULT 'draft',
 reviewer_id INT NULL, reviewer_name VARCHAR(190) NOT NULL DEFAULT '', review_comment TEXT NULL,
 submitted_at DATETIME NULL, reviewed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_goals_user_period (user_id, period_id), KEY idx_goals_status (status), KEY idx_goals_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS pmas_goals_record_revisions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, record_id INT UNSIGNED NOT NULL, revision_no INT NOT NULL,
 snapshot_json LONGTEXT NOT NULL, action VARCHAR(40) NOT NULL, actor_id INT NOT NULL,
 actor_name VARCHAR(190) NOT NULL DEFAULT '', comment TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_goal_revision_record (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS pmas_goals_form_templates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, template_key VARCHAR(80) NOT NULL DEFAULT 'pmas_form_1',
 version INT UNSIGNED NOT NULL DEFAULT 1, template_json LONGTEXT NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_goals_template_version (template_key, version), KEY idx_goals_template_active (template_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$template = [
    'formCode' => 'PMAS FORM 1', 'institution' => 'NOTRE DAME OF MIDSAYAP COLLEGE', 'title' => 'Goals Record Sheet',
    'instructions' => 'Complete this goal record sheet by formulating the work goals you intend to achieve within the rating period. Align the goals with departmental objectives and organizational directions.',
    'minimumGoals' => 1, 'totalWeight' => 100, 'requireTotalWeight' => true,
    'sectionOrder' => ['profile', 'instructions', 'goals', 'approval'],
    'goalFields' => [
        ['key' => 'keyResultArea', 'label' => 'Key Result Area', 'type' => 'text', 'required' => true],
        ['key' => 'goalStatement', 'label' => 'Goal Statement', 'type' => 'textarea', 'required' => true],
        ['key' => 'weight', 'label' => 'Goal Weight', 'type' => 'number', 'required' => true],
    ],
    'standardsTitle' => 'Performance Standards',
    'standardFields' => [
        ['key' => 'exceptional', 'label' => 'Exceptional', 'required' => true],
        ['key' => 'exceeds', 'label' => 'Exceeds Expectations', 'required' => true],
        ['key' => 'meets', 'label' => 'Meets Expectations', 'required' => true],
        ['key' => 'meetsMost', 'label' => 'Meets Most Expectations', 'required' => true],
        ['key' => 'doesNotMeet', 'label' => 'Does Not Meet Expectations', 'required' => true],
    ],
    'approval' => ['employeeSubmissionRequired' => true, 'reviewerApprovalRequired' => true, 'returnCommentRequired' => true, 'reopenCommentRequired' => true],
];
$templateJson = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$db->prepare("INSERT INTO pmas_goals_form_templates(template_key,version,template_json,is_active) VALUES('pmas_form_1',1,?,1) ON DUPLICATE KEY UPDATE template_json=VALUES(template_json),is_active=1")->execute([$templateJson]);
$templateRow = $db->query("SELECT id,version FROM pmas_goals_form_templates WHERE template_key='pmas_form_1' AND is_active=1 ORDER BY version DESC LIMIT 1")->fetch();

$participants = $db->prepare(
    "SELECT epp.user_id,u.full_name,u.role master_role,epp.role_snapshot,epp.program_snapshot,epp.program_id,
            epp.department_snapshot,f.id faculty_id,f.position_title
       FROM evaluation_period_participation epp
       JOIN users u ON u.id=epp.user_id
       LEFT JOIN faculty f ON f.user_id=u.id AND COALESCE(f.is_archived,0)=0
      WHERE epp.evaluation_period_id=? AND epp.department_snapshot=?
        AND epp.participation_status='included' AND epp.work_status='active'
      ORDER BY u.full_name"
);
$participants->execute([$periodId, $department]);
$people = $participants->fetchAll();
if ($people === []) throw new RuntimeException('No active 2024 CITE participants were found.');

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) throw new RuntimeException('Unable to create backup directory.');
$userIds = array_map(static fn(array $row): int => (int)$row['user_id'], $people);
$marks = implode(',', array_fill(0, count($userIds), '?'));
$backup = ['created_at' => date(DATE_ATOM), 'period_id' => $periodId, 'period' => $periodName];
$stmt = $db->prepare("SELECT * FROM pmas_goals_records WHERE period_id=? AND user_id IN ($marks)");
$stmt->execute([$periodId, ...$userIds]); $backup['goals'] = $stmt->fetchAll();
$stmt = $db->prepare("SELECT * FROM pmas_self_evaluations WHERE evaluation_period=? AND user_id IN ($marks)");
$stmt->execute([$periodName, ...$userIds]); $backup['self_evaluations'] = $stmt->fetchAll();
$backupFile = $backupDir . '/cite-2024-goals-self-before-' . date('Ymd-His') . '.json';
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

function programLabel(string $code): string {
    return match (strtoupper($code)) {
        'BSIT' => 'Information Technology', 'BSCS' => 'Computer Science', 'BSCPE', 'BSCPE' => 'Computer Engineering',
        'BSECE' => 'Electronics Engineering', 'BSIS' => 'Information Systems', default => $code,
    };
}
function goalStandards(string $measure): array {
    return [
        'exceptional' => 'Complete at least 110% of the target with documented quality outcomes and no major corrections.',
        'exceeds' => 'Complete 100% of the target ahead of schedule with positive stakeholder feedback.',
        'meets' => 'Complete the stated target on schedule and satisfy the required quality standard.',
        'meetsMost' => 'Complete 75–89% of the target with only minor unresolved requirements.',
        'doesNotMeet' => 'Complete below 75% of the target or miss major quality and documentation requirements. Measure: ' . $measure,
    ];
}
function goalsFor(array $person): array {
    $program = programLabel((string)$person['program_snapshot']);
    $role = (string)$person['role_snapshot'];
    if ((string)$person['master_role'] === 'dean') {
        $items = [
            ['Academic Governance', 'Complete quarterly CITE program performance reviews and document action decisions for all programs.', 30, 'Four documented reviews'],
            ['Accreditation and Quality', 'Coordinate evidence preparation and close priority compliance gaps across CITE programs.', 25, 'At least 90% evidence completeness'],
            ['Faculty Development', 'Implement a department-wide mentoring and professional development plan based on appraisal results.', 25, 'Three activities and follow-through reports'],
            ['Industry and Community Linkages', 'Establish active partnerships supporting internships, certification, research, or extension projects.', 20, 'At least two active partnerships'],
        ];
    } elseif ($role === 'program_head') {
        $items = [
            ['Program Quality Assurance', "Complete curriculum and outcomes monitoring for the {$program} program and document improvement actions.", 30, 'Two monitoring cycles completed'],
            ['Faculty Coordination', "Conduct regular {$program} faculty meetings, classroom monitoring, and timely submission tracking.", 25, 'Monthly meetings and 95% timely submissions'],
            ['Student Success', 'Implement an intervention plan for at-risk students and monitor retention and completion indicators.', 25, 'All identified students monitored'],
            ['External Engagement', 'Coordinate one program-relevant certification, extension, research, or industry activity.', 20, 'One completed activity with evidence'],
        ];
    } else {
        $items = [
            ['Teaching and Learning', "Deliver assigned {$program} courses using outcomes-based plans and updated digital learning resources.", 30, 'All assigned courses documented'],
            ['Student Performance', 'Provide consultation and timely interventions for students who are at risk of not meeting course outcomes.', 25, 'All identified students receive intervention'],
            ['Professional Development', "Complete role-relevant training or certification and apply the learning to {$program} instruction.", 25, 'At least 24 development hours'],
            ['Research and Service', 'Contribute to a research, extension, committee, or industry-linkage activity supporting CITE priorities.', 20, 'One completed output with documentation'],
        ];
    }
    return array_map(static fn(array $g): array => ['keyResultArea'=>$g[0], 'goalStatement'=>$g[1], 'weight'=>$g[2], 'standards'=>goalStandards($g[3])], $items);
}
function accomplishment(string $kra, string $program, string $role): string {
    return match ($kra) {
        'Teaching and Learning' => "Completed all assigned {$program} courses with updated syllabi, learning activities, assessment rubrics, and LMS resources.",
        'Student Performance' => 'Conducted scheduled consultations, documented learner interventions, and followed up students with missing or low-performing outputs.',
        'Professional Development' => 'Completed relevant webinars and technical training and integrated the acquired strategies into instruction and assessment.',
        'Research and Service' => 'Contributed documented work to a CITE committee and a research, extension, or industry-engagement activity.',
        'Program Quality Assurance' => "Completed {$program} outcomes monitoring, reviewed course evidence, and documented corrective actions with faculty members.",
        'Faculty Coordination' => 'Conducted regular coordination meetings, monitored faculty submissions, and followed up teaching and documentation concerns.',
        'Student Success' => 'Implemented an at-risk student monitoring list and coordinated academic advising and referral actions.',
        'External Engagement' => 'Completed a program-aligned professional, industry, research, or community engagement activity with supporting documentation.',
        'Academic Governance' => 'Completed quarterly program reviews and documented decisions, responsible persons, and target completion dates.',
        'Accreditation and Quality' => 'Consolidated accreditation evidence and closed priority documentation gaps through coordinated quality reviews.',
        'Faculty Development' => 'Delivered mentoring and development activities aligned with the department appraisal findings.',
        default => 'Established active CITE linkages that supported student exposure, faculty development, and academic collaboration.',
    };
}

$dean = $db->query("SELECT u.id,u.full_name FROM evaluation_period_deans epd JOIN users u ON u.id=epd.user_id WHERE epd.evaluation_period_id=1 AND u.department='College of Information Technology and Engineering' LIMIT 1")->fetch();
$vpaa = $db->query("SELECT id,full_name FROM users WHERE role='vpaa' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
$goalUpsert = $db->prepare("INSERT INTO pmas_goals_records(user_id,period_id,template_id,template_version,employee_name,position_title,department,appraisal_period,goals_json,template_snapshot_json,status,reviewer_id,reviewer_name,review_comment,submitted_at,reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,'approved',?,?,?,?,?) ON DUPLICATE KEY UPDATE template_id=VALUES(template_id),template_version=VALUES(template_version),employee_name=VALUES(employee_name),position_title=VALUES(position_title),department=VALUES(department),appraisal_period=VALUES(appraisal_period),goals_json=VALUES(goals_json),template_snapshot_json=VALUES(template_snapshot_json),status='approved',reviewer_id=VALUES(reviewer_id),reviewer_name=VALUES(reviewer_name),review_comment=VALUES(review_comment),submitted_at=VALUES(submitted_at),reviewed_at=VALUES(reviewed_at)");

$db->beginTransaction();
try {
    $goalCount = 0; $selfCount = 0;
    foreach ($people as $index => $person) {
        $userId = (int)$person['user_id'];
        $role = (string)$person['role_snapshot'];
        $program = (string)$person['program_snapshot'];
        $displayProgram = programLabel($program);
        $isDean = (string)$person['master_role'] === 'dean';
        $goals = goalsFor($person);

        if ($role === 'teacher') {
            $reviewerStmt = $db->prepare("SELECT u.id,u.full_name FROM evaluation_period_participation epp JOIN users u ON u.id=epp.user_id WHERE epp.evaluation_period_id=? AND epp.program_id=? AND epp.role_snapshot='program_head' AND epp.participation_status='included' ORDER BY u.id LIMIT 1");
            $reviewerStmt->execute([$periodId, (int)$person['program_id']]);
            $reviewer = $reviewerStmt->fetch() ?: $dean;
        } else {
            $reviewer = $isDean ? $vpaa : $dean;
        }
        if (!$reviewer || (int)$reviewer['id'] === $userId) $reviewer = $vpaa;
        $submittedAt = sprintf('2026-08-%02d 09:%02d:00', 1 + ($index % 6), 10 + $index);
        $reviewedAt = sprintf('2026-08-%02d 14:%02d:00', 3 + ($index % 6), 10 + $index);
        $goalUpsert->execute([$userId,$periodId,(int)$templateRow['id'],(int)$templateRow['version'],$person['full_name'],$isDean?'Dean':($role==='program_head'?'Program Head':'Faculty Member'),$department,$periodName,json_encode($goals,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$templateJson,(int)$reviewer['id'],$reviewer['full_name'],'Approved for demonstration. Goals are measurable, aligned with the employee’s 2024 role and program, and supported by clear performance standards.',$submittedAt,$reviewedAt]);
        $goalCount++;

        $assignmentStmt = $db->prepare("SELECT id FROM peer_assignments WHERE evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type='self' AND cycle_name=? AND COALESCE(is_archived,0)=0 ORDER BY id DESC LIMIT 1");
        $assignmentStmt->execute([$userId,(int)$person['faculty_id'],$periodName]);
        $assignmentId = (int)($assignmentStmt->fetchColumn() ?: 0);
        if ($assignmentId <= 0) {
            $insertAssignment = $db->prepare("INSERT INTO peer_assignments(cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,effective_from,is_current,evaluator_name_snapshot,evaluator_role_snapshot,deadline,submitted_at,is_archived) VALUES(?,?,?,?,?,'self','submitted',?,?,?,?,?,'2026-08-22',?,0)");
            $effectiveRole = $isDean ? 'dean' : $role;
            $insertAssignment->execute([$periodName,$userId,(int)$person['faculty_id'],$effectiveRole,'self',$submittedAt,$submittedAt,1,$person['full_name'],$effectiveRole,$submittedAt]);
            $assignmentId = (int)$db->lastInsertId();
        } else {
            $db->prepare("UPDATE peer_assignments SET status='submitted',questionnaire_type='self',submitted_at=COALESCE(submitted_at,?),is_archived=0 WHERE id=?")->execute([$submittedAt,$assignmentId]);
        }

        $rating = round(3.78 + (($index * 7) % 55) / 100, 2);
        $level = $rating >= 4.51 ? 'Exceptional' : ($rating >= 3.76 ? 'Exceeds Expectations' : 'Meets Expectations');
        $outputRows = [];
        foreach ($goals as $goalIndex => $goal) {
            $outputRows[] = [
                'goals' => $goal['keyResultArea'] . ' — ' . $goal['goalStatement'], 'weight' => $goal['weight'],
                'accomplishment' => accomplishment($goal['keyResultArea'], $displayProgram, $role),
                'rating' => $goalIndex === 3 && $rating < 4 ? 'ME' : 'EE', 'approvedGoal' => true,
            ];
        }
        $strength = $isDean
            ? 'Strategic leadership, data-informed decision-making, collaborative governance, and consistent follow-through across CITE programs.'
            : ($role === 'program_head'
                ? "Program coordination, faculty mentoring, quality assurance, and responsive academic leadership for {$displayProgram}."
                : "Subject-matter competence, learner-centered instruction, dependable teamwork, and timely support for {$displayProgram} students.");
        $improvementArea = $isDean ? 'Broader use of department analytics for early intervention' : ($role === 'program_head' ? 'More systematic documentation and delegation of program actions' : 'Expanded use of learning analytics and differentiated instruction');
        $answers = [
            'achievedGoals' => array_map(static fn(array $row): array => ['goals'=>$row['goals'],'accomplishment'=>$row['accomplishment'],'approvedGoal'=>true], $outputRows),
            'otherAccomplishments' => "Supported CITE activities beyond regular duties, shared resources with colleagues, and participated in institutional committees and student-support initiatives during 2024.",
            'unmetGoalsReason' => 'A small number of planned external activities were rescheduled because partner availability and academic-calendar conflicts limited implementation. Preparatory work and documentation were retained for the next cycle.',
            'personalStrengths' => $strength,
            'overallSelfRating' => $level,
            'ratingBasis' => "The {$rating}/5 self-rating reflects completed outputs, documented service, positive collaboration, and consistent fulfillment of responsibilities during the 2024 appraisal period.",
            'furtherContribution' => "Continue developing updated learning resources, mentoring colleagues and students, and supporting data-informed quality improvement for {$displayProgram} and CITE.",
            'performanceOutputs' => $outputRows,
            'performanceFactorsScore' => $rating,
            'appraiseeStrengths' => $strength,
            'improvementPlans' => [[
                'area'=>$improvementArea,
                'actionPlan'=>'Complete focused training, use a monthly evidence tracker, and review progress with the assigned supervisor or program team.',
                'timeFrame'=>'Within 6 months',
            ],[
                'area'=>'Research, publication, and external professional engagement',
                'actionPlan'=>'Join a collaborative project, prepare one presentation or manuscript, and participate in a relevant professional network.',
                'timeFrame'=>'Within 1 year',
            ]],
            'comments' => 'Performance was dependable and aligned with institutional expectations. Continue the documented strengths while completing the identified development actions.',
            'confirmations' => ['appraisee'=>$person['full_name'],'appraiseeSignature'=>'demo-signature-2024','appraiseeSignatureName'=>$person['full_name'],'appraiser'=>$reviewer['full_name'],'reviewer'=>$reviewer['full_name'],'date'=>'2026-08-09'],
            'careerDevelopment' => ['nextJob'=>$isDean?'Senior academic leadership role':($role==='program_head'?'Dean or senior academic coordinator':'Program coordinator or subject-area lead'),'status'=>'High potential for most probable next job but would need development interventions','developmentTime'=>'Within 2 years','actionPlans'=>[['assistance'=>'Leadership mentoring and relevant professional certification','difficulties'=>'Competing teaching and administrative schedules','actionSteps'=>'Complete development plan and document applied outputs','timeTable'=>'Within 1 year']],'appraiser'=>$reviewer['full_name'],'reviewer'=>$reviewer['full_name'],'date'=>'2026-08-09'],
            'selfRatings'=>[], 'selfEvidence'=>[], 'dynamicResponses'=>[], '_sample_data'=>true, '_sample_period'=>$periodName,
        ];
        $employeeInfo = ['name'=>$person['full_name'],'positionTitle'=>$isDean?'Dean':($role==='program_head'?'Program Head':'Faculty Member'),'department'=>$department,'program'=>$program,'appraisalPeriod'=>$periodName,'sampleData'=>true];
        $effectiveRole = $isDean ? 'dean' : $role;
        $formType = $isDean ? 'form_a_dean' : ($role === 'program_head' ? 'form_a_program_head' : 'form_b_faculty');
        $reviewNotes = 'Approved sample record for the 2024 presentation. Accomplishments, ratings, strengths, comments, and improvement actions were reviewed for consistency with the employee’s role and program.';
        $phReviewerId = null; $deanReviewerId = null; $vpaaReviewerId = null;
        $phStatus = 'pending'; $deanStatus = 'pending'; $vpaaStatus = 'pending';
        if ($role === 'teacher') { $phReviewerId=(int)$reviewer['id']; $phStatus='approved'; $deanReviewerId=(int)$dean['id']; $deanStatus='approved'; }
        elseif ($isDean) { $vpaaReviewerId=(int)$vpaa['id']; $vpaaStatus='approved'; }
        else { $deanReviewerId=(int)$reviewer['id']; $deanStatus='approved'; }
        $selfSql = "INSERT INTO pmas_self_evaluations(assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at,program_head_review_status,program_head_reviewed_by,program_head_reviewed_at,program_head_review_notes,dean_review_status,dean_reviewed_by,dean_reviewed_at,dean_review_notes,vpaa_review_status,vpaa_reviewed_by,vpaa_reviewed_at,vpaa_review_notes,final_admin_submission_status,admin_review_status) VALUES(?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,'submitted',?,?,?,?,?,?,?,?,?,?,?,?,?,'ready_for_admin','pending') ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),role=VALUES(role),department=VALUES(department),evaluation_period=VALUES(evaluation_period),form_type=VALUES(form_type),questionnaire_revision=1,employee_info=VALUES(employee_info),answers_json=VALUES(answers_json),raw_payload_json=VALUES(raw_payload_json),form_payload_json=VALUES(form_payload_json),questionnaire_snapshot=VALUES(questionnaire_snapshot),performance_outputs_score=VALUES(performance_outputs_score),performance_factors_score=VALUES(performance_factors_score),overall_rating=VALUES(overall_rating),performance_level=VALUES(performance_level),status='submitted',submitted_at=VALUES(submitted_at),program_head_review_status=VALUES(program_head_review_status),program_head_reviewed_by=VALUES(program_head_reviewed_by),program_head_reviewed_at=VALUES(program_head_reviewed_at),program_head_review_notes=VALUES(program_head_review_notes),dean_review_status=VALUES(dean_review_status),dean_reviewed_by=VALUES(dean_reviewed_by),dean_reviewed_at=VALUES(dean_reviewed_at),dean_review_notes=VALUES(dean_review_notes),vpaa_review_status=VALUES(vpaa_review_status),vpaa_reviewed_by=VALUES(vpaa_reviewed_by),vpaa_reviewed_at=VALUES(vpaa_reviewed_at),vpaa_review_notes=VALUES(vpaa_review_notes),final_admin_submission_status='ready_for_admin',admin_review_status='pending',reopened_at=NULL,reopened_by=NULL,reopened_reason=NULL";
        $db->prepare($selfSql)->execute([$assignmentId,$userId,$effectiveRole,$department,$periodName,$formType,json_encode($employeeInfo,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($answers,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($answers,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode(['sample_data'=>true,'period'=>$periodName,'program'=>$program],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode(['form_type'=>$formType,'revision'=>1,'period'=>$periodName],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$rating,$rating,$rating,$level,$submittedAt,$phStatus,$phReviewerId,$phReviewerId?$reviewedAt:null,$phReviewerId?$reviewNotes:null,$deanStatus,$deanReviewerId,$deanReviewerId?$reviewedAt:null,$deanReviewerId?$reviewNotes:null,$vpaaStatus,$vpaaReviewerId,$vpaaReviewerId?$reviewedAt:null,$vpaaReviewerId?$reviewNotes:null]);
        $selfCount++;
    }
    $db->commit();
    echo json_encode(['ok'=>true,'period'=>$periodName,'participants'=>count($people),'goal_records'=>$goalCount,'self_evaluations'=>$selfCount,'backup'=>$backupFile],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
