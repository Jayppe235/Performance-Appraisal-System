<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$period = '2027 Evaluation Period';
$userId = 138;
$facultyId = 136;
$db = db();

$person = admin_one(
    'SELECT u.id, u.full_name, u.role, u.department, f.id AS faculty_id, f.position_title
       FROM users u JOIN faculty f ON f.user_id = u.id
      WHERE u.id = ? AND f.id = ? LIMIT 1',
    [$userId, $facultyId]
);
if (!$person || stripos((string) $person['full_name'], 'HONEYLYN M. MAHINAY') === false) {
    throw new RuntimeException('Honeylyn M. Mahinay was not found at the expected IDs. Nothing was changed.');
}

$assignments = admin_all(
    "SELECT pa.*, u.full_name AS evaluator_name
       FROM peer_assignments pa
       JOIN users u ON u.id = pa.evaluator_user_id
      WHERE pa.cycle_name = ? AND pa.evaluatee_faculty_id = ?
        AND COALESCE(pa.is_archived, 0) = 0 AND pa.is_current = 1
        AND pa.status IN ('pending', 'in_progress', 'reopened')
      ORDER BY pa.id",
    [$period, $facultyId]
);
$selfAssignment = admin_one(
    "SELECT * FROM peer_assignments
      WHERE cycle_name = ? AND evaluator_user_id = ? AND evaluatee_faculty_id = ?
        AND assignment_type = 'self' AND COALESCE(is_archived, 0) = 0 AND is_current = 1
      ORDER BY id DESC LIMIT 1",
    [$period, $userId, $facultyId]
);
if (!$selfAssignment) {
    throw new RuntimeException('Honeylyn has no active 2027 self-evaluation assignment. Nothing was changed.');
}

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'employee' => $person['full_name'],
    'period' => $period,
    'sample_data' => true,
    'pending_assignments' => count($assignments),
    'assignment_ids' => array_map(static fn(array $row): int => (int) $row['id'], $assignments),
    'will_submit_self_evaluation' => true,
];
if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Cannot create the backup directory.');
}
$ids = array_map(static fn(array $row): int => (int) $row['id'], $assignments);
$marks = $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
$backup = [
    'created_at' => date(DATE_ATOM),
    'employee' => $person,
    'assignments' => $assignments,
    'form_a_results' => $ids ? admin_all("SELECT * FROM pmas_form_a_category_results WHERE assignment_id IN ($marks)", $ids) : [],
    'self_evaluations' => admin_all('SELECT * FROM pmas_self_evaluations WHERE user_id = ? AND evaluation_period = ?', [$userId, $period]),
];
$backupPath = $backupDir . '/honeylyn-2027-sample-before-' . date('Ymd-His') . '.json';
file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$evidence = [
    'SAMPLE DATA: Provides clear academic direction and follows through on department commitments.',
    'SAMPLE DATA: Coordinates faculty concerns promptly and communicates decisions professionally.',
    'SAMPLE DATA: Uses documented results to guide quality-improvement priorities.',
    'SAMPLE DATA: Supports collaborative planning, mentoring, and learner-focused initiatives.',
];
$development = [
    'SAMPLE DATA: Continue strengthening the use of measurable department dashboards.',
    'SAMPLE DATA: Expand delegation and succession-planning practices across programs.',
    'SAMPLE DATA: Document effective innovations for wider institutional sharing.',
];

$completed = [];
foreach ($assignments as $assignmentIndex => $assignment) {
    $categories = dipascaf_form_a_categories();
    $payload = [];
    foreach ($categories as $categoryIndex => $category) {
        $answers = [];
        foreach ($category['questions'] as $questionIndex => $question) {
            $answers[(string) $question['id']] = 4 + (($assignmentIndex + $categoryIndex + $questionIndex) % 2);
        }
        $payload[(string) $category['id']] = [
            'category_id' => (int) $category['id'],
            'answers' => $answers,
            'evidence' => [],
            'behavioral_evidence' => $evidence[($assignmentIndex + $categoryIndex) % count($evidence)],
            'reason_for_rating' => $development[($assignmentIndex + $categoryIndex) % count($development)],
            'recommendation' => 'SAMPLE DATA: Sustain strong academic leadership while completing the identified development action.',
        ];
    }
    dipascaf_submit_category_results($assignment, (int) $assignment['evaluator_user_id'], 'a', $payload, $period);
    $completed[] = (int) $assignment['id'];
}

$rating = 4.45;
$employeeInfo = [
    'name' => $person['full_name'],
    'positionTitle' => $person['position_title'] ?: 'Dean',
    'department' => $person['department'],
    'appraisalPeriod' => $period,
    'sampleData' => true,
];
$answers = [
    'achievedGoals' => [
        ['goals' => 'Academic quality and faculty development', 'accomplishment' => 'SAMPLE DATA: Conducted faculty consultations, monitored academic deliverables, and documented follow-up actions.', 'approvedGoal' => true],
        ['goals' => 'Department planning and learner support', 'accomplishment' => 'SAMPLE DATA: Coordinated department priorities and learner-support interventions with program teams.', 'approvedGoal' => true],
    ],
    'otherAccomplishments' => 'SAMPLE DATA: Supported institutional committees, accreditation preparation, and cross-department coordination.',
    'unmetGoalsReason' => 'SAMPLE DATA: A small number of external activities were rescheduled because of partner availability.',
    'personalStrengths' => 'SAMPLE DATA: Collaborative leadership, sound judgment, professional communication, and dependable follow-through.',
    'overallSelfRating' => 'Exceeds Expectations',
    'ratingBasis' => 'SAMPLE DATA: The rating reflects completed department outputs, documented coordination, and sustained support for faculty and learners.',
    'furtherContribution' => 'SAMPLE DATA: Strengthen department analytics, mentoring systems, and evidence-based quality improvement.',
    'performanceOutputs' => [],
    'performanceFactorsScore' => $rating,
    'appraiseeStrengths' => 'SAMPLE DATA: Academic leadership, collaboration, responsiveness, and commitment to quality.',
    'improvementPlans' => [[
        'area' => 'Department analytics and succession planning',
        'actionPlan' => 'SAMPLE DATA: Maintain a monthly performance dashboard and mentor designated faculty leaders.',
        'timeFrame' => 'Within 6 months',
    ]],
    'comments' => 'SAMPLE DATA: Submitted demonstration self-evaluation for the 2027 period.',
    '_sample_data' => true,
    '_sample_period' => $period,
];
$json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$db->prepare(
    "INSERT INTO pmas_self_evaluations
        (assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,
         employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,
         performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at)
     VALUES (?,?,?,?,?,'form_a_dean',1,?,?,?,?,?,?,?,?,?,'submitted',NOW())
     ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),role=VALUES(role),department=VALUES(department),
         evaluation_period=VALUES(evaluation_period),form_type=VALUES(form_type),questionnaire_revision=1,
         employee_info=VALUES(employee_info),answers_json=VALUES(answers_json),raw_payload_json=VALUES(raw_payload_json),
         form_payload_json=VALUES(form_payload_json),questionnaire_snapshot=VALUES(questionnaire_snapshot),
         performance_outputs_score=VALUES(performance_outputs_score),performance_factors_score=VALUES(performance_factors_score),
         overall_rating=VALUES(overall_rating),performance_level=VALUES(performance_level),status='submitted',submitted_at=NOW(),
         reopened_at=NULL,reopened_by=NULL,reopened_reason=NULL"
)->execute([
    (int) $selfAssignment['id'], $userId, 'dean', $person['department'], $period,
    json_encode($employeeInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $json, $json,
    json_encode(['sample_data' => true, 'period' => $period], JSON_THROW_ON_ERROR),
    json_encode(['form_type' => 'form_a_dean', 'revision' => 1, 'period' => $period], JSON_THROW_ON_ERROR),
    $rating, $rating, $rating, 'Exceeds Expectations',
]);

$summary['applied'] = true;
$summary['backup'] = $backupPath;
$summary['completed_assignment_ids'] = $completed;
$summary['remaining_assignments'] = admin_count(
    "SELECT COUNT(*) FROM peer_assignments WHERE cycle_name = ? AND evaluatee_faculty_id = ?
       AND COALESCE(is_archived, 0) = 0 AND is_current = 1 AND status IN ('pending','in_progress','reopened')",
    [$period, $facultyId]
);
$summary['submitted_self_evaluations'] = admin_count(
    "SELECT COUNT(*) FROM pmas_self_evaluations WHERE user_id = ? AND evaluation_period = ? AND status = 'submitted'",
    [$userId, $period]
);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
