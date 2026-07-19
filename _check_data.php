<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

echo "=== DEPARTMENTS ===\n";
$stmt = db()->query('SELECT id, department_code, department_name FROM departments');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== PROGRAMS ===\n";
$stmt = db()->query('SELECT id, program_code, program_name, department_id FROM programs');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== FACULTY ===\n";
$stmt = db()->query('SELECT id, full_name, department, program_code, position_title FROM faculty WHERE is_archived = 0');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== PEER ASSIGNMENTS ===\n";
$stmt = db()->query('SELECT id, cycle_name, evaluator_user_id, evaluatee_faculty_id, assignment_type, status FROM peer_assignments');
$rows = $stmt->fetchAll();
echo "Count: " . count($rows) . "\n";
foreach ($rows as $r) echo json_encode($r) . "\n";

echo "\n=== FORM A CATEGORY RESULTS ===\n";
$stmt = db()->query('SELECT id, evaluatee_faculty_id, category_id, average_rating, status FROM pmas_form_a_category_results');
$rows = $stmt->fetchAll();
echo "Count: " . count($rows) . "\n";
foreach ($rows as $r) echo json_encode($r) . "\n";

echo "\n=== FORM B CATEGORY RESULTS ===\n";
$stmt = db()->query('SELECT id, evaluatee_faculty_id, category_id, average_rating, status FROM pmas_form_b_category_results');
$rows = $stmt->fetchAll();
echo "Count: " . count($rows) . "\n";
foreach ($rows as $r) echo json_encode($r) . "\n";

echo "\n=== AI INSIGHTS ===\n";
$stmt = db()->query('SELECT id, faculty_id, weak_area, strength_area FROM ai_insights');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== INTERVENTION PLANS ===\n";
$stmt = db()->query('SELECT id, faculty_id, weak_area, recommendation, status FROM intervention_plans');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== USERS ===\n";
$stmt = db()->query('SELECT id, full_name, email, role FROM users');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";
