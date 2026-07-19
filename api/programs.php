<?php
/**
 * Programs API
 *
 * GET /api/programs.php — List all active programs
 * GET /api/programs.php?department_code=CAS — Filter by department code
 * POST /api/programs.php — Create a new program
 * PUT /api/programs.php — Update an existing program
 * DELETE /api/programs.php — Archive (soft-delete) a program
 *
 * Returns JSON with program details.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/http.php';

header('Content-Type: application/json; charset=utf-8');
allow_local_dev_cors(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);

function program_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function program_response(int $status, bool $ok, string $message, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function program_load_by_id(int $id): ?array {
    return admin_one(
        'SELECT p.*, d.department_code, d.department_name, u.full_name AS program_head_name, u.email AS program_head_email
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         LEFT JOIN users u ON u.id = p.program_head_user_id
         WHERE p.id = :id',
        ['id' => $id]
    );
}

function program_format(array $program): array {
    return [
        'id' => (int) $program['id'],
        'code' => $program['program_code'] ?? '',
        'name' => $program['program_name'] ?? '',
        'department_id' => (int) ($program['department_id'] ?? 0),
        'department_code' => $program['department_code'] ?? '',
        'department_name' => $program['department_name'] ?? '',
        'program_head' => $program['program_head_name'] ?? '',
        'program_head_email' => $program['program_head_email'] ?? '',
        'program_head_user_id' => $program['program_head_user_id'] ? (int) $program['program_head_user_id'] : null,
        'is_active' => (int) ($program['is_active'] ?? 1),
    ];
}

function program_department_key(?string $value): string {
    $text = strtolower(trim((string) $value));
    if ($text === '') return '';

    if (
        $text === 'cite' ||
        $text === 'cit' ||
        str_contains($text, 'information technology') ||
        str_contains($text, 'computer')
    ) {
        return 'cite';
    }

    if ($text === 'coed' || str_contains($text, 'education')) return 'coed';
    if ($text === 'cba' || str_contains($text, 'business') || str_contains($text, 'accountancy')) return 'cba';
    if ($text === 'cas' || str_contains($text, 'arts') || str_contains($text, 'sciences')) return 'cas';
    if ($text === 'cn' || str_contains($text, 'nursing')) return 'cn';

    return preg_replace('/[^a-z0-9]+/', '', $text) ?: $text;
}

function program_head_matches_department(int $programHeadUserId, int $departmentId): bool {
    $row = admin_one(
        'SELECT u.department AS user_department, d.department_code, d.department_name
         FROM users u
         JOIN departments d ON d.id = :department_id
         WHERE u.id = :user_id AND u.role = "program_head" AND u.is_active = 1
         LIMIT 1',
        ['user_id' => $programHeadUserId, 'department_id' => $departmentId]
    );

    if ($row === null) return false;

    $userKey = program_department_key($row['user_department'] ?? '');
    $departmentCodeKey = program_department_key($row['department_code'] ?? '');
    $departmentNameKey = program_department_key($row['department_name'] ?? '');

    return $userKey !== '' && ($userKey === $departmentCodeKey || $userKey === $departmentNameKey);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ── Mutations require authentication ─────────────────────────────
    if ($method !== 'GET') {
        $currentUser = current_user();
        if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin_hr') {
            program_response(401, false, 'Unauthorized. Admin access required.');
        }
    }

    if ($method === 'POST') {
        $input = program_input();
        $departmentId = (int) ($input['department_id'] ?? 0);
        $programCode = strtoupper(trim((string) ($input['code'] ?? $input['program_code'] ?? '')));
        $programName = trim((string) ($input['name'] ?? $input['program_name'] ?? ''));
        $programHeadUserId = !empty($input['program_head_user_id']) ? (int) $input['program_head_user_id'] : null;
        $isActive = isset($input['is_active']) ? (int) ((bool) $input['is_active']) : 1;

        if ($departmentId <= 0 || $programCode === '' || $programName === '') {
            program_response(400, false, 'Department, program code, and program name are required.');
        }

        $department = admin_one('SELECT id FROM departments WHERE id = :id AND is_active = 1', ['id' => $departmentId]);
        if ($department === null) {
            program_response(400, false, 'Selected department is not valid.');
        }

        if ($programHeadUserId !== null && $programHeadUserId > 0) {
            $programHead = admin_one('SELECT id FROM users WHERE id = :id AND role = "program_head" AND is_active = 1', ['id' => $programHeadUserId]);
            if ($programHead === null) {
                program_response(400, false, 'Selected program head is not a valid active Program Head account.');
            }
            if (!program_head_matches_department($programHeadUserId, $departmentId)) {
                program_response(400, false, 'Selected Program Head must belong to this department.');
            }
        }

        // Check for duplicate program code within the same department
        $existing = admin_one(
            'SELECT id FROM programs WHERE department_id = :dept_id AND (LOWER(program_code) = LOWER(:code) OR LOWER(program_name) = LOWER(:name))',
            ['code' => $programCode, 'name' => $programName, 'dept_id' => $departmentId]
        );
        if ($existing !== null) {
            program_response(409, false, 'A program with this name or code already exists in this department.');
        }

        $stmt = db()->prepare(
            'INSERT INTO programs (department_id, program_code, program_name, program_head_user_id, is_active)
             VALUES (:department_id, :program_code, :program_name, :program_head_user_id, :is_active)'
        );
        $stmt->execute([
            'department_id' => $departmentId,
            'program_code' => $programCode,
            'program_name' => $programName,
            'program_head_user_id' => $programHeadUserId,
            'is_active' => $isActive,
        ]);
        $newId = (int) db()->lastInsertId();

        admin_activity('Created program: ' . $programCode . ' - ' . $programName);

        $saved = program_load_by_id($newId);
        program_response(200, true, 'Program created successfully.', [
            'program' => $saved !== null ? program_format($saved) : null,
        ]);
    }

    if ($method === 'PUT') {
        $input = program_input();
        $id = (int) ($input['id'] ?? 0);
        $programCode = strtoupper(trim((string) ($input['code'] ?? $input['program_code'] ?? '')));
        $programName = trim((string) ($input['name'] ?? $input['program_name'] ?? ''));
        $programHeadUserId = isset($input['program_head_user_id'])
            ? (!empty($input['program_head_user_id']) ? (int) $input['program_head_user_id'] : null)
            : null;
        $isActive = isset($input['is_active']) ? (int) $input['is_active'] : null;

        if ($id <= 0) {
            program_response(400, false, 'Program ID is required.');
        }

        $existing = admin_one('SELECT * FROM programs WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            program_response(404, false, 'Program not found.');
        }

        // Use existing values for fields not provided
        if ($programCode === '') $programCode = $existing['program_code'];
        if ($programName === '') $programName = $existing['program_name'];
        if ($programHeadUserId === null) $programHeadUserId = $existing['program_head_user_id'] ? (int) $existing['program_head_user_id'] : null;
        if ($isActive === null) $isActive = (int) $existing['is_active'];

        if ($programCode === '' || $programName === '') {
            program_response(400, false, 'Program code and name are required.');
        }

        if ($programHeadUserId !== null && $programHeadUserId > 0) {
            $programHead = admin_one('SELECT id FROM users WHERE id = :id AND role = "program_head" AND is_active = 1', ['id' => $programHeadUserId]);
            if ($programHead === null) {
                program_response(400, false, 'Selected program head is not a valid active Program Head account.');
            }
            if (!program_head_matches_department($programHeadUserId, (int) $existing['department_id'])) {
                program_response(400, false, 'Selected Program Head must belong to this department.');
            }
        }

        // Check for duplicate program code (excluding current program)
        $duplicate = admin_one(
            'SELECT id FROM programs WHERE program_code = :code AND department_id = :dept_id AND id <> :id',
            ['code' => $programCode, 'dept_id' => $existing['department_id'], 'id' => $id]
        );
        if ($duplicate !== null) {
            program_response(409, false, 'Another program already uses this code in this department.');
        }

        $db = db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE programs
                 SET program_code = :program_code, program_name = :program_name,
                     program_head_user_id = :program_head_user_id, is_active = :is_active
                 WHERE id = :id'
            );
            $stmt->execute([
                'program_code' => $programCode,
                'program_name' => $programName,
                'program_head_user_id' => $programHeadUserId,
                'is_active' => $isActive,
                'id' => $id,
            ]);

            if ((int) ($existing['program_head_user_id'] ?? 0) !== (int) ($programHeadUserId ?? 0)) {
                $period = dipascaf_current_evaluation_period();
                $periodName = trim((string) ($period['period_name'] ?? ''));
                $deadline = trim((string) ($period['date_end'] ?? ''));
                if ($periodName !== '' && $deadline !== '') {
                    dipascaf_upsert_required_assignments_for_period($periodName, $deadline);
                }
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        admin_activity('Updated program: ' . $programCode);

        $saved = program_load_by_id($id);
        program_response(200, true, 'Program updated successfully.', [
            'program' => $saved !== null ? program_format($saved) : null,
        ]);
    }

    if ($method === 'DELETE') {
        $input = program_input();
        $id = (int) ($input['id'] ?? 0);

        if ($id <= 0) {
            program_response(400, false, 'Program ID is required.');
        }

        $existing = admin_one('SELECT * FROM programs WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            program_response(404, false, 'Program not found.');
        }

        // Soft-delete: set is_active = 0
        db()->prepare('UPDATE programs SET is_active = 0, program_head_user_id = NULL WHERE id = :id')->execute(['id' => $id]);

        admin_activity('Archived program: ' . ($existing['program_code'] ?? 'Unknown'));

        program_response(200, true, 'Program archived successfully.');
    }

    // ── GET: List programs ─────────────────────────────────────────
    $departmentCode = $_GET['department_code'] ?? '';
    $departmentId = (int) ($_GET['department_id'] ?? 0);
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
    $programs = admin_programs();

    // Filter by department code
    if ($departmentCode !== '') {
        $filtered = [];
        $codeUpper = strtoupper(trim($departmentCode));
        foreach ($programs as $program) {
            if (strtoupper($program['department_code'] ?? '') === $codeUpper) {
                $filtered[] = $program;
            }
        }
        $programs = $filtered;
    }

    // Filter by department ID
    if ($departmentId > 0) {
        $filtered = [];
        foreach ($programs as $program) {
            if ((int) ($program['department_id'] ?? 0) === $departmentId) {
                $filtered[] = $program;
            }
        }
        $programs = $filtered;
    }

    // Optionally show all (including inactive)
    if (!$includeInactive) {
        $filtered = [];
        foreach ($programs as $program) {
            if ((int) ($program['is_active'] ?? 1) === 1) {
                $filtered[] = $program;
            }
        }
        $programs = $filtered;
    }

    // Get faculty count per program
    $facultyCounts = [];
    try {
        $countStmt = db()->query(
            'SELECT COALESCE(NULLIF(program_code, ""), "Unassigned Program") AS prog_code, COUNT(*) AS cnt
             FROM faculty WHERE is_archived = 0 GROUP BY program_code'
        );
        while ($row = $countStmt->fetch()) {
            $facultyCounts[$row['prog_code']] = (int) $row['cnt'];
        }
    } catch (Throwable) {
        // Non-fatal
    }

    // Get available program heads (users with program_head role who are active)
    $availableHeads = admin_all(
        'SELECT id, full_name, email, department, program FROM users WHERE role = "program_head" AND is_active = 1 ORDER BY department, full_name'
    );

    // Get available program heads not currently assigned, plus their current assignment
    $headAssignments = [];
    foreach ($programs as $program) {
        if (!empty($program['program_head_user_id'])) {
            $uid = (int) $program['program_head_user_id'];
            if (!isset($headAssignments[$uid])) {
                $headAssignments[$uid] = [];
            }
            $headAssignments[$uid][] = $program['program_code'];
        }
    }

    $result = array_map(static function (array $program) use ($facultyCounts): array {
        $progCode = $program['program_code'] ?? '';
        return [
            'id' => (int) $program['id'],
            'code' => $progCode,
            'name' => $program['program_name'] ?? '',
            'department_id' => (int) ($program['department_id'] ?? 0),
            'department_code' => $program['department_code'] ?? '',
            'department_name' => $program['department_name'] ?? '',
            'program_head' => $program['program_head_name'] ?? '',
            'program_head_email' => $program['program_head_email'] ?? '',
            'program_head_user_id' => $program['program_head_user_id'] ? (int) $program['program_head_user_id'] : null,
            'is_active' => (int) ($program['is_active'] ?? 1),
            'faculty_count' => $facultyCounts[$progCode] ?? 0,
        ];
    }, $programs);

    echo json_encode([
        'ok' => true,
        'data' => $result,
        'total' => count($result),
        'available_heads' => array_map(static function (array $user): array {
            return [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'] ?? '',
                'email' => $user['email'] ?? '',
                'department' => $user['department'] ?? '',
                'program' => $user['program'] ?? '',
            ];
        }, $availableHeads),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
