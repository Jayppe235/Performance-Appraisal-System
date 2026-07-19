<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';
// evaluation_cards.php is lazy-loaded inside vpaa_ensure_dean_assignments()
// to prevent its PHP parse errors from poisoning API requests that only
// need vpaa_data.php helpers (e.g., GET /api/people.php).

function vpaa_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        db()->exec("ALTER TABLE users MODIFY role ENUM('admin_hr', 'vpaa', 'dean', 'program_head', 'teacher') NOT NULL");
        db()->exec("ALTER TABLE peer_assignments MODIFY evaluator_role ENUM('vpaa', 'dean', 'program_head', 'teacher') NOT NULL");
        db()->exec("ALTER TABLE evaluation_rules MODIFY evaluator_role ENUM('vpaa', 'dean', 'program_head', 'teacher') NOT NULL");
        db()->exec(
            "CREATE TABLE IF NOT EXISTS vpaa_departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vpaa_user_id INT NOT NULL,
                department_code VARCHAR(120) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_vpaa_department (vpaa_user_id, department_code),
                CONSTRAINT fk_vpaa_department_user FOREIGN KEY (vpaa_user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
    } catch (Throwable) {
    }
}

function vpaa_ensure_dean_assignments(int $vpaaUserId): void
{
    require_once __DIR__ . '/evaluation_cards.php';
    $departments = vpaa_departments($vpaaUserId);
    if ($departments === []) {
        return;
    }

    $conditions = [];
    $params = [];
    foreach (array_values($departments) as $index => $department) {
        $codeKey = 'department_code_' . $index;
        $nameKey = 'department_name_' . $index;
        $userKey = 'user_department_' . $index;
        $conditions[] = "(d.department_code = :$codeKey OR d.department_name = :$nameKey OR du.department = :$userKey)";
        $params[$codeKey] = $department;
        $params[$nameKey] = admin_normalize_department_name((string) $department);
        $params[$userKey] = $department;
    }

    $deans = admin_all(
        "SELECT DISTINCT du.id, du.full_name
         FROM departments d
         JOIN users du ON du.id = d.dean_user_id
         WHERE d.is_active = 1
           AND du.role = 'dean'
           AND du.is_active = 1
           AND (" . implode(' OR ', $conditions) . ")",
        $params
    );

    if ($deans === []) {
        return;
    }

    $cycleName = dipascaf_current_cycle_name();
    $deadline = dipascaf_evaluation_deadline();
    $insert = db()->prepare(
        "INSERT IGNORE INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES (?, ?, ?, 'vpaa', 'dean', 'admin', 'pending', NOW(), ?)"
    );

    foreach ($deans as $dean) {
        $deanUserId = (int) ($dean['id'] ?? 0);
        if ($deanUserId === 0 || $deanUserId === $vpaaUserId) {
            continue;
        }

        $deanFacultyId = dipascaf_ensure_leadership_faculty_record($deanUserId, 'Dean');
        if ($deanFacultyId === 0) {
            continue;
        }

        $insert->execute([$cycleName, $vpaaUserId, $deanFacultyId, $deadline]);
    }
}

function vpaa_departments(int $userId): array
{
    vpaa_ensure_schema();
    admin_ensure_faculty_program_schema();

    $mapped = admin_all(
        'SELECT department_code FROM vpaa_departments WHERE vpaa_user_id = :user_id ORDER BY department_code',
        ['user_id' => $userId]
    );

    if ($mapped !== []) {
        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['department_code'], $mapped)));
    }

    $user = admin_one('SELECT department FROM users WHERE id = :id', ['id' => $userId]);
    $departmentValue = trim((string) ($user['department'] ?? ''));
    if ($departmentValue !== '') {
        $parts = array_filter(array_map('trim', preg_split('/[,;|]/', $departmentValue) ?: []));
        if ($parts !== []) {
            return array_values(array_unique($parts));
        }
    }

    return array_column(admin_all('SELECT department_code FROM departments WHERE is_active = 1 ORDER BY department_name'), 'department_code');
}

function vpaa_sync_departments_for_user(int $userId, string|array $departments): void
{
    vpaa_ensure_schema();

    $values = is_array($departments)
        ? $departments
        : preg_split('/[,;|]/', $departments);
    $values = array_values(array_unique(array_filter(array_map(
        static fn ($department): string => trim((string) $department),
        $values ?: []
    ))));

    db()->prepare('DELETE FROM vpaa_departments WHERE vpaa_user_id = :user_id')
        ->execute(['user_id' => $userId]);

    if ($values === []) {
        return;
    }

    $insert = db()->prepare(
        'INSERT IGNORE INTO vpaa_departments (vpaa_user_id, department_code)
         VALUES (:user_id, :department_code)'
    );

    foreach ($values as $department) {
        $record = admin_one(
            'SELECT department_code
             FROM departments
             WHERE is_active = 1 AND (department_code = :department OR department_name = :department)
             LIMIT 1',
            ['department' => $department]
        );

        $insert->execute([
            'user_id' => $userId,
            'department_code' => (string) ($record['department_code'] ?? $department),
        ]);
    }
}

function vpaa_department_filter_sql(array $departments, string $alias = 'f'): array
{
    if ($departments === []) {
        return ['1 = 0', []];
    }

    $parts = [];
    $params = [];
    foreach (array_values($departments) as $index => $department) {
        $codeKey = 'vpaa_department_code_' . $index;
        $nameKey = 'vpaa_department_name_' . $index;
        $parts[] = "($alias.department = :$codeKey OR $alias.department = :$nameKey)";
        $params[$codeKey] = $department;
        $params[$nameKey] = admin_normalize_department_name((string) $department);
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function vpaa_assignments(array $departments, string $periodName = ''): array
{
    admin_ensure_archive_schema();
    [$where, $params] = vpaa_department_filter_sql($departments, 'f');
    $periodSql = '';
    if ($periodName !== '') {
        $periodSql = ' AND pa.cycle_name = :period_name';
        $params['period_name'] = $periodName;
    }

    return admin_all(
        "SELECT pa.*, f.full_name AS faculty_name, f.department, f.program_code, f.position_title,
                u.full_name AS evaluator_name, es.submitted_at,
                ROUND((
                    COALESCE(es.communication_score, 0) +
                    COALESCE(es.teaching_score, 0) +
                    COALESCE(es.classroom_management_score, 0) +
                    COALESCE(es.job_knowledge_score, 0)
                ) / NULLIF(
                    (es.communication_score IS NOT NULL) +
                    (es.teaching_score IS NOT NULL) +
                    (es.classroom_management_score IS NOT NULL) +
                    (es.job_knowledge_score IS NOT NULL),
                    0
                ), 2) AS average_score
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = pa.evaluator_user_id
         LEFT JOIN evaluation_submissions es ON es.assignment_id = pa.id
         WHERE $where
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           $periodSql
         ORDER BY f.department, FIELD(pa.status, 'pending', 'submitted'), pa.deadline ASC, pa.assigned_at DESC",
        $params
    );
}

function vpaa_faculty(array $departments): array
{
    [$where, $params] = vpaa_department_filter_sql($departments, 'f');
    return admin_all(
        "SELECT f.* FROM faculty f WHERE $where AND COALESCE(f.is_archived, 0) = 0 ORDER BY f.department, f.full_name",
        $params
    );
}

function vpaa_weak_areas(array $departments): array
{
    [$where, $params] = vpaa_department_filter_sql($departments, 'f');
    return admin_all(
        "SELECT i.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY i.created_at DESC",
        $params
    );
}

function vpaa_interventions(array $departments): array
{
    [$where, $params] = vpaa_department_filter_sql($departments, 'f');
    return admin_all(
        "SELECT p.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
         FROM intervention_plans p
         JOIN faculty f ON f.id = p.faculty_id
         WHERE $where
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
        $params
    );
}

function vpaa_period_comparison(array $departments): array
{
    [$where, $params] = vpaa_department_filter_sql($departments, 'f');

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

        $assignments = admin_all(
            "SELECT pa.*, f.full_name AS faculty_name
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE $where AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0 AND pa.cycle_name = :period_name
             ORDER BY pa.assigned_at",
            $p
        );

        $total = count($assignments);
        $completed = count(array_filter($assignments, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'submitted'));
        $pending = count(array_filter($assignments, static fn(array $row): bool => (string) ($row['status'] ?? '') !== 'submitted'));

        $scores = [];
        foreach ($assignments as $a) {
            // Try to get category result scores
            $scoreRow = admin_one(
                "SELECT ROUND(AVG(average_rating), 2) AS avg_score
                 FROM pmas_form_b_category_results
                 WHERE assignment_id = :assignment_id AND status = 'completed' AND COALESCE(is_archived, 0) = 0",
                ['assignment_id' => (int) ($a['id'] ?? 0)]
            );
            if ($scoreRow !== null && ($scoreRow['avg_score'] ?? null) !== null) {
                $scores[] = (float) $scoreRow['avg_score'];
            }
        }
        $avgScore = $scores !== [] ? round(array_sum($scores) / count($scores), 2) : null;

        $change = null;
        if ($previousScore !== null && $avgScore !== null) {
            $change = round($avgScore - $previousScore, 2);
        }
        $previousScore = $avgScore;

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

        $result[] = [
            'period_name' => $periodName,
            'total_assignments' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'average_score' => $avgScore,
            'score_change' => $change,
            'weak_areas' => array_map(fn($w) => ['area' => $w['weak_area'] ?? '', 'count' => (int) ($w['cnt'] ?? 0)], $weakAreas),
        ];
    }

    return $result;
}

function vpaa_summary(array $departments): array
{
    $assignments = vpaa_assignments($departments);
    $interventions = vpaa_interventions($departments);
    $weakAreas = vpaa_weak_areas($departments);

    $total = count($assignments);
    $completed = count(array_filter($assignments, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'submitted'));
    $pending = count(array_filter($assignments, static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'submitted'));
    $overdue = count(array_filter($assignments, static function (array $row): bool {
        $deadline = (string) ($row['deadline'] ?? '');
        return (string) ($row['status'] ?? '') !== 'submitted'
            && $deadline !== ''
            && strtotime($deadline) !== false
            && strtotime($deadline) < strtotime(date('Y-m-d'));
    }));
    $scores = array_values(array_filter(array_map(static fn (array $row): ?float => isset($row['average_score']) ? (float) $row['average_score'] : null, $assignments), static fn (?float $score): bool => $score !== null && $score > 0));
    $average = $scores === [] ? null : round(array_sum($scores) / count($scores), 2);

    return [
        'totalEvaluations' => $total,
        'pendingEvaluations' => $pending,
        'completedEvaluations' => $completed,
        'overdueEvaluations' => $overdue,
        'interventionPlans' => count($interventions),
        'averageFacultyRating' => $average,
        'weakAreaCount' => count($weakAreas),
        'completionRate' => $total > 0 ? round(($completed / $total) * 100) : 0,
    ];
}

function vpaa_department_performance(array $departments): array
{
    $assignments = vpaa_assignments($departments);
    $weakAreas = vpaa_weak_areas($departments);
    $rows = [];

    foreach ($departments as $department) {
        $deptAssignments = array_values(array_filter($assignments, static fn (array $row): bool => (string) ($row['department'] ?? '') === (string) $department || admin_normalize_department_name((string) ($row['department'] ?? '')) === admin_normalize_department_name((string) $department)));
        $submitted = count(array_filter($deptAssignments, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'submitted'));
        $scores = array_values(array_filter(array_map(static fn (array $row): ?float => isset($row['average_score']) ? (float) $row['average_score'] : null, $deptAssignments), static fn (?float $score): bool => $score !== null && $score > 0));
        $weakCount = count(array_filter($weakAreas, static fn (array $row): bool => (string) ($row['department'] ?? '') === (string) $department || admin_normalize_department_name((string) ($row['department'] ?? '')) === admin_normalize_department_name((string) $department)));
        $total = count($deptAssignments);

        $rows[] = [
            'department' => (string) $department,
            'assigned' => $total,
            'submitted' => $submitted,
            'completion' => $total > 0 ? round(($submitted / $total) * 100) : 0,
            'averageRating' => $scores === [] ? null : round(array_sum($scores) / count($scores), 2),
            'weakAreaCount' => $weakCount,
        ];
    }

    usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['department'], (string) $b['department']));
    return $rows;
}

function vpaa_latest_period(): ?array
{
    return admin_one("SELECT * FROM appraisal_periods ORDER BY FIELD(status, 'open', 'draft', 'closed'), date_start DESC, id DESC LIMIT 1");
}

function vpaa_recommendation_seminar(string $category): string
{
    $key = strtolower($category);

    return match (true) {
        str_contains($key, 'communication') => 'Communication Skills and Professional Feedback Seminar',
        str_contains($key, 'teaching') || str_contains($key, 'instruction') || str_contains($key, 'learning') || str_contains($key, 'pedagogy') => 'Teaching Strategies and Outcomes-Based Education Seminar',
        str_contains($key, 'classroom') || str_contains($key, 'learner') || str_contains($key, 'engagement') => 'Classroom Management and Learner Engagement Seminar',
        str_contains($key, 'knowledge') || str_contains($key, 'competence') || str_contains($key, 'expertise') || str_contains($key, 'mastery') => 'Subject Mastery and Professional Competence Seminar',
        str_contains($key, 'leadership') || str_contains($key, 'administrative') || str_contains($key, 'management') => 'Academic Leadership and Administrative Effectiveness Seminar',
        str_contains($key, 'technology') || str_contains($key, 'digital') || str_contains($key, 'innovation') => 'Educational Technology and Innovation Workshop',
        str_contains($key, 'initiative') || str_contains($key, 'resourcefulness') || str_contains($key, 'creativity') => 'Innovation and Resourcefulness Workshop',
        str_contains($key, 'institutional') || str_contains($key, 'values') || str_contains($key, 'commitment') => 'Institutional Commitment and Values Alignment Session',
        str_contains($key, 'interpersonal') || str_contains($key, 'teamwork') || str_contains($key, 'collaboration') => 'Team Collaboration and Interpersonal Sensitivity Seminar',
        str_contains($key, 'attendance') || str_contains($key, 'punctuality') || str_contains($key, 'professionalism') => 'Professional Work Habits and Time Management Workshop',
        str_contains($key, 'decorum') || str_contains($key, 'ethics') || str_contains($key, 'professional') => 'Professional Ethics and Decorum Refresher Course',
        str_contains($key, 'flexibility') || str_contains($key, 'adaptability') => 'Adaptability and Change Management Seminar',
        str_contains($key, 'research') || str_contains($key, 'publication') || str_contains($key, 'scholarship') => 'Research and Scholarly Publication Workshop',
        str_contains($key, 'curriculum') || str_contains($key, 'syllabus') || str_contains($key, 'obe') || str_contains($key, 'outcome') => 'Curriculum Design and Outcomes-Based Education Seminar',
        str_contains($key, 'assessment') || str_contains($key, 'grading') || str_contains($key, 'evaluation') => 'Assessment Strategies and Grading Rubrics Workshop',
        str_contains($key, 'student') || str_contains($key, 'advising') || str_contains($key, 'mentoring') => 'Student Advising and Mentoring Best Practices Seminar',
        str_contains($key, 'community') || str_contains($key, 'extension') || str_contains($key, 'outreach') => 'Community Engagement and Extension Program Planning Workshop',
        default => 'Targeted Faculty Development Program on ' . $category,
    };
}
