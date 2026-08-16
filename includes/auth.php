<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/database_session.php';

if (session_status() === PHP_SESSION_NONE) {
    configure_database_sessions();
    // Set cookie path to / so the session cookie works across
    // both the Vite dev proxy (localhost:5173/api/...) and Apache
    // production (localhost/PMAS/api/...).
    // Only configure cookie params if no output has been sent yet;
    // otherwise the defaults from php.ini will be used.
    if (!headers_sent()) {
        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || $forwardedProto === 'https'
            || str_starts_with(strtolower(APP_URL), 'https://');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function display_name(?string $full_name, string $fallback = 'User'): string
{
    if ($full_name === null || $full_name === '') {
        return $fallback;
    }

    $parts = explode(' ', trim($full_name));
    $first = $parts[0] ?? '';

    // If the first part ends with '.', it's a title (e.g., Engr., Dr., Prof., Atty.)
    if (count($parts) >= 2 && str_ends_with($first, '.')) {
        return $first . ' ' . ($parts[1] ?? '');
    }

    // No title, return just the first name
    return $first;
}

function secure_encrypt_value(string $value): string
{
    if ($value === '' || str_starts_with($value, 'enc:v1:')) {
        return $value;
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', DATA_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);

    if ($ciphertext === false) {
        return $value;
    }

    return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
}

function secure_decrypt_value(?string $value): string
{
    if ($value === null || !str_starts_with($value, 'enc:v1:')) {
        return (string) $value;
    }

    $parts = explode(':', $value, 5);
    if (count($parts) !== 5) {
        return $value;
    }

    [, , $encodedIv, $encodedTag, $encodedCiphertext] = $parts;
    $iv = base64_decode($encodedIv, true);
    $tag = base64_decode($encodedTag, true);
    $ciphertext = base64_decode($encodedCiphertext, true);

    if ($iv === false || $tag === false || $ciphertext === false) {
        return $value;
    }

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', DATA_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? $value : $plaintext;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_token_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function redirect(string $path): never
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function role_dashboard_path(string $role): string
{
    return match ($role) {
        'admin_hr' => '/dashboards/admin_hr.php',
        'vpaa' => '/dashboards/vpaa.php',
        'dean' => '/dashboards/dean.php',
        'program_head' => '/dashboards/program_head.php',
        'teacher' => '/dashboards/teacher.php',
        default => '/login.php',
    };
}

function require_guest(): void
{
    $user = current_user();

    if ($user !== null) {
        redirect(role_dashboard_path($user['role']));
    }
}

function require_role(string $role): void
{
    $user = current_user();

    if ($user === null) {
        redirect('/login.php');
    }

    if ($user['role'] !== $role) {
        redirect(role_dashboard_path($user['role']));
    }
}

function attempt_login(string $userCode, string $password): array
{
    if (!valid_user_code($userCode)) return [false, 'Invalid username code or password.'];

    try {
        $stmt = db()->prepare(
            'SELECT id, user_code, full_name, email, email_verified_at, password_hash, must_change_password, role, is_active, department, program, profile_image
             FROM users
             WHERE user_code = :user_code
             LIMIT 1'
        );
        $stmt->execute(['user_code' => $userCode]);
        $user = $stmt->fetch();
    } catch (PDOException $exception) {
        return [false, 'Database is not ready. Please open /PMAS/setup.php once to create the tables.'];
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [false, 'Invalid username code or password.'];
    }

    if ((int) $user['is_active'] !== 1) {
        return [false, 'This account is inactive. Please contact Admin/HR.'];
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'user_code' => (string) $user['user_code'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'department' => $user['department'] ?? '',
        'program' => $user['program'] ?? '',
        'profile_image' => $user['profile_image'],
        'email_verified_at' => $user['email_verified_at'],
        'must_change_password' => (int) $user['must_change_password'],
    ];

    try {
        $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => $user['id']]);
        $activity = db()->prepare('INSERT INTO activity_logs (user_id, description) VALUES (:user_id, :description)');
        $activity->execute([
            'user_id' => $user['id'],
            'description' => 'Logged in to DIPASCAF.',
        ]);

        // ── Create login notification ──
        require_once __DIR__ . '/notifications.php';
        notify_create(
            (int) $user['id'],
            'account_activity',
            'Welcome back, ' . display_name($user['full_name'] ?? null) . '!',
            'You have successfully logged in to the DIPASCAF system.',
            role_dashboard_path($user['role'])
        );
    } catch (PDOException|Throwable $exception) {
        // Login should still succeed even if optional notification creation fails.
    }

    return [true, 'Login successful.'];
}
