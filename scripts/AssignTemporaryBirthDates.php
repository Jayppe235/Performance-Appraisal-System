<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$db = db();
$db->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS birth_date_is_temporary TINYINT(1) NOT NULL DEFAULT 0 AFTER birth_date');
$db->beginTransaction();

try {
    // The administrator uses separately managed credentials and must never be reset by this bulk utility.
    $stmt = $db->query("SELECT id, user_code FROM users WHERE is_active = 1 AND role <> 'admin_hr' AND birth_date IS NULL ORDER BY id FOR UPDATE");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare(
        "UPDATE users
         SET birth_date = ?, birth_date_is_temporary = 1, password_hash = ?, must_change_password = 1
         WHERE id = ? AND is_active = 1 AND role <> 'admin_hr' AND birth_date IS NULL"
    );
    $expireTokens = $db->prepare('UPDATE auth_tokens SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');

    foreach ($users as $user) {
        // Valid deterministic samples from 1990-01-01 through 1990-01-28.
        $day = (((int) $user['id'] - 1) % 28) + 1;
        $birthDate = sprintf('1990-01-%02d', $day);
        $hash = password_hash($birthDate, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash a temporary password.');
        }
        $update->execute([$birthDate, $hash, (int) $user['id']]);
        $expireTokens->execute([(int) $user['id']]);
    }

    $actor = $db->query("SELECT id FROM users WHERE role = 'admin_hr' ORDER BY id LIMIT 1")->fetchColumn();
    if ($actor) {
        $log = $db->prepare('INSERT INTO activity_logs (user_id, description) VALUES (?, ?)');
        $log->execute([(int) $actor, 'Assigned temporary sample birthdays and reset first-login passwords for ' . count($users) . ' active accounts.']);
    }

    $db->commit();
    echo json_encode(['updated_accounts' => count($users)], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo 'ERROR: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
