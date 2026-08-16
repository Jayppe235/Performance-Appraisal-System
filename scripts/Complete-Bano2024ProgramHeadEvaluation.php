<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$period = '2024 APPRAISAL PERIOD';
$assignmentId = 253945; // Engr. Riza Acanto -> Engr. Edirlyn Baño
$incorrectAssignmentId = 516319; // Sir Mark's retired Program Head assignment
$db = db();

$assignment = admin_one(
    "SELECT pa.*, f.full_name AS evaluatee_name, u.full_name AS evaluator_name
       FROM peer_assignments pa
       JOIN faculty f ON f.id = pa.evaluatee_faculty_id
       JOIN users u ON u.id = pa.evaluator_user_id
      WHERE pa.id = ? AND pa.cycle_name = ? AND pa.assignment_type = 'program_head'",
    [$assignmentId, $period]
);
if ($assignment === null) {
    throw new RuntimeException('Ma’am Baño’s 2024 Program Head assignment was not found.');
}

$summary = ['mode' => $apply ? 'apply' : 'dry-run', 'period' => $period, 'assignment' => $assignment];
if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Unable to create backup directory.');
}
$backupPath = $backupDir . '/bano-2024-program-head-' . date('Ymd-His') . '.json';
$backup = [
    'created_at' => date(DATE_ATOM),
    'assignments' => admin_all('SELECT * FROM peer_assignments WHERE id IN (?, ?)', [$assignmentId, $incorrectAssignmentId]),
    'correct_assignment_results' => admin_all('SELECT * FROM pmas_form_b_category_results WHERE assignment_id = ?', [$assignmentId]),
    'incorrect_assignment_results' => admin_all('SELECT * FROM pmas_form_b_category_results WHERE assignment_id = ?', [$incorrectAssignmentId]),
];
file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

$db->prepare(
    "UPDATE peer_assignments
        SET status = 'pending', is_current = 1, effective_from = COALESCE(effective_from, NOW()),
            effective_to = NULL, replaced_by_assignment_id = NULL, replacement_reason = NULL,
            evaluator_name_snapshot = ?, evaluator_role_snapshot = 'program_head'
      WHERE id = ?"
)->execute([(string) $assignment['evaluator_name'], $assignmentId]);
$db->prepare(
    "UPDATE peer_assignments
        SET is_current = 0, effective_to = COALESCE(effective_to, NOW()),
            replaced_by_assignment_id = ?,
            replacement_reason = 'Historical correction: Sir Mark remains Dean; Ma’am Riza is the 2024 BSCpE Program Head evaluator.'
      WHERE id = ?"
)->execute([$assignmentId, $incorrectAssignmentId]);

$assignment['status'] = 'pending';
$assignment['is_current'] = 1;
$categories = dipascaf_form_b_categories();
$items = [];
foreach ($categories as $categoryIndex => $category) {
    $answers = [];
    foreach ($category['questions'] as $questionIndex => $question) {
        $answers[(string) $question['id']] = 3 + (($categoryIndex + $questionIndex + 1) % 3);
    }
    $items[] = [
        'category_id' => (int) $category['id'],
        'answers' => $answers,
        'evidence' => [],
        'behavioral_evidence' => 'SAMPLE DATA: Demonstrates reliable instruction, timely documentation, and constructive collaboration within the BSCpE program.',
        'reason_for_rating' => 'SAMPLE DATA: Continue strengthening outcome-based assessment documentation and sharing instructional practices with colleagues.',
    ];
}
dipascaf_submit_category_results(
    $assignment,
    (int) $assignment['evaluator_user_id'],
    'b',
    ['sample_data' => true, 'categories' => $items],
    $period
);

$actor = (int) (admin_one("SELECT id FROM users WHERE role = 'admin_hr' AND is_active = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
$db->prepare('INSERT INTO activity_logs(user_id, description) VALUES (?, ?)')->execute([
    $actor,
    'Completed Engr. Edirlyn C. Baño’s 2024 Program Head evaluation under Engr. Riza Jean M. Acanto and retained Sir Mark as Dean only.',
]);

$verification = admin_one(
    "SELECT pa.id, pa.status, pa.is_current, u.full_name AS evaluator,
            ROUND(SUM(r.weighted_score), 2) AS final_score, COUNT(r.id) AS category_results
       FROM peer_assignments pa
       JOIN users u ON u.id = pa.evaluator_user_id
       LEFT JOIN pmas_form_b_category_results r ON r.assignment_id = pa.id
      WHERE pa.id = ? GROUP BY pa.id",
    [$assignmentId]
);
echo json_encode(['applied' => true, 'backup' => $backupPath, 'verification' => $verification], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
