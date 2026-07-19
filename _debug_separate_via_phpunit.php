<?php
/**
 * Simulate PHPUnit's @runInSeparateProcess exactly.
 * PHPUnit uses proc_open with PHP_BINARY -d error_reporting=-1 <script>
 * The script runs through the PHPUnit bootstrap and then the test.
 */

define('PHPUNIT_TESTSUITE', true);

// This is what PHPUnit does internally for @runInSeparateProcess
// It creates a PHP file with the test code and runs it via proc_open

$script = <<<'ENDSCRIPT'
<?php
// PHPUnit sets these globals for @runInSeparateProcess
$_SERVER['argv'] = ['phpunit', '--filter', 'testEnsureLeadershipFacultyRecordCreatesNewRecord'];
$_SERVER['argc'] = 2;

// PHPUnit also sets error_reporting via ini in phpunit.xml
error_reporting(-1);

require_once __DIR__ . '/tests/bootstrap.php';

$level = error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/includes/evaluation_cards.php';
error_reporting($level);

echo "DEBUG_START\n";
echo "error_reporting = " . error_reporting() . "\n";
echo "PHP_SAPI = " . php_sapi_name() . "\n";
echo "DB_NAME = " . DB_NAME . "\n";

// Connect
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "DB cleaned.\n";

$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, department) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['Dr. Dean', 'dean@cite.edu', password_hash('password', PASSWORD_DEFAULT), 'dean', 1, 'CITE']);
$deanId = (int) $pdo->lastInsertId();
echo "Inserted dean ID: {$deanId}\n";

$facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
echo "Function returned: {$facultyId}\n";

$row = $pdo->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
echo "PDO SELECT: " . ($row ? 'FOUND' : 'NOT FOUND') . "\n";
if ($row) {
    echo json_encode($row) . "\n";
}

// Also check via db()
try {
    $row2 = db()->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
    echo "db() SELECT: " . ($row2 ? 'FOUND' : 'NOT FOUND') . "\n";
} catch (Exception $e) {
    echo "db() ERROR: " . $e->getMessage() . "\n";
}

echo "DEBUG_END\n";
ENDSCRIPT;

// Write script to a temp file in project directory
$tmpFile = __DIR__ . '/_temp_separate_process_test.php';
file_put_contents($tmpFile, $script);

// Run exactly like PHPUnit would
$cmd = PHP_BINARY . ' -d error_reporting=-1 ' . escapeshellarg($tmpFile);
echo "Running: $cmd\n";

$spec = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
];

$proc = proc_open($cmd, $spec, $pipes);
if (is_resource($proc)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    
    echo "STDOUT:\n$stdout\n";
    if ($stderr) {
        echo "STDERR:\n$stderr\n";
    }
    echo "Exit code: $exitCode\n";
}

// Cleanup
unlink($tmpFile);
