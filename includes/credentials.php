<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const USER_CODE_START = 2025001;

function valid_user_code(mixed $value): bool
{
    return preg_match('/^[1-9][0-9]*$/', trim((string) $value)) === 1;
}

function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least 8 characters, including uppercase, lowercase, and a number.';
    }
    return null;
}

function next_available_user_code(PDO $db, int $candidate): int
{
    $candidate = max(USER_CODE_START, $candidate);
    $stmt = $db->prepare('SELECT 1 FROM users WHERE user_code = ? LIMIT 1');
    do {
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) return $candidate;
        $candidate++;
    } while (true);
}

/** Call inside a transaction. Locks and advances the global sequence. */
function allocate_user_code(PDO $db, ?string $requested = null): int
{
    $db->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('next_user_code', '2025001')");
    $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='next_user_code' FOR UPDATE")->fetch();
    $next = next_available_user_code($db, (int) ($row['setting_value'] ?? USER_CODE_START));
    if ($requested !== null && $requested !== '') {
        if (!valid_user_code($requested)) throw new DomainException('Username code must contain positive numeric digits only.');
        $code = (int) $requested;
        $check = $db->prepare('SELECT 1 FROM users WHERE user_code = ? LIMIT 1');
        $check->execute([$code]);
        if ($check->fetchColumn()) throw new DomainException('This username code is already assigned to another account. Please enter a different code.');
    } else {
        $code = $next;
    }
    $advanced = next_available_user_code($db, max($next, $code + 1));
    $db->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='next_user_code'")->execute([(string) $advanced]);
    return $code;
}

function create_auth_token(PDO $db, int $userId, string $type, int $ttlSeconds): string
{
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $db->prepare('UPDATE auth_tokens SET consumed_at=NOW() WHERE user_id=? AND token_type=? AND consumed_at IS NULL')->execute([$userId, $type]);
    $db->prepare('INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))')
        ->execute([$userId, $type, $hash, $ttlSeconds]);
    return $raw;
}

function consume_auth_token(PDO $db, string $raw, string $type): ?int
{
    $hash = hash('sha256', $raw);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, user_id FROM auth_tokens WHERE token_hash=? AND token_type=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE');
        $stmt->execute([$hash, $type]);
        $row = $stmt->fetch();
        if (!$row) { $db->rollBack(); return null; }
        $db->prepare('UPDATE auth_tokens SET consumed_at=NOW() WHERE id=?')->execute([$row['id']]);
        $db->commit();
        return (int) $row['user_id'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function resend_email(string $to, string $subject, string $html): bool
{
    if (RESEND_API_KEY === '' || RESEND_FROM_EMAIL === '') return false;
    $payload = json_encode(['from' => RESEND_FROM_NAME . ' <' . RESEND_FROM_EMAIL . '>', 'to' => [$to], 'subject' => $subject, 'html' => $html]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        error_log('Resend delivery failed. HTTP ' . $status . ($error ? ' Transport error present.' : ''));
        return false;
    }
    return true;
}

function send_account_link(PDO $db, array $user, string $type): bool
{
    $isReset = $type === 'password_reset';
    $token = create_auth_token($db, (int) $user['id'], $type, $isReset ? 1800 : 86400);
    $path = $isReset ? '/reset-password?token=' : '/verify-email?token=';
    $url = rtrim(PMAS_REACT_URL, '/') . $path . urlencode($token);
    $subject = $isReset ? 'Reset your APPRAISIA password' : 'Verify your APPRAISIA email';
    $html = '<p>Hello ' . htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . ($isReset ? 'Reset password' : 'Verify email') . '</a></p>'
        . '<p>This link expires ' . ($isReset ? 'in 30 minutes.' : 'in 24 hours.') . '</p>';
    return resend_email((string) $user['email'], $subject, $html);
}

function send_email_verification_code(PDO $db, array $user): bool
{
    $code = (string) random_int(100000, 999999);
    $hash = hash('sha256', (int) $user['id'] . ':' . $code);
    $db->prepare("UPDATE auth_tokens SET consumed_at=NOW() WHERE user_id=? AND token_type='email_verification' AND consumed_at IS NULL")->execute([(int) $user['id']]);
    $db->prepare("INSERT INTO auth_tokens (user_id,token_type,token_hash,expires_at) VALUES (?,'email_verification',?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))")
        ->execute([(int) $user['id'], $hash]);
    $safeName = htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8');
    $html = '<p>Hello ' . $safeName . ',</p><p>Your APPRAISIA email verification code is:</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:8px">' . $code . '</p>'
        . '<p>This code expires in 10 minutes. Do not share it with anyone.</p>';
    return resend_email((string) $user['email'], 'Your APPRAISIA verification code', $html);
}

function consume_email_verification_code(PDO $db, int $userId, string $code): bool
{
    if (preg_match('/^[0-9]{6}$/', $code) !== 1) return false;
    $hash = hash('sha256', $userId . ':' . $code);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM auth_tokens WHERE user_id=? AND token_type='email_verification' AND token_hash=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE");
        $stmt->execute([$userId, $hash]);
        $tokenId = $stmt->fetchColumn();
        if (!$tokenId) { $db->rollBack(); return false; }
        $db->prepare('UPDATE auth_tokens SET consumed_at=NOW() WHERE id=?')->execute([$tokenId]);
        $db->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$userId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
