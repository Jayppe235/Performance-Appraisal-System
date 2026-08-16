<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$period = '2024 APPRAISAL PERIOD';
$department = 'College of Education';
$db = db();

$assignments = admin_all(
    "SELECT pa.*, f.full_name AS evaluatee_name, f.user_id AS evaluatee_user_id,
            f.program_code, u.full_name AS evaluator_name,
            COALESCE(epp.department_snapshot, f.department) AS period_department,
            COALESCE(epp.program_snapshot, f.program_code) AS period_program
       FROM peer_assignments pa
       JOIN faculty f ON f.id = pa.evaluatee_faculty_id
       JOIN users u ON u.id = pa.evaluator_user_id
       LEFT JOIN users eu ON eu.id = f.user_id
       LEFT JOIN appraisal_periods ap ON ap.period_name = pa.cycle_name
       LEFT JOIN evaluation_period_participation epp
              ON epp.evaluation_period_id = ap.id AND epp.user_id = eu.id
      WHERE pa.cycle_name = ?
        AND COALESCE(pa.is_archived, 0) = 0
        AND pa.is_current = 1
        AND pa.status IN ('pending', 'in_progress', 'reopened')
        AND COALESCE(epp.department_snapshot, f.department) = ?
      ORDER BY period_program, pa.id",
    [$period, $department]
);

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'period' => $period,
    'department' => $department,
    'sample_data' => true,
    'remaining_count' => count($assignments),
    'by_type' => [],
];
foreach ($assignments as $assignment) {
    $key = $assignment['assignment_type'] . '/' . $assignment['questionnaire_type'];
    $summary['by_type'][$key] = ($summary['by_type'][$key] ?? 0) + 1;
}
if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Cannot create backup directory.');
}
$ids = array_map(static fn(array $row): int => (int) $row['id'], $assignments);
$marks = $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
$backup = [
    'created_at' => date(DATE_ATOM),
    'period' => $period,
    'department' => $department,
    'assignments' => $assignments,
    'form_a' => $ids ? admin_all("SELECT * FROM pmas_form_a_category_results WHERE assignment_id IN ($marks)", $ids) : [],
    'form_b' => $ids ? admin_all("SELECT * FROM pmas_form_b_category_results WHERE assignment_id IN ($marks)", $ids) : [],
];
$backupPath = $backupDir . '/ced-2024-sample-completion-' . date('Ymd-His') . '.json';
file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$evidence = [
    'Consistently prepares organized lessons and aligns activities with stated learning outcomes.',
    'Demonstrates dependable classroom management and responds constructively to learner needs.',
    'Collaborates with colleagues on instructional materials, assessment, and student support.',
    'Uses assessment evidence to improve instruction and provide timely learner feedback.',
    'Participates actively in program initiatives and completes assigned responsibilities on time.',
];
$improvements = [
    'Continue expanding differentiated learning strategies for students with varied needs.',
    'Use additional digital assessment tools to strengthen timely feedback and progress monitoring.',
    'Document teaching innovations and share effective practices during program meetings.',
    'Increase participation in research, extension, and professional development activities.',
    'Strengthen the use of measurable evidence when reviewing course and learner outcomes.',
];

$completed = [];
$averages = [];
foreach ($assignments as $assignmentIndex => $assignment) {
    $form = $assignment['questionnaire_type'] === 'admin' ? 'a' : 'b';
    $categories = $form === 'a' ? dipascaf_form_a_categories() : dipascaf_form_b_categories();
    $items = [];
    $ratingTotal = 0;
    $ratingCount = 0;

    foreach ($categories as $categoryIndex => $category) {
        $answers = [];
        foreach ($category['questions'] as $questionIndex => $question) {
            // A deterministic 3/4/5 distribution gives each record credible strengths
            // and improvement areas while keeping the demonstration data satisfactory.
            $rating = 3 + (($assignmentIndex * 2 + $categoryIndex + $questionIndex) % 3);
            $answers[(string) $question['id']] = $rating;
            $ratingTotal += $rating;
            $ratingCount++;
        }
        $item = [
            'category_id' => (int) $category['id'],
            'answers' => $answers,
            'evidence' => [],
            'behavioral_evidence' => 'SAMPLE DATA: ' . $evidence[($assignmentIndex + $categoryIndex) % count($evidence)],
            'reason_for_rating' => 'SAMPLE DATA: ' . $improvements[($assignmentIndex + $categoryIndex) % count($improvements)],
        ];
        if ($form === 'a') {
            $items[(string) $category['id']] = $item;
        } else {
            $items[] = $item;
        }
    }

    dipascaf_submit_category_results(
        $assignment,
        (int) $assignment['evaluator_user_id'],
        $form,
        $form === 'a' ? $items : ['sample_data' => true, 'categories' => $items],
        $period
    );
    $completed[] = (int) $assignment['id'];
    $averages[] = $ratingCount ? round($ratingTotal / $ratingCount, 2) : 0;
}

$actor = (int) (admin_one("SELECT id FROM users WHERE role = 'admin_hr' AND is_active = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
$db->prepare('INSERT INTO activity_logs(user_id, description) VALUES (?, ?)')->execute([
    $actor,
    'Completed the remaining College of Education evaluations for 2024 with clearly labeled mixed sample data.',
]);

$summary['applied'] = true;
$summary['backup'] = $backupPath;
$summary['completed_assignment_ids'] = $completed;
$summary['sample_average_range'] = $averages ? [min($averages), max($averages)] : [];
$summary['remaining_after'] = admin_count(
    "SELECT COUNT(*)
       FROM peer_assignments pa
       JOIN faculty f ON f.id = pa.evaluatee_faculty_id
       LEFT JOIN users eu ON eu.id = f.user_id
       LEFT JOIN appraisal_periods ap ON ap.period_name = pa.cycle_name
       LEFT JOIN evaluation_period_participation epp
              ON epp.evaluation_period_id = ap.id AND epp.user_id = eu.id
      WHERE pa.cycle_name = ?
        AND COALESCE(pa.is_archived, 0) = 0
        AND pa.is_current = 1
        AND pa.status IN ('pending', 'in_progress', 'reopened')
        AND COALESCE(epp.department_snapshot, f.department) = ?",
    [$period, $department]
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
