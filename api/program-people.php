<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/http.php';

header('Content-Type: application/json; charset=utf-8');
allow_local_dev_cors(['GET', 'PUT', 'OPTIONS']);

function program_people_response(int $status, bool $ok, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function program_people_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function program_people_scope(int $programHeadUserId): array
{
    return admin_all(
        'SELECT p.id, p.program_code, p.program_name, d.department_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.program_head_user_id = :id
           AND p.is_active = 1
           AND d.is_active = 1',
        ['id' => $programHeadUserId]
    );
}

function program_people_scope_condition(array $programs, string $facultyAlias = 'f', string $userAlias = 'u'): array
{
    $codes = [];
    foreach ($programs as $program) {
        if (!empty($program['program_code'])) {
            $codes[] = strtoupper(trim((string) $program['program_code']));
        }
    }
    $codes = array_values(array_unique(array_filter($codes)));
    if ($codes === []) {
        return ['sql' => '0 = 1', 'params' => []];
    }

    $facultyKeys = [];
    $userKeys = [];
    $params = [];
    foreach ($codes as $index => $code) {
        $facultyKey = 'faculty_program_' . $index;
        $userKey = 'user_program_' . $index;
        $facultyKeys[] = ':' . $facultyKey;
        $userKeys[] = ':' . $userKey;
        $params[$facultyKey] = $code;
        $params[$userKey] = $code;
    }

    return [
        'sql' => '(UPPER(' . $facultyAlias . '.program_code) IN (' . implode(',', $facultyKeys) . ')
                  OR UPPER(' . $userAlias . '.program) IN (' . implode(',', $userKeys) . '))',
        'params' => $params,
    ];
}

function program_people_select_sql(string $whereSql): string
{
    return "
        SELECT DISTINCT
            u.id,
            u.full_name,
            u.email,
            u.role,
            u.phone,
            COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''), d.department_name, d.department_code, '') AS department,
            COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''), p.program_code, '') AS program,
            f.position_title,
            f.id AS faculty_id,
            u.profile_image,
            u.is_active,
            u.last_login_at,
            u.created_at
        FROM users u
        LEFT JOIN faculty f ON f.user_id = u.id OR f.email = u.email
        LEFT JOIN programs p ON p.program_code = COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''))
        LEFT JOIN departments d ON d.id = p.department_id
            OR d.department_code = COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''))
            OR d.department_name = COALESCE(NULLIF(f.department, ''), NULLIF(u.department, ''))
        WHERE u.is_active = 1
          AND u.role = 'teacher'
          AND f.id IS NOT NULL
          AND {$whereSql}
    ";
}

try {
    admin_ensure_faculty_program_schema();
    admin_ensure_profile_image_column();

    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'program_head') {
        program_people_response(403, false, 'Unauthorized. Program Head access required.');
    }

    $scope = program_people_scope((int) $user['id']);
    if ($scope === []) {
        program_people_response(403, false, 'No program is assigned to this Program Head account.');
    }

    $scopeCondition = program_people_scope_condition($scope);
    $method = $_SERVER['REQUEST_METHOD'];
    if (isset($_POST['_method'])) {
        $method = strtoupper((string) $_POST['_method']);
    }

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $params = $scopeCondition['params'];
        $whereSql = $scopeCondition['sql'];
        if ($id > 0) {
            $whereSql .= ' AND u.id = :id';
            $params['id'] = $id;
        }

        $rows = admin_all(program_people_select_sql($whereSql) . ' ORDER BY u.full_name ASC', $params);
        if ($id > 0) {
            if ($rows === []) {
                program_people_response(404, false, 'User not found within this program.');
            }
            program_people_response(200, true, 'User retrieved.', ['user' => $rows[0]]);
        }
        program_people_response(200, true, 'Users retrieved.', ['users' => $rows, 'total' => count($rows)]);
    }

    if ($method === 'PUT') {
        $input = program_people_input();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            program_people_response(400, false, 'User ID is required.');
        }

        $params = $scopeCondition['params'] + ['id' => $id];
        $existing = admin_one(program_people_select_sql($scopeCondition['sql'] . ' AND u.id = :id') . ' LIMIT 1', $params);
        if ($existing === null) {
            program_people_response(404, false, 'User not found within this program.');
        }

        $fullName = trim((string) ($input['full_name'] ?? $existing['full_name']));
        $email = trim((string) ($input['email'] ?? $existing['email']));
        $phone = trim((string) ($input['phone'] ?? $existing['phone']));
        $positionTitle = trim((string) ($input['position_title'] ?? $existing['position_title'] ?? 'Faculty'));
        if ($fullName === '' || $email === '') {
            program_people_response(400, false, 'Full name and email are required.');
        }

        $duplicate = admin_one('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1', ['email' => $email, 'id' => $id]);
        if ($duplicate !== null) {
            program_people_response(409, false, 'Another account already uses this email.');
        }

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE users SET full_name = :name, email = :email, phone = :phone WHERE id = :id')
                ->execute(['name' => $fullName, 'email' => $email, 'phone' => $phone ?: null, 'id' => $id]);
            $db->prepare('UPDATE faculty SET full_name = :name, email = :email, phone = :phone, position_title = :position WHERE id = :faculty_id')
                ->execute([
                    'name' => $fullName,
                    'email' => $email,
                    'phone' => $phone ?: null,
                    'position' => $positionTitle ?: 'Faculty',
                    'faculty_id' => (int) $existing['faculty_id'],
                ]);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }

        $updated = admin_one(program_people_select_sql($scopeCondition['sql'] . ' AND u.id = :id') . ' LIMIT 1', $params);
        program_people_response(200, true, 'User updated successfully.', ['user' => $updated]);
    }

    program_people_response(405, false, 'Method not allowed.');
} catch (Throwable $exception) {
    error_log('Program People API Error: ' . $exception->getMessage());
    program_people_response(500, false, 'An unexpected error occurred.');
}
