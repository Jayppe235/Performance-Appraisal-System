<?php
declare(strict_types=1);

/**
 * Renders a Personal Performance Summary section for any logged-in user
 * (dean, program_head, teacher, etc.) using the same data source as
 * api/my-evaluation-results.php.
 *
 * Usage: In a PHP dashboard template:
 *   <section class="admin-box module-wide">
 *       <?php require __DIR__ . '/personal_performance_summary.php'; ?>
 *   </section>
 */

// ── Helpers ──────────────────────────────────────────────────────────

if (!function_exists('pps_performance_level')) {
    function pps_performance_level(?float $score): string
    {
        return match (true) {
            $score === null => 'Pending',
            $score >= 4.50 => 'Outstanding',
            $score >= 3.50 => 'Very Satisfactory',
            $score >= 2.50 => 'Satisfactory',
            $score >= 1.50 => 'Fair',
            default => 'Needs Improvement',
        };
    }
}

if (!function_exists('pps_recommended_session')) {
    function pps_recommended_session(string $category): string
    {
        $key = strtolower($category);
        if (str_contains($key, 'classroom')) return 'Classroom management and learner engagement seminar';
        if (str_contains($key, 'communication')) return 'Communication skills and constructive feedback workshop';
        if (str_contains($key, 'teamwork') || str_contains($key, 'interpersonal') || str_contains($key, 'collaboration')) return 'Team collaboration and interpersonal sensitivity seminar';
        if (str_contains($key, 'attendance') || str_contains($key, 'punctuality')) return 'Professional discipline, attendance, and time management seminar';
        if (str_contains($key, 'leadership') || str_contains($key, 'management')) return 'Leadership planning and people management coaching';
        if (str_contains($key, 'knowledge') || str_contains($key, 'quality') || str_contains($key, 'excellence')) return 'Instructional excellence and work quality enhancement seminar';
        if (str_contains($key, 'initiative') || str_contains($key, 'resourcefulness') || str_contains($key, 'creativity') || str_contains($key, 'innovation')) return 'Innovation, initiative, and resourcefulness workshop';
        if (str_contains($key, 'institutional') || str_contains($key, 'commitment') || str_contains($key, 'responsibility')) return 'Institutional commitment and professional responsibility seminar';
        if (str_contains($key, 'decorum') || str_contains($key, 'ethic')) return 'Professional ethics and decorum seminar';
        return 'Targeted professional development session for ' . ($category !== '' ? $category : 'the identified area');
    }
}

if (!function_exists('pps_action_text')) {
    function pps_action_text(string $category, float $score): string
    {
        $lower = strtolower($category);
        if (str_contains($lower, 'classroom')) return 'Use clearer routines, learner engagement checks, and consistent classroom management strategies.';
        if (str_contains($lower, 'communication')) return 'Practice clearer instructions, timely feedback, and active listening during academic interactions.';
        if (str_contains($lower, 'teamwork') || str_contains($lower, 'interpersonal')) return 'Strengthen collaboration through peer consultation, shared planning, and constructive feedback habits.';
        if (str_contains($lower, 'attendance') || str_contains($lower, 'punctuality')) return 'Set stricter schedule reminders and document attendance-related commitments consistently.';
        if (str_contains($lower, 'quality') || str_contains($lower, 'knowledge')) return 'Review instructional materials, assessment practices, and work outputs against department standards.';
        if (str_contains($lower, 'initiative') || str_contains($lower, 'resourcefulness')) return 'Try one improvement project or teaching innovation and document the impact for follow-up coaching.';
        if (str_contains($lower, 'institutional') || str_contains($lower, 'commitment')) return 'Align work priorities with institutional policies, deadlines, and program goals.';
        return $score < 3.5
            ? 'Coordinate with your dean or program head for coaching and a focused improvement plan.'
            : 'Maintain this area through continued practice, peer sharing, and periodic self-review.';
    }
}

// ── Look up the current user's linked faculty record ────────────────

$ppsUser = current_user();
$ppsFacultyId = null;
$ppsFacultyName = '';

if ($ppsUser !== null) {
    $ppsFaculty = admin_one(
        'SELECT f.id, f.full_name, f.department, f.program_code
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE u.id = :user_id OR f.user_id = :user_id_match
         LIMIT 1',
        ['user_id' => (int) $ppsUser['id'], 'user_id_match' => (int) $ppsUser['id']]
    );

    if ($ppsFaculty !== null) {
        $ppsFacultyId = (int) $ppsFaculty['id'];
        $ppsFacultyName = (string) ($ppsFaculty['full_name'] ?? '');
    }
}

// ── If no linked faculty record, show a message ─────────────────────

if ($ppsFacultyId === null):
?>
    <div class="box-title">
        <h2>Personal Performance Summary</h2>
        <span>Your evaluation results in one place</span>
    </div>
    <div class="notice info">
        <p>Your personal evaluation results will appear here once your account is linked to a faculty profile. Please ask Admin/HR to link your account.</p>
    </div>
<?php
    return; // Stop rendering
endif;

// ── Fetch evaluation data (mirrors api/my-evaluation-results.php) ───

dipascaf_ensure_form_a_schema();
dipascaf_ensure_form_b_schema();
admin_ensure_archive_schema();

// Get period progress
$ppsProgressRows = admin_all(
    "SELECT pa.cycle_name,
            COUNT(*) AS total_assignments,
            SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_assignments,
            MAX(pa.submitted_at) AS latest_submitted_at
     FROM peer_assignments pa
     WHERE pa.evaluatee_faculty_id = :fac_id AND COALESCE(pa.is_archived, 0) = 0
     GROUP BY pa.cycle_name
     ORDER BY MAX(COALESCE(pa.submitted_at, pa.assigned_at)) DESC, pa.cycle_name DESC",
    ['fac_id' => $ppsFacultyId]
);

$ppsPeriodProgress = [];
foreach ($ppsProgressRows as $row) {
    $period = (string) ($row['cycle_name'] ?? '');
    if ($period === '') continue;
    $total = (int) ($row['total_assignments'] ?? 0);
    $submitted = (int) ($row['submitted_assignments'] ?? 0);
    $ppsPeriodProgress[$period] = [
        'period' => $period,
        'total' => $total,
        'submitted' => $submitted,
        'pending' => max(0, $total - $submitted),
        'complete' => $total > 0 && $submitted >= $total,
    ];
}

$ppsCompletedPeriods = array_values(array_filter(
    array_keys($ppsPeriodProgress),
    static fn (string $period): bool => (bool) ($ppsPeriodProgress[$period]['complete'] ?? false)
));

$ppsLockedProgress = $ppsProgressRows[0] ?? null;
$ppsCanReveal = $ppsCompletedPeriods !== [];
$ppsLatestPeriod = $ppsCompletedPeriods[0] ?? ($ppsLockedProgress['cycle_name'] ?? '');
$ppsTotalAssignments = (int) ($ppsLockedProgress['total_assignments'] ?? 0);
$ppsSubmittedAssignments = (int) ($ppsLockedProgress['submitted_assignments'] ?? 0);
$ppsPendingAssignments = max(0, $ppsTotalAssignments - $ppsSubmittedAssignments);

$ppsData = [];
$ppsStrengths = [];
$ppsWeaknesses = [];
$ppsRecommendations = [];
$ppsLatestScore = null;

if ($ppsCanReveal) {
    // Fetch completed assignment scores
    $periodPlaceholders = [];
    $periodSqlParams = ['fac_a' => $ppsFacultyId, 'fac_b' => $ppsFacultyId];
    foreach ($ppsCompletedPeriods as $index => $period) {
        $key = 'period_' . $index;
        $periodPlaceholders[] = ':' . $key;
        $periodSqlParams[$key] = $period;
    }
    $periodIn = implode(',', $periodPlaceholders);

    $ppsAssignmentRows = admin_all(
        "SELECT assignment_scores.cycle_name,
                ROUND(AVG(assignment_scores.assignment_score), 4) AS overall_score,
                COUNT(*) AS completed_assignments,
                MAX(assignment_scores.submitted_at) AS latest_submitted_at
         FROM (
             SELECT pa.id, pa.cycle_name, pa.submitted_at, ROUND(SUM(x.weighted_score), 4) AS assignment_score
             FROM (
                 SELECT assignment_id, weighted_score
                 FROM pmas_form_a_category_results
                 WHERE evaluatee_faculty_id = :fac_a AND status = 'completed' AND COALESCE(is_archived, 0) = 0
                 UNION ALL
                 SELECT assignment_id, weighted_score
                 FROM pmas_form_b_category_results
                 WHERE evaluatee_faculty_id = :fac_b AND status = 'completed' AND COALESCE(is_archived, 0) = 0
             ) x
             JOIN peer_assignments pa ON pa.id = x.assignment_id
             WHERE COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted' AND pa.cycle_name IN ($periodIn)
             GROUP BY pa.id, pa.cycle_name, pa.submitted_at
         ) assignment_scores
         GROUP BY assignment_scores.cycle_name
         ORDER BY latest_submitted_at DESC, assignment_scores.cycle_name DESC",
        $periodSqlParams
    );

    foreach ($ppsAssignmentRows as $row) {
        $periodName = (string) ($row['cycle_name'] ?? '');
        preg_match('/\b(20\d{2})\b/', $periodName, $matches);
        $score = isset($row['overall_score']) ? (float) $row['overall_score'] : null;
        $ppsData[] = [
            'periodKey' => $periodName,
            'period' => $periodName,
            'year' => $matches[1] ?? '',
            'overallScore' => $score,
            'performanceLevel' => pps_performance_level($score),
            'status' => 'Completed',
            'completedAssignments' => (int) ($row['completed_assignments'] ?? 0),
            'totalAssignments' => (int) ($ppsPeriodProgress[$periodName]['total'] ?? 0),
            'submittedAt' => (string) ($row['latest_submitted_at'] ?? ''),
        ];
    }

    $ppsLatestPeriod = (string) ($ppsData[0]['period'] ?? $ppsCompletedPeriods[0] ?? '');
    $ppsLatestScore = $ppsData[0]['overallScore'] ?? null;

    // Fetch category data for the latest period
    $ppsCategoryRows = $ppsLatestPeriod !== '' ? admin_all(
        "SELECT form_label, category_title, average_rating, recommendation
         FROM (
             SELECT 'Form A' AS form_label, c.title AS category_title, r.average_rating, r.recommendation, pa.cycle_name
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id = :fac_a AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'
             UNION ALL
             SELECT 'Form B' AS form_label, c.title AS category_title, r.average_rating, r.recommendation, pa.cycle_name
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id = :fac_b AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'
         ) category_data
         WHERE cycle_name = :latest_period",
        ['fac_a' => $ppsFacultyId, 'fac_b' => $ppsFacultyId, 'latest_period' => $ppsLatestPeriod]
    ) : [];

    $ppsCategoryMap = [];
    foreach ($ppsCategoryRows as $row) {
        $category = trim((string) ($row['category_title'] ?? ''));
        if ($category === '') continue;
        $key = strtolower($category);
        if (!isset($ppsCategoryMap[$key])) {
            $ppsCategoryMap[$key] = [
                'category' => $category,
                'form' => (string) ($row['form_label'] ?? ''),
                'scores' => [],
                'recommendations' => [],
            ];
        }
        $ppsCategoryMap[$key]['scores'][] = (float) ($row['average_rating'] ?? 0);
        $recommendation = secure_decrypt_value($row['recommendation'] ?? '');
        if ($recommendation !== '') $ppsCategoryMap[$key]['recommendations'][] = $recommendation;
    }

    $ppsCategories = array_map(static function (array $item): array {
        $scores = array_values(array_filter($item['scores'], static fn ($score): bool => (float) $score > 0));
        $average = $scores !== [] ? array_sum($scores) / count($scores) : 0.0;
        return [
            'category' => $item['category'],
            'form' => $item['form'],
            'score' => round($average, 2),
            'recommendation' => (string) ($item['recommendations'][0] ?? ''),
        ];
    }, array_values($ppsCategoryMap));

    usort($ppsCategories, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
    $ppsWeaknesses = array_slice($ppsCategories, 0, 3);
    $ppsStrengths = array_slice(array_reverse($ppsCategories), 0, 3);

    // Build recommendations from weaknesses
    foreach ($ppsWeaknesses as $weakness) {
        $category = (string) ($weakness['category'] ?? '');
        $score = (float) ($weakness['score'] ?? 0);
        $existingRecommendation = trim((string) ($weakness['recommendation'] ?? ''));
        $ppsRecommendations[] = [
            'category' => $category,
            'score' => $score,                    'seminar' => pps_recommended_session($category),
                    'action' => $existingRecommendation !== '' ? $existingRecommendation : pps_action_text($category, $score),
        ];
    }
}

$ppsScorePct = $ppsLatestScore !== null ? min(100, max(0, round(($ppsLatestScore / 5) * 100))) : 0;
$ppsName = $ppsFacultyName ?: ($ppsUser['full_name'] ?? 'User');
?>

<div class="box-title">
    <h2>Personal Performance Summary</h2>
    <span>Your evaluation results at a glance</span>
</div>

<?php if (!$ppsCanReveal): ?>
    <div class="pps-locked">
        <div class="pps-locked-icon">!</div>
        <div>
            <strong>Overall results are locked until evaluations are complete.</strong>
            <p>
                Waiting for <?= e((string) $ppsPendingAssignments) ?> evaluator<?= $ppsPendingAssignments === 1 ? '' : 's' ?> to submit for <?= e($ppsLatestPeriod ?: 'this period') ?>.
                Your overall score and AI insights will appear after all assigned evaluators submit their evaluations.
            </p>
        </div>
    </div>
<?php else: ?>
    <!-- ── Hero Section with Donut Chart ──────────────────────────── -->
    <div class="pps-hero">
        <div>
            <span class="pps-hero-eyebrow">Your Evaluation Results</span>
            <h2>Personal Performance Summary</h2>
            <p>Review your latest evaluation results, strengths, weaknesses, and recommended development actions.</p>
        </div>
        <div class="pps-hero-chart">
            <div class="completion-donut" style="--completion-rate: <?= e((string) $ppsScorePct) ?>;">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="completion-donut-track" cx="60" cy="60" r="48" pathLength="100"></circle>
                    <circle class="completion-donut-progress" cx="60" cy="60" r="48" pathLength="100"></circle>
                </svg>
                <strong><?= $ppsLatestScore !== null ? e(number_format($ppsLatestScore, 2)) : '--' ?></strong>
                <span>Latest Score</span>
            </div>
            <div class="pps-hero-stats">
                <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-safe">&#9679;</span> Received: <?= e((string) ($ppsSubmittedAssignments ?: count($ppsData))) ?></span>
                <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-warning">&#9679;</span> Score: <?= $ppsLatestScore !== null ? e(number_format($ppsLatestScore, 2)) : 'Pending' ?></span>
                <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-info">&#9679;</span> Period: <?= e($ppsLatestPeriod ?: 'N/A') ?></span>
                <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-accent">&#9679;</span> Level: <?= e(pps_performance_level($ppsLatestScore)) ?></span>
            </div>
        </div>
    </div>

    <!-- Metrics -->
    <div class="stat-grid compact pps-metrics">
        <article>
            <span>Evaluations Received</span>
            <strong><?= e((string) ($ppsSubmittedAssignments ?: count($ppsData))) ?></strong>
            <small><?= $ppsTotalAssignments ? 'of ' . e((string) $ppsTotalAssignments) . ' submitted' : 'Total completed evaluations' ?></small>
        </article>
        <article>
            <span>Latest Overall Score</span>
            <strong><?= $ppsLatestScore !== null ? e(number_format($ppsLatestScore, 2)) : 'No result yet' ?></strong>
            <small>Most recent period average</small>
        </article>
        <article>
            <span>Latest Period</span>
            <strong><?= e($ppsLatestPeriod ?: 'N/A') ?></strong>
            <small>Most recent appraisal cycle</small>
        </article>
        <article>
            <span>Performance Level</span>
            <strong><?= e(pps_performance_level($ppsLatestScore)) ?></strong>
            <small>Based on latest period</small>
        </article>
    </div>

    <!-- Strengths, Weaknesses, Recommendations -->
    <div class="pps-insight-grid">
        <!-- Strengths -->
        <article class="pps-insight-card is-strength">
            <div class="pps-insight-head">
                <span class="pps-insight-icon">&#9650;</span>
                <div>
                    <span class="pps-insight-label">Strengths</span>
                    <strong class="pps-insight-subtitle">Highest rated areas</strong>
                </div>
            </div>
            <?php if (count($ppsStrengths) > 0): ?>
                <?php foreach ($ppsStrengths as $item): ?>
                    <div class="pps-insight-row">
                        <span><?= e($item['category']) ?></span>
                        <strong><?= e(number_format((float) $item['score'], 2)) ?>/5</strong>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pps-empty-text">No strength areas available yet.</p>
            <?php endif; ?>
        </article>

        <!-- Weaknesses -->
        <article class="pps-insight-card is-weakness">
            <div class="pps-insight-head">
                <span class="pps-insight-icon">&#9660;</span>
                <div>
                    <span class="pps-insight-label">Weaknesses</span>
                    <strong class="pps-insight-subtitle">Areas to improve first</strong>
                </div>
            </div>
            <?php if (count($ppsWeaknesses) > 0): ?>
                <?php foreach ($ppsWeaknesses as $item): ?>
                    <div class="pps-insight-row is-weakness">
                        <span><?= e($item['category']) ?></span>
                        <strong><?= e(number_format((float) $item['score'], 2)) ?>/5</strong>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pps-empty-text">No weak areas available yet.</p>
            <?php endif; ?>
        </article>

        <!-- Recommendations -->
        <article class="pps-insight-card is-recommendation">
            <div class="pps-insight-head">
                <span class="pps-insight-icon">&#9733;</span>
                <div>
                    <span class="pps-insight-label">AI Recommendations</span>
                    <strong class="pps-insight-subtitle">What to do next</strong>
                </div>
            </div>
            <?php if (count($ppsRecommendations) > 0): ?>
                <?php foreach ($ppsRecommendations as $rec): ?>
                    <div class="pps-recommendation-item">
                        <strong><?= e($rec['seminar']) ?></strong>
                        <p><?= e($rec['action']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pps-empty-text">Recommendations will appear once category scores are available.</p>
            <?php endif; ?>
        </article>
    </div>

    <!-- Period History Table -->
    <?php if (count($ppsData) > 0): ?>
        <div class="box-title pps-section-title">
            <h2>Completed Periods</h2>
            <span><?= e((string) count($ppsData)) ?> period<?= count($ppsData) !== 1 ? 's' : '' ?></span>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Year</th>
                    <th>Overall Score</th>
                    <th>Performance Level</th>
                    <th>Status</th>
                    <th>Evaluators</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ppsData as $row): ?>
                    <tr>
                        <td data-label="Period"><?= e($row['period']) ?></td>
                        <td data-label="Year"><?= e($row['year'] ?: '-') ?></td>
                        <td data-label="Overall Score">
                            <strong><?= $row['overallScore'] !== null ? e(number_format((float) $row['overallScore'], 2)) : '--' ?></strong>
                            <small>/5</small>
                        </td>
                        <td data-label="Performance Level"><?= e($row['performanceLevel']) ?></td>
                        <td data-label="Status"><span class="pps-table-status"><?= e($row['status']) ?></span></td>
                        <td data-label="Evaluators"><?= e((string) $row['completedAssignments']) ?>/<?= e((string) $row['totalAssignments']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="notice info">
            <p>No completed evaluation periods found. Results will appear once evaluations are finalized.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>
