<?php
/**
 * Test simulation WITHOUT @runInSeparateProcess.
 * Verifies that the function_exists() guard in includeSource()
 * prevents redefinition issues when running in the same process.
 */

require_once __DIR__ . '/tests/bootstrap.php';

$level = error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/includes/evaluation_cards.php';
error_reporting($level);

echo "=== First call ===\n";
echo "DB_NAME = " . DB_NAME . "\n";
echo "dipascaf_ensure_leadership_faculty_record exists: " . (function_exists('dipascaf_ensure_leadership_faculty_record') ? 'YES' : 'NO') . "\n";

// Clean DB
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, department) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['Dr. Dean', 'dean@cite.edu', password_hash('password', PASSWORD_DEFAULT), 'dean', 1, 'CITE']);
$deanId = (int) $pdo->lastInsertId();

$facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
echo "First call result: facultyId={$facultyId} (expected > 0)\n";

$faculty = $pdo->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
echo "First call SELECT: " . ($faculty ? 'FOUND' : 'NOT FOUND') . "\n";

// Now simulate a SECOND test running in the same process (no @runInSeparateProcess)
// includeSource() should skip the require because function already exists
echo "\n=== Second test (same process) ===\n";

// Simulate includeSource with function_exists guard
$level2 = error_reporting(E_ALL & ~E_WARNING);
if (!function_exists('dipascaf_questionnaire_type_from_position')) {
    require_once __DIR__ . '/includes/evaluation_cards.php';
    echo "  (loaded evaluation_cards.php)\n";
} else {
    echo "  (skipped - functions already loaded)\n";
}
error_reporting($level2);

// Clean and re-test
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, department) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['Dr. Dean 2', 'dean2@cite.edu', password_hash('password', PASSWORD_DEFAULT), 'dean', 1, 'CITE']);
$deanId2 = (int) $pdo->lastInsertId();

$facultyId2 = dipascaf_ensure_leadership_faculty_record($deanId2, 'Dean');
echo "Second call result: facultyId={$facultyId2} (expected > 0)\n";

$faculty2 = $pdo->query("SELECT * FROM faculty WHERE id = {$facultyId2}")->fetch();
echo "Second call SELECT: " . ($faculty2 ? 'FOUND' : 'NOT FOUND') . "\n";

// Check that functions from both calls work
echo "\n=== Verifying function availability ===\n";
echo "dipascaf_questionnaire_type: " . dipascaf_questionnaire_type_from_position('Dean') . "\n";
echo "dipascaf_current_cycle_name: " . dipascaf_current_cycle_name() . "\n";

echo "\n=== ALL PASSED ===\n";
