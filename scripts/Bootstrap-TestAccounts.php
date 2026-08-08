<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/credentials.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--create', '--remove'], true)) {
    fwrite(STDERR, "Usage: php scripts/Bootstrap-TestAccounts.php --create|--remove\n");
    exit(2);
}
if (PMAS_ENV !== 'production') {
    fwrite(STDERR, "This command is restricted to an explicitly configured production environment.\n");
    exit(2);
}

$codes = [
    'admin_hr' => 9900001,
    'vpaa' => 9900002,
    'dean' => 9900003,
    'program_head' => 9900004,
    'teacher' => 9900005,
];
$db = db();

if ($mode === '--remove') {
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $db->prepare("DELETE FROM users WHERE user_code IN ({$placeholders})");
    $stmt->execute(array_values($codes));
    echo "Removed {$stmt->rowCount()} unused temporary accounts.\n";
    exit;
}

$password = (string) getenv('PMAS_BOOTSTRAP_PASSWORD');
$domain = strtolower(trim((string) getenv('PMAS_BOOTSTRAP_EMAIL_DOMAIN')));
if (($error = password_policy_error($password)) !== null) {
    fwrite(STDERR, "PMAS_BOOTSTRAP_PASSWORD: {$error}\n");
    exit(2);
}
if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
    fwrite(STDERR, "PMAS_BOOTSTRAP_EMAIL_DOMAIN must be a valid school-controlled domain.\n");
    exit(2);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$statement = $db->prepare(
    'INSERT INTO users
        (user_code, full_name, email, password_hash, must_change_password, role, is_active)
     VALUES (?, ?, ?, ?, 1, ?, 1)
     ON DUPLICATE KEY UPDATE
        full_name=VALUES(full_name), email=VALUES(email), password_hash=VALUES(password_hash),
        must_change_password=1, role=VALUES(role), is_active=1'
);

$db->beginTransaction();
try {
    foreach ($codes as $role => $code) {
        $label = ucwords(str_replace('_', ' ', $role));
        $email = "pmas-test-{$role}@{$domain}";
        $statement->execute([$code, "Temporary {$label}", $email, $hash, $role]);
        echo "{$role}: {$code} ({$email})\n";
    }
    $db->commit();
} catch (Throwable $exception) {
    $db->rollBack();
    throw $exception;
}

echo "Temporary accounts created. Remove PMAS_BOOTSTRAP_PASSWORD from the shell environment now.\n";
