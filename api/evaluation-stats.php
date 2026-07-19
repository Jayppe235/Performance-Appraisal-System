<?php
/**
 * Real-time Evaluation Statistics API
 * Returns deduplicated, accurate counts of completed evaluations
 * for dashboards, charts, and report cards.
 *
 * GET /api/evaluation-stats.php?role=admin|dean|program_head|faculty
 * Optional: &department=xxx&program=xxx&period_id=xxx
 *
 * All counts are based on officially completed (status='submitted') peer_assignments
 * and completed category results (status='completed').
 * No duplicate counting. Every unique assignment = 1 count.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/http.php';

header('Content-Type: application/json; charset=utf-8');
allow_local_dev_cors(['GET', 'OPTIONS']);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    admin_ensure_archive_schema();

    $role = (string) ($_GET['role'] ?? 'admin');
    $department = (string) ($_GET['department'] ?? '');
    $program = (string) ($_GET['program'] ?? '');
    $periodId = (string) ($_GET['period_id'] ?? '');
    $user = current_user();
    $userId = $user !== null ? (int) ($user['id'] ?? 0) : 0;

    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $periodName = $selectedPeriod !== null ? trim((string) ($selectedPeriod['period_name'] ?? '')) : '';

    $data = match ($role) {
        'admin' => eval_stats_admin($periodName),
        'dean' => eval_stats_dean($userId, $department, $periodName),
        'program_head' => eval_stats_program_head($userId, $program, $department, $periodName),
        'vpaa' => eval_stats_vpaa($userId, $periodName),
        'faculty' => eval_stats_faculty($userId, $periodName),
        default => eval_stats_admin($periodName),
    };

    echo json_encode([
        'ok' => true,
        'data' => $data,
        'timestamp' => time(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/**
 * Count helper with consistent error handling.
 */
function eval_stat_count(string $sql, array $params = []): int
{
    return admin_count($sql, $params);
}

/**
 * Admin stats — all departments, all programs, all faculty.
 */
function eval_stats_admin(string $periodName): array
{
    $periodWhere = 'WHERE COALESCE(pa.is_archived, 0) = 0';
    $params = [];
    if ($periodName !== '') {
        $periodWhere .= ' AND pa.cycle_name = :period_name';
        $params['period_name'] = $periodName;
    }

    // Core counts — deduplicated by unique evaluatee_faculty_id
    $totalAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         $periodWhere AND COALESCE(f.is_archived, 0) = 0",
        $params
    );
    $completedAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         $periodWhere AND COALESCE(f.is_archived, 0) = 0 AND pa.status = 'submitted'",
        $params
    );
    $pendingAssignments = $totalAssignments - $completedAssignments;

    // Faculty with completed evaluations (unique faculty who have at least 1 completed eval)
    $facultyWithCompleted = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.evaluatee_faculty_id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         $periodWhere AND COALESCE(f.is_archived, 0) = 0 AND pa.status = 'submitted'",
        $params
    );

    // Total active faculty
    $totalFaculty = eval_stat_count(
        "SELECT COUNT(*) FROM faculty WHERE COALESCE(is_archived, 0) = 0"
    );

    $completionRate = $totalAssignments > 0
        ? round(($completedAssignments / $totalAssignments) * 100)
        : 0;

    $facultyCompletionRate = $totalFaculty > 0
        ? round(($facultyWithCompleted / $totalFaculty) * 100)
        : 0;

    // Per-program breakdown
    $perProgram = admin_all(
        "SELECT
            COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS program_code,
            COUNT(DISTINCT pa.evaluatee_faculty_id) AS total_faculty,
            SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed_evals,
            COUNT(DISTINCT pa.id) AS total_evals
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         " . ($periodName !== '' ? "AND pa.cycle_name = :period_name" : "") . "
         GROUP BY f.program_code
         ORDER BY program_code",
        $periodName !== '' ? ['period_name' => $periodName] : []
    );

    return [
        'totalFaculty' => $totalFaculty,
        'facultyWithCompleted' => $facultyWithCompleted,
        'facultyCompletionRate' => $facultyCompletionRate,
        'totalAssignments' => $totalAssignments,
        'completedAssignments' => $completedAssignments,
        'pendingAssignments' => $pendingAssignments,
        'completionRate' => $completionRate,
        'perProgram' => array_map(fn(array $row): array => [
            'programCode' => (string) ($row['program_code'] ?? ''),
            'totalFaculty' => (int) ($row['total_faculty'] ?? 0),
            'completedEvals' => (int) ($row['completed_evals'] ?? 0),
            'totalEvals' => (int) ($row['total_evals'] ?? 0),
        ], $perProgram),
    ];
}

/**
 * Dean stats — faculty in the dean's departments.
 */
function eval_stats_dean(int $deanUserId, string $department, string $periodName): array
{
    require_once __DIR__ . '/../includes/dean_data.php';

    $departmentAliases = [];

    if ($deanUserId > 0) {
        $departments = dean_departments($deanUserId);
        foreach ($departments as $dept) {
            $aliases = admin_matching_department_aliases((string) $dept);
            $departmentAliases = array_merge($departmentAliases, $aliases !== [] ? $aliases : [(string) $dept]);
        }
    }

    if ($departmentAliases === [] && $department !== '') {
        $departmentAliases = admin_matching_department_aliases($department);
        if ($departmentAliases === []) {
            $departmentAliases = [$department];
        }
    }

    if ($departmentAliases === []) {
        return [
            'totalFaculty' => 0,
            'facultyWithCompleted' => 0,
            'facultyCompletionRate' => 0,
            'totalAssignments' => 0,
            'completedAssignments' => 0,
            'pendingAssignments' => 0,
            'completionRate' => 0,
            'perProgram' => [],
        ];
    }

    $departmentAliases = array_values(array_unique(array_filter($departmentAliases)));
    $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));

    // Total faculty in these departments
    $totalFaculty = eval_stat_count(
        "SELECT COUNT(*) FROM faculty WHERE department IN ($placeholders) AND COALESCE(is_archived, 0) = 0",
        $departmentAliases
    );

    // Faculty with at least 1 completed evaluation
    $periodCondition = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $periodParams = $periodName !== '' ? [$periodName] : [];

    $facultyWithCompleted = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.evaluatee_faculty_id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders)
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND pa.status = 'submitted'
           $periodCondition",
        $periodName !== ''
            ? array_merge($departmentAliases, $periodParams)
            : $departmentAliases
    );

    $totalAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders)
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           $periodCondition",
        $periodName !== ''
            ? array_merge($departmentAliases, $periodParams)
            : $departmentAliases
    );

    $completedAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders)
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND pa.status = 'submitted'
           $periodCondition",
        $periodName !== ''
            ? array_merge($departmentAliases, $periodParams)
            : $departmentAliases
    );

    $pendingAssignments = $totalAssignments - $completedAssignments;
    $completionRate = $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100) : 0;
    $facultyCompletionRate = $totalFaculty > 0 ? round(($facultyWithCompleted / $totalFaculty) * 100) : 0;

    return [
        'totalFaculty' => $totalFaculty,
        'facultyWithCompleted' => $facultyWithCompleted,
        'facultyCompletionRate' => $facultyCompletionRate,
        'totalAssignments' => $totalAssignments,
        'completedAssignments' => $completedAssignments,
        'pendingAssignments' => $pendingAssignments,
        'completionRate' => $completionRate,
        'perProgram' => [],
    ];
}

/**
 * Program Head stats.
 */
function eval_stats_program_head(int $userId, string $program, string $department, string $periodName): array
{
    $params = [];
    $paramVals = [];

    if ($program !== '') {
        $where = '(f.program_code = ? OR u.program = ?)';
        $paramVals = [$program, $program];
    } elseif ($department !== '') {
        $aliases = admin_matching_department_aliases($department);
        if ($aliases === []) { $aliases = [$department]; }
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $where = "f.department IN ($placeholders)";
        $paramVals = $aliases;
    } else {
        // Use the user's own data
        $user = admin_one('SELECT program, department FROM users WHERE id = ?', [$userId]);
        if ($user !== null && trim((string) ($user['program'] ?? '')) !== '') {
            $program = trim((string) $user['program']);
            $where = '(f.program_code = ? OR u.program = ?)';
            $paramVals = [$program, $program];
        } elseif ($user !== null && trim((string) ($user['department'] ?? '')) !== '') {
            $department = trim((string) $user['department']);
            $aliases = admin_matching_department_aliases($department);
            if ($aliases === []) { $aliases = [$department]; }
            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            $where = "f.department IN ($placeholders)";
            $paramVals = $aliases;
        } else {
            return [
                'totalFaculty' => 0, 'facultyWithCompleted' => 0,
                'facultyCompletionRate' => 0, 'totalAssignments' => 0,
                'completedAssignments' => 0, 'pendingAssignments' => 0,
                'completionRate' => 0, 'perProgram' => [],
            ];
        }
    }

    if ($periodName !== '') {
        $where .= ' AND pa.cycle_name = ?';
        $paramVals[] = $periodName;
    }

    $totalFaculty = eval_stat_count(
        "SELECT COUNT(DISTINCT f.id)
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where AND f.is_active = 1 AND COALESCE(f.is_archived, 0) = 0",
        $paramVals
    );

    $facultyWithCompleted = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.evaluatee_faculty_id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'",
        $paramVals
    );

    $completedAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'",
        $paramVals
    );

    $totalAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0",
        $paramVals
    );

    $pendingAssignments = $totalAssignments - $completedAssignments;
    $completionRate = $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100) : 0;
    $facultyCompletionRate = $totalFaculty > 0 ? round(($facultyWithCompleted / $totalFaculty) * 100) : 0;

    return [
        'totalFaculty' => $totalFaculty,
        'facultyWithCompleted' => $facultyWithCompleted,
        'facultyCompletionRate' => $facultyCompletionRate,
        'totalAssignments' => $totalAssignments,
        'completedAssignments' => $completedAssignments,
        'pendingAssignments' => $pendingAssignments,
        'completionRate' => $completionRate,
        'perProgram' => [],
    ];
}

/**
 * VPAA stats.
 */
function eval_stats_vpaa(int $vpaaUserId, string $periodName): array
{
    require_once __DIR__ . '/../includes/vpaa_data.php';

    $assignedDepartments = $vpaaUserId > 0 ? vpaa_departments($vpaaUserId) : [];

    if ($assignedDepartments === []) {
        return [
            'totalFaculty' => 0, 'facultyWithCompleted' => 0,
            'facultyCompletionRate' => 0, 'totalAssignments' => 0,
            'completedAssignments' => 0, 'pendingAssignments' => 0,
            'completionRate' => 0, 'perProgram' => [],
        ];
    }

    $departmentAliases = [];
    foreach ($assignedDepartments as $dept) {
        $aliases = admin_matching_department_aliases((string) $dept);
        $departmentAliases = array_merge($departmentAliases, $aliases !== [] ? $aliases : [(string) $dept]);
    }
    $departmentAliases = array_values(array_unique(array_filter($departmentAliases)));

    if ($departmentAliases === []) {
        $departmentAliases = $assignedDepartments;
    }

    $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));
    $periodCondition = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $periodParam = $periodName !== '' ? [$periodName] : [];

    $totalFaculty = eval_stat_count(
        "SELECT COUNT(*) FROM faculty WHERE department IN ($placeholders) AND COALESCE(is_archived, 0) = 0",
        $departmentAliases
    );

    $facultyWithCompleted = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.evaluatee_faculty_id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'$periodCondition",
        $periodName !== '' ? array_merge($departmentAliases, $periodParam) : $departmentAliases
    );

    $completedAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'$periodCondition",
        $periodName !== '' ? array_merge($departmentAliases, $periodParam) : $departmentAliases
    );

    $totalAssignments = eval_stat_count(
        "SELECT COUNT(DISTINCT pa.id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0$periodCondition",
        $periodName !== '' ? array_merge($departmentAliases, $periodParam) : $departmentAliases
    );

    $pendingAssignments = $totalAssignments - $completedAssignments;
    $completionRate = $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100) : 0;
    $facultyCompletionRate = $totalFaculty > 0 ? round(($facultyWithCompleted / $totalFaculty) * 100) : 0;

    return [
        'totalFaculty' => $totalFaculty,
        'facultyWithCompleted' => $facultyWithCompleted,
        'facultyCompletionRate' => $facultyCompletionRate,
        'totalAssignments' => $totalAssignments,
        'completedAssignments' => $completedAssignments,
        'pendingAssignments' => $pendingAssignments,
        'completionRate' => $completionRate,
        'perProgram' => [],
    ];
}

/**
 * Faculty stats.
 */
function eval_stats_faculty(int $facultyUserId, string $periodName): array
{
    if ($facultyUserId <= 0) {
        return [
            'totalFaculty' => 0, 'facultyWithCompleted' => 0,
            'facultyCompletionRate' => 0, 'totalAssignments' => 0,
            'completedAssignments' => 0, 'pendingAssignments' => 0,
            'completionRate' => 0,
        ];
    }

    $periodCondition = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $periodParam = $periodName !== '' ? [$periodName] : [];

    $totalAssignments = eval_stat_count(
         "SELECT COUNT(DISTINCT pa.id) FROM peer_assignments pa
         WHERE pa.evaluator_user_id = ? AND COALESCE(pa.is_archived, 0) = 0$periodCondition",
        $periodName !== '' ? [$facultyUserId, $periodName] : [$facultyUserId]
    );

    $completedAssignments = eval_stat_count(
         "SELECT COUNT(DISTINCT pa.id) FROM peer_assignments pa
         WHERE pa.evaluator_user_id = ? AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'$periodCondition",
        $periodName !== '' ? [$facultyUserId, $periodName] : [$facultyUserId]
    );

    $pendingAssignments = $totalAssignments - $completedAssignments;
    $completionRate = $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100) : 0;

    return [
        'totalFaculty' => 0,
        'facultyWithCompleted' => 0,
        'facultyCompletionRate' => 0,
        'totalAssignments' => $totalAssignments,
        'completedAssignments' => $completedAssignments,
        'pendingAssignments' => $pendingAssignments,
        'completionRate' => $completionRate,
    ];
}
