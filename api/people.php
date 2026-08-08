<?php
/**
 * People Management API
 * 
 * Endpoints:
 *   GET    /api/people.php          — List all active users
 *   GET    /api/people.php?id=X     — Get single user
 *   POST   /api/people.php          — Create user (password hashed)
 *   PUT    /api/people.php          — Update user
 *   DELETE /api/people.php?id=X     — Soft-delete (deactivate) user
 *
 * All endpoints require admin_hr role and return JSON.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/vpaa_data.php';
require_once __DIR__ . '/../includes/people_assignments.php';
require_once __DIR__ . '/../includes/credentials.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';
require_once __DIR__ . '/../includes/subject_assignments.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';
require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';

dipascaf_ensure_period_participation_schema();
subject_assignments_ensure_schema();
dipascaf_ensure_peer_evaluation_schema();
if (admin_one("SHOW COLUMNS FROM users LIKE 'faculty_designation'") === null) {
    db()->exec("ALTER TABLE users ADD COLUMN faculty_designation VARCHAR(120) NULL AFTER role");
}

// ── CORS ───────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:5175',
    'http://localhost:3000',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Auth guard ─────────────────────────────────────────────────────────────
$currentUser = current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}
if (!people_assignments_admin_authorized($currentUser)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden. Admin access required.']);
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────
function jsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function jsonResponse(int $status, bool $ok, string $message, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function getUserById(PDO $db, int $id): ?array {
    $stmt = $db->prepare(
        "SELECT u.id, u.user_code, u.full_name, u.email, u.email_verified_at, u.must_change_password, u.role, u.faculty_designation, u.phone, u.department,u.start_evaluation_period_id,
                f.id AS faculty_id,
                (SELECT pr.id FROM password_reset_requests pr WHERE pr.user_id=u.id AND pr.status='pending' ORDER BY pr.requested_at DESC LIMIT 1) AS pending_password_reset_request_id,
                COALESCE(NULLIF(u.program, ''), f.program_code, '') AS program,
                u.profile_image, u.is_active, u.last_login_at, u.created_at
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id
         WHERE u.id = ?"
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($user) {
        $user['assigned_programs'] = programHeadProgramsForUser($db,$id);
        $user['subject_assignments'] = subject_assignments_for_faculty($db, (int)($user['faculty_id'] ?? 0));
        $user['primary_subject_id'] = (int)(array_values(array_filter(
            $user['subject_assignments'],
            static fn(array $subject): bool => (int)$subject['is_primary'] === 1
        ))[0]['id'] ?? 0);
        $user['coordinator_subject_ids'] = array_values(array_map(
            static fn(array $subject): int => (int)$subject['id'],
            array_filter($user['subject_assignments'], static fn(array $subject): bool => (int)$subject['is_coordinator'] === 1)
        ));
        $user['assignment_needs_review'] = false;
    }
    return $user;
}

function programHeadProgramsForUser(PDO $db, int $userId): array {
    $stmt = $db->prepare(
        'SELECT p.id,p.program_code,p.program_name,p.department_id
         FROM programs p WHERE p.program_head_user_id=? AND p.is_active=1 ORDER BY p.program_name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function listUsers(PDO $db, bool $activeOnly = true, ?string $role = null, int $periodId = 0): array {
    $periodScoped = $periodId > 0;
    $sql = "SELECT u.id, u.user_code, u.full_name, u.email, u.email_verified_at, u.must_change_password,
                   " . ($periodScoped ? "COALESCE(epp.role_snapshot, u.role)" : "u.role") . " AS role,
                   u.faculty_designation, u.phone, f.id AS faculty_id,
                   " . ($periodScoped ? "COALESCE(NULLIF(epp.department_snapshot, ''), u.department)" : "u.department") . " AS department,
                   u.start_evaluation_period_id,
                (SELECT pr.id FROM password_reset_requests pr WHERE pr.user_id=u.id AND pr.status='pending' ORDER BY pr.requested_at DESC LIMIT 1) AS pending_password_reset_request_id,
                   " . ($periodScoped
                       ? "COALESCE(NULLIF(epp.program_snapshot, ''), NULLIF(u.program, ''), f.program_code, '')"
                       : "COALESCE(NULLIF(u.program, ''), f.program_code, '')") . " AS program,
                   u.profile_image, u.is_active, u.last_login_at, u.created_at
            FROM users u
            LEFT JOIN faculty f ON f.user_id = u.id";
    $params = [];
    $where = [];
    if ($periodScoped) {
        $sql .= " INNER JOIN evaluation_period_participation epp
                    ON epp.user_id = u.id
                   AND epp.evaluation_period_id = ?
                   AND epp.participation_status = 'included'";
        $params[] = $periodId;
    }
    if ($activeOnly) {
        $where[] = "u.is_active = 1";
    }
    if ($role !== null && $role !== '') {
        $where[] = "u.role = ?";
        $params[] = $role;
    }
    if ($where !== []) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY u.full_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$user) {
        $user['assigned_programs'] = $periodScoped
            ? (($user['program'] ?? '') !== '' ? [['program_code' => $user['program']]] : [])
            : programHeadProgramsForUser($db, (int)$user['id']);
        $user['subject_assignments'] = subject_assignments_for_faculty(
            $db,
            (int)($user['faculty_id'] ?? 0),
            $periodScoped ? $periodId : 0
        );
        $user['primary_subject_id'] = (int)(array_values(array_filter(
            $user['subject_assignments'],
            static fn(array $subject): bool => (int)$subject['is_primary'] === 1
        ))[0]['id'] ?? 0);
        $user['coordinator_subject_ids'] = array_values(array_map(
            static fn(array $subject): int => (int)$subject['id'],
            array_filter($user['subject_assignments'], static fn(array $subject): bool => (int)$subject['is_coordinator'] === 1)
        ));
        $user['assignment_needs_review'] = false;
    }
    unset($user);
    return $users;
}

function findDepartment(PDO $db, string $department): ?array {
    if ($department === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT id, department_code, department_name, dean_user_id
         FROM departments
         WHERE is_active = 1 AND (department_name = ? OR department_code = ?)
         LIMIT 1"
    );
    $stmt->execute([$department, $department]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function findProgram(PDO $db, int $departmentId, string $program): ?array {
    if ($departmentId <= 0 || $program === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT id, program_code, program_head_user_id
         FROM programs
         WHERE is_active = 1 AND department_id = ? AND program_code = ?
         LIMIT 1"
    );
    $stmt->execute([$departmentId, $program]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function syncLinkedPeopleData(PDO $db, int $id, string $fullName, string $email, string $phone, string $department, string $program, string $role, string $oldEmail = ''): void {
    if ($role === 'vpaa') {
        vpaa_sync_departments_for_user($id, $department);
    } else {
        $db->prepare('DELETE FROM vpaa_departments WHERE vpaa_user_id = ?')->execute([$id]);
    }

    if (!in_array($role, ['admin_hr', 'vpaa'], true)) {
        $positionTitle = match ($role) {
            'dean' => 'Dean',
            'program_head' => 'Program Head',
            default => 'Faculty',
        };

        $faculty = null;
        $stmt = $db->prepare("SELECT id FROM faculty WHERE user_id = ? LIMIT 1");
        $stmt->execute([$id]);
        $faculty = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$faculty) {
            $emails = array_values(array_unique(array_filter([$email, $oldEmail], static fn (string $value): bool => $value !== '')));
            $placeholders = implode(',', array_fill(0, count($emails), '?'));
            $stmt = $db->prepare("SELECT id FROM faculty WHERE email IN ($placeholders) LIMIT 1");
            $stmt->execute($emails);
            $faculty = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($faculty) {
            $stmt = $db->prepare(
                "UPDATE faculty
                 SET full_name = ?, email = ?, phone = ?, department = ?, program_code = ?, position_title = ?, user_id = ?
                 WHERE id = ?"
            );
            $stmt->execute([$fullName, $email, $phone ?: null, $department ?: '', $program ?: null, $positionTitle, $id, (int) $faculty['id']]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO faculty (full_name, email, phone, department, program_code, position_title, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$fullName, $email, $phone ?: null, $department ?: '', $program ?: null, $positionTitle, $id]);
        }
    }

}

try {
    $db = db();
    admin_ensure_profile_image_column();
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();
    admin_ensure_evaluation_role_schema();
    vpaa_ensure_schema();

    $method = $_SERVER['REQUEST_METHOD'];
    // Allow _method override for FormData file uploads (PUT via POST)
    if (isset($_POST['_method'])) {
        $method = strtoupper($_POST['_method']);
    }

    switch ($method) {
        // ── GET: List or single ───────────────────────────────────────────
        case 'GET':
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id > 0) {
                $user = getUserById($db, $id);
                if (!$user) {
                    jsonResponse(404, false, 'User not found.');
                }
                jsonResponse(200, true, 'User retrieved.', ['user' => $user]);
            } else {
                $activeOnly = !isset($_GET['active_only']) || $_GET['active_only'] !== '0';
                $role = isset($_GET['role']) ? trim((string) $_GET['role']) : null;
                if ($role !== null && $role !== '' && !in_array($role, ['admin_hr', 'vpaa', 'dean', 'program_head', 'teacher'], true)) {
                    jsonResponse(400, false, 'Invalid role filter.');
                }
                $periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : 0;
                if ($periodId > 0) {
                    $periodExists = $db->prepare('SELECT id FROM appraisal_periods WHERE id = ? LIMIT 1');
                    $periodExists->execute([$periodId]);
                    if (!$periodExists->fetchColumn()) {
                        jsonResponse(400, false, 'Invalid evaluation period filter.');
                    }
                }
                $users = listUsers($db, $activeOnly, $role, $periodId);
                $next = (int) $db->query("SELECT setting_value FROM system_settings WHERE setting_key='next_user_code'")->fetchColumn();
                jsonResponse(200, true, 'Users retrieved.', [
                    'users' => $users,
                    'total' => count($users),
                    'period_id' => $periodId ?: null,
                    'next_user_code' => $next ?: USER_CODE_START,
                ]);
            }
            break;

        // ── POST: Create user ─────────────────────────────────────────────
        case 'POST':
            $input = jsonInput();

            $fullName  = trim($input['full_name'] ?? '');
            $requestedCode = trim((string) ($input['user_code'] ?? ''));
            $requestedEmail = strtolower(trim((string) ($input['email'] ?? '')));
            $role      = trim($input['role'] ?? '');
            $facultyDesignation = trim((string)($input['faculty_designation'] ?? ''));
            if (mb_strlen($facultyDesignation) > 120) {
                jsonResponse(422, false, 'Faculty position/designation must not exceed 120 characters.');
            }
            $phone     = trim($input['phone'] ?? '');
            $department = admin_normalize_department_name(trim($input['department'] ?? ''));
            $program   = strtoupper(trim($input['program'] ?? ''));
            $programsInput = $input['program_ids'] ?? [];
            if (is_string($programsInput)) $programsInput = json_decode($programsInput, true) ?: [];
            $programCodes = is_array($programsInput) ? $programsInput : [];
            if ($role === 'program_head' && $programCodes === [] && $program !== '') $programCodes = [$program];
            $subjectIdsInput = $input['subject_ids'] ?? [];
            if (is_string($subjectIdsInput)) $subjectIdsInput = json_decode($subjectIdsInput, true) ?: [];
            $subjectIds = is_array($subjectIdsInput) ? array_map('intval', $subjectIdsInput) : [];
            $primarySubjectId = (int)($input['primary_subject_id'] ?? 0);
            $coordinatorInput = $input['coordinator_subject_ids'] ?? [];
            if (is_string($coordinatorInput)) $coordinatorInput = json_decode($coordinatorInput, true) ?: [];
            $coordinatorSubjectIds = is_array($coordinatorInput) ? array_map('intval', $coordinatorInput) : [];
            $isActive  = isset($input['is_active']) ? (int) ((bool) $input['is_active']) : 1;
            $startPeriodId = (int)($input['start_evaluation_period_id'] ?? 0);

            // Validate required fields
            $errors = [];
            if ($fullName === '') $errors[] = 'Full name is required.';
            if (!in_array($role, ['admin_hr', 'vpaa', 'dean', 'program_head', 'teacher'], true)) {
                $errors[] = 'Valid role is required (admin_hr, vpaa, dean, program_head, teacher).';
            }
            if ($role !== 'teacher') $facultyDesignation = '';
            if (!empty($errors)) {
                jsonResponse(400, false, implode(' ', $errors));
            }
            if (in_array($role, ['teacher','program_head','dean'], true)) {
                if ($startPeriodId <= 0 || !admin_one('SELECT id FROM appraisal_periods WHERE id=:id', ['id'=>$startPeriodId])) {
                    jsonResponse(422, false, 'Start Evaluation Period is required for evaluation-role accounts.');
                }
            } else {
                $startPeriodId = 0;
            }
            if ($requestedEmail !== '' && (!filter_var($requestedEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($requestedEmail, '@gmail.com'))) {
                jsonResponse(422, false, 'Enter a valid Gmail address ending in @gmail.com, or leave it blank.');
            }
            if ($requestedEmail !== '') {
                $emailCheck = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
                $emailCheck->execute([$requestedEmail]);
                if ($emailCheck->fetch()) {
                    jsonResponse(409, false, 'This Gmail address is already assigned to another account.');
                }
            }

            // Check duplicate name
            $check = $db->prepare("SELECT id FROM users WHERE full_name = ?");
            $check->execute([$fullName]);
            if ($check->fetch()) {
                jsonResponse(409, false, 'This name already has an account.');
            }

            $passwordHash = password_hash(STANDARD_TEMPORARY_PASSWORD, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                jsonResponse(500, false, 'Failed to hash password.');
            }

            // Enforce single admin rule
            if ($role === 'admin_hr') {
                $adminCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1');
                $adminCheck->execute(['admin_hr']);
                if ((int) $adminCheck->fetchColumn() > 0) {
                    jsonResponse(409, false, 'Only one admin account is allowed.');
                }
            }

            if ($role === 'vpaa') {
                $vpaaCheck = $db->prepare("SELECT id FROM users WHERE role = 'vpaa' AND is_active = 1 LIMIT 1");
                $vpaaCheck->execute();
                if ($vpaaCheck->fetch()) {
                    jsonResponse(409, false, 'Only one VPAA account is allowed in the system.');
                }
            }

            if (!in_array($role, ['admin_hr', 'vpaa'], true) && $department === '') {
                jsonResponse(400, false, 'Department is required for faculty, Dean, Program Head, and Teacher accounts.');
            }

            $departmentRecord = findDepartment($db, $department);
            if (!in_array($role, ['admin_hr', 'vpaa'], true) && !$departmentRecord) {
                jsonResponse(400, false, 'Select a valid department before saving this account.');
            }
            if ($departmentRecord) {
                $department = (string) $departmentRecord['department_name'];
            }

            if (!in_array($role, ['dean', 'program_head', 'teacher'], true)) {
                $program = '';
            }

            if ($role === 'program_head' && $program === '') {
                jsonResponse(400, false, 'Select a program/course for this account.');
            }

            if ($program !== '' && $departmentRecord && !findProgram($db, (int) $departmentRecord['id'], $program)) {
                jsonResponse(400, false, 'The selected program/course does not belong to this department.');
            }
            if ($role === 'teacher') {
                if ($subjectIds !== []) {
                    try {
                        $subjectIds = subject_assignments_validate($db, (int)$departmentRecord['id'], $subjectIds, $primarySubjectId);
                    } catch (DomainException $e) {
                        jsonResponse(422, false, $e->getMessage());
                    }
                } else {
                    $primarySubjectId = 0;
                    $coordinatorSubjectIds = [];
                }
            } else {
                $subjectIds = [];
                $primarySubjectId = 0;
                $coordinatorSubjectIds = [];
            }

            // Enforce one Program Head per program
            if ($role === 'program_head' && $program !== '') {
                $phCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND program = ? AND is_active = 1');
                $phCheck->execute(['program_head', $program]);
                if ((int) $phCheck->fetchColumn() > 0) {
                    jsonResponse(409, false, 'There is already a Program Head assigned to this program/course.');
                }
            }

            try {
                $assignment = people_validate_assignment($db, $role, $department, $program);
                $programIds = $role === 'program_head'
                    ? people_validate_program_head_programs($db,(int)$assignment['department_id'],$programCodes)
                    : [];
                $department = (string) $assignment['department'];
                $program = (string) $assignment['program'];
            } catch (DomainException $e) {
                jsonResponse(str_contains($e->getMessage(), 'already') ? 409 : 422, false, $e->getMessage());
            }

            $db->beginTransaction();
            try {
                $userCode = allocate_user_code($db, $requestedCode !== '' ? $requestedCode : null);
                // The legacy schema still requires a unique email value. Keep it internal;
                // account access and management use the username code instead.
                $email = $requestedEmail !== '' ? $requestedEmail : strtolower($userCode) . '@pmas.local';
                $assignment = people_validate_assignment($db, $role, $department, $program);
                $programIds = $role === 'program_head'
                    ? people_validate_program_head_programs($db,(int)$assignment['department_id'],$programCodes)
                    : [];
                $stmt = $db->prepare("
                    INSERT INTO users (user_code, full_name, email, password_hash, must_change_password, role, faculty_designation, phone, department, program, start_evaluation_period_id, is_active, created_at)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$userCode, $fullName, strtolower($email), $passwordHash, $role, $facultyDesignation ?: null, $phone ?: null, $department ?: null, $program ?: null, $startPeriodId ?: null, $isActive]);
                $newId = (int) $db->lastInsertId();
                syncLinkedPeopleData($db, $newId, $fullName, $email, $phone, $department, $program, $role);
                $facultyStmt = $db->prepare('SELECT id FROM faculty WHERE user_id=? LIMIT 1');
                $facultyStmt->execute([$newId]);
                $facultyId = (int)$facultyStmt->fetchColumn();
                if ($facultyId > 0) {
                    subject_assignments_sync_faculty($db, $facultyId, $subjectIds, $primarySubjectId);
                    subject_assignments_sync_coordinator_designations($db, $facultyId, $subjectIds, $coordinatorSubjectIds);
                }
                people_sync_leadership_assignments($db, $newId, $role, $assignment['department_id'], $assignment['program_id'], $programIds);
                $db->prepare('INSERT INTO activity_logs (user_id, description) VALUES (?, ?)')->execute([
                    (int) $currentUser['id'],
                    "Created {$role} account with username code {$userCode}; department " . ($department ?: 'none') . ' and program ' . ($program ?: 'none') . '.',
                ]);
                $syncSummary = dipascaf_sync_account_evaluation_periods($newId, (int)$currentUser['id']);
                if ($db->inTransaction()) {
                    $db->commit();
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            // Handle profile image upload
            try {
                $profileImage = admin_profile_image_upload($newId);
                if ($profileImage !== null) {
                    $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->execute([$profileImage, $newId]);
                }
            } catch (RuntimeException $e) {
                error_log('Profile image upload failed for user ' . $newId . ': ' . $e->getMessage());
                jsonResponse(422, false, $e->getMessage());
            }

            // Participant and evaluation assignments are prepared only through the
            // selected period workflow after the administrator finalizes the roster.
            if (false && $role === 'teacher') {
                try {
                    $positionTitle = trim($input['position_title'] ?? 'Faculty');
                    $facultyStmt = $db->prepare("SELECT id FROM faculty WHERE user_id = ? OR email = ? LIMIT 1");
                    $facultyStmt->execute([$newId, $email]);
                    $facultyRow = $facultyStmt->fetch();

                    if ($facultyRow) {
                        $facultyId = (int) $facultyRow['id'];
                        $facultyStmt = $db->prepare("
                            UPDATE faculty
                            SET full_name = ?, email = ?, phone = ?, department = ?, program_code = ?, position_title = ?, user_id = ?
                            WHERE id = ?
                        ");
                        $facultyStmt->execute([
                            $fullName, $email, $phone ?: null,
                            $department ?: '', $program ?: null, $positionTitle, $newId, $facultyId
                        ]);
                    } else {
                        $facultyStmt = $db->prepare("
                            INSERT INTO faculty (full_name, email, phone, department, program_code, position_title, user_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $facultyStmt->execute([
                            $fullName, $email, $phone ?: null,
                            $department ?: '', $program ?: null, $positionTitle, $newId
                        ]);
                        $facultyId = (int) $db->lastInsertId();
                    }

                    // Period requirements are synchronized below after the
                    // official participation snapshots have been created.
                } catch (Throwable $e) {
                    error_log('Auto-assignment failed for user ' . $newId . ': ' . $e->getMessage());
                }
            }

            $user = getUserById($db, $newId);
            jsonResponse(201, true, 'Account created and synchronized successfully.', ['user' => $user, 'synchronization' => $syncSummary]);
            break;

        // ── PUT: Update or Restore user ──────────────────────────────────
        case 'PUT':
            $input = jsonInput();
            $action = trim($input['action'] ?? '');
            if ($action === 'set_next_user_code') {
                $value = trim((string) ($input['next_user_code'] ?? ''));
                if (!valid_user_code($value)) jsonResponse(422, false, 'Next username code must contain positive numeric digits only.');
                $check = $db->prepare('SELECT 1 FROM users WHERE user_code=? LIMIT 1'); $check->execute([$value]);
                if ($check->fetchColumn()) jsonResponse(409, false, 'This username code is already assigned to another account. Please enter a different code.');
                $available = next_available_user_code($db, (int) $value);
                $db->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES ('next_user_code',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([(string) $available]);
                $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([$currentUser['id'], "Set next automatic username code to {$available}."]);
                jsonResponse(200, true, 'Next username code updated.', ['next_user_code' => $available]);
            }
            if ($action === 'complete_password_reset') {
                $requestId = (int) ($input['request_id'] ?? 0);
                if ($requestId <= 0) jsonResponse(422, false, 'Password reset request ID is required.');
                $db->beginTransaction();
                try {
                    $stmt=$db->prepare("SELECT pr.id,pr.user_id,u.user_code,u.full_name,u.is_active,u.role FROM password_reset_requests pr JOIN users u ON u.id=pr.user_id WHERE pr.id=? AND pr.status='pending' FOR UPDATE");
                    $stmt->execute([$requestId]); $request=$stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$request) throw new DomainException('This password reset request has already been completed or no longer exists.');
                    if (!(int)$request['is_active'] || $request['role']==='admin_hr') throw new DomainException('This account is not eligible for administrator-assisted recovery.');
                    $hash=password_hash(STANDARD_TEMPORARY_PASSWORD, PASSWORD_DEFAULT);
                    if ($hash===false) throw new RuntimeException('Unable to secure the temporary password.');
                    $db->prepare('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?')->execute([$hash,$request['user_id']]);
                    $db->prepare('UPDATE auth_tokens SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL')->execute([$request['user_id']]);
                    $db->prepare("UPDATE password_reset_requests SET status='completed',completed_at=NOW(),completed_by_user_id=? WHERE id=? AND status='pending'")->execute([$currentUser['id'],$requestId]);
                    $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([$currentUser['id'], 'Completed password reset request #'.$requestId.' for username code '.$request['user_code'].'.']);
                    $db->commit();
                    notify_send(['recipient_id'=>(int)$request['user_id'],'type'=>'success','title'=>'Your password was reset','message'=>'An administrator reset your password to the standard temporary password. Sign in and create a new password when prompted.','module'=>'password_reset','related_record_id'=>$requestId,'dedupe'=>false]);
                    jsonResponse(200,true,'Password reset successfully. The user must change APPRAISIA_NDMC after signing in.',['user'=>getUserById($db,(int)$request['user_id'])]);
                } catch (DomainException $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonResponse(409,false,$e->getMessage());
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
            }
            if ($action === 'reset_account_password') {
                $userId = (int) ($input['user_id'] ?? 0);
                if ($userId <= 0) jsonResponse(422, false, 'User ID is required.');
                $stmt = $db->prepare('SELECT id,user_code,full_name,is_active,role FROM users WHERE id=? LIMIT 1');
                $stmt->execute([$userId]);
                $resetUser = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$resetUser) jsonResponse(404, false, 'User account was not found.');
                if (!(int) $resetUser['is_active']) jsonResponse(409, false, 'Activate this account before resetting its password.');
                if ((string) $resetUser['role'] === 'admin_hr') jsonResponse(403, false, 'Administrator passwords cannot be reset from People Management.');

                $hash = password_hash(STANDARD_TEMPORARY_PASSWORD, PASSWORD_DEFAULT);
                if ($hash === false) jsonResponse(500, false, 'Unable to secure the temporary password.');
                $db->beginTransaction();
                try {
                    $db->prepare('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?')->execute([$hash, $userId]);
                    $db->prepare('UPDATE auth_tokens SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL')->execute([$userId]);
                    $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([
                        $currentUser['id'],
                        'Reset the password for username code ' . $resetUser['user_code'] . ' to the standard temporary password.',
                    ]);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                notify_send([
                    'recipient_id' => $userId,
                    'type' => 'success',
                    'title' => 'Your password was reset',
                    'message' => 'Use APPRAISIA_NDMC to sign in. You must create a new password before accessing your dashboard.',
                    'module' => 'password_reset',
                    'related_record_id' => $userId,
                    'dedupe' => false,
                ]);
                jsonResponse(200, true, 'Password reset to APPRAISIA_NDMC. The user must change it after signing in.', [
                    'user' => getUserById($db, $userId),
                ]);
            }
            // Handle restore (reactivate) action —
            // frontend sends PUT with action=restore because some HTTP clients
            // cannot send PATCH method.
            if ($action === 'restore') {
                $id = isset($input['id']) ? (int) $input['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
                if ($id <= 0) {
                    jsonResponse(400, false, 'User ID is required.');
                }
                $existing = getUserById($db, $id);
                if (!$existing) {
                    jsonResponse(404, false, 'User not found.');
                }
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("UPDATE users u
                        LEFT JOIN faculty f ON f.user_id=u.id
                        SET u.is_active=1,
                            u.department=COALESCE(NULLIF(u.department,''),NULLIF(f.department,'')),
                            u.program=COALESCE(NULLIF(u.program,''),NULLIF(f.program_code,''))
                        WHERE u.id=?");
                    $stmt->execute([$id]);
                    $stmt = $db->prepare("UPDATE faculty SET is_archived = 0 WHERE user_id = ?");
                    $stmt->execute([$id]);
                    $syncSummary = dipascaf_sync_account_evaluation_periods($id, (int)$currentUser['id']);
                    if ($db->inTransaction()) {
                        $db->commit();
                    }
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
                $user = getUserById($db, $id);
                jsonResponse(200, true, 'User restored and synchronized successfully.', ['user' => $user, 'synchronization' => $syncSummary]);
            }

            $id = isset($input['id']) ? (int) $input['id'] : 0;

            if ($id <= 0) {
                jsonResponse(400, false, 'User ID is required.');
            }

            $existing = getUserById($db, $id);
            if (!$existing) {
                jsonResponse(404, false, 'User not found.');
            }

            $fullName  = trim($input['full_name'] ?? $existing['full_name']);
            $userCodeInput = trim((string) ($input['user_code'] ?? $existing['user_code']));
            $requestedEmail = strtolower(trim((string) ($input['email'] ?? '')));
            if ($requestedEmail !== '' && (!filter_var($requestedEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($requestedEmail, '@gmail.com'))) {
                jsonResponse(422, false, 'Enter a valid Gmail address ending in @gmail.com, or leave it blank.');
            }
            $email = $requestedEmail !== '' ? $requestedEmail : strtolower($userCodeInput) . '@pmas.local';
            $emailCheck = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
            $emailCheck->execute([$email, $id]);
            if ($emailCheck->fetch()) {
                jsonResponse(409, false, 'This Gmail address is already assigned to another account.');
            }
            $role      = trim($input['role'] ?? $existing['role']);
            $facultyDesignation = trim((string)($input['faculty_designation'] ?? $existing['faculty_designation'] ?? ''));
            if (mb_strlen($facultyDesignation) > 120) {
                jsonResponse(422, false, 'Faculty position/designation must not exceed 120 characters.');
            }
            if ($role !== 'teacher') $facultyDesignation = '';
            $phone     = trim($input['phone'] ?? $existing['phone']);
            $department = admin_normalize_department_name(trim((string) (array_key_exists('department', $input) ? ($input['department'] ?? '') : ($existing['department'] ?? ''))));
            $program   = strtoupper(trim((string) (array_key_exists('program', $input) ? ($input['program'] ?? '') : ($existing['program'] ?? ''))));
            $programsInput = $input['program_ids'] ?? array_column($existing['assigned_programs'] ?? [], 'program_code');
            if (is_string($programsInput)) $programsInput = json_decode($programsInput, true) ?: [];
            $programCodes = is_array($programsInput) ? $programsInput : [];
            if ($role === 'program_head' && $programCodes === [] && $program !== '') $programCodes = [$program];
            $subjectIdsInput = $input['subject_ids'] ?? array_column($existing['subject_assignments'] ?? [], 'id');
            if (is_string($subjectIdsInput)) $subjectIdsInput = json_decode($subjectIdsInput, true) ?: [];
            $subjectIds = is_array($subjectIdsInput) ? array_map('intval', $subjectIdsInput) : [];
            $primarySubjectId = (int)($input['primary_subject_id'] ?? $existing['primary_subject_id'] ?? 0);
            $coordinatorInput = $input['coordinator_subject_ids'] ?? $existing['coordinator_subject_ids'] ?? [];
            if (is_string($coordinatorInput)) $coordinatorInput = json_decode($coordinatorInput, true) ?: [];
            $coordinatorSubjectIds = is_array($coordinatorInput) ? array_map('intval', $coordinatorInput) : [];
            $password  = $input['password'] ?? '';
            $isActive  = isset($input['is_active']) ? (int) ((bool) $input['is_active']) : (int) $existing['is_active'];
            $startPeriodId = (int)($input['start_evaluation_period_id'] ?? $existing['start_evaluation_period_id'] ?? 0);

            // Validate role
            if (!in_array($role, ['admin_hr', 'vpaa', 'dean', 'program_head', 'teacher'], true)) {
                jsonResponse(400, false, 'Valid role is required.');
            }
            if (in_array($role, ['teacher','program_head','dean'], true)) {
                if ($startPeriodId <= 0 || !admin_one('SELECT id FROM appraisal_periods WHERE id=:id', ['id'=>$startPeriodId])) {
                    jsonResponse(422, false, 'Start Evaluation Period is required for evaluation-role accounts.');
                }
            } else {
                $startPeriodId = 0;
            }

            if (!valid_user_code($userCodeInput)) jsonResponse(422, false, 'Username code must contain positive numeric digits only.');
            $check=$db->prepare('SELECT id FROM users WHERE user_code=? AND id!=?'); $check->execute([$userCodeInput,$id]);
            if ($check->fetch()) jsonResponse(409, false, 'This username code is already assigned to another account. Please enter a different code.');

            // Enforce one Program Head per program (excluding current user)
            if ($role === 'program_head' && $program !== '') {
                $phCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND program = ? AND is_active = 1 AND id != ?');
                $phCheck->execute(['program_head', $program, $id]);
                if ((int) $phCheck->fetchColumn() > 0) {
                    jsonResponse(409, false, 'There is already a Program Head assigned to this program/course.');
                }
            }

            if ($role === 'vpaa') {
                $vpaaCheck = $db->prepare("SELECT id FROM users WHERE role = 'vpaa' AND is_active = 1 AND id != ? LIMIT 1");
                $vpaaCheck->execute([$id]);
                if ($vpaaCheck->fetch()) {
                    jsonResponse(409, false, 'Only one VPAA account is allowed in the system.');
                }
            }

            if (!in_array($role, ['admin_hr', 'vpaa'], true) && $department === '') {
                jsonResponse(400, false, 'Department is required for faculty, Dean, Program Head, and Teacher accounts.');
            }

            $departmentRecord = findDepartment($db, $department);
            if (!in_array($role, ['admin_hr', 'vpaa'], true) && !$departmentRecord) {
                jsonResponse(400, false, 'Select a valid department before saving this account.');
            }
            if ($departmentRecord) {
                $department = (string) $departmentRecord['department_name'];
            }

            if (!in_array($role, ['dean', 'program_head', 'teacher'], true)) {
                $program = '';
            }

            if ($role === 'program_head' && $program === '') {
                jsonResponse(400, false, 'Select a program/course for this account.');
            }

            if ($program !== '' && $departmentRecord && !findProgram($db, (int) $departmentRecord['id'], $program)) {
                jsonResponse(400, false, 'The selected program/course does not belong to this department.');
            }
            if ($role === 'teacher') {
                if ($subjectIds !== []) {
                    try {
                        $subjectIds = subject_assignments_validate($db, (int)$departmentRecord['id'], $subjectIds, $primarySubjectId);
                    } catch (DomainException $e) {
                        jsonResponse(422, false, $e->getMessage());
                    }
                } else {
                    $primarySubjectId = 0;
                    $coordinatorSubjectIds = [];
                }
            } else {
                $subjectIds = [];
                $primarySubjectId = 0;
                $coordinatorSubjectIds = [];
            }

            try {
                $assignment = people_validate_assignment($db, $role, $department, $program, $id);
                $programIds = $role === 'program_head'
                    ? people_validate_program_head_programs($db,(int)$assignment['department_id'],$programCodes,$id)
                    : [];
                $department = (string) $assignment['department'];
                $program = (string) $assignment['program'];
            } catch (DomainException $e) {
                jsonResponse(str_contains($e->getMessage(), 'already') ? 409 : 422, false, $e->getMessage());
            }

            $db->beginTransaction();
            try {
                $oldCode=(string)$existing['user_code'];
                $assignment = people_validate_assignment($db, $role, $department, $program, $id);
                $programIds = $role === 'program_head'
                    ? people_validate_program_head_programs($db,(int)$assignment['department_id'],$programCodes,$id)
                    : [];
                if (!empty($password)) {
                    // Updating password
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($passwordHash === false) {
                        jsonResponse(500, false, 'Failed to hash password.');
                    }
                    $stmt = $db->prepare("UPDATE users SET user_code=?, full_name=?, email=?, password_hash=?, must_change_password=1, role=?, faculty_designation=?, phone=?, department=?, program=?, start_evaluation_period_id=?, is_active=? WHERE id=?");
                    $stmt->execute([$userCodeInput,$fullName,$email,$passwordHash,$role,$facultyDesignation?:null,$phone?:null,$department?:null,$program?:null,$startPeriodId?:null,$isActive,$id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET user_code=?, full_name=?, email=?, role=?, faculty_designation=?, phone=?, department=?, program=?, start_evaluation_period_id=?, is_active=? WHERE id=?");
                    $stmt->execute([$userCodeInput,$fullName,$email,$role,$facultyDesignation?:null,$phone?:null,$department?:null,$program?:null,$startPeriodId?:null,$isActive,$id]);
                }
                if ($oldCode !== $userCodeInput) $db->prepare('INSERT INTO user_code_audit (user_id,old_user_code,new_user_code,changed_by_user_id) VALUES (?,?,?,?)')->execute([$id,$oldCode,$userCodeInput,$currentUser['id']]);

                syncLinkedPeopleData($db, $id, $fullName, $email, $phone, $department, $program, $role, (string) ($existing['email'] ?? ''));
                $facultyStmt = $db->prepare('SELECT id FROM faculty WHERE user_id=? LIMIT 1');
                $facultyStmt->execute([$id]);
                $facultyId = (int)$facultyStmt->fetchColumn();
                if ($facultyId > 0) {
                    subject_assignments_sync_faculty($db, $facultyId, $subjectIds, $primarySubjectId);
                    subject_assignments_sync_coordinator_designations($db, $facultyId, $subjectIds, $coordinatorSubjectIds);
                }
                people_sync_leadership_assignments($db, $id, $role, $assignment['department_id'], $assignment['program_id'], $programIds);
                $db->prepare('INSERT INTO activity_logs (user_id, description) VALUES (?, ?)')->execute([
                    (int) $currentUser['id'],
                    "Updated account #{$id} to {$role}; department " . ($department ?: 'none') . '; program ' . ($program ?: 'none') . '. Previous role: ' . (string) ($existing['role'] ?? 'unknown') . '.',
                ]);
                $syncSummary = dipascaf_sync_account_evaluation_periods($id, (int)$currentUser['id']);
                if ($db->inTransaction()) {
                    $db->commit();
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            // Handle profile image upload
            try {
                $profileImage = admin_profile_image_upload($id);
                if ($profileImage !== null) {
                    $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->execute([$profileImage, $id]);
                }
            } catch (RuntimeException $e) {
                error_log('Profile image upload failed for user ' . $id . ': ' . $e->getMessage());
                jsonResponse(422, false, $e->getMessage());
            }

            $user = getUserById($db, $id);
            jsonResponse(200, true, 'User updated and synchronized successfully.', ['user' => $user, 'synchronization' => $syncSummary]);
            break;



        // ── DELETE: Soft-delete (deactivate) ──────────────────────────────
        case 'DELETE':
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

            if ($id <= 0) {
                jsonResponse(400, false, 'User ID is required.');
            }

            $existing = getUserById($db, $id);
            if (!$existing) {
                jsonResponse(404, false, 'User not found.');
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("UPDATE users SET is_active = 0, department = NULL WHERE id = ?");
                $stmt->execute([$id]);

                $stmt = $db->prepare("DELETE FROM vpaa_departments WHERE vpaa_user_id = ?");
                $stmt->execute([$id]);

                $stmt = $db->prepare("UPDATE departments SET dean_user_id = NULL WHERE dean_user_id = ?");
                $stmt->execute([$id]);

                $stmt = $db->prepare("UPDATE programs SET program_head_user_id = NULL WHERE program_head_user_id = ?");
                $stmt->execute([$id]);

                admin_ensure_archive_schema();
                $stmt = $db->prepare("UPDATE peer_assignments SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE evaluator_user_id = ? AND status = 'pending'");
                $stmt->execute([(int) $currentUser['id'], $id]);

                // Also archive the corresponding faculty record
                $stmt = $db->prepare("UPDATE faculty SET is_archived = 1 WHERE user_id = ?");
                $stmt->execute([$id]);

                if ($db->inTransaction()) {
                    $db->commit();
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            jsonResponse(200, true, 'User deactivated successfully.');
            break;

        default:
            jsonResponse(405, false, 'Method not allowed.');
    }
} catch (PDOException $e) {
    error_log("People API DB Error: " . $e->getMessage());
    jsonResponse(500, false, 'Database error occurred.');
} catch (Exception $e) {
    error_log("People API Error: " . $e->getMessage());
    jsonResponse(500, false, 'An unexpected error occurred.');
}
