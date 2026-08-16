<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';

final class PerformanceReportScopeException extends RuntimeException {}

/** Resolve a stored report image to a verified project-relative URL and local path. */
function performance_report_asset(?string $storedPath, ?string $fallback = null): ?array
{
    $candidates = array_filter([$storedPath, $fallback], static fn ($value) => trim((string) $value) !== '');
    $projectRoot = realpath(__DIR__ . '/..');
    $assetRoot = realpath(__DIR__ . '/../assets');
    if ($projectRoot === false || $assetRoot === false) return null;
    foreach ($candidates as $candidate) {
        $normalized = str_replace('\\', '/', trim((string) $candidate));
        $normalized = preg_replace('#^(?:https?://[^/]+)?/(?:PMAS/)?#i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^(?:\.\./|\./)+#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#(?:^|/)PMAS/#i', '', $normalized) ?? $normalized;
        $absolute = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized));
        if ($absolute === false || !is_file($absolute) || !str_starts_with($absolute, $assetRoot)) continue;
        $relative = str_replace('\\', '/', substr($absolute, strlen($projectRoot) + 1));
        return ['url' => $relative, 'path' => $absolute, 'mime' => mime_content_type($absolute) ?: 'image/png'];
    }
    return null;
}

function performance_report_level(?float $score): string
{
    if ($score === null) return 'Incomplete';
    if ($score >= 4.5) return 'Excellent';
    if ($score >= 3.75) return 'Very Satisfactory';
    if ($score >= 3.0) return 'Satisfactory';
    return 'Needs Improvement';
}

function performance_report_source(string $assignmentType, string $evaluatorRole = ''): string
{
    $assignmentType = strtolower(trim($assignmentType));
    return match ($assignmentType) {
        'self' => 'self',
        'dean', 'vpaa' => 'head',
        'program_head' => 'phsc',
        'peer' => 'peer',
        default => in_array(strtolower(trim($evaluatorRole)), ['vpaa', 'dean'], true)
            ? 'head'
            : (strtolower(trim($evaluatorRole)) === 'program_head' ? 'phsc' : 'peer'),
    };
}

function performance_report_metadata(): array
{
    return [
        'departments' => admin_all('SELECT id, department_code AS code, department_name AS name, dean_user_id, logo_image FROM departments WHERE is_active = 1 ORDER BY department_name'),
        'programs' => admin_all('SELECT id, department_id, program_code AS code, program_name AS name FROM programs WHERE is_active = 1 ORDER BY program_name'),
        'periods' => admin_all('SELECT id, period_name, school_year, date_start, date_end, status FROM appraisal_periods ORDER BY date_start DESC, id DESC'),
        'faculty' => [],
    ];
}

/** Apply the signed-in academic leader's report scope to both filters and choices. */
function performance_report_user_scope(array $user, array $filters, array $metadata): array
{
    $role = (string) ($user['role'] ?? '');
    $requestedPeriodId = (int)($filters['period_id'] ?? $filters['evaluation_period_id'] ?? 0);
    if ($requestedPeriodId > 0 && $role !== 'dean') {
        require_once __DIR__ . '/evaluation_participation.php';
        if (dipascaf_period_dean_scope($requestedPeriodId, (int)$user['id']) !== []) $role = 'dean';
    }
    if ($role === 'teacher') {
        $faculty = admin_one('SELECT id, department, program_code FROM faculty WHERE user_id = :id AND COALESCE(is_archived, 0) = 0 LIMIT 1', ['id' => (int)$user['id']]);
        if ($faculty === null) throw new PerformanceReportScopeException('No faculty profile is assigned to this account.');
        $requestedFacultyId = (int)($filters['faculty_id'] ?? 0);
        if ($requestedFacultyId > 0 && $requestedFacultyId !== (int)$faculty['id']) throw new PerformanceReportScopeException('The requested faculty record is outside your reporting scope.');
        $filters['faculty_id'] = (int)$faculty['id'];
        $filters['role'] = 'teacher';
        $filters['report_type'] = 'individual';
        $metadata['faculty'] = [['id' => (int)$faculty['id'], 'name' => $user['full_name'] ?? 'My Report', 'department' => $faculty['department'], 'program' => $faculty['program_code']]];
        return [$filters, $metadata];
    }
    if (!in_array($role, ['dean', 'program_head'], true)) return [$filters, $metadata];

    if ($role === 'dean') {
        $department = admin_one(
            'SELECT id FROM departments WHERE dean_user_id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $user['id']]
        );
        if ($department === null) {
            $department = admin_one(
                'SELECT id FROM departments WHERE is_active = 1 AND (department_code = :department OR department_name = :department) LIMIT 1',
                ['department' => trim((string) ($user['department'] ?? ''))]
            );
        }
        if ($department === null) throw new PerformanceReportScopeException('No department is assigned to this Dean account.');
        $departmentId = (int) $department['id'];
        $requestedDepartmentId = (int) ($filters['department_id'] ?? 0);
        if ($requestedDepartmentId > 0 && $requestedDepartmentId !== $departmentId) {
            throw new PerformanceReportScopeException('The requested department is outside your assigned reporting scope.');
        }
        $filters['department_id'] = $departmentId;
        $metadata['departments'] = array_values(array_filter($metadata['departments'], static fn ($item) => (int) $item['id'] === $departmentId));
        $metadata['programs'] = array_values(array_filter($metadata['programs'], static fn ($item) => (int) $item['department_id'] === $departmentId));
        if (($filters['report_type'] ?? '') === 'overall_department') $filters['report_type'] = 'department';
        return [$filters, $metadata];
    }

    $programs = [];
    if ($requestedPeriodId > 0) {
        require_once __DIR__ . '/evaluation_participation.php';
        $programs = array_map(static fn(array $row): array => [
            'id'=>(int)$row['program_id'],
            'department_id'=>(int)$row['department_id'],
            'program_code'=>(string)$row['program_code'],
        ], dipascaf_period_program_head_programs($requestedPeriodId, (int)$user['id'], false));
    }
    if ($programs === []) {
        $programs = admin_all(
            'SELECT id, department_id, program_code FROM programs WHERE program_head_user_id = :id AND is_active = 1 ORDER BY program_name',
            ['id' => (int) $user['id']]
        );
    }
    if ($programs === [] && trim((string) ($user['program'] ?? '')) !== '') {
        $programs = admin_all(
            'SELECT p.id, p.department_id, p.program_code
             FROM programs p
             JOIN departments d ON d.id = p.department_id
             WHERE p.program_code = :program AND p.is_active = 1
               AND (d.department_code = :department OR d.department_name = :department)',
            [
                'program' => strtoupper(trim((string) $user['program'])),
                'department' => trim((string) ($user['department'] ?? '')),
            ]
        );
    }
    if ($programs === []) throw new PerformanceReportScopeException('No program is assigned to this Program Head account.');
    $allowedProgramCodes = array_map(static fn ($item) => strtoupper((string) $item['program_code']), $programs);
    $requestedProgram = strtoupper(trim((string) ($filters['program'] ?? '')));
    if ($requestedProgram !== '' && !in_array($requestedProgram, $allowedProgramCodes, true)) {
        throw new PerformanceReportScopeException('The requested program is outside your assigned reporting scope.');
    }
    $requestedDepartmentId = (int) ($filters['department_id'] ?? 0);
    $filters['program'] = in_array($requestedProgram, $allowedProgramCodes, true) ? $requestedProgram : '';
    $filters['_allowed_program_codes'] = $allowedProgramCodes;
    $assignedDepartmentId = (int)$programs[0]['department_id'];
    if ($requestedDepartmentId > 0 && $requestedDepartmentId !== $assignedDepartmentId) {
        throw new PerformanceReportScopeException('The requested department is outside your assigned reporting scope.');
    }
    $filters['department_id'] = $assignedDepartmentId;
    if (!in_array((string)($filters['report_type'] ?? ''), ['consolidated','form_a','form_b','department','role_based'], true)) $filters['report_type'] = 'consolidated';
    $filters['role'] = 'teacher';
    $allowedIds = array_map(static fn ($item) => (int) $item['id'], $programs);
    $metadata['programs'] = array_values(array_filter($metadata['programs'], static fn ($item) => in_array((int) $item['id'], $allowedIds, true)));
    $departmentIds = array_unique(array_map(static fn ($item) => (int) $item['department_id'], $programs));
    $metadata['departments'] = array_values(array_filter($metadata['departments'], static fn ($item) => in_array((int) $item['id'], $departmentIds, true)));
    return [$filters, $metadata];
}

function performance_report_build(array $filters): array
{
    $reportType = (string) ($filters['report_type'] ?? 'department');
    $departmentId = (int) ($filters['department_id'] ?? 0);
    $role = (string) ($filters['role'] ?? 'teacher');
    $program = strtoupper(trim((string) ($filters['program'] ?? '')));
    $allowedProgramCodes = array_values(array_unique(array_filter(array_map(
        static fn($value): string => strtoupper(trim((string)$value)),
        is_array($filters['_allowed_program_codes'] ?? null) ? $filters['_allowed_program_codes'] : []
    ))));
    $periodId = (int) ($filters['period_id'] ?? 0);
    $facultyId = (int) ($filters['faculty_id'] ?? 0);
    $sort = (string) ($filters['sort'] ?? 'name');

    $department = $departmentId > 0 ? admin_one('SELECT d.*, u.full_name AS dean_name FROM departments d LEFT JOIN users u ON u.id = d.dean_user_id WHERE d.id = :id', ['id' => $departmentId]) : null;
    $period = $periodId > 0 ? admin_one('SELECT * FROM appraisal_periods WHERE id = :id', ['id' => $periodId]) : null;
    $periodJoin = ' LEFT JOIN evaluation_period_participation epp ON 1=0 ';
    $where = ['COALESCE(f.is_archived, 0) = 0'];
    $params = [];
    if ($period !== null) {
        $periodJoin = ' LEFT JOIN evaluation_period_participation epp ON epp.user_id = u.id AND epp.evaluation_period_id = :report_period_id ';
        $params['report_period_id'] = $periodId;
    }
    if ($facultyId > 0) {
        $where[] = 'f.id = :faculty_id';
        $params['faculty_id'] = $facultyId;
    }
    if ($department !== null) {
        $where[] = '(COALESCE(NULLIF(epp.department_snapshot, ""), f.department) = :department_name OR COALESCE(NULLIF(epp.department_snapshot, ""), f.department) = :department_code)';
        $params['department_name'] = $department['department_name'];
        $params['department_code'] = $department['department_code'];
    }
    if ($role !== '') {
        $where[] = 'COALESCE(NULLIF(epp.role_snapshot, ""), u.role, "teacher") = :role';
        $params['role'] = $role;
    }
    if ($program !== '') {
        $where[] = 'UPPER(COALESCE(NULLIF(epp.program_snapshot, ""), f.program_code)) = :program';
        $params['program'] = $program;
    } elseif ($allowedProgramCodes !== []) {
        $programParts = [];
        foreach ($allowedProgramCodes as $index => $code) {
            $key = 'allowed_program_' . $index;
            $programParts[] = ':' . $key;
            $params[$key] = $code;
        }
        $where[] = 'UPPER(COALESCE(NULLIF(epp.program_snapshot, ""), f.program_code)) IN (' . implode(',', $programParts) . ')';
    }
    if ($period !== null) {
        $where[] = 'p.cycle_name = :cycle_name';
        $params['cycle_name'] = $period['period_name'];
    }

    $resultSql = "
        SELECT assignment_id, LEAST(5.0000, GREATEST(0.0000, ROUND(SUM(weighted_score), 4))) AS score
        FROM (
            SELECT assignment_id, weighted_score FROM pmas_form_a_category_results WHERE status = 'completed' AND COALESCE(is_archived, 0) = 0
            UNION ALL
            SELECT assignment_id, weighted_score FROM pmas_form_b_category_results WHERE status = 'completed' AND COALESCE(is_archived, 0) = 0
            UNION ALL
            SELECT assignment_id, overall_rating AS weighted_score FROM pmas_self_evaluations WHERE status IN ('submitted','approved')
        ) valid_results
        GROUP BY assignment_id";

    $rows = admin_all(
        'SELECT f.id, f.full_name,
                COALESCE(NULLIF(epp.department_snapshot, ""), f.department) AS department,
                COALESCE(NULLIF(epp.program_snapshot, ""), f.program_code) AS program_code,
                COALESCE(NULLIF(epp.role_snapshot, ""), u.role, "teacher") AS user_role,
                p.id AS assignment_id, p.assignment_type, p.evaluator_role, p.status AS assignment_status, r.score
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id
         ' . $periodJoin . '
         JOIN peer_assignments p ON p.evaluatee_faculty_id = f.id AND COALESCE(p.is_archived, 0) = 0
         LEFT JOIN (' . $resultSql . ') r ON r.assignment_id = p.id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY f.department, f.full_name, p.id',
        $params
    );

    $people = [];
    $assignmentTotal = 0;
    $assignmentCompleted = 0;
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $people[$id] ??= [
            'id' => $id, 'name' => $row['full_name'], 'department' => $row['department'],
            'program' => $row['program_code'] ?? '', 'role' => $row['user_role'],
            'sources' => ['peer' => [], 'head' => [], 'phsc' => [], 'self' => []],
            'scores' => [], 'assignments' => 0, 'completed' => 0,
        ];
        $people[$id]['assignments']++;
        $assignmentTotal++;
        if ($row['score'] === null || !in_array((string)$row['assignment_status'], ['submitted', 'completed'], true)) continue;
        $score = (float) $row['score'];
        $source = performance_report_source((string)$row['assignment_type'], (string)$row['evaluator_role']);
        $people[$id]['sources'][$source][] = $score;
        $people[$id]['scores'][] = $score;
        $people[$id]['completed']++;
        $assignmentCompleted++;
    }

    $personRows = [];
    foreach ($people as $person) {
        $sourceMeans = [];
        foreach ($person['sources'] as $key => $values) {
            $sourceMeans[$key] = $values === [] ? null : array_sum($values) / count($values);
        }
        $mean = $person['scores'] === [] ? null : array_sum($person['scores']) / count($person['scores']);
        $applicable = array_values(array_filter([$sourceMeans['peer'], $sourceMeans['head'], $sourceMeans['phsc']], static fn ($value) => $value !== null));
        $personRows[] = [
            ...$person,
            'peer' => $sourceMeans['peer'] === null ? null : round($sourceMeans['peer'], 4),
            'head' => $sourceMeans['head'] === null ? null : round($sourceMeans['head'], 4),
            'phsc' => $sourceMeans['phsc'] === null ? null : round($sourceMeans['phsc'], 4),
            'self' => $sourceMeans['self'] === null ? null : round($sourceMeans['self'], 4),
            'total' => $applicable === [] ? null : round(array_sum($applicable), 4),
            'mean' => $mean === null ? null : round($mean, 4),
            'level' => performance_report_level($mean),
        ];
    }

    usort($personRows, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'score_desc') return ($b['mean'] ?? -1) <=> ($a['mean'] ?? -1);
        if ($sort === 'score_asc') return ($a['mean'] ?? 99) <=> ($b['mean'] ?? 99);
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $outputRows = $personRows;
    if ($reportType === 'overall_department') {
        $groups = [];
        foreach ($personRows as $person) {
            $key = $person['department'] ?: 'Unassigned';
            $groups[$key] ??= ['department' => $key, 'personnel' => 0, 'peer' => [], 'head' => [], 'phsc' => [], 'mean' => []];
            $groups[$key]['personnel']++;
            foreach (['peer', 'head', 'phsc', 'mean'] as $metric) if ($person[$metric] !== null) $groups[$key][$metric][] = $person[$metric];
        }
        $outputRows = [];
        foreach ($groups as $group) {
            $average = static fn (array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 4);
            $mean = $average($group['mean']);
            $outputRows[] = [
                'department' => $group['department'], 'personnel' => $group['personnel'],
                'peer' => $average($group['peer']), 'head' => $average($group['head']), 'phsc' => $average($group['phsc']),
                'mean' => $mean, 'level' => performance_report_level($mean),
            ];
        }
        usort($outputRows, static fn (array $a, array $b): int => ($b['mean'] ?? -1) <=> ($a['mean'] ?? -1));
    }

    $validMeans = array_values(array_filter(array_column($personRows, 'mean'), static fn ($value) => $value !== null));
    $overallMean = $validMeans === [] ? null : round(array_sum($validMeans) / count($validMeans), 4);
    $departmentName = $department['department_name'] ?? 'All Departments';
    $roleLabels = ['dean' => 'Deans', 'program_head' => 'Program Heads', 'teacher' => 'Faculty Members', '' => 'All Applicable Roles'];
    $institutionAsset = performance_report_asset('assets/images/ndmc-seal.png');
    $departmentAsset = performance_report_asset($department['logo_image'] ?? null);

    $analytics = performance_report_analytics($personRows, $period, $filters);
    return [
        'report_type' => $reportType,
        'department' => $departmentName,
        'department_code' => $department['department_code'] ?? '',
        'institution_logo' => $institutionAsset['url'] ?? null,
        'department_logo' => $departmentAsset['url'] ?? null,
        'assets' => ['institution_logo' => $institutionAsset, 'department_logo' => $departmentAsset],
        'role' => $role,
        'role_label' => $roleLabels[$role] ?? ucfirst($role),
        'program' => $program ?: 'All Programs',
        'period' => $period,
        'rows' => $outputRows,
        'person_rows' => $personRows,
        'summary' => [
            'overall_mean' => $overallMean,
            'level' => performance_report_level($overallMean),
            'personnel' => count($personRows),
            'departments' => count(array_unique(array_column($personRows, 'department'))),
            'completion' => $assignmentTotal > 0 ? round($assignmentCompleted / $assignmentTotal * 100, 1) : 0,
            'highest_department' => $reportType === 'overall_department' && $outputRows !== [] ? $outputRows[0]['department'] : null,
        ],
        'analytics' => $analytics,
        'recommendation' => $analytics['recommendation'],
        'warnings' => $analytics['warnings'],
        'signatory' => $department['dean_name'] ?? null,
        'generated_by' => current_user()['full_name'] ?? 'Authorized User',
        'generated_at' => date(DATE_ATOM),
    ];
}

function performance_report_average(array $values): ?float
{
    $values = array_values(array_filter($values, static fn($value) => $value !== null && is_numeric($value)));
    return $values === [] ? null : round(array_sum($values) / count($values), 2);
}

function performance_report_source_analysis(string $key, string $label, array $categories, array $scores): array
{
    $grouped = [];
    foreach ($categories as $row) {
        $title = trim((string)($row['category'] ?? '')) ?: 'Uncategorized';
        $grouped[$title] ??= ['title' => $title, 'values' => [], 'weight' => (float)($row['factor_weight'] ?? 0), 'result_count' => 0];
        $grouped[$title]['values'][] = (float)$row['score'];
        $grouped[$title]['result_count']++;
    }
    $items = array_map(static function(array $item): array {
        $item['score'] = performance_report_average($item['values']);
        unset($item['values']);
        return $item;
    }, array_values($grouped));
    usort($items, static fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $strengths = array_slice($items, 0, 3);
    $ascending = $items;
    usort($ascending, static fn($a, $b) => ($a['score'] ?? 99) <=> ($b['score'] ?? 99));
    $weak = array_values(array_filter($ascending, static fn($item) => ($item['score'] ?? 99) <= 3.5));
    $improvements = array_slice($weak !== [] ? $weak : $ascending, 0, $weak !== [] ? 3 : 2);
    foreach ($improvements as &$item) $item['classification'] = ($item['score'] ?? 99) <= 3.5 ? 'weakness' : 'growth_opportunity';
    unset($item);
    return [
        'key' => $key, 'label' => $label, 'available' => $scores !== [],
        'completed_count' => count($scores), 'score' => performance_report_average($scores),
        'categories' => $items, 'strengths' => $strengths, 'improvement_areas' => $improvements,
    ];
}

function performance_report_development_activity(array $evidence): array
{
    $haystack = strtolower(implode(' ', array_column($evidence, 'category')));
    if (str_contains($haystack, 'teach') || str_contains($haystack, 'instruction')) {
        return ['activity_type' => 'Workshop', 'title' => 'Instructional Strategies and Effective Communication Workshop', 'objective' => 'Strengthen evidence-based teaching delivery, learner engagement, and clear classroom communication.'];
    }
    if (str_contains($haystack, 'communicat')) return ['activity_type' => 'Seminar', 'title' => 'Professional Communication Seminar', 'objective' => 'Improve clear, inclusive, and effective professional communication.'];
    if (str_contains($haystack, 'leader') || str_contains($haystack, 'manage')) return ['activity_type' => 'Training', 'title' => 'Academic Leadership and Management Training', 'objective' => 'Develop planning, delegation, coaching, and accountable academic leadership.'];
    if (str_contains($haystack, 'research')) return ['activity_type' => 'Workshop', 'title' => 'Research Capability Development Workshop', 'objective' => 'Improve applied research design, publication readiness, and research mentoring.'];
    return ['activity_type' => 'Intervention', 'title' => 'Targeted Faculty Performance Development Program', 'objective' => 'Address the lowest-scoring competency areas through guided practice, coaching, and follow-up assessment.'];
}

function performance_report_analytics(array $people, ?array $period, array $filters): array
{
    $ids = array_values(array_unique(array_map(static fn($person) => (int)$person['id'], $people)));
    $periodName = (string)($period['period_name'] ?? '');
    $categoryRows = ['form_a' => [], 'form_b' => []];
    if ($ids !== [] && $periodName !== '') {
        $idList = implode(',', array_map('intval', $ids));
        foreach (['form_a' => 'a', 'form_b' => 'b'] as $key => $suffix) {
            $categoryRows[$key] = admin_all(
                "SELECT r.evaluatee_faculty_id AS faculty_id, c.title AS category, r.average_rating AS score, r.factor_weight
                 FROM pmas_form_{$suffix}_category_results r
                 JOIN pmas_form_{$suffix}_categories c ON c.id = r.category_id
                 WHERE r.evaluatee_faculty_id IN ({$idList}) AND r.evaluation_period = :period
                   AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0",
                ['period' => $periodName]
            );
        }
    }
    $aScores = []; $bScores = [];
    foreach ($people as $person) {
        foreach ($categoryRows['form_a'] as $row) if ((int)$row['faculty_id'] === (int)$person['id']) $aScores[] = (float)$row['score'];
        foreach ($categoryRows['form_b'] as $row) if ((int)$row['faculty_id'] === (int)$person['id']) $bScores[] = (float)$row['score'];
    }
    $sources = [
        'form_a' => performance_report_source_analysis('form_a', 'PMAS Form A', $categoryRows['form_a'], $aScores),
        'form_b' => performance_report_source_analysis('form_b', 'PMAS Form B', $categoryRows['form_b'], $bScores),
    ];
    $missing = array_values(array_map(static fn($source) => $source['label'], array_filter($sources, static fn($source) => !$source['available'])));
    $sourceMeans = array_column($sources, 'score');
    $consolidated = $missing === [] ? performance_report_average($sourceMeans) : null;
    $evidence = [];
    foreach ($sources as $source) foreach ($source['improvement_areas'] as $item) $evidence[] = [
        'source' => $source['label'], 'category' => $item['title'], 'score' => $item['score'],
        'trigger' => $item['classification'] === 'weakness' ? 'Score at or below 3.50' : 'Lowest available growth opportunity',
    ];
    usort($evidence, static fn($a, $b) => $a['score'] <=> $b['score']);
    $evidence = array_slice($evidence, 0, 5);
    $recommendation = null;
    if ($missing === []) {
        $activity = performance_report_development_activity($evidence);
        $reasonParts = array_map(static fn($item) => $item['category'] . ' (' . number_format((float)$item['score'], 2) . ', ' . $item['source'] . ')', array_slice($evidence, 0, 3));
        $recommendation = [...$activity,
            'reason' => 'Recommended because the selected-period evidence identifies ' . implode(', ', $reasonParts) . ' as the lowest development priorities.',
            'evidence' => $evidence, 'source' => 'scoped_database_rules', 'generated_at' => date(DATE_ATOM),
        ];
    }
    $distribution = ['Excellent' => 0, 'Very Satisfactory' => 0, 'Satisfactory' => 0, 'Needs Improvement' => 0];
    foreach ($people as $person) if ($person['mean'] !== null) $distribution[performance_report_level((float)$person['mean'])]++;
    return [
        'sources' => $sources,
        'consolidated' => ['available' => $missing === [], 'score' => $consolidated, 'level' => performance_report_level($consolidated), 'required_sources' => ['PMAS Form A', 'PMAS Form B'], 'missing_sources' => $missing],
        'charts' => [
            'source_comparison' => ['labels' => array_column($sources, 'label'), 'values' => $sourceMeans],
            'rating_distribution' => ['labels' => array_keys($distribution), 'values' => array_values($distribution)],
            'categories' => array_values(array_merge($sources['form_a']['categories'], $sources['form_b']['categories'])),
        ],
        'recommendation' => $recommendation,
        'warnings' => $missing === [] ? [] : ['Consolidated recommendation unavailable. Missing completed evidence: ' . implode(', ', $missing) . '.'],
    ];
}

function performance_report_snapshot(array $filters, array $analytics, array $user, bool $regenerate = false): ?array
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS report_ai_snapshots (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,filter_hash CHAR(64) NOT NULL,evidence_hash CHAR(64) NOT NULL,
          filters_json JSON NOT NULL,evidence_json JSON NOT NULL,recommendation_json JSON NULL,source VARCHAR(60) NOT NULL DEFAULT 'scoped_database_rules',
          provider VARCHAR(80) NULL,model VARCHAR(120) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_report_snapshot(user_id,filter_hash,evidence_hash),KEY idx_report_snapshot_lookup(user_id,filter_hash,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $safeFilters = array_intersect_key($filters, array_flip(['report_type','department_id','role','program','faculty_id','period_id']));
        $evidence = ['sources' => $analytics['sources'], 'consolidated' => $analytics['consolidated'], 'charts' => $analytics['charts']];
        $filterHash = hash('sha256', json_encode($safeFilters, JSON_UNESCAPED_UNICODE));
        $evidenceHash = hash('sha256', json_encode($evidence, JSON_UNESCAPED_UNICODE));
        if (!$regenerate) {
            $existing = admin_one('SELECT id, source, provider, model, created_at FROM report_ai_snapshots WHERE user_id=:user_id AND filter_hash=:filter_hash AND evidence_hash=:evidence_hash LIMIT 1', ['user_id'=>(int)$user['id'],'filter_hash'=>$filterHash,'evidence_hash'=>$evidenceHash]);
            if ($existing !== null) return [...$existing, 'filter_hash'=>$filterHash, 'evidence_hash'=>$evidenceHash, 'reused'=>true];
        }
        $stmt = db()->prepare('INSERT INTO report_ai_snapshots(user_id,filter_hash,evidence_hash,filters_json,evidence_json,recommendation_json,source) VALUES(:user_id,:filter_hash,:evidence_hash,:filters,:evidence,:recommendation,:source) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute(['user_id'=>(int)$user['id'],'filter_hash'=>$filterHash,'evidence_hash'=>$evidenceHash,'filters'=>json_encode($safeFilters),'evidence'=>json_encode($evidence),'recommendation'=>json_encode($analytics['recommendation']),'source'=>$analytics['recommendation']['source'] ?? 'unavailable']);
        return ['id'=>(int)db()->lastInsertId(),'filter_hash'=>$filterHash,'evidence_hash'=>$evidenceHash,'source'=>$analytics['recommendation']['source'] ?? 'unavailable','created_at'=>date(DATE_ATOM),'reused'=>false];
    } catch (Throwable $error) {
        error_log('APPRAISIA report snapshot unavailable: ' . $error->getMessage());
        return null;
    }
}
