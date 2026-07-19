<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/notifications.php';

allow_local_dev_cors();

header('Content-Type: application/json');

function react_role_key(string $databaseRole): string
{
    return match ($databaseRole) {
        'admin_hr' => 'admin',
        'vpaa' => 'vpaa',
        'dean' => 'dean',
        'program_head' => 'programHead',
        'faculty' => 'faculty',
        'teacher' => 'faculty',
        default => 'admin',
    };
}

function auth_user_payload(?array $user): ?array
{
    if ($user === null) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'userCode' => (string) ($user['user_code'] ?? ''),
        'name' => $user['full_name'],
        'email' => $user['email'],
        'birthDate' => (string) ($user['birth_date'] ?? ''),
        'department' => $user['department'] ?? '',
        'program' => $user['program'] ?? '',
        'databaseRole' => $user['role'],
        'roleKey' => react_role_key($user['role']),
        'profileImage' => $user['profile_image'] ?? null,
        'emailVerified' => !empty($user['email_verified_at']),
        'mustChangePassword' => (bool) ($user['must_change_password'] ?? false),
    ];
}

function request_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

$action = $_GET['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') {
        $sessionUser = current_user();
        $user = null;
        if ($sessionUser !== null) {
            $freshUser = null;
            try {
                $stmt = db()->prepare(
                    'SELECT id, user_code, full_name, email, email_verified_at, birth_date, must_change_password, role, department, program, profile_image
                     FROM users
                     WHERE id = :id AND is_active = 1
                     LIMIT 1'
                );
                $stmt->execute(['id' => (int) $sessionUser['id']]);
                $freshUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {
                $freshUser = null;
            }

            if ($freshUser !== null) {
                $_SESSION['user'] = [
                    'id' => (int) $freshUser['id'],
                    'user_code' => (string) $freshUser['user_code'],
                    'full_name' => $freshUser['full_name'],
                    'email' => $freshUser['email'],
                    'birth_date' => $freshUser['birth_date'] ?? null,
                    'role' => $freshUser['role'],
                    'department' => $freshUser['department'] ?? '',
                    'program' => $freshUser['program'] ?? '',
                    'profile_image' => $freshUser['profile_image'] ?? null,
                    'email_verified_at' => $freshUser['email_verified_at'],
                    'must_change_password' => (int) $freshUser['must_change_password'],
                ];
                $user = auth_user_payload($freshUser);
            } else {
                $user = auth_user_payload($sessionUser);
            }
        }
        echo json_encode(['ok' => $user !== null, 'user' => $user]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $body = request_body();
    $action = $body['action'] ?? $action;

    if ($action === 'logout') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'change-password') {
        $user = current_user();
        if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Please sign in again.']); exit; }
        $newPassword = (string) ($body['password'] ?? '');
        $error = password_policy_error($newPassword);
        if ($error) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>$error]); exit; }
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?'); $stmt->execute([$user['id']]);
        if (password_verify($newPassword, (string) $stmt->fetchColumn())) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Choose a password different from your current or temporary password.']); exit; }
        db()->beginTransaction();
        try {
            db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            db()->prepare('UPDATE auth_tokens SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL')->execute([$user['id']]);
            db()->prepare('INSERT INTO activity_logs (user_id, description) VALUES (?, ?)')->execute([$user['id'], 'Changed account password.']);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        $_SESSION['user']['must_change_password'] = 0;
        echo json_encode(['ok'=>true,'user'=>auth_user_payload(current_user())]); exit;
    }

    if ($action === 'send-verification') {
        $user = current_user();
        if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Please sign in again.']); exit; }
        $stmt = db()->prepare('SELECT id, full_name, email, email_verified_at FROM users WHERE id=?'); $stmt->execute([$user['id']]); $fresh=$stmt->fetch();
        if (!$fresh['email_verified_at']) send_account_link(db(), $fresh, 'email_verification');
        echo json_encode(['ok'=>true,'message'=>'If delivery is available, a verification link has been sent.']); exit;
    }

    if ($action === 'send-verification-code') {
        $user = current_user();
        if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Please sign in again.']); exit; }
        $stmt = db()->prepare('SELECT id, full_name, email, email_verified_at FROM users WHERE id=? AND is_active=1');
        $stmt->execute([$user['id']]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fresh) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Account not found.']); exit; }
        if (!empty($fresh['email_verified_at'])) { echo json_encode(['ok'=>true,'verified'=>true,'message'=>'Your email address is already verified.']); exit; }
        $rate = db()->prepare("SELECT COUNT(*) FROM auth_tokens WHERE user_id=? AND token_type='email_verification' AND created_at>DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $rate->execute([$fresh['id']]);
        if ((int) $rate->fetchColumn() >= 3) { http_response_code(429); echo json_encode(['ok'=>false,'message'=>'Too many verification requests. Please wait 10 minutes.']); exit; }
        if (!send_email_verification_code(db(), $fresh)) { http_response_code(503); echo json_encode(['ok'=>false,'message'=>'Verification email could not be sent. Please contact the administrator or try again later.']); exit; }
        echo json_encode(['ok'=>true,'message'=>'A six-digit verification code was sent to your email address.']); exit;
    }

    if ($action === 'verify-email-code') {
        $user = current_user();
        if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Please sign in again.']); exit; }
        $code = trim((string) ($body['verification_code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Enter the six-digit verification code.']); exit; }
        if (!consume_email_verification_code(db(), (int) $user['id'], $code)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'The verification code is invalid or expired.']); exit; }
        $_SESSION['user']['email_verified_at'] = date('Y-m-d H:i:s');
        $stmt = db()->prepare('SELECT id, user_code, full_name, email, email_verified_at, birth_date, must_change_password, role, department, program, profile_image FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$user['id']]);
        echo json_encode(['ok'=>true,'message'=>'Email verified successfully.','user'=>auth_user_payload($stmt->fetch(PDO::FETCH_ASSOC) ?: current_user())]); exit;
    }

    if ($action === 'request-reset') {
        $code = trim((string)($body['user_code'] ?? ''));
        if (valid_user_code($code)) {
            $stmt=db()->prepare("SELECT id, user_code, full_name FROM users WHERE user_code=? AND is_active=1 AND role<>'admin_hr' LIMIT 1");
            $stmt->execute([$code]);
            $resetUser=$stmt->fetch(PDO::FETCH_ASSOC);
            if ($resetUser) {
                $recent=db()->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE user_id=? AND requested_at>DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
                $recent->execute([$resetUser['id']]);
                $pending=db()->prepare("SELECT id, requested_at FROM password_reset_requests WHERE user_id=? AND status='pending' LIMIT 1");
                $pending->execute([$resetUser['id']]);
                $request=$pending->fetch(PDO::FETCH_ASSOC);
                if (!$request && (int)$recent->fetchColumn() === 0) {
                    try {
                        $insert=db()->prepare("INSERT INTO password_reset_requests (user_id,status,requested_at) VALUES (?,'pending',NOW())");
                        $insert->execute([$resetUser['id']]);
                        $requestId=(int)db()->lastInsertId();
                    } catch (PDOException $exception) {
                        // A simultaneous request may win the unique pending-user constraint.
                        $requestId=0;
                    }
                    if ($requestId <= 0) {
                        echo json_encode(['ok'=>true,'message'=>'If the username code belongs to an eligible account, the administrator has been notified. Please wait for the administrator to reset your password.']); exit;
                    }
                    $admins=db()->query("SELECT id FROM users WHERE role='admin_hr' AND is_active=1")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($admins as $admin) {
                        notify_send([
                            'recipient_id'=>(int)$admin['id'], 'type'=>'warning',
                            'title'=>'Password reset requested',
                            'message'=>sprintf('%s (username code %s) requested an administrator-assisted password reset.', $resetUser['full_name'], $resetUser['user_code']),
                            'action_url'=>'/admin/people?view=users&reset_request='.$requestId.'&user_id='.(int)$resetUser['id'].'#account-management',
                            'module'=>'password_reset', 'related_record_id'=>$requestId,
                        ]);
                    }
                }
            }
        }
        echo json_encode(['ok'=>true,'message'=>'If the username code belongs to an eligible account, the administrator has been notified. Please wait for the administrator to reset your password.']); exit;
    }

    if ($action === 'verify-email') {
        $uid=consume_auth_token(db(),(string)($body['token']??''),'email_verification');
        if (!$uid) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'This verification link is invalid or expired.']); exit; }
        db()->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$uid]);
        if ((int)(current_user()['id']??0)===$uid) $_SESSION['user']['email_verified_at']=date('Y-m-d H:i:s');
        echo json_encode(['ok'=>true,'message'=>'Email verified successfully.']); exit;
    }

    if ($action === 'reset-password') {
        $password=(string)($body['password']??''); $error=password_policy_error($password);
        if ($error) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>$error]); exit; }
        $uid=consume_auth_token(db(),(string)($body['token']??''),'password_reset');
        if (!$uid) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'This reset link is invalid or expired.']); exit; }
        db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);
        echo json_encode(['ok'=>true,'message'=>'Password reset successfully. You may now sign in.']); exit;
    }

    if ($action !== 'login') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown auth action.']);
        exit;
    }

    $userCode = trim((string) ($body['user_code'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($userCode === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Please complete all login fields.']);
        exit;
    }

    [$success, $message] = attempt_login($userCode, $password);

    if (!$success) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => $message,
        'user' => auth_user_payload(current_user()),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to connect to the database right now.']);
}
