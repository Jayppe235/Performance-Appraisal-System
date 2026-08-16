<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$apply = in_array('--apply', $argv, true);
$period = '2024 APPRAISAL PERIOD';
$db = db();

$targets = [
    ['assignment_id' => 3064, 'name' => 'Hazel Joy M. Gadingan', 'kind' => 'self', 'score' => 3.82],
    ['assignment_id' => 3066, 'name' => 'Nero L. Hontiveros', 'kind' => 'self', 'score' => 3.48],
    ['assignment_id' => 383203, 'name' => 'Nero L. Hontiveros', 'kind' => 'peer', 'score' => 3.97],
    ['assignment_id' => 3068, 'name' => 'Ryan L. Nambong', 'kind' => 'self', 'score' => 3.91],
];

$resolved = [];
foreach ($targets as $target) {
    $assignment = admin_one(
        "SELECT pa.*, f.full_name AS evaluatee_name, f.department, f.user_id AS evaluatee_user_id,
                u.full_name AS evaluator_name
         FROM peer_assignments pa
         JOIN faculty f ON f.id=pa.evaluatee_faculty_id
         JOIN users u ON u.id=pa.evaluator_user_id
         WHERE pa.id=:id AND pa.cycle_name=:period AND COALESCE(pa.is_archived,0)=0",
        ['id'=>$target['assignment_id'], 'period'=>$period]
    );
    if (!$assignment || strcasecmp(trim((string)$assignment['evaluatee_name']), $target['name']) !== 0) {
        throw new RuntimeException("Assignment {$target['assignment_id']} did not match {$target['name']}; stopped without changes.");
    }
    if ((string)$assignment['assignment_type'] !== $target['kind']) {
        throw new RuntimeException("Assignment {$target['assignment_id']} is not the expected {$target['kind']} evaluation.");
    }
    $target['assignment'] = $assignment;
    $resolved[] = $target;
}

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'period' => $period,
    'sample_data' => true,
    'targets' => array_map(static fn(array $row): array => [
        'assignment_id'=>$row['assignment_id'], 'evaluatee'=>$row['name'],
        'evaluation'=>$row['kind'], 'sample_score'=>$row['score'],
        'current_status'=>$row['assignment']['status'],
    ], $resolved),
];

if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Unable to create the private backup directory.');
}
$backupPath = $backupDir . '/bsit-pending-samples-' . date('Ymd-His') . '.json';
$backup = ['created_at'=>date(DATE_ATOM), 'period'=>$period, 'assignments'=>[], 'self_evaluations'=>[], 'form_b_results'=>[]];
foreach ($resolved as $row) {
    $id = (int)$row['assignment_id'];
    $backup['assignments'][] = $row['assignment'];
    $backup['self_evaluations'] = array_merge($backup['self_evaluations'], admin_all('SELECT * FROM pmas_self_evaluations WHERE assignment_id=:id', ['id'=>$id]));
    $backup['form_b_results'] = array_merge($backup['form_b_results'], admin_all('SELECT * FROM pmas_form_b_category_results WHERE assignment_id=:id', ['id'=>$id]));
}
if (file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Unable to write the pre-change backup.');
}

try {
    // The shared Form B submission service owns its transaction. Submit those
    // records first, then write all self-evaluations in one transaction below.
    foreach ($resolved as $index => $row) {
        $assignment = $row['assignment'];
        if ($row['kind'] !== 'peer') continue;
        $payload = ['sample_data'=>true, 'categories'=>[]];
        foreach (dipascaf_form_b_categories() as $categoryIndex => $category) {
                $answers = [];
                foreach (($category['questions'] ?? []) as $questionIndex => $question) {
                    $answers[(string)$question['id']] = 3 + (($categoryIndex + $questionIndex + $index) % 3);
                }
                $payload['categories'][] = [
                    'category_id'=>(int)$category['id'],
                    'answers'=>$answers,
                    'evidence'=>[],
                    'behavioral_evidence'=>'SAMPLE DATA: Demonstrates dependable work performance and constructive collaboration.',
                    'reason_for_rating'=>'SAMPLE DATA entered for demonstration and evaluation-monitor validation only.',
                    'recommendation'=>'SAMPLE DATA: Continue coaching in the lowest-rated competency areas.',
                ];
        }
        dipascaf_submit_category_results($assignment, (int)$assignment['evaluator_user_id'], 'b', $payload, $period);
    }

    $db->beginTransaction();
    foreach ($resolved as $row) {
        if ($row['kind'] !== 'self') continue;
        $assignment = $row['assignment'];
        $score = (float)$row['score'];
        $role = (string)($assignment['evaluator_role'] ?? 'teacher');
        $level = $score >= 4.5 ? 'Outstanding' : ($score >= 3.5 ? 'Very Satisfactory' : 'Satisfactory');
        $answers = [
            '_sample_data'=>true,
            '_notice'=>'SAMPLE DATA entered for demonstration and evaluation-monitor validation only.',
            'performance_outputs'=>['sample_rating'=>$score],
            'performance_factors'=>['sample_rating'=>$score],
        ];
        $employee = [
            'name'=>$row['name'], 'department'=>(string)$assignment['department'],
            'appraisalPeriod'=>$period, 'sampleData'=>true,
        ];
        $db->prepare(
            "INSERT INTO pmas_self_evaluations
             (assignment_id,user_id,role,department,evaluation_period,form_type,questionnaire_revision,
              employee_info,answers_json,raw_payload_json,form_payload_json,questionnaire_snapshot,
              performance_outputs_score,performance_factors_score,overall_rating,performance_level,status,submitted_at)
             VALUES (?,?,?,?,?,'self',1,?,?,?,?,?,?,?,?,?,'submitted',NOW())
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),role=VALUES(role),department=VALUES(department),
              evaluation_period=VALUES(evaluation_period),form_type=VALUES(form_type),
              employee_info=VALUES(employee_info),answers_json=VALUES(answers_json),
              raw_payload_json=VALUES(raw_payload_json),form_payload_json=VALUES(form_payload_json),
              performance_outputs_score=VALUES(performance_outputs_score),performance_factors_score=VALUES(performance_factors_score),
              overall_rating=VALUES(overall_rating),performance_level=VALUES(performance_level),status='submitted',submitted_at=NOW()"
        )->execute([
            (int)$assignment['id'], (int)$assignment['evaluatee_user_id'], $role,
            (string)$assignment['department'], $period,
            json_encode($employee, JSON_THROW_ON_ERROR), json_encode($answers, JSON_THROW_ON_ERROR),
            json_encode(['sample_data'=>true,'answers'=>$answers], JSON_THROW_ON_ERROR),
            json_encode(['sample_data'=>true], JSON_THROW_ON_ERROR),
            json_encode(['sample_data'=>true,'questionnaire'=>'demonstration snapshot'], JSON_THROW_ON_ERROR),
            $score, $score, $score, $level,
        ]);
        $db->prepare("UPDATE peer_assignments SET status='submitted',submitted_at=COALESCE(submitted_at,NOW()) WHERE id=?")
            ->execute([(int)$assignment['id']]);
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

$summary['backup'] = $backupPath;
$summary['applied'] = true;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
