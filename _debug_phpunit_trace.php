<?php
/**
 * Trace exactly what PHPUnit does — replicates includeSource + insertUser + dipascaf_ensure_leadership_faculty_record
 */
declare(strict_types=1);

// Load bootstrap (same as PHPUnit does)
require_once __DIR__ . '/tests/bootstrap.php';

echo "STEP 1: After bootstrap\n";
echo "  DB_NAME = " . DB_NAME . "\n";
echo "  get_defined_constants(true)['user']['DB_NAME'] = " . (defined('DB_NAME') ? DB_NAME : 'NOT DEFINED') . "\n";

// Simulate includeSource()
$level = error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/includes/evaluation_cards.php';
error_reporting($level);

echo "\nSTEP 2: After includeSource()\n";
echo "  DB_NAME = " . DB_NAME . "\n";
echo "  db() connected to: " . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";

// Simulate cleanDb()
$testPdo = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $table) {
    $testPdo->exec("TRUNCATE TABLE `{$table}`");
}
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\nSTEP 3: After cleanDb()\n";
echo "  self::db() connected to: " . $testPdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

// Simulate insertUser
$stmt = $testPdo->prepare(
    'INSERT INTO users (full_name, email, password_hash, role, is_active, department, program, phone)
     VALUES (:full_name, :email, :password_hash, :role, :is_active, :department, :program, :phone)'
);
$stmt->execute([
    'full_name' => 'Dr. Dean',
    'email' => 'dean@cite.edu',
    'password_hash' => password_hash('password', PASSWORD_DEFAULT),
    'role' => 'dean',
    'is_active' => 1,
    'department' => 'CITE',
    'program' => 'BSCS',
    'phone' => '09170000000',
]);
$deanId = (int) $testPdo->lastInsertId();

echo "\nSTEP 4: After insertUser\n";
echo "  deanId = $deanId\n";

// Check via self::db()
$row = $testPdo->query("SELECT id, email, role, is_active FROM users WHERE id = {$deanId}")->fetch();
echo "  self::db() finds user: " . ($row ? "YES (email={$row['email']}, role={$row['role']})" : "NO") . "\n";

// Check via production db()
$row2 = db()->query("SELECT id, email, role, is_active FROM users WHERE id = {$deanId}")->fetch();
echo "  db() finds user: " . ($row2 ? "YES (email={$row2['email']}, role={$row2['role']})" : "NO") . "\n";

// Check via admin_one()
$row3 = admin_one(
    'SELECT id, full_name, email, phone, department, program, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
    ['id' => $deanId]
);
echo "  admin_one() finds user: " . ($row3 !== null ? "YES (email={$row3['email']})" : "NO (null)") . "\n";

// Now try dipascaf_ensure_leadership_faculty_record
$facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
echo "\nSTEP 5: dipascaf_ensure_leadership_faculty_result\n";
echo "  facultyId = " . ($facultyId ?: '0 (FAIL)') . "\n";

if ($facultyId > 0) {
    $faculty = db()->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
    echo "  Faculty record: " . ($faculty ? "YES (name={$faculty['full_name']})" : "NO (not found)") . "\n";
}

// Cleanup
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $table) {
    $testPdo->exec("TRUNCATE TABLE `{$table}`");
}
$testPdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\n=== DONE ===\n";
