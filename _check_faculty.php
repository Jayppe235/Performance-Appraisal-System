<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

echo "=== FACULTY DEPARTMENTS & PROGRAMS ===\n";
$stmt = db()->query('SELECT id, full_name, department, program_code FROM faculty ORDER BY department');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== PEER ASSIGNMENTS BY DEPARTMENT ===\n";
$stmt = db()->query(
    'SELECT f.department, pa.status, COUNT(*) as cnt
     FROM peer_assignments pa
     JOIN faculty f ON f.id = pa.evaluatee_faculty_id
     GROUP BY f.department, pa.status'
);
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== SUBMITTED ASSIGNMENTS ===\n";
$stmt = db()->query(
    'SELECT pa.id, pa.status, pa.submitted_at, f.full_name, f.department
     FROM peer_assignments pa
     JOIN faculty f ON f.id = pa.evaluatee_faculty_id
     WHERE pa.status = "submitted"'
);
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";
echo "Count: " . count($r ?? []) . "\n";

echo "\n=== ALL DEPARTMENTS (from departments table) ===\n";
$stmt = db()->query('SELECT id, department_code, department_name FROM departments');
foreach ($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== MIGRATIONS APPLIED ===\n";
// Check if migration files have been run
$stmt = db()->query("SHOW TABLES LIKE 'pmas_form_b_category_results'");
$r = $stmt->fetchAll();
echo "form_b_category_results exists: " . (count($r) > 0 ? "YES" : "NO") . "\n";

$stmt = db()->query("SHOW TABLES LIKE 'pmas_form_a_category_results'");
$r = $stmt->fetchAll();
echo "form_a_category_results exists: " . (count($r) > 0 ? "YES" : "NO") . "\n";
