<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';

function dipascaf_ensure_evaluation_period_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        db()->exec("ALTER TABLE appraisal_periods MODIFY status ENUM('draft', 'open', 'locked', 'closed') NOT NULL DEFAULT 'draft'");
    } catch (Throwable) {
        // Older installs may already be compatible.
    }

    foreach ([
        "ALTER TABLE appraisal_periods ADD COLUMN school_year VARCHAR(20) NULL AFTER period_name",
        "ALTER TABLE appraisal_periods ADD COLUMN semester VARCHAR(40) NULL AFTER school_year",
        "ALTER TABLE appraisal_periods ADD COLUMN locked_at DATETIME NULL AFTER status",
        "ALTER TABLE appraisal_periods ADD COLUMN opened_at DATETIME NULL AFTER locked_at",
    ] as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable) {
            // Column already exists or database is unavailable.
        }
    }
}

function dipascaf_ensure_peer_lifecycle_schema(): void
{
    dipascaf_ensure_evaluation_period_schema();

    db()->exec("
        CREATE TABLE IF NOT EXISTS peer_evaluation_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evaluation_period_id INT NOT NULL,
            status ENUM('unlocked', 'locked') NOT NULL DEFAULT 'unlocked',
            locked_at DATETIME NULL,
            locked_by INT NULL,
            unlocked_at DATETIME NULL,
            unlocked_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_peer_lifecycle_period (evaluation_period_id),
            CONSTRAINT fk_peer_lifecycle_period
                FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function dipascaf_peer_lifecycle(int $periodId): array
{
    dipascaf_ensure_peer_lifecycle_schema();
    $row = admin_one(
        'SELECT * FROM peer_evaluation_locks WHERE evaluation_period_id = :period_id LIMIT 1',
        ['period_id' => $periodId]
    );

    return [
        'periodId' => $periodId,
        'status' => (string) ($row['status'] ?? 'unlocked'),
        'isLocked' => (string) ($row['status'] ?? 'unlocked') === 'locked',
        'lockedAt' => (string) ($row['locked_at'] ?? ''),
        'unlockedAt' => (string) ($row['unlocked_at'] ?? ''),
    ];
}

function dipascaf_peer_is_locked_for_period(int $periodId): bool
{
    return dipascaf_peer_lifecycle($periodId)['isLocked'];
}

function dipascaf_peer_assignment_is_locked(int $assignmentId): bool
{
    dipascaf_ensure_peer_lifecycle_schema();
    $row = admin_one(
        "SELECT pel.status
         FROM peer_evaluation_assignments pea
         JOIN peer_assignments pa
            ON pa.id = pea.peer_assignment_id
            AND pa.evaluator_user_id = pea.evaluator_id
            AND pa.evaluatee_faculty_id = pea.evaluatee_faculty_id
         JOIN peer_evaluation_locks pel ON pel.evaluation_period_id = pea.evaluation_period_id
         WHERE pea.peer_assignment_id = :assignment_id
           AND COALESCE(pea.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND pa.assignment_type = 'peer'
         LIMIT 1",
        ['assignment_id' => $assignmentId]
    );

    return (string) ($row['status'] ?? 'unlocked') === 'locked';
}

function dipascaf_set_peer_lifecycle(int $periodId, string $status, int $userId): void
{
    dipascaf_ensure_peer_lifecycle_schema();
    $status = $status === 'locked' ? 'locked' : 'unlocked';

    db()->prepare("
        INSERT INTO peer_evaluation_locks
            (evaluation_period_id, status, locked_at, locked_by, unlocked_at, unlocked_by)
        VALUES
            (:period_id, :status, :locked_at, :locked_by, :unlocked_at, :unlocked_by)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            locked_at = IF(VALUES(status) = 'locked', COALESCE(locked_at, VALUES(locked_at)), locked_at),
            locked_by = IF(VALUES(status) = 'locked', VALUES(locked_by), locked_by),
            unlocked_at = IF(VALUES(status) = 'unlocked', VALUES(unlocked_at), unlocked_at),
            unlocked_by = IF(VALUES(status) = 'unlocked', VALUES(unlocked_by), unlocked_by)
    ")->execute([
        'period_id' => $periodId,
        'status' => $status,
        'locked_at' => $status === 'locked' ? date('Y-m-d H:i:s') : null,
        'locked_by' => $status === 'locked' ? $userId : null,
        'unlocked_at' => $status === 'unlocked' ? date('Y-m-d H:i:s') : null,
        'unlocked_by' => $status === 'unlocked' ? $userId : null,
    ]);
}

function dipascaf_current_evaluation_period(): ?array
{
    dipascaf_ensure_evaluation_period_schema();

    $period = admin_one(
        "SELECT *
         FROM appraisal_periods
         WHERE status = 'open'
           AND period_name NOT LIKE 'Smoke Self Eval Period%'
           AND period_name NOT LIKE '%SMK%'
         ORDER BY date_start DESC, id DESC
         LIMIT 1"
    );

    if ($period !== null) {
        return $period;
    }

    return admin_one(
        "SELECT *
         FROM appraisal_periods
         WHERE period_name NOT LIKE 'Smoke Self Eval Period%'
           AND period_name NOT LIKE '%SMK%'
         ORDER BY FIELD(status, 'locked', 'closed', 'draft'), date_start DESC, id DESC
         LIMIT 1"
    );
}

function dipascaf_open_evaluation_period(): ?array
{
    dipascaf_ensure_evaluation_period_schema();

    return admin_one(
        "SELECT *
         FROM appraisal_periods
         WHERE status = 'open'
           AND period_name NOT LIKE 'Smoke Self Eval Period%'
           AND period_name NOT LIKE '%SMK%'
         ORDER BY date_start DESC, id DESC
         LIMIT 1"
    );
}

function dipascaf_period_payload(?array $period = null): array
{
    $period ??= dipascaf_current_evaluation_period();
    $isOpen = $period !== null && (string) ($period['status'] ?? '') === 'open';
    $status = $period['status'] ?? 'locked';

    return [
        'id' => $period !== null ? (int) $period['id'] : null,
        'period_name' => (string) ($period['period_name'] ?? 'No active evaluation period'),
        'school_year' => (string) ($period['school_year'] ?? ''),
        'semester' => (string) ($period['semester'] ?? ''),
        'date_start' => (string) ($period['date_start'] ?? ''),
        'date_end' => (string) ($period['date_end'] ?? ''),
        'status' => $status === 'closed' ? 'locked' : (string) $status,
        'is_open' => $isOpen,
        'message' => $isOpen
            ? 'The evaluation period is open. Assigned evaluators may answer and submit forms.'
            : 'The evaluation period is locked. Evaluators cannot answer, edit, or submit evaluations.',
    ];
}

function dipascaf_is_smoke_period_name(string $periodName): bool
{
    return stripos($periodName, 'Smoke Self Eval Period') !== false
        || preg_match('/\bSMK\d+\b/i', $periodName) === 1;
}

function dipascaf_period_year(array $period): string
{
    $dateStart = (string) ($period['date_start'] ?? '');
    if (preg_match('/^(\d{4})-/', $dateStart, $matches)) {
        return $matches[1];
    }

    $schoolYear = (string) ($period['school_year'] ?? '');
    if (preg_match('/\b(20\d{2})\b/', $schoolYear, $matches)) {
        return $matches[1];
    }

    $periodName = (string) ($period['period_name'] ?? '');
    if (preg_match('/\b(20\d{2})\b/', $periodName, $matches)) {
        return $matches[1];
    }

    return '';
}

function dipascaf_period_list_payload(): array
{
    dipascaf_ensure_evaluation_period_schema();

    $periods = admin_all(
        "SELECT *
         FROM appraisal_periods
         WHERE period_name NOT LIKE 'Smoke Self Eval Period%'
           AND period_name NOT LIKE '%SMK%'
         ORDER BY date_start DESC, id DESC"
    );

    $periods = array_values(array_filter($periods, static function (array $period): bool {
        return !dipascaf_is_smoke_period_name((string) ($period['period_name'] ?? ''));
    }));

    return array_map(static function (array $period): array {
        $payload = dipascaf_period_payload($period);
        $payload['year'] = dipascaf_period_year($period);
        return $payload;
    }, $periods);
}

function dipascaf_selected_period_from_request(array $source, bool $defaultToCurrent = true): ?array
{
    dipascaf_ensure_evaluation_period_schema();

    $periodId = (int) ($source['period_id'] ?? 0);
    if ($periodId > 0) {
        $period = admin_one('SELECT * FROM appraisal_periods WHERE id = :id LIMIT 1', ['id' => $periodId]);
        if ($period !== null) {
            return $period;
        }
    }

    $periodName = trim((string) ($source['period'] ?? ''));
    if ($periodName !== '') {
        if (ctype_digit($periodName)) {
            $period = admin_one('SELECT * FROM appraisal_periods WHERE id = :id LIMIT 1', ['id' => (int) $periodName]);
            if ($period !== null) {
                return $period;
            }
        }

        $period = admin_one('SELECT * FROM appraisal_periods WHERE period_name = :period_name LIMIT 1', ['period_name' => $periodName]);
        if ($period !== null) {
            return $period;
        }
    }

    return $defaultToCurrent ? dipascaf_current_evaluation_period() : null;
}

function dipascaf_require_open_evaluation_period(): array
{
    $period = dipascaf_open_evaluation_period();
    if ($period === null) {
        throw new RuntimeException('Evaluation is locked. The Admin must open the evaluation period before forms can be accessed or submitted.');
    }

    return $period;
}

function dipascaf_assignment_matches_open_period(array $assignment, array $period): bool
{
    return strcasecmp((string) ($assignment['cycle_name'] ?? ''), (string) ($period['period_name'] ?? '')) === 0;
}

function dipascaf_normalized_program_codes(array|string|null $values): array
{
    $items = is_array($values) ? $values : preg_split('/[,;|]/', (string) $values);
    return array_values(array_unique(array_filter(array_map(
        static fn ($value): string => strtoupper(trim((string) $value)),
        $items ?: []
    ), static fn (string $value): bool => $value !== '')));
}

function dipascaf_department_matches_scope(string $department, array $scope): bool
{
    $department = trim($department);
    if ($department === '' || $scope === []) {
        return false;
    }

    $aliases = admin_matching_department_aliases($department);
    if ($aliases === []) {
        $aliases = [$department];
    }

    $normalizedScope = array_map(
        static fn (string $value): string => strtolower(admin_normalize_department_name($value)),
        array_map('strval', $scope)
    );

    foreach ($aliases as $alias) {
        if (in_array(strtolower(admin_normalize_department_name((string) $alias)), $normalizedScope, true)) {
            return true;
        }
    }

    return false;
}

function dipascaf_evaluator_scope(int $userId, string $role): array
{
    $user = admin_one(
        'SELECT id, role, department, program FROM users WHERE id = :id LIMIT 1',
        ['id' => $userId]
    ) ?? [];

    $departments = [];
    $programCodes = dipascaf_normalized_program_codes($user['program'] ?? '');

    $userDepartment = trim((string) ($user['department'] ?? ''));
    if ($userDepartment !== '') {
        $parts = preg_split('/[,;|]/', $userDepartment) ?: [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $aliases = admin_matching_department_aliases($part);
                $departments = array_merge($departments, $aliases !== [] ? $aliases : [$part]);
            }
        }
    }

    if ($role === 'dean') {
        $rows = admin_all(
            'SELECT department_code, department_name
             FROM departments
             WHERE dean_user_id = :user_id AND is_active = 1',
            ['user_id' => $userId]
        );
        foreach ($rows as $row) {
            $departments = array_merge($departments, admin_department_aliases($row));
        }
    }

    if ($role === 'program_head') {
        $rows = admin_all(
            'SELECT p.program_code, d.department_code, d.department_name
             FROM programs p
             JOIN departments d ON d.id = p.department_id
             WHERE p.program_head_user_id = :user_id AND p.is_active = 1',
            ['user_id' => $userId]
        );
        foreach ($rows as $row) {
            $programCodes[] = strtoupper(trim((string) ($row['program_code'] ?? '')));
            $departments = array_merge($departments, admin_department_aliases($row));
        }
    }

    if ($role === 'vpaa') {
        try {
            $rows = admin_all(
                'SELECT department_code FROM vpaa_departments WHERE vpaa_user_id = :user_id',
                ['user_id' => $userId]
            );
            foreach ($rows as $row) {
                $department = trim((string) ($row['department_code'] ?? ''));
                if ($department !== '') {
                    $aliases = admin_matching_department_aliases($department);
                    $departments = array_merge($departments, $aliases !== [] ? $aliases : [$department]);
                }
            }
        } catch (Throwable) {
            // VPAA scope table is created lazily in older installs.
        }
    }

    return [
        'departments' => array_values(array_unique(array_filter($departments))),
        'program_codes' => array_values(array_unique(array_filter($programCodes))),
    ];
}

function dipascaf_evaluatee_context(int $facultyId): ?array
{
    $evaluatee = admin_one(
        'SELECT f.id, f.department, f.program_code, f.position_title,
                COALESCE(u.role, "") AS user_role
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR u.email = f.email
         WHERE f.id = :id
         LIMIT 1',
        ['id' => $facultyId]
    );

    if ($evaluatee === null) {
        return null;
    }

    $position = strtolower((string) ($evaluatee['position_title'] ?? ''));
    $evaluateeRole = (string) ($evaluatee['user_role'] ?? '');
    if ($evaluateeRole === '' || $evaluateeRole === 'teacher') {
        if (str_contains($position, 'dean')) {
            $evaluateeRole = 'dean';
        } elseif (str_contains($position, 'program head')) {
            $evaluateeRole = 'program_head';
        } else {
            $evaluateeRole = 'teacher';
        }
    }

    $evaluatee['resolved_role'] = $evaluateeRole;
    $evaluatee['normalized_program_code'] = dipascaf_normalized_program_codes($evaluatee['program_code'] ?? '')[0] ?? '';
    return $evaluatee;
}

function dipascaf_assignment_relationship_allowed(array $assignment, int $evaluatorUserId, string $evaluatorRole): bool
{
    $evaluatee = dipascaf_evaluatee_context((int) ($assignment['evaluatee_faculty_id'] ?? 0));
    if ($evaluatee === null) {
        return false;
    }

    $assignmentType = (string) ($assignment['assignment_type'] ?? '');
    $evaluateeRole = (string) ($evaluatee['resolved_role'] ?? '');
    $scope = dipascaf_evaluator_scope($evaluatorUserId, $evaluatorRole);
    $evaluateeDepartment = (string) ($evaluatee['department'] ?? '');
    $evaluateeProgram = (string) ($evaluatee['normalized_program_code'] ?? '');

    if ($assignmentType === 'peer') {
        $officialPeer = false;
        try {
            $officialPeer = admin_one(
                "SELECT pea.id
                 FROM peer_evaluation_assignments pea
                 JOIN peer_assignments pa
                    ON pa.id = pea.peer_assignment_id
                    AND pa.evaluator_user_id = pea.evaluator_id
                    AND pa.evaluatee_faculty_id = pea.evaluatee_faculty_id
                 JOIN peer_evaluation_locks pel ON pel.evaluation_period_id = pea.evaluation_period_id
                 WHERE pea.peer_assignment_id = :assignment_id
                   AND pea.evaluator_id = :evaluator_id
                   AND COALESCE(pea.is_archived, 0) = 0
                   AND COALESCE(pa.is_archived, 0) = 0
                   AND pa.assignment_type = 'peer'
                   AND pel.status = 'locked'
                 LIMIT 1",
                [
                    'assignment_id' => (int) ($assignment['id'] ?? 0),
                    'evaluator_id' => $evaluatorUserId,
                ]
            ) !== null;
        } catch (Throwable) {
            $officialPeer = false;
        }

        if (!$officialPeer) {
            return false;
        }

        if ($evaluatorRole === 'teacher') {
            return $evaluateeRole === 'teacher'
                && dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments']);
        }

        if ($evaluatorRole === 'program_head') {
            return $evaluateeRole === 'program_head'
                && dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments']);
        }

        if ($evaluatorRole === 'dean') {
            return $evaluateeRole === 'dean'
                && !dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments']);
        }

        return false;
    }

    // Self-evaluations are always allowed — the evaluator is evaluating themselves
    if ($assignmentType === 'self') {
        return true;
    }

    return match ($evaluatorRole) {
        'vpaa' => $evaluateeRole === 'dean'
            && dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments']),
        'dean' => in_array($evaluateeRole, ['teacher', 'program_head'], true)
            && dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments']),
        'program_head' => (
            $assignmentType === 'dean'
            && $evaluateeRole === 'dean'
            && dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments'])
        ) || (
            $evaluateeRole === 'teacher'
            && $assignmentType === 'program_head'
            && $evaluateeProgram !== ''
            && in_array($evaluateeProgram, $scope['program_codes'], true)
        ),
        'teacher' => (
            $assignmentType === 'dean'
            && $evaluateeRole === 'dean'
            && dipascaf_department_matches_scope($evaluateeDepartment, $scope['departments'])
        ) || (
            $assignmentType === 'program_head'
            && $evaluateeRole === 'program_head'
            && $evaluateeProgram !== ''
            && in_array($evaluateeProgram, $scope['program_codes'], true)
        ),
        default => false,
    };
}

function dipascaf_assert_assignment_allowed(array $assignment, int $evaluatorUserId, string $formType): array
{
    $currentUser = current_user();
    if ($currentUser === null) {
        throw new RuntimeException('Unauthenticated.');
    }

    if (($currentUser['role'] ?? '') === 'admin_hr') {
        throw new RuntimeException('Admin manages evaluation periods and assignments, but cannot submit evaluations.');
    }

    if ((int) ($assignment['evaluator_user_id'] ?? 0) !== $evaluatorUserId) {
        throw new RuntimeException('This evaluation assignment does not belong to your account.');
    }

    if ((string) ($assignment['status'] ?? '') === 'submitted') {
        throw new RuntimeException('This evaluation was already submitted and is now protected from editing.');
    }

    if ((string) ($assignment['assignment_type'] ?? '') === 'peer' && !dipascaf_peer_assignment_is_locked((int) ($assignment['id'] ?? 0))) {
        throw new RuntimeException('Peer-to-peer evaluation is not currently locked by the Admin. This form cannot be opened or submitted.');
    }

    $period = dipascaf_require_open_evaluation_period();
    if (!dipascaf_assignment_matches_open_period($assignment, $period)) {
        throw new RuntimeException('This assignment is not part of the currently open evaluation period.');
    }

    $questionnaireType = (string) ($assignment['questionnaire_type'] ?? '');
    if ($formType === 'form_a' && $questionnaireType !== 'admin') {
        throw new RuntimeException('This assignment requires PMAS Form B, not Form A.');
    }
    if ($formType === 'form_b' && $questionnaireType !== 'faculty') {
        throw new RuntimeException('This assignment requires PMAS Form A, not Form B.');
    }

    $allowed = [
        'vpaa' => ['admin'],
        'dean' => ['faculty', 'admin'],
        'program_head' => ['faculty', 'admin'],
        'teacher' => ['faculty', 'admin'],
    ];
    $role = (string) ($currentUser['role'] ?? '');
    if (!in_array($questionnaireType, $allowed[$role] ?? [], true)) {
        throw new RuntimeException('Your role is not allowed to submit this type of evaluation.');
    }

    if (!dipascaf_assignment_relationship_allowed($assignment, $evaluatorUserId, $role)) {
        throw new RuntimeException('This evaluation is outside your assigned department, program, or official peer assignment.');
    }

    return $period;
}
