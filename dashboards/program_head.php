<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/program_head_data.php';
require_once __DIR__ . '/../includes/teacher_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

require_role('program_head');

$user = current_user();
$programHeadId = (int) $user['id'];
$programs = program_head_programs($programHeadId);
$departments = program_head_departments($programHeadId);
$section = $_GET['section'] ?? 'overview';
$allowedSections = ['overview', 'evaluate', 'summary', 'insights', 'training', 'results'];

if (!in_array($section, $allowedSections, true)) {
    $section = 'overview';
}

if ($section === 'training') {
    $section = 'summary';
}

function program_head_redirect(string $message = 'Saved successfully.'): never
{
    $_SESSION['flash'] = $message;
    redirect('/dashboards/program_head.php?section=evaluate');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
        redirect('/dashboards/program_head.php?section=' . $section);
    }

    try {
        if (($_POST['action'] ?? '') === 'submit_evaluation') {
            $result = dipascaf_submit_evaluation($programHeadId, 'program_head', 'Program Head submitted an evaluation.');
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true] + $result);
                exit;
            }
            program_head_redirect('Evaluation submitted.');
        }
    } catch (Throwable $exception) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
            exit;
        }
        $_SESSION['flash_error'] = $exception->getMessage();
        redirect('/dashboards/program_head.php?section=' . $section);
    }
}

$flash = $_SESSION['flash'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

$showProgramHeadAiPrompt = $section === 'overview' && empty($_SESSION['program_head_ai_prompt_shown']);
if ($showProgramHeadAiPrompt) {
    $_SESSION['program_head_ai_prompt_shown'] = true;
}
$programHeadAiSuggestions = [
    'Show faculty under my program.',
    'Who still has pending evaluations?',
    'View faculty evaluation results.',
    'Generate program performance summary.',
    'Show AI insights for faculty improvement.',
    'Check peer evaluation progress.',
    'View completed evaluations.',
    'Show faculty average ratings.',
    'Which faculty need seminars or training?',
    'Display evaluation statistics.',
];

dipascaf_init_evaluation_assignments($programHeadId, 'program_head');
$assignments = dipascaf_assignment_rows($programHeadId, 'program_head');
$faculty = program_head_faculty($departments, $programs);
$summary = program_head_summary($programHeadId, $departments, $programs);
$insights = program_head_ai_insights($departments, $programs, $programHeadId);
$interventions = program_head_interventions($departments, $programs);
$factors = admin_factors();
$programInsightLabel = $programs === []
    ? 'this program'
    : implode(', ', array_map(fn (array $program): string => (string) $program['program_code'], $programs));
$insightWeakCounts = [];
foreach ($insights as $insight) {
    $weakArea = (string) ($insight['weak_area'] ?? 'Unspecified');
    $insightWeakCounts[$weakArea] = ($insightWeakCounts[$weakArea] ?? 0) + 1;
}
arsort($insightWeakCounts);
$topInsightWeakArea = array_key_first($insightWeakCounts) ?: 'No weak area yet';
$nav = [
    'overview' => ['dashboard', 'Overview'],
    'evaluate' => ['evaluations', 'Evaluate'],
    'summary' => ['summary', 'Summary & Training'],
    'insights' => ['insights', 'Insights'],
    'results' => ['results', 'Results'],
];
$pageTitle = $nav[$section][1];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Head Dashboard | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=program-head-robot-4">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/evaluation-form.css">
    <style>
        .program-head-body .dean-content > .program-head-hero {
            position: relative !important;
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) 320px !important;
            align-items: center !important;
            min-height: 250px !important;
            overflow: hidden !important;
        }

        .program-head-body .dean-content > .program-head-hero > div:first-child {
            position: relative !important;
            z-index: 1 !important;
            min-width: 0 !important;
        }

        .program-head-body .dean-content > .program-head-hero .hero-illustration {
            position: relative !important;
            z-index: 1 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            align-self: stretch !important;
            justify-self: stretch !important;
            width: 100% !important;
            min-height: 100% !important;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            overflow: visible !important;
            pointer-events: none !important;
        }

        .program-head-body .dean-content > .program-head-hero .hero-illustration::before,
        .program-head-body .dean-content > .program-head-hero .hero-illustration::after {
            display: none !important;
            content: none !important;
        }

        .program-head-body .dean-content > .program-head-hero .hero-robot {
            position: static !important;
            inset: auto !important;
            width: clamp(150px, 11vw, 210px) !important;
            height: auto !important;
            max-width: none !important;
            max-height: none !important;
            margin: 0 !important;
            object-fit: contain !important;
            object-position: center !important;
            transform: none !important;
            filter: drop-shadow(0 12px 24px rgba(16, 185, 129, 0.18)) !important;
            animation: programHeadRobotFloat 4s ease-in-out infinite !important;
        }

        .program-head-body.dark-mode .dean-content > .program-head-hero .hero-robot {
            filter: drop-shadow(0 12px 26px rgba(59, 130, 246, 0.25)) !important;
        }

        @keyframes programHeadRobotFloat {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-6px);
            }
        }

        @media (max-width: 900px) {
            .program-head-body .dean-content > .program-head-hero {
                grid-template-columns: 1fr !important;
                min-height: auto !important;
            }

            .program-head-body .dean-content > .program-head-hero .hero-illustration {
                width: 100% !important;
                min-height: 170px !important;
                margin-top: 16px !important;
            }

            .program-head-body .dean-content > .program-head-hero .hero-robot {
                width: clamp(130px, 28vw, 170px) !important;
            }
        }

        @media (max-width: 640px) {
            .program-head-body .dean-content > .program-head-hero .hero-illustration {
                min-height: 140px !important;
            }

            .program-head-body .dean-content > .program-head-hero .hero-robot {
                width: clamp(100px, 34vw, 140px) !important;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body class="admin-body dean-body program-head-body">
    <button class="sidebar-overlay" type="button" aria-label="Close menu"></button>
    <aside class="admin-sidebar" aria-label="Program Head navigation">
        <div class="sidebar-brand">
            <span class="brand-icon">P</span>
            <span class="sidebar-brand-copy">
                <strong><?= e(APP_NAME) ?></strong>
                <small>Program Head</small>
            </span>
            <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar"></button>
        </div>
        <nav class="sidebar-menu">
            <?php foreach ($nav as $key => [$icon, $label]): ?>
                <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboards/program_head.php?section=<?= e($key) ?>">
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
                <p class="admin-header-note">Program-Level Faculty Evaluation and Development Monitoring</p>
            </div>
            <div class="admin-search dean-header-context">
                <span><?= e($programs[0]['program_code'] ?? implode(', ', $departments)) ?></span>
            </div>
            <div class="admin-actions" aria-label="Program Head metrics and profile">
                <button class="notification-button" type="button" aria-label="Pending evaluations">
                    <span class="notification-badge"><?= e((string) $summary['pending']) ?></span>
                </button>
                <button class="notification-button warning" type="button" aria-label="Submitted reviews">
                    <span class="notification-badge"><?= e((string) $summary['submitted']) ?></span>
                </button>
                <button class="profile-button" type="button" aria-label="Program Head profile"><span class="admin-avatar"><?= e(strtoupper(substr((string) ($user['full_name'] ?? 'P'), 0, 1))) ?></span></button>
            </div>
        </header>

        <section class="admin-content admin-module dean-content">
        <?php if ($flash !== ''): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($flashError !== ''): ?><div class="notice error"><?= e($flashError) ?></div><?php endif; ?>

        <?php if ($section === 'overview'): ?>
        <?php if ($showProgramHeadAiPrompt): ?>
        <div id="admin-ai-top-prompt" class="admin-ai-top-prompt module-wide" role="status" aria-live="polite">
            <div class="admin-ai-top-icon" aria-hidden="true">
                <img src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="">
            </div>
            <div class="admin-ai-top-copy">
                <p>Hi Program Head! You can quickly manage faculty evaluations here.</p>
                <div class="admin-ai-suggestions">
                    <?php foreach ($programHeadAiSuggestions as $suggestion): ?>
                        <button type="button" data-chat-sample="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <button id="admin-ai-top-dismiss" type="button" aria-label="Dismiss AI message">x</button>
        </div>
        <?php endif; ?>
        <div class="hero-card program-head-hero module-wide">
            <div>
                <h2>Welcome back, <?= e(display_name($user['full_name'])) ?></h2>
                <p>Program-level dashboard for <?= e($programs[0]['program_code'] ?? implode(', ', $departments)) ?>. Evaluate assigned faculty, monitor pending reviews, and prepare improvement plans for Dean review.</p>
            </div>
            <div class="hero-illustration" aria-hidden="true">
                <img class="hero-robot" src="<?= BASE_URL ?>/assets/images/ROBOT%203.svg" alt="">
            </div>
        </div>

        <div class="metric-card gold"><span>Faculty</span><strong><?= e((string) $summary['facultyCount']) ?></strong><small>Under review</small><div class="metric-chart"></div></div>
        <div class="metric-card coral"><span>Pending</span><strong><?= e((string) $summary['pending']) ?></strong><small>Reviews</small><div class="metric-list"></div></div>

        <section class="admin-box stat-grid module-wide">
            <article><span>Assigned Program</span><strong><?= e($programs[0]['program_code'] ?? 'Program') ?></strong><small><?= e($programs[0]['program_name'] ?? implode(', ', $departments)) ?></small></article>
            <article><span>Submitted Reviews</span><strong><?= e((string) $summary['submitted']) ?></strong></article>
            <article><span>Completion Rate</span><strong><?= e((string) round(($summary['submitted'] ?? 0) / (count($assignments) ?: 1) * 100)) ?>%</strong></article>
            <article><span>AI Insights</span><strong><?= e((string) count($insights)) ?></strong></article>
            <article><span>Training Plans</span><strong><?= e((string) count($interventions)) ?></strong></article>
            <article><span>Active Departments</span><strong><?= e((string) count($departments)) ?></strong></article>
        </section>
        <?php endif; ?>

        <?php if ($section === 'evaluate'): ?>
            <?php dipascaf_render_evaluation_dashboard([
                'assignments' => $assignments,
                'eyebrow' => 'Program Head Evaluation',
                'title' => 'Evaluate Assigned Faculty and Peers',
                'subtitle' => 'Review every assigned Faculty, Program Head, Dean, and Peer appraisal card under your program using the same focused evaluation workspace as the Dean dashboard.',
                'defaultSection' => 'all',
            ]); ?>
        <?php endif; ?>

        <?php if ($section === 'summary'): ?>
        <section class="admin-box module-table">
            <div class="box-title">
                <h2>Program Summary and Training Plans</h2>
                <span>Common issues and factor weights</span>
            </div>
            <div class="stat-grid compact">
                <?php foreach ($summary['weakAreas'] as $area): ?>
                    <article><span><?= e($area['program_code']) ?></span><strong><?= e($area['weak_area']) ?></strong><small><?= e((string) $area['weak_count']) ?> faculty member(s)</small></article>
                <?php endforeach; ?>
                <?php foreach ($factors as $factor): ?>
                    <article><span>Factor Weight</span><strong><?= e($factor['factor_name']) ?></strong><small><?= e((string) $factor['weight_percent']) ?>%</small></article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="admin-box module-table">
            <div class="box-title">
                <h2>Recommended Training and Development Plans by Program</h2>
                <span>Training, mentoring, coaching</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Program</th><th>Faculty</th><th>Weak Area</th><th>Recommendation</th><th>Action Type</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($interventions as $plan): ?>
                        <tr>
                            <td data-label="Program"><?= e($plan['program_code']) ?></td>
                            <td data-label="Faculty"><?= e($plan['faculty_name']) ?></td>
                            <td data-label="Weak Area"><?= e($plan['weak_area']) ?></td>
                            <td data-label="Recommendation"><?= e($plan['recommendation']) ?></td>
                            <td data-label="Action Type"><?= e(admin_status_label($plan['action_type'])) ?></td>
                            <td data-label="Status"><?= e(admin_status_label($plan['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($interventions === []): ?>
                        <tr><td colspan="6">No training plans are listed for this program yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <?php if ($section === 'results'): ?>
        <section class="admin-box module-wide">
            <?php require __DIR__ . '/../includes/program_faculty_summary.php'; ?>
        </section>

            <?php
            // Faculty lookup for program head's own category comparison
            $phFaculty = admin_one(
                'SELECT f.id, f.full_name FROM faculty f JOIN users u ON u.email = f.email WHERE u.id = :uid LIMIT 1',
                ['uid' => $programHeadId]
            );
            $phFacultyId = $phFaculty !== null ? (int) $phFaculty['id'] : 0;
            $phSelectedPeriod = $_GET['period'] ?? '';
            $phSelectedPeriodA = $_GET['period_a'] ?? null;
            $phSelectedPeriodB = $_GET['period_b'] ?? null;
            $phComparisonData = $phFacultyId > 0 ? teacher_category_comparison($phFacultyId, $phSelectedPeriodA, $phSelectedPeriodB) : [];
            $phFactorScores = $phFacultyId > 0 ? teacher_factor_scores($phFacultyId, $phSelectedPeriod ?: null) : [];
            $phWeightedTotal = (float) ($phFactorScores['_weightedTotal'] ?? 0);
            unset($phFactorScores['_weightedTotal']);

            $phPeriodNames = $phComparisonData['periods'] ?? [];
            $phFactorScoresLatest = [];
            $phFactorScoresPrev = [];
            $phFactorLatestLabel = '';
            $phFactorPrevLabel = '';

            // All available periods for the selector
            $phAvailablePeriods = admin_periods();

            if ($phFacultyId > 0) {
                // Determine primary period
                if ($phSelectedPeriod !== '') {
                    $primaryPeriod = $phSelectedPeriod;
                } elseif (!empty($phPeriodNames)) {
                    $sortedPs = $phPeriodNames;
                    sort($sortedPs);
                    $primaryPeriod = $sortedPs[count($sortedPs) - 1];
                } else {
                    $primaryPeriod = '';
                }

                // Determine comparison period
                $comparePeriod = '';
                if ($phSelectedPeriodB !== null && $phSelectedPeriodB !== $primaryPeriod) {
                    $comparePeriod = $phSelectedPeriodB;
                } elseif ($primaryPeriod !== '' && !empty($phPeriodNames) && count($phPeriodNames) >= 2) {
                    $sortedPs = $phPeriodNames;
                    sort($sortedPs);
                    $idx = array_search($primaryPeriod, $sortedPs, true);
                    if ($idx === false || $idx === 0) {
                        $comparePeriod = $sortedPs[count($sortedPs) - 2];
                    } else {
                        $comparePeriod = $sortedPs[$idx - 1];
                    }
                }

                $rawC = teacher_factor_scores($phFacultyId, $primaryPeriod ?: null);
                unset($rawC['_weightedTotal']);
                if (!empty($rawC)) {
                    $phFactorScoresLatest = array_values(array_filter($rawC, 'is_array'));
                    $phFactorLatestLabel = $primaryPeriod ?: 'All Periods';
                }

                if ($comparePeriod !== '' && $comparePeriod !== $primaryPeriod) {
                    $rawP = teacher_factor_scores($phFacultyId, $comparePeriod);
                    unset($rawP['_weightedTotal']);
                    if (!empty($rawP)) {
                        $phFactorScoresPrev = array_values(array_filter($rawP, 'is_array'));
                        $phFactorPrevLabel = $comparePeriod;
                    }
                }
            }
            ?>
            <?php if ($phFacultyId > 0 && !empty($phComparisonData['comparison'])):
                $pcc = $phComparisonData['comparison'];
            ?>
            <section class="admin-box module-table" style="margin-top:24px;">
                <div class="box-title">
                    <h2>Category Comparison: <?= e($pcc['periodA'] ?? 'Earlier') ?> vs <?= e($pcc['periodB'] ?? 'Latest') ?></h2>
                    <span>Period-over-period category score changes</span>
                </div>

                <div class="period-selector" style="display:flex;gap:16px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border,#e2e8f0);flex-wrap:wrap;">
                    <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Compare:</label>
                    <select id="phPeriodASelect" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <?php foreach (($phComparisonData['periods'] ?? []) as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === $pcc['periodA'] ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="font-size:14px;color:var(--text-muted,#94a3b8);">vs</span>
                    <select id="phPeriodBSelect" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <?php foreach (($phComparisonData['periods'] ?? []) as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === $pcc['periodB'] ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="phCompareBtn" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:#3b82f6;color:#fff;transition:background 0.15s;">Compare</button>
                </div>

                <script>
                (function() {
                    var btn = document.getElementById('phCompareBtn');
                    var selA = document.getElementById('phPeriodASelect');
                    var selB = document.getElementById('phPeriodBSelect');
                    if (!btn || !selA || !selB) return;
                    function syncOpts() {
                        Array.from(selB.options).forEach(function(o) { o.disabled = o.value === selA.value; });
                        Array.from(selA.options).forEach(function(o) { o.disabled = o.value === selB.value; });
                    }
                    selA.addEventListener('change', syncOpts);
                    selB.addEventListener('change', syncOpts);
                    syncOpts();
                    btn.addEventListener('click', function() {
                        var params = new URLSearchParams(window.location.search);
                        params.set('period_a', selA.value);
                        params.set('period_b', selB.value);
                        params.set('section', 'results');
                        window.location.search = '?' + params.toString();
                    });
                    [selA, selB].forEach(function(s) {
                        s.addEventListener('keydown', function(e) { if (e.key === 'Enter') btn.click(); });
                    });
                })();
                </script>

                <div class="stat-grid compact" style="padding:12px 16px;">
                    <article style="border-left:3px solid #166a45;"><span>Improved</span><strong style="color:#166a45;"><?= e((string) ($pcc['summary']['improved'] ?? 0)) ?></strong><small>grew</small></article>
                    <article style="border-left:3px solid #b45309;"><span>Declined</span><strong style="color:#b45309;"><?= e((string) ($pcc['summary']['declined'] ?? 0)) ?></strong><small>dropped</small></article>
                    <article style="border-left:3px solid #64748b;"><span>Stable</span><strong style="color:#64748b;"><?= e((string) ($pcc['summary']['stable'] ?? 0)) ?></strong><small>unchanged</small></article>
                    <article style="border-left:3px solid #3b82f6;"><span>New</span><strong style="color:#3b82f6;"><?= e((string) ($pcc['summary']['new'] ?? 0)) ?></strong><small>newly evaluated</small></article>
                </div>

                <div class="chart-container" style="position:relative;height:280px;margin:0 8px 24px;">
                    <canvas id="phCategoryChart"></canvas>
                </div>

                <script id="phComparisonData" type="application/json"><?= json_encode(['categories' => $pcc['categories'], 'periodA' => $pcc['periodA'] ?? 'Earlier', 'periodB' => $pcc['periodB'] ?? 'Latest']) ?></script>

                <table class="data-table">
                    <thead><tr><th>Category</th><th>Form</th><th><?= e($pcc['periodA'] ?? 'Earlier') ?></th><th><?= e($pcc['periodB'] ?? 'Latest') ?></th><th>Change</th><th>Direction</th></tr></thead>
                    <tbody>
                        <?php foreach ($pcc['categories'] as $cat):
                            $dir = (string) ($cat['direction'] ?? '');
                            $change = $cat['change'];
                            $color = match ($dir) { 'improved' => '#166a45', 'declined' => '#b45309', 'stable' => '#64748b', 'new' => '#3b82f6', default => '#94a3b8' };
                            $arrow = match ($dir) { 'improved' => '▲', 'declined' => '▼', 'stable' => '→', 'new' => '✦', default => '-' };
                        ?>
                            <tr>
                                <td data-label="Category"><strong><?= e($cat['category'] ?? '') ?></strong></td>
                                <td data-label="Form"><?= e($cat['formType'] ?? '') ?></td>
                                <td data-label="Earlier"><?= $cat['periodAScore'] !== null ? e(number_format((float) $cat['periodAScore'], 2)) : '<span class="muted">—</span>' ?></td>
                                <td data-label="Latest"><?= $cat['periodBScore'] !== null ? e(number_format((float) $cat['periodBScore'], 2)) : '<span class="muted">—</span>' ?></td>
                                <td data-label="Change" style="color:<?= e($color) ?>;font-weight:700;"><?= $change !== null ? ($change > 0 ? '+' : '') . e(number_format((float) $change, 2)) : '<span class="muted">—</span>' ?></td>
                                <td data-label="Direction" style="color:<?= e($color) ?>;"><?= $arrow ?> <?= e(ucfirst($dir)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <script>
            (function() {
                var dataEl = document.getElementById('phComparisonData');
                var canvas = document.getElementById('phCategoryChart');
                if (!dataEl || !canvas || typeof Chart === 'undefined') return;
                var chartData = JSON.parse(dataEl.textContent);
                var cats = chartData.categories || [];
                if (!cats || cats.length === 0) return;
                var labels = cats.map(function(c) { return c.category || ''; });
                var scoresA = cats.map(function(c) { return c.periodAScore; });
                var scoresB = cats.map(function(c) { return c.periodBScore; });
                var dirs = cats.map(function(c) { return c.direction || 'stable'; });
                var colorMap = { improved: '#166a45', declined: '#b45309', stable: '#64748b', new: '#3b82f6' };
                var barColors = dirs.map(function(d) { return colorMap[d] || '#94a3b8'; });
                var isDark = document.body.classList.contains('dark-mode');
                var gColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
                var tColor = isDark ? '#cbd5e1' : '#475569';
                var chart = new Chart(canvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: chartData.periodA || 'Earlier', data: scoresA, backgroundColor: barColors.map(function(c){return c+'99'}), borderColor: barColors, borderWidth: 2, borderRadius: 4, barPercentage: 0.4, categoryPercentage: 0.8 },
                            { label: chartData.periodB || 'Latest', data: scoresB, backgroundColor: barColors.map(function(c){return c+'CC'}), borderColor: barColors, borderWidth: 2, borderRadius: 4, barPercentage: 0.4, categoryPercentage: 0.8 }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { labels: { color: tColor, font: { size: 13, weight: '600' }, padding: 16 } },
                            tooltip: {
                                backgroundColor: isDark ? '#1e293b' : '#ffffff', titleColor: isDark ? '#f1f5f9' : '#1e293b', bodyColor: tColor,
                                borderColor: isDark ? '#334155' : '#e2e8f0', borderWidth: 1, padding: 12, cornerRadius: 8, usePointStyle: true,
                                callbacks: {
                                    afterBody: function(items) {
                                        var idx = items[0].dataIndex;
                                        var c = cats[idx];
                                        if (c.change === null || c.change === undefined) return '';
                                        var sign = c.change > 0 ? '+' : '';
                                        var emoji = c.direction === 'improved' ? ' ▲' : c.direction === 'declined' ? ' ▼' : c.direction === 'new' ? ' ✦' : ' →';
                                        return 'Change: ' + sign + Number(c.change).toFixed(2) + emoji;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, max: 5, grid: { color: gColor }, ticks: { color: tColor, stepSize: 0.5 }, title: { display: true, text: 'Score (1–5)', color: tColor, font: { size: 12, weight: '600' } } },
                            x: { grid: { display: false }, ticks: { color: tColor, font: { size: 11 }, maxRotation: 30 } }
                        }
                    }
                });
                var obs = new MutationObserver(function() {
                    var dn = document.body.classList.contains('dark-mode');
                    var gc = dn ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
                    var tc = dn ? '#cbd5e1' : '#475569';
                    chart.options.scales.y.grid.color = gc;
                    chart.options.scales.y.ticks.color = tc;
                    chart.options.scales.y.title.color = tc;
                    chart.options.scales.x.ticks.color = tc;
                    chart.options.plugins.legend.labels.color = tc;
                    chart.options.plugins.tooltip.backgroundColor = dn ? '#1e293b' : '#ffffff';
                    chart.options.plugins.tooltip.titleColor = dn ? '#f1f5f9' : '#1e293b';
                    chart.options.plugins.tooltip.bodyColor = tc;
                    chart.options.plugins.tooltip.borderColor = dn ? '#334155' : '#e2e8f0';
                    chart.update('none');
                });
                obs.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            })();
            </script>

            <?php if (!empty($phFactorScores) || !empty($phFactorScoresLatest)): ?>
            <section class="admin-box module-table" style="margin-top:24px;">
                <div class="box-title">
                    <h2>Performance Profile</h2>
                    <span>Radar view of factor scores (1–5 scale)</span>
                </div>

                <!-- Period Selector -->
                <div class="period-selector" style="display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border,#e2e8f0);flex-wrap:wrap;">
                    <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Period:</label>
                    <select id="phRadarPeriod" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <option value="">All periods</option>
                        <?php foreach ($phAvailablePeriods as $ap): ?>
                            <?php $pn = (string) ($ap['period_name'] ?? ''); ?>
                            <?php if ($pn !== ''): ?>
                            <option value="<?= e($pn) ?>" <?= $phSelectedPeriod === $pn ? 'selected' : '' ?>><?= e($pn) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Compare:</label>
                    <select id="phRadarCompare" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <option value="">No comparison</option>
                        <?php foreach ($phAvailablePeriods as $ap): ?>
                            <?php $pn = (string) ($ap['period_name'] ?? ''); ?>
                            <?php if ($pn !== ''): ?>
                            <option value="<?= e($pn) ?>" <?= ($phSelectedPeriodB ?? '') === $pn && (string) $pn !== $phSelectedPeriod ? 'selected' : '' ?>><?= e($pn) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="phRadarApplyBtn" class="period-apply-btn" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:#3b82f6;color:#fff;transition:background 0.15s;">Apply</button>
                </div>

                <div style="position:relative;max-width:420px;height:320px;margin:8px auto 24px;">
                    <canvas id="phFactorRadarChart"></canvas>
                </div>
                <script id="phFactorRadarData" type="application/json"><?= json_encode([
                    'periodA' => $phFactorLatestLabel,
                    'periodB' => $phFactorPrevLabel,
                    'currentScores' => $phFactorScoresLatest,
                    'previousScores' => $phFactorScoresPrev,
                ]) ?></script>
            </section>

            <script>
            (function() {
                var periodSelect = document.getElementById('phRadarPeriod');
                var compareSelect = document.getElementById('phRadarCompare');
                var applyBtn = document.getElementById('phRadarApplyBtn');
                if (!periodSelect || !applyBtn) return;

                // Disable selected period option in comparison dropdown
                function syncCompareOpts() {
                    var selectedPeriod = periodSelect.value;
                    Array.from(compareSelect.options).forEach(function(opt) {
                        opt.disabled = opt.value !== '' && opt.value === selectedPeriod;
                    });
                }

                periodSelect.addEventListener('change', syncCompareOpts);
                syncCompareOpts();

                applyBtn.addEventListener('click', function() {
                    var params = new URLSearchParams(window.location.search);
                    params.set('section', 'results');

                    var period = periodSelect.value;
                    var compare = compareSelect.value;

                    if (period) {
                        params.set('period', period);
                        params.delete('period_a');
                        if (compare && compare !== period) {
                            params.set('period_b', compare);
                        } else {
                            params.delete('period_b');
                        }
                    } else {
                        params.delete('period');
                        params.delete('period_a');
                        params.delete('period_b');
                    }

                    window.location.search = '?' + params.toString();
                });

                [periodSelect, compareSelect].forEach(function(s) {
                    s.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') applyBtn.click();
                    });
                });
            })();
            </script>

            <script>
            (function() {
                var dataEl = document.getElementById('phFactorRadarData');
                var canvas = document.getElementById('phFactorRadarChart');
                if (!dataEl || !canvas || typeof Chart === 'undefined') return;

                var data = JSON.parse(dataEl.textContent);
                var currentScores = data.currentScores || [];
                var previousScores = data.previousScores || [];
                var hasOverlay = previousScores.length > 0 && currentScores.length > 0;
                var scores = hasOverlay ? currentScores : (currentScores.length > 0 ? currentScores : previousScores);
                if (!scores || scores.length === 0) return;

                var labels = scores.map(function(f) { return f.factor || ''; });

                var isDark = document.body.classList.contains('dark-mode');
                var gridColor = isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)';
                var labelColor = isDark ? '#cbd5e1' : '#475569';

                function currentColorScheme(dn) {
                    return {
                        bg: dn ? 'rgba(59,130,246,0.15)' : 'rgba(31,122,79,0.12)',
                        border: dn ? '#3b82f6' : '#1f7a4f',
                        point: dn ? '#60a5fa' : '#2e965f',
                        pointBorder: dn ? '#1e293b' : '#ffffff'
                    };
                }

                function previousColorScheme(dn) {
                    return {
                        bg: dn ? 'rgba(251,146,60,0.10)' : 'rgba(180,83,9,0.08)',
                        border: dn ? '#fb923c' : '#b45309',
                        point: dn ? '#fdba74' : '#d97706',
                        pointBorder: dn ? '#1e293b' : '#ffffff'
                    };
                }

                var cur = currentColorScheme(isDark);
                var prev = previousColorScheme(isDark);

                var datasets = [{
                    label: data.periodA || 'Current',
                    data: currentScores.map(function(f) { return f.score || 0; }),
                    backgroundColor: cur.bg,
                    borderColor: cur.border,
                    borderWidth: 3,
                    pointBackgroundColor: cur.point,
                    pointBorderColor: cur.pointBorder,
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }];

                if (hasOverlay) {
                    datasets.push({
                        label: data.periodB || 'Previous',
                        data: previousScores.map(function(f) { return f.score || 0; }),
                        backgroundColor: prev.bg,
                        borderColor: prev.border,
                        borderWidth: 2,
                        borderDash: [5, 3],
                        pointBackgroundColor: prev.point,
                        pointBorderColor: prev.pointBorder,
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    });
                }

                var chart = new Chart(canvas.getContext('2d'), {
                    type: 'radar',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: hasOverlay,
                                position: 'bottom',
                                labels: {
                                    color: labelColor,
                                    font: { size: 12, weight: '600' },
                                    padding: 16,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                backgroundColor: isDark ? '#1e293b' : '#ffffff',
                                titleColor: isDark ? '#f1f5f9' : '#1e293b',
                                bodyColor: labelColor,
                                borderColor: isDark ? '#334155' : '#e2e8f0',
                                borderWidth: 1,
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.r.toFixed(2) + ' / 5.00';
                                    }
                                }
                            }
                        },
                        scales: {
                            r: {
                                beginAtZero: true,
                                max: 5,
                                grid: { color: gridColor },
                                angleLines: { color: gridColor },
                                pointLabels: {
                                    color: labelColor,
                                    font: { size: 12, weight: '600' }
                                },
                                ticks: {
                                    backdropColor: 'transparent',
                                    color: labelColor,
                                    font: { size: 10 },
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });

                var obs = new MutationObserver(function() {
                    var dn = document.body.classList.contains('dark-mode');
                    var opts = chart.options;
                    opts.scales.r.grid.color = dn ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)';
                    opts.scales.r.angleLines.color = dn ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)';
                    opts.scales.r.pointLabels.color = dn ? '#cbd5e1' : '#475569';
                    opts.scales.r.ticks.color = dn ? '#cbd5e1' : '#475569';
                    opts.plugins.legend.labels.color = dn ? '#cbd5e1' : '#475569';

                    var c = currentColorScheme(dn);
                    chart.data.datasets[0].backgroundColor = c.bg;
                    chart.data.datasets[0].borderColor = c.border;
                    chart.data.datasets[0].pointBackgroundColor = c.point;
                    chart.data.datasets[0].pointBorderColor = c.pointBorder;

                    if (chart.data.datasets.length > 1) {
                        var p = previousColorScheme(dn);
                        chart.data.datasets[1].backgroundColor = p.bg;
                        chart.data.datasets[1].borderColor = p.border;
                        chart.data.datasets[1].pointBackgroundColor = p.point;
                        chart.data.datasets[1].pointBorderColor = p.pointBorder;
                    }

                    opts.plugins.tooltip.backgroundColor = dn ? '#1e293b' : '#ffffff';
                    opts.plugins.tooltip.titleColor = dn ? '#f1f5f9' : '#1e293b';
                    opts.plugins.tooltip.bodyColor = dn ? '#cbd5e1' : '#475569';
                    opts.plugins.tooltip.borderColor = dn ? '#334155' : '#e2e8f0';
                    chart.update();
                });
                obs.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            })();
            </script>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'insights'): ?>
        <section class="program-insights-shell module-wide">
            <div class="program-insights-hero">
                <div>
                    <span class="eyebrow">Faculty Insights</span>
                    <h2>Faculty Performance Analysis</h2>
                    <p>Focused AI review for faculty assigned to <?= e($programInsightLabel) ?>. Use these signals to prioritize coaching, seminars, and follow-up conversations.</p>
                </div>
                <div class="program-insights-scope">
                    <span>Program Scope</span>
                    <strong><?= e($programInsightLabel) ?></strong>
                </div>
            </div>

            <div class="program-insights-stats">
                <article>
                    <span>Faculty Reviewed</span>
                    <strong><?= e((string) count($insights)) ?></strong>
                    <small>With AI-generated insight records</small>
                </article>
                <article>
                    <span>Priority Weak Area</span>
                    <strong><?= e($topInsightWeakArea) ?></strong>
                    <small><?= e((string) ($insightWeakCounts[$topInsightWeakArea] ?? 0)) ?> detected case(s)</small>
                </article>
                <article>
                    <span>Development Plans</span>
                    <strong><?= e((string) count($interventions)) ?></strong>
                    <small>Recommended actions linked to this scope</small>
                </article>
            </div>

            <div class="program-insights-grid">
                <?php foreach ($insights as $insight): ?>
                    <?php
                        $confidence = max(0, min(100, (float) ($insight['confidence_score'] ?? 0)));
                        $matchingPlan = null;
                        foreach ($interventions as $plan) {
                            if ((int) ($plan['faculty_id'] ?? 0) === (int) ($insight['faculty_id'] ?? 0)
                                && strcasecmp((string) ($plan['weak_area'] ?? ''), (string) ($insight['weak_area'] ?? '')) === 0) {
                                $matchingPlan = $plan;
                                break;
                            }
                        }
                    ?>
                    <article class="program-insight-card">
                        <div class="program-insight-head">
                            <div>
                                <span><?= e($insight['program_code']) ?></span>
                                <h3><?= e($insight['faculty_name']) ?></h3>
                            </div>
                            <strong><?= e(number_format($confidence, 0)) ?>%</strong>
                        </div>

                        <div class="program-insight-signals">
                            <div class="signal strength">
                                <span>Strength</span>
                                <strong><?= e($insight['strength_area'] ?? 'Not specified') ?></strong>
                            </div>
                            <div class="signal weak">
                                <span>Needs Attention</span>
                                <strong><?= e($insight['weak_area']) ?></strong>
                            </div>
                        </div>

                        <p><?= e($insight['analysis_summary']) ?></p>

                        <div class="program-insight-confidence eval-confidence-bar" aria-label="Confidence <?= e(number_format($confidence, 0)) ?> percent">
                            <span style="width: <?= e((string) $confidence) ?>%;"></span>
                        </div>

                        <div class="program-insight-action">
                            <span>Recommended Action</span>
                            <strong><?= e($matchingPlan['recommendation'] ?? 'Schedule a focused coaching conversation and monitor the next appraisal cycle.') ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ($insights === []): ?>
                    <article class="program-insight-empty">
                        <strong>No faculty insights yet</strong>
                        <p>AI insights will appear here once faculty under <?= e($programInsightLabel) ?> have submitted evaluation data.</p>
                    </article>
                <?php endif; ?>
            </div>
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
                <strong>Program AI Assistant</strong>
                <span>Program-level analysis</span>
            </div>
            <button id="floating-chat-close" type="button" aria-label="Close assistant">x</button>
        </div>
        <div id="chat-log" class="chat-log floating-chat-log">
            <div class="chat-message assistant"><div class="chat-bubble"><strong>Assistant</strong>Ask about weak areas, training priorities, program trends, or pending appraisal tasks.</div></div>
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
        const chatForm = document.getElementById('chat-form');

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

