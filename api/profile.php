<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/notifications.php';

notify_ensure_schema();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedDevOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5175',
];

if (in_array($origin, $allowedDevOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function profile_json_response(int $status, bool $ok, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function profile_react_role_key(string $databaseRole): string
{
    return match ($databaseRole) {
        'admin_hr' => 'admin',
        'vpaa' => 'vpaa',
        'dean' => 'dean',
        'program_head' => 'programHead',
        'teacher' => 'faculty',
        default => 'admin',
    };
}

function profile_user_payload(?array $user): ?array
{
    if ($user === null) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'birthDate' => (string) ($user['birth_date'] ?? ''),
        'department' => $user['department'] ?? '',
        'program' => $user['program'] ?? '',
        'databaseRole' => $user['role'],
        'roleKey' => profile_react_role_key((string) $user['role']),
        'profileImage' => $user['profile_image'] ?? null,
        'mustChangePassword' => (bool) ($user['must_change_password'] ?? false),
        'emailVerified' => !empty($user['email_verified_at']),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        profile_json_response(405, false, 'Method not allowed.');
    }

    $currentUser = current_user();
    if ($currentUser === null) {
        profile_json_response(401, false, 'Unauthorized.');
    }

    admin_ensure_profile_image_column();
    admin_ensure_faculty_program_schema();

    $userId = (int) $currentUser['id'];
    $fullName = trim((string) ($_POST['full_name'] ?? $currentUser['full_name'] ?? ''));
    $profileEmail = strtolower(trim((string) ($_POST['email'] ?? $currentUser['email'] ?? '')));
    $birthDate = trim((string) ($_POST['birth_date'] ?? $currentUser['birth_date'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? ($_POST['password'] ?? ''));
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $removeProfileImage = (string) ($_POST['remove_profile_image'] ?? '') === '1';

    if ($fullName === '') {
        profile_json_response(422, false, 'Full name is required.');
    }

    if (!filter_var($profileEmail, FILTER_VALIDATE_EMAIL)) {
        profile_json_response(422, false, 'Enter a valid email address.');
    }

    $birthDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
    if ($birthDate === '' || !$birthDateObject || $birthDateObject->format('Y-m-d') !== $birthDate || $birthDateObject > new DateTimeImmutable('today')) {
        profile_json_response(422, false, 'Enter a valid birth date that is not in the future.');
    }

    if ($newPassword !== '') {
        if ($currentPassword === '') {
            profile_json_response(422, false, 'Current password is required to change your password.');
        }

        if (strlen($newPassword) < 8) {
            profile_json_response(422, false, 'New password must be at least 8 characters.');
        }

        if ($confirmPassword === '' || !hash_equals($newPassword, $confirmPassword)) {
            profile_json_response(422, false, 'New password and confirmation do not match.');
        }
    }

    $existing = admin_one(
        'SELECT id, email, birth_date, password_hash, must_change_password, role, department, program, profile_image FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        ['id' => $userId]
    );

    if ($existing === null) {
        profile_json_response(404, false, 'User account was not found.');
    }

    $existingEmail = strtolower(trim((string) ($existing['email'] ?? '')));
    $emailChanged = !hash_equals($existingEmail, $profileEmail);
    if ($emailChanged) {
        $duplicateEmail = admin_one('SELECT id FROM users WHERE LOWER(email) = :email AND id <> :id LIMIT 1', [
            'email' => $profileEmail,
            'id' => $userId,
        ]);
        if ($duplicateEmail !== null) {
            profile_json_response(409, false, 'This email address is already assigned to another account.');
        }
    }

    if ($newPassword !== '' && !password_verify($currentPassword, (string) ($existing['password_hash'] ?? ''))) {
        profile_json_response(422, false, 'Current password is incorrect.');
    }

    if ($newPassword !== '' && password_verify($newPassword, (string) ($existing['password_hash'] ?? ''))) {
        profile_json_response(422, false, 'Your new password must be different from your current password.');
    }

    if ($newPassword !== '' && (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword))) {
        profile_json_response(422, false, 'New password must include uppercase and lowercase letters and a number.');
    }

    $db = db();
    $params = [
        'full_name' => $fullName,
        'email' => $profileEmail,
        'birth_date' => $birthDate,
        'id' => $userId,
    ];
    $sql = 'UPDATE users SET full_name = :full_name, email = :email, birth_date = :birth_date';

    if ($emailChanged) {
        $sql .= ', email_verified_at = NULL';
    }

    if ($newPassword !== '') {
        $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $sql .= ', password_hash = :password_hash, must_change_password = 0';
    }

    $sql .= ' WHERE id = :id';
    $db->prepare($sql)->execute($params);

    if ($emailChanged) {
        $db->prepare("UPDATE auth_tokens SET consumed_at=NOW() WHERE user_id=? AND token_type='email_verification' AND consumed_at IS NULL")->execute([$userId]);
    }

    $profileImage = admin_profile_image_upload($userId);
    if ($profileImage !== null) {
        $db->prepare('UPDATE users SET profile_image = :profile_image WHERE id = :id')->execute([
            'profile_image' => $profileImage,
            'id' => $userId,
        ]);
    } elseif ($removeProfileImage) {
        $profileImage = '';
        $db->prepare('UPDATE users SET profile_image = NULL WHERE id = :id')->execute(['id' => $userId]);
    } else {
        $profileImage = (string) ($existing['profile_image'] ?? '');
    }

    if ($existingEmail !== '') {
        $db->prepare(
            'UPDATE faculty
             SET full_name = :full_name, email = :new_email
             WHERE user_id = :user_id OR LOWER(email) = :old_email'
        )->execute([
            'full_name' => $fullName,
            'new_email' => $profileEmail,
            'user_id' => $userId,
            'old_email' => $existingEmail,
        ]);
    }

    $_SESSION['user']['full_name'] = $fullName;
    $_SESSION['user']['email'] = $profileEmail;
    $_SESSION['user']['birth_date'] = $birthDate;
    if ($emailChanged) {
        $_SESSION['user']['email_verified_at'] = null;
    }
    $_SESSION['user']['profile_image'] = $profileImage;
    $_SESSION['user']['program'] = (string) ($existing['program'] ?? '');
    if ($newPassword !== '') {
        $_SESSION['user']['must_change_password'] = 0;
    }

    $freshUser = admin_one(
        'SELECT id, full_name, email, email_verified_at, birth_date, must_change_password, role, department, program, profile_image FROM users WHERE id = :id LIMIT 1',
        ['id' => $userId]
    );

    $verificationSent = false;
    if ($emailChanged && $freshUser !== null) {
        try {
            $verificationSent = send_email_verification_code($db, $freshUser);
        } catch (Throwable) {
            $verificationSent = false;
        }
    }

    admin_activity('Updated own profile.');
    notify_create($userId, 'info', 'Profile Updated',
        'Your account profile was updated successfully.', null, 'account', $userId);

    $message = $emailChanged
        ? ($verificationSent ? 'Profile updated. A verification code was sent to your new email address.' : 'Profile updated. Use Send Code to verify your new email address.')
        : 'Profile updated.';
    profile_json_response(200, true, $message, [
        'user' => profile_user_payload($freshUser),
    ]);
} catch (RuntimeException $exception) {
    profile_json_response(422, false, $exception->getMessage());
} catch (Throwable $exception) {
    profile_json_response(500, false, 'Unable to update profile right now.');
}
