<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$messageType = 'info';

function setup_pdo(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $message = 'Your setup session expired. Please try again.';
        $messageType = 'error';
    } else {
        try {
            $sqlPath = __DIR__ . '/database/pmas.sql';
            $sql = file_get_contents($sqlPath);

            if ($sql === false) {
                throw new RuntimeException('Cannot read database/pmas.sql.');
            }

            $sql = str_replace('pmas_db', DB_NAME, $sql);
            setup_pdo()->exec($sql);
            $message = 'Database setup completed. You can now go to the login page.';
            $messageType = 'success';
        } catch (Throwable $exception) {
            $message = 'Setup failed: ' . $exception->getMessage();
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIPASCAF Setup</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
</head>
<body class="setup-page">
    <main class="setup-card">
        <p class="eyebrow">DIPASCAF Installation</p>
        <h1>Database Setup</h1>
        <p class="muted">
            Use this once on your local XAMPP server to create the database, users table, and starter accounts.
        </p>

        <?php if ($message !== ''): ?>
            <div class="setup-message <?= e($messageType) ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit">Create Database Tables</button>
        </form>

        <a class="setup-link" href="<?= BASE_URL ?>/login.php">Go to Login</a>
    </main>
</body>
</html>
