<?php
/**
 * Real-time Dashboard API
 * Returns live statistics from the database for each user role.
 * GET /api/dashboard.php?role=admin|dean|program_head|faculty
 * Optional: &department=xxx&program=xxx&user_id=xxx
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/vpaa_data.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';
require_once __DIR__ . '/../includes/evaluation_consistency_sync.php';
require_once __DIR__ . '/../includes/http.php';

header('Content-Type: application/json');
allow_local_dev_cors(['GET', 'OPTIONS']);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function dashboard_payload(string $role, array $query = []): array
{
    admin_ensure_archive_schema();

    $department = admin_normalize_department_name((string) ($query['department'] ?? ''));
    $program = $query['program'] ?? '';
    $userId = $query['user_id'] ?? '';
    $selectedPeriod = dipascaf_selected_period_from_request($query, true);

    $db = db();
    dipascaf_sync_evaluation_consistency((string) ($selectedPeriod['period_name'] ?? ''));

    switch ($role) {
        case 'admin':
            return getAdminStats($db, $selectedPeriod);
        case 'dean':
            return getDeanStats($db, $department, $selectedPeriod);
        case 'program_head':
            return getProgramHeadStats($db, $program, $department, $selectedPeriod);
        case 'vpaa':
            return getVpaaStats($db, $selectedPeriod);
        case 'faculty':
            return getFacultyStats($db, $userId, $selectedPeriod);
        default:
            return getAdminStats($db, $selectedPeriod);
    }
}

if (!defined('DIPASCAF_DASHBOARD_LIBRARY')) {
try {
    $role = (string) ($_GET['role'] ?? 'admin');
    $data = dashboard_payload($role, $_GET);
    echo json_encode(['ok' => true, 'data' => $data, 'timestamp' => time()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
}

function getAdminStats(PDO $db, ?array $period = null): array {
    $periodName = trim((string) ($period['period_name'] ?? ''));
    $periodId = (int)($period['id'] ?? 0);
    dipascaf_ensure_period_participation_schema();
    $participationFilter = $periodId > 0
        ? " AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp WHERE epp.evaluation_period_id=:participation_period_id AND epp.participation_status='excluded' AND (epp.user_id=peer_assignments.evaluator_user_id OR epp.user_id=(SELECT fpx.user_id FROM faculty fpx WHERE fpx.id=peer_assignments.evaluatee_faculty_id LIMIT 1)))"
        : '';
    $peerPeriodWhere = $periodName !== ''
        ? " WHERE COALESCE(is_archived, 0) = 0 AND status <> 'not_required' AND cycle_name = :period_name{$participationFilter}"
        : " WHERE COALESCE(is_archived, 0) = 0 AND status <> 'not_required'";
    $peerPeriodAnd = $periodName !== '' ? " AND cycle_name = :period_name{$participationFilter}" : " AND status <> 'not_required'";
    $peerPeriodParams = $periodName !== '' ? ['period_name' => $periodName] : [];
    if ($periodId > 0) $peerPeriodParams['participation_period_id'] = $periodId;

    $totalUsers = $periodName !== ''
        ? dashboard_count(
            $db,
            "SELECT COUNT(DISTINCT user_id)
             FROM (
                 SELECT evaluator_user_id AS user_id
                 FROM peer_assignments
                 WHERE COALESCE(is_archived, 0) = 0 AND cycle_name = :period_name_a
                 UNION
                 SELECT f.user_id AS user_id
                 FROM peer_assignments pa
                 JOIN faculty f ON f.id = pa.evaluatee_faculty_id
                 WHERE COALESCE(pa.is_archived, 0) = 0 AND pa.cycle_name = :period_name_b AND f.user_id IS NOT NULL
             ) period_users",
            ['period_name_a' => $periodName, 'period_name_b' => $periodName]
        )
        : (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers = $periodName !== ''
        ? $totalUsers
        : (int) $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $facultyHasArchive = dashboard_column_exists($db, 'faculty', 'is_archived');
    $activeFacultyWhere = $facultyHasArchive ? 'WHERE COALESCE(is_archived, 0) = 0' : '';
    $activeFacultyCondition = $facultyHasArchive ? 'COALESCE(is_archived, 0) = 0 AND ' : '';
    $facultyCount = $periodName !== ''
        ? dashboard_count(
            $db,
            "SELECT COUNT(DISTINCT f.id)
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name",
            ['period_name' => $periodName]
        )
        : dashboard_count($db, "SELECT COUNT(*) FROM faculty $activeFacultyWhere");

    $evalTotal = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments{$peerPeriodWhere}", $peerPeriodParams);
    $evalPending = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending'{$peerPeriodAnd}", $peerPeriodParams);
    $evalCompleted = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'submitted'{$peerPeriodAnd}", $peerPeriodParams);

    $departments = $periodName !== ''
        ? dashboard_count(
            $db,
            "SELECT COUNT(DISTINCT NULLIF(TRIM(f.department), ''))
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name",
            ['period_name' => $periodName]
        )
        : (int) $db->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    $programs = $periodName !== ''
        ? dashboard_count(
            $db,
            "SELECT COUNT(DISTINCT NULLIF(TRIM(COALESCE(f.program_code, '')), ''))
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name",
            ['period_name' => $periodName]
        )
        : (int) $db->query("SELECT COUNT(*) FROM programs")->fetchColumn();
    $aiInsights = 0;
    $tableCheck = $db->query("SHOW TABLES LIKE 'ai_insights'");
    if ($tableCheck && $tableCheck->fetch()) {
      $aiInsights = (int) $db->query("SELECT COUNT(*) FROM ai_insights")->fetchColumn();
    }

    $completionRate = $evalTotal > 0 ? round(($evalCompleted / $evalTotal) * 100) : 0;

    $today = date('Y-m-d');
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $peerHasDeadline = dashboard_column_exists($db, 'peer_assignments', 'deadline');
    $peerOverdueCount = $peerHasDeadline
        ? dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending' AND deadline IS NOT NULL AND deadline < CURDATE(){$peerPeriodAnd}", $peerPeriodParams)
        : dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending' AND assigned_at < DATE_SUB(NOW(), INTERVAL 7 DAY){$peerPeriodAnd}", $peerPeriodParams);
    $peerDueSoonCount = $peerHasDeadline
        ? dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending' AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY){$peerPeriodAnd}", $peerPeriodParams)
        : dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending' AND assigned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY){$peerPeriodAnd}", $peerPeriodParams);

    $evaluationPeriodFilter = dashboard_table_exists($db, 'evaluations') && dashboard_column_exists($db, 'evaluations', 'evaluation_period') && $periodName !== ''
        ? ' AND evaluation_period = :period_name'
        : '';
    $evaluationPeriodParams = $evaluationPeriodFilter !== '' ? ['period_name' => $periodName] : [];
    $evaluationOverdueCount = dashboard_table_exists($db, 'evaluations')
        ? dashboard_count($db, "SELECT COUNT(*) FROM evaluations WHERE status != 'completed' AND deadline < CURDATE(){$evaluationPeriodFilter}", $evaluationPeriodParams)
        : 0;
    $evaluationDueSoonCount = dashboard_table_exists($db, 'evaluations')
        ? dashboard_count($db, "SELECT COUNT(*) FROM evaluations WHERE status != 'completed' AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY){$evaluationPeriodFilter}", $evaluationPeriodParams)
        : 0;

    $noDeanCount = dashboard_count($db, "SELECT COUNT(*) FROM departments WHERE dean_user_id IS NULL");
    $noHeadCount = dashboard_count($db, "SELECT COUNT(*) FROM programs WHERE program_head_user_id IS NULL");
    $inactiveUsers = dashboard_count($db, "SELECT COUNT(*) FROM users WHERE is_active = 0");
    $archivedFaculty = $facultyHasArchive
        ? dashboard_count($db, "SELECT COUNT(*) FROM faculty WHERE COALESCE(is_archived, 0) = 1")
        : 0;
    $lowProgressFaculty = dashboard_column_exists($db, 'faculty', 'progress_percent')
        ? dashboard_count($db, "SELECT COUNT(*) FROM faculty WHERE {$activeFacultyCondition}COALESCE(progress_percent, 0) < 50")
        : 0;

    return [
        'metrics' => [
            ['label' => 'Total Users', 'value' => $totalUsers],
            ['label' => 'Pending Evaluations', 'value' => $evalPending],
            ['label' => 'Faculty Profiles', 'value' => $facultyCount],
            ['label' => 'Completed Evaluations', 'value' => $evalCompleted],
            ['label' => 'Departments', 'value' => $departments],
            ['label' => 'Programs', 'value' => $programs],
        ],
        'actionCenter' => [
            ['label' => 'Overdue evaluations', 'count' => $evaluationOverdueCount + $peerOverdueCount, 'detail' => 'Past deadline and still open', 'href' => '/admin/assignments', 'cta' => 'Review', 'tone' => 'danger', 'initial' => 'O'],
            ['label' => 'Due this week', 'count' => $evaluationDueSoonCount + $peerDueSoonCount, 'detail' => 'Deadlines within 7 days', 'href' => '/admin/assignments', 'cta' => 'Check', 'tone' => 'warning', 'initial' => 'D'],
            ['label' => 'Pending assignments', 'count' => $evalPending, 'detail' => 'Awaiting evaluator submission', 'href' => '/admin/assignments', 'cta' => 'Open', 'tone' => 'info', 'initial' => 'P'],
            ['label' => 'Departments need dean', 'count' => $noDeanCount, 'detail' => 'Unassigned department leadership', 'href' => '/admin/people', 'cta' => 'Assign', 'tone' => 'warning', 'initial' => 'D'],
            ['label' => 'Programs need head', 'count' => $noHeadCount, 'detail' => 'Program head not fully assigned', 'href' => '/admin/people', 'cta' => 'Manage', 'tone' => 'info', 'initial' => 'H'],
            ['label' => 'Faculty below 50%', 'count' => $lowProgressFaculty, 'detail' => 'Progress records need attention', 'href' => '/admin/people', 'cta' => 'View', 'tone' => 'danger', 'initial' => 'F'],
            ['label' => 'Archived records', 'count' => $inactiveUsers + $archivedFaculty, 'detail' => 'Inactive users and archived faculty', 'href' => '/admin/settings', 'cta' => 'View', 'tone' => 'info', 'initial' => 'A'],
        ],
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
}

function dashboard_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function dashboard_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function dashboard_count(PDO $db, string $sql, array $params = []): int
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function getDeanStats(PDO $db, string $department, ?array $period = null): array {
    // Use the session user to look up dean's assigned departments from DB
    require_once __DIR__ . '/../includes/dean_data.php';
    $user = current_user();
    $deanUserId = $user !== null ? (int) ($user['id'] ?? 0) : 0;
    $dbDepartments = $deanUserId > 0 ? dean_departments($deanUserId) : [];

    if ($dbDepartments === []) {
        // Fallback to the department string passed from frontend
        if (empty($department)) {
            $department = '';
        }
        $departmentAliases = admin_matching_department_aliases($department);
        if ($departmentAliases === []) {
            $departmentAliases = [$department];
        }
    } else {
        $departmentAliases = [];
        foreach ($dbDepartments as $dept) {
            $aliases = admin_matching_department_aliases($dept);
            $departmentAliases = array_merge($departmentAliases, $aliases !== [] ? $aliases : [$dept]);
        }
        $departmentAliases = array_values(array_unique(array_filter($departmentAliases)));
    }

    if ($departmentAliases === []) {
        return ['metrics' => []];
    }

    $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));
    $periodName = trim((string) ($period['period_name'] ?? ''));
    $periodSql = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $periodParams = $periodName !== '' ? [$periodName] : [];

    // For a selected period, count only faculty who have assignments in that cycle.
    if ($periodName !== '') {
        $facultyStmt = $db->prepare("
            SELECT COUNT(DISTINCT f.id)
            FROM peer_assignments pa
            JOIN faculty f ON pa.evaluatee_faculty_id = f.id
            WHERE f.department IN ($placeholders)
              AND COALESCE(f.is_archived, 0) = 0
              AND COALESCE(pa.is_archived, 0) = 0
              $periodSql
        ");
        $facultyStmt->execute(array_merge($departmentAliases, $periodParams));
    } else {
        $facultyStmt = $db->prepare("SELECT COUNT(*) FROM faculty WHERE department IN ($placeholders) AND COALESCE(is_archived, 0) = 0");
        $facultyStmt->execute($departmentAliases);
    }
    $facultyCount = (int) $facultyStmt->fetchColumn();

    // Count peer assignments where faculty belong to the dean's departments
    $pendingStmt = $db->prepare("
        SELECT COUNT(*) FROM peer_assignments pa
        JOIN faculty f ON pa.evaluatee_faculty_id = f.id
        WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'pending'{$periodSql}
    ");
    $pendingStmt->execute(array_merge($departmentAliases, $periodParams));
    $pendingCount = (int) $pendingStmt->fetchColumn();

    $submittedStmt = $db->prepare("
        SELECT COUNT(*) FROM peer_assignments pa
        JOIN faculty f ON pa.evaluatee_faculty_id = f.id
        WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'{$periodSql}
    ");
    $submittedStmt->execute(array_merge($departmentAliases, $periodParams));
    $submittedCount = (int) $submittedStmt->fetchColumn();

    $totalEvals = $pendingCount + $submittedCount;
    $completionRate = $totalEvals > 0 ? round(($submittedCount / $totalEvals) * 100) . '%' : '0%';

    $aiInsights = 0;
    $tableCheck = $db->query("SHOW TABLES LIKE 'ai_insights'");
    if ($tableCheck && $tableCheck->fetch()) {
            $aiStmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM ai_insights i
                    JOIN faculty f ON f.id = i.faculty_id
                    LEFT JOIN users u ON u.id = f.user_id
                    WHERE COALESCE(f.is_archived, 0) = 0 AND (u.department IN ($placeholders) OR f.department IN ($placeholders))
            ");
            $aiStmt->execute(array_merge($departmentAliases, $departmentAliases));
      $aiInsights = (int) $aiStmt->fetchColumn();
    }

    $trainingPlans = 0;
    $tableCheck = $db->query("SHOW TABLES LIKE 'training_plans'");
    if ($tableCheck && $tableCheck->fetch()) {
            try {
                $columnCheck = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training_plans' AND COLUMN_NAME = 'department'");
                $columnCheck->execute();
                if ((int) $columnCheck->fetchColumn() > 0) {
                    $trainingStmt = $db->prepare("SELECT COUNT(*) FROM training_plans WHERE department IN ($placeholders)");
                    $trainingStmt->execute($departmentAliases);
                    $trainingPlans = (int) $trainingStmt->fetchColumn();
                }
            } catch (Exception) {
                $trainingPlans = 0;
            }
    }

    $overdueCount = dashboard_column_exists($db, 'peer_assignments', 'deadline')
        ? dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON pa.evaluatee_faculty_id = f.id
             WHERE f.department IN ($placeholders)
               AND COALESCE(f.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status = 'pending'
               AND pa.deadline IS NOT NULL
               AND pa.deadline < CURDATE()
               {$periodSql}",
            array_merge($departmentAliases, $periodParams)
        )
        : 0;

    return [
        'metrics' => [
            ['label' => 'Faculty Under Review', 'value' => $facultyCount],
            ['label' => 'Pending Reviews', 'value' => $pendingCount],
            ['label' => 'Submitted Reviews', 'value' => $submittedCount],
            ['label' => 'Completion Rate', 'value' => $completionRate],
            ['label' => 'AI Insights', 'value' => $aiInsights],
            ['label' => 'Training Plans', 'value' => $trainingPlans],
        ],
        'actionCenter' => [
            ['label' => 'Pending reviews', 'count' => $pendingCount, 'detail' => 'Evaluations still awaiting submission', 'href' => '/dean/evaluate', 'cta' => 'Open', 'tone' => 'warning', 'initial' => 'P'],
            ['label' => 'Overdue reviews', 'count' => $overdueCount, 'detail' => 'Pending evaluations past deadline', 'href' => '/dean/evaluate', 'cta' => 'Review', 'tone' => 'danger', 'initial' => 'O'],
            ['label' => 'AI insights', 'count' => $aiInsights, 'detail' => 'Department weak-area insights', 'href' => '/dean/summary', 'cta' => 'Analyze', 'tone' => 'info', 'initial' => 'A'],
            ['label' => 'Training plans', 'count' => $trainingPlans, 'detail' => 'Development plans and interventions', 'href' => '/dean/summary', 'cta' => 'View', 'tone' => 'success', 'initial' => 'T'],
        ],
    ];
}

function getProgramHeadStats(PDO $db, string $program, string $department, ?array $period = null): array {
    $periodName = trim((string) ($period['period_name'] ?? ''));
    $periodSql = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $periodParams = $periodName !== '' ? [$periodName] : [];
    $activeProgramCount = 1;
    $sessionUser = current_user();
    $sessionProgramCodes = [];

    if (($sessionUser['role'] ?? '') === 'program_head') {
        $programRows = admin_all(
            'SELECT program_code
             FROM programs
             WHERE program_head_user_id = :program_head_user_id AND is_active = 1',
            ['program_head_user_id' => (int) ($sessionUser['id'] ?? 0)]
        );
        foreach ($programRows as $row) {
            $code = strtoupper(trim((string) ($row['program_code'] ?? '')));
            if ($code !== '') {
                $sessionProgramCodes[] = $code;
            }
        }

        $fallbackCodes = dipascaf_normalized_program_codes($sessionUser['program'] ?? '');
        $sessionProgramCodes = array_values(array_unique(array_merge($sessionProgramCodes, $fallbackCodes)));
    }

    if ($sessionProgramCodes !== [] || !empty($program)) {
        $programCodes = $sessionProgramCodes !== [] ? $sessionProgramCodes : dipascaf_normalized_program_codes($program);
        if ($programCodes === []) {
            $programCodes = [strtoupper(trim($program))];
        }
        $activeProgramCount = count($programCodes);
        $programPlaceholders = implode(',', array_fill(0, count($programCodes), '?'));
        $programWhere = "UPPER(TRIM(COALESCE(f.program_code, u.program, ''))) IN ($programPlaceholders)";

        if ($periodName !== '') {
            $facultyStmt = $db->prepare("
                SELECT COUNT(DISTINCT f.id)
                FROM peer_assignments pa
                JOIN faculty f ON pa.evaluatee_faculty_id = f.id
                LEFT JOIN users u ON f.user_id = u.id
                WHERE {$programWhere}
                  AND COALESCE(f.is_archived, 0) = 0
                  AND COALESCE(pa.is_archived, 0) = 0
                  {$periodSql}
            ");
            $facultyStmt->execute(array_merge($programCodes, $periodParams));
        } else {
            $facultyStmt = $db->prepare("
                SELECT COUNT(DISTINCT f.id)
                FROM faculty f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE {$programWhere}
                  AND COALESCE(f.is_archived, 0) = 0
            ");
            $facultyStmt->execute($programCodes);
        }
        $facultyCount = (int) $facultyStmt->fetchColumn();

        $pendingStmt = $db->prepare("
            SELECT COUNT(*) FROM peer_assignments pa
            JOIN faculty f ON pa.evaluatee_faculty_id = f.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE {$programWhere} AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'pending'{$periodSql}
        ");
        $pendingStmt->execute(array_merge($programCodes, $periodParams));
        $pendingCount = (int) $pendingStmt->fetchColumn();

        $submittedStmt = $db->prepare("
            SELECT COUNT(*) FROM peer_assignments pa
            JOIN faculty f ON pa.evaluatee_faculty_id = f.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE {$programWhere} AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'{$periodSql}
        ");
        $submittedStmt->execute(array_merge($programCodes, $periodParams));
        $submittedCount = (int) $submittedStmt->fetchColumn();
    } elseif (!empty($department)) {
        $departmentAliases = admin_matching_department_aliases($department);
        if ($departmentAliases === []) {
            $departmentAliases = [$department];
        }
        $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));

        if ($periodName !== '') {
            $facultyStmt = $db->prepare("
                SELECT COUNT(DISTINCT f.id)
                FROM peer_assignments pa
                JOIN faculty f ON pa.evaluatee_faculty_id = f.id
                LEFT JOIN users u ON f.user_id = u.id
                WHERE (f.department IN ($placeholders) OR u.department IN ($placeholders))
                  AND COALESCE(f.is_archived, 0) = 0
                  AND COALESCE(pa.is_archived, 0) = 0
                  {$periodSql}
            ");
            $facultyStmt->execute(array_merge($departmentAliases, $departmentAliases, $periodParams));
        } else {
            $facultyStmt = $db->prepare("
                SELECT COUNT(DISTINCT f.id)
                FROM faculty f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE (f.department IN ($placeholders) OR u.department IN ($placeholders))
                  AND COALESCE(f.is_archived, 0) = 0
            ");
            $facultyStmt->execute(array_merge($departmentAliases, $departmentAliases));
        }
        $facultyCount = (int) $facultyStmt->fetchColumn();

        $pendingStmt = $db->prepare("
            SELECT COUNT(*) FROM peer_assignments pa
            JOIN faculty f ON pa.evaluatee_faculty_id = f.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE (f.department IN ($placeholders) OR u.department IN ($placeholders)) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'pending'{$periodSql}
        ");
        $pendingStmt->execute(array_merge($departmentAliases, $departmentAliases, $periodParams));
        $pendingCount = (int) $pendingStmt->fetchColumn();

        $submittedStmt = $db->prepare("
            SELECT COUNT(*) FROM peer_assignments pa
            JOIN faculty f ON pa.evaluatee_faculty_id = f.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE (f.department IN ($placeholders) OR u.department IN ($placeholders)) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'{$periodSql}
        ");
        $submittedStmt->execute(array_merge($departmentAliases, $departmentAliases, $periodParams));
        $submittedCount = (int) $submittedStmt->fetchColumn();
    } else {
        $facultyCount = $periodName !== ''
            ? dashboard_count($db, "SELECT COUNT(DISTINCT evaluatee_faculty_id) FROM peer_assignments pa WHERE COALESCE(is_archived, 0) = 0{$periodSql}", $periodParams)
            : dashboard_count($db, "SELECT COUNT(*) FROM faculty WHERE COALESCE(is_archived, 0) = 0");
        $pendingCount = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments pa WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending'{$periodSql}", $periodParams);
        $submittedCount = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments pa WHERE COALESCE(is_archived, 0) = 0 AND status = 'submitted'{$periodSql}", $periodParams);
    }

    $totalEvals = $pendingCount + $submittedCount;
    $completionRate = $totalEvals > 0 ? round(($submittedCount / $totalEvals) * 100) . '%' : '0%';
    $overdueScopeSql = '';
    $overdueScopeParams = [];
    if (!empty($programCodes ?? [])) {
        $overdueScopeSql = "AND UPPER(TRIM(COALESCE(f.program_code, u.program, ''))) IN (" . implode(',', array_fill(0, count($programCodes), '?')) . ")";
        $overdueScopeParams = $programCodes;
    } elseif (!empty($department)) {
        $departmentAliases = admin_matching_department_aliases($department) ?: [$department];
        $overdueScopeSql = "AND (f.department IN (" . implode(',', array_fill(0, count($departmentAliases), '?')) . ") OR u.department IN (" . implode(',', array_fill(0, count($departmentAliases), '?')) . "))";
        $overdueScopeParams = array_merge($departmentAliases, $departmentAliases);
    }
    $overdueCount = dashboard_column_exists($db, 'peer_assignments', 'deadline')
        ? dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON pa.evaluatee_faculty_id = f.id
             LEFT JOIN users u ON f.user_id = u.id
             WHERE COALESCE(f.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status = 'pending'
               AND pa.deadline IS NOT NULL
               AND pa.deadline < CURDATE()
               {$overdueScopeSql} {$periodSql}",
            array_merge($overdueScopeParams, $periodParams)
        )
        : 0;

    return [
        'metrics' => [
            ['label' => 'Faculty', 'value' => $facultyCount],
            ['label' => 'Pending', 'value' => $pendingCount],
            ['label' => 'Submitted Reviews', 'value' => $submittedCount],
            ['label' => 'Completion Rate', 'value' => $completionRate],
            ['label' => 'Active Programs', 'value' => $activeProgramCount],
        ],
        'actionCenter' => [
            ['label' => 'Pending evaluations', 'count' => $pendingCount, 'detail' => 'Faculty evaluations awaiting submission', 'href' => '/program-head/evaluate', 'cta' => 'Open', 'tone' => 'warning', 'initial' => 'P'],
            ['label' => 'Overdue evaluations', 'count' => $overdueCount, 'detail' => 'Pending evaluations past deadline', 'href' => '/program-head/evaluate', 'cta' => 'Review', 'tone' => 'danger', 'initial' => 'O'],
            ['label' => 'Submitted reviews', 'count' => $submittedCount, 'detail' => 'Completed program evaluation records', 'href' => '/program-head/summary', 'cta' => 'Review', 'tone' => 'success', 'initial' => 'S'],
        ],
    ];
}

function getVpaaStats(PDO $db, ?array $period = null): array
{
    $user = current_user();
    $vpaaUserId = $user !== null ? (int) ($user['id'] ?? 0) : 0;
    $assignedDepartments = $vpaaUserId > 0 ? vpaa_departments($vpaaUserId) : [];

    if ($assignedDepartments === []) {
        return [
            'metrics' => [
                ['label' => 'Departments', 'value' => 0],
                ['label' => 'Active Faculty', 'value' => 0],
                ['label' => 'Pending Evaluations', 'value' => 0],
                ['label' => 'Completed Evaluations', 'value' => 0],
                ['label' => 'Completion Rate', 'value' => '0%'],
                ['label' => 'AI Insights', 'value' => 0],
            ],
            'actionCenter' => null,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    $departmentAliases = [];
    foreach ($assignedDepartments as $department) {
        $aliases = admin_matching_department_aliases((string) $department);
        $departmentAliases = array_merge($departmentAliases, $aliases !== [] ? $aliases : [(string) $department]);
    }
    $departmentAliases = array_values(array_unique(array_filter($departmentAliases)));

    if ($departmentAliases === []) {
        $departmentAliases = $assignedDepartments;
    }

    $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));
    $periodName = trim((string) ($period['period_name'] ?? ''));
    $periodSql = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $periodParams = $periodName !== '' ? [$periodName] : [];

    $totalEvals = dashboard_count(
        $db,
        "SELECT COUNT(*)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0{$periodSql}",
        array_merge($departmentAliases, $periodParams)
    );
    $pendingEvals = dashboard_count(
        $db,
        "SELECT COUNT(*)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'pending'{$periodSql}",
        array_merge($departmentAliases, $periodParams)
    );
    $completedEvals = dashboard_count(
        $db,
        "SELECT COUNT(*)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'{$periodSql}",
        array_merge($departmentAliases, $periodParams)
    );

    $departments = dashboard_count(
        $db,
        "SELECT COUNT(*) FROM departments WHERE is_active = 1 AND (department_code IN ($placeholders) OR department_name IN ($placeholders))",
        array_merge($departmentAliases, $departmentAliases)
    );
    $faculty = dashboard_count(
        $db,
        "SELECT COUNT(*)
         FROM faculty
         WHERE department IN ($placeholders) AND COALESCE(is_archived, 0) = 0",
        $departmentAliases
    );

    $completionRate = $totalEvals > 0 ? round(($completedEvals / $totalEvals) * 100) . '%' : '0%';

    $overdueEvals = dashboard_column_exists($db, 'peer_assignments', 'deadline')
        ? dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE f.department IN ($placeholders)
               AND COALESCE(f.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status = 'pending'
               AND pa.deadline IS NOT NULL
               AND pa.deadline < CURDATE()
               {$periodSql}",
            array_merge($departmentAliases, $periodParams)
        )
        : dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE f.department IN ($placeholders)
               AND COALESCE(f.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status = 'pending'
               AND pa.assigned_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
               {$periodSql}",
            array_merge($departmentAliases, $periodParams)
        );

    $dueSoonEvals = dashboard_column_exists($db, 'peer_assignments', 'deadline')
        ? dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE f.department IN ($placeholders)
               AND COALESCE(f.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status = 'pending'
               AND pa.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               {$periodSql}",
            array_merge($departmentAliases, $periodParams)
        )
        : 0;

    $aiInsights = 0;
    $weakAreas = 0;
    $tableCheck = $db->query("SHOW TABLES LIKE 'ai_insights'");
    if ($tableCheck && $tableCheck->fetch()) {
        $aiInsights = dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM ai_insights i
             JOIN faculty f ON f.id = i.faculty_id
             WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0",
            $departmentAliases
        );
        $weakAreas = $aiInsights;
    }

    $developmentPlans = dashboard_table_exists($db, 'intervention_plans')
        ? dashboard_count(
            $db,
            "SELECT COUNT(*)
             FROM intervention_plans p
             JOIN faculty f ON f.id = p.faculty_id
             WHERE f.department IN ($placeholders) AND COALESCE(f.is_archived, 0) = 0",
            $departmentAliases
        )
        : 0;

    return [
        'metrics' => [
            ['label' => 'Departments', 'value' => $departments],
            ['label' => 'Active Faculty', 'value' => $faculty],
            ['label' => 'Pending Evaluations', 'value' => $pendingEvals],
            ['label' => 'Completed Evaluations', 'value' => $completedEvals],
            ['label' => 'Completion Rate', 'value' => $completionRate],
            ['label' => 'AI Insights', 'value' => $aiInsights],
        ],
        'actionCenter' => [
            ['label' => 'Overdue evaluations', 'count' => $overdueEvals, 'detail' => 'Pending evaluations past the deadline', 'href' => '/vpaa/analytics', 'cta' => 'Review', 'tone' => 'danger', 'initial' => 'O'],
            ['label' => 'Due this week', 'count' => $dueSoonEvals, 'detail' => 'Pending evaluations due within 7 days', 'href' => '/vpaa/analytics', 'cta' => 'Check', 'tone' => 'warning', 'initial' => 'D'],
            ['label' => 'Pending evaluations', 'count' => $pendingEvals, 'detail' => 'Institution assignments awaiting completion', 'href' => '/vpaa/analytics', 'cta' => 'Open', 'tone' => 'warning', 'initial' => 'P'],
            ['label' => 'Weak areas', 'count' => $weakAreas, 'detail' => 'Faculty or program areas needing attention', 'href' => '/vpaa/summary', 'cta' => 'Analyze', 'tone' => 'danger', 'initial' => 'W'],
            ['label' => 'Development plans', 'count' => $developmentPlans, 'detail' => 'Recommended institutional interventions', 'href' => '/vpaa/summary', 'cta' => 'View', 'tone' => 'info', 'initial' => 'D'],
        ],
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
}

function getFacultyStats(PDO $db, int|string $userId, ?array $period = null): array {
    $userIdInt = (int) $userId;
    $periodName = trim((string) ($period['period_name'] ?? ''));
    $periodSql = $periodName !== '' ? ' AND cycle_name = ?' : '';
    $periodParams = $periodName !== '' ? [$periodName] : [];

    if ($userIdInt > 0) {
        $facultyStmt = $db->prepare("SELECT id FROM faculty WHERE user_id = ? AND COALESCE(is_archived, 0) = 0");
        $facultyStmt->execute([$userIdInt]);
        $facultyIds = array_map('intval', $facultyStmt->fetchAll(PDO::FETCH_COLUMN));

        $evalAssignedCount = dashboard_count(
            $db,
            "SELECT COUNT(*) FROM peer_assignments WHERE evaluator_user_id = ? AND COALESCE(is_archived, 0) = 0{$periodSql}",
            array_merge([$userIdInt], $periodParams)
        );
        $evalPendingCount = dashboard_count(
            $db,
            "SELECT COUNT(*) FROM peer_assignments WHERE evaluator_user_id = ? AND COALESCE(is_archived, 0) = 0 AND status = 'pending'{$periodSql}",
            array_merge([$userIdInt], $periodParams)
        );
        $submittedCount = dashboard_count(
            $db,
            "SELECT COUNT(*) FROM peer_assignments WHERE evaluator_user_id = ? AND COALESCE(is_archived, 0) = 0 AND status = 'submitted'{$periodSql}",
            array_merge([$userIdInt], $periodParams)
        );

        if ($facultyIds !== []) {
            $facultyPlaceholders = implode(',', array_fill(0, count($facultyIds), '?'));
            $assignedCount = dashboard_count(
                $db,
                "SELECT COUNT(*) FROM peer_assignments WHERE evaluatee_faculty_id IN ($facultyPlaceholders) AND COALESCE(is_archived, 0) = 0{$periodSql}",
                array_merge($facultyIds, $periodParams)
            );
        } else {
            $assignedCount = 0;
        }
    } else {
        $assignedCount = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0{$periodSql}", $periodParams);
        $evalAssignedCount = $assignedCount;
        $evalPendingCount = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending'{$periodSql}", $periodParams);
        $submittedCount = dashboard_count($db, "SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'submitted'{$periodSql}", $periodParams);
    }

    $totalTasks = $evalAssignedCount;
    $pendingTasks = $evalPendingCount;

    return [
        'metrics' => [
            ['label' => 'Assigned Tasks', 'value' => $totalTasks],
            ['label' => 'Pending Evaluations', 'value' => $pendingTasks],
            ['label' => 'Submitted Evaluations', 'value' => $submittedCount],
            ['label' => 'Evaluations Received', 'value' => $assignedCount],
        ],
    ];
}
