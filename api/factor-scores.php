<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/teacher_data.php';

$user = current_user();
if ($user === null) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
    exit;
}

$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['admin_hr', 'vpaa', 'dean', 'program_head', 'teacher'], true)) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

$periodName = trim((string) ($_GET['period'] ?? ''));
$comparePeriod = trim((string) ($_GET['compare_period'] ?? ''));
$action = $_GET['action'] ?? 'scores';

$userId = (int) ($user['id'] ?? 0);

// Determine filter scope based on role
$scopeDepartments = [];

if ($role === 'vpaa') {
    $vpaaDepts = admin_all(
        'SELECT d.department_code, d.department_name
         FROM vpaa_departments vd
         JOIN departments d ON d.department_code = vd.department_code
         WHERE vd.vpaa_user_id = :user_id',
        ['user_id' => $userId]
    );
    foreach ($vpaaDepts as $dept) {
        $scopeDepartments[] = (string) ($dept['department_name'] ?? $dept['department_code'] ?? '');
    }
} elseif ($role === 'dean') {
    $deptRows = admin_all(
        'SELECT department_code, department_name FROM departments WHERE dean_user_id = :user_id AND is_active = 1',
        ['user_id' => $userId]
    );
    foreach ($deptRows as $deptRow) {
        $scopeDepartments = array_merge($scopeDepartments, admin_department_aliases($deptRow));
    }
} elseif ($role === 'program_head') {
    $programRows = admin_all(
        'SELECT p.program_code, d.department_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.program_head_user_id = :user_id AND p.is_active = 1',
        ['user_id' => $userId]
    );
    foreach ($programRows as $progRow) {
        $scopeDepartments = array_merge($scopeDepartments, admin_department_aliases($progRow));
    }
}
// admin_hr: no department filter (all departments)

header('Content-Type: application/json');

if ($action === 'periods') {
    $periodsData = admin_all(
        "SELECT id, period_name, school_year, semester, status,
                date_start, date_end,
                CASE WHEN status = 'open' THEN 1 ELSE 0 END AS is_open
         FROM appraisal_periods
         ORDER BY date_start DESC, id DESC"
    );
    echo json_encode([
        'ok' => true,
        'data' => $periodsData,
        'selectedPeriod' => $periodName,
    ]);
    exit;
}

if ($action === 'scores') {
    // Enrich periods: also get available period names for the selector
    $allPeriods = admin_periods();
    $periodNames = array_values(array_unique(array_filter(array_map(
        fn (array $p): string => (string) ($p['period_name'] ?? ''),
        $allPeriods
    ))));

    // Get current scores
    $currentScores = teacher_factor_scores_aggregate($periodName ?: null, $scopeDepartments !== [] ? $scopeDepartments : null);

    // Get comparison scores
    $previousScores = [];
    $previousLabel = '';
    if ($comparePeriod !== '' && $comparePeriod !== $periodName) {
        $rawPrev = teacher_factor_scores_aggregate($comparePeriod, $scopeDepartments !== [] ? $scopeDepartments : null);
        if (!empty($rawPrev)) {
            $previousScores = $rawPrev;
            $previousLabel = $comparePeriod;
        }
    }

    // Default to latest period if none selected
    $effectivePeriod = $periodName;
    if ($effectivePeriod === '' && !empty($currentScores)) {
        // scores were computed without period filter
    } elseif ($effectivePeriod === '' && !empty($periodNames)) {
        // Try the latest period
        $latestName = end($periodNames);
        if ($latestName) {
            $effectivePeriod = $latestName;
            $currentScores = teacher_factor_scores_aggregate($effectivePeriod, $scopeDepartments !== [] ? $scopeDepartments : null);
        }
    }

    echo json_encode([
        'ok' => true,
        'periods' => $periodNames,
        'selectedPeriod' => $effectivePeriod ?: 'All Periods',
        'currentScores' => $currentScores,
        'previousScores' => $previousScores,
        'previousLabel' => $previousLabel,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
