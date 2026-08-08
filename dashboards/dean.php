p<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/dean_data.php';
require_once __DIR__ . '/../includes/teacher_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

require_role('dean');

$user = current_user();
$deanId = (int) $user['id'];
$departments = dean_departments($deanId);
$section = $_GET['section'] ?? 'overview';
$allowedSections = ['overview', 'directory', 'evaluate', 'summary', 'insights', 'training', 'report'];

if (!in_array($section, $allowedSections, true)) {
    $section = 'overview';
}

if ($section === 'training') {
    $section = 'summary';
}

function dean_redirect(string $message = 'Saved successfully.'): never
{
    $_SESSION['flash'] = $message;
    redirect('/dashboards/dean.php?section=evaluate');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
        redirect('/dashboards/dean.php?section=' . $section);
    }

    try {
        if (($_POST['action'] ?? '') === 'submit_evaluation') {
            $result = dipascaf_submit_evaluation($deanId, 'dean', 'Dean submitted an evaluation.');
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true] + $result);
                exit;
            }
            dean_redirect('Evaluation submitted.');
        }
    } catch (Throwable $exception) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
            exit;
        }
        $_SESSION['flash_error'] = $exception->getMessage();
        redirect('/dashboards/dean.php?section=' . $section);
    }
}

$flash = $_SESSION['flash'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

$showDeanAiPrompt = $section === 'overview' && empty($_SESSION['dean_ai_prompt_shown']);
if ($showDeanAiPrompt) {
    $_SESSION['dean_ai_prompt_shown'] = true;
}
$deanAiSuggestions = [
    'Show my department evaluation progress.',
    'Which faculty members are pending?',
    'View faculty performance summaries.',
    'Generate department performance reports.',
    'Show AI recommendations for faculty.',
    'Who received the highest evaluation scores?',
    'View completed evaluations.',
    'Show faculty strengths and weaknesses.',
    'Check evaluation completion percentage.',
    'View recent faculty feedback.',
];

dipascaf_init_evaluation_assignments($deanId, 'dean');
$assignments = dean_assignments($deanId);
$evaluationCardAssignments = dipascaf_assignment_rows($deanId, 'dean');
$faculty = dean_faculty($departments);
$summary = dean_summary($departments);
$insights = dean_ai_insights($departments);
$interventions = dean_interventions($departments);
$factors = admin_factors();
$deanCompletionRate = count($assignments) > 0 ? round(($summary['submitted'] ?? 0) / count($assignments) * 100) : 0;
$deanReportTypes = [
    'evaluation_status' => [
        'title' => 'Evaluation Status Report',
        'description' => 'Shows pending, submitted, and active department evaluation tasks assigned to your dean account.',
        'best_for' => 'Daily department monitoring',
        'badge' => (string) $summary['pending'] . ' pending',
        'category' => 'Operations',
        'icon' => 'activity',
        'progress' => $deanCompletionRate,
    ],
    'department_summary' => [
        'title' => 'Department Summary Report',
        'description' => 'Summarizes faculty counts, department appraisal progress, weak areas, and current review activity.',
        'best_for' => 'Department review',
        'badge' => count($departments) . ' departments',
        'category' => 'Department',
        'icon' => 'building',
        'progress' => min(100, count($departments) * 24),
    ],
    'faculty_performance' => [
        'title' => 'Faculty Performance Report',
        'description' => 'Highlights faculty under your department with submitted reviews, ratings, and performance summaries.',
        'best_for' => 'Faculty coaching',
        'badge' => (string) count($faculty) . ' faculty',
        'category' => 'Performance',
        'icon' => 'users',
        'progress' => $deanCompletionRate,
    ],
    'peer_assignments' => [
        'title' => 'Peer Assignment Report',
        'description' => 'Reviews confidential peer and leadership evaluation assignments connected to your department.',
        'best_for' => 'Assignment checking',
        'badge' => (string) count($assignments) . ' assignments',
        'category' => 'Peer Review',
        'icon' => 'network',
        'progress' => min(100, count($assignments) * 10),
    ],
    'ai_training' => [
        'title' => 'AI Insights and Training Report',
        'description' => 'Combines AI-generated strengths, weak areas, and recommended development actions for faculty.',
        'best_for' => 'Development planning',
        'badge' => (string) count($interventions) . ' plans',
        'category' => 'AI Analytics',
        'icon' => 'spark',
        'progress' => min(100, max(12, count($interventions) * 18)),
    ],
    'complete_export' => [
        'title' => 'Complete Evaluation Export',
        'description' => 'Exports your full department evaluation data for records, backup, and reporting preparation.',
        'best_for' => 'Records and backup',
        'badge' => (string) count($assignments) . ' records',
        'category' => 'Export',
        'icon' => 'download',
        'progress' => 100,
    ],
];
$nav = [
    'overview' => ['dashboard', 'Overview'],
    'directory' => ['users', 'Directory'],
    'evaluate' => ['evaluations', 'Evaluate'],
    'summary' => ['summary', 'Summary & Plans'],
    'insights' => ['insights', 'Insights'],
    'report' => ['reports', 'Reports'],
];
$pageTitle = $nav[$section][1];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/evaluation-form.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body class="admin-body dean-body">
    <button class="sidebar-overlay" type="button" aria-label="Close menu"></button>
    <aside class="admin-sidebar dean-sidebar" aria-label="Dean navigation">
        <div class="sidebar-brand">
            <span class="brand-icon">D</span>
            <span class="sidebar-brand-copy">
                <strong><?= e(APP_NAME) ?></strong>
                <small>Dean Portal</small>
            </span>
            <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar"></button>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($nav as $key => [$icon, $label]): ?>
                <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboards/dean.php?section=<?= e($key) ?>">
                    <span class="menu-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
                    <span class="sidebar-item-label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <a class="sidebar-logout" href="<?= BASE_URL ?>/logout.php">
                <span class="menu-icon" data-icon="logout" aria-hidden="true"></span>
                <span class="sidebar-item-label">Logout</span>
            </a>
            <label class="dark-mode-switch">
                <span class="menu-icon" data-icon="moon" aria-hidden="true"></span>
                <span class="sidebar-item-label">Dark Mode</span>
                <input class="dark-mode-input" type="checkbox" aria-label="Toggle dark mode">
                <span class="toggle-track" aria-hidden="true"></span>
            </label>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div class="admin-header-info">
                <h1><?= e($pageTitle) ?></h1>
                <p class="admin-header-note">Dean for <?= e(implode(', ', $departments)) ?></p>
            </div>
            <div class="admin-search dean-header-context">
                <span><?= e(implode(', ', $departments)) ?></span>
            </div>
            <div class="admin-actions" aria-label="Dean metrics and profile">
                <button class="notification-button" type="button" onclick="location.href='<?= BASE_URL ?>/dashboards/dean.php?section=evaluate'" aria-label="Open pending evaluations">
                    <span class="notification-badge"><?= e((string) $summary['pending']) ?></span>
                </button>
                <button class="notification-button warning" type="button" onclick="location.href='<?= BASE_URL ?>/dashboards/dean.php?section=summary'" aria-label="Open submitted reviews">
                    <span class="notification-badge"><?= e((string) $summary['submitted']) ?></span>
                </button>
                <button class="profile-button" type="button" aria-label="Dean profile"><span class="admin-avatar"><?= e(strtoupper(substr((string) ($user['full_name'] ?? 'D'), 0, 1))) ?></span></button>
            </div>
        </header>

        <section class="admin-content admin-module dean-content <?= $section === 'report' ? 'reports-analytics-content' : '' ?>">
        <?php if ($flash !== ''): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($flashError !== ''): ?><div class="notice error"><?= e($flashError) ?></div><?php endif; ?>

        <?php if ($section === 'overview'): ?>
        <?php if ($showDeanAiPrompt): ?>
        <div id="admin-ai-top-prompt" class="admin-ai-top-prompt module-wide" role="status" aria-live="polite">
            <div class="admin-ai-top-icon" aria-hidden="true">
                <img src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="">
            </div>
            <div class="admin-ai-top-copy">
                <p>Hello Dean! Need help monitoring your department evaluations?</p>
                <div class="admin-ai-suggestions">
                    <?php foreach ($deanAiSuggestions as $suggestion): ?>
                        <button type="button" data-chat-sample="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <button id="admin-ai-top-dismiss" type="button" aria-label="Dismiss AI message">x</button>
        </div>
        <?php endif; ?>
        <div class="hero-card module-wide">
            <div>
                <h2>Welcome back, <?= e(display_name($user['full_name'])) ?></h2>
                <p>Real-time dean dashboard for <?= e(implode(', ', $departments)) ?>. Track appraisal progress, faculty performance, and department-level insights automatically.</p>
            </div>
            <div class="hero-illustration" aria-hidden="true">
                <img class="hero-robot" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="">
            </div>
        </div>

        <div class="metric-card gold"><span>Faculty Under Review</span><strong><?= e((string) $summary['facultyCount']) ?></strong><div class="metric-chart"></div></div>
        <div class="metric-card coral"><span>Pending Reviews</span><strong><?= e((string) $summary['pending']) ?></strong><div class="metric-list"></div></div>

        <section class="admin-box stat-grid module-wide">
            <article><span>Assigned Tasks</span><strong><?= e((string) count($assignments)) ?></strong></article>
            <article><span>Submitted Reviews</span><strong><?= e((string) $summary['submitted']) ?></strong></article>
            <article><span>Completion Rate</span><strong><?= e((string) round(($summary['submitted'] ?? 0) / (count($assignments) ?: 1) * 100)) ?>%</strong></article>
            <article><span>AI Insights</span><strong><?= e((string) count($insights)) ?></strong></article>
            <article><span>Training Plans</span><strong><?= e((string) count($interventions)) ?></strong></article>
            <article><span>Active Departments</span><strong><?= e((string) count($departments)) ?></strong></article>
        </section>
        <?php endif; ?>

        <?php if ($section === 'directory'): ?>
        <?php
            // Get users (program heads, teachers) from the dean's departments
            $directoryAliases = [];
            foreach ($departments as $dept) {
                $aliases = admin_matching_department_aliases($dept);
                $directoryAliases = array_merge($directoryAliases, $aliases !== [] ? $aliases : [$dept]);
            }
            $directoryAliases = array_values(array_unique(array_filter($directoryAliases)));
            $directoryUsers = [];
            if ($directoryAliases !== []) {
                $placeholders = implode(',', array_fill(0, count($directoryAliases), '?'));
                $directoryUsers = admin_all(
                    "SELECT id, full_name, email, phone, role, department, program
                     FROM users
                     WHERE is_active = 1 AND department IN ($placeholders)
                       AND role IN ('program_head', 'teacher')
                     ORDER BY CASE role WHEN 'program_head' THEN 1 ELSE 2 END, full_name",
                    $directoryAliases
                );
            }
        ?>
        <section class="admin-box module-wide">
            <div class="box-title">
                <h2>Department Directory</h2>
                <span><?= e(implode(', ', $departments)) ?></span>
            </div>
            <div class="stat-grid compact">
                <article><span>Faculty Members</span><strong><?= e((string) count($faculty)) ?></strong></article>
                <article><span>Program Heads</span><strong><?= e((string) count(array_filter($directoryUsers, fn (array $u): bool => $u['role'] === 'program_head'))) ?></strong></article>
                <article><span>Teachers</span><strong><?= e((string) count(array_filter($directoryUsers, fn (array $u): bool => $u['role'] === 'teacher'))) ?></strong></article>
                <article><span>Departments</span><strong><?= e((string) count($departments)) ?></strong></article>
            </div>
        </section>

        <?php if ($directoryUsers !== []): ?>
        <section class="admin-box module-table">
            <div class="box-title">
                <h2>Department Accounts (<?= count($directoryUsers) ?>)</h2>
                <span>Program Heads and Teachers with user accounts</span>
            </div>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Program</th>
                            <th>Email</th>
                            <th>Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($directoryUsers as $userRow): ?>
                            <tr>
                                <td><strong><?= e($userRow['full_name']) ?></strong></td>
                                <td><?= e(admin_role_label($userRow['role'])) ?></td>
                                <td><?= e($userRow['program'] ?? '-') ?></td>
                                <td><a href="mailto:<?= e($userRow['email']) ?>"><?= e($userRow['email']) ?></a></td>
                                <td><?= e($userRow['phone'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($faculty !== []): ?>
        <section class="admin-box module-table">
            <div class="box-title">
                <h2>Faculty Records (<?= count($faculty) ?>)</h2>
                <span>All faculty members under your departments</span>
            </div>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Position</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faculty as $fac): ?>
                            <tr>
                                <td><strong><?= e($fac['full_name']) ?></strong></td>
                                <td><?= e($fac['program_code'] ?? '-') ?></td>
                                <td><?= e($fac['position_title'] ?? '-') ?></td>
                                <td><a href="mailto:<?= e($fac['email']) ?>"><?= e($fac['email']) ?></a></td>
                                <td><?= e($fac['phone'] ?? '-') ?></td>
                                <td>
                                    <div class="eval-fac-progress">
                                        <div class="eval-fac-progress-fill" style="width: <?= e((string) ($fac['progress_percent'] ?? 0)) ?>%;"></div>
                                        <span class="eval-fac-progress-label"><?= e((string) ($fac['progress_percent'] ?? 0)) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php else: ?>
        <div class="notice info">
            <p>No faculty members are assigned to your department(s) yet.</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'evaluate'): ?>
            <?php dipascaf_render_evaluation_dashboard([
                'assignments' => $evaluationCardAssignments,
                'eyebrow' => 'Dean Evaluation',
                'title' => 'Evaluate Program Heads and Faculty',
                'subtitle' => 'Review every assigned Program Head, Faculty, and Peer appraisal card under your department.',
                'defaultSection' => 'all',
                'hideRoleStatusFilters' => true,
            ]); ?>
        <?php endif; ?>

        <?php if ($section === 'summary'): ?>
        <section class="admin-box module-table">
            <div class="box-title">
                <h2>Department Summary and Development Plans</h2>
                <span><?= e(implode(', ', $departments)) ?></span>
            </div>
            <div class="stat-grid compact">
                <?php foreach ($summary['weakAreas'] as $area): ?>
                    <article><span><?= e($area['program_code']) ?></span><strong><?= e($area['weak_area']) ?></strong><small><?= e((string) $area['weak_count']) ?> detected case(s)</small></article>
                <?php endforeach; ?>
                <?php foreach ($factors as $factor): ?>
                    <article><span>Factor Weight</span><strong><?= e($factor['factor_name']) ?></strong><small><?= e((string) $factor['weight_percent']) ?>%</small></article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="admin-box module-table">
            <div class="box-title">
                <h2>Recommended Training and Development Plans by Program</h2>
                <span>Seminars, mentoring, coaching, training</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Program</th><th>Faculty</th><th>Weak Area</th><th>Recommendation</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($interventions as $plan): ?>
                        <tr>
                            <td data-label="Program"><?= e($plan['program_code']) ?></td>
                            <td data-label="Faculty"><?= e($plan['faculty_name']) ?></td>
                            <td data-label="Weak Area"><?= e($plan['weak_area']) ?></td>
                            <td data-label="Recommendation"><?= e($plan['recommendation']) ?></td>
                            <td data-label="Type"><?= e(admin_status_label($plan['action_type'])) ?></td>
                            <td data-label="Status"><?= e(admin_status_label($plan['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($interventions === []): ?>
                        <tr><td colspan="6">No development plans are listed for this department yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <?php if ($section === 'insights'): ?>
        <section class="admin-box module-table">
            <div class="box-title">
                <h2>AI-Generated Analysis</h2>
                <span>Strengths, weak areas, and interpretation</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Faculty</th><th>Department</th><th>Program</th><th>Strength</th><th>Weak Area</th><th>AI Summary</th></tr></thead>
                <tbody>
                    <?php foreach ($insights as $insight): ?>
                        <tr>
                            <td data-label="Faculty"><?= e($insight['faculty_name']) ?></td>
                            <td data-label="Department"><?= e($insight['department']) ?></td>
                            <td data-label="Program"><?= e($insight['program_code']) ?></td>
                            <td data-label="Strength"><?= e($insight['strength_area'] ?? '') ?></td>
                            <td data-label="Weak Area"><?= e($insight['weak_area']) ?></td>
                            <td data-label="AI Summary"><?= e($insight['analysis_summary']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <?php if ($section === 'report'): ?>
        <section class="admin-box admin-report-intro analytics-report-intro module-wide">
            <div>
                <span class="eyebrow">Dean Reports</span>
                <h2>Reports & Analytics Dashboard</h2>
                <p>Generate AI-ready evaluation exports, department summaries, and faculty performance reports from one premium analytics workspace.</p>
            </div>
            <div class="admin-report-completion" aria-label="Overall completion <?= e((string) $deanCompletionRate) ?> percent">
                <div class="completion-donut" style="--completion-rate: <?= e((string) $deanCompletionRate) ?>">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="completion-donut-track" cx="60" cy="60" r="48" pathLength="100"></circle>
                        <circle class="completion-donut-progress" cx="60" cy="60" r="48" pathLength="100"></circle>
                    </svg>
                    <strong><?= e((string) $deanCompletionRate) ?>%</strong>
                </div>
                <span>Overall Completion</span>
            </div>
        </section>
        <section class="admin-report-grid module-wide" aria-label="Specific dean report types">
            <?php $reportIndex = 0; ?>
            <?php foreach ($deanReportTypes as $reportKey => $report): ?>
                <article class="admin-report-card" style="--card-delay: <?= e((string) ($reportIndex * 80)) ?>ms;">
                    <div class="admin-report-card-top">
                        <span class="admin-report-stat"><?= e($report['badge']) ?></span>
                        <span class="admin-report-icon" data-icon="<?= e($report['icon']) ?>" aria-hidden="true"></span>
                    </div>
                    <span class="admin-report-badge"><?= e($report['category']) ?></span>
                    <div class="admin-report-title-row">
                        <span class="admin-report-title-icon" data-icon="<?= e($report['icon']) ?>" aria-hidden="true"></span>
                        <h3><?= e($report['title']) ?></h3>
                    </div>
                    <p><?= e($report['description']) ?></p>
                    <small>Best for: <?= e($report['best_for']) ?></small>
                    <div class="admin-report-progress" aria-label="Report readiness <?= e((string) $report['progress']) ?> percent">
                        <span style="--progress-value: <?= e((string) $report['progress']) ?>%;"></span>
                    </div>
                    <form method="get" action="<?= BASE_URL ?>/reports/dean_download.php" class="admin-report-actions">
                        <input type="hidden" name="report_type" value="<?= e($reportKey) ?>">
                        <label>
                            Format
                            <select name="format">
                                <option value="csv">CSV</option>
                                <option value="excel">Excel</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </label>
                        <button type="submit" data-tooltip="Generates <?= e(strtolower($report['title'])) ?> as the selected export format.">
                            <span class="report-button-text">Generate</span>
                            <span class="report-button-loader" aria-hidden="true"></span>
                        </button>
                    </form>
                </article>
                <?php $reportIndex++; ?>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
        </section>
    </main>

    <button id="floating-chat-toggle" class="floating-chat-toggle" type="button" aria-label="Open <?= e(APP_NAME) ?> assistant" aria-expanded="false">
        <img class="floating-chat-logo" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="" aria-hidden="true">
    </button>

    <section id="floating-chat-panel" class="floating-chat-panel" aria-label="<?= e(APP_NAME) ?> assistant" hidden>
        <div class="floating-chat-header">
            <div>
                <strong>Dean Assistant</strong>
                <span>Department-level analysis</span>
            </div>
            <button id="floating-chat-close" type="button" aria-label="Close assistant">x</button>
        </div>
        <div id="chat-log" class="chat-log floating-chat-log">
            <div class="chat-message assistant"><div class="chat-bubble"><strong>Assistant</strong>Ask about department weak areas, training priorities, appraisal progress, or AI summaries.</div></div>
        </div>
        <form id="chat-form" class="chat-form floating-chat-form">
            <input id="chat-message" name="message" placeholder="Ask DIPASCAF assistant..." autocomplete="off">
            <button type="submit">Send</button>
        </form>
    </section>

    <script>
        const baseUrl = '<?= BASE_URL ?>';
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebarOverlay = document.querySelector('.sidebar-overlay');
        const sidebarCollapse = document.querySelector('.sidebar-collapse');
        const darkModeInput = document.querySelector('.dark-mode-input');
        const darkModeLabel = document.querySelector('.dark-mode-switch .sidebar-item-label');
        const chatToggle = document.getElementById('floating-chat-toggle');
        const chatPanel = document.getElementById('floating-chat-panel');
        const chatClose = document.getElementById('floating-chat-close');
        const adminAiTopPrompt = document.getElementById('admin-ai-top-prompt');
        const adminAiTopDismiss = document.getElementById('admin-ai-top-dismiss');

        if (localStorage.getItem('pmas-sidebar-collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }

        function syncThemeMode(enabled) {
            document.body.classList.toggle('dark-mode', enabled);
            if (darkModeInput) darkModeInput.checked = enabled;
            if (darkModeLabel) darkModeLabel.textContent = enabled ? 'Light Mode' : 'Dark Mode';
        }

        syncThemeMode(localStorage.getItem('pmas-dark-mode') === '1');

        if (sidebarCollapse) {
            sidebarCollapse.addEventListener('click', () => {
                const collapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('pmas-sidebar-collapsed', collapsed ? '1' : '0');
                sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            });
        }

        if (darkModeInput) {
            darkModeInput.addEventListener('change', () => {
                syncThemeMode(darkModeInput.checked);
                localStorage.setItem('pmas-dark-mode', darkModeInput.checked ? '1' : '0');
            });
        }

        function setSidebar(open) {
            document.body.classList.toggle('sidebar-open', open);
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                menuToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            }
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                setSidebar(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', () => setSidebar(false));
        }

        document.querySelectorAll('.sidebar-menu a, .sidebar-logout').forEach((link) => {
            link.addEventListener('click', () => setSidebar(false));
        });

        function setChatPanel(open) {
            if (!chatPanel || !chatToggle) return;
            chatPanel.hidden = !open;
            chatToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                const input = document.getElementById('chat-message');
                if (input) input.focus();
            }
        }

        if (chatToggle) {
            chatToggle.addEventListener('click', () => {
                setChatPanel(chatPanel ? chatPanel.hidden : true);
            });
        }

        if (chatClose) {
            chatClose.addEventListener('click', () => setChatPanel(false));
        }

        document.querySelectorAll('.admin-report-actions').forEach((actions) => {
            actions.addEventListener('click', (event) => {
                const trigger = event.target.closest('a.report-link, button');
                if (!trigger) return;
                actions.classList.add('is-loading');
                const label = trigger.querySelector('.report-button-text');
                if (label) label.textContent = trigger.tagName === 'A' ? 'Downloading' : 'Preparing';
            });
        });

        document.querySelectorAll('[data-chat-sample]').forEach((sampleButton) => {
            sampleButton.addEventListener('click', () => {
                const input = document.getElementById('chat-message');
                setChatPanel(true);
                if (input) {
                    input.value = sampleButton.dataset.chatSample || '';
                    input.focus();
                }
            });
        });

        function hideAdminAiTopPrompt() {
            if (!adminAiTopPrompt) return;
            adminAiTopPrompt.classList.remove('is-visible');
            window.setTimeout(() => {
                adminAiTopPrompt.hidden = true;
            }, 350);
        }

        if (adminAiTopPrompt) {
            adminAiTopPrompt.hidden = true;
            window.setTimeout(() => {
                adminAiTopPrompt.hidden = false;
                requestAnimationFrame(() => adminAiTopPrompt.classList.add('is-visible'));
                window.setTimeout(hideAdminAiTopPrompt, 7500);
            }, 2400);
        }

        if (adminAiTopDismiss) {
            adminAiTopDismiss.addEventListener('click', hideAdminAiTopPrompt);
        }

        const chatForm = document.getElementById('chat-form');
        if (chatForm) {
            chatForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const input = document.getElementById('chat-message');
                const log = document.getElementById('chat-log');
                const message = input.value.trim();
                if (!message) return;
                const escapeHtml = (value) => value.replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char]));
                log.insertAdjacentHTML('beforeend', `<div class="chat-message user"><div class="chat-bubble"><strong>You</strong>${escapeHtml(message)}</div></div>`);
                input.value = '';
                const response = await fetch(`${baseUrl}/api/assistant.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ message })
                });
                const payload = await response.json();
                log.insertAdjacentHTML('beforeend', `<div class="chat-message assistant"><div class="chat-bubble"><strong>Assistant</strong>${escapeHtml(payload.answer || '')}</div></div>`);
                log.scrollTop = log.scrollHeight;
            });
        }
    </script>

    <!-- ── Auto-refresh polling for live data ── -->
    <script>
    (function() {
        var refreshInterval = 45000;
        var isFormDirty = false;
        var currentSection = new URLSearchParams(window.location.search).get('section') || 'overview';
        var skipSections = ['evaluate'];
        if (skipSections.indexOf(currentSection) !== -1) return;

        document.addEventListener('input', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                isFormDirty = true;
            }
        });

        setInterval(function() {
            if (isFormDirty) return;
            fetch(baseUrl + '/api/notifications.php?action=unread_count&t=' + Date.now(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.ok !== false) {
                        var banner = document.getElementById('refresh-banner');
                        if (!banner) {
                            banner = document.createElement('div');
                            banner.id = 'refresh-banner';
                            banner.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1e293b;color:white;padding:12px 20px;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.25);display:flex;align-items:center;gap:12px;z-index:9999;font-size:14px;transform:translateY(20px);opacity:0;transition:all 0.3s ease;';
                            banner.innerHTML = '<span>\u{1f504} New data available</span><button onclick="location.reload()" style="background:#3b82f6;color:white;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">Refresh</button>';
                            document.body.appendChild(banner);
                            requestAnimationFrame(function() {
                                banner.style.transform = 'translateY(0)';
                                banner.style.opacity = '1';
                            });
                            setTimeout(function() {
                                banner.style.transform = 'translateY(20px)';
                                banner.style.opacity = '0';
                                setTimeout(function() { if (banner.parentNode) banner.remove(); }, 300);
                            }, 10000);
                        }
                    }
                })
                .catch(function() {});
        }, refreshInterval);
    })();
    </script>
</body>
</html>

