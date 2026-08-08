<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';
require_once __DIR__ . '/evaluation_cards.php';
require_once __DIR__ . '/evaluation_participation.php';

function dean_departments(int $deanUserId, ?int $evaluationPeriodId = null): array
{
    if ($evaluationPeriodId !== null && $evaluationPeriodId > 0) {
        $periodRows = dipascaf_period_dean_scope($evaluationPeriodId, $deanUserId);
        if ($periodRows !== []) {
            return array_values(array_unique(array_map(
                static fn(array $row): string => (string)($row['department_code'] ?: $row['department_name']),
                $periodRows
            )));
        }
    }
    $departments = admin_all(
        'SELECT department_code FROM departments WHERE dean_user_id = :dean_user_id AND is_active = 1',
        ['dean_user_id' => $deanUserId]
    );

    if ($departments === []) {
        // Fallback: use the dean's user record department from DB
        $deanUser = admin_one(
            'SELECT department FROM users WHERE id = :id',
            ['id' => $deanUserId]
        );
        if ($deanUser !== null && ($deanUser['department'] ?? '') !== '') {
            return [$deanUser['department']];
        }
        // Last resort: list all departments from faculty table
        return array_column(admin_all('SELECT DISTINCT department FROM faculty ORDER BY department'), 'department');
    }

    return array_map(
        fn (array $row): string => $row['department_code'],
        $departments
    );
}

function dean_department_filter_sql(array $departments, string $alias = 'f'): array
{
    if ($departments === []) {
        return ['1=1', []];
    }

    $departmentAliases = [];
    foreach ($departments as $department) {
        $departmentAliases = array_merge($departmentAliases, admin_matching_department_aliases((string) $department));
    }
    $departments = array_values(array_unique(array_filter($departmentAliases)));

    if ($departments === []) {
        return ['1=1', []];
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

function dean_assignments(int $deanUserId): array
{
    admin_ensure_profile_image_column();
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    return admin_all(
        'SELECT p.*, f.full_name, f.full_name AS evaluatee_name, f.email, f.department, f.program_code, f.position_title, f.progress_percent, u.profile_image
         FROM peer_assignments p
         JOIN faculty f ON f.id = p.evaluatee_faculty_id
         LEFT JOIN users u ON u.email = f.email
         WHERE p.evaluator_user_id = :dean_user_id AND p.evaluator_role = "dean"
           AND COALESCE(p.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY FIELD(p.status, "pending", "submitted"), p.assigned_at DESC',
        ['dean_user_id' => $deanUserId]
    );
}

function dean_faculty(array $departments): array
{
    admin_ensure_profile_image_column();
    admin_ensure_faculty_program_schema();

    [$where, $params] = dean_department_filter_sql($departments);
    return admin_all(
        "SELECT f.*, u.profile_image
         FROM faculty f
         LEFT JOIN users u ON u.email = f.email
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head'))
         ORDER BY f.department, f.full_name",
        $params
    );
}

function dean_ai_insights(array $departments): array
{
    admin_ensure_faculty_program_schema();

    [$where, $params] = dean_department_filter_sql($departments);
    return admin_all(
        "SELECT i.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head'))
         ORDER BY program_code, i.created_at DESC",
        $params
    );
}

function dean_interventions(array $departments): array
{
    admin_ensure_faculty_program_schema();

    [$where, $params] = dean_department_filter_sql($departments);
    return admin_all(
        "SELECT p.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM intervention_plans p
         JOIN faculty f ON f.id = p.faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head'))
         ORDER BY program_code, FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
        $params
    );
}

function dean_period_comparison(array $departments): array
{
    [$where, $params] = dean_department_filter_sql($departments, 'f');

    $periodNames = admin_all(
        "SELECT DISTINCT pa.cycle_name
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE $where AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name IS NOT NULL AND pa.cycle_name != ''
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

        $p = array_merge($params, ['period_name' => $periodName]);

        $facultyCount = admin_count(
            "SELECT COUNT(DISTINCT f.id) FROM faculty f WHERE $where AND COALESCE(f.is_archived, 0) = 0",
            $params
        );
        $submitted = admin_count(
            "SELECT COUNT(*) FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE $where AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name AND pa.status = 'submitted'",
            $p
        );
        $pending = admin_count(
            "SELECT COUNT(*) FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE $where AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name AND pa.status = 'pending'",
            $p
        );

        // Average score per period
        $avgScore = admin_one(
            "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
             FROM pmas_form_b_category_results r
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE $where AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name AND r.status = 'completed'",
            $p
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
             WHERE $where AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name
             GROUP BY i.weak_area
             ORDER BY cnt DESC
             LIMIT 3",
            $p
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
            'weak_areas' => array_map(fn($w) => ['area' => $w['weak_area'] ?? '', 'count' => (int) ($w['cnt'] ?? 0)], $weakAreas),
        ];
    }

    return $result;
}

function dean_result_period_filter(string $periodName, string $alias = 'pa'): array
{
    if ($periodName === '') {
        return ['', []];
    }

    return [" AND {$alias}.cycle_name = :period_name", ['period_name' => $periodName]];
}

function dean_program_performance(array $departments, string $periodName = ''): array
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    [$where, $params] = dean_department_filter_sql($departments, 'f');
    [$periodSql, $periodParams] = dean_result_period_filter($periodName, 'pa');
    $params = array_merge($params, $periodParams);

    $assignmentRows = admin_all(
        "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                COUNT(DISTINCT f.id) AS faculty_count,
                COUNT(DISTINCT pa.id) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
         FROM faculty f
         LEFT JOIN peer_assignments pa ON pa.evaluatee_faculty_id = f.id
            AND COALESCE(pa.is_archived, 0) = 0{$periodSql}
         WHERE {$where}
           AND COALESCE(f.is_archived, 0) = 0
         GROUP BY program_code
         ORDER BY program_code",
        $params
    );

    $scoreRows = array_merge(
        dean_category_result_rows($departments, $periodName, 'a', true),
        dean_category_result_rows($departments, $periodName, 'b', true)
    );

    $scoreBuckets = [];
    foreach ($scoreRows as $row) {
        $program = (string) ($row['program_code'] ?? 'Unassigned Program');
        $scoreBuckets[$program] ??= ['score_total' => 0.0, 'weighted_total' => 0.0, 'count' => 0];
        $scoreBuckets[$program]['score_total'] += (float) ($row['average_rating'] ?? 0);
        $scoreBuckets[$program]['weighted_total'] += (float) ($row['weighted_score'] ?? 0);
        $scoreBuckets[$program]['count']++;
    }

    $insightRows = dean_ai_insight_counts($departments);
    $insightBuckets = [];
    foreach ($insightRows as $row) {
        $program = (string) ($row['program_code'] ?? 'Unassigned Program');
        $insightBuckets[$program] = [
            'insight_count' => (int) ($row['insight_count'] ?? 0),
            'weak_area_count' => (int) ($row['weak_area_count'] ?? 0),
            'latest_insight_at' => (string) ($row['latest_insight_at'] ?? ''),
        ];
    }

    $programs = [];
    foreach ($assignmentRows as $row) {
        $program = (string) ($row['program_code'] ?? 'Unassigned Program');
        $scores = $scoreBuckets[$program] ?? ['score_total' => 0.0, 'weighted_total' => 0.0, 'count' => 0];
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted'] ?? 0);
        $pending = (int) ($row['pending'] ?? 0);
        $insights = $insightBuckets[$program] ?? ['insight_count' => 0, 'weak_area_count' => 0, 'latest_insight_at' => ''];
        $programs[] = [
            'program_code' => $program,
            'faculty_count' => (int) ($row['faculty_count'] ?? 0),
            'total_assignments' => $total,
            'submitted' => $submitted,
            'pending' => $pending,
            'overdue' => (int) ($row['overdue'] ?? 0),
            'completion_rate' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0,
            'average_score' => $scores['count'] > 0 ? round($scores['score_total'] / $scores['count'], 2) : null,
            'average_weighted_score' => $scores['count'] > 0 ? round($scores['weighted_total'] / $scores['count'], 4) : null,
            'result_count' => $scores['count'],
            'insight_count' => $insights['insight_count'],
            'weak_area_count' => $insights['weak_area_count'],
            'latest_insight_at' => $insights['latest_insight_at'],
        ];
    }

    return $programs;
}

function dean_period_performance(array $departments): array
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    [$where, $params] = dean_department_filter_sql($departments, 'f');
    $assignmentRows = admin_all(
        "SELECT pa.cycle_name AS evaluation_period,
                COUNT(DISTINCT pa.id) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE {$where}
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.cycle_name, '') != ''
         GROUP BY pa.cycle_name
         ORDER BY pa.cycle_name DESC",
        $params
    );

    $scoreRows = array_merge(
        dean_category_result_rows($departments, '', 'a', true),
        dean_category_result_rows($departments, '', 'b', true)
    );

    $scoreBuckets = [];
    foreach ($scoreRows as $row) {
        $period = (string) ($row['evaluation_period'] ?? '');
        if ($period === '') {
            continue;
        }
        $scoreBuckets[$period] ??= ['score_total' => 0.0, 'count' => 0];
        $scoreBuckets[$period]['score_total'] += (float) ($row['average_rating'] ?? 0);
        $scoreBuckets[$period]['count']++;
    }

    return array_map(static function (array $row) use ($scoreBuckets): array {
        $period = (string) ($row['evaluation_period'] ?? '');
        $scores = $scoreBuckets[$period] ?? ['score_total' => 0.0, 'count' => 0];
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted'] ?? 0);
        return [
            'evaluation_period' => $period,
            'total_assignments' => $total,
            'submitted' => $submitted,
            'pending' => (int) ($row['pending'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'completion_rate' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0,
            'average_score' => $scores['count'] > 0 ? round($scores['score_total'] / $scores['count'], 2) : null,
            'result_count' => $scores['count'],
        ];
    }, $assignmentRows);
}

function dean_program_period_performance(array $departments): array
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    [$where, $params] = dean_department_filter_sql($departments, 'f');
    $assignmentRows = admin_all(
        "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                pa.cycle_name AS evaluation_period,
                COUNT(DISTINCT pa.id) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE {$where}
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.cycle_name, '') != ''
         GROUP BY program_code, pa.cycle_name
         ORDER BY pa.cycle_name DESC, program_code",
        $params
    );

    $resultRows = array_merge(
        dean_category_result_rows($departments, '', 'a', true),
        dean_category_result_rows($departments, '', 'b', true)
    );
    $scoreBuckets = [];
    foreach ($resultRows as $row) {
        $key = (string) ($row['program_code'] ?? 'Unassigned Program') . "\n" . (string) ($row['cycle_name'] ?? $row['evaluation_period'] ?? '');
        $scoreBuckets[$key] ??= ['score_total' => 0.0, 'weighted_total' => 0.0, 'count' => 0];
        $scoreBuckets[$key]['score_total'] += (float) ($row['average_rating'] ?? 0);
        $scoreBuckets[$key]['weighted_total'] += (float) ($row['weighted_score'] ?? 0);
        $scoreBuckets[$key]['count']++;
    }

    $insightRows = admin_all(
        "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                pa.cycle_name AS evaluation_period,
                COUNT(DISTINCT i.id) AS insight_count,
                COUNT(DISTINCT NULLIF(i.weak_area, '')) AS weak_area_count
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN ai_insights i ON i.faculty_id = f.id
         WHERE {$where}
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.cycle_name, '') != ''
         GROUP BY program_code, pa.cycle_name",
        $params
    );
    $insightBuckets = [];
    foreach ($insightRows as $row) {
        $key = (string) ($row['program_code'] ?? 'Unassigned Program') . "\n" . (string) ($row['evaluation_period'] ?? '');
        $insightBuckets[$key] = [
            'insight_count' => (int) ($row['insight_count'] ?? 0),
            'weak_area_count' => (int) ($row['weak_area_count'] ?? 0),
        ];
    }

    return array_map(static function (array $row) use ($scoreBuckets, $insightBuckets): array {
        $program = (string) ($row['program_code'] ?? 'Unassigned Program');
        $period = (string) ($row['evaluation_period'] ?? '');
        $key = $program . "\n" . $period;
        $scores = $scoreBuckets[$key] ?? ['score_total' => 0.0, 'weighted_total' => 0.0, 'count' => 0];
        $insights = $insightBuckets[$key] ?? ['insight_count' => 0, 'weak_area_count' => 0];
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted'] ?? 0);

        return [
            'program_code' => $program,
            'evaluation_period' => $period,
            'total_assignments' => $total,
            'submitted' => $submitted,
            'pending' => (int) ($row['pending'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'completion_rate' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0,
            'average_score' => $scores['count'] > 0 ? round($scores['score_total'] / $scores['count'], 2) : null,
            'average_weighted_score' => $scores['count'] > 0 ? round($scores['weighted_total'] / $scores['count'], 4) : null,
            'result_count' => $scores['count'],
            'insight_count' => $insights['insight_count'],
            'weak_area_count' => $insights['weak_area_count'],
        ];
    }, $assignmentRows);
}

function dean_category_result_rows(array $departments, string $periodName = '', string $form = 'b', bool $includeAll = false): array
{
    $prefix = $form === 'a' ? 'pmas_form_a' : 'pmas_form_b';
    $form === 'a' ? dipascaf_ensure_form_a_schema() : dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();

    [$where, $params] = dean_department_filter_sql($departments, 'f');
    [$periodSql, $periodParams] = dean_result_period_filter($periodName, 'pa');
    $scoreSql = $includeAll ? '' : ' AND r.average_rating <= 3.50';

    return admin_all(
        "SELECT r.evaluatee_faculty_id, r.assignment_id, r.average_rating, r.weighted_score,
                r.factor_weight, r.evaluation_period, r.submitted_at, c.title AS category_title,
                f.full_name AS faculty_name, f.department,
                COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                pa.cycle_name, pa.assignment_type, pa.evaluator_role, pa.status AS assignment_status
         FROM {$prefix}_category_results r
         JOIN {$prefix}_categories c ON c.id = r.category_id
         JOIN peer_assignments pa ON pa.id = r.assignment_id
         JOIN faculty f ON f.id = r.evaluatee_faculty_id
         WHERE {$where}
           AND COALESCE(f.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(r.is_archived, 0) = 0
           AND r.status = 'completed'
           {$scoreSql}{$periodSql}
         ORDER BY r.average_rating ASC, r.submitted_at DESC",
        array_merge($params, $periodParams)
    );
}

function dean_ai_insight_counts(array $departments): array
{
    [$where, $params] = dean_department_filter_sql($departments, 'f');

    return admin_all(
        "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                COUNT(i.id) AS insight_count,
                COUNT(DISTINCT NULLIF(i.weak_area, '')) AS weak_area_count,
                MAX(i.created_at) AS latest_insight_at
         FROM faculty f
         LEFT JOIN ai_insights i ON i.faculty_id = f.id
         WHERE {$where}
           AND COALESCE(f.is_archived, 0) = 0
         GROUP BY program_code
         ORDER BY program_code",
        $params
    );
}

function dean_faculty_performance_profiles(array $departments, string $periodName = ''): array
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    [$where, $params] = dean_department_filter_sql($departments, 'f');
    $facultyRows = admin_all(
        "SELECT f.id, f.full_name, f.email, f.department,
                COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                f.position_title, f.progress_percent
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE {$where}
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head'))
         ORDER BY program_code, f.full_name",
        $params
    );

    $profiles = [];
    foreach ($facultyRows as $row) {
        $profiles[(int) $row['id']] = [
            'faculty_id' => (int) $row['id'],
            'full_name' => (string) ($row['full_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'program_code' => (string) ($row['program_code'] ?? 'Unassigned Program'),
            'position_title' => (string) ($row['position_title'] ?? ''),
            'progress_percent' => (int) ($row['progress_percent'] ?? 0),
            'total_assignments' => 0,
            'submitted' => 0,
            'pending' => 0,
            'completion_rate' => 0,
            'average_score' => null,
            'result_count' => 0,
            'weakest_category' => '',
            'weakest_score' => null,
            'strongest_category' => '',
            'strongest_score' => null,
            'category_scores' => [],
            'ai_weak_area' => '',
            'ai_strength_area' => '',
            'ai_summary' => '',
        ];
    }

    if ($profiles === []) {
        return [];
    }

    $ids = array_keys($profiles);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $periodWhere = $periodName !== '' ? ' AND cycle_name = ?' : '';
    $assignmentRows = admin_all(
        "SELECT evaluatee_faculty_id,
                COUNT(*) AS total_assignments,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
         FROM peer_assignments
         WHERE evaluatee_faculty_id IN ({$placeholders})
           AND COALESCE(is_archived, 0) = 0{$periodWhere}
         GROUP BY evaluatee_faculty_id",
        $periodName !== '' ? array_merge($ids, [$periodName]) : $ids
    );
    foreach ($assignmentRows as $row) {
        $id = (int) ($row['evaluatee_faculty_id'] ?? 0);
        if (!isset($profiles[$id])) {
            continue;
        }
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted'] ?? 0);
        $profiles[$id]['total_assignments'] = $total;
        $profiles[$id]['submitted'] = $submitted;
        $profiles[$id]['pending'] = (int) ($row['pending'] ?? 0);
        $profiles[$id]['completion_rate'] = $total > 0 ? round(($submitted / $total) * 100, 1) : 0;
    }

    $resultRows = array_merge(
        dean_category_result_rows($departments, $periodName, 'a', true),
        dean_category_result_rows($departments, $periodName, 'b', true)
    );
    $scoreBuckets = [];
    foreach ($resultRows as $row) {
        $id = (int) ($row['evaluatee_faculty_id'] ?? 0);
        if (!isset($profiles[$id])) {
            continue;
        }
        $score = (float) ($row['average_rating'] ?? 0);
        $scoreBuckets[$id] ??= ['total' => 0.0, 'count' => 0];
        $scoreBuckets[$id]['total'] += $score;
        $scoreBuckets[$id]['count']++;
        if ($profiles[$id]['weakest_score'] === null || $score < (float) $profiles[$id]['weakest_score']) {
            $profiles[$id]['weakest_score'] = round($score, 2);
            $profiles[$id]['weakest_category'] = (string) ($row['category_title'] ?? '');
        }
        if ($profiles[$id]['strongest_score'] === null || $score > (float) $profiles[$id]['strongest_score']) {
            $profiles[$id]['strongest_score'] = round($score, 2);
            $profiles[$id]['strongest_category'] = (string) ($row['category_title'] ?? '');
        }
        $categoryTitle = (string) ($row['category_title'] ?? 'Uncategorized');
        $profiles[$id]['category_scores'][$categoryTitle] ??= ['total' => 0.0, 'count' => 0];
        $profiles[$id]['category_scores'][$categoryTitle]['total'] += $score;
        $profiles[$id]['category_scores'][$categoryTitle]['count']++;
    }
    foreach ($scoreBuckets as $id => $bucket) {
        $profiles[$id]['average_score'] = $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null;
        $profiles[$id]['result_count'] = $bucket['count'];
    }
    foreach ($profiles as &$profile) {
        $profile['category_scores'] = array_map(
            static fn (string $category, array $bucket): array => [
                'category' => $category,
                'score' => $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null,
            ],
            array_keys($profile['category_scores']),
            array_values($profile['category_scores'])
        );
    }
    unset($profile);

    $insightRows = admin_all(
        "SELECT i.faculty_id, i.weak_area, i.strength_area, i.analysis_summary, i.created_at
         FROM ai_insights i
         WHERE i.faculty_id IN ({$placeholders})
         ORDER BY i.created_at DESC",
        $ids
    );
    foreach ($insightRows as $row) {
        $id = (int) ($row['faculty_id'] ?? 0);
        if (!isset($profiles[$id]) || $profiles[$id]['ai_summary'] !== '') {
            continue;
        }
        $profiles[$id]['ai_weak_area'] = (string) ($row['weak_area'] ?? '');
        $profiles[$id]['ai_strength_area'] = (string) ($row['strength_area'] ?? '');
        $profiles[$id]['ai_summary'] = (string) ($row['analysis_summary'] ?? '');
    }

    return array_values($profiles);
}

function dean_analytics(array $departments, string $periodName = ''): array
{
    $programs = dean_program_performance($departments, $periodName);
    $periods = dean_period_performance($departments);
    $programPeriods = dean_program_period_performance($departments);
    $profiles = dean_faculty_performance_profiles($departments, $periodName);
    if ($periodName !== '') {
        $periodRow = admin_one('SELECT id FROM appraisal_periods WHERE period_name=:name LIMIT 1', ['name'=>$periodName]);
        $participationPeriodId = (int)($periodRow['id'] ?? 0);
        if ($participationPeriodId > 0) {
            $profiles = array_values(array_filter($profiles, static function (array $profile) use ($participationPeriodId): bool {
                $linkedUserId = (int)($profile['user_id'] ?? 0);
                return $linkedUserId <= 0 || !dipascaf_period_user_is_excluded($participationPeriodId, $linkedUserId);
            }));
        }
    }
    $allResults = array_merge(
        dean_category_result_rows($departments, $periodName, 'a', true),
        dean_category_result_rows($departments, $periodName, 'b', true)
    );
    $weakResults = array_merge(
        dean_category_result_rows($departments, $periodName, 'a'),
        dean_category_result_rows($departments, $periodName, 'b')
    );

    $totalAssignments = array_sum(array_column($programs, 'total_assignments'));
    $submitted = array_sum(array_column($programs, 'submitted'));
    $scoreValues = array_values(array_filter(array_column($programs, 'average_score'), static fn ($score): bool => $score !== null));
    $weightedValues = array_values(array_filter(array_map(static fn (array $row): ?float => isset($row['weighted_score']) ? (float) $row['weighted_score'] : null, $allResults), static fn ($score): bool => $score !== null));

    $categoryBuckets = [];
    $ratingDistribution = ['excellent' => 0, 'very_satisfactory' => 0, 'satisfactory' => 0, 'needs_improvement' => 0];
    foreach ($allResults as $row) {
        $category = (string) ($row['category_title'] ?? 'Uncategorized');
        $score = (float) ($row['average_rating'] ?? 0);
        $categoryBuckets[$category] ??= ['category' => $category, 'total' => 0.0, 'count' => 0];
        $categoryBuckets[$category]['total'] += $score;
        $categoryBuckets[$category]['count']++;
        if ($score >= 4.5) {
            $ratingDistribution['excellent']++;
        } elseif ($score >= 3.75) {
            $ratingDistribution['very_satisfactory']++;
        } elseif ($score >= 3.0) {
            $ratingDistribution['satisfactory']++;
        } else {
            $ratingDistribution['needs_improvement']++;
        }
    }
    $categoryScores = array_values(array_map(static fn (array $bucket): array => [
        'category' => $bucket['category'],
        'average_score' => $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null,
        'result_count' => $bucket['count'],
    ], $categoryBuckets));
    usort($categoryScores, static fn (array $a, array $b): int => ((float) ($b['average_score'] ?? 0)) <=> ((float) ($a['average_score'] ?? 0)));
    $lowestCategoryScores = $categoryScores;
    usort($lowestCategoryScores, static fn (array $a, array $b): int => ((float) ($a['average_score'] ?? 99)) <=> ((float) ($b['average_score'] ?? 99)));

    $overallWeightedAverage = $weightedValues !== [] ? round(array_sum($weightedValues) / count($weightedValues), 4) : null;
    $overallAverage = $scoreValues !== [] ? round(array_sum($scoreValues) / count($scoreValues), 2) : null;
    $interpretation = $overallAverage === null
        ? 'No evaluation data available'
        : ($overallAverage >= 4.5 ? 'Excellent' : ($overallAverage >= 3.75 ? 'Very Satisfactory' : ($overallAverage >= 3.0 ? 'Satisfactory' : 'Needs Improvement')));

    return [
        'summary' => [
            'program_count' => count($programs),
            'faculty_count' => count($profiles),
            'total_assignments' => $totalAssignments,
            'submitted' => $submitted,
            'pending' => array_sum(array_column($programs, 'pending')),
            'overdue' => array_sum(array_column($programs, 'overdue')),
            'completion_rate' => $totalAssignments > 0 ? round(($submitted / $totalAssignments) * 100, 1) : 0,
            'average_score' => $overallAverage,
            'overall_weighted_average' => $overallWeightedAverage,
            'interpretation' => $interpretation,
            'weak_result_count' => count($weakResults),
        ],
        'programs' => $programs,
        'periods' => $periods,
        'programPeriods' => $programPeriods,
        'facultyProfiles' => $profiles,
        'weakResults' => $weakResults,
        'categoryScores' => $categoryScores,
        'highestRatedAreas' => array_slice($categoryScores, 0, 3),
        'lowestRatedAreas' => array_slice($lowestCategoryScores, 0, 3),
        'ratingDistribution' => $ratingDistribution,
        'generatedSummary' => $overallAverage === null
            ? 'No evaluation data available for the selected appraisal period.'
            : 'The department recorded an average performance rating of ' . number_format((float) $overallAverage, 2) . ' with ' . $interpretation . ' interpretation. Priority improvement areas are based on the lowest category scores and completed evaluations for the selected appraisal period.',
    ];
}

function dean_summary(array $departments): array
{
    admin_ensure_faculty_program_schema();

    [$where, $params] = dean_department_filter_sql($departments);
    $facultyCount = admin_count("SELECT COUNT(*) FROM faculty f WHERE $where AND COALESCE(f.is_archived, 0) = 0", $params);
    $submitted = admin_count(
        "SELECT COUNT(*) FROM peer_assignments p JOIN faculty f ON f.id = p.evaluatee_faculty_id
         WHERE $where AND COALESCE(p.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND p.evaluator_role = 'dean' AND p.status = 'submitted'",
        $params
    );
    $pending = admin_count(
        "SELECT COUNT(*) FROM peer_assignments p JOIN faculty f ON f.id = p.evaluatee_faculty_id
         WHERE $where AND COALESCE(p.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND p.evaluator_role = 'dean' AND p.status = 'pending'",
        $params
    );
    $weakAreas = admin_all(
        "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code, i.weak_area, COUNT(*) AS weak_count
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head'))
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
