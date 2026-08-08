<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/subject_assignments.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required.']);
    exit;
}

function subject_api_response(int $status, bool $ok, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function subject_api_input(): array
{
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : ($_POST ?: []);
}

function subject_api_admin(array $user): void
{
    if (($user['role'] ?? '') !== 'admin_hr') {
        subject_api_response(403, false, 'Only administrators may manage subject areas.');
    }
}

function subject_api_validate_coordinator(PDO $db, int $departmentId, int $subjectId, int $facultyId): void
{
    if ($facultyId <= 0) {
        return;
    }
    $stmt = $db->prepare(
        "SELECT f.id
         FROM faculty f
         JOIN users u ON u.id=f.user_id AND u.role='teacher' AND u.is_active=1
         JOIN departments d ON d.id=? AND (u.department=d.department_name OR u.department=d.department_code)
         JOIN faculty_subject_assignments fsa ON fsa.faculty_id=f.id AND fsa.subject_area_id=?
         WHERE f.id=? AND f.is_active=1 LIMIT 1"
    );
    $stmt->execute([$departmentId, $subjectId, $facultyId]);
    if (!$stmt->fetchColumn()) {
        throw new DomainException('The Subject Coordinator must be an active Faculty Member assigned to this subject.');
    }
}

function subject_api_validate_standard_owner(PDO $db, int $departmentId, string $code): void
{
    if (!in_array(strtoupper($code), ['RE', 'MATH', 'NSTP'], true)) {
        return;
    }
    $stmt = $db->prepare('SELECT department_code FROM departments WHERE id=? LIMIT 1');
    $stmt->execute([$departmentId]);
    if (strtoupper((string)$stmt->fetchColumn()) !== 'CAS') {
        throw new DomainException('RE, Mathematics, and NSTP subject areas belong only to CAS.');
    }
}

try {
    subject_assignments_ensure_schema();
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $departmentId = (int)($_GET['department_id'] ?? 0);
        $includeInactive = ($_GET['include_inactive'] ?? '0') === '1';
        $where = $departmentId > 0 ? 'WHERE sa.department_id=?' : 'WHERE 1=1';
        $params = $departmentId > 0 ? [$departmentId] : [];
        if (!$includeInactive) {
            $where .= ' AND sa.is_active=1';
        }
        $stmt = $db->prepare(
            "SELECT sa.id,sa.department_id,sa.subject_code,sa.subject_name,sa.is_active,
                    sa.coordinator_faculty_id,f.full_name AS coordinator_name,
                    COUNT(fsa.faculty_id) AS faculty_count
             FROM subject_areas sa
             LEFT JOIN faculty f ON f.id=sa.coordinator_faculty_id
             LEFT JOIN faculty_subject_assignments fsa ON fsa.subject_area_id=sa.id
             $where
             GROUP BY sa.id
             ORDER BY sa.subject_name"
        );
        $stmt->execute($params);
        $subjects = array_map(static function (array $row): array {
            return [
                ...$row,
                'id' => (int)$row['id'],
                'department_id' => (int)$row['department_id'],
                'is_active' => (int)$row['is_active'],
                'coordinator_faculty_id' => $row['coordinator_faculty_id'] ? (int)$row['coordinator_faculty_id'] : null,
                'faculty_count' => (int)$row['faculty_count'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        subject_api_response(200, true, 'Subject areas retrieved.', ['subjects' => $subjects]);
    }

    subject_api_admin($user);
    $input = subject_api_input();
    $id = (int)($input['id'] ?? 0);
    $departmentId = (int)($input['department_id'] ?? 0);
    $code = strtoupper(trim((string)($input['subject_code'] ?? $input['code'] ?? '')));
    $name = trim((string)($input['subject_name'] ?? $input['name'] ?? ''));
    $coordinatorId = (int)($input['coordinator_faculty_id'] ?? 0);
    $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;

    if ($method === 'POST') {
        if ($departmentId <= 0 || $code === '' || $name === '') {
            subject_api_response(422, false, 'Department, subject code, and subject name are required.');
        }
        subject_api_validate_standard_owner($db, $departmentId, $code);
        $stmt = $db->prepare(
            'INSERT INTO subject_areas (department_id,subject_code,subject_name,is_active) VALUES (?,?,?,?)'
        );
        try {
            $stmt->execute([$departmentId, $code, $name, $isActive]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                subject_api_response(409, false, 'This subject code already exists in the department.');
            }
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        if ($coordinatorId > 0) {
            subject_api_validate_coordinator($db, $departmentId, $id, $coordinatorId);
            $db->prepare('UPDATE subject_areas SET coordinator_faculty_id=? WHERE id=?')->execute([$coordinatorId, $id]);
        }
        subject_api_response(201, true, 'Subject area created.', ['subject_id' => $id]);
    }

    if ($method === 'PUT') {
        if ($id <= 0) {
            subject_api_response(422, false, 'Subject area ID is required.');
        }
        $existingStmt = $db->prepare('SELECT * FROM subject_areas WHERE id=? LIMIT 1');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            subject_api_response(404, false, 'Subject area not found.');
        }
        $departmentId = $departmentId ?: (int)$existing['department_id'];
        $code = $code ?: (string)$existing['subject_code'];
        $name = $name ?: (string)$existing['subject_name'];
        subject_api_validate_standard_owner($db, $departmentId, $code);
        subject_api_validate_coordinator($db, $departmentId, $id, $coordinatorId);
        $db->prepare(
            'UPDATE subject_areas SET subject_code=?,subject_name=?,coordinator_faculty_id=?,is_active=? WHERE id=?'
        )->execute([$code, $name, $coordinatorId ?: null, $isActive, $id]);
        subject_api_response(200, true, 'Subject area updated.');
    }

    subject_api_response(405, false, 'Method not allowed.');
} catch (DomainException $e) {
    subject_api_response(422, false, $e->getMessage());
} catch (Throwable $e) {
    error_log('Subject areas API error: ' . $e->getMessage());
    subject_api_response(500, false, 'Unable to process subject areas.');
}
