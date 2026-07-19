<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/vpaa_data.php';
require_once __DIR__ . '/../includes/teacher_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

require_role('vpaa');

$user = current_user();
$vpaaId = (int) ($user['id'] ?? 0);
$departments = vpaa_departments($vpaaId);
$selectedDepartment = trim((string) ($_GET['department'] ?? ''));
$selectedPeriod = trim((string) ($_GET['period'] ?? ''));
$scopedDepartments = $selectedDepartment !== '' && in_array($selectedDepartment, $departments, true)
    ? [$selectedDepartment]
    : $departments;

$summary = vpaa_summary($scopedDepartments);
$assignments = vpaa_assignments($scopedDepartments, $selectedPeriod);
$weakAreas = vpaa_weak_areas($scopedDepartments);
$interventions = vpaa_interventions($scopedDepartments);
$departmentPerformance = vpaa_department_performance($scopedDepartments);
$latestPeriod = vpaa_latest_period();
$periods = admin_periods();

// Aggregate factor scores for radar chart (VPAA-wide view)
$vpaaSelectedPeriod = $selectedPeriod ?: ($latestPeriod['period_name'] ?? '');
$vpaaFactorScores = teacher_factor_scores_aggregate($vpaaSelectedPeriod ?: null, $scopedDepartments);
$vpaaComparePeriod = trim((string) ($_GET['compare_period'] ?? ''));
$vpaaCompareScores = [];
$vpaaCompareLabel = '';
if ($vpaaComparePeriod !== '' && $vpaaComparePeriod !== $vpaaSelectedPeriod) {
    $rawCompare = teacher_factor_scores_aggregate($vpaaComparePeriod, $scopedDepartments);
    if (!empty($rawCompare)) {
        $vpaaCompareScores = $rawCompare;
        $vpaaCompareLabel = $vpaaComparePeriod;
    }
}

// Select latest period for default display if none selected
if ($vpaaSelectedPeriod === '' && $vpaaFactorScores === [] && $latestPeriod !== null) {
    $vpaaSelectedPeriod = (string) ($latestPeriod['period_name'] ?? '');
    $vpaaFactorScores = teacher_factor_scores_aggregate($vpaaSelectedPeriod ?: null, $scopedDepartments);
}

$vpaaPeriodNames = array_values(array_unique(array_filter(array_map(
    fn (array $p): string => (string) ($p['period_name'] ?? ''),
    $periods
))));

dipascaf_init_evaluation_assignments($vpaaId, 'vpaa');
$evaluationCardAssignments = dipascaf_assignment_rows($vpaaId, 'vpaa');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_evaluation') {
    header('Content-Type: application/json');
    try {
        $result = dipascaf_submit_evaluation($vpaaId, 'vpaa', 'VPAA submitted a Dean evaluation.');
        echo json_encode(['success' => true] + $result);
    } catch (Throwable $exception) {
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

$weakAreaCounts = [];
foreach ($weakAreas as $weakArea) {
    $key = (string) ($weakArea['weak_area'] ?? 'Unspecified');
    $weakAreaCounts[$key] = ($weakAreaCounts[$key] ?? 0) + 1;
}
arsort($weakAreaCounts);

$lowestDepartments = $departmentPerformance;
usort($lowestDepartments, static fn (array $a, array $b): int => ($a['averageRating'] ?? 999) <=> ($b['averageRating'] ?? 999));

$facultyNeedingIntervention = array_values(array_filter($interventions, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['planned', 'assigned'], true)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VPAA Dashboard | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/evaluation-form.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="admin-body dean-body">
    <button class="sidebar-overlay" type="button" aria-label="Close menu"></button>
    <aside class="admin-sidebar dean-sidebar" aria-label="VPAA navigation">
        <div class="sidebar-brand">
            <span class="brand-icon">V</span>
            <span class="sidebar-brand-copy">
                <strong><?= e(APP_NAME) ?></strong>
                <small>VPAA Portal</small>
            </span>
            <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar"></button>
        </div>
        <nav class="sidebar-menu">
            <a class="active" href="<?= BASE_URL ?>/dashboards/vpaa.php"><span class="menu-icon" data-icon="dashboard"></span><span class="sidebar-item-label">Overview</span></a>
            <a href="#assignments"><span class="menu-icon" data-icon="evaluations"></span><span class="sidebar-item-label">Assignments</span></a>
            <a href="#analytics"><span class="menu-icon" data-icon="insights"></span><span class="sidebar-item-label">Analytics</span></a>
            <a href="#reports"><span class="menu-icon" data-icon="reports"></span><span class="sidebar-item-label">Reports</span></a>
        </nav>
        <div class="sidebar-bottom">
            <a class="sidebar-logout" href="<?= BASE_URL ?>/logout.php"><span class="menu-icon" data-icon="logout"></span><span class="sidebar-item-label">Logout</span></a>
            <label class="dark-mode-switch">
                <span class="menu-icon" data-icon="moon"></span>
                <span class="sidebar-item-label">Dark Mode</span>
                <input class="dark-mode-input" type="checkbox" aria-label="Toggle dark mode">
                <span class="toggle-track" aria-hidden="true"></span>
            </label>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
            <div class="admin-header-info">
                <h1>VPAA Academic Oversight</h1>
                <p class="admin-header-note">Monitoring <?= e(implode(', ', $departments)) ?></p>
            </div>
            <div class="admin-search dean-header-context"><span><?= e($latestPeriod['period_name'] ?? 'Latest Evaluation Period') ?></span></div>
            <div class="admin-actions">
                <span class="action-dot"><?= e((string) $summary['pendingEvaluations']) ?></span>
                <span class="action-dot yellow"><?= e((string) $summary['completedEvaluations']) ?></span>
                <div class="admin-avatar"><?= e(strtoupper(substr((string) ($user['full_name'] ?? 'V'), 0, 1))) ?></div>
            </div>
        </header>

        <section class="admin-content admin-module dean-content reports-analytics-content">
            <div class="hero-card module-wide">
                <div>
                    <h2>Welcome, <?= e(display_name($user['full_name'] ?? 'VPAA')) ?></h2>
                    <p>High-level academic monitoring for assigned departments, evaluation completion, weak-area trends, and intervention follow-through.</p>
                </div>
                <div class="hero-illustration" aria-hidden="true">
                    <img class="hero-robot" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="">
                </div>
            </div>

            <form class="admin-form module-wide" method="get">
                <label>Department
                    <select name="department">
                        <option value="">All assigned departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e($department) ?>" <?= $selectedDepartment === $department ? 'selected' : '' ?>><?= e($department) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Evaluation Period
                    <select name="period">
                        <option value="">All periods</option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?= e((string) $period['period_name']) ?>" <?= $selectedPeriod === (string) $period['period_name'] ? 'selected' : '' ?>><?= e((string) $period['period_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Apply Filters</button>
            </form>

            <section class="admin-box stat-grid module-wide">
                <article><span>Total Evaluations</span><strong><?= e((string) $summary['totalEvaluations']) ?></strong></article>
                <article><span>Pending Evaluations</span><strong><?= e((string) $summary['pendingEvaluations']) ?></strong></article>
                <article><span>Completed Evaluations</span><strong><?= e((string) $summary['completedEvaluations']) ?></strong></article>
                <article><span>Overdue Evaluations</span><strong><?= e((string) $summary['overdueEvaluations']) ?></strong></article>
                <article><span>Intervention Plans</span><strong><?= e((string) $summary['interventionPlans']) ?></strong></article>
                <article><span>Average Faculty Rating</span><strong><?= e($summary['averageFacultyRating'] === null ? 'N/A' : number_format((float) $summary['averageFacultyRating'], 2)) ?></strong></article>
            </section>

            <section class="module-wide" id="dean-evaluations">
                <?php dipascaf_render_evaluation_dashboard([
                    'assignments' => $evaluationCardAssignments,
                    'eyebrow' => 'VPAA Evaluation',
                    'title' => 'Evaluate Department Deans',
                    'subtitle' => 'Complete PMAS Form A evaluations for Deans assigned under your academic department scope.',
                    'defaultSection' => 'dean',
                    'hideRoleStatusFilters' => true,
                ]); ?>
            </section>

            <section class="admin-box module-wide">
                <div class="box-title"><h2>Department Performance Snapshot</h2><span>Completion, ratings, and weak-area counts</span></div>
                <div class="eval-chart-container"><canvas id="departmentChart"></canvas></div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Department</th><th>Assigned</th><th>Submitted</th><th>Completion</th><th>Average Rating</th><th>Weak Areas</th></tr></thead>
                        <tbody>
                        <?php foreach ($departmentPerformance as $row): ?>
                            <tr>
                                <td><?= e($row['department']) ?></td>
                                <td><?= e((string) $row['assigned']) ?></td>
                                <td><?= e((string) $row['submitted']) ?></td>
                                <td><span class="status-badge submitted"><?= e((string) $row['completion']) ?>%</span></td>
                                <td><?= e($row['averageRating'] === null ? 'N/A' : number_format((float) $row['averageRating'], 2)) ?></td>
                                <td><?= e((string) $row['weakAreaCount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ── Aggregate Factor Scores Radar Chart ── -->
            <section class="admin-box module-wide">
                <div class="box-title"><h2>Aggregate Performance Profile</h2><span>Radar view of factor scores across all assigned departments (1–5 scale)</span></div>

                <div class="period-selector" style="display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border,#e2e8f0);flex-wrap:wrap;">
                    <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Period:</label>
                    <select id="vpaaRadarPeriod" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <option value="">All periods</option>
                        <?php foreach ($vpaaPeriodNames as $pn): ?>
                            <?php if ($pn !== ''): ?>
                            <option value="<?= e($pn) ?>" <?= $vpaaSelectedPeriod === $pn ? 'selected' : '' ?>><?= e($pn) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Compare:</label>
                    <select id="vpaaRadarCompare" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <option value="">No comparison</option>
                        <?php foreach ($vpaaPeriodNames as $pn): ?>
                            <?php if ($pn !== ''): ?>
                            <option value="<?= e($pn) ?>" <?= $vpaaComparePeriod === $pn && $pn !== $vpaaSelectedPeriod ? 'selected' : '' ?>><?= e($pn) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="vpaaRadarApplyBtn" class="period-apply-btn" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:#3b82f6;color:#fff;transition:background 0.15s;">Apply</button>
                </div>

                <div style="position:relative;max-width:420px;height:320px;margin:8px auto 24px;">
                    <canvas id="vpaaFactorRadarChart"></canvas>
                </div>

                <script id="vpaaFactorRadarData" type="application/json"><?= json_encode([
                    'periodA' => $vpaaSelectedPeriod ?: 'All Periods',
                    'periodB' => $vpaaCompareLabel,
                    'currentScores' => $vpaaFactorScores,
                    'previousScores' => $vpaaCompareScores,
                ]) ?></script>
            </section>

            <section id="assignments" class="admin-box module-wide">
                <div class="box-title"><h2>All Evaluation Assignments</h2><span>Scoped to VPAA assigned departments</span></div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Evaluator</th><th>Faculty Evaluated</th><th>Type</th><th>Department</th><th>Status</th><th>Submission Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><?= e((string) ($assignment['evaluator_name'] ?? 'Unassigned')) ?></td>
                                <td><?= e((string) ($assignment['faculty_name'] ?? 'Faculty')) ?></td>
                                <td><?= e(admin_status_label((string) ($assignment['assignment_type'] ?? 'evaluation'))) ?></td>
                                <td><?= e((string) ($assignment['department'] ?? '')) ?></td>
                                <td><span class="status-badge <?= e((string) ($assignment['status'] ?? 'pending')) ?>"><?= e(admin_status_label((string) ($assignment['status'] ?? 'pending'))) ?></span></td>
                                <td><?= e((string) ($assignment['submitted_at'] ?? 'Not submitted')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="analytics" class="admin-box module-wide">
                <div class="box-title"><h2>Weak Areas Analytics</h2><span>Common categories, low departments, and interventions</span></div>
                <div class="analytics-grid">
                    <article>
                        <h3>Most Common Weak Categories</h3>
                        <?php foreach (array_slice($weakAreaCounts, 0, 6, true) as $area => $count): ?>
                            <p><strong><?= e((string) $area) ?></strong> - <?= e((string) $count) ?> occurrence(s)</p>
                        <?php endforeach; ?>
                    </article>
                    <article>
                        <h3>Lowest Scoring Departments</h3>
                        <?php foreach (array_slice($lowestDepartments, 0, 5) as $row): ?>
                            <p><strong><?= e($row['department']) ?></strong> - <?= e($row['averageRating'] === null ? 'N/A' : number_format((float) $row['averageRating'], 2)) ?>/5</p>
                        <?php endforeach; ?>
                    </article>
                    <article>
                        <h3>Faculty Needing Intervention</h3>
                        <?php foreach (array_slice($facultyNeedingIntervention, 0, 6) as $plan): ?>
                            <p><strong><?= e((string) $plan['faculty_name']) ?></strong> - <?= e((string) $plan['recommendation']) ?></p>
                        <?php endforeach; ?>
                    </article>
                </div>
            </section>

            <section id="reports" class="admin-box module-wide">
                <div class="box-title"><h2>VPAA Summary Report</h2><span>Overall performance, completion, weak areas, interventions, and latest period</span></div>
                <p>Latest period: <strong><?= e((string) ($latestPeriod['period_name'] ?? 'Not configured')) ?></strong></p>
                <p>Overall faculty performance: <strong><?= e($summary['averageFacultyRating'] === null ? 'No completed ratings yet' : number_format((float) $summary['averageFacultyRating'], 2) . ' / 5') ?></strong></p>
                <div class="admin-report-actions dean-report-actions">
                    <a class="button-like" href="<?= BASE_URL ?>/reports/vpaa_download.php?format=pdf">Export PDF</a>
                    <a class="button-like" href="<?= BASE_URL ?>/reports/vpaa_download.php?format=excel">Export Excel</a>
                    <a class="button-like" href="<?= BASE_URL ?>/reports/vpaa_download.php?format=csv">Export CSV</a>
                </div>
            </section>
        </section>
    </main>

    <script>
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebarOverlay = document.querySelector('.sidebar-overlay');
        const darkModeInput = document.querySelector('.dark-mode-input');
        function setSidebar(open) {
            document.body.classList.toggle('sidebar-open', open);
            if (menuToggle) menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        menuToggle?.addEventListener('click', () => setSidebar(!document.body.classList.contains('sidebar-open')));
        sidebarOverlay?.addEventListener('click', () => setSidebar(false));
        darkModeInput?.addEventListener('change', () => {
            document.body.classList.toggle('dark-mode', darkModeInput.checked);
            localStorage.setItem('pmas-dark-mode', darkModeInput.checked ? '1' : '0');
        });
        if (localStorage.getItem('pmas-dark-mode') === '1') {
            document.body.classList.add('dark-mode');
            if (darkModeInput) darkModeInput.checked = true;
        }

        const departmentRows = <?= json_encode($departmentPerformance, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const chartTarget = document.getElementById('departmentChart');
        if (chartTarget && window.Chart) {
            new Chart(chartTarget, {
                type: 'bar',
                data: {
                    labels: departmentRows.map((row) => row.department),
                    datasets: [
                        { label: 'Completion %', data: departmentRows.map((row) => row.completion), backgroundColor: '#2563eb' },
                        { label: 'Average Rating x20', data: departmentRows.map((row) => row.averageRating ? row.averageRating * 20 : 0), backgroundColor: '#16a34a' }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
            });
        }

        // ── VPAA Aggregate Factor Score Radar Chart ──
        (function() {
            var dataEl = document.getElementById('vpaaFactorRadarData');
            var canvas = document.getElementById('vpaaFactorRadarChart');
            if (!dataEl || !canvas || typeof Chart === 'undefined') return;

            var data = JSON.parse(dataEl.textContent);
            var currentScores = data.currentScores || [];
            var previousScores = data.previousScores || [];
            var hasOverlay = previousScores.length > 0 && currentScores.length > 0;
            var scores = hasOverlay ? currentScores : (currentScores.length > 0 ? currentScores : previousScores);
            if (!scores || scores.length === 0) {
                canvas.parentElement.innerHTML = '<div class="eval-monitor-empty" style="padding:60px 20px;text-align:center;"><strong>No evaluation data available for the selected period.</strong><p style="margin-top:8px;color:var(--text-muted);">Complete evaluations first to view radar chart data.</p></div>';
                return;
            }

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

            // Re-theme when dark mode toggles
            var observer = new MutationObserver(function() {
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
            observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

            // ── Period selector logic ──
            var periodSelect = document.getElementById('vpaaRadarPeriod');
            var compareSelect = document.getElementById('vpaaRadarCompare');
            var applyBtn = document.getElementById('vpaaRadarApplyBtn');
            if (periodSelect && applyBtn) {
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
                    params.set('department', document.querySelector('select[name="department"]')?.value || '');

                    var period = periodSelect.value;
                    var compare = compareSelect.value;

                    if (period) {
                        params.set('period', period);
                        if (compare && compare !== period) {
                            params.set('compare_period', compare);
                        } else {
                            params.delete('compare_period');
                        }
                    } else {
                        params.delete('period');
                        params.delete('compare_period');
                    }

                    window.location.search = '?' + params.toString();
                });

                [periodSelect, compareSelect].forEach(function(s) {
                    s.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') applyBtn.click();
                    });
                });
            }
        })();
    </script>
</body>
</html>
