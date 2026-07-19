<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function render_dashboard(string $pageTitle, string $roleName, array $features): void
{
    $user = current_user();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/light-mode-fixes.css?v=1">
    </head>
    <body>
        <header class="topbar">
            <div>
                <span class="app-mark"><?= e(APP_NAME) ?></span>
                <p>Faculty performance appraisal and development management system</p>
            </div>
            <a class="logout-link" href="<?= BASE_URL ?>/logout.php">Logout</a>
        </header>

        <main class="dashboard">
            <section class="welcome">
                <div class="welcome-copy">
                    <p class="eyebrow"><?= e($roleName) ?> Dashboard</p>
                    <h1>Welcome, <?= e(display_name($user['full_name'] ?? null)) ?></h1>
                    <p class="muted">
                        You are signed in as <?= e($user['email'] ?? '') ?>. Your dashboard shows the evaluation tasks and performance insights for your role.
                    </p>
                </div>
                <img class="welcome-robot" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="" aria-hidden="true">
            </section>

            <section class="panel-grid" aria-label="Dashboard functions">
                <?php foreach ($features as $feature): ?>
                    <article class="panel">
                        <h2><?= e($feature['title']) ?></h2>
                        <p><?= e($feature['description']) ?></p>
                    </article>
                <?php endforeach; ?>
            </section>
        </main>
</body>
    </html>
    <?php
}
