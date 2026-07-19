<?php
/**
 * Departments API
 *
 * GET /api/departments.php — List all active departments
 * POST /api/departments.php — Create department
 * PUT /api/departments.php — Update department
 * Returns JSON with department code, name, dean info, and program count.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/http.php';

header('Content-Type: application/json; charset=utf-8');
allow_local_dev_cors(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);

function departmentInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function departmentResponse(int $status, bool $ok, string $message, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (isset($_POST['_method'])) {
        $method = strtoupper((string) $_POST['_method']);
    }
    admin_ensure_department_logo_schema();
    admin_ensure_department_details_schema();
    $currentUser = current_user();
    $currentRole = (string) ($currentUser['role'] ?? '');
    $isAdmin = $currentRole === 'admin_hr';
    $isDean = $currentRole === 'dean';

    if ($method !== 'GET') {
        if (!$currentUser || (!$isAdmin && !($isDean && $method === 'PUT'))) {
            departmentResponse(401, false, 'Unauthorized. Admin access required.');
        }

        $input = departmentInput();
        $id = (int) ($input['id'] ?? 0);
        $action = trim((string) ($input['action'] ?? ''));

        if ($method === 'DELETE') {
            if (!$isAdmin) {
                departmentResponse(403, false, 'Only Admin/HR can archive departments.');
            }
            if ($id <= 0) {
                departmentResponse(400, false, 'Department ID is required.');
            }

            $exists = admin_one('SELECT id FROM departments WHERE id = :id', ['id' => $id]);
            if ($exists === null) {
                departmentResponse(404, false, 'Department not found.');
            }

            db()->prepare('UPDATE departments SET is_active = 0, dean_user_id = NULL WHERE id = :id')->execute(['id' => $id]);
            db()->prepare('UPDATE programs SET is_active = 0, program_head_user_id = NULL WHERE department_id = :id')->execute(['id' => $id]);
            departmentResponse(200, true, 'Department archived successfully.');
        }

        if ($method === 'PUT' && $action === 'restore') {
            if (!$isAdmin) {
                departmentResponse(403, false, 'Only Admin/HR can restore departments.');
            }
            if ($id <= 0) {
                departmentResponse(400, false, 'Department ID is required.');
            }

            $exists = admin_one('SELECT id FROM departments WHERE id = :id', ['id' => $id]);
            if ($exists === null) {
                departmentResponse(404, false, 'Department not found.');
            }

            db()->prepare('UPDATE departments SET is_active = 1 WHERE id = :id')->execute(['id' => $id]);
            departmentResponse(200, true, 'Department restored successfully.');
        }

        $code = strtoupper(trim((string) ($input['code'] ?? $input['department_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? $input['department_name'] ?? ''));
        $deanUserId = (int) ($input['dean_user_id'] ?? 0);
        $isActive = isset($input['is_active']) ? (int) ((bool) $input['is_active']) : 1;
        $description = trim((string) ($input['description'] ?? ''));
        $departmentType = trim((string) ($input['department_type'] ?? 'Academic Department'));
        $contactEmail = strtolower(trim((string) ($input['contact_email'] ?? '')));
        $officeLocation = trim((string) ($input['office_location'] ?? ''));

        if ($code === '' || $name === '') {
            departmentResponse(400, false, 'Department code and name are required.');
        }
        if (!preg_match('/^[A-Z0-9-]{2,10}$/', $code)) {
            departmentResponse(400, false, 'Department code must contain 2 to 10 uppercase letters, numbers, or hyphens.');
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            departmentResponse(400, false, 'Enter a valid department contact email address.');
        }

        $duplicateSql = 'SELECT id FROM departments WHERE (LOWER(department_code) = LOWER(:code) OR LOWER(department_name) = LOWER(:name))';
        $duplicateParams = ['code' => $code, 'name' => $name];
        if ($id > 0) {
            $duplicateSql .= ' AND id <> :current_id';
            $duplicateParams['current_id'] = $id;
        }
        $duplicate = admin_one($duplicateSql, $duplicateParams);
        if ($duplicate !== null) {
            departmentResponse(409, false, 'A department with this name or code already exists.');
        }

        if ($deanUserId > 0) {
            $dean = admin_one('SELECT id FROM users WHERE id = :id AND role = "dean" AND is_active = 1', ['id' => $deanUserId]);
            if ($dean === null) {
                departmentResponse(400, false, 'Select a valid Dean account.');
            }
            $deanAssignmentSql = 'SELECT id, department_name FROM departments WHERE dean_user_id = :dean_user_id';
            $deanAssignmentParams = ['dean_user_id' => $deanUserId];
            if ($id > 0) {
                $deanAssignmentSql .= ' AND id <> :department_id';
                $deanAssignmentParams['department_id'] = $id;
            }
            $deanAssignment = admin_one($deanAssignmentSql . ' LIMIT 1', $deanAssignmentParams);
            if ($deanAssignment !== null) {
                departmentResponse(409, false, 'This Dean is already assigned to ' . ($deanAssignment['department_name'] ?? 'another department') . '.');
            }
        }

        $departmentLogo = null;
        try {
            $departmentLogo = admin_department_logo_upload($code);
        } catch (RuntimeException $exception) {
            departmentResponse(422, false, $exception->getMessage());
        }

        if ($method === 'POST') {
            if (!$isAdmin) {
                departmentResponse(403, false, 'Only Admin/HR can create departments.');
            }
            $stmt = db()->prepare(
                'INSERT INTO departments (department_code, department_name, dean_user_id, logo_image, is_active, description, department_type, contact_email, office_location)
                 VALUES (:code, :name, :dean_user_id, :logo_image, :is_active, :description, :department_type, :contact_email, :office_location)
                 ON DUPLICATE KEY UPDATE
                    department_name = VALUES(department_name),
                    dean_user_id = VALUES(dean_user_id),
                    logo_image = COALESCE(VALUES(logo_image), logo_image),
                    is_active = 1'
            );
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'dean_user_id' => $deanUserId > 0 ? $deanUserId : null,
                'logo_image' => $departmentLogo,
                'is_active' => $isActive,
                'description' => $description ?: null,
                'department_type' => $departmentType,
                'contact_email' => $contactEmail ?: null,
                'office_location' => $officeLocation ?: null,
            ]);
            $saved = admin_one('SELECT id FROM departments WHERE department_code = :code', ['code' => $code]);
            $id = (int) ($saved['id'] ?? db()->lastInsertId());
        } elseif ($method === 'PUT') {
            if ($id <= 0) {
                departmentResponse(400, false, 'Department ID is required.');
            }

            $exists = admin_one('SELECT id, dean_user_id FROM departments WHERE id = :id', ['id' => $id]);
            if ($exists === null) {
                departmentResponse(404, false, 'Department not found.');
            }
            if ($isDean && (int) ($exists['dean_user_id'] ?? 0) !== (int) ($currentUser['id'] ?? 0)) {
                departmentResponse(403, false, 'This Dean can only update the assigned department.');
            }
            if ($isDean) {
                $deanUserId = (int) ($exists['dean_user_id'] ?? 0);
            }

            $duplicate = admin_one('SELECT id FROM departments WHERE department_code = :code AND id <> :id', ['code' => $code, 'id' => $id]);
            if ($duplicate !== null) {
                departmentResponse(409, false, 'Another department already uses this code.');
            }

            $params = [
                'code' => $code,
                'name' => $name,
                'dean_user_id' => $deanUserId > 0 ? $deanUserId : null,
                'is_active' => $isActive,
                'description' => $description ?: null,
                'department_type' => $departmentType,
                'id' => $id,
            ];
            $logoSql = '';
            if ($departmentLogo !== null) {
                $logoSql = ', logo_image = :logo_image';
                $params['logo_image'] = $departmentLogo;
            }

            $stmt = db()->prepare(
                'UPDATE departments
                 SET department_code = :code, department_name = :name, dean_user_id = :dean_user_id, is_active = :is_active,
                     description = :description, department_type = :department_type' . $logoSql . '
                 WHERE id = :id'
            );
            $stmt->execute($params);
        } else {
            departmentResponse(405, false, 'Method not allowed.');
        }

        if ($deanUserId > 0) {
            db()->prepare('UPDATE users SET department = :department WHERE id = :id')->execute([
                'department' => $name,
                'id' => $deanUserId,
            ]);
        }

        $dept = admin_one(
            'SELECT d.*, u.full_name AS dean_name, u.email AS dean_email
             FROM departments d
             LEFT JOIN users u ON u.id = d.dean_user_id
             WHERE d.id = :id',
            ['id' => $id]
        );

        departmentResponse(200, true, 'Department saved successfully.', [
            'department' => [
                'id' => (int) $dept['id'],
                'code' => $dept['department_code'] ?? '',
                'name' => $dept['department_name'] ?? '',
                'dean' => $dept['dean_name'] ?? '',
                'dean_email' => $dept['dean_email'] ?? '',
                'dean_user_id' => $dept['dean_user_id'] ? (int) $dept['dean_user_id'] : null,
                'logo' => $dept['logo_image'] ?? '/assets/images/ndmc-seal.png',
                'programs' => 0,
                'is_active' => (int) ($dept['is_active'] ?? 1),
                'description' => $dept['description'] ?? '',
                'department_type' => $dept['department_type'] ?? 'Academic Department',
                'contact_email' => $dept['contact_email'] ?? '',
                'office_location' => $dept['office_location'] ?? '',
            ],
        ]);
    }

    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
    $params = ['include_inactive' => $includeInactive ? 1 : 0];
    $deanWhere = '';
    if ($isDean) {
        $deanWhere = ' AND d.dean_user_id = :dean_user_id';
        $params['dean_user_id'] = (int) ($currentUser['id'] ?? 0);
    }
    $departments = admin_all(
        'SELECT d.*, u.full_name AS dean_name, u.email AS dean_email
         FROM departments d
         LEFT JOIN users u ON u.id = d.dean_user_id
         WHERE (:include_inactive = 1 OR d.is_active = 1)' . $deanWhere . '
         ORDER BY d.department_name',
        $params
    );

    // Add program count per department
    $programs = $includeInactive
        ? admin_all(
            'SELECT p.*, d.department_code, d.department_name, u.full_name AS program_head_name, u.email AS program_head_email
             FROM programs p
             JOIN departments d ON d.id = p.department_id
             LEFT JOIN users u ON u.id = p.program_head_user_id
             ORDER BY d.department_name, p.program_name'
        )
        : admin_all(
            'SELECT p.*, d.department_code, d.department_name, u.full_name AS program_head_name, u.email AS program_head_email
             FROM programs p
             JOIN departments d ON d.id = p.department_id
             LEFT JOIN users u ON u.id = p.program_head_user_id
             WHERE p.is_active = 1 AND d.is_active = 1
             ORDER BY d.department_code, p.program_name'
        );
    $programCounts = [];
    foreach ($programs as $program) {
        $deptId = (int) ($program['department_id'] ?? 0);
        $programCounts[$deptId] = ($programCounts[$deptId] ?? 0) + 1;
    }

    $userRows = admin_all(
        'SELECT department, COUNT(*) AS total
         FROM users
         WHERE is_active = 1 AND department IS NOT NULL AND department <> ""
         GROUP BY department'
    );
    $userCounts = [];
    foreach ($userRows as $row) {
        $key = strtolower(trim((string) ($row['department'] ?? '')));
        if ($key !== '') {
            $userCounts[$key] = (int) ($row['total'] ?? 0);
        }
    }

    $result = array_map(static function (array $dept) use ($programCounts, $userCounts): array {
        $nameKey = strtolower(trim((string) ($dept['department_name'] ?? '')));
        $codeKey = strtolower(trim((string) ($dept['department_code'] ?? '')));
        $userCount = ($userCounts[$nameKey] ?? 0) + ($codeKey !== $nameKey ? ($userCounts[$codeKey] ?? 0) : 0);

        return [
            'id' => (int) $dept['id'],
            'code' => $dept['department_code'] ?? '',
            'name' => $dept['department_name'] ?? '',
            'dean' => $dept['dean_name'] ?? '',
            'dean_email' => $dept['dean_email'] ?? '',
            'dean_user_id' => $dept['dean_user_id'] ? (int) $dept['dean_user_id'] : null,
            'logo' => $dept['logo_image'] ?? '/assets/images/ndmc-seal.png',
            'programs' => $programCounts[(int) $dept['id']] ?? 0,
            'user_count' => $userCount,
            'faculty_count' => $userCount,
            'is_active' => (int) ($dept['is_active'] ?? 1),
            'description' => $dept['description'] ?? '',
            'department_type' => $dept['department_type'] ?? 'Academic Department',
            'contact_email' => $dept['contact_email'] ?? '',
            'office_location' => $dept['office_location'] ?? '',
        ];
    }, $departments);

    echo json_encode([
        'ok' => true,
        'data' => $result,
        'total' => count($result),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
