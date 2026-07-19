<?php
declare(strict_types=1);

/**
 * Evaluation Comparison API
 * Provides category-level period-over-period comparison for faculty evaluations.
 *
 * GET /api/evaluation-comparison.php
 *   ?faculty_id=123          — Compare last two periods for a specific faculty
 *   &scope=department        — Compare programs within a scope (admin/dean/vpaa)
 *   &department_code=CAS     — Filter by department
 *   &program_code=BSIT       — Filter by program
 *   &period_a=Period Name    — Specific first period (optional, default: second-to-last)
 *   &period_b=Period Name    — Specific second period (optional, default: latest)
 *
 * Returns per-category scores for each period with deltas, ranked by improvement.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedDevOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5175',
];

if (in_array($origin, $allowedDevOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ── Helper: get category scores per period for a faculty member ──────────
function comparison_category_scores(int $facultyId): array
{
    admin_ensure_archive_schema();
    $results = array_merge(
        admin_all(
            "SELECT r.evaluation_period AS period_name, c.title AS category_title, 'Form A' AS form_type,
                    ROUND(AVG(r.average_rating), 2) AS average_rating,
                    COUNT(*) AS submission_count
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id = :fac_a AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
             GROUP BY r.evaluation_period, c.title",
            ['fac_a' => $facultyId]
        ),
        admin_all(
            "SELECT r.evaluation_period AS period_name, c.title AS category_title, 'Form B' AS form_type,
                    ROUND(AVG(r.average_rating), 2) AS average_rating,
                    COUNT(*) AS submission_count
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id = :fac_b AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
             GROUP BY r.evaluation_period, c.title",
            ['fac_b' => $facultyId]
        )
    );

    // Group by period → category
    $grouped = [];
    foreach ($results as $row) {
        $period = (string) ($row['period_name'] ?? 'Unknown Period');
        $category = (string) ($row['category_title'] ?? 'Uncategorized');
        $formType = (string) ($row['form_type'] ?? '');
        if (!isset($grouped[$period])) {
            $grouped[$period] = ['period_name' => $period, 'categories' => []];
        }
        $grouped[$period]['categories'][$category] = [
            'category' => $category,
            'formType' => $formType,
            'averageScore' => (float) ($row['average_rating'] ?? 0),
            'submissionCount' => (int) ($row['submission_count'] ?? 0),
        ];
    }

    return $grouped;
}

// ── Helper: get program/department aggregated category scores per period ─
function comparison_scope_category_scores(string $scope = '', string $departmentCode = '', string $programCode = ''): array
{
    admin_ensure_archive_schema();
    $filters = [];
    $params = [];

    if ($programCode !== '') {
        $filters[] = 'f.program_code = :program_code';
        $params['program_code'] = $programCode;
    } elseif ($departmentCode !== '') {
        $aliases = admin_matching_department_aliases($departmentCode);
        if ($aliases === []) {
            $aliases = [$departmentCode];
        }
        $parts = [];
        foreach ($aliases as $i => $alias) {
            $key = 'dept_' . $i;
            $parts[] = 'f.department = :' . $key;
            $params[$key] = $alias;
        }
        $filters[] = '(' . implode(' OR ', $parts) . ')';
    } elseif ($scope !== '' && strtolower($scope) !== 'all') {
        $aliases = admin_matching_department_aliases($scope);
        if ($aliases !== []) {
            $parts = [];
            foreach ($aliases as $i => $alias) {
                $key = 'scope_' . $i;
                $parts[] = 'f.department = :' . $key;
                $params[$key] = $alias;
            }
            $filters[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    $where = $filters !== [] ? 'AND ' . implode(' AND ', $filters) : '';

    $results = array_merge(
        admin_all(
            "SELECT r.evaluation_period AS period_name, c.title AS category_title, 'Form A' AS form_type,
                    ROUND(AVG(r.average_rating), 2) AS average_rating,
                    COUNT(DISTINCT r.evaluatee_faculty_id) AS faculty_count,
                    COUNT(*) AS submission_count
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(f.is_archived, 0) = 0 {$where}
             GROUP BY r.evaluation_period, c.title",
            $params
        ),
        admin_all(
            "SELECT r.evaluation_period AS period_name, c.title AS category_title, 'Form B' AS form_type,
                    ROUND(AVG(r.average_rating), 2) AS average_rating,
                    COUNT(DISTINCT r.evaluatee_faculty_id) AS faculty_count,
                    COUNT(*) AS submission_count
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(f.is_archived, 0) = 0 {$where}
             GROUP BY r.evaluation_period, c.title",
            $params
        )
    );

    $grouped = [];
    foreach ($results as $row) {
        $period = (string) ($row['period_name'] ?? 'Unknown Period');
        $category = (string) ($row['category_title'] ?? 'Uncategorized');
        if (!isset($grouped[$period])) {
            $grouped[$period] = ['period_name' => $period, 'categories' => []];
        }
        $grouped[$period]['categories'][$category] = [
            'category' => $category,
            'formType' => (string) ($row['form_type'] ?? ''),
            'averageScore' => (float) ($row['average_rating'] ?? 0),
            'facultyCount' => (int) ($row['faculty_count'] ?? 0),
            'submissionCount' => (int) ($row['submission_count'] ?? 0),
        ];
    }

    return $grouped;
}

// ── Main handler ─────────────────────────────────────────────────────────
try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_faculty_program_schema();

    $facultyId = (int) ($_GET['faculty_id'] ?? 0);
    $scope = trim((string) ($_GET['scope'] ?? ''));
    $departmentCode = trim((string) ($_GET['department_code'] ?? ''));
    $programCode = trim((string) ($_GET['program_code'] ?? ''));
    $periodARequested = trim((string) ($_GET['period_a'] ?? ''));
    $periodBRequested = trim((string) ($_GET['period_b'] ?? ''));

    // ── Load comparison data ──────────────────────────────────────────
    if ($facultyId > 0) {
        $grouped = comparison_category_scores($facultyId);

        // Get faculty info
        $faculty = admin_one(
            'SELECT id, full_name, department, program_code FROM faculty WHERE id = :id',
            ['id' => $facultyId]
        );
        $facultyInfo = $faculty !== null ? [
            'id' => (int) $faculty['id'],
            'name' => (string) ($faculty['full_name'] ?? ''),
            'department' => (string) ($faculty['department'] ?? ''),
            'program' => (string) ($faculty['program_code'] ?? ''),
        ] : null;
    } else {
        $grouped = comparison_scope_category_scores($scope, $departmentCode, $programCode);
        $facultyInfo = null;
    }

    // ── Sort periods chronologically ──────────────────────────────────
    $periodNames = array_keys($grouped);
    sort($periodNames);

    // ── Determine which two periods to compare ─────────────────────────
    $periodA = '';
    $periodB = '';

    if ($periodARequested !== '' && $periodBRequested !== '') {
        $periodA = $periodARequested;
        $periodB = $periodBRequested;
    } elseif ($periodBRequested !== '') {
        $periodB = $periodBRequested;
        $periodA = count($periodNames) >= 2 ? $periodNames[count($periodNames) - 2] : '';
    } elseif ($periodARequested !== '') {
        $periodA = $periodARequested;
        $periodB = count($periodNames) >= 1 ? $periodNames[count($periodNames) - 1] : '';
    } else {
        // Default: compare last two periods
        if (count($periodNames) >= 2) {
            $periodB = $periodNames[count($periodNames) - 1];
            $periodA = $periodNames[count($periodNames) - 2];
        } elseif (count($periodNames) === 1) {
            $periodB = $periodNames[0];
        }
    }

    // ── Build comparison data ─────────────────────────────────────────
    $categoriesAll = [];
    if ($periodA !== '' && isset($grouped[$periodA])) {
        foreach ($grouped[$periodA]['categories'] as $cat => $data) {
            $categoriesAll[$cat] = $data;
            $categoriesAll[$cat]['periodA'] = $data['averageScore'];
            $categoriesAll[$cat]['periodB'] = null;
            $categoriesAll[$cat]['change'] = null;
            $categoriesAll[$cat]['direction'] = 'new';
        }
    }
    if ($periodB !== '' && isset($grouped[$periodB])) {
        foreach ($grouped[$periodB]['categories'] as $cat => $data) {
            if (isset($categoriesAll[$cat])) {
                $categoriesAll[$cat]['periodB'] = $data['averageScore'];
                $categoriesAll[$cat]['change'] = round($data['averageScore'] - ($categoriesAll[$cat]['periodA'] ?? $data['averageScore']), 2);
                $categoriesAll[$cat]['direction'] = $categoriesAll[$cat]['change'] > 0 ? 'improved' : ($categoriesAll[$cat]['change'] < 0 ? 'declined' : 'stable');
                $categoriesAll[$cat]['formType'] = $data['formType'];
            } else {
                $categoriesAll[$cat] = $data;
                $categoriesAll[$cat]['periodA'] = null;
                $categoriesAll[$cat]['periodB'] = $data['averageScore'];
                $categoriesAll[$cat]['change'] = null;
                $categoriesAll[$cat]['direction'] = 'new';
            }
        }
    }

    // ── Sort: improved first, then declined, then stable, then new ────
    usort($categoriesAll, static function (array $a, array $b): int {
        $order = ['improved' => 0, 'stable' => 1, 'declined' => 2, 'new' => 3];
        $aOrder = $order[$a['direction']] ?? 4;
        $bOrder = $order[$b['direction']] ?? 4;
        if ($aOrder !== $bOrder) return $aOrder <=> $bOrder;
        // Within same direction, sort by magnitude of change
        $aChange = abs((float) ($a['change'] ?? 0));
        $bChange = abs((float) ($b['change'] ?? 0));
        return $bChange <=> $aChange;
    });

    // ── Compute overall scores per period ─────────────────────────────
    $overallA = null;
    $overallB = null;
    if ($periodA !== '' && isset($grouped[$periodA])) {
        $scores = array_column($grouped[$periodA]['categories'], 'averageScore');
        $overallA = $scores !== [] ? round(array_sum($scores) / count($scores), 2) : null;
    }
    if ($periodB !== '' && isset($grouped[$periodB])) {
        $scores = array_column($grouped[$periodB]['categories'], 'averageScore');
        $overallB = $scores !== [] ? round(array_sum($scores) / count($scores), 2) : null;
    }

    $overallChange = null;
    if ($overallA !== null && $overallB !== null) {
        $overallChange = round($overallB - $overallA, 2);
    }

    echo json_encode([
        'ok' => true,
        'faculty' => $facultyInfo,
        'comparison' => [
            'periodA' => $periodA,
            'periodB' => $periodB,
            'overallA' => $overallA,
            'overallB' => $overallB,
            'overallChange' => $overallChange,
            'overallDirection' => $overallChange !== null ? ($overallChange > 0 ? 'improved' : ($overallChange < 0 ? 'declined' : 'stable')) : null,
        ],
        'categories' => $categoriesAll,
        'summary' => [
            'improved' => count(array_filter($categoriesAll, fn($c) => $c['direction'] === 'improved')),
            'declined' => count(array_filter($categoriesAll, fn($c) => $c['direction'] === 'declined')),
            'stable' => count(array_filter($categoriesAll, fn($c) => $c['direction'] === 'stable')),
            'new' => count(array_filter($categoriesAll, fn($c) => $c['direction'] === 'new')),
            'totalCategories' => count($categoriesAll),
            'periodsAvailable' => $periodNames,
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
