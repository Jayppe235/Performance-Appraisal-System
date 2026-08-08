<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/notifications.php';

notify_ensure_schema();

header('Content-Type: application/json; charset=utf-8');

function peer_api_latest_period(): ?array
{
    return admin_one("
        SELECT ap.id, ap.period_name, s.due_date
        FROM appraisal_periods ap
        LEFT JOIN evaluation_schedules s ON s.evaluation_period_id = ap.id
        ORDER BY COALESCE(s.created_at, ap.created_at) DESC, ap.id DESC
        LIMIT 1
    ");
}

function peer_api_dean_departments(int $deanUserId): array
{
    $rows = admin_all(
        'SELECT department_code, department_name FROM departments WHERE dean_user_id = :id',
        ['id' => $deanUserId]
    );

    $aliases = [];
    foreach ($rows as $row) {
        if (!empty($row['department_code'])) $aliases[] = (string) $row['department_code'];
        if (!empty($row['department_name'])) $aliases[] = (string) $row['department_name'];
    }

    return array_values(array_unique($aliases));
}

function peer_api_dean_department_scope(int $deanUserId): array
{
    $rows = admin_all(
        'SELECT id, department_code, department_name FROM departments WHERE dean_user_id = :id',
        ['id' => $deanUserId]
    );

    $departmentIds = [];
    $departments = [];
    foreach ($rows as $row) {
        $departmentIds[] = (int) $row['id'];
        if (!empty($row['department_code'])) $departments[] = (string) $row['department_code'];
        if (!empty($row['department_name'])) $departments[] = (string) $row['department_name'];
        $departments = array_merge($departments, admin_department_aliases([
            'department_code' => (string) ($row['department_code'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? ''),
        ]));
    }

    return [
        'department_ids' => array_values(array_unique(array_filter($departmentIds))),
        'departments' => array_values(array_unique($departments)),
    ];
}

function peer_api_department_scope(int $departmentId): array
{
    $row = admin_one(
        'SELECT id, department_code, department_name FROM departments WHERE id = :id',
        ['id' => $departmentId]
    );

    if ($row === null) {
        return ['department_ids' => [], 'departments' => []];
    }

    $departments = [];
    if (!empty($row['department_code'])) $departments[] = (string) $row['department_code'];
    if (!empty($row['department_name'])) $departments[] = (string) $row['department_name'];
    $departments = array_merge($departments, admin_department_aliases([
        'department_code' => (string) ($row['department_code'] ?? ''),
        'department_name' => (string) ($row['department_name'] ?? ''),
    ]));

    return [
        'department_ids' => [(int) $row['id']],
        'departments' => array_values(array_unique($departments)),
    ];
}

function peer_api_program_head_scope(int $programHeadUserId): array
{
    $rows = admin_all(
        'SELECT p.id AS program_id, p.program_code, p.program_name, d.id AS department_id, d.department_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.program_head_user_id = :id
           AND p.is_active = 1
           AND d.is_active = 1',
        ['id' => $programHeadUserId]
    );

    $departmentIds = [];
    $departments = [];
    $programIds = [];
    $programCodes = [];

    foreach ($rows as $row) {
        $departmentIds[] = (int) $row['department_id'];
        $programIds[] = (int) $row['program_id'];
        if (!empty($row['program_code'])) $programCodes[] = strtoupper(trim((string) $row['program_code']));
        if (!empty($row['program_name'])) $programCodes[] = strtoupper(trim((string) $row['program_name']));
        if (!empty($row['department_code'])) $departments[] = (string) $row['department_code'];
        if (!empty($row['department_name'])) $departments[] = (string) $row['department_name'];
        $departments = array_merge($departments, admin_department_aliases([
            'department_code' => (string) ($row['department_code'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? ''),
        ]));
    }

    return [
        'department_ids' => array_values(array_unique(array_filter($departmentIds))),
        'departments' => array_values(array_unique(array_filter($departments))),
        'program_ids' => array_values(array_unique(array_filter($programIds))),
        'program_codes' => array_values(array_unique(array_filter($programCodes))),
    ];
}

function peer_api_require_dean(array $user): void
{
    if (($user['role'] ?? '') !== 'dean') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Peer-to-peer assignment controls are available to Deans only.']);
        exit;
    }
}

function peer_api_is_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin_hr';
}

function peer_api_can_manage(array $user): bool
{
    return peer_api_is_admin($user);
}

function peer_api_require_manage(array $user): void
{
    if (!peer_api_can_manage($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Peer-to-peer assignment setup is available to Admin/HR only.']);
        exit;
    }
}

function peer_api_require_admin(array $user): void
{
    if (!peer_api_is_admin($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Manual peer-to-peer assignment setup is available to the Admin only.']);
        exit;
    }
}

function peer_api_setup_payload(array $user, ?int $evaluationPeriodId = null): array
{
    admin_ensure_faculty_program_schema();
    dipascaf_ensure_peer_evaluation_schema();
    dipascaf_sync_peer_leadership_faculty_records();

    $departments = admin_all(
        'SELECT id, department_code, department_name
         FROM departments
         WHERE COALESCE(is_active, 1) = 1
         ORDER BY department_code, department_name'
    );

    $users = admin_all(
        "SELECT
            u.id,
            u.full_name,
            u.email,
            u.role,
            COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''), d.department_name, d.department_code, '') AS department,
            COALESCE(d.id, 0) AS department_id,
            COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''), p.program_code, '') AS program,
            f.id AS faculty_id,
            u.profile_image
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id OR f.email = u.email
         LEFT JOIN programs p
            ON p.program_code = COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''))
            OR p.program_head_user_id = u.id
         LEFT JOIN departments d
            ON d.id = p.department_id
            OR d.department_code = COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''))
            OR d.department_name = COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''))
            OR d.dean_user_id = u.id
         WHERE u.is_active = 1
           AND u.role IN ('teacher', 'program_head', 'dean')
           AND f.id IS NOT NULL
         GROUP BY u.id, f.id, d.id
         ORDER BY department, FIELD(u.role, 'dean', 'program_head', 'teacher'), u.full_name"
    );

    $departmentPayload = array_map(static fn (array $department): array => [
        'id' => (int) $department['id'],
        'code' => (string) ($department['department_code'] ?? ''),
        'name' => (string) ($department['department_name'] ?? ''),
        'label' => trim((string) (($department['department_code'] ?? '') . ' - ' . ($department['department_name'] ?? '')), ' -'),
    ], $departments);

    $userPayload = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'department' => (string) ($row['department'] ?? ''),
        'departmentId' => (int) ($row['department_id'] ?? 0),
        'program' => (string) ($row['program'] ?? ''),
        'facultyId' => (int) ($row['faculty_id'] ?? 0),
        'avatar' => (string) ($row['profile_image'] ?? ''),
    ], $users);
    if ($evaluationPeriodId !== null && $evaluationPeriodId > 0) {
        foreach ($userPayload as &$candidate) {
            $context = dipascaf_period_user_context($evaluationPeriodId, (int)$candidate['id']);
            if ($context === null) continue;
            $candidate['role'] = (string)$context['role'];
            $candidate['department'] = (string)$context['department'];
            $candidate['program'] = (string)$context['program'];
            $candidate['actingRoleLabel'] = $candidate['role'] === 'dean' ? 'Acting Dean' : ($candidate['actingRoleLabel'] ?? null);
            $candidate['periodWorkStatus'] = (string)($context['work_status'] ?? 'active');
            $candidate['periodParticipationStatus'] = (string)($context['participation_status'] ?? 'included');
        }
        unset($candidate);
        $userPayload = array_values(array_filter($userPayload, static fn(array $candidate): bool =>
            ($candidate['periodParticipationStatus'] ?? 'included') === 'included'
            && ($candidate['periodWorkStatus'] ?? 'active') === 'active'
        ));
    }

    $actingProgramHeads = admin_all(
        "SELECT
            u.id,
            u.full_name,
            u.email,
            u.role,
            d.department_name AS department,
            d.id AS department_id,
            p.program_code AS program,
            f.id AS faculty_id,
            u.profile_image
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         JOIN users u ON u.id = p.program_head_user_id
         LEFT JOIN faculty f ON f.user_id = u.id OR f.email = u.email
         WHERE COALESCE(p.is_active, 1) = 1
           AND COALESCE(d.is_active, 1) = 1
           AND u.is_active = 1
           AND u.role <> 'program_head'
         ORDER BY d.department_code, p.program_code, u.full_name"
    );

    foreach ($actingProgramHeads as $row) {
        $userPayload[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'role' => 'program_head',
            'baseRole' => (string) $row['role'],
            'department' => (string) ($row['department'] ?? ''),
            'departmentId' => (int) ($row['department_id'] ?? 0),
            'program' => (string) ($row['program'] ?? ''),
            'facultyId' => (int) ($row['faculty_id'] ?? 0),
            'avatar' => (string) ($row['profile_image'] ?? ''),
            'actingRoleLabel' => 'Acting Program Head',
        ];
    }

    if (($user['role'] ?? '') === 'dean') {
        $scope = peer_api_dean_department_scope((int) $user['id']);
        $departmentIds = array_flip(array_map('intval', $scope['department_ids'] ?? []));
        $departmentAliases = array_map(static fn (string $value): string => strtolower(admin_normalize_department_name($value)), $scope['departments'] ?? []);
        $departmentPayload = array_values(array_filter($departmentPayload, static fn (array $department): bool => isset($departmentIds[(int) $department['id']])));
        $userPayload = array_values(array_filter($userPayload, static function (array $row) use ($departmentIds, $departmentAliases): bool {
            if (isset($departmentIds[(int) ($row['departmentId'] ?? 0)])) return true;
            return in_array(strtolower(admin_normalize_department_name((string) ($row['department'] ?? ''))), $departmentAliases, true);
        }));
    } elseif (($user['role'] ?? '') === 'program_head') {
        $scope = peer_api_program_head_scope((int) $user['id']);
        $departmentIds = array_flip(array_map('intval', $scope['department_ids'] ?? []));
        $programCodes = array_flip(array_map('strtoupper', $scope['program_codes'] ?? []));
        $departmentPayload = array_values(array_filter($departmentPayload, static fn (array $department): bool => isset($departmentIds[(int) $department['id']])));
        $userPayload = array_values(array_filter($userPayload, static function (array $row) use ($programCodes): bool {
            return ($row['role'] ?? '') === 'teacher' && isset($programCodes[strtoupper(trim((string) ($row['program'] ?? '')))]);
        }));
    }

    return [
        'canManual' => peer_api_can_manage($user),
        'periods' => dipascaf_period_list_payload(),
        'departments' => $departmentPayload,
        'users' => $userPayload,
    ];
}

function peer_api_user_context(int $userId): ?array
{
    admin_ensure_faculty_program_schema();
    $userRole = admin_one('SELECT role FROM users WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $userId]);
    if (($userRole['role'] ?? '') === 'program_head') {
        dipascaf_ensure_leadership_faculty_record($userId, 'Program Head');
    }

    $row = admin_one(
        "SELECT
            u.id,
            u.full_name,
            u.email,
            u.role,
            COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''), d.department_name, d.department_code, '') AS department,
            COALESCE(d.id, 0) AS department_id,
            COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''), p.program_code, '') AS program,
            f.id AS faculty_id,
            f.position_title
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id OR f.email = u.email
         LEFT JOIN programs p
            ON p.program_code = COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''))
            OR p.program_head_user_id = u.id
         LEFT JOIN departments d
            ON d.id = p.department_id
            OR d.department_code = COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''))
            OR d.department_name = COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''))
            OR d.dean_user_id = u.id
         WHERE u.id = :id
           AND u.is_active = 1
           AND f.id IS NOT NULL
         LIMIT 1",
        ['id' => $userId]
    );

    if ($row === null) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'department' => (string) ($row['department'] ?? ''),
        'department_id' => (int) ($row['department_id'] ?? 0),
        'program' => (string) ($row['program'] ?? ''),
        'faculty_id' => (int) ($row['faculty_id'] ?? 0),
        'position' => (string) ($row['position_title'] ?? ''),
    ];
}

function peer_api_user_can_act_as_role(array $context, string $role): bool
{
    $userId = (int) ($context['id'] ?? 0);
    $actualRole = (string) ($context['role'] ?? '');
    if ($actualRole === $role) {
        return true;
    }

    if ($role === 'program_head' && $userId > 0) {
        $assigned = admin_one(
            'SELECT id
             FROM programs
             WHERE program_head_user_id = :user_id
               AND COALESCE(is_active, 1) = 1
             LIMIT 1',
            ['user_id' => $userId]
        );
        return $assigned !== null;
    }

    return false;
}

function peer_api_departments_match(array $left, array $right): bool
{
    $leftId = (int) ($left['department_id'] ?? 0);
    $rightId = (int) ($right['department_id'] ?? 0);
    if ($leftId > 0 && $rightId > 0 && $leftId === $rightId) {
        return true;
    }

    $leftDepartment = (string) ($left['department'] ?? '');
    $rightDepartment = (string) ($right['department'] ?? '');
    if ($leftDepartment === '' || $rightDepartment === '') {
        return false;
    }

    $leftAliases = admin_matching_department_aliases($leftDepartment);
    $rightAliases = admin_matching_department_aliases($rightDepartment);
    $leftAliases[] = $leftDepartment;
    $rightAliases[] = $rightDepartment;
    $leftAliases = array_map(static fn (string $value): string => strtolower(admin_normalize_department_name($value)), $leftAliases);
    $rightAliases = array_map(static fn (string $value): string => strtolower(admin_normalize_department_name($value)), $rightAliases);

    return count(array_intersect($leftAliases, $rightAliases)) > 0;
}

function peer_api_program_matches_scope(array $context, array $scope): bool
{
    $program = strtoupper(trim((string) ($context['program'] ?? '')));
    $programCodes = array_map(static fn (string $value): string => strtoupper(trim($value)), $scope['program_codes'] ?? []);
    return $program !== '' && in_array($program, $programCodes, true);
}

function peer_api_department_matches_scope(array $context, array $scope): bool
{
    $departmentId = (int) ($context['department_id'] ?? 0);
    $departmentIds = array_map('intval', $scope['department_ids'] ?? []);
    if ($departmentId > 0 && in_array($departmentId, $departmentIds, true)) {
        return true;
    }

    $department = trim((string) ($context['department'] ?? ''));
    if ($department === '') {
        return false;
    }

    $scopeAliases = [];
    foreach (($scope['departments'] ?? []) as $scopeDepartment) {
        $scopeAliases[] = strtolower(admin_normalize_department_name((string) $scopeDepartment));
        foreach (admin_matching_department_aliases((string) $scopeDepartment) as $alias) {
            $scopeAliases[] = strtolower(admin_normalize_department_name($alias));
        }
    }
    $scopeAliases = array_values(array_unique(array_filter($scopeAliases)));
    $departmentAliases = array_map(
        static fn (string $value): string => strtolower(admin_normalize_department_name($value)),
        array_merge([$department], admin_matching_department_aliases($department))
    );

    return count(array_intersect($scopeAliases, $departmentAliases)) > 0;
}

function peer_api_require_assignment_scope(array $manager, array $evaluator, array $evaluatee): void
{
    $role = (string) ($manager['role'] ?? '');
    if (peer_api_is_admin($manager)) {
        return;
    }

    if ($role === 'dean') {
        $scope = peer_api_dean_department_scope((int) $manager['id']);
        if (!peer_api_department_matches_scope($evaluator, $scope) || !peer_api_department_matches_scope($evaluatee, $scope)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'This Dean can only manage peer assignments within the assigned department.']);
            exit;
        }
        return;
    }

    if ($role === 'program_head') {
        $scope = peer_api_program_head_scope((int) $manager['id']);
        if (($evaluator['role'] ?? '') !== 'teacher' || ($evaluatee['role'] ?? '') !== 'teacher') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Program Heads can only manage faculty peer assignments under their assigned program.']);
            exit;
        }
        if (!peer_api_program_matches_scope($evaluator, $scope) || !peer_api_program_matches_scope($evaluatee, $scope)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'This Program Head can only manage peer assignments within the assigned program.']);
            exit;
        }
        return;
    }

    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Peer assignment management is not available for this role.']);
    exit;
}

function peer_api_require_existing_assignment_scope(array $manager, int $assignmentId): array
{
    $assignment = admin_one(
        'SELECT pea.*, pa.status AS peer_status
         FROM peer_evaluation_assignments pea
         LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
         WHERE pea.id = :id
         LIMIT 1',
        ['id' => $assignmentId]
    );
    if ($assignment === null) {
        throw new RuntimeException('The selected peer assignment was not found.');
    }

    $evaluator = peer_api_user_context((int) $assignment['evaluator_id']);
    $evaluatee = peer_api_user_context((int) $assignment['evaluatee_id']);
    if ($evaluator === null || $evaluatee === null) {
        throw new RuntimeException('The selected peer assignment references a user that no longer exists.');
    }
    peer_api_require_assignment_scope($manager, $evaluator, $evaluatee);

    return $assignment;
}

function peer_api_manual_validate(array $input, ?int $existingId = null): array
{
    $periodId = (int) ($input['evaluation_period_id'] ?? 0);
    $evaluatorId = (int) ($input['evaluator_id'] ?? 0);
    $evaluateeId = (int) ($input['evaluatee_id'] ?? 0);
    $role = (string) ($input['evaluator_role'] ?? '');
    $role = $role === 'faculty' ? 'teacher' : $role;

    if ($periodId <= 0 || $evaluatorId <= 0 || $evaluateeId <= 0 || $role === '') {
        throw new RuntimeException('Select an evaluation period, evaluator role, evaluator, and peer/evaluatee.');
    }

    if ($evaluatorId === $evaluateeId) {
        throw new RuntimeException('You cannot assign a user to evaluate himself or herself.');
    }

    $period = admin_one('SELECT * FROM appraisal_periods WHERE id = :id LIMIT 1', ['id' => $periodId]);
    if ($period === null) {
        throw new RuntimeException('The selected evaluation period does not exist in the database.');
    }

    $evaluator = peer_api_user_context($evaluatorId);
    $evaluatee = peer_api_user_context($evaluateeId);
    if ($evaluator === null || $evaluatee === null) {
        throw new RuntimeException('Assignments can only use users that exist in the database.');
    }

    if (!peer_api_user_can_act_as_role($evaluator, $role)) {
        throw new RuntimeException('The selected evaluator does not match the selected evaluator role.');
    }

    if ($role === 'teacher') {
        if ($evaluatee['role'] !== 'teacher' || !peer_api_departments_match($evaluator, $evaluatee)) {
            throw new RuntimeException('This faculty member can only evaluate faculty within the same department.');
        }
    } elseif ($role === 'program_head') {
        if (!peer_api_user_can_act_as_role($evaluatee, 'program_head') || !peer_api_departments_match($evaluator, $evaluatee)) {
            throw new RuntimeException('This Program Head can only evaluate another Program Head within the same department.');
        }
    } elseif ($role === 'dean') {
        if ($evaluatee['role'] !== 'dean' || peer_api_departments_match($evaluator, $evaluatee)) {
            throw new RuntimeException('This Dean can only evaluate Deans from other departments.');
        }
    } else {
        throw new RuntimeException('Only Faculty, Program Head, and Dean peer evaluators are allowed.');
    }

    $duplicateParams = [
        'period_id' => $periodId,
        'evaluator_id' => $evaluatorId,
        'evaluatee_id' => $evaluateeId,
        'existing_id' => (int) ($existingId ?? 0),
    ];
    $duplicate = admin_one(
        'SELECT id
         FROM peer_evaluation_assignments
         WHERE evaluation_period_id = :period_id
           AND evaluator_id = :evaluator_id
           AND evaluatee_id = :evaluatee_id
           AND COALESCE(is_archived, 0) = 0
           AND id <> :existing_id
         LIMIT 1',
        $duplicateParams
    );
    if ($duplicate !== null) {
        throw new RuntimeException('This evaluator is already assigned to this peer for the selected evaluation period.');
    }

    $existingEvaluator = admin_one(
        'SELECT id
         FROM peer_evaluation_assignments
         WHERE evaluation_period_id = :period_id
           AND evaluator_id = :evaluator_id
           AND COALESCE(is_archived, 0) = 0
           AND id <> :existing_id
         LIMIT 1',
        $duplicateParams
    );
    if ($existingEvaluator !== null) {
        throw new RuntimeException('This evaluator already has a peer-to-peer assignment for the selected evaluation period.');
    }

    $existingEvaluatee = admin_one(
        'SELECT id
         FROM peer_evaluation_assignments
         WHERE evaluation_period_id = :period_id
           AND evaluatee_id = :evaluatee_id
           AND COALESCE(is_archived, 0) = 0
           AND id <> :existing_id
         LIMIT 1',
        $duplicateParams
    );
    if ($existingEvaluatee !== null) {
        throw new RuntimeException('This peer/evaluatee already has one evaluator for the selected evaluation period.');
    }

    return [$period, $evaluator, $evaluatee, $role];
}

function peer_api_save_manual_assignment(array $input, array $user): array
{
    peer_api_require_manage($user);
    dipascaf_ensure_peer_evaluation_schema();
    admin_ensure_archive_schema();

    $id = (int) ($input['id'] ?? 0);
    [$period, $evaluator, $evaluatee, $role] = peer_api_manual_validate($input, $id > 0 ? $id : null);
    if (dipascaf_period_user_is_excluded((int)$period['id'], (int)$evaluator['id'])
        || dipascaf_period_user_is_excluded((int)$period['id'], (int)$evaluatee['id'])) {
        throw new RuntimeException('An excluded faculty member cannot be assigned in this evaluation period.');
    }
    peer_api_require_assignment_scope($user, $evaluator, $evaluatee);

    $existing = null;
    if ($id > 0) {
        $existing = peer_api_require_existing_assignment_scope($user, $id);
        if ((int) ($existing['is_archived'] ?? 0) === 1) {
            throw new RuntimeException('The selected peer assignment was not found.');
        }
        if (($existing['status'] ?? '') === 'completed' || ($existing['peer_status'] ?? '') === 'submitted') {
            throw new RuntimeException('Completed peer assignments cannot be edited.');
        }
    }

    $status = (string) ($input['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'overdue', 'completed'], true)) {
        $status = 'pending';
    }

    $peerStatus = $status === 'completed' ? 'submitted' : 'pending';
    $questionnaireType = in_array($evaluatee['role'], ['dean', 'program_head'], true) ? 'admin' : 'faculty';
    $departmentId = (int) ($evaluatee['department_id'] ?: $evaluator['department_id']);
    $deadline = (string) ($period['date_end'] ?? '');
    if ($deadline === '') {
        $deadline = date('Y-m-d', strtotime('+30 days'));
    }

    $db = db();
    $db->beginTransaction();
    try {
        if ($existing !== null && !empty($existing['peer_assignment_id'])) {
            $peerAssignmentId = (int) $existing['peer_assignment_id'];
            $db->prepare(
                "UPDATE peer_assignments
                 SET cycle_name = :cycle_name,
                     evaluator_user_id = :evaluator_user_id,
                     evaluatee_faculty_id = :evaluatee_faculty_id,
                     evaluator_role = :evaluator_role,
                     assignment_type = 'peer',
                     questionnaire_type = :questionnaire_type,
                     status = :status,
                     deadline = :deadline,
                     is_archived = 0,
                     archived_at = NULL,
                     archived_by = NULL
                 WHERE id = :id"
            )->execute([
                'cycle_name' => (string) $period['period_name'],
                'evaluator_user_id' => (int) $evaluator['id'],
                'evaluatee_faculty_id' => (int) $evaluatee['faculty_id'],
                'evaluator_role' => $role,
                'questionnaire_type' => $questionnaireType,
                'status' => $peerStatus,
                'deadline' => $deadline,
                'id' => $peerAssignmentId,
            ]);
        } else {
            $db->prepare(
                "INSERT INTO peer_assignments
                    (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
                 VALUES
                    (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, :evaluator_role, 'peer', :questionnaire_type, :status, NOW(), :deadline)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    evaluator_role = VALUES(evaluator_role),
                    questionnaire_type = VALUES(questionnaire_type),
                    status = VALUES(status),
                    deadline = VALUES(deadline),
                    is_archived = 0,
                    archived_at = NULL,
                    archived_by = NULL"
            )->execute([
                'cycle_name' => (string) $period['period_name'],
                'evaluator_user_id' => (int) $evaluator['id'],
                'evaluatee_faculty_id' => (int) $evaluatee['faculty_id'],
                'evaluator_role' => $role,
                'questionnaire_type' => $questionnaireType,
                'status' => $peerStatus,
                'deadline' => $deadline,
            ]);
            $peerAssignmentId = (int) $db->lastInsertId();
        }

        if ($existing !== null) {
            $db->prepare(
                "UPDATE peer_evaluation_assignments
                 SET peer_assignment_id = :peer_assignment_id,
                     evaluator_id = :evaluator_id,
                     evaluatee_id = :evaluatee_id,
                     evaluatee_faculty_id = :evaluatee_faculty_id,
                     department_id = :department_id,
                     evaluation_period_id = :evaluation_period_id,
                     status = :status,
                     is_archived = 0,
                     archived_at = NULL,
                     archived_by = NULL
                 WHERE id = :id"
            )->execute([
                'peer_assignment_id' => $peerAssignmentId,
                'evaluator_id' => (int) $evaluator['id'],
                'evaluatee_id' => (int) $evaluatee['id'],
                'evaluatee_faculty_id' => (int) $evaluatee['faculty_id'],
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'evaluation_period_id' => (int) $period['id'],
                'status' => $status,
                'id' => $id,
            ]);
            $assignmentId = $id;
        } else {
            $db->prepare(
                "INSERT INTO peer_evaluation_assignments
                    (peer_assignment_id, evaluator_id, evaluatee_id, evaluatee_faculty_id, department_id, evaluation_period_id, assigned_at, status)
                 VALUES
                    (:peer_assignment_id, :evaluator_id, :evaluatee_id, :evaluatee_faculty_id, :department_id, :evaluation_period_id, NOW(), :status)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    peer_assignment_id = VALUES(peer_assignment_id),
                    evaluatee_id = VALUES(evaluatee_id),
                    evaluatee_faculty_id = VALUES(evaluatee_faculty_id),
                    department_id = VALUES(department_id),
                    status = VALUES(status),
                    is_archived = 0,
                    archived_at = NULL,
                    archived_by = NULL"
            )->execute([
                'peer_assignment_id' => $peerAssignmentId,
                'evaluator_id' => (int) $evaluator['id'],
                'evaluatee_id' => (int) $evaluatee['id'],
                'evaluatee_faculty_id' => (int) $evaluatee['faculty_id'],
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'evaluation_period_id' => (int) $period['id'],
                'status' => $status,
            ]);
            $assignmentId = (int) $db->lastInsertId();
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    notify_create(
        (int) $evaluator['id'],
        'evaluation',
        $existing !== null ? 'Peer Evaluation Assignment Updated' : 'New Peer Evaluation Assignment',
        ($existing !== null ? 'Your peer-to-peer evaluation assignment has been updated. ' : 'You have been assigned a new peer evaluation. ')
            . 'Assigned peer: ' . (string) ($evaluatee['name'] ?? 'your assigned peer') . '.',
        '/faculty/evaluate',
        'peer_assignment',
        $peerAssignmentId
    );

    return ['id' => $assignmentId, 'message' => $existing !== null ? 'Peer assignment updated.' : 'Peer assignment saved.'];
}

function peer_api_archive_or_remove(array $input, array $user, bool $remove): array
{
    peer_api_require_manage($user);
    dipascaf_ensure_peer_evaluation_schema();
    admin_ensure_archive_schema();

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Select a peer assignment first.');
    }

    $assignment = peer_api_require_existing_assignment_scope($user, $id);

    $db = db();
    $db->beginTransaction();
    try {
        if ($remove && ($assignment['status'] ?? '') !== 'completed' && ($assignment['peer_status'] ?? '') !== 'submitted') {
            $peerAssignmentId = (int) ($assignment['peer_assignment_id'] ?? 0);
            $db->prepare('DELETE FROM peer_evaluation_assignments WHERE id = :id')->execute(['id' => $id]);
            if ($peerAssignmentId > 0) {
                $db->prepare('DELETE FROM peer_assignments WHERE id = :id')->execute(['id' => $peerAssignmentId]);
            }
            $message = 'Peer assignment removed.';
        } else {
            $db->prepare(
                'UPDATE peer_evaluation_assignments
                 SET is_archived = 1, archived_at = NOW(), archived_by = :user_id
                 WHERE id = :id'
            )->execute(['user_id' => (int) $user['id'], 'id' => $id]);
            if (!empty($assignment['peer_assignment_id'])) {
                $db->prepare(
                    'UPDATE peer_assignments
                     SET is_archived = 1, archived_at = NOW(), archived_by = :user_id
                     WHERE id = :id'
                )->execute(['user_id' => (int) $user['id'], 'id' => (int) $assignment['peer_assignment_id']]);
            }
            $message = $remove ? 'Completed assignment was archived instead of deleted.' : 'Peer assignment archived.';
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    return ['message' => $message];
}

function peer_api_regenerate_one(array $input, array $user): array
{
    peer_api_require_admin($user);
    dipascaf_ensure_peer_evaluation_schema();

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Select a peer assignment first.');
    }

    $assignment = admin_one(
        'SELECT pea.*, pa.status AS peer_status, eu.role AS evaluator_role
         FROM peer_evaluation_assignments pea
         JOIN users eu ON eu.id = pea.evaluator_id
         LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
         WHERE pea.id = :id
           AND COALESCE(pea.is_archived, 0) = 0
         LIMIT 1',
        ['id' => $id]
    );
    if ($assignment === null) {
        throw new RuntimeException('The selected peer assignment was not found.');
    }
    if (($assignment['status'] ?? '') === 'completed' || ($assignment['peer_status'] ?? '') === 'submitted') {
        throw new RuntimeException('Completed peer assignments cannot be regenerated.');
    }

    $periodId = (int) $assignment['evaluation_period_id'];
    $evaluator = peer_api_user_context((int) $assignment['evaluator_id']);
    if ($evaluator === null) {
        throw new RuntimeException('The evaluator no longer exists in the database.');
    }

    $users = peer_api_setup_payload($user)['users'];
    $candidates = array_values(array_filter($users, static function (array $candidate) use ($evaluator, $assignment): bool {
        if ((int) $candidate['id'] === (int) $evaluator['id']) return false;
        if ((int) $candidate['id'] === (int) ($assignment['evaluatee_id'] ?? 0)) return false;
        if ($evaluator['role'] === 'dean') {
            return $candidate['role'] === 'dean' && !peer_api_departments_match([
                'department_id' => $candidate['departmentId'] ?? 0,
                'department' => $candidate['department'] ?? '',
            ], $evaluator);
        }
        $candidateContext = [
            'department_id' => $candidate['departmentId'] ?? 0,
            'department' => $candidate['department'] ?? '',
            'program' => $candidate['program'] ?? '',
        ];
        if ($evaluator['role'] === 'program_head') {
            return $candidate['role'] === 'program_head' && peer_api_departments_match($candidateContext, $evaluator);
        }
        if ($candidate['role'] !== 'teacher' || !peer_api_departments_match($candidateContext, $evaluator)) {
            return false;
        }
        return true;
    }));

    if ($candidates === []) {
        throw new RuntimeException('No valid peer is available for this evaluator in the selected department rule.');
    }

    $usedRows = admin_all(
        'SELECT evaluatee_id
         FROM peer_evaluation_assignments
         WHERE evaluation_period_id = :period_id
           AND COALESCE(is_archived, 0) = 0
           AND id <> :id',
        ['period_id' => $periodId, 'id' => $id]
    );
    $usedEvaluatees = [];
    foreach ($usedRows as $usedRow) {
        $usedEvaluatees[(int) $usedRow['evaluatee_id']] = true;
    }
    $unusedCandidates = array_values(array_filter($candidates, static function (array $candidate) use ($usedEvaluatees): bool {
        return !isset($usedEvaluatees[(int) $candidate['id']]);
    }));
    if ($unusedCandidates !== []) {
        $candidates = $unusedCandidates;
    }

    shuffle($candidates);
    $selected = null;
    foreach ($candidates as $candidate) {
        $duplicate = admin_one(
            'SELECT id
             FROM peer_evaluation_assignments
             WHERE evaluation_period_id = :period_id
               AND evaluator_id = :evaluator_id
               AND evaluatee_id = :evaluatee_id
               AND COALESCE(is_archived, 0) = 0
               AND id <> :id
             LIMIT 1',
            [
                'period_id' => $periodId,
                'evaluator_id' => (int) $evaluator['id'],
                'evaluatee_id' => (int) $candidate['id'],
                'id' => $id,
            ]
        );
        if ($duplicate === null) {
            $selected = $candidate;
            break;
        }
    }

    if ($selected === null) {
        throw new RuntimeException('This peer assignment already exists for the selected evaluation period.');
    }

    $result = peer_api_save_manual_assignment([
        'id' => $id,
        'evaluation_period_id' => $periodId,
        'evaluator_role' => $evaluator['role'],
        'evaluator_id' => (int) $evaluator['id'],
        'evaluatee_id' => (int) $selected['id'],
        'status' => 'pending',
    ], $user);
    $result['message'] = 'Peer assignment regenerated.';
    return $result;
}

function peer_api_rows(array $user, ?int $periodId, array $departmentScope = [], bool $excludeDeans = false, bool $strictDepartmentScope = false): array
{
    dipascaf_ensure_peer_evaluation_schema();
    dipascaf_ensure_period_participation_schema();
    dipascaf_ensure_form_b_schema();
    dipascaf_ensure_form_a_schema();

    $role = (string) ($user['role'] ?? '');
    $params = [];
    $where = [];

    if ($periodId !== null && $periodId > 0) {
        $where[] = 'pea.evaluation_period_id = :period_id';
        $params['period_id'] = $periodId;
    }

    if ($role === 'program_head') {
        $programScope = peer_api_program_head_scope((int) $user['id']);
        $programCodes = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            $programScope['program_codes'] ?? []
        ))));
        if ($programCodes === []) return [];

        $evaluateeProgramKeys = [];
        $evaluatorProgramKeys = [];
        foreach ($programCodes as $index => $programCode) {
            $evaluateeKey = 'scope_evaluatee_program_' . $index;
            $evaluatorKey = 'scope_evaluator_program_' . $index;
            $evaluateeProgramKeys[] = ':' . $evaluateeKey;
            $evaluatorProgramKeys[] = ':' . $evaluatorKey;
            $params[$evaluateeKey] = $programCode;
            $params[$evaluatorKey] = $programCode;
        }

        $where[] = 'UPPER(COALESCE(NULLIF(ef.program_code, \'\'), NULLIF(efu.program, \'\'))) IN (' . implode(',', $evaluateeProgramKeys) . ')';
        $where[] = 'UPPER(COALESCE(NULLIF(euf.program_code, \'\'), NULLIF(eu.program, \'\'))) IN (' . implode(',', $evaluatorProgramKeys) . ')';
        $where[] = "eu.role = 'teacher'";
        $where[] = "efu.role = 'teacher'";
    } elseif ($role === 'dean' || ($role === 'admin_hr' && $departmentScope !== [])) {
        if ($departmentScope === []) {
            $departmentScope = peer_api_dean_department_scope((int) $user['id']);
        }
        $departments = $departmentScope['departments'] ?? [];
        $departmentIds = $departmentScope['department_ids'] ?? [];
        if ($departments === [] && $departmentIds === []) return [];

        $deptIdKeys = [];
        foreach ($departmentIds as $index => $departmentId) {
            $key = 'dept_id_' . $index;
            $deptIdKeys[] = ':' . $key;
            $params[$key] = (int) $departmentId;
        }
        $facultyKeys = [];
        $evaluatorKeys = [];
        foreach ($departments as $index => $department) {
            $facultyKey = 'dept_faculty_' . $index;
            $evaluatorKey = 'dept_evaluator_' . $index;
            $facultyKeys[] = ':' . $facultyKey;
            $evaluatorKeys[] = ':' . $evaluatorKey;
            $params[$facultyKey] = $department;
            $params[$evaluatorKey] = $department;
        }
        $evaluateeScopeWhere = [];
        if ($deptIdKeys !== []) $evaluateeScopeWhere[] = 'pea.department_id IN (' . implode(',', $deptIdKeys) . ')';
        if ($facultyKeys !== []) $evaluateeScopeWhere[] = 'ef.department IN (' . implode(',', $facultyKeys) . ')';

        $evaluatorScopeWhere = [];
        if ($evaluatorKeys !== []) $evaluatorScopeWhere[] = 'COALESCE(NULLIF(euf.department, \'\'), NULLIF(eu.department, \'\')) IN (' . implode(',', $evaluatorKeys) . ')';

        if ($strictDepartmentScope) {
            $scopeParts = [];
            if ($evaluateeScopeWhere !== []) $scopeParts[] = '(' . implode(' OR ', $evaluateeScopeWhere) . ')';
            if ($evaluatorScopeWhere !== []) $scopeParts[] = '(' . implode(' OR ', $evaluatorScopeWhere) . ')';
            if ($scopeParts !== []) $where[] = '(' . implode(' AND ', $scopeParts) . ')';
        } else {
            $scopeWhere = array_merge($evaluateeScopeWhere, $evaluatorScopeWhere);
            if ($scopeWhere !== []) $where[] = '(' . implode(' OR ', $scopeWhere) . ')';
        }
    } elseif ($role !== 'admin_hr') {
        return [];
    }

    if ($excludeDeans) {
        $where[] = "eu.role <> 'dean'";
        $where[] = "efu.role <> 'dean'";
    }

    $where[] = 'COALESCE(pa.is_archived, 0) = 0';
    $where[] = 'COALESCE(pea.is_archived, 0) = 0';
    $where[] = 'COALESCE(ef.is_archived, 0) = 0';
    $where[] = "pa.status <> 'not_required'";
    $where[] = "NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp_evaluator WHERE epp_evaluator.evaluation_period_id=pea.evaluation_period_id AND epp_evaluator.user_id=pea.evaluator_id AND epp_evaluator.participation_status='excluded')";
    $where[] = "NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp_evaluatee WHERE epp_evaluatee.evaluation_period_id=pea.evaluation_period_id AND epp_evaluatee.user_id=pea.evaluatee_id AND epp_evaluatee.participation_status='excluded')";
    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $canViewResults = in_array($role, ['dean', 'admin_hr'], true);

    $rows = admin_all("
        SELECT
            pea.id,
            pea.peer_assignment_id,
            pea.evaluator_id,
            pea.evaluatee_id,
            pea.evaluation_period_id,
            pea.assigned_at,
            pea.locked_at,
            CASE
                WHEN pea.status = 'completed' OR pa.status = 'submitted' THEN 'completed'
                WHEN pa.deadline IS NOT NULL AND pa.deadline < CURDATE() THEN 'overdue'
                ELSE 'pending'
            END AS display_status,
            pa.deadline,
            ap.period_name,
            eu.full_name AS evaluator_name,
            COALESCE(NULLIF(pa.evaluator_role, ''), eu.role) AS evaluator_role,
            euf.position_title AS evaluator_position,
            COALESCE(NULLIF(euf.department, ''), NULLIF(eu.department, '')) AS evaluator_department,
            COALESCE(NULLIF(euf.program_code, ''), NULLIF(eu.program, '')) AS evaluator_program_code,
            eu.profile_image AS evaluator_profile_image,
            efu.full_name AS evaluatee_user_name,
            CASE
                WHEN COALESCE(NULLIF(pa.evaluator_role, ''), eu.role) = 'program_head'
                 AND EXISTS (
                    SELECT 1
                    FROM programs ph_program
                    WHERE ph_program.program_head_user_id = efu.id
                      AND COALESCE(ph_program.is_active, 1) = 1
                    LIMIT 1
                 )
                THEN 'program_head'
                ELSE efu.role
            END AS evaluatee_user_role,
            ef.full_name AS evaluatee_name,
            ef.department,
            pea.department_id,
            ef.program_code,
            ef.position_title,
            efu.profile_image,
            pa.status AS peer_status,
            pa.assignment_type,
            ROUND(AVG(COALESCE(fbr.average_rating, far.average_rating)), 2) AS average_score
        FROM peer_evaluation_assignments pea
        JOIN users eu ON eu.id = pea.evaluator_id
        JOIN users efu ON efu.id = pea.evaluatee_id
        LEFT JOIN faculty euf ON euf.user_id = eu.id OR euf.email = eu.email
        LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
        LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
        LEFT JOIN appraisal_periods ap ON ap.id = pea.evaluation_period_id
        LEFT JOIN pmas_form_b_category_results fbr ON fbr.assignment_id = pa.id AND COALESCE(fbr.is_archived, 0) = 0
        LEFT JOIN pmas_form_a_category_results far ON far.assignment_id = pa.id AND COALESCE(far.is_archived, 0) = 0
        $whereSql
        GROUP BY pea.id, pa.id, ap.id, eu.id, efu.id, euf.id, ef.id
        ORDER BY ap.id DESC, ef.department, ef.program_code, eu.full_name
    ", $params);

    $rows = array_values(array_filter($rows, static function (array $row): bool {
        $evaluator = [
            'department_id' => 0,
            'department' => (string) ($row['evaluator_department'] ?? ''),
        ];
        $evaluatee = [
            'department_id' => 0,
            'department' => (string) ($row['department'] ?? ''),
        ];
        $evaluatorRole = (string) ($row['evaluator_role'] ?? '');
        $evaluateeRole = (string) ($row['evaluatee_user_role'] ?? '');

        if ($evaluatorRole === 'teacher') {
            return $evaluateeRole === 'teacher' && peer_api_departments_match($evaluator, $evaluatee);
        }

        if ($evaluatorRole === 'program_head') {
            return peer_api_user_can_act_as_role([
                'id' => (int) ($row['evaluatee_id'] ?? 0),
                'role' => $evaluateeRole,
            ], 'program_head') && peer_api_departments_match($evaluator, $evaluatee);
        }

        if ($evaluatorRole === 'dean') {
            return $evaluateeRole === 'dean' && !peer_api_departments_match($evaluator, $evaluatee);
        }

        return false;
    }));

    return array_map(static function (array $row) use ($canViewResults, $role): array {
        return [
            'id' => (int) $row['id'],
            'assignmentId' => (int) ($row['peer_assignment_id'] ?? 0),
            'periodId' => (int) $row['evaluation_period_id'],
            'periodName' => (string) ($row['period_name'] ?? ''),
            'evaluatorId' => (int) $row['evaluator_id'],
            'evaluatorName' => $role === 'teacher' ? 'You' : (string) $row['evaluator_name'],
            'evaluatorRole' => (string) $row['evaluator_role'],
            'evaluatorRoleLabel' => (string) (match ((string) $row['evaluator_role']) {
                'dean' => 'Dean',
                'program_head' => 'Program Head',
                'teacher' => 'Faculty',
                default => ucwords(str_replace('_', ' ', (string) $row['evaluator_role'])),
            }),
            'evaluatorPosition' => (string) ($row['evaluator_position'] ?? $row['evaluator_role'] ?? 'Faculty'),
            'evaluatorDepartment' => (string) ($row['evaluator_department'] ?? ''),
            'evaluatorProgram' => (string) ($row['evaluator_program_code'] ?? ''),
            'evaluatorAvatar' => (string) ($row['evaluator_profile_image'] ?? ''),
            'evaluateeId' => (int) $row['evaluatee_id'],
            'evaluateeName' => (string) ($row['evaluatee_name'] ?: $row['evaluatee_user_name']),
            'evaluateeRole' => (string) ($row['evaluatee_user_role'] ?? ''),
            'evaluateeRoleLabel' => (string) (match ((string) ($row['evaluatee_user_role'] ?? '')) {
                'dean' => 'Dean',
                'program_head' => 'Program Head',
                'teacher' => 'Faculty',
                default => ((stripos((string) ($row['position_title'] ?? ''), 'dean') !== false)
                    ? 'Dean'
                    : ((stripos((string) ($row['position_title'] ?? ''), 'program head') !== false) ? 'Program Head' : 'Faculty')),
            }),
            'departmentId' => (int) ($row['department_id'] ?? 0),
            'department' => (string) ($row['department'] ?? ''),
            'program' => (string) ($row['program_code'] ?? ''),
            'position' => (string) ($row['position_title'] ?? 'Faculty'),
            'avatar' => (string) ($row['profile_image'] ?? ''),
            'status' => (string) $row['display_status'],
            'rawStatus' => (string) ($row['peer_status'] ?? ''),
            'assignmentType' => (string) ($row['assignment_type'] ?? 'peer'),
            'assignmentTypeLabel' => 'Peer-to-Peer',
            'deadline' => (string) ($row['deadline'] ?? ''),
            'assignedAt' => (string) ($row['assigned_at'] ?? ''),
            'locked' => !empty($row['locked_at']),
            'score' => $canViewResults && $row['average_score'] !== null ? (float) $row['average_score'] : null,
        ];
    }, $rows);
}

function peer_api_invalids(?int $periodId, array $departmentScope = []): array
{
    $periodWhere = $periodId !== null && $periodId > 0 ? 'AND evaluation_period_id = ' . (int) $periodId : '';
    $scopeCondition = $departmentScope !== [] ? dipascaf_peer_scope_condition($departmentScope, 'pea', 'ef') : ['sql' => '1 = 1', 'params' => []];
    $params = $scopeCondition['params'];

    $duplicates = admin_all("
        SELECT 'duplicate_evaluator' AS issue, evaluator_id AS subject_id, COUNT(*) AS count
        FROM peer_evaluation_assignments pea
        LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
        WHERE COALESCE(pea.is_archived, 0) = 0
          AND {$scopeCondition['sql']} $periodWhere
        GROUP BY evaluator_id, evaluation_period_id
        HAVING COUNT(*) > 1
        UNION ALL
        SELECT 'self_assignment' AS issue, evaluator_id AS subject_id, COUNT(*) AS count
        FROM peer_evaluation_assignments pea
        LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
        WHERE evaluator_id = evaluatee_id
          AND COALESCE(pea.is_archived, 0) = 0
          AND {$scopeCondition['sql']} $periodWhere
        GROUP BY evaluator_id, evaluation_period_id
    ", $params);

    return $duplicates;
}

function peer_api_lifecycle_payload(int $periodId, array $departmentScope = []): array
{
    dipascaf_ensure_peer_lifecycle_schema();
    dipascaf_ensure_peer_evaluation_schema();

    $scopeCondition = dipascaf_peer_scope_condition($departmentScope, 'pea', 'ef');
    $counts = admin_one("
        SELECT
            COUNT(DISTINCT pea.id) AS total,
            SUM(CASE WHEN pea.status = 'completed' OR pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed
        FROM peer_evaluation_assignments pea
        LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
        LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
        WHERE pea.evaluation_period_id = :period_id
          AND COALESCE(pea.is_archived, 0) = 0
          AND COALESCE(pa.is_archived, 0) = 0
          AND {$scopeCondition['sql']}
    ", ['period_id' => $periodId] + $scopeCondition['params']) ?? [];

    $total = (int) ($counts['total'] ?? 0);
    $completed = (int) ($counts['completed'] ?? 0);
    $pending = max(0, $total - $completed);
    $lifecycle = dipascaf_peer_lifecycle($periodId);

    return $lifecycle + [
        'total' => $total,
        'completed' => $completed,
        'pending' => $pending,
        'canLock' => $total > 0 && !$lifecycle['isLocked'],
        'canUnlock' => $lifecycle['isLocked'] && $pending === 0,
    ];
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : null;

    if ($periodId === null || $periodId <= 0) {
        $period = peer_api_latest_period();
        $periodId = $period ? (int) $period['id'] : null;
    }

    if ($method === 'GET') {
        $departmentScope = [];
        if ((string) ($user['role'] ?? '') === 'dean') {
            $departmentScope = peer_api_dean_department_scope((int) $user['id']);
        } elseif ((string) ($user['role'] ?? '') === 'program_head') {
            $departmentScope = peer_api_program_head_scope((int) $user['id']);
        } elseif ((string) ($user['role'] ?? '') === 'admin_hr' && isset($_GET['department_id'])) {
            $departmentScope = peer_api_department_scope((int) $_GET['department_id']);
        }
        dipascaf_sync_peer_leadership_faculty_records($departmentScope);

        $excludeDeans = isset($_GET['exclude_deans']) && (string) $_GET['exclude_deans'] === '1';
        $strictDepartmentScope = isset($_GET['strict_department']) && (string) $_GET['strict_department'] === '1';
        $rows = peer_api_rows($user, $periodId, $departmentScope, $excludeDeans, $strictDepartmentScope);
        $invalids = $departmentScope !== [] ? peer_api_invalids($periodId, $departmentScope) : [];
        $lifecycle = $periodId !== null ? peer_api_lifecycle_payload((int) $periodId, $departmentScope) : ['status' => 'unlocked', 'isLocked' => false];
        $completed = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'completed'));
        $response = [
            'ok' => true,
            'data' => $rows,
            'invalids' => $invalids,
            'peerLifecycle' => $lifecycle,
            'summary' => [
                'total' => count($rows),
                'completed' => $completed,
                'pending' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'pending')),
                'overdue' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'overdue')),
                'completionRate' => count($rows) > 0 ? round(($completed / count($rows)) * 100) : 0,
            ],
        ];
        if (isset($_GET['setup']) && peer_api_can_manage($user)) {
            $response['setup'] = peer_api_setup_payload($user, $periodId);
        }
        echo json_encode($response);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    peer_api_require_manage($user);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string) ($input['action'] ?? '');
    $departmentScope = [];
    if (($user['role'] ?? '') === 'dean') {
        $departmentScope = peer_api_dean_department_scope((int) $user['id']);
    } elseif (($user['role'] ?? '') === 'program_head') {
        $departmentScope = peer_api_program_head_scope((int) $user['id']);
    } elseif (!empty($input['department_id'])) {
        $departmentScope = peer_api_department_scope((int) $input['department_id']);
    }

    if (($user['role'] ?? '') === 'dean' && ($departmentScope['department_ids'] ?? []) === [] && ($departmentScope['departments'] ?? []) === []) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'No department is assigned to this Dean account.']);
        exit;
    }
    if (($user['role'] ?? '') === 'program_head' && ($departmentScope['program_codes'] ?? []) === []) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'No program is assigned to this Program Head account.']);
        exit;
    }

    $period = null;
    if (!empty($input['evaluation_period_id'])) {
        $period = admin_one('SELECT id, period_name, date_end FROM appraisal_periods WHERE id = :id', ['id' => (int) $input['evaluation_period_id']]);
    }
    if ($period === null) {
        $period = peer_api_latest_period();
    }
    if ($period === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'No active evaluation period found.']);
        exit;
    }

    if (in_array($action, ['generate','regenerate','save','update','validate'], true)) {
        $workflow = admin_one(
            'SELECT participants_finalized_at FROM appraisal_periods WHERE id=:id',
            ['id'=>(int)$period['id']]
        );
        if (empty($workflow['participants_finalized_at'])) {
            http_response_code(409);
            echo json_encode(['ok'=>false,'message'=>'Finalize Evaluation Period Participants before configuring Peer Assignments.']);
            exit;
        }
    }

    $lifecycle = peer_api_lifecycle_payload((int) $period['id'], $departmentScope);
    $setupMutationActions = ['generate', 'regenerate', 'save', 'update', 'archive', 'remove', 'regenerate_one'];
    if (($lifecycle['isLocked'] ?? false) && in_array($action, $setupMutationActions, true)) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'message' => 'Peer-to-peer evaluation is locked and already started. Setup changes are blocked until all assigned peer evaluations are completed.',
            'peerLifecycle' => $lifecycle,
        ]);
        exit;
    }
    if (in_array($action, $setupMutationActions, true)) {
        db()->prepare(
            'UPDATE appraisal_periods SET peer_assignments_validated_at=NULL,peer_assignments_validated_by=NULL WHERE id=:id'
        )->execute(['id'=>(int)$period['id']]);
    }

    if ($action === 'generate' || $action === 'regenerate') {
        peer_api_require_admin($user);
        $peerGroup = (string)($input['peer_group'] ?? 'department');
        if (!in_array($peerGroup, ['department', 'dean'], true)) {
            throw new RuntimeException('Select a valid peer assignment group.');
        }
        $dueDate = (string) ($input['due_date'] ?? $period['due_date'] ?? $period['date_end'] ?? date('Y-m-d', strtotime('+30 days')));
        $summary = dipascaf_generate_peer_evaluation_assignments(
            (int) $period['id'],
            (string) $period['period_name'],
            $dueDate,
            (bool) ($input['include_program_heads'] ?? true),
            $action === 'regenerate',
            $departmentScope,
            $peerGroup
        );
        $created = (int) ($summary['created'] ?? 0);
        $message = $action === 'regenerate'
            ? "{$created} peer assignment(s) regenerated successfully."
            : "{$created} peer assignment(s) generated successfully.";
        echo json_encode(['ok' => true, 'message' => $message, 'summary' => $summary]);
        exit;
    }

    if ($action === 'validate') {
        peer_api_require_admin($user);
        $invalid = admin_all(
            "SELECT pea.id FROM peer_evaluation_assignments pea
             LEFT JOIN evaluation_period_participation evaluator
               ON evaluator.evaluation_period_id=pea.evaluation_period_id AND evaluator.user_id=pea.evaluator_id
             LEFT JOIN evaluation_period_participation evaluatee
               ON evaluatee.evaluation_period_id=pea.evaluation_period_id AND evaluatee.user_id=pea.evaluatee_id
             WHERE pea.evaluation_period_id=:period_id AND COALESCE(pea.is_archived,0)=0
               AND (pea.evaluator_id=pea.evaluatee_id
                 OR evaluator.participation_status<>'included' OR evaluator.work_status<>'active'
                 OR evaluator.employment_status NOT IN ('active','newly_added')
                 OR evaluatee.participation_status<>'included' OR evaluatee.work_status<>'active'
                 OR evaluatee.employment_status NOT IN ('active','newly_added'))",
            ['period_id'=>(int)$period['id']]
        );
        $total = (int)(admin_one(
            'SELECT COUNT(*) total FROM peer_evaluation_assignments WHERE evaluation_period_id=:period_id AND COALESCE(is_archived,0)=0',
            ['period_id'=>(int)$period['id']]
        )['total'] ?? 0);
        if ($total === 0) throw new DomainException('Create peer assignments before validation.');
        if ($invalid !== []) throw new DomainException('Peer assignments contain excluded, inactive, missing, or self-assigned participants.');
        db()->prepare(
            'UPDATE appraisal_periods SET peer_assignments_validated_at=NOW(),peer_assignments_validated_by=:actor WHERE id=:id'
        )->execute(['actor'=>(int)$user['id'],'id'=>(int)$period['id']]);
        echo json_encode(['ok'=>true,'message'=>'Peer assignments validated. The evaluation period can now be activated.','validated_count'=>$total]);
        exit;
    }

    if ($action === 'lock') {
        peer_api_require_admin($user);
        dipascaf_ensure_peer_evaluation_schema();
        $lifecycle = peer_api_lifecycle_payload((int) $period['id'], $departmentScope);
        if ((int) ($lifecycle['total'] ?? 0) === 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Create and save peer-to-peer assignments before locking the evaluation.']);
            exit;
        }
        $scopeCondition = dipascaf_peer_scope_condition($departmentScope, 'pea', 'ef');
        db()->prepare("
            UPDATE peer_evaluation_assignments pea
            LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
            SET pea.locked_at = COALESCE(pea.locked_at, NOW())
            WHERE pea.evaluation_period_id = :id
              AND COALESCE(pea.is_archived, 0) = 0
              AND {$scopeCondition['sql']}
        ")->execute(['id' => (int) $period['id']] + $scopeCondition['params']);
        dipascaf_set_peer_lifecycle((int) $period['id'], 'locked', (int) $user['id']);
        $notifyRows = admin_all("
            SELECT pea.peer_assignment_id, pea.evaluator_id, ef.full_name AS evaluatee_name
            FROM peer_evaluation_assignments pea
            LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
            LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
            WHERE pea.evaluation_period_id = :id
              AND COALESCE(pea.is_archived, 0) = 0
              AND COALESCE(pa.is_archived, 0) = 0
              AND {$scopeCondition['sql']}
        ", ['id' => (int) $period['id']] + $scopeCondition['params']);
        foreach ($notifyRows as $notifyRow) {
            notify_create(
                (int) ($notifyRow['evaluator_id'] ?? 0),
                'evaluation',
                'Peer-to-Peer Evaluation Started',
                'Your peer-to-peer evaluation assignment for ' . (string) $period['period_name'] . ' is now available.',
                '/faculty/evaluate',
                'peer_assignment',
                (int) ($notifyRow['peer_assignment_id'] ?? 0)
            );
        }
        echo json_encode([
            'ok' => true,
            'message' => 'Peer-to-peer evaluation locked. Users may now start their assigned peer evaluations.',
            'peerLifecycle' => peer_api_lifecycle_payload((int) $period['id'], $departmentScope),
        ]);
        exit;
    }

    if ($action === 'unlock') {
        peer_api_require_admin($user);
        $lifecycle = peer_api_lifecycle_payload((int) $period['id'], $departmentScope);
        if ((int) ($lifecycle['pending'] ?? 0) > 0) {
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'message' => 'Peer-to-peer evaluation cannot be unlocked while assigned evaluations are still pending. Complete all peer evaluations first.',
                'peerLifecycle' => $lifecycle,
            ]);
            exit;
        }
        dipascaf_set_peer_lifecycle((int) $period['id'], 'unlocked', (int) $user['id']);
        echo json_encode([
            'ok' => true,
            'message' => 'Peer-to-peer evaluation unlocked. User access to peer evaluation is now stopped.',
            'peerLifecycle' => peer_api_lifecycle_payload((int) $period['id'], $departmentScope),
        ]);
        exit;
    }

    if ($action === 'save' || $action === 'update') {
        $result = peer_api_save_manual_assignment($input, $user);
        echo json_encode(['ok' => true] + $result);
        exit;
    }

    if ($action === 'archive' || $action === 'remove') {
        $result = peer_api_archive_or_remove($input, $user, $action === 'remove');
        echo json_encode(['ok' => true] + $result);
        exit;
    }

    if ($action === 'regenerate_one') {
        $result = peer_api_regenerate_one($input, $user);
        echo json_encode(['ok' => true] + $result);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unknown peer assignment action.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
