<?php
/**
 * Diagnostic: Run inside PHPUnit's environment to check db() vs self::db().
 * Simulates exactly what a test does.
 */
require_once __DIR__ . '/tests/bootstrap.php';

$level = error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/includes/evaluation_cards.php';
error_reporting($level);

echo "=== DB CONNECTION DIAGNOSTIC ===\n";
echo "DB_NAME = '" . DB_NAME . "'\n";

// Check what production db() connects to
$prodDb = db();
$prodDbName = $prodDb->query('SELECT DATABASE()')->fetchColumn();
echo "db() connects to: '{$prodDbName}'\n";

// Check test PDO
$testPdo = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$testDbName = $testPdo->query('SELECT DATABASE()')->fetchColumn();
echo "self::db() connects to: '{$testDbName}'\n";

// They should be the same
echo "Match: " . ($prodDbName === $testDbName ? 'YES' : 'NO - MISMATCH!') . "\n";

// Now simulate a test
echo "\n=== SIMULATING TEST ===\n";
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $t) {
    $testPdo->exec("TRUNCATE TABLE `{$t}`");
}
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "DB cleaned.\n";

// Insert a dean
$testPdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, department) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['Dr. Dean', 'dean@cite.edu', password_hash('password', PASSWORD_DEFAULT), 'dean', 1, 'CITE']);
$deanId = (int) $testPdo->lastInsertId();
echo "Inserted dean ID: {$deanId}\n";

// Check if self::db() and db() see the same data
$viaTestPdo = $testPdo->query("SELECT * FROM users WHERE id = {$deanId}")->fetch();
echo "testPdo sees dean: " . ($viaTestPdo ? $viaTestPdo['full_name'] : 'NO') . "\n";

$viaDb = db()->query("SELECT * FROM users WHERE id = {$deanId}")->fetch();
echo "db() sees dean: " . ($viaDb ? $viaDb['full_name'] . ' (id=' . $viaDb['id'] . ')' : 'NO') . "\n";

// Now call the production function
$facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
echo "facultyId = {$facultyId}\n";

// Check via test PDO
$viaTestPdo2 = $testPdo->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
echo "testPdo sees faculty: " . ($viaTestPdo2 ? $viaTestPdo2['full_name'] : 'NOT FOUND') . "\n";

// Check via db()
$viaDb2 = db()->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
echo "db() sees faculty: " . ($viaDb2 ? $viaDb2['full_name'] : 'NOT FOUND') . "\n";

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
