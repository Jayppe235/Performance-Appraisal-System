<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

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

function dean_summary_api_seminar(string $weakArea): string
{
    $area = strtolower($weakArea);

    return match (true) {
        str_contains($area, 'communication') => 'Communication Skills and Professional Feedback Seminar',
        str_contains($area, 'teaching') || str_contains($area, 'instruction') || str_contains($area, 'learning') => 'Teaching Strategies and Outcomes-Based Education Seminar',
        str_contains($area, 'classroom') || str_contains($area, 'learner') || str_contains($area, 'engagement') => 'Classroom Management and Learner Engagement Seminar',
        str_contains($area, 'job') || str_contains($area, 'knowledge') || str_contains($area, 'competence') || str_contains($area, 'excellence') => 'Subject Mastery and Professional Competence Seminar',
        str_contains($area, 'leadership') || str_contains($area, 'administrative') || str_contains($area, 'management') => 'Academic Leadership and Administrative Effectiveness Seminar',
        str_contains($area, 'technology') || str_contains($area, 'digital') => 'Educational Technology Integration Seminar',
        str_contains($area, 'initiative') || str_contains($area, 'resourcefulness') || str_contains($area, 'creativity') => 'Innovation and Resourcefulness Workshop',
        str_contains($area, 'institutional') || str_contains($area, 'sensitivity') || str_contains($area, 'commitment') => 'Institutional Commitment and Values Alignment Session',
        str_contains($area, 'interpersonal') || str_contains($area, 'teamwork') || str_contains($area, 'collaboration') => 'Team Collaboration and Interpersonal Sensitivity Seminar',
        str_contains($area, 'attendance') || str_contains($area, 'punctuality') => 'Professional Work Habits and Time Management Workshop',
        str_contains($area, 'decorum') || str_contains($area, 'professional') => 'Professional Ethics and Decorum Refresher Course',
        str_contains($area, 'flexibility') || str_contains($area, 'adaptability') => 'Adaptability and Change Management Seminar',
        default => 'Targeted Faculty Development Program on ' . $weakArea,
    };
}

function dean_summary_api_scope(int $deanUserId): array
{
    $departments = dean_departments($deanUserId);
    $departmentAliases = [];

    foreach ($departments as $dept) {
        $deptRow = admin_one(
            'SELECT * FROM departments WHERE department_code = :code OR department_name = :name LIMIT 1',
            ['code' => $dept, 'name' => $dept]
        );
        if ($deptRow !== null) {
            $departmentAliases = array_merge($departmentAliases, admin_department_aliases($deptRow));
        } else {
            $departmentAliases[] = $dept;
        }
    }

    return array_values(array_unique(array_filter($departmentAliases)));
}

function dean_summary_api_resolved_role(array $faculty): string
{
    $role = strtolower(trim((string) ($faculty['user_role'] ?? '')));
    $position = strtolower(trim((string) ($faculty['position_title'] ?? '')));

    if ($role === '' || $role === 'teacher') {
        if (str_contains($position, 'program head')) {
            return 'program_head';
        }
        if (str_contains($position, 'dean')) {
            return 'dean';
        }
        return 'teacher';
    }

    return $role;
}

function dean_summary_api_action(string $weakArea, string $scopeLabel): string
{
    return 'Recommend ' . dean_summary_api_seminar($weakArea) . ' for ' . $scopeLabel . ', followed by coaching, progress monitoring, and review in the next appraisal cycle.';
}

try {
    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'vpaa') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'VPAA access is required.']);
        exit;
    }

    admin_ensure_faculty_program_schema();
    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();

    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = $selectedPeriod !== null ? (string) ($selectedPeriod['period_name'] ?? '') : '';

    $facultyRows = admin_all(
        "SELECT f.id, f.full_name,
                COALESCE(NULLIF(epp.department_snapshot, ''), f.department) AS department,
                f.position_title,
                COALESCE(NULLIF(epp.program_snapshot, ''), NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                COALESCE(NULLIF(epp.role_snapshot, ''), NULLIF(u.role, ''), '') AS user_role
         FROM faculty f
         JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id = ? AND epp.user_id = u.id
         WHERE f.is_active = 1
           AND f.is_archived = 0
           AND u.is_active = 1
           AND epp.participation_status = 'included'
           AND epp.work_status = 'active'
           AND epp.employment_status IN ('active','newly_added')
         ORDER BY f.department, program_code, f.full_name",
        [(int)($selectedPeriod['id'] ?? 0)]
    );

    $facultyById = [];
    foreach ($facultyRows as $faculty) {
        $resolvedRole = dean_summary_api_resolved_role($faculty);
        if (!in_array($resolvedRole, ['dean', 'program_head', 'teacher'], true)) {
            continue;
        }
        $faculty['resolved_role'] = $resolvedRole;
        $facultyById[(int) $faculty['id']] = $faculty;
    }

    $facultyIds = array_keys($facultyById);

    // --- Factor Summary: aggregate Form A and Form B category results ---
    $factorResults = [];
    $factorWeights = [];
    $facultyWeakest = [];
    $facultyCategoryBuckets = [];
    $departmentCategoryBuckets = [];
    $programCategoryBuckets = [];
    if ($facultyIds !== []) {
        $facultyPlaceholders = implode(',', array_fill(0, count($facultyIds), '?'));

        $periodJoin = 'JOIN peer_assignments pa ON pa.id = r.assignment_id';
        $periodParams = [];
        if ($selectedPeriodName !== '') {
            $periodParams[] = $selectedPeriodName;
        }

        // Form A results (admin evaluation categories)
        $formAResults = admin_all(
            "SELECT r.evaluatee_faculty_id, c.title AS category_title,
                    r.average_rating, r.factor_weight, r.submitted_at
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             $periodJoin
             WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
               AND r.status = 'completed'".
               " AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0".
               ($selectedPeriodName !== '' ? " AND pa.cycle_name = ?" : ''),
            $selectedPeriodName !== '' ? array_merge($facultyIds, [$selectedPeriodName]) : $facultyIds
        );

        // Form B results (faculty evaluation categories)
        $formBResults = admin_all(
            "SELECT r.evaluatee_faculty_id, c.title AS category_title,
                    r.average_rating, r.factor_weight, r.submitted_at
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             $periodJoin
             WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
               AND r.status = 'completed'".
               " AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0".
               ($selectedPeriodName !== '' ? " AND pa.cycle_name = ?" : ''),
            $selectedPeriodName !== '' ? array_merge($facultyIds, [$selectedPeriodName]) : $facultyIds
        );

        $allResults = array_merge($formAResults, $formBResults);

        // Aggregate category/factor results for overall factor summary
        foreach ($allResults as $row) {
            $facultyId = (int) $row['evaluatee_faculty_id'];
            $categoryTitle = trim((string) ($row['category_title'] ?? ''));
            if ($categoryTitle === '') continue;

            if (!isset($factorResults[$categoryTitle])) {
                $factorResults[$categoryTitle] = [
                    'factor' => $categoryTitle,
                    'totalScore' => 0.0,
                    'resultCount' => 0,
                    'weakArea' => $categoryTitle,
                ];
                $factorWeights[$categoryTitle] = (float) ($row['factor_weight'] ?? 0);
            }

            $factorResults[$categoryTitle]['totalScore'] += (float) ($row['average_rating'] ?? 0);
            $factorResults[$categoryTitle]['resultCount']++;
        }

        // Also compute per-faculty and per-program category results so we can recommend targeted plans.
        foreach ($allResults as $row) {
            $fid = (int) $row['evaluatee_faculty_id'];
            $cat = trim((string) ($row['category_title'] ?? ''));
            $avg = (float) ($row['average_rating'] ?? 0);
            if ($cat === '') continue;
            if (!isset($facultyById[$fid])) continue;

            $department = (string) ($facultyById[$fid]['department'] ?: 'Unassigned Department');
            $programCode = (string) ($facultyById[$fid]['program_code'] ?? 'Unassigned Program');
            $programKey = $department . '|' . $programCode;
            $facultyCategoryBuckets[$fid][$cat] ??= ['total' => 0.0, 'count' => 0];
            $facultyCategoryBuckets[$fid][$cat]['total'] += $avg;
            $facultyCategoryBuckets[$fid][$cat]['count']++;

            $departmentCategoryBuckets[$department][$cat] ??= ['total' => 0.0, 'count' => 0, 'faculty' => []];
            $departmentCategoryBuckets[$department][$cat]['total'] += $avg;
            $departmentCategoryBuckets[$department][$cat]['count']++;
            $departmentCategoryBuckets[$department][$cat]['faculty'][$fid] = true;

            if (($facultyById[$fid]['resolved_role'] ?? '') !== 'dean') {
                $programCategoryBuckets[$programKey][$cat] ??= ['total' => 0.0, 'count' => 0, 'faculty' => []];
                $programCategoryBuckets[$programKey][$cat]['total'] += $avg;
                $programCategoryBuckets[$programKey][$cat]['count']++;
                $programCategoryBuckets[$programKey][$cat]['faculty'][$fid] = true;
            }

            if (!isset($facultyWeakest[$fid]) || $avg < $facultyWeakest[$fid]['average']) {
                $facultyWeakest[$fid] = ['category' => $cat, 'average' => $avg, 'seminar' => dean_summary_api_seminar($cat)];
            }
        }
    }

    // Build factor summary sorted by score ascending (weakest first)
    $factorSummary = [];
    foreach ($factorResults as $title => $data) {
        $avgScore = $data['resultCount'] > 0
            ? round($data['totalScore'] / $data['resultCount'], 2)
            : 0;
        $weight = $factorWeights[$title] ?? 0;
        $factorSummary[] = [
            'factor' => $title,
            'weakArea' => $title,
            'weight' => $weight > 0 ? number_format($weight, 2) . '%' : 'N/A',
            'averageScore' => $avgScore,
            'seminar' => dean_summary_api_seminar($title),
        ];
    }
    usort($factorSummary, static fn (array $a, array $b): int => $a['averageScore'] <=> $b['averageScore']);

    // --- Training Plans: intervention_plans for dean's department faculty ---
    $planRows = [];
    if ($facultyIds !== []) {
        $facultyPlaceholders = implode(',', array_fill(0, count($facultyIds), '?'));
        $planRows = admin_all(
            "SELECT p.*, f.full_name AS faculty_name, f.department,
                    COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
             FROM intervention_plans p
             JOIN faculty f ON f.id = p.faculty_id
             WHERE p.faculty_id IN ($facultyPlaceholders)
             ORDER BY FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
            $facultyIds
        );
    }

    $trainingPlans = [];
    foreach ($planRows as $plan) {
        $trainingPlans[] = [
            'id' => (int) $plan['id'],
            'scope' => 'Faculty',
            'facultyName' => (string) ($plan['faculty_name'] ?? ''),
            'department' => (string) ($plan['department'] ?: 'Unassigned Department'),
            'program' => (string) ($plan['program_code'] ?: 'Unassigned Program'),
            'weakArea' => (string) ($plan['weak_area'] ?? ''),
            'seminar' => dean_summary_api_seminar((string) ($plan['weak_area'] ?? '')),
            'recommendation' => (string) ($plan['recommendation'] ?? ''),
            'status' => admin_status_label((string) ($plan['status'] ?? 'planned')),
        ];
    }

    // Always derive institution, department, program, and faculty recommendations from submitted results.
    $generatedPlans = [];
    if ($factorSummary !== []) {
        $institutionWeak = (string) ($factorSummary[0]['weakArea'] ?? $factorSummary[0]['factor'] ?? 'Professional Growth');
        $lastFactor = end($factorSummary);
        $institutionStrong = is_array($lastFactor) ? (string) ($lastFactor['weakArea'] ?? $lastFactor['factor'] ?? '') : '';
        $generatedPlans[] = [
            'id' => 0,
            'scope' => 'Institution',
            'facultyName' => '',
            'department' => 'All Departments',
            'program' => 'All Programs',
            'weakArea' => $institutionWeak,
            'facultyCount' => count($facultyById),
            'seminar' => dean_summary_api_seminar($institutionWeak),
            'recommendation' => dean_summary_api_action($institutionWeak, 'all departments') . ($institutionStrong !== '' ? ' Maintain institutional strengths in ' . $institutionStrong . '.' : ''),
            'status' => 'Identified',
        ];
    }

    $facultyByDepartment = [];
    $facultyByProgram = [];
    foreach ($facultyById as $facultyId => $faculty) {
        $department = (string) ($faculty['department'] ?: 'Unassigned Department');
        $facultyByDepartment[$department][] = $facultyId;
        if (($faculty['resolved_role'] ?? '') === 'dean') {
            continue;
        }
        $prog = (string) ($faculty['program_code'] ?: 'Unassigned Program');
        $facultyByProgram[$department . '|' . $prog][] = $facultyId;
    }

    $departmentSummaries = [];
    foreach ($facultyByDepartment as $department => $fIds) {
        $departmentWeakCounts = [];
        $departmentFields = [];

        foreach (($departmentCategoryBuckets[$department] ?? []) as $cat => $bucket) {
            $avg = (float) $bucket['total'] / max(1, (int) $bucket['count']);
            $departmentFields[] = ['category' => (string) $cat, 'average' => round($avg, 2)];
            if ($avg <= 3.50) {
                $departmentWeakCounts[(string) $cat] = count($bucket['faculty'] ?? []);
            }
        }

        foreach ($fIds as $fId) {
            if (isset($facultyWeakest[$fId])) {
                $cat = (string) $facultyWeakest[$fId]['category'];
                $departmentWeakCounts[$cat] = max(($departmentWeakCounts[$cat] ?? 0), 1);
            }
        }

        usort($departmentFields, static fn (array $a, array $b): int => $a['average'] <=> $b['average']);
        if ($departmentWeakCounts === [] && $departmentFields !== []) {
            $departmentWeakCounts[(string) $departmentFields[0]['category']] = count($fIds);
        }
        if ($departmentWeakCounts === []) {
            continue;
        }

        arsort($departmentWeakCounts);
        $topWeakArea = (string) array_key_first($departmentWeakCounts);
        $lastField = end($departmentFields);
        $strongArea = is_array($lastField) ? (string) ($lastField['category'] ?? '') : '';
        $seminar = dean_summary_api_seminar($topWeakArea);

        $departmentSummaries[] = [
            'department' => $department,
            'weakArea' => $topWeakArea,
            'strongArea' => $strongArea,
            'facultyCount' => count($fIds),
            'affectedFaculty' => (int) ($departmentWeakCounts[$topWeakArea] ?? 0),
            'averageScore' => $departmentFields !== [] ? (float) $departmentFields[0]['average'] : 0,
            'seminar' => $seminar,
        ];

        $generatedPlans[] = [
            'id' => 0,
            'scope' => 'Department',
            'facultyName' => '',
            'department' => $department,
            'program' => 'All Programs',
            'weakArea' => $topWeakArea,
            'facultyCount' => count($fIds),
            'seminar' => $seminar,
            'recommendation' => dean_summary_api_action($topWeakArea, $department) . ($strongArea !== '' ? ' Preserve department strength in ' . $strongArea . '.' : ''),
            'status' => 'Identified',
        ];
    }

    $programSummaries = [];
    foreach ($facultyByProgram as $programKey => $fIds) {
        [$department, $programCode] = array_pad(explode('|', (string) $programKey, 2), 2, 'Unassigned Program');
        $programWeakCounts = [];
        $programFields = [];

        foreach (($programCategoryBuckets[$programKey] ?? []) as $cat => $bucket) {
            $avg = (float) $bucket['total'] / max(1, (int) $bucket['count']);
            $programFields[] = ['category' => (string) $cat, 'average' => round($avg, 2)];
            if ($avg <= 3.50) {
                $programWeakCounts[(string) $cat] = count($bucket['faculty'] ?? []);
            }
        }

        foreach ($fIds as $fId) {
            if (isset($facultyWeakest[$fId])) {
                $cat = (string) $facultyWeakest[$fId]['category'];
                $programWeakCounts[$cat] = max(($programWeakCounts[$cat] ?? 0), 1);
            }
        }

        usort($programFields, static fn (array $a, array $b): int => $a['average'] <=> $b['average']);
        if ($programWeakCounts === [] && $programFields !== []) {
            $programWeakCounts[(string) $programFields[0]['category']] = count($fIds);
        }
        if ($programWeakCounts === []) {
            continue;
        }

        arsort($programWeakCounts);
        $topWeakArea = (string) array_key_first($programWeakCounts);
        $lastField = end($programFields);
        $strongArea = is_array($lastField) ? (string) ($lastField['category'] ?? '') : '';
        $seminar = dean_summary_api_seminar($topWeakArea);

        $programSummaries[] = [
            'department' => $department,
            'program' => $programCode,
            'weakArea' => $topWeakArea,
            'strongArea' => $strongArea,
            'facultyCount' => count($fIds),
            'affectedFaculty' => (int) ($programWeakCounts[$topWeakArea] ?? 0),
            'averageScore' => $programFields !== [] ? (float) $programFields[0]['average'] : 0,
            'seminar' => $seminar,
        ];

        $generatedPlans[] = [
            'id' => 0,
            'scope' => 'Program',
            'facultyName' => '',
            'department' => $department,
            'program' => $programCode,
            'weakArea' => $topWeakArea,
            'facultyCount' => count($fIds),
            'seminar' => $seminar,
            'recommendation' => dean_summary_api_action($topWeakArea, $programCode . ' faculty') . ($strongArea !== '' ? ' Preserve program strength in ' . $strongArea . '.' : ''),
            'status' => 'Identified',
        ];
    }

    $facultyRecommendations = [];
    foreach (($facultyCategoryBuckets ?? []) as $facultyId => $buckets) {
        if (!isset($facultyById[$facultyId])) {
            continue;
        }

        $fields = [];
        foreach ($buckets as $cat => $bucket) {
            $fields[] = [
                'category' => (string) $cat,
                'average' => round((float) $bucket['total'] / max(1, (int) $bucket['count']), 2),
            ];
        }
        usort($fields, static fn (array $a, array $b): int => $a['average'] <=> $b['average']);
        if ($fields === []) {
            continue;
        }

        $faculty = $facultyById[$facultyId];
        $weakArea = (string) $fields[0]['category'];
        $lastField = end($fields);
        $strongArea = is_array($lastField) ? (string) ($lastField['category'] ?? '') : '';
        $average = array_sum(array_map(static fn (array $field): float => (float) $field['average'], $fields)) / count($fields);
        $seminar = dean_summary_api_seminar($weakArea);

        $facultyRecommendations[] = [
            'facultyName' => (string) $faculty['full_name'],
            'department' => (string) ($faculty['department'] ?: 'Unassigned Department'),
            'program' => (string) ($faculty['program_code'] ?: 'Unassigned Program'),
            'weakArea' => $weakArea,
            'strongArea' => $strongArea,
            'averageScore' => round($average, 2),
            'seminar' => $seminar,
            'recommendation' => dean_summary_api_action($weakArea, (string) $faculty['full_name']),
        ];

        $generatedPlans[] = [
            'id' => 0,
            'scope' => 'Faculty',
            'facultyName' => (string) $faculty['full_name'],
            'department' => (string) ($faculty['department'] ?: 'Unassigned Department'),
            'program' => (string) ($faculty['program_code'] ?: 'Unassigned Program'),
            'weakArea' => $weakArea,
            'facultyCount' => 1,
            'seminar' => $seminar,
            'recommendation' => dean_summary_api_action($weakArea, (string) $faculty['full_name']) . ($strongArea !== '' ? ' Current strength: ' . $strongArea . '.' : ''),
            'status' => (float) $fields[0]['average'] <= 3.50 ? 'Identified' : 'Stable',
        ];
    }

    $trainingPlanKeys = [];
    foreach ($trainingPlans as $plan) {
        $trainingPlanKeys[strtolower(($plan['scope'] ?? '') . '|' . ($plan['facultyName'] ?? '') . '|' . ($plan['department'] ?? '') . '|' . ($plan['program'] ?? '') . '|' . ($plan['weakArea'] ?? ''))] = true;
    }
    foreach ($generatedPlans as $plan) {
        $key = strtolower(($plan['scope'] ?? '') . '|' . ($plan['facultyName'] ?? '') . '|' . ($plan['department'] ?? '') . '|' . ($plan['program'] ?? '') . '|' . ($plan['weakArea'] ?? ''));
        if (isset($trainingPlanKeys[$key])) {
            continue;
        }
        $trainingPlans[] = $plan;
        $trainingPlanKeys[$key] = true;
    }

    // --- Weak Area Register: per-evaluation-result rows from completed category results ---
    $weakAreas = [];
    if ($facultyIds !== []) {
        $facultyPlaceholders = implode(',', array_fill(0, count($facultyIds), '?'));

        $periodCondition = '';
        $periodParams = [];
        if ($selectedPeriodName !== '') {
            $periodCondition = ' AND pa.cycle_name = ?';
            $periodParams[] = $selectedPeriodName;
        }

        // Form A weak areas (average_rating < 3.5 indicates a weak area)
        $formAWeak = admin_all(
            "SELECT r.evaluatee_faculty_id, r.average_rating, r.submitted_at, r.status,
                    c.title AS category_title, 'Form A' AS form_title,
                    f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
               AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND r.average_rating <= 3.50
               $periodCondition
             ORDER BY r.average_rating ASC, r.submitted_at DESC",
            $selectedPeriodName !== '' ? array_merge($facultyIds, $periodParams) : $facultyIds
        );

        // Form B weak areas (average_rating < 3.5 indicates a weak area)
        $formBWeak = admin_all(
            "SELECT r.evaluatee_faculty_id, r.average_rating, r.submitted_at, r.status,
                    c.title AS category_title, 'Form B' AS form_title,
                    f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
               AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND r.average_rating <= 3.50
               $periodCondition
             ORDER BY r.average_rating ASC, r.submitted_at DESC",
            $selectedPeriodName !== '' ? array_merge($facultyIds, $periodParams) : $facultyIds
        );

        $allWeak = array_merge($formAWeak, $formBWeak);

        // Deduplicate by (faculty_id, category_title, form_title) to avoid duplicate rows
        $seen = [];
        foreach ($allWeak as $row) {
            $fid = (int) $row['evaluatee_faculty_id'];
            $cat = (string) ($row['category_title'] ?? '');
            $form = (string) ($row['form_title'] ?? '');
            $key = "{$fid}|{$cat}|{$form}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $weakAreas[] = [
                'facultyName' => (string) ($row['full_name'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'program' => (string) ($row['program_code'] ?? 'Unassigned Program'),
                'formTitle' => $form,
                'weakCategory' => $cat,
                'averageScore' => number_format((float) ($row['average_rating'] ?? 0), 2),
                'dateSubmitted' => (string) ($row['submitted_at'] ?? ''),
                'status' => admin_status_label((string) ($row['status'] ?? 'completed')),
                'seminar' => dean_summary_api_seminar($cat),
            ];
        }
    }

    // If no weak areas from category results, fall back to factor summary
    if ($weakAreas === []) {
        foreach ($factorSummary as $factor) {
            $weakAreas[] = [
                'facultyName' => '—',
                'department' => '—',
                'program' => '—',
                'formTitle' => 'Form A/B',
                'weakCategory' => $factor['weakArea'],
                'averageScore' => number_format($factor['averageScore'], 2),
                'dateSubmitted' => '—',
                'status' => 'Identified',
                'seminar' => dean_summary_api_seminar($factor['weakArea']),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'weakAreas' => $weakAreas,
            'factorSummary' => $factorSummary,
            'trainingPlans' => $trainingPlans,
            'departmentSummaries' => $departmentSummaries ?? [],
            'programSummaries' => $programSummaries ?? [],
            'facultyRecommendations' => $facultyRecommendations ?? [],
            'summary' => [
                'faculty' => count($facultyById),
                'reviewed' => count(array_filter($factorResults, static fn (array $data): bool => $data['resultCount'] > 0)),
                'plans' => count($trainingPlans),
            ],
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
