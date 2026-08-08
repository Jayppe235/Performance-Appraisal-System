<?php

declare(strict_types=1);

function people_assignments_admin_authorized(?array $user): bool
{
    return $user !== null && (string) ($user['role'] ?? '') === 'admin_hr';
}

function people_validate_assignment(PDO $db, string $role, string $department, string $program, int $excludeUserId = 0): array
{
    $department = trim($department);
    $program = strtoupper(trim($program));

    if (!in_array($role, ['admin_hr', 'vpaa', 'dean', 'program_head', 'teacher'], true)) {
        throw new DomainException('Valid role is required.');
    }

    if (in_array($role, ['admin_hr', 'vpaa'], true)) {
        return ['department' => '', 'department_id' => null, 'program' => '', 'program_id' => null];
    }

    if ($department === '') {
        throw new DomainException('Department is required for faculty, Dean, and Program Head accounts.');
    }

    $stmt = $db->prepare(
        'SELECT id, department_code, department_name
         FROM departments
         WHERE is_active = 1 AND (department_name = :department_name OR department_code = :department_code)
         LIMIT 1'
    );
    $stmt->execute(['department_name' => $department, 'department_code' => $department]);
    $departmentRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($departmentRecord === null) {
        throw new DomainException('Select a valid department before saving this account.');
    }

    if ($role === 'program_head' && $program === '') {
        throw new DomainException('Select a program/course for this account.');
    }

    $programRecord = null;
    if ($program !== '') {
        $sql =
            'SELECT id, program_code, program_head_user_id
             FROM programs
             WHERE is_active = 1 AND department_id = :department_id AND program_code = :program
             LIMIT 1';
        if ($db->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute(['department_id' => (int) $departmentRecord['id'], 'program' => $program]);
        $programRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($programRecord === null) {
            throw new DomainException('The selected program/course does not belong to this department.');
        }
    }

    if ($role === 'program_head' && $programRecord !== null) {
        $assignedHeadId = (int) ($programRecord['program_head_user_id'] ?? 0);
        if ($assignedHeadId > 0 && $assignedHeadId !== $excludeUserId) {
            throw new DomainException('This program already has another active Program Head. Remove or reassign that Program Head first.');
        }

        $stmt = $db->prepare(
            "SELECT id FROM users
             WHERE role = 'program_head' AND is_active = 1 AND program = :program
               AND department = :department AND id <> :exclude_user_id
             LIMIT 1"
        );
        $stmt->execute([
            'program' => $program,
            'department' => (string) $departmentRecord['department_name'],
            'exclude_user_id' => $excludeUserId,
        ]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new DomainException('There is already a Program Head assigned to this program/course.');
        }
    }

    return [
        'department' => (string) $departmentRecord['department_name'],
        'department_id' => (int) $departmentRecord['id'],
        'program' => $program,
        'program_id' => $programRecord !== null ? (int) $programRecord['id'] : null,
    ];
}

function people_validate_program_head_programs(PDO $db, int $departmentId, array $programCodes, int $excludeUserId = 0): array
{
    $programCodes = array_values(array_unique(array_filter(array_map(
        static fn($code): string => strtoupper(trim((string)$code)),
        $programCodes
    ))));
    if ($programCodes === []) throw new DomainException('Select at least one program/course for this Program Head.');
    $ids = [];
    foreach ($programCodes as $code) {
        $stmt = $db->prepare(
            'SELECT id,program_head_user_id FROM programs
             WHERE department_id=? AND program_code=? AND is_active=1 LIMIT 1'
                . ($db->inTransaction() ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$departmentId,$code]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$program) throw new DomainException('Every selected program must belong to the selected department.');
        $headId = (int)($program['program_head_user_id'] ?? 0);
        if ($headId > 0 && $headId !== $excludeUserId) {
            throw new DomainException($code . ' already has another active Program Head.');
        }
        $legacy = $db->prepare(
            "SELECT id FROM users WHERE role='program_head' AND is_active=1
             AND department=(SELECT department_name FROM departments WHERE id=?)
             AND program=? AND id<>? LIMIT 1"
        );
        $legacy->execute([$departmentId,$code,$excludeUserId]);
        if ($legacy->fetchColumn()) throw new DomainException($code . ' already has another active Program Head.');
        $ids[] = (int)$program['id'];
    }
    return $ids;
}

function people_sync_leadership_assignments(PDO $db, int $userId, string $role, ?int $departmentId, ?int $programId, array $programIds = []): void
{
    $db->prepare('UPDATE departments SET dean_user_id = NULL WHERE dean_user_id = ?')->execute([$userId]);
    $db->prepare('UPDATE programs SET program_head_user_id = NULL WHERE program_head_user_id = ?')->execute([$userId]);

    if ($role === 'dean' && $departmentId !== null) {
        $db->prepare('UPDATE departments SET dean_user_id = ? WHERE id = ?')->execute([$userId, $departmentId]);
    }

    if ($role === 'program_head') {
        $programIds = array_values(array_unique(array_filter(array_map('intval', $programIds))));
        if ($programIds === [] && $programId !== null) $programIds = [$programId];
        $stmt = $db->prepare('UPDATE programs SET program_head_user_id = ? WHERE id = ?');
        foreach ($programIds as $id) $stmt->execute([$userId,$id]);
    }
}
