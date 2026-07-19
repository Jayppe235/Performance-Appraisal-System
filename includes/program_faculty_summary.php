<?php
declare(strict_types=1);

/**
 * Renders a Program Faculty Summary for the Program Head dashboard.
 * Shows aggregated evaluation results, weak areas, and recommended seminars
 * for ALL faculty under the program head's program(s), NOT just the
 * program head's own personal evaluation.
 *
 * Usage:
 *   <section class="admin-box module-wide">
 *       <?php require __DIR__ . '/program_faculty_summary.php'; ?>
 *   </section>
 */

// ── Helpers ──────────────────────────────────────────────────────────

if (!function_exists('pfs_seminar')) {
    function pfs_seminar(string $weakArea): string
    {
        $area = strtolower($weakArea);
        return match (true) {
            str_contains($area, 'communication') => 'Communication Skills and Professional Feedback Seminar',
            str_contains($area, 'teaching') || str_contains($area, 'instruction') => 'Teaching Strategies and Learning Outcomes Seminar',
            str_contains($area, 'classroom') || str_contains($area, 'learner') => 'Classroom Management and Learner Engagement Seminar',
            str_contains($area, 'job') || str_contains($area, 'knowledge') || str_contains($area, 'competence') => 'Subject Mastery and Professional Competence Seminar',
            str_contains($area, 'leadership') || str_contains($area, 'administrative') => 'Academic Leadership and Administrative Effectiveness Seminar',
            str_contains($area, 'technology') || str_contains($area, 'digital') => 'Educational Technology Integration Seminar',
            default => 'Targeted Faculty Development Seminar for ' . ($weakArea !== '' ? $weakArea : 'Professional Growth'),
        };
    }
}

if (!function_exists('pfs_performance_label')) {
    function pfs_performance_label(?float $average): string
    {
        if ($average === null) return 'Pending';
        return match (true) {
            $average >= 4.51 => 'Excellent',
            $average >= 3.01 => 'Satisfactory',
            default => 'Needs Support',
        };
    }
}

// ── Current user & scope ────────────────────────────────────────────

$pfsUser = current_user();
$pfsProgramHeadId = $pfsUser !== null ? (int) $pfsUser['id'] : 0;

if ($pfsProgramHeadId === 0):
?>
    <div class="box-title">
        <h2>Program Faculty Summary</h2>
        <span>Aggregated evaluation results for your program</span>
    </div>
    <div class="notice info">
        <p>Please log in as a Program Head to view this summary.</p>
    </div>
<?php
    return;
endif;

// ── Scope: Programs under this Program Head ──────────────────────────

$pfsProgramRows = admin_all(
    'SELECT p.program_code, p.program_name
     FROM programs p
     WHERE p.program_head_user_id = :uid AND p.is_active = 1
     ORDER BY p.program_code',
    ['uid' => $pfsProgramHeadId]
);

$pfsProgramCodes = array_values(array_filter(array_map(
    static fn (array $row): string => trim((string) ($row['program_code'] ?? '')),
    $pfsProgramRows
)));

$pfsProgramNames = [];
foreach ($pfsProgramRows as $prog) {
    $code = trim((string) ($prog['program_code'] ?? ''));
    if ($code !== '') {
        $pfsProgramNames[$code] = trim((string) ($prog['program_name'] ?? $code));
    }
}

// Fallback from user record
if ($pfsProgramCodes === []) {
    $pfsUserRecord = admin_one(
        'SELECT program FROM users WHERE id = :id AND role = "program_head" LIMIT 1',
        ['id' => $pfsProgramHeadId]
    );
    $fallback = trim((string) ($pfsUserRecord['program'] ?? ''));
    if ($fallback !== '') {
        $pfsProgramCodes[] = $fallback;
        $pfsProgramNames[$fallback] = $fallback;
    }
}

$pfsProgramLabel = $pfsProgramCodes !== []
    ? implode(', ', $pfsProgramCodes)
    : 'No program assigned';

if ($pfsProgramCodes === []):
?>
    <div class="box-title">
        <h2>Program Faculty Summary</h2>
        <span>No programs assigned</span>
    </div>
    <div class="notice info">
        <p>You are not assigned to any program yet. Please contact Admin/HR to link your account to a program.</p>
    </div>
<?php
    return;
endif;

// ── Fetch faculty under the program head's programs ─────────────────

admin_ensure_faculty_program_schema();
dipascaf_ensure_form_b_schema();
admin_ensure_archive_schema();

$placeholders = implode(',', array_fill(0, count($pfsProgramCodes), '?'));
$pfsFacultyRows = admin_all(
    "SELECT f.id, f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''), '') AS program_code
     FROM faculty f
     LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
     WHERE f.is_active = 1 AND f.is_archived = 0
       AND (f.program_code IN ($placeholders) OR u.program IN ($placeholders))
       AND COALESCE(LOWER(u.role), 'teacher') = 'teacher'
     ORDER BY program_code, f.full_name",
    array_merge($pfsProgramCodes, $pfsProgramCodes)
);

$pfsFacultyCount = count($pfsFacultyRows);

// ── Fetch evaluation results, insights, interventions ───────────────

$pfsFacultyIds = array_map(static fn (array $row): int => (int) $row['id'], $pfsFacultyRows);
$pfsFacultyById = [];
foreach ($pfsFacultyRows as $row) {
    $pfsFacultyById[(int) $row['id']] = $row;
}

$pfsResultRows = [];
$pfsPlanRows = [];
$pfsInsightRows = [];

if ($pfsFacultyIds !== []) {
    $fPlaceholders = implode(',', array_fill(0, count($pfsFacultyIds), '?'));

    $pfsResultRows = admin_all(
        "SELECT x.evaluatee_faculty_id, x.category_title, x.average_rating, x.weighted_score, x.recommendation, x.submitted_at
         FROM (
             SELECT r.evaluatee_faculty_id, c.title AS category_title,
                    r.average_rating, r.weighted_score, r.recommendation, r.submitted_at
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id IN ($fPlaceholders)
               AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
             UNION ALL
             SELECT r.evaluatee_faculty_id, c.title AS category_title,
                    r.average_rating, r.weighted_score, r.recommendation, r.submitted_at
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id IN ($fPlaceholders)
               AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
         ) x
         ORDER BY x.submitted_at DESC, x.average_rating ASC",
        $pfsFacultyIds
    );

    $pfsPlanRows = admin_all(
        "SELECT p.*, f.full_name AS faculty_name, COALESCE(NULLIF(f.program_code, ''), '') AS program_code
         FROM intervention_plans p
         JOIN faculty f ON f.id = p.faculty_id
         WHERE p.faculty_id IN ($fPlaceholders)
         ORDER BY FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
        $pfsFacultyIds
    );

    $pfsInsightRows = admin_all(
        "SELECT i.*, f.full_name AS faculty_name, COALESCE(NULLIF(f.program_code, ''), '') AS program_code
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         WHERE i.faculty_id IN ($fPlaceholders)
         ORDER BY i.created_at DESC",
        $pfsFacultyIds
    );
}

// ── Process results by faculty ───────────────────────────────────────

$resultsByFaculty = [];
foreach ($pfsResultRows as $row) {
    $fid = (int) $row['evaluatee_faculty_id'];
    $resultsByFaculty[$fid] ??= [
        'totalScore' => 0.0,
        'categoryCount' => 0,
        'weakArea' => '',
        'weakRating' => 99.0,
        'recommendation' => '',
        'submittedAt' => (string) ($row['submitted_at'] ?? ''),
    ];
    $rating = (float) ($row['average_rating'] ?? 0);
    $resultsByFaculty[$fid]['totalScore'] += $rating;
    $resultsByFaculty[$fid]['categoryCount']++;
    if ($rating < $resultsByFaculty[$fid]['weakRating']) {
        $resultsByFaculty[$fid]['weakRating'] = $rating;
        $resultsByFaculty[$fid]['weakArea'] = (string) ($row['category_title'] ?? 'Professional Growth');
        $resultsByFaculty[$fid]['recommendation'] = secure_decrypt_value($row['recommendation'] ?? '');
    }
}

$insightsByFaculty = [];
foreach ($pfsInsightRows as $row) {
    $fid = (int) $row['faculty_id'];
    $insightsByFaculty[$fid] ??= $row;
}

// ── Build faculty results array ──────────────────────────────────────

$pfsFacultyResults = [];
foreach ($pfsFacultyById as $fid => $faculty) {
    $result = $resultsByFaculty[$fid] ?? null;
    $insight = $insightsByFaculty[$fid] ?? null;
    $average = $result !== null && $result['categoryCount'] > 0
        ? round($result['totalScore'] / $result['categoryCount'], 2)
        : null;
    $weakArea = $result['weakArea'] ?? (string) ($insight['weak_area'] ?? 'No submitted result yet');
    $pfsFacultyResults[] = [
        'id' => $fid,
        'faculty' => (string) $faculty['full_name'],
        'program' => (string) ($faculty['program_code'] ?: 'Unassigned Program'),
        'averageRating' => $average,
        'weakArea' => $weakArea,
        'result' => pfs_performance_label($average),
        'seminar' => $average === null ? 'Pending evaluation result' : pfs_seminar($weakArea),
    ];
}

// ── Build training plans ─────────────────────────────────────────────

$pfsTrainingPlans = [];
foreach ($pfsPlanRows as $plan) {
    $pfsTrainingPlans[] = [
        'id' => (int) $plan['id'],
        'program' => (string) ($plan['program_code'] ?: 'Unassigned Program'),
        'faculty' => (string) ($plan['faculty_name'] ?? ''),
        'weakArea' => (string) ($plan['weak_area'] ?? ''),
        'seminar' => pfs_seminar((string) ($plan['weak_area'] ?? '')),
        'recommendation' => (string) ($plan['recommendation'] ?? ''),
        'status' => admin_status_label((string) ($plan['status'] ?? 'planned')),
    ];
}

// If no stored plans, auto-generate from faculty weak areas
if ($pfsTrainingPlans === []) {
    $resultsByProg = [];
    foreach ($pfsFacultyResults as $row) {
        if ($row['weakArea'] === 'No submitted result yet') continue;
        $resultsByProg[$row['program']][] = $row;
    }
    foreach ($resultsByProg as $progCode => $rows) {
        $weakCounts = [];
        foreach ($rows as $row) {
            $weakCounts[$row['weakArea']] = ($weakCounts[$row['weakArea']] ?? 0) + 1;
        }
        arsort($weakCounts);
        $topWeak = array_key_first($weakCounts);
        $seminar = '';
        foreach ($rows as $row) {
            if ($row['weakArea'] === $topWeak) {
                $seminar = $row['seminar'];
                break;
            }
        }
        if ($seminar === '') $seminar = pfs_seminar($topWeak);
        $pfsTrainingPlans[] = [
            'id' => 0,
            'program' => $progCode,
            'faculty' => count($rows) . ' faculty',
            'weakArea' => $topWeak,
            'seminar' => $seminar,
            'recommendation' => 'Recommend attendance in ' . $seminar . ' for all ' . count($rows) . ' faculty in ' . $progCode . '.',
            'status' => 'Planned',
        ];
    }
}

// ── Check if ALL faculty in this program have completed ALL evaluations ──
$pfsAllFacultyComplete = false;
if ($pfsFacultyIds !== []) {
    $fPlaceholders = implode(',', array_fill(0, count($pfsFacultyIds), '?'));
    $completionRows = admin_all(
        "SELECT pa.evaluatee_faculty_id,
                COUNT(DISTINCT pa.id) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed_assignments
         FROM peer_assignments pa
         WHERE pa.evaluatee_faculty_id IN ($fPlaceholders)
           AND pa.assignment_type IN ('peer', 'dean', 'program_head')
           AND COALESCE(pa.is_archived, 0) = 0
         GROUP BY pa.evaluatee_faculty_id",
        $pfsFacultyIds
    );
    $allComplete = true;
    foreach ($completionRows as $row) {
        $total = (int) ($row['total_assignments'] ?? 0);
        $completed = (int) ($row['completed_assignments'] ?? 0);
        if ($total > 0 && $completed < $total) {
            $allComplete = false;
            break;
        }
    }
    $pfsAllFacultyComplete = $allComplete;
}

// ── Build weak areas register (from category results) — only show when ALL evaluations complete ──

$pfsWeakAreas = [];
if ($pfsAllFacultyComplete && $pfsFacultyIds !== []) {
    $fPlaceholders = implode(',', array_fill(0, count($pfsFacultyIds), '?'));
    $formBWeak = admin_all(
        "SELECT r.evaluatee_faculty_id, r.average_rating, r.submitted_at, r.status,
                c.title AS category_title, 'Form B' AS form_title,
                f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM pmas_form_b_category_results r
         JOIN pmas_form_b_categories c ON c.id = r.category_id
         JOIN peer_assignments pa ON pa.id = r.assignment_id
         JOIN faculty f ON f.id = r.evaluatee_faculty_id
         WHERE r.evaluatee_faculty_id IN ($fPlaceholders)
           AND r.status = 'completed'
           AND COALESCE(r.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND r.average_rating <= 3.50
         ORDER BY r.average_rating ASC, r.submitted_at DESC",
        $pfsFacultyIds
    );
    $seen = [];
    foreach ($formBWeak as $row) {
        $fid = (int) $row['evaluatee_faculty_id'];
        $cat = (string) ($row['category_title'] ?? '');
        $key = "{$fid}|{$cat}";
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $pfsWeakAreas[] = [
            'facultyName' => (string) ($row['full_name'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'program' => (string) ($row['program_code'] ?? 'Unassigned Program'),
            'formTitle' => (string) ($row['form_title'] ?? 'Form B'),
            'weakCategory' => $cat,
            'averageScore' => number_format((float) ($row['average_rating'] ?? 0), 2),
            'dateSubmitted' => (string) ($row['submitted_at'] ?? ''),
            'status' => admin_status_label((string) ($row['status'] ?? 'completed')),
            'seminar' => pfs_seminar($cat),
        ];
    }
}

// Fallback: derive from faculty results (only when fully complete)
if ($pfsAllFacultyComplete && $pfsWeakAreas === []) {
    foreach ($pfsFacultyResults as $row) {
        if ($row['weakArea'] === 'No submitted result yet') continue;
        $pfsWeakAreas[] = [
            'facultyName' => $row['faculty'],
            'department' => $row['program'],
            'program' => $row['program'],
            'formTitle' => 'Form B',
            'weakCategory' => $row['weakArea'],
            'averageScore' => $row['averageRating'] !== null ? number_format($row['averageRating'], 2) : '--',
            'dateSubmitted' => '—',
            'status' => 'Identified',
            'seminar' => pfs_seminar($row['weakArea']),
        ];
    }
}

// ── Summary counts ───────────────────────────────────────────────────

$pfsReviewed = count(array_filter($pfsFacultyResults, static fn (array $row): bool => $row['averageRating'] !== null));
$pfsPlanCount = count($pfsTrainingPlans);
$pfsWeakCount = count($pfsWeakAreas);
?>

<div class="box-title">
    <h2>Program Faculty Summary</h2>
    <span><?= e($pfsProgramLabel) ?> — <?= e((string) $pfsFacultyCount) ?> faculty</span>
</div>

<!-- ── Hero Section ──────────────────────────────────────────────── -->
<div class="pps-hero">
    <div>
        <span class="pps-hero-eyebrow">Faculty Evaluation Overview</span>
        <h2>Program Faculty Performance</h2>
        <p>Aggregated evaluation results, weak areas, and recommended development actions for all faculty under your program.</p>
    </div>
    <div class="pps-hero-chart is-compact">
        <div class="pps-hero-stats">
            <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-safe">&#9679;</span> Faculty: <?= e((string) $pfsFacultyCount) ?></span>
            <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-info">&#9679;</span> Reviewed: <?= e((string) $pfsReviewed) ?></span>
            <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-warning">&#9679;</span> Weak Areas: <?= e((string) $pfsWeakCount) ?></span>
            <span class="pps-hero-stat"><span class="pps-hero-stat-dot is-accent">&#9679;</span> Training Plans: <?= e((string) $pfsPlanCount) ?></span>
        </div>
    </div>
</div>

<!-- ── Faculty Results Table ─────────────────────────────────────── -->
<?php if ($pfsFacultyResults !== []): ?>
<div class="box-title pps-section-title">
    <h2>Faculty Evaluation Results</h2>
    <span><?= e((string) count($pfsFacultyResults)) ?> faculty members</span>
</div>
<table class="data-table">
    <thead>
        <tr>
            <th>Faculty</th>
            <th>Program</th>
            <th>Average Rating</th>
            <th>Result</th>
            <th>Weak Area</th>
            <th>Recommended Seminar</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($pfsFacultyResults as $fac): ?>
            <tr>
                <td data-label="Faculty"><strong><?= e($fac['faculty']) ?></strong></td>
                <td data-label="Program"><?= e($fac['program']) ?></td>
                <td data-label="Average Rating">
                    <?php if ($fac['averageRating'] !== null): ?>
                        <strong><?= e(number_format($fac['averageRating'], 2)) ?></strong><small>/5</small>
                    <?php else: ?>
                        <span class="pps-empty-text">Pending</span>
                    <?php endif; ?>
                </td>
                <td data-label="Result">
                    <span style="color:<?= $fac['averageRating'] !== null && $fac['averageRating'] >= 3.01 ? '#166a45' : ($fac['averageRating'] !== null ? '#b45309' : '#94a3b8') ?>;font-weight:800;">
                        <?= e($fac['result']) ?>
                    </span>
                </td>
                <td data-label="Weak Area"><?= e($fac['weakArea']) ?></td>
                <td data-label="Recommended Seminar"><?= e($fac['seminar']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ── Training Plans / Recommendations — Only when ALL faculty are fully evaluated ── -->
<?php if ($pfsAllFacultyComplete && $pfsTrainingPlans !== []): ?>
<div class="box-title pps-section-title">
    <h2>Recommended Training &amp; Seminars</h2>
    <span><?= e((string) $pfsPlanCount) ?> plan<?= $pfsPlanCount !== 1 ? 's' : '' ?></span>
</div>
<table class="data-table">
    <thead>
        <tr>
            <th>Program</th>
            <th>Faculty</th>
            <th>Weak Area</th>
            <th>Recommended Seminar</th>
            <th>Recommendation</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($pfsTrainingPlans as $plan): ?>
            <tr>
                <td data-label="Program"><?= e($plan['program']) ?></td>
                <td data-label="Faculty"><?= e($plan['faculty']) ?></td>
                <td data-label="Weak Area"><?= e($plan['weakArea']) ?></td>
                <td data-label="Seminar"><strong><?= e($plan['seminar']) ?></strong></td>
                <td data-label="Recommendation"><?= e($plan['recommendation']) ?></td>
                <td data-label="Status"><span class="pps-table-status"><?= e($plan['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php elseif (!$pfsAllFacultyComplete): ?>
<div class="notice info" style="margin-top:16px;">
    <p>Training recommendations will be available once <strong>all</strong> faculty in this program have completed evaluations.</p>
</div>
<?php endif; ?>

<!-- ── Weak Areas Register ───────────────────────────────────────── -->
<?php if ($pfsAllFacultyComplete): ?>
    <?php if ($pfsWeakAreas !== []): ?>
    <div class="box-title pps-section-title">
        <h2>Weak Areas Register</h2>
        <span><?= e((string) $pfsWeakCount) ?> identified area<?= $pfsWeakCount !== 1 ? 's' : '' ?></span>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Faculty</th>
                <th>Program</th>
                <th>Weak Category</th>
                <th>Score</th>
                <th>Form</th>
                <th>Recommended Seminar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pfsWeakAreas as $wa): ?>
                <tr>
                    <td data-label="Faculty"><strong><?= e($wa['facultyName']) ?></strong></td>
                    <td data-label="Program"><?= e($wa['program']) ?></td>
                    <td data-label="Weak Category"><?= e($wa['weakCategory']) ?></td>
                    <td data-label="Score"><strong><?= e($wa['averageScore']) ?></strong><small>/5</small></td>
                    <td data-label="Form"><?= e($wa['formTitle']) ?></td>
                    <td data-label="Seminar"><?= e($wa['seminar']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="notice info" style="margin-top:16px;">
        <p>All evaluations are complete. No weak areas were identified for this program.</p>
    </div>
    <?php endif; ?>
<?php else: ?>
<div class="notice info" style="margin-top:16px;">
    <p>Weak areas and recommendations will appear once <strong>all</strong> faculty evaluations in this program are completed.</p>
</div>
<?php endif; ?>
