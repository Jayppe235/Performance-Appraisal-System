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

function performance_report_metadata(): array
{
    return [
        'departments' => admin_all('SELECT id, department_code AS code, department_name AS name, dean_user_id, logo_image FROM departments WHERE is_active = 1 ORDER BY department_name'),
        'programs' => admin_all('SELECT id, department_id, program_code AS code, program_name AS name FROM programs WHERE is_active = 1 ORDER BY program_name'),
        'periods' => admin_all('SELECT id, period_name, school_year, date_start, date_end, status FROM appraisal_periods ORDER BY date_start DESC, id DESC'),
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
    $filters['report_type'] = 'department';
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
    $sort = (string) ($filters['sort'] ?? 'name');

    $department = $departmentId > 0 ? admin_one('SELECT d.*, u.full_name AS dean_name FROM departments d LEFT JOIN users u ON u.id = d.dean_user_id WHERE d.id = :id', ['id' => $departmentId]) : null;
    $period = $periodId > 0 ? admin_one('SELECT * FROM appraisal_periods WHERE id = :id', ['id' => $periodId]) : null;
    $where = ['COALESCE(f.is_archived, 0) = 0'];
    $params = [];
    if ($department !== null) {
        $where[] = '(f.department = :department_name OR f.department = :department_code)';
        $params['department_name'] = $department['department_name'];
        $params['department_code'] = $department['department_code'];
    }
    if ($role !== '') {
        $where[] = 'COALESCE(u.role, "teacher") = :role';
        $params['role'] = $role;
    }
    if ($program !== '') {
        $where[] = 'f.program_code = :program';
        $params['program'] = $program;
    } elseif ($allowedProgramCodes !== []) {
        $programParts = [];
        foreach ($allowedProgramCodes as $index => $code) {
            $key = 'allowed_program_' . $index;
            $programParts[] = ':' . $key;
            $params[$key] = $code;
        }
        $where[] = 'UPPER(f.program_code) IN (' . implode(',', $programParts) . ')';
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
        'SELECT f.id, f.full_name, f.department, f.program_code, COALESCE(u.role, "teacher") AS user_role,
                p.id AS assignment_id, p.assignment_type, p.evaluator_role, r.score
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id
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
        if ($row['score'] === null) continue;
        $score = (float) $row['score'];
        $source = $row['assignment_type'] === 'self' ? 'self'
            : ($row['assignment_type'] === 'peer' ? 'peer'
                : (in_array($row['evaluator_role'], ['vpaa', 'dean'], true) ? 'head'
                    : ($row['evaluator_role'] === 'program_head' ? 'phsc' : 'peer')));
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
        'signatory' => $department['dean_name'] ?? null,
        'generated_by' => current_user()['full_name'] ?? 'Authorized User',
        'generated_at' => date(DATE_ATOM),
    ];
}
