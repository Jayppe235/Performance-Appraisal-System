<?php
/**
 * Debug script — simulates @runInSeparateProcess execution.
 * Run from project root: php -d error_reporting=-1 _debug_separate_process.php
 */

require_once __DIR__ . '/tests/bootstrap.php';

$level = error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/includes/evaluation_cards.php';
error_reporting($level);

echo "=== DIAGNOSTICS ===\n";
echo 'DB_NAME    = ' . DB_NAME . "\n";
echo 'DB_HOST    = ' . DB_HOST . "\n";
echo 'DB_USER    = ' . DB_USER . "\n";
echo 'BASE_URL   = ' . BASE_URL . "\n";
echo 'PHP_SAPI   = ' . php_sapi_name() . "\n";
echo 'FUNCTIONS_EXIST: dipascaf_ensure_leadership_faculty_record = ' . (function_exists('dipascaf_ensure_leadership_faculty_record') ? 'YES' : 'NO') . "\n";
echo 'FUNCTIONS_EXIST: admin_one = ' . (function_exists('admin_one') ? 'YES' : 'NO') . "\n";
echo 'FUNCTIONS_EXIST: db = ' . (function_exists('db') ? 'YES' : 'NO') . "\n";

// Check what db() connects to
try {
    $pdo = db();
    echo 'db() database: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
    echo 'db() PDO class: ' . get_class($pdo) . "\n";
} catch (Exception $e) {
    echo 'db() FAILED: ' . $e->getMessage() . "\n";
}

// Create test PDO
$testPdo = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
echo 'testPdo database: ' . $testPdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

// Clean DB
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $table) {
    $testPdo->exec("TRUNCATE TABLE `{$table}`");
}
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "\nDB cleaned.\n";

// Insert dean user via test PDO
$testPdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, department) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['Dr. Dean', 'dean@cite.edu', password_hash('password', PASSWORD_DEFAULT), 'dean', 1, 'CITE']);
$deanId = (int) $testPdo->lastInsertId();
echo "Inserted dean ID: {$deanId} via testPdo\n";

// Verify via test PDO
$deanCheck = $testPdo->query("SELECT * FROM users WHERE id = {$deanId}")->fetch();
echo "testPdo finds dean: " . ($deanCheck ? $deanCheck['full_name'] : 'NO') . "\n";

// Verify via production db()
try {
    $deanCheck2 = db()->query("SELECT * FROM users WHERE id = {$deanId}")->fetch();
    echo "db() finds dean: " . ($deanCheck2 ? $deanCheck2['full_name'] . ' (id=' . $deanCheck2['id'] . ')' : 'NO') . "\n";
} catch (Exception $e) {
    echo "db() dean query FAILED: " . $e->getMessage() . "\n";
}

// Call the production function
$facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
echo "dipascaf_ensure_leadership_faculty_record returned: {$facultyId}\n";

// Query via test PDO
$row = $testPdo->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
echo "testPdo faculty query: " . ($row ? 'FOUND - ' . json_encode($row) : 'NOT FOUND') . "\n";

// Query via production db()
try {
    $row2 = db()->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
    echo "db() faculty query: " . ($row2 ? 'FOUND - ' . json_encode($row2) : 'NOT FOUND') . "\n";
} catch (Exception $e) {
    echo "db() faculty query FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
