<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$assignment = admin_one(
    "SELECT pa.*, ev.full_name evaluator_name, ef.full_name evaluatee_name
     FROM peer_assignments pa
     JOIN users ev ON ev.id=pa.evaluator_user_id
     JOIN faculty ef ON ef.id=pa.evaluatee_faculty_id
     WHERE pa.id=75 AND ev.id=31 AND ef.user_id=34
       AND pa.cycle_name='2024 APPRAISAL PERIOD'
       AND pa.questionnaire_type='admin' AND COALESCE(pa.is_archived,0)=0"
);
if ($assignment === null) {
    throw new RuntimeException('The expected Mark-to-Hazel Form A assignment was not found.');
}

$existing = admin_one(
    'SELECT COUNT(*) total FROM pmas_form_a_category_results WHERE assignment_id=:id AND COALESCE(is_archived,0)=0',
    ['id'=>(int)$assignment['id']]
);
if ((int)($existing['total'] ?? 0) > 0) {
    echo json_encode(['status'=>'unchanged','message'=>'Active Form A sample data already exists.'], JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

$payload = [];
foreach (dipascaf_form_a_categories() as $categoryIndex => $category) {
    $answers = [];
    foreach (($category['questions'] ?? []) as $questionIndex => $question) {
        // Deterministic satisfactory-to-very-satisfactory sample ratings.
        $answers[(string)$question['id']] = (($categoryIndex + $questionIndex) % 5 === 0) ? 5 : 4;
    }
    $payload[(string)$category['id']] = [
        'answers'=>$answers,
        'evidence'=>[],
        'behavioral_evidence'=>'SAMPLE DATA: Demonstrates consistent Program Head leadership, coordination, and timely follow-through.',
        'reason_for_rating'=>'SAMPLE DATA entered for system demonstration and report validation only.',
    ];
}

$result = dipascaf_submit_category_results(
    $assignment,
    (int)$assignment['evaluator_user_id'],
    'a',
    $payload,
    (string)$assignment['cycle_name']
);

echo json_encode([
    'status'=>'submitted',
    'assignment_id'=>(int)$assignment['id'],
    'evaluator'=>$assignment['evaluator_name'],
    'evaluatee'=>$assignment['evaluatee_name'],
    'form'=>'Form A',
    'sample_data'=>true,
    'total_weighted_score'=>$result['total_weighted_score'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
