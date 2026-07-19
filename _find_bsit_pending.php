<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// Find active open periods
$activePeriods = $pdo->query("SELECT period_name FROM appraisal_periods WHERE status = 'open'")->fetchAll(PDO::FETCH_COLUMN);
echo "Active periods: " . implode(', ', $activePeriods) . "\n\n";

// Find all pending assignments for BSIT faculty in active cycles
$stmt = $pdo->prepare("
    SELECT pa.id, pa.assignment_type, pa.evaluator_role, pa.status, 
           f.full_name AS evaluatee, f.program_code,
           u.full_name AS evaluator_name, u.email AS evaluator_email, u.role AS evaluator_user_role
    FROM peer_assignments pa
    JOIN faculty f ON f.id = pa.evaluatee_faculty_id
    LEFT JOIN users u ON u.id = pa.evaluator_user_id
    WHERE f.program_code = 'BSIT'
      AND pa.status = 'pending'
      AND COALESCE(pa.is_archived, 0) = 0
      AND pa.cycle_name IN (" . implode(',', array_fill(0, count($activePeriods), '?')) . ")
    ORDER BY f.full_name, pa.assignment_type
");
$stmt->execute($activePeriods);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($pending) . " pending BSIT assignments:\n\n";
foreach ($pending as $p) {
    echo "ID={$p['id']} | {$p['evaluatee']} | Type={$p['assignment_type']} | Evaluator={$p['evaluator_name']} ({$p['evaluator_email']}) Role={$p['evaluator_role']}\n";
}
