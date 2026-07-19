<?php
declare(strict_types=1);
/**
 * VPAA Evaluation Monitor API
 *
 * GET /api/vpaa-evaluation-monitor.php?scope=departments
 * GET /api/vpaa-evaluation-monitor.php?scope=report&department_id=1
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vpaa_data.php';
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

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    $role = $user['role'] ?? '';
    if ($role !== 'vpaa' && $role !== 'admin_hr') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'VPAA or Admin access required.']);
        exit;
    }

    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();

    $scope = trim((string) ($_GET['scope'] ?? 'departments'));
    $departmentId = (int) ($_GET['department_id'] ?? 0);
    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = trim((string) ($selectedPeriod['period_name'] ?? ''));
    $selectedYear = trim((string) ($_GET['year'] ?? ''));
    if ($selectedYear === '' && $selectedPeriod !== null) {
        $selectedYear = dipascaf_period_year($selectedPeriod);
    }
    $cycleWhere = '';
    $cycleParams = [];
    $resultWhere = '';
    $resultParams = [];
    if ($selectedPeriodName !== '') {
        $cycleWhere = ' AND pa.cycle_name = ?';
        $cycleParams[] = $selectedPeriodName;
        $resultWhere = ' AND r.evaluation_period = ?';
        $resultParams[] = $selectedPeriodName;
    } elseif ($selectedYear !== '') {
        $cycleWhere = ' AND (pa.cycle_name LIKE ? OR YEAR(pa.assigned_at) = ?)';
        $cycleParams[] = '%' . $selectedYear . '%';
        $cycleParams[] = $selectedYear;
        $resultWhere = ' AND (r.evaluation_period LIKE ? OR YEAR(r.submitted_at) = ?)';
        $resultParams[] = '%' . $selectedYear . '%';
        $resultParams[] = $selectedYear;
    }

    // Get VPAA's assigned departments
    if ($role === 'vpaa') {
        $assignedDepartments = vpaa_departments((int) $user['id']);
    } else {
        $allDepts = admin_departments();
        $assignedDepartments = array_values(array_unique(array_map(
            static fn (array $d): string => (string) ($d['department_code'] ?? ''),
            $allDepts
        )));
    }

    if ($assignedDepartments === []) {
        echo json_encode(['ok' => false, 'message' => 'No departments assigned.']);
        exit;
    }

    // ── Department listing (dean info only) ──────────────────────────
    if ($scope === 'departments') {
        $allDepts = admin_departments();
        $departments = [];

        foreach ($allDepts as $dept) {
            $deptCode = (string) ($dept['department_code'] ?? '');
            $deptName = (string) ($dept['department_name'] ?? '');

            $isAssigned = false;
            foreach ($assignedDepartments as $assigned) {
                if (strcasecmp($deptCode, $assigned) === 0 || strcasecmp($deptName, $assigned) === 0) {
                    $isAssigned = true;
                    break;
                }
            }
            if (!$isAssigned) {
                continue;
            }

            $programCount = admin_count(
                'SELECT COUNT(*) FROM programs WHERE department_id = :id AND is_active = 1',
                ['id' => (int) $dept['id']]
            );
            $aliases = admin_department_aliases($dept);
            $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));

            $archivedFacultyCount = admin_count(
                "SELECT COUNT(*) FROM faculty WHERE is_archived = 1 AND department IN ($aliasPlaceholders)",
                $aliases
            );

            $evalStats = admin_one(
                "SELECT COUNT(DISTINCT pa.id) AS total,
                        SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
                 FROM peer_assignments pa
                 JOIN faculty f ON f.id = pa.evaluatee_faculty_id
                 WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0{$cycleWhere}",
                array_merge($aliases, $cycleParams)
            ) ?? [];
            $totalEvaluations = (int) ($evalStats['total'] ?? 0);
            $completedEvaluations = (int) ($evalStats['completed'] ?? 0);

            $departments[] = [
                'id' => (int) $dept['id'],
                'department_code' => $deptCode,
                'department_name' => $deptName,
                'dean_name' => (string) ($dept['dean_name'] ?? 'Unassigned'),
                'dean_email' => (string) ($dept['dean_email'] ?? ''),
                'program_count' => $programCount,
                'archived_faculty_count' => $archivedFacultyCount,
                'total_evaluations' => $totalEvaluations,
                'completed' => $completedEvaluations,
                'pending' => (int) ($evalStats['pending'] ?? 0),
                'overdue' => (int) ($evalStats['overdue'] ?? 0),
                'completion_pct' => $totalEvaluations > 0 ? round(($completedEvaluations / $totalEvaluations) * 100) : 0,
            ];
        }

        echo json_encode([
            'ok' => true,
            'data' => $departments,
        ]);
        exit;
    }

    // ── Department evaluations overview ─────────────────────────────
    if ($scope === 'evaluations' && $departmentId > 0) {
        $department = admin_one('SELECT * FROM departments WHERE id = :id', ['id' => $departmentId]);
        if ($department === null) {
            echo json_encode(['ok' => false, 'message' => 'Department not found.']);
            exit;
        }

        $deptCode = (string) ($department['department_code'] ?? '');
        $deptName = (string) ($department['department_name'] ?? '');

        // Verify VPAA has access
        $hasAccess = false;
        foreach ($assignedDepartments as $assigned) {
            if (strcasecmp($deptCode, $assigned) === 0 || strcasecmp($deptName, $assigned) === 0) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Access denied to this department.']);
            exit;
        }

        $aliases = admin_department_aliases($department);
        $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));

        // Programs under this department
        $programs = admin_all(
            'SELECT p.*, u.full_name AS program_head_name
             FROM programs p
             LEFT JOIN users u ON u.id = p.program_head_user_id
             WHERE p.department_id = :department_id AND p.is_active = 1
             ORDER BY p.program_name',
            ['department_id' => $departmentId]
        );

        // Faculty count
        $facultyCount = admin_count(
            "SELECT COUNT(*) FROM faculty WHERE is_archived = 0 AND department IN ($aliasPlaceholders)",
            $aliases
        );

        // All evaluations (peer_assignments) for this department's faculty
        $allEvals = admin_all(
            "SELECT pa.id, pa.assignment_type, pa.status, pa.deadline, pa.evaluator_role,
                    f.full_name AS evaluatee_name, f.position_title, f.program_code,
                    u.full_name AS evaluator_name
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             JOIN users u ON u.id = pa.evaluator_user_id
             WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0{$cycleWhere}
             ORDER BY f.program_code, pa.assignment_type, f.full_name",
            array_merge($aliases, $cycleParams)
        );

        // Group evaluations by program and section_key
        $programEvals = [];
        $totalAll = 0;
        $pendingAll = 0;
        $completedAll = 0;
        $overdueAll = 0;

        foreach ($allEvals as $ev) {
            $progCode = (string) ($ev['program_code'] ?? '');
            $posTitle = strtolower((string) ($ev['position_title'] ?? ''));
            $evStatus = (string) ($ev['status'] ?? 'pending');
            $deadline = (string) ($ev['deadline'] ?? '');

            // Determine overdue
            if ($evStatus !== 'submitted' && $deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time()) {
                $evStatus = 'overdue';
            }

            // Section key (matches the PHP logic in evaluation_cards.php)
            $sectionKey = match (true) {
                (string) ($ev['assignment_type'] ?? '') === 'peer' => 'peer',
                str_contains($posTitle, 'dean') => 'dean',
                str_contains($posTitle, 'program head') => 'program_head',
                default => 'faculty',
            };

            $totalAll++;
            if ($evStatus === 'submitted') $completedAll++;
            elseif ($evStatus === 'overdue') $overdueAll++;
            else $pendingAll++;

            if (!isset($programEvals[$progCode])) {
                $programEvals[$progCode] = [];
            }
            if (!isset($programEvals[$progCode][$sectionKey])) {
                $programEvals[$progCode][$sectionKey] = [];
            }

            $programEvals[$progCode][$sectionKey][] = [
                'id' => (int) $ev['id'],
                'evaluatee_name' => (string) ($ev['evaluatee_name'] ?? ''),
                'evaluator_name' => (string) ($ev['evaluator_name'] ?? ''),
                'evaluator_role' => (string) ($ev['evaluator_role'] ?? ''),
                'assignment_type' => (string) ($ev['assignment_type'] ?? ''),
                'status' => $evStatus,
                'deadline' => $deadline,
            ];
        }

        // Build program data
        $programData = [];
        foreach ($programs as $prog) {
            $pc = (string) ($prog['program_code'] ?? '');
            $evals = $programEvals[$pc] ?? [];
            $progTotal = 0;
            $progDone = 0;
            foreach ($evals as $secEvs) {
                $progTotal += count($secEvs);
                foreach ($secEvs as $e) {
                    if ($e['status'] === 'submitted') $progDone++;
                }
            }
            $programData[] = [
                'id' => (int) $prog['id'],
                'program_code' => $pc,
                'program_name' => (string) ($prog['program_name'] ?? $pc),
                'program_head_name' => (string) ($prog['program_head_name'] ?? 'Unassigned'),
                'total_faculty' => 0,
                'total_evaluations' => $progTotal,
                'completed' => $progDone,
                'pending' => $progTotal - $progDone,
                'evaluations' => $evals,
            ];
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'department' => [
                    'id' => (int) $department['id'],
                    'department_code' => $deptCode,
                    'department_name' => $deptName,
                    'dean_name' => (string) ($department['dean_name'] ?? 'Unassigned'),
                    'dean_email' => (string) ($department['dean_email'] ?? ''),
                ],
                'programs' => $programData,
                'faculty_count' => $facultyCount,
                'summary' => [
                    'total_evaluations' => $totalAll,
                    'completed' => $completedAll,
                    'pending' => $pendingAll,
                    'overdue' => $overdueAll,
                ],
            ],
        ]);
        exit;
    }

    // ── Detailed department report ───────────────────────────────────
    if ($scope === 'report' && $departmentId > 0) {
        $department = admin_one('SELECT * FROM departments WHERE id = :id', ['id' => $departmentId]);
        if ($department === null) {
            echo json_encode(['ok' => false, 'message' => 'Department not found.']);
            exit;
        }

        $deptCode = (string) ($department['department_code'] ?? '');
        $deptName = (string) ($department['department_name'] ?? '');

        // Verify VPAA has access
        $hasAccess = false;
        foreach ($assignedDepartments as $assigned) {
            if (strcasecmp($deptCode, $assigned) === 0 || strcasecmp($deptName, $assigned) === 0) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Access denied to this department.']);
            exit;
        }

        $aliases = admin_department_aliases($department);
        $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));

        // Programs under this department
        $programs = admin_all(
            'SELECT p.*, u.full_name AS program_head_name
             FROM programs p
             LEFT JOIN users u ON u.id = p.program_head_user_id
             WHERE p.department_id = :department_id AND p.is_active = 1
             ORDER BY p.program_name',
            ['department_id' => $departmentId]
        );

        // Faculty under this department
        $facultyMembers = admin_all(
            "SELECT f.* FROM faculty f
             WHERE f.is_archived = 0 AND f.department IN ($aliasPlaceholders)
             ORDER BY f.department, f.full_name",
            $aliases
        );

        // Evaluation stats from peer_assignments
        $evalStats = admin_one(
            "SELECT COUNT(DISTINCT pa.id) AS total,
                    SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0{$cycleWhere}",
            array_merge($aliases, $cycleParams)
        );

        $totalEval = (int) ($evalStats['total'] ?? 0);
        $completedEval = (int) ($evalStats['completed'] ?? 0);
        $pendingEval = (int) ($evalStats['pending'] ?? 0);
        $overdueEval = (int) ($evalStats['overdue'] ?? 0);
        $completionPct = $totalEval > 0 ? round(($completedEval / $totalEval) * 100, 1) : 0;

        // Average score from Form A results
        $avgScore = admin_one(
            "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
             FROM pmas_form_a_category_results r
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'{$resultWhere}",
            array_merge($aliases, $resultParams)
        );
        $departmentAvgScore = $avgScore !== null ? (float) ($avgScore['avg_score'] ?? 0) : 0;

        // Category-level scores for the department
        $categoryScores = admin_all(
            "SELECT c.title AS category_title, c.factor_weight,
                    ROUND(AVG(r.average_rating), 2) AS avg_rating,
                    ROUND(AVG(r.weighted_score), 2) AS avg_weighted_score,
                    COUNT(r.id) AS result_count
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'{$resultWhere}
             GROUP BY c.id, c.title, c.factor_weight
             ORDER BY c.sort_order",
            array_merge($aliases, $resultParams)
        );

        $formattedCategories = [];
        foreach ($categoryScores as $cs) {
            $formattedCategories[] = [
                'category' => (string) ($cs['category_title'] ?? ''),
                'score' => (float) ($cs['avg_rating'] ?? 0),
                'weight' => (float) ($cs['factor_weight'] ?? 0),
                'weighted_score' => (float) ($cs['avg_weighted_score'] ?? 0),
                'result_count' => (int) ($cs['result_count'] ?? 0),
            ];
        }
        usort($formattedCategories, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        // Build program data
        $programData = [];
        foreach ($programs as $prog) {
            $progCode = (string) ($prog['program_code'] ?? '');

            $progEvalStats = admin_one(
                "SELECT COUNT(DISTINCT pa.id) AS total,
                        SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
                 FROM peer_assignments pa
                 JOIN faculty f ON f.id = pa.evaluatee_faculty_id
                 WHERE f.program_code = ? AND f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0{$cycleWhere}",
                array_merge([$progCode], $aliases, $cycleParams)
            );

            $pTotal = (int) ($progEvalStats['total'] ?? 0);
            $pCompleted = (int) ($progEvalStats['completed'] ?? 0);

            $progAvgScore = admin_one(
                "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
                 FROM pmas_form_a_category_results r
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 JOIN faculty f ON f.id = r.evaluatee_faculty_id
                 WHERE f.program_code = ? AND f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'{$resultWhere}",
                array_merge([$progCode], $aliases, $resultParams)
            );

            $progCategoryScores = admin_all(
                "SELECT c.title AS category_title,
                        ROUND(AVG(r.average_rating), 2) AS avg_rating
                 FROM pmas_form_a_category_results r
                 JOIN pmas_form_a_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 JOIN faculty f ON f.id = r.evaluatee_faculty_id
                 WHERE f.program_code = ? AND f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'{$resultWhere}
                 GROUP BY c.id, c.title
                 ORDER BY avg_rating ASC",
                array_merge([$progCode], $aliases, $resultParams)
            );

            $progFacultyCount = admin_count(
                "SELECT COUNT(*) FROM faculty WHERE is_archived = 0 AND program_code = ? AND department IN ($aliasPlaceholders)",
                array_merge([$progCode], $aliases)
            );

            $programData[] = [
                'id' => (int) $prog['id'],
                'program_code' => $progCode,
                'program_name' => (string) ($prog['program_name'] ?? $progCode),
                'program_head_name' => (string) ($prog['program_head_name'] ?? 'Unassigned'),
                'total_faculty' => $progFacultyCount,
                'total_assignments' => $pTotal,
                'completed' => $pCompleted,
                'pending' => (int) ($progEvalStats['pending'] ?? 0),
                'overdue' => (int) ($progEvalStats['overdue'] ?? 0),
                'completion_pct' => $pTotal > 0 ? round(($pCompleted / $pTotal) * 100, 1) : 0,
                'average_score' => $progAvgScore !== null ? (float) ($progAvgScore['avg_score'] ?? 0) : 0,
                'category_scores' => array_map(static fn (array $cs): array => [
                    'category' => (string) ($cs['category_title'] ?? ''),
                    'score' => (float) ($cs['avg_rating'] ?? 0),
                ], $progCategoryScores),
            ];
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'department' => [
                    'id' => (int) $department['id'],
                    'department_code' => $deptCode,
                    'department_name' => $deptName,
                    'dean_name' => (string) ($department['dean_name'] ?? 'Unassigned'),
                    'dean_email' => (string) ($department['dean_email'] ?? ''),
                ],
                'programs' => $programData,
                'faculty_count' => count($facultyMembers),
                'eval_summary' => [
                    'total' => $totalEval,
                    'completed' => $completedEval,
                    'pending' => $pendingEval,
                    'overdue' => $overdueEval,
                    'completion_pct' => $completionPct,
                    'average_score' => $departmentAvgScore,
                ],
                'category_scores' => $formattedCategories,
                'period' => $selectedPeriod !== null ? dipascaf_period_payload($selectedPeriod) + ['year' => dipascaf_period_year($selectedPeriod)] : null,
                'year' => $selectedYear,
            ],
        ]);
        exit;
    }

    // ── Department recommendations ────────────────────────────────────
    if ($scope === 'recommendations' && $departmentId > 0) {
        $department = admin_one('SELECT * FROM departments WHERE id = :id', ['id' => $departmentId]);
        if ($department === null) {
            echo json_encode(['ok' => false, 'message' => 'Department not found.']);
            exit;
        }

        $deptCode = (string) ($department['department_code'] ?? '');
        $deptName = (string) ($department['department_name'] ?? '');

        // Verify VPAA has access
        $hasAccess = false;
        foreach ($assignedDepartments as $assigned) {
            if (strcasecmp($deptCode, $assigned) === 0 || strcasecmp($deptName, $assigned) === 0) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Access denied to this department.']);
            exit;
        }

        $aliases = admin_department_aliases($department);
        $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));

        // ── 1. Aggregate weak areas from Form A + Form B category results ──
        $allCategoryResults = array_merge(
            admin_all(
                "SELECT c.title AS category_title, r.average_rating, f.full_name,
                        COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS program_code
                 FROM pmas_form_a_category_results r
                 JOIN pmas_form_a_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 JOIN faculty f ON f.id = r.evaluatee_faculty_id
                 WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'{$resultWhere}",
                array_merge($aliases, $resultParams)
            ),
            admin_all(
                "SELECT c.title AS category_title, r.average_rating, f.full_name,
                        COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS program_code
                 FROM pmas_form_b_category_results r
                 JOIN pmas_form_b_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 JOIN faculty f ON f.id = r.evaluatee_faculty_id
                 WHERE f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'{$resultWhere}",
                array_merge($aliases, $resultParams)
            )
        );

        // Build weak area buckets
        $weakAreaBuckets = []; // category_title => ['totalScore', 'count', 'faculty' => []]
        $lowScoreBuckets = []; // categories with avg <= 3.50

        foreach ($allCategoryResults as $row) {
            $cat = trim((string) ($row['category_title'] ?? ''));
            if ($cat === '') continue;
            $score = (float) ($row['average_rating'] ?? 0);
            $facultyName = (string) ($row['full_name'] ?? '');

            $weakAreaBuckets[$cat] ??= ['totalScore' => 0.0, 'count' => 0, 'faculty' => []];
            $weakAreaBuckets[$cat]['totalScore'] += $score;
            $weakAreaBuckets[$cat]['count']++;
            if ($facultyName !== '' && $score <= 3.50) {
                $weakAreaBuckets[$cat]['faculty'][$facultyName] = ($weakAreaBuckets[$cat]['faculty'][$facultyName] ?? 0) + 1;
            }
        }

        // Build low-score weak areas (avg <= 3.50)
        $weakAreas = [];
        foreach ($weakAreaBuckets as $cat => $data) {
            $avgScore = $data['count'] > 0 ? round($data['totalScore'] / $data['count'], 2) : 0;
            if ($avgScore <= 3.50 && $data['count'] > 0) {
                $seminar = vpaa_recommendation_seminar($cat);
                $weakAreas[] = [
                    'weak_area' => $cat,
                    'average_score' => $avgScore,
                    'faculty_count' => count($data['faculty']),
                    'total_results' => $data['count'],
                    'recommended_seminar' => $seminar,
                ];
            }
        }
        usort($weakAreas, static fn (array $a, array $b): int => $a['average_score'] <=> $b['average_score']);

        // ── 2. Get intervention plans for department faculty ──
        $interventions = admin_all(
            "SELECT p.*, f.full_name AS faculty_name,
                    COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS program_code
             FROM intervention_plans p
             JOIN faculty f ON f.id = p.faculty_id
             WHERE f.department IN ($aliasPlaceholders)
             ORDER BY FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
            $aliases
        );

        $interventionPlans = [];
        foreach ($interventions as $plan) {
            $interventionPlans[] = [
                'id' => (int) $plan['id'],
                'faculty_name' => (string) ($plan['faculty_name'] ?? ''),
                'program_code' => (string) ($plan['program_code'] ?? ''),
                'weak_area' => (string) ($plan['weak_area'] ?? ''),
                'recommendation' => (string) ($plan['recommendation'] ?? ''),
                'action_type' => (string) ($plan['action_type'] ?? ''),
                'status' => admin_status_label((string) ($plan['status'] ?? 'planned')),
                'target_date' => (string) ($plan['target_date'] ?? ''),
            ];
        }

        // ── 3. Derive recommendations from all categories (for full picture) ──
        $allCategories = [];
        foreach ($weakAreaBuckets as $cat => $data) {
            $avgScore = $data['count'] > 0 ? round($data['totalScore'] / $data['count'], 2) : 0;
            $allCategories[] = [
                'category' => $cat,
                'average_score' => $avgScore,
                'result_count' => $data['count'],
                'faculty_affected' => count($data['faculty']),
                'recommended_seminar' => $avgScore <= 3.50 ? vpaa_recommendation_seminar($cat) : '',
                'rating_level' => $avgScore >= 4.51 ? 'excellent' : ($avgScore >= 3.51 ? 'satisfactory' : 'needs_improvement'),
            ];
        }
        usort($allCategories, static fn (array $a, array $b): int => $a['average_score'] <=> $b['average_score']);

        echo json_encode([
            'ok' => true,
            'data' => [
                'department' => [
                    'id' => (int) $department['id'],
                    'department_code' => $deptCode,
                    'department_name' => $deptName,
                    'dean_name' => (string) ($department['dean_name'] ?? 'Unassigned'),
                ],
                'weak_areas' => $weakAreas,
                'all_categories' => $allCategories,
                'intervention_plans' => $interventionPlans,
                'summary' => [
                    'total_weak_areas' => count($weakAreas),
                    'total_interventions' => count($interventionPlans),
                    'active_interventions' => count(array_filter($interventionPlans, static fn (array $p): bool => !in_array(strtolower($p['status']), ['completed'], true))),
                ],
            ],
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Invalid scope. Use departments, evaluations, report, or recommendations.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
