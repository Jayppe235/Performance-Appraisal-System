<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';
require_once __DIR__ . '/evaluation_participation.php';
require_once __DIR__ . '/evaluation_cards.php';
require_once __DIR__ . '/evaluation_period.php';
require_once __DIR__ . '/notifications.php';

function dipascaf_ensure_peer_evaluation_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;
    admin_ensure_archive_schema();

    db()->exec("
        CREATE TABLE IF NOT EXISTS peer_evaluation_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            peer_assignment_id INT NULL,
            evaluator_id INT NOT NULL,
            evaluatee_id INT NOT NULL,
            evaluatee_faculty_id INT NULL,
            department_id INT NULL,
            evaluation_period_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'completed', 'overdue') NOT NULL DEFAULT 'pending',
            locked_at DATETIME NULL,
            regenerated_from_id INT NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME NULL,
            archived_by INT NULL,
            UNIQUE KEY uq_peer_eval_evaluator_period (evaluator_id, evaluation_period_id),
            UNIQUE KEY uq_peer_eval_pair_period (evaluator_id, evaluatee_id, evaluation_period_id),
            KEY idx_peer_eval_evaluatee (evaluatee_id),
            KEY idx_peer_eval_department_period (department_id, evaluation_period_id),
            KEY idx_peer_eval_status (status),
            CONSTRAINT fk_peer_eval_assignment
                FOREIGN KEY (peer_assignment_id) REFERENCES peer_assignments(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_peer_eval_evaluator
                FOREIGN KEY (evaluator_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_peer_eval_evaluatee
                FOREIGN KEY (evaluatee_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_peer_eval_faculty
                FOREIGN KEY (evaluatee_faculty_id) REFERENCES faculty(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_peer_eval_department
                FOREIGN KEY (department_id) REFERENCES departments(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_peer_eval_period
                FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id)
                ON DELETE CASCADE
        )
    ");

    $duplicateEvaluateeIndex = admin_one(
        "SHOW INDEX FROM peer_evaluation_assignments WHERE Key_name = 'uq_peer_eval_evaluatee_period'"
    );
    if ($duplicateEvaluateeIndex !== null) {
        $plainEvaluateeIndex = admin_one(
            "SHOW INDEX FROM peer_evaluation_assignments WHERE Key_name = 'idx_peer_eval_evaluatee'"
        );
        if ($plainEvaluateeIndex === null) {
            db()->exec('ALTER TABLE peer_evaluation_assignments ADD INDEX idx_peer_eval_evaluatee (evaluatee_id)');
        }
        db()->exec('ALTER TABLE peer_evaluation_assignments DROP INDEX uq_peer_eval_evaluatee_period');
    }

    foreach ([
        'is_archived' => 'ALTER TABLE peer_evaluation_assignments ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0',
        'archived_at' => 'ALTER TABLE peer_evaluation_assignments ADD COLUMN archived_at DATETIME NULL',
        'archived_by' => 'ALTER TABLE peer_evaluation_assignments ADD COLUMN archived_by INT NULL',
    ] as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM peer_evaluation_assignments LIKE '{$column}'") === null) {
            db()->exec($sql);
        }
    }
}

function dipascaf_peer_eligible_users(bool $includeProgramHeads = true, array $departmentScope = [], ?int $evaluationPeriodId = null): array
{
    $roles = $includeProgramHeads ? "'teacher', 'program_head'" : "'teacher'";
    $params = [];
    $scopeWhere = '';
    $unassignedOnly = !empty($departmentScope['unassigned_only']);

    $departmentIds = array_values(array_filter(array_map('intval', $departmentScope['department_ids'] ?? [])));
    $departments = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $departmentScope['departments'] ?? []
    ), static fn (string $value): bool => $value !== '')));

    $scopeParts = [];
    if ($departmentIds !== []) {
        $keys = [];
        foreach ($departmentIds as $index => $departmentId) {
            $key = 'scope_department_id_' . $index;
            $keys[] = ':' . $key;
            $params[$key] = $departmentId;
        }
        $scopeParts[] = 'd.id IN (' . implode(',', $keys) . ')';
    }

    if ($departments !== []) {
        $userKeys = [];
        $facultyKeys = [];
        $codeKeys = [];
        $nameKeys = [];
        foreach ($departments as $index => $department) {
            $userKey = 'scope_user_department_' . $index;
            $facultyKey = 'scope_faculty_department_' . $index;
            $codeKey = 'scope_department_code_' . $index;
            $nameKey = 'scope_department_name_' . $index;
            $userKeys[] = ':' . $userKey;
            $facultyKeys[] = ':' . $facultyKey;
            $codeKeys[] = ':' . $codeKey;
            $nameKeys[] = ':' . $nameKey;
            $params[$userKey] = $department;
            $params[$facultyKey] = $department;
            $params[$codeKey] = $department;
            $params[$nameKey] = $department;
        }
        $scopeParts[] = 'u.department IN (' . implode(',', $userKeys) . ')';
        $scopeParts[] = 'f.department IN (' . implode(',', $facultyKeys) . ')';
        $scopeParts[] = 'd.department_code IN (' . implode(',', $codeKeys) . ')';
        $scopeParts[] = 'd.department_name IN (' . implode(',', $nameKeys) . ')';
    }

    if ($unassignedOnly) {
        $scopeWhere = " AND d.id IS NULL
            AND (u.department IS NULL OR TRIM(u.department) = '' OR LOWER(TRIM(u.department)) IN ('general','unassigned','unassigned department'))
            AND (f.department IS NULL OR TRIM(f.department) = '' OR LOWER(TRIM(f.department)) IN ('general','unassigned','unassigned department'))";
    } elseif ($scopeParts !== []) {
        $scopeWhere = ' AND (' . implode(' OR ', $scopeParts) . ')';
    }

    dipascaf_ensure_period_participation_schema();
    if ($evaluationPeriodId !== null && $evaluationPeriodId > 0) {
        $params['participation_period_id'] = $evaluationPeriodId;
        $scopeWhere .= " AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp WHERE epp.evaluation_period_id=:participation_period_id AND epp.user_id=u.id AND epp.participation_status='excluded')";
    }

    return admin_all("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.role,
            u.email,
            COALESCE(d.department_name, d.department_code, NULLIF(u.department, ''), NULLIF(f.department, ''), 'General') AS department,
            COALESCE(NULLIF(u.program, ''), NULLIF(f.program_code, ''), p.program_code, '') AS program_code,
            f.id AS faculty_id,
            u.profile_image,
            f.position_title,
            d.id AS department_id
        FROM users u
        LEFT JOIN faculty f ON f.user_id = u.id OR f.email = u.email
        LEFT JOIN programs p
            ON p.program_code = COALESCE(NULLIF(u.program, ''), NULLIF(f.program_code, ''))
            OR p.program_head_user_id = u.id
        LEFT JOIN departments d
            ON d.id = p.department_id
            OR d.department_code = COALESCE(NULLIF(u.department, ''), NULLIF(f.department, ''))
            OR d.department_name = COALESCE(NULLIF(u.department, ''), NULLIF(f.department, ''))
        WHERE u.is_active = 1
          AND u.role IN ($roles)
          AND f.id IS NOT NULL
          $scopeWhere
        GROUP BY u.id, f.id
        ORDER BY department, program_code, u.role, u.full_name
    ", $params);
}

function dipascaf_peer_scope_key(array $user): string
{
    $departmentId = (int) ($user['department_id'] ?? 0);
    $department = $departmentId > 0 ? 'department-' . $departmentId : trim((string) ($user['department'] ?? 'General'));
    $role = trim((string) ($user['role'] ?? 'teacher'));

    return strtolower($department . '|department|' . $role);
}

function dipascaf_peer_department_key(array $user): string
{
    $departmentId = (int) ($user['department_id'] ?? 0);
    if ($departmentId > 0) {
        return 'department-' . $departmentId;
    }

    $department = trim((string) ($user['department'] ?? 'General'));
    return strtolower(admin_normalize_department_name($department !== '' ? $department : 'General'));
}

function dipascaf_peer_departments_match(array $left, array $right): bool
{
    $leftId = (int) ($left['department_id'] ?? $left['departmentId'] ?? 0);
    $rightId = (int) ($right['department_id'] ?? $right['departmentId'] ?? 0);
    if ($leftId > 0 && $rightId > 0) {
        return $leftId === $rightId;
    }

    $leftDepartment = trim((string) ($left['department'] ?? ''));
    $rightDepartment = trim((string) ($right['department'] ?? ''));
    if ($leftDepartment === '' || $rightDepartment === '') {
        return false;
    }

    $leftAliases = admin_matching_department_aliases($leftDepartment);
    $rightAliases = admin_matching_department_aliases($rightDepartment);
    $leftAliases[] = $leftDepartment;
    $rightAliases[] = $rightDepartment;

    $normalize = static fn (string $value): string => strtolower(admin_normalize_department_name($value));
    $leftAliases = array_values(array_unique(array_map($normalize, $leftAliases)));
    $rightAliases = array_values(array_unique(array_map($normalize, $rightAliases)));

    return count(array_intersect($leftAliases, $rightAliases)) > 0;
}

function dipascaf_peer_pair_allowed(array $evaluator, array $evaluatee): bool
{
    $evaluatorRole = (string) ($evaluator['role'] ?? '');
    $evaluateeRole = (string) ($evaluatee['role'] ?? '');

    if ($evaluatorRole === 'teacher') {
        return $evaluateeRole === 'teacher'
            && dipascaf_peer_departments_match($evaluator, $evaluatee);
    }

    if ($evaluatorRole === 'program_head') {
        return $evaluateeRole === 'program_head'
            && dipascaf_peer_departments_match($evaluator, $evaluatee);
    }

    if ($evaluatorRole === 'dean') {
        return $evaluateeRole === 'dean'
            && !dipascaf_peer_departments_match($evaluator, $evaluatee);
    }

    return false;
}

function dipascaf_peer_eligible_deans(array $departmentScope = []): array
{
    $scopeWhere = '';
    $params = [];
    $departmentIds = array_values(array_filter(array_map('intval', $departmentScope['department_ids'] ?? [])));
    $departments = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $departmentScope['departments'] ?? []
    ), static fn (string $value): bool => $value !== '')));

    $scopeParts = [];
    if ($departmentIds !== []) {
        $keys = [];
        foreach ($departmentIds as $index => $departmentId) {
            $key = 'dean_scope_department_id_' . $index;
            $keys[] = ':' . $key;
            $params[$key] = $departmentId;
        }
        $scopeParts[] = 'd.id IN (' . implode(',', $keys) . ')';
    }

    if ($departments !== []) {
        $keys = [];
        foreach ($departments as $index => $department) {
            $key = 'dean_scope_department_' . $index;
            $keys[] = ':' . $key;
            $params[$key] = $department;
        }
        $scopeParts[] = 'COALESCE(NULLIF(f.department, \'\'), NULLIF(u.department, \'\'), d.department_name, d.department_code) IN (' . implode(',', $keys) . ')';
    }

    if ($scopeParts !== []) {
        $scopeWhere = ' AND (' . implode(' OR ', $scopeParts) . ')';
    }

    return admin_all("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.role,
            u.email,
            COALESCE(d.department_name, d.department_code, NULLIF(u.department, ''), NULLIF(f.department, ''), 'General') AS department,
            COALESCE(NULLIF(u.program, ''), NULLIF(f.program_code, ''), '') AS program_code,
            f.id AS faculty_id,
            u.profile_image,
            f.position_title,
            d.id AS department_id
        FROM users u
        LEFT JOIN faculty f ON f.user_id = u.id OR f.email = u.email
        LEFT JOIN departments d
            ON d.dean_user_id = u.id
            OR d.department_code = COALESCE(NULLIF(u.department, ''), NULLIF(f.department, ''))
            OR d.department_name = COALESCE(NULLIF(u.department, ''), NULLIF(f.department, ''))
        WHERE u.is_active = 1
          AND u.role = 'dean'
          AND f.id IS NOT NULL
          $scopeWhere
        GROUP BY u.id, f.id, d.id
        ORDER BY department, u.full_name
    ", $params);
}

function dipascaf_select_random_peer(array $evaluator, array $candidates, array $priorPairs, array $currentPairs, array $evaluateeLoad): ?array
{
    $unusedCandidates = array_values(array_filter($candidates, static function (array $candidate) use ($evaluateeLoad): bool {
        return (int) ($evaluateeLoad[(int) $candidate['user_id']] ?? 0) === 0;
    }));

    $preferred = $unusedCandidates !== [] ? $unusedCandidates : $candidates;
    return dipascaf_select_incremental_peer($evaluator, $preferred, $priorPairs, $currentPairs, $evaluateeLoad);
}

function dipascaf_prefer_cross_program_faculty(array $evaluator, array $facultyCandidates): array
{
    return $facultyCandidates;
}

function dipascaf_prior_peer_pairs(?int $periodId = null): array
{
    dipascaf_ensure_peer_evaluation_schema();

    $where = $periodId !== null ? 'WHERE evaluation_period_id <> :period_id AND COALESCE(is_archived, 0) = 0' : 'WHERE COALESCE(is_archived, 0) = 0';
    $rows = admin_all(
        "SELECT evaluator_id, evaluatee_id FROM peer_evaluation_assignments $where",
        $periodId !== null ? ['period_id' => $periodId] : []
    );

    $pairs = [];
    foreach ($rows as $row) {
        $pairs[(int) $row['evaluator_id']][(int) $row['evaluatee_id']] = true;
    }

    return $pairs;
}

function dipascaf_current_peer_state(int $periodId): array
{
    dipascaf_ensure_peer_evaluation_schema();

    $rows = admin_all(
        "SELECT pea.evaluator_id, pea.evaluatee_id,
                eu.role AS evaluator_role,
                efu.role AS evaluatee_role,
                COALESCE(NULLIF(euf.department, ''), NULLIF(eu.department, '')) AS evaluator_department,
                COALESCE(NULLIF(ef.department, ''), NULLIF(efu.department, '')) AS evaluatee_department,
                pea.department_id
         FROM peer_evaluation_assignments pea
         JOIN users eu ON eu.id = pea.evaluator_id
         JOIN users efu ON efu.id = pea.evaluatee_id
         LEFT JOIN faculty euf ON euf.user_id = eu.id OR euf.email = eu.email
         LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
         WHERE pea.evaluation_period_id = :period_id
           AND COALESCE(pea.is_archived, 0) = 0",
        ['period_id' => $periodId]
    );

    $byEvaluator = [];
    $evaluateeLoad = [];
    $pairs = [];

    foreach ($rows as $row) {
        $evaluatorId = (int) $row['evaluator_id'];
        $evaluateeId = (int) $row['evaluatee_id'];
        if (!dipascaf_peer_pair_allowed(
            [
                'user_id' => $evaluatorId,
                'role' => (string) ($row['evaluator_role'] ?? ''),
                'department' => (string) ($row['evaluator_department'] ?? ''),
                'department_id' => 0,
            ],
            [
                'user_id' => $evaluateeId,
                'role' => (string) ($row['evaluatee_role'] ?? ''),
                'department' => (string) ($row['evaluatee_department'] ?? ''),
                'department_id' => 0,
            ]
        )) {
            continue;
        }
        $byEvaluator[$evaluatorId] = $evaluateeId;
        $evaluateeLoad[$evaluateeId] = ($evaluateeLoad[$evaluateeId] ?? 0) + 1;
        $pairs[$evaluatorId][$evaluateeId] = true;
    }

    return [
        'byEvaluator' => $byEvaluator,
        'evaluateeLoad' => $evaluateeLoad,
        'pairs' => $pairs,
    ];
}

function dipascaf_assign_group(array $members, array $priorPairs): array
{
    if (count($members) < 2) {
        return [];
    }

    $evaluators = $members;
    shuffle($evaluators);

    $attempts = [
        ['avoid_history' => true, 'avoid_reciprocal' => true],
        ['avoid_history' => true, 'avoid_reciprocal' => false],
        ['avoid_history' => false, 'avoid_reciprocal' => true],
        ['avoid_history' => false, 'avoid_reciprocal' => false],
    ];

    foreach ($attempts as $rules) {
        $solution = dipascaf_assign_group_backtrack($evaluators, $members, $priorPairs, [], [], $rules);
        if ($solution !== null) {
            return $solution;
        }
    }

    return [];
}

function dipascaf_assign_group_backtrack(array $evaluators, array $members, array $priorPairs, array $assigned, array $usedEvaluatees, array $rules, int $index = 0): ?array
{
    if ($index >= count($evaluators)) {
        return $assigned;
    }

    $evaluator = $evaluators[$index];
    $candidates = $members;
    shuffle($candidates);

    usort($candidates, static function (array $a, array $b) use ($priorPairs, $evaluator): int {
        $aSeen = isset($priorPairs[(int) $evaluator['user_id']][(int) $a['user_id']]) ? 1 : 0;
        $bSeen = isset($priorPairs[(int) $evaluator['user_id']][(int) $b['user_id']]) ? 1 : 0;
        return $aSeen <=> $bSeen;
    });

    foreach ($candidates as $candidate) {
        $evaluatorId = (int) $evaluator['user_id'];
        $evaluateeId = (int) $candidate['user_id'];

        if ($evaluatorId === $evaluateeId || isset($usedEvaluatees[$evaluateeId])) {
            continue;
        }

        if (($rules['avoid_history'] ?? false) && isset($priorPairs[$evaluatorId][$evaluateeId])) {
            continue;
        }

        if (($rules['avoid_reciprocal'] ?? false) && isset($assigned[$evaluateeId]) && (int) $assigned[$evaluateeId]['evaluatee']['user_id'] === $evaluatorId) {
            continue;
        }

        $nextAssigned = $assigned;
        $nextUsed = $usedEvaluatees;
        $nextAssigned[$evaluatorId] = ['evaluator' => $evaluator, 'evaluatee' => $candidate];
        $nextUsed[$evaluateeId] = true;

        $solution = dipascaf_assign_group_backtrack($evaluators, $members, $priorPairs, $nextAssigned, $nextUsed, $rules, $index + 1);
        if ($solution !== null) {
            return $solution;
        }
    }

    return null;
}

function dipascaf_select_incremental_peer(array $evaluator, array $members, array $priorPairs, array $currentPairs, array $evaluateeLoad): ?array
{
    $evaluatorId = (int) $evaluator['user_id'];

    $baseCandidates = array_values(array_filter($members, static function (array $candidate) use ($evaluator, $evaluatorId, $currentPairs): bool {
        $evaluateeId = (int) $candidate['user_id'];
        return $evaluateeId !== $evaluatorId
            && !isset($currentPairs[$evaluatorId][$evaluateeId])
            && dipascaf_peer_pair_allowed($evaluator, $candidate);
    }));

    if ($baseCandidates === []) {
        return null;
    }

    foreach ([true, false] as $avoidHistory) {
        $candidates = array_values(array_filter($baseCandidates, static function (array $candidate) use ($avoidHistory, $priorPairs, $evaluatorId): bool {
            return !$avoidHistory || !isset($priorPairs[$evaluatorId][(int) $candidate['user_id']]);
        }));

        if ($candidates === []) {
            continue;
        }

        shuffle($candidates);
        usort($candidates, static function (array $a, array $b) use ($priorPairs, $currentPairs, $evaluateeLoad, $evaluatorId): int {
            $aId = (int) $a['user_id'];
            $bId = (int) $b['user_id'];
            $aLoad = $evaluateeLoad[$aId] ?? 0;
            $bLoad = $evaluateeLoad[$bId] ?? 0;
            if ($aLoad !== $bLoad) {
                return $aLoad <=> $bLoad;
            }

            $aReciprocal = isset($currentPairs[$aId][$evaluatorId]) ? 1 : 0;
            $bReciprocal = isset($currentPairs[$bId][$evaluatorId]) ? 1 : 0;
            if ($aReciprocal !== $bReciprocal) {
                return $aReciprocal <=> $bReciprocal;
            }

            $aSeen = isset($priorPairs[$evaluatorId][$aId]) ? 1 : 0;
            $bSeen = isset($priorPairs[$evaluatorId][$bId]) ? 1 : 0;
            return $aSeen <=> $bSeen;
        });

        return $candidates[0];
    }

    return null;
}

function dipascaf_peer_scope_condition(array $departmentScope, string $assignmentAlias = 'pea', string $facultyAlias = 'ef'): array
{
    $params = [];
    $parts = [];

    if (!empty($departmentScope['unassigned_only'])) {
        return [
            'sql' => '(' . $assignmentAlias . ".department_id IS NULL AND (" . $facultyAlias . ".department IS NULL OR TRIM(" . $facultyAlias . ".department) = '' OR LOWER(TRIM(" . $facultyAlias . ".department)) IN ('general','unassigned','unassigned department')))",
            'params' => [],
        ];
    }

    $programCodes = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => strtoupper(trim((string) $value)),
        $departmentScope['program_codes'] ?? []
    ), static fn (string $value): bool => $value !== '')));
    if ($programCodes !== []) {
        $keys = [];
        foreach ($programCodes as $index => $programCode) {
            $key = 'peer_scope_program_code_' . $index;
            $keys[] = ':' . $key;
            $params[$key] = $programCode;
        }

        return [
            'sql' => 'UPPER(' . $facultyAlias . '.program_code) IN (' . implode(',', $keys) . ')',
            'params' => $params,
        ];
    }

    $departmentIds = array_values(array_filter(array_map('intval', $departmentScope['department_ids'] ?? [])));
    if ($departmentIds !== []) {
        $keys = [];
        foreach ($departmentIds as $index => $departmentId) {
            $key = 'peer_scope_department_id_' . $index;
            $keys[] = ':' . $key;
            $params[$key] = $departmentId;
        }
        $parts[] = $assignmentAlias . '.department_id IN (' . implode(',', $keys) . ')';
    }

    $departments = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $departmentScope['departments'] ?? []
    ), static fn (string $value): bool => $value !== '')));
    if ($departments !== []) {
        $keys = [];
        foreach ($departments as $index => $department) {
            $key = 'peer_scope_department_' . $index;
            $keys[] = ':' . $key;
            $params[$key] = $department;
        }
        $parts[] = $facultyAlias . '.department IN (' . implode(',', $keys) . ')';
    }

    return [
        'sql' => $parts !== [] ? '(' . implode(' OR ', $parts) . ')' : '1 = 1',
        'params' => $params,
    ];
}

function dipascaf_sync_peer_leadership_faculty_records(array $departmentScope = []): void
{
    admin_ensure_faculty_program_schema();

    $departmentIds = array_values(array_filter(array_map('intval', $departmentScope['department_ids'] ?? [])));
    $departments = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $departmentScope['departments'] ?? []
    ), static fn (string $value): bool => $value !== '')));

    $scopeAliases = [];
    foreach ($departments as $department) {
        $scopeAliases[] = strtolower(admin_normalize_department_name($department));
        foreach (admin_matching_department_aliases($department) as $alias) {
            $scopeAliases[] = strtolower(admin_normalize_department_name($alias));
        }
    }
    $scopeAliases = array_values(array_unique(array_filter($scopeAliases)));

    $programHeads = admin_all(
        "SELECT DISTINCT u.id, u.department, f.department AS faculty_department, p.department_id,
                d.department_code, d.department_name
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id OR (f.user_id IS NULL AND f.email = u.email)
         LEFT JOIN programs p ON p.program_head_user_id = u.id AND p.is_active = 1
         LEFT JOIN departments d ON d.id = p.department_id OR d.department_code = u.department OR d.department_name = u.department
         WHERE u.role = 'program_head'
           AND u.is_active = 1"
    );

    foreach ($programHeads as $programHead) {
        if ($departmentIds !== [] && in_array((int) ($programHead['department_id'] ?? 0), $departmentIds, true)) {
            dipascaf_ensure_leadership_faculty_record((int) $programHead['id'], 'Program Head');
            continue;
        }

        if ($scopeAliases === []) {
            dipascaf_ensure_leadership_faculty_record((int) $programHead['id'], 'Program Head');
            continue;
        }

        $candidateDepartments = array_filter([
            (string) ($programHead['department'] ?? ''),
            (string) ($programHead['faculty_department'] ?? ''),
            (string) ($programHead['department_code'] ?? ''),
            (string) ($programHead['department_name'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

        foreach ($candidateDepartments as $department) {
            $candidateAliases = [strtolower(admin_normalize_department_name($department))];
            foreach (admin_matching_department_aliases($department) as $alias) {
                $candidateAliases[] = strtolower(admin_normalize_department_name($alias));
            }

            if (array_intersect($scopeAliases, array_unique($candidateAliases)) !== []) {
                dipascaf_ensure_leadership_faculty_record((int) $programHead['id'], 'Program Head');
                break;
            }
        }
    }
}

function dipascaf_generate_peer_evaluation_assignments(int $evaluationPeriodId, string $periodName, string $dueDate, bool $includeProgramHeads = true, bool $regenerate = false, array $departmentScope = [], string $peerGroup = 'department'): array
{
    dipascaf_ensure_peer_evaluation_schema();
    $deanOnly = $peerGroup === 'dean';
    if ($deanOnly) {
        $deanUsers = admin_all("SELECT id FROM users WHERE role='dean' AND is_active=1");
        foreach ($deanUsers as $deanUser) {
            dipascaf_ensure_leadership_faculty_record((int)$deanUser['id'], 'Dean');
        }
    } elseif ($includeProgramHeads) {
        dipascaf_sync_peer_leadership_faculty_records($departmentScope);
    }

    $db = db();
    $eligible = $deanOnly
        ? array_values(array_filter(
            dipascaf_peer_eligible_deans(),
            static fn(array $row): bool => !dipascaf_period_user_is_excluded($evaluationPeriodId, (int)$row['user_id'])
        ))
        : dipascaf_peer_eligible_users($includeProgramHeads, $departmentScope, $evaluationPeriodId);
    if ($eligible === []) {
        throw new RuntimeException(
            $deanOnly
            ? 'No eligible Deans were found. Add active Dean accounts with department assignments before generating Dean-to-Dean peers.'
            : 'No eligible Faculty or Program Heads were found in the selected department. '
            . 'Add active users with linked faculty records before generating peer assignments.'
        );
    }
    $deans = [];
    $departmentGroups = [];
    foreach ($eligible as $user) {
        $key = $deanOnly ? 'institution-deans' : dipascaf_peer_department_key($user);
        $departmentGroups[$key]['evaluators'][] = $user;
        if ((string) $user['role'] === 'teacher') {
            $departmentGroups[$key]['faculty'][] = $user;
        }
        if ((string) $user['role'] === 'program_head') {
            $departmentGroups[$key]['program_heads'][] = $user;
        }
        if ((string) $user['role'] === 'dean') {
            $departmentGroups[$key]['deans'][] = $user;
        }
    }
    $hasGeneratableGroup = false;
    foreach ($departmentGroups as $group) {
        if (count($group['faculty'] ?? []) >= 2 || count($group['program_heads'] ?? []) >= 2 || count($group['deans'] ?? []) >= 2) {
            $hasGeneratableGroup = true;
            break;
        }
    }
    if (!$hasGeneratableGroup) {
        throw new RuntimeException(
            $deanOnly
                ? 'At least two eligible Deans from different departments are required for Dean-to-Dean peer assignments.'
                : 'At least two eligible people of the same evaluator type are required in the selected department.'
        );
    }

    $priorPairs = dipascaf_prior_peer_pairs($evaluationPeriodId);
    if ($regenerate) {
        $previousRows = admin_all(
            "SELECT pea.evaluator_id, pea.evaluatee_id
             FROM peer_evaluation_assignments pea
             JOIN users generation_evaluator ON generation_evaluator.id = pea.evaluator_id
             LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
             WHERE pea.evaluation_period_id = :period_id
               AND pea.locked_at IS NULL
               AND pea.status <> 'completed'
               AND COALESCE(pea.is_archived, 0) = 0
               AND " . ($deanOnly ? "generation_evaluator.role = 'dean'" : "generation_evaluator.role <> 'dean'") . "
               AND " . dipascaf_peer_scope_condition($departmentScope, 'pea', 'ef')['sql'],
            ['period_id' => $evaluationPeriodId] + dipascaf_peer_scope_condition($departmentScope, 'pea', 'ef')['params']
        );
        foreach ($previousRows as $previousRow) {
            $priorPairs[(int) $previousRow['evaluator_id']][(int) $previousRow['evaluatee_id']] = true;
        }
    }

    // Pending assignments are only refreshed during an explicit regeneration.
    $scopeCondition = dipascaf_peer_scope_condition($departmentScope, 'pea', 'ef');
    if ($regenerate) {
        $db->prepare("
        DELETE pea, pa
        FROM peer_evaluation_assignments pea
        JOIN users generation_evaluator ON generation_evaluator.id = pea.evaluator_id
        LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
        LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
        WHERE pea.evaluation_period_id = :period_id
          AND pea.locked_at IS NULL
          AND pea.status <> 'completed'
          AND (pa.status IS NULL OR pa.status <> 'submitted')
          AND COALESCE(pea.is_archived, 0) = 0
          AND " . ($deanOnly ? "generation_evaluator.role = 'dean'" : "generation_evaluator.role <> 'dean'") . "
          AND {$scopeCondition['sql']}
        ")->execute(['period_id' => $evaluationPeriodId] + $scopeCondition['params']);
    }

    $currentState = dipascaf_current_peer_state($evaluationPeriodId);
    $generated = [];
    $invalidGroups = [];

    foreach ($departmentGroups as $scopeKey => $group) {
        $evaluators = array_values($group['evaluators'] ?? []);
        $facultyCandidates = array_values($group['faculty'] ?? []);
        $programHeadCandidates = array_values($group['program_heads'] ?? []);
        $deanCandidates = array_values($group['deans'] ?? []);
        if (count($facultyCandidates) < 2 && count(array_filter($evaluators, static fn (array $user): bool => (string) $user['role'] === 'teacher')) > 0) {
            $invalidGroups[] = ['scope' => $scopeKey . '_faculty', 'eligible' => count($facultyCandidates)];
        }
        if (count($programHeadCandidates) < 2 && count(array_filter($evaluators, static fn (array $user): bool => (string) $user['role'] === 'program_head')) > 0) {
            $invalidGroups[] = ['scope' => $scopeKey . '_program_head', 'eligible' => count($programHeadCandidates)];
        }

        $missingMembers = array_values(array_filter($evaluators, static function (array $member) use ($currentState): bool {
            return !isset($currentState['byEvaluator'][(int) $member['user_id']]);
        }));

        if ($missingMembers === []) {
            continue;
        }

        $assignments = [];
        shuffle($missingMembers);
        foreach ($missingMembers as $evaluator) {
            $candidatePool = match ((string)($evaluator['role'] ?? '')) {
                'dean' => $deanCandidates,
                'program_head' => $programHeadCandidates,
                default => dipascaf_prefer_cross_program_faculty($evaluator, $facultyCandidates),
            };
            $candidatePool = array_values(array_filter(
                $candidatePool,
                static fn (array $candidate): bool =>
                    dipascaf_peer_pair_allowed($evaluator, $candidate)
            ));
            $evaluatee = dipascaf_select_random_peer(
                $evaluator,
                $candidatePool,
                $priorPairs,
                $currentState['pairs'],
                $currentState['evaluateeLoad']
            );

            if ($evaluatee === null) {
                $invalidGroups[] = ['scope' => $scopeKey . '_' . $evaluator['role'], 'eligible' => count($candidatePool)];
                continue;
            }

            $assignments[(int) $evaluator['user_id']] = [
                'evaluator' => $evaluator,
                'evaluatee' => $evaluatee,
            ];
            $currentState['byEvaluator'][(int) $evaluator['user_id']] = (int) $evaluatee['user_id'];
            $currentState['pairs'][(int) $evaluator['user_id']][(int) $evaluatee['user_id']] = true;
            $currentState['evaluateeLoad'][(int) $evaluatee['user_id']] = ($currentState['evaluateeLoad'][(int) $evaluatee['user_id']] ?? 0) + 1;
        }

        if ($assignments === []) {
            $invalidGroups[] = ['scope' => $scopeKey, 'eligible' => count($facultyCandidates)];
            continue;
        }
        foreach ($assignments as $assignment) {
            $generated[] = $assignment;
        }
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $insertPeer = $db->prepare("
            INSERT INTO peer_assignments
                (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
            VALUES
                (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, :evaluator_role, 'peer', :questionnaire_type, 'pending', NOW(), :deadline)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                questionnaire_type = VALUES(questionnaire_type),
                deadline = VALUES(deadline),
                is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
        ");

        $insertDedicated = $db->prepare("
            INSERT INTO peer_evaluation_assignments
                (peer_assignment_id, evaluator_id, evaluatee_id, evaluatee_faculty_id, department_id, evaluation_period_id, assigned_at, status)
            VALUES
                (:peer_assignment_id, :evaluator_id, :evaluatee_id, :evaluatee_faculty_id, :department_id, :evaluation_period_id, NOW(), 'pending')
            ON DUPLICATE KEY UPDATE
                peer_assignment_id = VALUES(peer_assignment_id),
                evaluatee_faculty_id = VALUES(evaluatee_faculty_id),
                department_id = VALUES(department_id),
                status = IF(status = 'completed', status, 'pending'),
                is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
        ");

        foreach ($generated as $assignment) {
            $evaluator = $assignment['evaluator'];
            $evaluatee = $assignment['evaluatee'];
            if (!dipascaf_peer_pair_allowed($evaluator, $evaluatee)) {
                continue;
            }
            $questionnaireType = ((string) $evaluatee['role'] === 'teacher') ? 'faculty' : 'admin';

            $insertPeer->execute([
                'cycle_name' => $periodName,
                'evaluator_user_id' => (int) $evaluator['user_id'],
                'evaluatee_faculty_id' => (int) $evaluatee['faculty_id'],
                'evaluator_role' => (string) $evaluator['role'],
                'questionnaire_type' => $questionnaireType,
                'deadline' => $dueDate,
            ]);

            $peerAssignmentId = (int) $db->lastInsertId();

            $insertDedicated->execute([
                'peer_assignment_id' => $peerAssignmentId,
                'evaluator_id' => (int) $evaluator['user_id'],
                'evaluatee_id' => (int) $evaluatee['user_id'],
                'evaluatee_faculty_id' => (int) $evaluatee['faculty_id'],
                'department_id' => $evaluatee['department_id'] !== null ? (int) $evaluatee['department_id'] : null,
                'evaluation_period_id' => $evaluationPeriodId,
            ]);

            if (dipascaf_peer_is_locked_for_period($evaluationPeriodId)) {
                notify_create(
                    (int) $evaluator['user_id'],
                    'evaluation',
                    'New Peer Evaluation Assignment',
                    'You have been assigned to complete a peer evaluation.',
                    '/faculty/evaluate',
                    'peer_assignment',
                    $peerAssignmentId
                );
            }
        }

        if ($ownsTransaction && $db->inTransaction()) {
            $db->commit();
        }
        dipascaf_prune_pending_peer_duplicates($periodName, $evaluationPeriodId);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return [
        'created' => count($generated),
        'eligible' => count($eligible) + count($deans),
        'groups' => count($departmentGroups) + 1,
        'invalidGroups' => $invalidGroups,
    ];
}

/** Add only the missing peer assignment for a newly synchronized account. */
function dipascaf_sync_incremental_peers_for_account(
    int $userId,
    int $evaluationPeriodId,
    string $periodName,
    string $deadline
): array {
    $context = dipascaf_period_user_context($evaluationPeriodId, $userId);
    if ($context === null || !in_array((string)$context['role'], ['teacher','program_head','dean'], true)
        || (string)$context['participation_status'] !== 'included'
        || (string)$context['work_status'] !== 'active') {
        return ['created' => 0, 'review_required' => false];
    }

    $existing = admin_one(
        'SELECT id FROM peer_evaluation_assignments WHERE evaluation_period_id=:period AND evaluator_id=:user AND COALESCE(is_archived,0)=0 LIMIT 1',
        ['period' => $evaluationPeriodId, 'user' => $userId]
    );
    if ($existing !== null) return ['created' => 0, 'review_required' => false];

    $role = (string)$context['role'];
    $scope = $role === 'dean' ? [] : [
        'department_ids' => !empty($context['department_id']) ? [(int)$context['department_id']] : [],
        'departments' => !empty($context['department']) ? [(string)$context['department']] : [],
    ];
    try {
        $result = dipascaf_generate_peer_evaluation_assignments(
            $evaluationPeriodId,
            $periodName,
            $deadline,
            true,
            false,
            $scope,
            $role === 'dean' ? 'dean' : 'department'
        );
        return ['created' => (int)($result['created'] ?? 0), 'review_required' => !empty($result['invalidGroups'])];
    } catch (RuntimeException) {
        return ['created' => 0, 'review_required' => true];
    }
}

function dipascaf_prune_pending_peer_duplicates(string $periodName, ?int $evaluationPeriodId = null): array
{
    dipascaf_ensure_peer_evaluation_schema();

    $params = ['period_name' => $periodName];
    $periodJoin = '';
    $periodWhere = '';
    if ($evaluationPeriodId !== null) {
        $periodJoin = 'LEFT JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id';
        $periodWhere = ' AND (pea.evaluation_period_id = :period_id OR pea.evaluation_period_id IS NULL)';
        $params['period_id'] = $evaluationPeriodId;
    }

    $rows = admin_all(
        "SELECT pa.id, pa.evaluator_user_id, pa.evaluatee_faculty_id, pa.status,
                pea2.id AS official_peer_id
         FROM peer_assignments pa
         {$periodJoin}
         LEFT JOIN peer_evaluation_assignments pea2 ON pea2.peer_assignment_id = pa.id AND COALESCE(pea2.is_archived, 0) = 0
         WHERE pa.cycle_name = :period_name
           AND pa.assignment_type = 'peer'
           AND COALESCE(pa.is_archived, 0) = 0
           {$periodWhere}
         GROUP BY pa.id, pa.evaluator_user_id, pa.evaluatee_faculty_id, pa.status, pea2.id
         ORDER BY FIELD(pa.status, 'submitted', 'pending'), (pea2.id IS NULL), pa.assigned_at DESC, pa.id DESC",
        $params
    );

    $keptEvaluators = [];
    $keptEvaluatees = [];
    $archiveIds = [];
    foreach ($rows as $row) {
        $assignmentId = (int) ($row['id'] ?? 0);
        $evaluatorId = (int) ($row['evaluator_user_id'] ?? 0);
        $evaluateeFacultyId = (int) ($row['evaluatee_faculty_id'] ?? 0);
        $isSubmitted = (string) ($row['status'] ?? '') === 'submitted';
        $isOfficial = (int) ($row['official_peer_id'] ?? 0) > 0;

        if ($assignmentId === 0 || $isSubmitted) {
            if ($evaluatorId > 0) {
                $keptEvaluators[$evaluatorId] = true;
            }
            if ($evaluateeFacultyId > 0) {
                $keptEvaluatees[$evaluateeFacultyId] = true;
            }
            continue;
        }

        if (
            !$isOfficial
            || isset($keptEvaluators[$evaluatorId])
            || isset($keptEvaluatees[$evaluateeFacultyId])
        ) {
            $archiveIds[] = $assignmentId;
            continue;
        }

        $keptEvaluators[$evaluatorId] = true;
        $keptEvaluatees[$evaluateeFacultyId] = true;
    }

    if ($archiveIds !== []) {
        $placeholders = implode(',', array_fill(0, count($archiveIds), '?'));
        db()->prepare("UPDATE peer_assignments SET is_archived = 1, archived_at = NOW() WHERE id IN ($placeholders)")
            ->execute($archiveIds);
        db()->prepare("UPDATE peer_evaluation_assignments SET is_archived = 1, archived_at = NOW() WHERE peer_assignment_id IN ($placeholders)")
            ->execute($archiveIds);
    }

    return ['archived' => count($archiveIds)];
}

function dipascaf_generate_peer_to_peer_assignments(string $cycleName, string $deadline): array
{
    $period = admin_one(
        'SELECT id, period_name FROM appraisal_periods WHERE period_name = :period_name ORDER BY id DESC LIMIT 1',
        ['period_name' => $cycleName]
    );
    if ($period === null) {
        $period = dipascaf_current_evaluation_period();
    }

    if ($period !== null) {
        $summary = dipascaf_generate_peer_evaluation_assignments(
            (int) $period['id'],
            (string) $period['period_name'],
            $deadline,
            true,
            false
        );

        return [
            'created' => (int) ($summary['created'] ?? 0),
            'skipped_existing' => 0,
            'groups_processed' => (int) ($summary['groups'] ?? 0),
            'invalid_groups' => $summary['invalidGroups'] ?? [],
        ];
    }

    return [
        'created' => 0,
        'skipped_existing' => 0,
        'groups_processed' => 0,
        'invalid_groups' => [['scope' => 'evaluation_period', 'eligible' => 0]],
    ];
}

function dipascaf_sync_peer_assignment_status(int $peerAssignmentId, string $status): void
{
    dipascaf_ensure_peer_evaluation_schema();

    $normalized = $status === 'submitted' ? 'completed' : $status;
    db()->prepare(
        'UPDATE peer_evaluation_assignments SET status = :status WHERE peer_assignment_id = :peer_assignment_id'
    )->execute([
        'status' => $normalized,
        'peer_assignment_id' => $peerAssignmentId,
    ]);
}
