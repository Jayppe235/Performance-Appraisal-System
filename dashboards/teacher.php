<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/teacher_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/notifications.php';

require_role('teacher');

$user = current_user();
$teacherId = (int) ($user['id'] ?? 0);
$section = $_GET['section'] ?? 'overview';
$allowedSections = ['overview', 'evaluate', 'results', 'feedback', 'training', 'notifications'];

if (!in_array($section, $allowedSections, true)) {
    $section = 'overview';
}

function teacher_redirect(string $message = 'Saved successfully.'): never
{
    $_SESSION['flash'] = $message;
    redirect('/dashboards/teacher.php?section=evaluate');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
        redirect('/dashboards/teacher.php?section=' . $section);
    }

    try {
        if (in_array(($_POST['action'] ?? ''), ['submit_evaluation', 'submit_self_evaluation'], true)) {
            $result = dipascaf_submit_evaluation(
                $teacherId,
                'teacher',
                ($_POST['action'] ?? '') === 'submit_self_evaluation'
                    ? 'Teacher submitted a self-evaluation.'
                    : 'Teacher submitted an evaluation.'
            );
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true] + $result);
                exit;
            }
            teacher_redirect(($_POST['action'] ?? '') === 'submit_self_evaluation' ? 'Self-evaluation submitted.' : 'Evaluation submitted.');
        }
    } catch (Throwable $exception) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
            exit;
        }
        $_SESSION['flash_error'] = $exception->getMessage();
        redirect('/dashboards/teacher.php?section=' . $section);
    }
}

$flash = $_SESSION['flash'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

$showTeacherAiPrompt = $section === 'overview' && empty($_SESSION['teacher_ai_prompt_shown']);
if ($showTeacherAiPrompt) {
    $_SESSION['teacher_ai_prompt_shown'] = true;
}
$teacherAiSuggestions = [
    'How do I start my evaluation?',
    'View my evaluation feedback.',
    'Show my submitted evaluations.',
    'Check my performance results.',
    'What are my strengths and weaknesses?',
    'View AI recommendations for improvement.',
    'Track my evaluation progress.',
    'How can I improve my ratings?',
    'View peer feedback summary.',
    'Check pending evaluation tasks.',
];

dipascaf_init_evaluation_assignments($teacherId, 'teacher');
$assignments = dipascaf_assignment_rows($teacherId, 'teacher');
$pendingAssignments = teacher_pending_assignments($teacherId);
$summary = teacher_summary($teacherId);
$ownFaculty = teacher_user_faculty($teacherId);
$resultsReleased = admin_setting('teacher_results_released', '1') === '1';
$selfEvaluationEnabled = admin_setting('self_evaluation_enabled', '1') === '1';
$selfAssignment = ($ownFaculty && $selfEvaluationEnabled) ? teacher_self_assignment($teacherId, (int) $ownFaculty['id']) : null;
$personalResults = ($ownFaculty && $resultsReleased) ? teacher_personal_results((int) $ownFaculty['id']) : ['submissionCount' => 0, 'averageScore' => null, 'history' => []];
$personalInsight = $ownFaculty ? teacher_personal_insight((int) $ownFaculty['id']) : null;
$recommendations = $ownFaculty ? teacher_recommendations((int) $ownFaculty['id']) : [];
$factorScores = ($ownFaculty && $resultsReleased) ? teacher_factor_scores((int) $ownFaculty['id']) : [];
$weightedTotal = (float) ($factorScores['_weightedTotal'] ?? 0);
unset($factorScores['_weightedTotal']);
$trendRows = ($ownFaculty && $resultsReleased) ? teacher_trend((int) $ownFaculty['id']) : [];
$selectedPeriodA = $_GET['period_a'] ?? null;
$selectedPeriodB = $_GET['period_b'] ?? null;
$categoryComparisonData = ($ownFaculty && $resultsReleased) ? teacher_category_comparison((int) $ownFaculty['id'], $selectedPeriodA, $selectedPeriodB) : [];
$sentiment = ($ownFaculty && $resultsReleased) ? teacher_sentiment_summary((int) $ownFaculty['id']) : ['label' => 'Locked', 'score' => 0, 'summary' => 'Results are waiting for HR release.'];
$generatedFeedback = teacher_generated_feedback($factorScores);
$teacherScorePercent = min(100, max(0, round($weightedTotal)));

// Period-over-period factor scores for radar chart overlay (configurable)
$teacherSelectedPeriod = $_GET['period'] ?? '';
$teacherComparePeriod = $_GET['compare_period'] ?? '';
$factorOverlayPeriods = $categoryComparisonData['periods'] ?? [];
$factorScoresLatest = [];
$factorScoresPrev = [];
$factorScoresLatestLabel = '';
$factorScoresPrevLabel = '';
$teacherAvailablePeriods = admin_periods();

if ($ownFaculty && $resultsReleased) {
    $facId = (int) $ownFaculty['id'];

    // Determine primary period: from 'period' GET param, or latest available
    if ($teacherSelectedPeriod !== '') {
        $primaryPeriod = $teacherSelectedPeriod;
    } elseif (!empty($factorOverlayPeriods)) {
        $sortedPs = $factorOverlayPeriods;
        sort($sortedPs);
        $primaryPeriod = $sortedPs[count($sortedPs) - 1];
    } else {
        $primaryPeriod = '';
    }

    // Determine comparison period
    $comparePeriod = '';
    if ($teacherComparePeriod !== '' && $teacherComparePeriod !== $primaryPeriod) {
        $comparePeriod = $teacherComparePeriod;
    } elseif ($primaryPeriod !== '' && !empty($factorOverlayPeriods) && count($factorOverlayPeriods) >= 2) {
        $sortedPs = $factorOverlayPeriods;
        sort($sortedPs);
        $idx = array_search($primaryPeriod, $sortedPs, true);
        if ($idx === false || $idx === 0) {
            $comparePeriod = $sortedPs[count($sortedPs) - 2];
        } else {
            $comparePeriod = $sortedPs[$idx - 1];
        }
    }

    $rawC = teacher_factor_scores($facId, $primaryPeriod ?: null);
    unset($rawC['_weightedTotal']);
    if (!empty($rawC)) {
        $factorScoresLatest = array_values(array_filter($rawC, 'is_array'));
        $factorScoresLatestLabel = $primaryPeriod ?: 'All Periods';
    }

    if ($comparePeriod !== '' && $comparePeriod !== $primaryPeriod) {
        $rawP = teacher_factor_scores($facId, $comparePeriod);
        unset($rawP['_weightedTotal']);
        if (!empty($rawP)) {
            $factorScoresPrev = array_values(array_filter($rawP, 'is_array'));
            $factorScoresPrevLabel = $comparePeriod;
        }
    }
}
$unreadNotifCount = notify_unread_count($teacherId);
$notificationsList = ($section === 'notifications') ? notify_fetch($teacherId, 50) : [];
$nav = [
    'overview' => ['dashboard', 'Overview'],
    'evaluate' => ['evaluations', 'Evaluate'],
    'results' => ['results', 'Results'],
    'feedback' => ['feedback', 'Feedback'],
    'training' => ['plans', 'Training'],
    'notifications' => ['notifications', 'Notifications'],
];
$pageTitle = $nav[$section][1];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/teacher-dashboard-polish.css?v=campus-bg-1">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/evaluation-form.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body class="admin-body role-dashboard-body role-sidebar-body faculty-body">
    <button class="sidebar-overlay" type="button" aria-label="Close menu"></button>
    <aside class="admin-sidebar" aria-label="Faculty navigation">
        <div class="sidebar-brand">
            <span class="brand-icon">F</span>
            <span class="sidebar-brand-copy">
                <strong><?= e(APP_NAME) ?></strong>
                <small>Faculty Portal</small>
            </span>
            <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar"></button>
        </div>
        <nav class="sidebar-menu">
            <?php foreach ($nav as $key => [$icon, $label]): ?>
                <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboards/teacher.php?section=<?= e($key) ?>">
                    <span class="menu-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
                    <span class="sidebar-item-label"><?= e($label) ?></span>
                    <?php if ($key === 'notifications' && $unreadNotifCount > 0): ?>
                        <span class="notif-nav-badge"><?= e((string) $unreadNotifCount) ?></span>
                    <?php endif; ?>
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
                <p class="admin-header-note">Teacher Peer Evaluation and Personal Development Center</p>
            </div>
            <div class="admin-search dean-header-context">
                <span><?= e($ownFaculty['department'] ?? 'Faculty Portal') ?></span>
            </div>
            <div class="admin-actions" aria-label="Faculty metrics and profile">
                <button class="notification-button" type="button" aria-label="Pending evaluation tasks">
                    <span class="notification-badge"><?= e((string) $summary['pending']) ?></span>
                </button>
                <button class="notification-button warning" type="button" aria-label="Submitted evaluations">
                    <span class="notification-badge"><?= e((string) $summary['submitted']) ?></span>
                </button>
                <button class="profile-button" type="button" aria-label="Faculty profile"><span class="admin-avatar"><?= e(strtoupper(substr((string) ($user['full_name'] ?? 'F'), 0, 1))) ?></span></button>
            </div>
        </header>

    <section class="dashboard role-dashboard-content">
        <?php if ($flash !== ''): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($flashError !== ''): ?><div class="notice error"><?= e($flashError) ?></div><?php endif; ?>

        <?php if ($section === 'overview'): ?>
        <?php if ($showTeacherAiPrompt): ?>
        <div id="admin-ai-top-prompt" class="admin-ai-top-prompt module-wide" role="status" aria-live="polite">
            <div class="admin-ai-top-icon" aria-hidden="true">
                <img src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="">
            </div>
            <div class="admin-ai-top-copy">
                <p>Welcome Faculty! I'm here to guide you with your evaluation tasks.</p>
                <div class="admin-ai-suggestions">
                    <?php foreach ($teacherAiSuggestions as $suggestion): ?>
                        <button type="button" data-chat-sample="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <button id="admin-ai-top-dismiss" type="button" aria-label="Dismiss AI message">x</button>
        </div>
        <?php endif; ?>
        <section class="welcome teacher-welcome">
            <img class="teacher-hero-background" src="<?= BASE_URL ?>/assets/images/pmas-campus-bg.jpg" alt="" aria-hidden="true">
            <div class="welcome-copy">
                <p class="eyebrow">Teacher Dashboard</p>
                <h1>Welcome, <?= e(display_name($user['full_name'])) ?></h1>
                <p class="muted">
                    Review your assigned confidential evaluation tasks, submit ratings, and explore AI feedback for your career growth.
                </p>
            </div>
            <div class="teacher-welcome-visual" aria-hidden="true">
                <span class="teacher-building-glow"></span>
                <img class="welcome-robot" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="">
            </div>
        </section>

        <section class="stat-grid teacher-stat-grid">
            <article><span>Assigned Tasks</span><strong><?= e((string) $summary['assigned']) ?></strong></article>
            <article><span>Pending Evaluations</span><strong><?= e((string) $summary['pending']) ?></strong></article>
            <article><span>Submitted Evaluations</span><strong><?= e((string) $summary['submitted']) ?></strong></article>
            <article><span>Personal Results</span><strong><?= $resultsReleased ? e((string) ($personalResults['submissionCount'] ?? 0)) : 'Locked' ?></strong></article>
        </section>
        <?php endif; ?>

        <?php if ($section === 'evaluate'): ?>
        <div>
            <?php dipascaf_render_evaluation_dashboard([
                'assignments' => $assignments,
                'eyebrow' => 'Faculty Evaluation',
                'title' => 'Evaluate Dean, Program Heads, and Peer Faculty',
                'subtitle' => 'Peer-to-peer evaluations are confidential. Use the focused menus to switch between Dean, Program Head, Faculty, and Peer appraisal cards.',
                'defaultSection' => 'all',
            ]); ?>
        </div>
        <?php endif; ?>

        <?php if ($ownFaculty): ?>
            <?php if ($section === 'evaluate' && $selfEvaluationEnabled && $selfAssignment && $selfAssignment['status'] === 'pending'): ?>
                <?php
                    $formBCategories = dipascaf_form_b_categories();
                ?>
                <section class="admin-box module-form form-b-self-eval">
                    <div class="box-title">
                        <h2>Complete Self-Evaluation — PMAS Form B</h2>
                        <span>Enabled by HR</span>
                    </div>
                    <div class="eval-kbd-indicator" id="fbKbdIndicator">
                        <span class="eval-kbd-icon">⌨</span>
                        <span class="eval-kbd-text">Use <kbd>↑</kbd> <kbd>↓</kbd> to navigate questions, <kbd>←</kbd> <kbd>→</kbd> to change rating</span>
                        <button type="button" class="eval-kbd-dismiss" id="fbKbdDismiss">✕</button>
                    </div>
                    <div class="form-b-scale" aria-label="PMAS Form B rating scale">
                        <span>5 — Highly Evident</span>
                        <span>4 — Evident</span>
                        <span>3 — Moderately Evident</span>
                        <span>2 — Slightly Evident</span>
                        <span>1 — Not Evident</span>
                    </div>
                    <form method="post" class="admin-form" id="formBSelfEvalForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="submit_self_evaluation">
                        <input type="hidden" name="assignment_id" value="<?= e((string) $selfAssignment['id']) ?>">
                        <input type="hidden" name="form_b_payload" id="formBSelfPayload">

                        <div class="form-b-categories-wrap">
                            <?php foreach ($formBCategories as $catIndex => $category): ?>
                                <details class="form-b-category-section" data-category-id="<?= e((string) $category['id']) ?>" data-weight="<?= e((string) $category['factor_weight']) ?>">
                                    <summary class="form-b-category-head">
                                        <strong><?= e($category['title']) ?></strong>
                                        <span class="form-b-weight-badge"><?= e(number_format((float) $category['factor_weight'], 0)) ?>% weight</span>
                                        <span class="form-b-cat-status" id="fbStatus_<?= e((string) $category['id']) ?>">0/<?= e((string) count($category['questions'])) ?> answered</span>
                                    </summary>
                                    <div class="form-b-category-body">
                                        <?php foreach ($category['questions'] as $qIndex => $question): ?>
                                            <div class="form-b-question-row">
                                                <p><?= e($question['text']) ?></p>
                                                <div class="form-b-rating-group" data-qid="<?= e((string) $question['id']) ?>">
                                                    <?php for ($r = 1; $r <= 5; $r++): ?>
                                                        <label class="form-b-radio-label">
                                                            <input type="radio" name="q_<?= e((string) $category['id']) ?>_<?= e((string) $question['id']) ?>" value="<?= e((string) $r) ?>" class="form-b-rating-radio" required>
                                                            <span><?= e((string) $r) ?></span>
                                                        </label>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="form-b-conditional-fields" id="fbCond_<?= e((string) $category['id']) ?>">
                                            <div class="form-b-cond-field fb-reason" hidden>
                                                <label>Reason for Rating<textarea class="fb-reason-input" data-cid="<?= e((string) $category['id']) ?>" placeholder="Explain why this rating was given."></textarea></label>
                                            </div>
                                            <div class="form-b-cond-field fb-behavioral" hidden>
                                                <label>Behavioral Evidence<textarea class="fb-evidence-input" data-cid="<?= e((string) $category['id']) ?>" placeholder="Describe specific observable behaviors that support this rating."></textarea></label>
                                            </div>
                                            <div class="form-b-cond-field fb-recommendation" hidden>
                                                <label>Recommendation<textarea class="fb-recommendation-input" data-cid="<?= e((string) $category['id']) ?>" placeholder="Suggest specific actions for improvement."></textarea></label>
                                            </div>
                                        </div>

                                        <div class="form-b-category-average">
                                            <span>Category Average:</span>
                                            <strong id="fbAvg_<?= e((string) $category['id']) ?>">—</strong>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-b-self-summary">
                            <div class="form-b-total-card">
                                <span>Total Weighted Score</span>
                                <strong id="fbSelfFinalScore">0.00 / 5.00</strong>
                                <small id="fbSelfFinalStatus">Complete all <?= e((string) count($formBCategories)) ?> categories to submit.</small>
                            </div>
                            <button type="submit" id="fbSelfSubmitBtn" disabled>Submit Self-Evaluation</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($section === 'results'): ?>
            <section class="admin-box module-table">
                <div class="box-title">
                    <h2>Your Personal Evaluation Results</h2>
                    <span>View performance summaries once HR releases them</span>
                </div>
                <?php if (!$resultsReleased): ?>
                    <div class="notice info">Your evaluation results are confidentially stored and will appear after HR releases them.</div>
                <?php elseif ($personalResults['submissionCount'] === 0): ?>
                    <div class="notice info">No evaluation results are available yet. Your results will appear after submissions are completed and released by HR.</div>
                <?php else: ?>
                    <div class="stat-grid compact">
                        <article><span>Evaluations Received</span><strong><?= e((string) $personalResults['submissionCount']) ?></strong></article>
                        <article><span>Average Rating</span><strong><?= e((string) ($personalResults['averageScore'] ?? 'N/A')) ?></strong></article>
                        <article><span>Weighted Score</span><strong><?= e(number_format($weightedTotal, 2)) ?>%</strong></article>
                        <article><span>Comment Sentiment</span><strong><?= e($sentiment['label']) ?></strong></article>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>Cycle</th><th>Type</th><th>Status</th><th>Average Score</th><th>Submitted At</th></tr></thead>
                        <tbody>
                            <?php foreach ($personalResults['history'] as $history): ?>
                                <tr>
                                    <td data-label="Cycle"><?= e($history['cycle_name']) ?></td>
                                    <td data-label="Type"><?= e(admin_status_label($history['assignment_type'])) ?></td>
                                    <td data-label="Status"><?= e(admin_status_label($history['status'])) ?></td>
                                    <td data-label="Average Score"><?= e(number_format((float) $history['average_score'], 2)) ?></td>
                                    <td data-label="Submitted At"><?= e((string) $history['submitted_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <?php if ($section === 'results' && $resultsReleased && !empty($categoryComparisonData['comparison'])): 
                $cc = $categoryComparisonData['comparison'];
            ?>
            <section class="admin-box module-table">
                <div class="box-title">
                    <h2>Category Comparison: <?= e($cc['periodA'] ?? 'Earlier') ?> vs <?= e($cc['periodB'] ?? 'Latest') ?></h2>
                    <span>Period-over-period category score changes</span>
                </div>
                <div class="stat-grid compact">
                    <article style="border-left:3px solid #166a45;">
                        <span>Improved</span>
                        <strong style="color:#166a45;"><?= e((string) ($cc['summary']['improved'] ?? 0)) ?></strong>
                        <small>categories grew</small>
                    </article>
                    <article style="border-left:3px solid #b45309;">
                        <span>Declined</span>
                        <strong style="color:#b45309;"><?= e((string) ($cc['summary']['declined'] ?? 0)) ?></strong>
                        <small>categories dropped</small>
                    </article>
                    <article style="border-left:3px solid #64748b;">
                        <span>Stable</span>
                        <strong style="color:#64748b;"><?= e((string) ($cc['summary']['stable'] ?? 0)) ?></strong>
                        <small>no significant change</small>
                    </article>
                    <article style="border-left:3px solid #3b82f6;">
                        <span>New</span>
                        <strong style="color:#3b82f6;"><?= e((string) ($cc['summary']['new'] ?? 0)) ?></strong>
                        <small>newly evaluated</small>
                    </article>
                </div>

                <div class="period-selector" style="display:flex;gap:16px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border,#e2e8f0);flex-wrap:wrap;">
                    <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Compare:</label>
                    <select id="periodASelect" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <?php foreach (($categoryComparisonData['periods'] ?? []) as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === $cc['periodA'] ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="font-size:14px;color:var(--text-muted,#94a3b8);">vs</span>
                    <select id="periodBSelect" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                        <?php foreach (($categoryComparisonData['periods'] ?? []) as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === $cc['periodB'] ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="compareBtn" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:#3b82f6;color:#fff;transition:background 0.15s;">Compare</button>
                </div>

                <script>
                (function() {
                    var btn = document.getElementById('compareBtn');
                    var selA = document.getElementById('periodASelect');
                    var selB = document.getElementById('periodBSelect');
                    if (!btn || !selA || !selB) return;

                    function getCurrentParams() {
                        var params = new URLSearchParams(window.location.search);
                        params.set('period_a', selA.value);
                        params.set('period_b', selB.value);
                        params.set('section', 'results');
                        return params.toString();
                    }

                    btn.addEventListener('click', function() {
                        window.location.search = '?' + getCurrentParams();
                    });

                    // Allow pressing Enter on selects to trigger compare
                    // Prevent selecting same period for both dropdowns
                    function syncSelectOptions() {
                        Array.from(selB.options).forEach(function(opt) {
                            opt.disabled = opt.value === selA.value;
                        });
                        Array.from(selA.options).forEach(function(opt) {
                            opt.disabled = opt.value === selB.value;
                        });
                    }
                    selA.addEventListener('change', syncSelectOptions);
                    selB.addEventListener('change', syncSelectOptions);
                    syncSelectOptions();

                    [selA, selB].forEach(function(sel) {
                        sel.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter') btn.click();
                        });
                    });
                })();
                </script>

                <div class="chart-container" style="position:relative;height:280px;margin:16px 0 24px;padding:0 8px;">
                    <canvas id="categoryComparisonChart"></canvas>
                </div>

                <script id="categoryComparisonData" type="application/json"><?= json_encode(['categories' => $cc['categories'], 'periodA' => $cc['periodA'] ?? 'Earlier', 'periodB' => $cc['periodB'] ?? 'Latest']) ?></script>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Form</th>
                            <th><?= e($cc['periodA'] ?? 'Earlier') ?></th>
                            <th><?= e($cc['periodB'] ?? 'Latest') ?></th>
                            <th>Change</th>
                            <th>Direction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cc['categories'] as $cat): 
                            $direction = (string) ($cat['direction'] ?? '');
                            $change = $cat['change'];
                            $color = match ($direction) {
                                'improved' => '#166a45',
                                'declined' => '#b45309',
                                'stable' => '#64748b',
                                'new' => '#3b82f6',
                                default => '#94a3b8',
                            };
                            $arrow = match ($direction) {
                                'improved' => '▲',
                                'declined' => '▼',
                                'stable' => '→',
                                'new' => '✦',
                                default => '-',
                            };
                        ?>
                            <tr>
                                <td data-label="Category"><strong><?= e($cat['category'] ?? '') ?></strong></td>
                                <td data-label="Form"><?= e($cat['formType'] ?? '') ?></td>
                                <td data-label="Earlier"><?= $cat['periodAScore'] !== null ? e(number_format((float) $cat['periodAScore'], 2)) : '<span class="muted">—</span>' ?></td>
                                <td data-label="Latest"><?= $cat['periodBScore'] !== null ? e(number_format((float) $cat['periodBScore'], 2)) : '<span class="muted">—</span>' ?></td>
                                <td data-label="Change" style="color:<?= e($color) ?>;font-weight:700;">
                                    <?= $change !== null ? ($change > 0 ? '+' : '') . e(number_format((float) $change, 2)) : '<span class="muted">—</span>' ?>
                                </td>
                                <td data-label="Direction" style="color:<?= e($color) ?>;">
                                    <?= $arrow ?> <?= e(ucfirst($direction)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'feedback'): ?>
            <section class="admin-box module-table">
                <div class="box-title">
                    <h2>AI Feedback and Recommendations</h2>
                    <span>Personalized insights based on your performance profile</span>
                </div>
                <?php if (!$resultsReleased): ?>
                    <div class="notice info">AI feedback is ready for secure release after HR publishes personal results.</div>
                <?php elseif ($personalInsight === null && $factorScores === []): ?>
                    <div class="notice info">AI feedback is not yet available for your profile.</div>
                <?php else: ?>
                    <div class="stat-grid compact">
                        <article><span>Strength</span><strong><?= e($personalInsight['strength_area'] ?? $generatedFeedback['strength']) ?></strong></article>
                        <article><span>Weak Area</span><strong><?= e($personalInsight['weak_area'] ?? $generatedFeedback['weakness']) ?></strong></article>
                        <article><span>Confidence</span><strong><?= e((string) ($personalInsight['confidence_score'] ?? 80)) ?>%</strong></article>
                        <article><span>Sentiment</span><strong><?= e($sentiment['label']) ?></strong></article>
                    </div>
                    <div class="admin-box-summary">
                        <p><?= e($personalInsight['analysis_summary'] ?? $generatedFeedback['summary']) ?></p>
                        <p><?= e($sentiment['summary']) ?></p>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($section === 'feedback' && $resultsReleased && $factorScores !== []): ?>
                <section class="admin-box module-table">
                    <div class="box-title">
                        <h2>Factor Scores and Improvement Suggestions</h2>
                        <span>1 to 5 scale with weighted score</span>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>Factor</th><th>Average</th><th>Weight</th><th>Weighted Score</th><th>AI Suggestion</th></tr></thead>
                        <tbody>
                            <?php foreach ($factorScores as $row): ?>
                                <tr>
                                    <td data-label="Factor"><?= e($row['factor']) ?></td>
                                    <td data-label="Average"><?= e(number_format((float) $row['score'], 2)) ?></td>
                                    <td data-label="Weight"><?= e(number_format((float) $row['weight'], 2)) ?>%</td>
                                    <td data-label="Weighted Score"><?= e(number_format((float) $row['weighted_score'], 2)) ?>%</td>
                                    <td data-label="AI Suggestion"><?= e($row['suggestion']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title">
                        <h2>Performance Profile</h2>
                        <span>Radar view of your factor scores (1–5 scale)</span>
                    </div>

                    <!-- Period Selector -->
                    <div class="period-selector" style="display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border,#e2e8f0);flex-wrap:wrap;">
                        <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Period:</label>
                        <select id="teacherRadarPeriod" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                            <option value="">All periods</option>
                            <?php foreach ($teacherAvailablePeriods as $ap): ?>
                                <?php $pn = (string) ($ap['period_name'] ?? ''); ?>
                                <?php if ($pn !== ''): ?>
                                <option value="<?= e($pn) ?>" <?= $teacherSelectedPeriod === $pn ? 'selected' : '' ?>><?= e($pn) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <label style="font-size:13px;font-weight:600;color:var(--text,#1e293b);">Compare:</label>
                        <select id="teacherRadarCompare" class="period-select" style="padding:6px 12px;border:1px solid var(--border,#e2e8f0);border-radius:6px;font-size:13px;background:var(--bg-card,#fff);color:var(--text,#1e293b);max-width:220px;">
                            <option value="">No comparison</option>
                            <?php foreach ($teacherAvailablePeriods as $ap): ?>
                                <?php $pn = (string) ($ap['period_name'] ?? ''); ?>
                                <?php if ($pn !== ''): ?>
                                <option value="<?= e($pn) ?>" <?= $teacherComparePeriod === $pn && (string) $pn !== $teacherSelectedPeriod ? 'selected' : '' ?>><?= e($pn) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="teacherRadarApplyBtn" class="period-apply-btn" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:#3b82f6;color:#fff;transition:background 0.15s;">Apply</button>
                    </div>

                    <div style="position:relative;max-width:420px;height:320px;margin:8px auto 24px;">
                        <canvas id="factorRadarChart"></canvas>
                    </div>
                    <script id="factorRadarData" type="application/json"><?= json_encode([
                        'periodA' => $factorScoresLatestLabel,
                        'periodB' => $factorScoresPrevLabel,
                        'currentScores' => $factorScoresLatest,
                        'previousScores' => $factorScoresPrev,
                    ]) ?></script>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title">
                        <h2>Progress Across Appraisal Periods</h2>
                        <span>Trend analysis</span>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>Appraisal Period</th><th>Average Rating</th><th>Submissions</th></tr></thead>
                        <tbody>
                            <?php foreach ($trendRows as $row): ?>
                                <tr>
                                    <td data-label="Appraisal Period"><?= e($row['cycle_name']) ?></td>
                                    <td data-label="Average Rating"><?= e(number_format((float) $row['average_score'], 2)) ?></td>
                                    <td data-label="Submissions"><?= e((string) $row['submission_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <?php if ($section === 'training'): ?>
            <section class="admin-box module-table">
                <div class="box-title">
                    <h2>Recommended Development Activities</h2>
                    <span>Seminars, training, and coaching suggested for your improvement</span>
                </div>
                <?php if ($recommendations === []): ?>
                    <div class="notice info">No recommended interventions are available yet.</div>
                <?php else: ?>
                    <table class="data-table">
                        <thead><tr><th>Weak Area</th><th>Recommendation</th><th>Type</th><th>Status</th><th>Target Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($recommendations as $plan): ?>
                                <tr>
                                    <td data-label="Weak Area"><?= e($plan['weak_area']) ?></td>
                                    <td data-label="Recommendation"><?= e($plan['recommendation']) ?></td>
                                    <td data-label="Type"><?= e(admin_status_label($plan['action_type'])) ?></td>
                                    <td data-label="Status"><?= e(admin_status_label($plan['status'])) ?></td>
                                    <td data-label="Target Date"><?= e((string) $plan['target_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($section === 'notifications'): ?>
            <section class="admin-box module-form">
                <div class="box-title">
                    <h2>Notification Center</h2>
                    <span>System updates, evaluation alerts, and account activity</span>
                </div>

                <div class="notif-toolbar" style="display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border,#e2e8f0);flex-wrap:wrap;">
                    <div class="notif-filter-group" style="display:flex;gap:4px;background:var(--bg-muted,#f1f5f9);border-radius:8px;padding:3px;">
                        <button type="button" class="notif-filter-btn active" data-filter="all" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:var(--bg-card,#fff);color:var(--text,#1e293b);box-shadow:0 1px 2px rgba(0,0,0,0.06);transition:all 0.15s;">All</button>
                        <button type="button" class="notif-filter-btn" data-filter="unread" style="padding:6px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;background:transparent;color:var(--text-muted,#64748b);transition:all 0.15s;">Unread</button>
                    </div>
                    <button type="button" id="notif-mark-all-read" class="btn-secondary" style="margin-left:auto;padding:6px 14px;border:1px solid var(--border,#e2e8f0);border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;background:var(--bg-card,#fff);color:var(--text,#1e293b);">Mark All Read</button>
                </div>

                <div id="notif-list" class="notif-list" style="max-height:520px;overflow-y:auto;">
                    <?php if (empty($notificationsList)): ?>
                        <div class="notice info" style="margin:16px;">No notifications yet. System updates, evaluation alerts, and account activity will appear here.</div>
                    <?php else: ?>
                        <?php foreach ($notificationsList as $n): 
                            $n = notify_format($n);
                            $typeIcon = match ($n['type']) {
                                'system_update' => '📢',
                                'account_activity' => '👤',
                                default => '🔔',
                            };
                        ?>
                            <div class="notif-item" data-id="<?= e((string) $n['id']) ?>" data-read="<?= $n['is_read'] ? '1' : '0' ?>" style="display:flex;gap:14px;padding:14px 16px;border-bottom:1px solid var(--border,#e2e8f0);transition:background 0.15s;<?= !$n['is_read'] ? 'background:var(--bg-accent,#eff6ff);' : '' ?>">
                                <span style="font-size:20px;flex-shrink:0;margin-top:2px;"><?= $typeIcon ?></span>
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;">
                                        <strong style="font-size:14px;<?= !$n['is_read'] ? 'color:var(--text,#1e293b);' : 'color:var(--text-muted,#64748b);' ?>"><?= e($n['title']) ?></strong>
                                        <span style="font-size:12px;color:var(--text-muted,#94a3b8);white-space:nowrap;"><?= e($n['relative_time']) ?></span>
                                        <?php if (!$n['is_read']): ?>
                                            <span class="notif-new-badge" style="font-size:10px;background:#3b82f6;color:#fff;padding:2px 7px;border-radius:10px;font-weight:700;line-height:1.4;">NEW</span>
                                        <?php endif; ?>
                                    </div>
                                    <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted,#64748b);line-height:1.5;word-wrap:break-word;"><?= e($n['description']) ?></p>
                                    <?php if ($n['link'] !== ''): ?>
                                        <a href="<?= e($n['link']) ?>" style="display:inline-block;margin-top:6px;font-size:12px;color:#3b82f6;font-weight:600;text-decoration:none;">View details →</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$n['is_read']): ?>
                                    <button type="button" class="notif-mark-read" data-id="<?= e((string) $n['id']) ?>" title="Mark as read" style="flex-shrink:0;width:28px;height:28px;border:none;border-radius:50%;background:transparent;cursor:pointer;font-size:16px;color:var(--text-muted,#94a3b8);display:flex;align-items:center;justify-content:center;transition:all 0.15s;">✓</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

        <?php else: ?>
            <?php if (!in_array($section, ['overview', 'evaluate'], true)): ?>
            <section class="admin-box module-form">
                <div class="box-title">
                    <h2>Your Personal Results</h2>
                    <span>The system can show your personal evaluation when your faculty record is linked to your teacher account.</span>
                </div>
                <div class="notice info">Please ask Admin/HR to link your teacher account email to your faculty profile so your personal feedback, AI reports, and intervention suggestions can be displayed.</div>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    </main>

    <button id="floating-chat-toggle" class="floating-chat-toggle" type="button" aria-label="Open <?= e(APP_NAME) ?> assistant" aria-expanded="false">
        <img class="floating-chat-logo" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="" aria-hidden="true">
    </button>

    <section id="floating-chat-panel" class="floating-chat-panel" aria-label="<?= e(APP_NAME) ?> assistant" hidden>
        <div class="floating-chat-header">
            <div>
                <strong>Teacher AI Assistant</strong>
                <span>Feedback and development help</span>
            </div>
            <button id="floating-chat-close" type="button" aria-label="Close assistant">x</button>
        </div>
        <div id="chat-log" class="chat-log floating-chat-log">
            <div class="chat-message assistant"><div class="chat-bubble"><strong>Assistant</strong>Ask about your strengths, weak areas, low-rated factors, seminars, or improvement suggestions.</div></div>
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

        // ── Mirrored pure functions (tested via src/utils/evalKeyboardNav.js) ──
        function fbComputeCategoryState(inputs) {
            var cid = inputs.cid, weight = inputs.weight || 0, answers = inputs.answers || {};
            var totalQuestions = inputs.totalQuestions || 0;
            var evidence = (inputs.evidence || "").trim();
            var reason = (inputs.reason || "").trim();
            var recommendation = (inputs.recommendation || "").trim();
            var answered = Object.keys(answers).length;
            var total = Object.values(answers).reduce(function(s, v) { return s + Number(v); }, 0);
            var avg = answered === totalQuestions && totalQuestions > 0 ? total / totalQuestions : 0;
            var weighted = avg * (Number(weight) / 100);
            var requiredType = "none";
            if (avg >= 4.51) requiredType = "high";
            else if (avg <= 3) requiredType = "low";
            else if (avg > 0) requiredType = "reason";
            var complete = false;
            if (answered === totalQuestions && totalQuestions > 0) {
                if (avg >= 4.51) complete = evidence.length > 0;
                else if (avg <= 3) complete = evidence.length > 0 && recommendation.length > 0;
                else complete = reason.length > 0;
            }
            return { cid: String(cid), answers: answers, avg: avg, weighted: weighted, evidence: evidence, reason: reason, recommendation: recommendation, requiredType: requiredType, answered: answered, totalQuestions: Number(totalQuestions), complete: complete, weight: Number(weight) };
        }

        function fbComputeProgressSummary(states) {
            if (!states || states.length === 0) {
                return { totalWeighted: 0, allComplete: false, anyAnswered: false, totalQuestionsAll: 0, totalAnsweredAll: 0, remaining: 0, pctComplete: 0, pending: 0 };
            }
            var totalWeighted = 0, allComplete = true, anyAnswered = false;
            var totalQuestionsAll = 0, totalAnsweredAll = 0, pending = 0;
            states.forEach(function(s) {
                totalWeighted += s.weighted || 0;
                if (!s.complete && (s.totalQuestions || 0) > 0) allComplete = false;
                if ((s.answered || 0) > 0) anyAnswered = true;
                totalQuestionsAll += s.totalQuestions || 0;
                totalAnsweredAll += s.answered || 0;
                if (!s.complete && (s.totalQuestions || 0) > 0) pending++;
            });
            var remaining = totalQuestionsAll - totalAnsweredAll;
            var pct = totalQuestionsAll > 0 ? Math.round((totalAnsweredAll / totalQuestionsAll) * 100) : 0;
            return { totalWeighted: totalWeighted, allComplete: totalQuestionsAll > 0 ? allComplete : false, anyAnswered: anyAnswered, totalQuestionsAll: totalQuestionsAll, totalAnsweredAll: totalAnsweredAll, remaining: Math.max(0, remaining), pctComplete: pct, pending: pending };
        }

        // PMAS Form B Self-Evaluation Logic
        (function () {
            const form = document.getElementById('formBSelfEvalForm');
            if (!form) return;

            const payloadInput = document.getElementById('formBSelfPayload');
            const finalScore = document.getElementById('fbSelfFinalScore');
            const finalStatus = document.getElementById('fbSelfFinalStatus');
            const submitBtn = document.getElementById('fbSelfSubmitBtn');
            const sections = form.querySelectorAll('.form-b-category-section');

            // Extract DOM data and delegate to pure fbComputeCategoryState
            function getCategoryState(section) {
                const cid = section.dataset.categoryId;
                const weight = parseFloat(section.dataset.weight) || 0;
                const radios = section.querySelectorAll('.form-b-rating-radio:checked');
                const answers = {};
                radios.forEach(function (r) {
                    const qid = r.closest('.form-b-rating-group').dataset.qid;
                    answers[qid] = parseInt(r.value, 10);
                });
                const totalQuestions = section.querySelectorAll('.form-b-rating-group').length;
                const evidence = section.querySelector('.fb-evidence-input')?.value?.trim() || '';
                const reason = section.querySelector('.fb-reason-input')?.value?.trim() || '';
                const recommendation = section.querySelector('.fb-recommendation-input')?.value?.trim() || '';
                return fbComputeCategoryState({ cid: cid, weight: weight, answers: answers, totalQuestions: totalQuestions, evidence: evidence, reason: reason, recommendation: recommendation });
            }

            function updateConditionalFields(section) {
                const state = getCategoryState(section);
                const cond = section.querySelector('.form-b-conditional-fields');
                if (!cond) return;

                const reasonField = cond.querySelector('.fb-reason');
                const evidenceField = cond.querySelector('.fb-behavioral');
                const recommendationField = cond.querySelector('.fb-recommendation');

                reasonField.hidden = state.avg === 0 || state.requiredType !== 'reason';
                evidenceField.hidden = state.avg === 0 || (state.requiredType !== 'high' && state.requiredType !== 'low');
                recommendationField.hidden = state.avg === 0 || state.requiredType !== 'low';
            }

            function updateStatus(section) {
                const state = getCategoryState(section);
                const statusEl = section.querySelector('.form-b-cat-status');
                const avgEl = section.querySelector('.form-b-category-average strong');
                if (statusEl) {
                    statusEl.textContent = state.answered + '/' + state.totalQuestions + ' answered';
                    statusEl.classList.remove('completed', 'partial', 'neutral');
                    statusEl.classList.add(state.complete ? 'completed' : 'neutral');
                }
                if (avgEl) {
                    avgEl.textContent = state.avg > 0 ? state.avg.toFixed(2) + ' / 5.00' : '\u2014';
                }
            }

            function updateSummary() {
                const states = Array.from(sections).map(getCategoryState);
                const summary = fbComputeProgressSummary(states);

                finalScore.textContent = summary.totalWeighted.toFixed(2) + ' / 5.00';
                if (summary.allComplete && summary.anyAnswered) {
                    finalStatus.textContent = 'All categories complete. Ready to submit.';
                    finalStatus.className = 'eval-final-status ready';
                    submitBtn.disabled = false;
                    if (!fbWasAllComplete) {
                        fbWasAllComplete = true;
                        if (finalScore) {
                            finalScore.classList.remove('bounce');
                            void finalScore.offsetWidth;
                            finalScore.classList.add('bounce');
                        }
                        fbTriggerConfetti();
                    }
                } else {
                    fbWasAllComplete = false;
                    finalStatus.textContent = summary.pending + ' incomplete categor' + (summary.pending === 1 ? 'y' : 'ies') + ' remaining. Please answer all questions and fill required fields.';
                    finalStatus.className = 'eval-final-status waiting';
                    submitBtn.disabled = true;
                }
            }

            function savePayload() {
                const categories = [];
                sections.forEach(function (section) {
                    const state = getCategoryState(section);
                    if (state.answered === 0) return;
                    categories.push({
                        category_id: Number(state.cid),
                        answers: Object.fromEntries(
                            Object.entries(state.answers).map(function (e) { return [e[0], Number(e[1])]; })
                        ),
                        total_rate: Number(Object.values(state.answers).reduce(function (a, b) { return a + b; }, 0).toFixed(2)),
                        question_count: state.totalQuestions,
                        average_rating: Number(state.avg.toFixed(2)),
                        factor_weight: Number(state.weight),
                        weighted_score: Number(state.weighted.toFixed(4)),
                        behavioral_evidence: state.evidence,
                        reason_for_rating: state.reason,
                        recommendation: state.recommendation,
                        ai_suggestion: '',
                        ai_decision: 'none'
                    });
                });
                payloadInput.value = JSON.stringify({ categories: categories });
            }

            function refreshAll() {
                sections.forEach(function (section) {
                    updateConditionalFields(section);
                    updateStatus(section);
                });
                updateSummary();
                savePayload();
            }

            // Listen for rating changes
            form.querySelectorAll('.form-b-rating-radio').forEach(function (radio) {
                radio.addEventListener('change', refreshAll);
            });

            // Listen for textarea changes
            form.querySelectorAll('.fb-evidence-input, .fb-reason-input, .fb-recommendation-input').forEach(function (ta) {
                ta.addEventListener('input', refreshAll);
            });

            // Keyboard indicator dismiss
            const fbKbdDismiss = document.getElementById('fbKbdDismiss');
            if (fbKbdDismiss) {
                fbKbdDismiss.addEventListener('click', function() {
                    const ind = document.getElementById('fbKbdIndicator');
                    if (ind) ind.remove();
                });
                // Auto-dismiss after 5 seconds
                setTimeout(function() {
                    const ind = document.getElementById('fbKbdIndicator');
                    if (ind) ind.remove();
                }, 5000);
            }

            // ── Animation state trackers ──
            var fbWasAllComplete = false;

            function fbTriggerConfetti() {
                var container = form.querySelector('.form-b-self-summary');
                if (!container) return;
                var existing = container.querySelector('.eval-confetti-container');
                if (existing) existing.remove();
                var frag = document.createElement('div');
                frag.className = 'eval-confetti-container';
                frag.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:0;overflow:visible;pointer-events:none;z-index:100;';
                for (var c = 0; c < 15; c++) {
                    var p = document.createElement('div');
                    p.className = 'eval-confetti';
                    frag.appendChild(p);
                }
                container.appendChild(frag);
                setTimeout(function() {
                    if (frag.parentNode) frag.remove();
                }, 2500);
            }

            // ── Keyboard Navigation for Form B ──
            var fbQuestions = form.querySelectorAll('.form-b-question-row');
            var fbFocusedIdx = -1;

            function fbKbdClearFocus() {
                fbQuestions.forEach(function(q) { q.classList.remove('focused'); });
                fbFocusedIdx = -1;
            }

            function fbKbdFocusQuestion(idx) {
                fbKbdClearFocus();
                if (idx < 0) idx = 0;
                if (idx >= fbQuestions.length) idx = fbQuestions.length - 1;
                fbFocusedIdx = idx;
                var q = fbQuestions[idx];
                // Open parent details section so the question is visible
                var parentSection = q.closest('.form-b-category-section');
                if (parentSection) parentSection.open = true;
                q.classList.add('focused');
                q.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            function fbKbdRateFocused(delta) {
                if (fbFocusedIdx < 0 || fbFocusedIdx >= fbQuestions.length) return;
                var q = fbQuestions[fbFocusedIdx];
                var group = q.querySelector('.form-b-rating-group');
                if (!group) return;
                var radios = group.querySelectorAll('.form-b-rating-radio');
                var checked = group.querySelector('.form-b-rating-radio:checked');
                var currentVal = checked ? parseInt(checked.value, 10) : 0;
                var newVal = Math.max(1, Math.min(5, currentVal + delta));
                radios.forEach(function(r) {
                    if (parseInt(r.value, 10) === newVal) {
                        r.checked = true;
                        r.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }

            form.addEventListener('keydown', function(e) {
                // Ignore if user is typing in textarea
                if (e.target.tagName === 'TEXTAREA') return;
                var key = e.key;
                if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'ArrowRight' || key === 'ArrowLeft') {
                    e.preventDefault();
                    if (key === 'ArrowDown') {
                        if (fbFocusedIdx < 0) fbKbdFocusQuestion(0);
                        else fbKbdFocusQuestion(fbFocusedIdx + 1);
                    } else if (key === 'ArrowUp') {
                        if (fbFocusedIdx < 0) fbKbdFocusQuestion(fbQuestions.length - 1);
                        else fbKbdFocusQuestion(fbFocusedIdx - 1);
                    } else if (key === 'ArrowRight') {
                        fbKbdRateFocused(1);
                    } else if (key === 'ArrowLeft') {
                        fbKbdRateFocused(-1);
                    }
                }
            });

            // Clear focus when clicking outside
            form.addEventListener('click', function() {
                setTimeout(fbKbdClearFocus, 150);
            });

            // Initial render
            refreshAll();
        })();
    </script>

    <!-- ── Notification Center JavaScript ── -->
    <script>
    (function() {
        var notifSection = document.querySelector('.notif-list');
        if (!notifSection) return;

        var filterBtns = document.querySelectorAll('.notif-filter-btn');
        var notifItems = document.querySelectorAll('.notif-item');
        var markAllBtn = document.getElementById('notif-mark-all-read');
        var navBadge = document.querySelector('.notif-nav-badge');

        // ── Filter: All / Unread ──
        function applyFilter(filter) {
            filterBtns.forEach(function(btn) {
                var isActive = btn.dataset.filter === filter;
                btn.classList.toggle('active', isActive);
                btn.style.background = isActive ? 'var(--bg-card,#fff)' : 'transparent';
                btn.style.color = isActive ? 'var(--text,#1e293b)' : 'var(--text-muted,#64748b)';
                btn.style.fontWeight = isActive ? '600' : '500';
                btn.style.boxShadow = isActive ? '0 1px 2px rgba(0,0,0,0.06)' : 'none';
            });
            notifItems.forEach(function(item) {
                var isRead = item.dataset.read === '1';
                if (filter === 'all') {
                    item.hidden = false;
                } else {
                    item.hidden = isRead;
                }
            });
        }

        filterBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                applyFilter(btn.dataset.filter);
            });
        });

        // ── Mark single notification as read ──
        function markRead(notifId, itemEl) {
            fetch(baseUrl + '/api/notifications.php?action=mark_read', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + notifId
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    itemEl.dataset.read = '1';
                    itemEl.style.background = '';
                    var markBtn = itemEl.querySelector('.notif-mark-read');
                    if (markBtn) markBtn.remove();
                    var newBadge = itemEl.querySelector('.notif-new-badge');
                    if (newBadge) newBadge.remove();
                    // Check if active filter is 'unread' and hide
                    var activeFilter = document.querySelector('.notif-filter-btn.active');
                    if (activeFilter && activeFilter.dataset.filter === 'unread') {
                        itemEl.hidden = true;
                    }
                    refreshBadge();
                }
            })
            .catch(function() {});
        }

        document.querySelectorAll('.notif-mark-read').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var id = btn.dataset.id;
                var item = btn.closest('.notif-item');
                if (id && item) markRead(id, item);
            });
        });

        // Click on notification item body (not button) to mark as read
        notifItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                if (e.target.closest('.notif-mark-read') || e.target.closest('a')) return;
                if (item.dataset.read === '0') {
                    markRead(item.dataset.id, item);
                }
            });
            item.style.cursor = item.dataset.read === '0' ? 'pointer' : '';
        });

        // ── Mark all as read ──
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function() {
                fetch(baseUrl + '/api/notifications.php?action=mark_all_read', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        notifItems.forEach(function(item) {
                            item.dataset.read = '1';
                            item.style.background = '';
                            item.style.cursor = '';
                            var markBtn = item.querySelector('.notif-mark-read');
                            if (markBtn) markBtn.remove();
                            var badge = item.querySelector('.notif-new-badge');
                            if (badge) badge.remove();
                            var activeFilter = document.querySelector('.notif-filter-btn.active');
                            if (activeFilter && activeFilter.dataset.filter === 'unread') {
                                item.hidden = true;
                            }
                        });
                        refreshBadge();
                    }
                })
                .catch(function() {});
            });
        }

        // ── Refresh sidebar badge ──
        function refreshBadge() {
            fetch(baseUrl + '/api/notifications.php?action=unread_count&t=' + Date.now(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.ok !== false) {
                        var count = data.unread_count || 0;
                        if (count > 0) {
                            if (!navBadge) {
                                var notifLink = document.querySelector('.sidebar-menu a[href*="section=notifications"]');
                                if (notifLink) {
                                    var newBadge = document.createElement('span');
                                    newBadge.className = 'notif-nav-badge';
                                    newBadge.textContent = count;
                                    notifLink.appendChild(newBadge);
                                }
                            } else {
                                navBadge.textContent = count;
                            }
                        } else {
                            if (navBadge) navBadge.remove();
                        }
                    }
                })
                .catch(function() {});
        }

        // Refresh badge every 30s
        setInterval(refreshBadge, 30000);
    })();
    </script>

    <!-- ── Category Comparison Bar Chart ── -->
    <script>
    (function() {
        var dataEl = document.getElementById('categoryComparisonData');
        var canvas = document.getElementById('categoryComparisonChart');
        if (!dataEl || !canvas || typeof Chart === 'undefined') return;

        var chartData = JSON.parse(dataEl.textContent);
        var categories = chartData.categories || [];
        if (!categories || categories.length === 0) return;

        var periodALabel = chartData.periodA || 'Earlier';
        var periodBLabel = chartData.periodB || 'Latest';

        var labels = categories.map(function(c) { return c.category || ''; });
        var scoresA = categories.map(function(c) { return c.periodAScore; });
        var scoresB = categories.map(function(c) { return c.periodBScore; });
        var directions = categories.map(function(c) { return c.direction || 'stable'; });

        var colorMap = { improved: '#166a45', declined: '#b45309', stable: '#64748b', new: '#3b82f6' };
        var barColors = directions.map(function(d) { return colorMap[d] || '#94a3b8'; });

        var ctx = canvas.getContext('2d');
        var isDark = document.body.classList.contains('dark-mode');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var textColor = isDark ? '#cbd5e1' : '#475569';

        var chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: periodALabel,
                    data: scoresA,
                    backgroundColor: barColors.map(function(c) { return c + '99'; }),
                    borderColor: barColors,
                    borderWidth: 2,
                    borderRadius: 4,
                    barPercentage: 0.4,
                    categoryPercentage: 0.8
                }, {
                    label: periodBLabel,
                    data: scoresB,
                    backgroundColor: barColors.map(function(c) { return c + 'CC'; }),
                    borderColor: barColors,
                    borderWidth: 2,
                    borderRadius: 4,
                    barPercentage: 0.4,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        labels: {
                            color: textColor,
                            font: { size: 13, weight: '600' },
                            padding: 16
                        }
                    },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#ffffff',
                        titleColor: isDark ? '#f1f5f9' : '#1e293b',
                        bodyColor: textColor,
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true,
                        callbacks: {
                            afterBody: function(items) {
                                var idx = items[0].dataIndex;
                                var cat = categories[idx];
                                var change = cat.change;
                                var dir = cat.direction || '';
                                if (change === null || change === undefined) return '';
                                var sign = change > 0 ? '+' : '';
                                var emoji = dir === 'improved' ? ' ▲' : dir === 'declined' ? ' ▼' : dir === 'new' ? ' ✦' : ' →';
                                return 'Change: ' + sign + Number(change).toFixed(2) + emoji;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        grid: { color: gridColor },
                        ticks: {
                            color: textColor,
                            stepSize: 0.5,
                            font: { size: 12 }
                        },
                        title: {
                            display: true,
                            text: 'Score (1–5)',
                            color: textColor,
                            font: { size: 12, weight: '600' }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: textColor,
                            font: { size: 11 },
                            maxRotation: 30
                        }
                    }
                }
            }
        });

        // Re-theme chart when dark mode toggles
        var observer = new MutationObserver(function() {
            var isDarkNow = document.body.classList.contains('dark-mode');
            var gColor = isDarkNow ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
            var tColor = isDarkNow ? '#cbd5e1' : '#475569';
            chart.options.scales.y.grid.color = gColor;
            chart.options.scales.y.ticks.color = tColor;
            chart.options.scales.y.title.color = tColor;
            chart.options.scales.x.ticks.color = tColor;
            chart.options.plugins.legend.labels.color = tColor;
            chart.options.plugins.tooltip.backgroundColor = isDarkNow ? '#1e293b' : '#ffffff';
            chart.options.plugins.tooltip.titleColor = isDarkNow ? '#f1f5f9' : '#1e293b';
            chart.options.plugins.tooltip.bodyColor = tColor;
            chart.options.plugins.tooltip.borderColor = isDarkNow ? '#334155' : '#e2e8f0';
            chart.update('none');
        });
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    })();
    </script>

    <!-- ── Teacher Radar Period Selector ── -->
    <script>
    (function() {
        var periodSelect = document.getElementById('teacherRadarPeriod');
        var compareSelect = document.getElementById('teacherRadarCompare');
        var applyBtn = document.getElementById('teacherRadarApplyBtn');
        if (!periodSelect || !applyBtn) return;

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
            params.set('section', 'feedback');

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
    })();
    </script>

    <!-- ── Factor Score Radar Chart ── -->
    <script>
    (function() {
        var dataEl = document.getElementById('factorRadarData');
        var canvas = document.getElementById('factorRadarChart');
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
    })();
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

