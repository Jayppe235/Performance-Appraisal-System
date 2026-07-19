<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$error = '';
$userCode = $_COOKIE['pmas_remember_user_code'] ?? '';
$rememberCode = $userCode !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userCode = preg_replace('/\D/', '', trim($_POST['user_code'] ?? ''));
    $password = $_POST['password'] ?? '';
    $rememberCode = isset($_POST['remember_code']);

    if ($userCode === '' || $password === '') {
        $error = 'Please complete all login fields.';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        [$success, $message] = attempt_login($userCode, $password);

        if ($success) {
            if ($rememberCode) {
                setcookie('pmas_remember_user_code', $userCode, [
                    'expires' => time() + (60 * 60 * 24 * 30),
                    'path' => BASE_URL,
                    'samesite' => 'Lax',
                ]);
            } else {
                setcookie('pmas_remember_user_code', '', [
                    'expires' => time() - 3600,
                    'path' => BASE_URL,
                    'samesite' => 'Lax',
                ]);
            }
            if (!empty(current_user()['must_change_password'])) {
                header('Location: ' . PMAS_REACT_URL . '/change-password'); exit;
            }
            redirect(role_dashboard_path(current_user()['role']));
        }

        $error = $message;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> Login</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
</head>
<body class="login-page">
    <div class="login-bg" aria-hidden="true"></div>
    <main class="login-shell">
        <nav class="login-nav" aria-label="Login page navigation">
            <span class="active">Login</span>
            <span><?= e(APP_NAME) ?></span>
            <span>Support</span>
        </nav>

        <section class="brand-panel">
            <div class="login-brand-mark">
                <img src="<?= BASE_URL ?>/assets/images/ndmc-seal.png" alt="<?= e(APP_NAME) ?> seal">
            </div>
            <p class="eyebrow">Digital Performance Appraisal System</p>
            <h1>Welcome to <?= e(APP_NAME) ?></h1>
            <p>
                Faculty performance appraisal and development management system.
            </p>
        </section>

        <section class="login-card" aria-labelledby="login-title">
          <h2 id="login-title">Login to your account</h2>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= BASE_URL ?>/login.php" class="form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label for="user-code">Username Code</label>
                <input
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]+"
                    id="user-code"
                    name="user_code"
                    value="<?= e($userCode) ?>"
                    placeholder="User ID"
                    autocomplete="username"
                    required
                >

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    autocomplete="current-password"
                    required
                >

                <div class="password-toggle">
                    <input type="checkbox" id="show-password">
                    <label for="show-password">Show password</label>
                </div>

                <div class="login-options">
                    <label class="password-toggle" for="remember-code">
                        <input type="checkbox" id="remember-code" name="remember_code" <?= $rememberCode ? 'checked' : '' ?>>
                        Remember username code
                    </label>
                </div>

                <button type="submit">Log in</button>
            </form>
        </section>

        <section class="login-visual" aria-hidden="true">
            <div class="visual-circle">
                <img class="visual-logo" src="<?= BASE_URL ?>/assets/images/ndmc-seal.png" alt="">
            </div>
        </section>
    </main>
    <script>
        document.getElementById('show-password').addEventListener('change', function () {
            document.getElementById('password').type = this.checked ? 'text' : 'password';
        });
    </script>
</body>
</html>
