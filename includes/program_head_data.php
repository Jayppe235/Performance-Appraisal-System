<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';

function program_head_programs(int $programHeadUserId, ?int $evaluationPeriodId = null): array
{
    if ($evaluationPeriodId !== null && $evaluationPeriodId > 0) {
        require_once __DIR__ . '/evaluation_participation.php';
        $periodPrograms = dipascaf_period_program_head_programs($evaluationPeriodId, $programHeadUserId, false);
        if ($periodPrograms !== []) return $periodPrograms;
    }
    return admin_all(
        'SELECT p.*, d.department_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.program_head_user_id = :program_head_user_id AND p.is_active = 1
         ORDER BY p.program_name',
        ['program_head_user_id' => $programHeadUserId]
    );
}

function program_head_departments(int $programHeadUserId, ?int $evaluationPeriodId = null): array
{
    $programs = program_head_programs($programHeadUserId, $evaluationPeriodId);

    if ($programs === []) {
        // Fallback to the user's department field when no programs are mapped
        $user = admin_one(
            'SELECT department FROM users WHERE id = :id AND role = "program_head" LIMIT 1',
            ['id' => $programHeadUserId]
        );
        $department = trim((string) ($user['department'] ?? ''));
        if ($department !== '') {
            return [admin_normalize_department_name($department)];
        }
        return [];
    }

    return array_map(
        fn (array $program): string => admin_normalize_department_name((string) $program['department_name']),
        $programs
    );
}

function program_head_filter_sql(array $departments, string $alias = 'f'): array
{
    if ($departments === []) {
        // An unmapped Program Head must never fall back to institution-wide data.
        return ['1=0', []];
    }

    $parts = [];
    $params = [];
    foreach ($departments as $index => $department) {
        $key = 'department_' . $index;
        $parts[] = "$alias.department = :$key";
        $params[$key] = $department;
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function program_head_program_filter_sql(array $programs, array $departments, string $alias = 'f'): array
{
    $programCodes = array_values(array_filter(array_map(
        fn (array $program): string => trim((string) ($program['program_code'] ?? '')),
        $programs
    )));

    if ($programCodes !== []) {
        $parts = [];
        $params = [];
        foreach ($programCodes as $index => $programCode) {
            $key = 'program_code_' . $index;
            $parts[] = "$alias.program_code = :$key";
            $params[$key] = $programCode;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    return program_head_filter_sql($departments, $alias);
}

function program_head_assignments(int $programHeadUserId): array
{
    admin_ensure_archive_schema();
    return admin_all(
        'SELECT p.*, f.full_name AS evaluatee_name, f.department, f.position_title
         FROM peer_assignments p
         JOIN faculty f ON f.id = p.evaluatee_faculty_id
         WHERE p.evaluator_user_id = :program_head_user_id AND p.evaluator_role = "program_head"
           AND COALESCE(p.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY FIELD(p.status, "pending", "submitted"), p.assigned_at DESC',
        ['program_head_user_id' => $programHeadUserId]
    );
}

function program_head_faculty(array $departments, array $programs = []): array
{
    admin_ensure_faculty_program_schema();
    [$where, $params] = program_head_program_filter_sql($programs, $departments);
    return admin_all(
        "SELECT f.*
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role = 'teacher')
         ORDER BY f.department, f.full_name",
        $params
    );
}

function program_head_ai_insights(array $departments, array $programs = [], int $programHeadUserId = 0): array
{
    admin_ensure_faculty_program_schema();
    [$where, $params] = program_head_program_filter_sql($programs, $departments);

    return admin_all(
        "SELECT i.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role = 'teacher')
         ORDER BY program_code, i.created_at DESC",
        $params
    );
}

function program_head_interventions(array $departments, array $programs = []): array
{
    admin_ensure_faculty_program_schema();
    [$where, $params] = program_head_program_filter_sql($programs, $departments);
    return admin_all(
        "SELECT p.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM intervention_plans p
         JOIN faculty f ON f.id = p.faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role = 'teacher')
         ORDER BY program_code, FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
        $params
    );
}

function program_head_period_comparison(array $departments, array $programs = []): array
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();
    [$where, $params] = program_head_program_filter_sql($programs, $departments, 'f');

    $periodNames = admin_all(
        "SELECT DISTINCT pa.cycle_name
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role = 'teacher')
           AND pa.cycle_name IS NOT NULL
           AND pa.cycle_name != ''
         ORDER BY pa.cycle_name",
        $params
    );

    $result = [];
    $previousScore = null;

    foreach ($periodNames as $periodRow) {
        $periodName = (string) ($periodRow['cycle_name'] ?? '');
        if ($periodName === '') {
            continue;
        }

        $periodParams = $params + ['period_name' => $periodName];

        $facultyCount = admin_count(
            "SELECT COUNT(DISTINCT f.id)
             FROM faculty f
             LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
             WHERE $where
               AND COALESCE(f.is_archived, 0) = 0
               AND (u.role IS NULL OR u.role = 'teacher')",
            $params
        );
        $submitted = admin_count(
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
             WHERE $where
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(f.is_archived, 0) = 0
               AND (u.role IS NULL OR u.role = 'teacher')
               AND pa.cycle_name = :period_name
               AND pa.status = 'submitted'",
            $periodParams
        );
        $pending = admin_count(
            "SELECT COUNT(*)
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
             WHERE $where
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(f.is_archived, 0) = 0
               AND (u.role IS NULL OR u.role = 'teacher')
               AND pa.cycle_name = :period_name
               AND pa.status = 'pending'",
            $periodParams
        );

        $avgScore = admin_one(
            "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
             FROM pmas_form_b_category_results r
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
             WHERE $where
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(f.is_archived, 0) = 0
               AND (u.role IS NULL OR u.role = 'teacher')
               AND pa.cycle_name = :period_name
               AND r.status = 'completed'",
            $periodParams
        );
        $score = $avgScore !== null ? (float) ($avgScore['avg_score'] ?? 0) : null;

        $change = null;
        if ($previousScore !== null && $score !== null) {
            $change = round($score - $previousScore, 2);
        }
        $previousScore = $score;

        $weakAreas = admin_all(
            "SELECT i.weak_area, COUNT(*) AS cnt
             FROM ai_insights i
             JOIN peer_assignments pa ON pa.evaluatee_faculty_id = i.faculty_id
             JOIN faculty f ON f.id = i.faculty_id
             LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
             WHERE $where
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(f.is_archived, 0) = 0
               AND (u.role IS NULL OR u.role = 'teacher')
               AND pa.cycle_name = :period_name
             GROUP BY i.weak_area
             ORDER BY cnt DESC
             LIMIT 3",
            $periodParams
        );

        $total = $submitted + $pending;

        $result[] = [
            'period_name' => $periodName,
            'faculty_count' => $facultyCount,
            'submitted' => $submitted,
            'pending' => $pending,
            'total' => $total,
            'completion_rate' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0,
            'average_score' => $score,
            'score_change' => $change,
            'weak_areas' => array_map(fn ($w) => ['area' => $w['weak_area'] ?? '', 'count' => (int) ($w['cnt'] ?? 0)], $weakAreas),
        ];
    }

    return $result;
}

function program_head_summary(int $programHeadUserId, array $departments, array $programs = []): array
{
    admin_ensure_faculty_program_schema();
    [$where, $params] = program_head_program_filter_sql($programs, $departments);
    $facultyCount = admin_count("SELECT COUNT(*) FROM faculty f WHERE $where AND COALESCE(f.is_archived, 0) = 0", $params);
    $submitted = admin_count(
        'SELECT COUNT(*) FROM peer_assignments
         WHERE evaluator_user_id = :program_head_user_id AND evaluator_role = "program_head" AND status = "submitted" AND COALESCE(is_archived, 0) = 0',
        ['program_head_user_id' => $programHeadUserId]
    );
    $pending = admin_count(
        'SELECT COUNT(*) FROM peer_assignments
         WHERE evaluator_user_id = :program_head_user_id AND evaluator_role = "program_head" AND status = "pending" AND COALESCE(is_archived, 0) = 0',
        ['program_head_user_id' => $programHeadUserId]
    );
    $weakAreas = admin_all(
        "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code, i.weak_area, COUNT(*) AS weak_count
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role = 'teacher')
         GROUP BY program_code, i.weak_area
         ORDER BY program_code, weak_count DESC",
        $params
    );

    return [
        'facultyCount' => $facultyCount,
        'submitted' => $submitted,
        'pending' => $pending,
        'weakAreas' => $weakAreas,
    ];
}
