<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/react_redirect.php';
redirect_to_react('/admin/dashboard');

require_once __DIR__ . '/../includes/admin_data.php';

require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';
require_once __DIR__ . '/../includes/notifications.php';

require_role('admin_hr');
admin_ensure_evaluation_role_schema();
admin_ensure_archive_schema();
admin_ensure_department_logo_schema();

$user = current_user();
$section = $_GET['section'] ?? 'dashboard';
$allowedSections = ['dashboard', 'people', 'users', 'faculty', 'department',    'assignments', 'evaluations', 'ai_actions', 'reports', 'directory', 'dept_management', 'settings',
    'form_b_categories', 'form_b_questionnaire', 'ai_assistance', 'computation_rules', 'evaluation_periods',
    'form_a_categories', 'form_a_questionnaire', 'form_a_ai_assistance', 'form_a_periods'];

if (!in_array($section, $allowedSections, true)) {
    $section = 'dashboard';
}

if (in_array($section, ['users', 'faculty'], true)) {
    $section = 'people';
}

$searchQuery = trim((string) ($_GET['search'] ?? ''));

function admin_search_matches(array $values, string $query): bool
{
    if ($query === '') {
        return true;
    }

    $needle = strtolower($query);
    foreach ($values as $value) {
        if (is_array($value)) {
            if (admin_search_matches($value, $query)) {
                return true;
            }
            continue;
        }

        if ($value !== null && str_contains(strtolower((string) $value), $needle)) {
            return true;
        }
    }

    return false;
}

function admin_filter_rows(array $rows, string $query, array $keys = []): array
{
    if ($query === '') {
        return $rows;
    }

    return array_values(array_filter($rows, function (array $row) use ($query, $keys): bool {
        if ($keys === []) {
            return admin_search_matches($row, $query);
        }

        $values = [];
        foreach ($keys as $key) {
            $values[] = $row[$key] ?? null;
        }

        return admin_search_matches($values, $query);
    }));
}

function admin_is_leadership_role(string $role): bool
{
    return in_array($role, ['vpaa', 'dean', 'program_head'], true);
}

function admin_is_leadership_position(string $positionTitle): bool
{
    $position = strtolower($positionTitle);

    return str_contains($position, 'dean') || str_contains($position, 'program head');
}

function admin_filter_department_directory(array $directory, string $query): array
{
    if ($query === '') {
        return $directory;
    }

    return array_values(array_filter($directory, function (array $group) use ($query): bool {
        return admin_search_matches([
            $group['department'] ?? [],
            $group['programs'] ?? [],
            $group['faculty'] ?? [],
            $group['users'] ?? [],
        ], $query);
    }));
}

function admin_redirect_section(): string
{
    $section = $_POST['return_section'] ?? $_GET['section'] ?? 'people';
    $allowed = ['dashboard', 'people', 'department', 'assignments', 'evaluations', 'ai_actions', 'reports', 'settings'];
    return in_array($section, $allowed, true) ? $section : 'people';
}

function admin_redirect(string $section, string $message = 'Saved successfully.'): never
{
    $_SESSION['flash'] = $message;
    redirect('/dashboards/admin_hr.php?section=' . $section);
}

function admin_require_csrf(): void
{
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
        redirect('/dashboards/admin_hr.php');
    }
}

function form_b_handle_post(string $action): void
{
    throw new RuntimeException('Unsupported Admin/HR Form B action: ' . $action);
}

function form_a_handle_post(string $action): void
{
    throw new RuntimeException('Unsupported Admin/HR Form A action: ' . $action);
}

function admin_department_logo_upload(string $departmentCode): ?string
{
    if (!isset($_FILES['department_logo']) || !is_array($_FILES['department_logo'])) {
        return null;
    }

    $file = $_FILES['department_logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Department logo upload failed. Please try another image.');
    }

    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Department logo must be 3 MB or smaller.');
    }

    $tmpName = $file['tmp_name'] ?? '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Department logo must be a JPG, PNG, WEBP, or GIF image.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/departments';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Cannot create department logo upload folder.');
    }

    $safeCode = preg_replace('/[^A-Z0-9_-]/', '', strtoupper($departmentCode)) ?: 'DEPARTMENT';
    $fileName = 'department_' . $safeCode . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mimeType];
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Could not save the department logo.');
    }

    return 'assets/uploads/departments/' . $fileName;
}

function admin_department_logo_url(array $department): string
{
    $logo = trim((string) ($department['logo_image'] ?? ''));
    return $logo !== '' ? BASE_URL . '/' . ltrim($logo, '/') : BASE_URL . '/assets/images/ndmc-seal.png';
}

function admin_avatar_markup(array $profile, string $class = 'admin-avatar'): string
{
    $image = trim((string) ($profile['profile_image'] ?? ''));
    $name = (string) ($profile['full_name'] ?? 'User');

    if ($image !== '') {
        return '<img class="' . e($class) . '" src="' . e(BASE_URL . '/' . $image) . '" alt="' . e($name) . '">';
    }

    return '<div class="' . e($class) . '">' . e(strtoupper(substr($name !== '' ? $name : 'U', 0, 1))) . '</div>';
}

function admin_import_questionnaire_csv(array $file): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a CSV questionnaire file to upload.');
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Questionnaire upload failed. Please try again.');
    }

    if (($file['size'] ?? 0) > 1024 * 1024) {
        throw new RuntimeException('Questionnaire CSV must be 1 MB or smaller.');
    }

    $handle = fopen((string) $file['tmp_name'], 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not read uploaded questionnaire CSV.');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('Questionnaire CSV is empty.');
    }

    $columns = array_map(fn (string $value): string => strtolower(trim($value)), $header);
    $imported = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $values = array_slice(array_pad($row, count($columns), ''), 0, count($columns));
        $data = array_combine($columns, $values);
        if (!is_array($data)) {
            continue;
        }

        $factorName = trim((string) ($data['factor_name'] ?? $data['factor'] ?? ''));
        $questionText = trim((string) ($data['question_text'] ?? $data['question'] ?? ''));
        if ($factorName === '' || $questionText === '') {
            continue;
        }

        db()->prepare(
            'INSERT INTO performance_factors (factor_name, weight_percent, description, is_active)
             VALUES (:factor_name, :weight_percent, :description, 1)
             ON DUPLICATE KEY UPDATE
             weight_percent = IF(VALUES(weight_percent) > 0, VALUES(weight_percent), weight_percent),
             description = IF(VALUES(description) != "", VALUES(description), description),
             is_active = 1'
        )->execute([
            'factor_name' => $factorName,
            'weight_percent' => (float) ($data['weight_percent'] ?? $data['weight'] ?? 0),
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        $factor = admin_one('SELECT id FROM performance_factors WHERE factor_name = :factor_name', ['factor_name' => $factorName]);
        if ($factor === null) {
            continue;
        }

        $questionType = strtolower(trim((string) ($data['question_type'] ?? 'rating')));
        if (!in_array($questionType, ['rating', 'comment'], true)) {
            $questionType = 'rating';
        }

        db()->prepare(
            'INSERT INTO appraisal_questionnaires (factor_id, question_text, question_type, is_active)
             SELECT :factor_id, :question_text, :question_type, 1
             WHERE NOT EXISTS (
                 SELECT 1 FROM appraisal_questionnaires
                 WHERE factor_id = :factor_id AND question_text = :question_text
             )'
        )->execute([
            'factor_id' => (int) $factor['id'],
            'question_text' => $questionText,
            'question_type' => $questionType,
        ]);
        $imported++;
    }

    fclose($handle);
    return $imported;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        // PMAS Form B handlers (must run before other handlers to allow Form B actions to be intercepted)
        if (strpos($action, 'save_form_b_') === 0 || strpos($action, 'reset_form_b_') === 0 || strpos($action, 'toggle_form_b_') === 0 || strpos($action, 'delete_form_b_') === 0 || $action === 'save_ai_assistance' || $action === 'save_evaluation_period') {
            form_b_handle_post($action);
        }

        if (strpos($action, 'save_form_a_') === 0 || strpos($action, 'reset_form_a_') === 0 || strpos($action, 'toggle_form_a_') === 0 || strpos($action, 'delete_form_a_') === 0 || $action === 'save_form_a_ai_assistance' || $action === 'save_form_a_period') {
            form_a_handle_post($action);
        }

        if ($action === 'save_person') {
            admin_ensure_faculty_program_schema();
            $id = (int) ($_POST['id'] ?? 0);
            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            $password = trim($_POST['password'] ?? '');
            $role = $_POST['role'] ?? 'teacher';
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $programCode = strtoupper(trim($_POST['program_code'] ?? ''));
            $selectedProgram = null;

            if ($fullName === '' || $email === '') {
                throw new RuntimeException('Full name and email are required.');
            }

            if ($id === 0 && $role === 'admin_hr') {
                throw new RuntimeException('Only the existing super admin can have the Admin/HR role.');
            }

            if ($role !== 'admin_hr') {
                if ($department === '') {
                    throw new RuntimeException('Department is required for faculty, Dean, Program Head, and Teacher accounts.');
                }

                $departmentRecord = admin_one(
                    'SELECT id, department_code, department_name, dean_user_id
                     FROM departments
                     WHERE is_active = 1 AND (department_name = :department OR department_code = :department)
                     LIMIT 1',
                    ['department' => $department]
                );

                if ($departmentRecord === null) {
                    throw new RuntimeException('Select a valid department before saving this account.');
                }

                if ($role === 'vpaa') {
                    $existingVpaa = admin_one(
                        'SELECT id FROM users WHERE role = "vpaa" AND is_active = 1 AND id <> :id LIMIT 1',
                        ['id' => $id]
                    );

                    if ($existingVpaa !== null) {
                        throw new RuntimeException('Only one VPAA account is allowed in the system.');
                    }
                }

                if ($role === 'dean') {
                    if ((int) ($departmentRecord['dean_user_id'] ?? 0) > 0 && (int) $departmentRecord['dean_user_id'] !== $id) {
                        throw new RuntimeException('There is already a Dean assigned to this department.');
                    }

                    $existingDean = admin_one(
                        'SELECT id, full_name
                         FROM users
                         WHERE role = "dean" AND is_active = 1 AND department = :department AND id <> :id
                         LIMIT 1',
                        ['department' => $department, 'id' => $id]
                    );

                    if ($existingDean !== null) {
                        throw new RuntimeException('There is already a Dean assigned to this department.');
                    }
                }

                if (in_array($role, ['program_head', 'teacher'], true)) {
                    if ($programCode === '') {
                        throw new RuntimeException('Select a program/course for this account.');
                    }
                }

                if ($programCode !== '' && in_array($role, ['dean', 'program_head', 'teacher'], true)) {
                    $selectedProgram = admin_one(
                        'SELECT p.id, p.program_code, p.program_head_user_id
                         FROM programs p
                         JOIN departments d ON d.id = p.department_id
                         WHERE p.is_active = 1
                           AND p.program_code = :program_code
                           AND d.id = :department_id
                         LIMIT 1',
                        [
                            'program_code' => $programCode,
                            'department_id' => (int) $departmentRecord['id'],
                        ]
                    );

                    if ($selectedProgram === null) {
                        throw new RuntimeException('The selected program/course does not belong to this department.');
                    }
                }

                if ($role === 'program_head') {
                    if (isset($selectedProgram) && (int) ($selectedProgram['program_head_user_id'] ?? 0) > 0 && (int) $selectedProgram['program_head_user_id'] !== $id) {
                        throw new RuntimeException('There is already a Program Head assigned to this program/course.');
                    }

                    $existingProgramHead = admin_one(
                        'SELECT u.id, u.full_name
                         FROM users u
                         JOIN faculty f ON f.user_id = u.id
                         WHERE u.role = "program_head"
                           AND u.is_active = 1
                           AND f.program_code = :program_code
                           AND u.id <> :id
                         LIMIT 1',
                        ['program_code' => $programCode, 'id' => $id]
                    );

                    if ($existingProgramHead !== null) {
                        throw new RuntimeException('There is already a Program Head assigned to this program/course.');
                    }
                }
            } else {
                $programCode = '';
            }

            if (!in_array($role, ['dean', 'program_head', 'teacher'], true)) {
                $programCode = '';
            }

            db()->beginTransaction();

            $userParams = [
                'full_name' => $fullName,
                'email' => $email,
                'role' => $role,
                'phone' => $phone,
                'department' => $department,
                'program' => $programCode,
                'is_active' => 1,
            ];

            if ($id > 0) {
                $sql = 'UPDATE users SET full_name=:full_name, email=:email, role=:role, phone=:phone,
                        department=:department, program=:program, is_active=:is_active';
                if ($password !== '') {
                    $sql .= ', password_hash=:password_hash';
                    $userParams['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=:id';
                $userParams['id'] = $id;
                db()->prepare($sql)->execute($userParams);
            } else {
                if ($password === '') {
                    throw new RuntimeException('Password is required for new users.');
                }
                $userParams['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, phone, department, program, is_active)
                     VALUES (:full_name, :email, :password_hash, :role, :phone, :department, :program, :is_active)'
                )->execute($userParams);
                $id = (int) db()->lastInsertId();
            }

            if ($id === (int) $user['id']) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['role'] = $role;
            }

            $profileImage = admin_profile_image_upload($id);
            if ($profileImage !== null) {
                db()->prepare('UPDATE users SET profile_image = :profile_image WHERE id = :id')->execute([
                    'profile_image' => $profileImage,
                    'id' => $id,
                ]);
                if ($id === (int) $user['id']) {
                    $_SESSION['user']['profile_image'] = $profileImage;
                }
            }

            if ($role === 'vpaa') {
                vpaa_sync_departments_for_user($id, $department);
            } else {
                db()->prepare('DELETE FROM vpaa_departments WHERE vpaa_user_id = :id')->execute(['id' => $id]);
            }

            if (!in_array($role, ['admin_hr', 'vpaa'], true)) {
                if ($department === '') {
                    throw new RuntimeException('Department is required for faculty, Dean, Program Head, and Teacher accounts.');
                }

                $existingFaculty = null;
                if ($facultyId > 0) {
                    $existingFaculty = admin_one('SELECT * FROM faculty WHERE id = :id', ['id' => $facultyId]);
                }
                if ($existingFaculty === null) {
                    $existingFaculty = admin_one('SELECT * FROM faculty WHERE email = :email', ['email' => $email]);
                }

                $facultyParams = [
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'department' => $department,
                    'program_code' => $programCode,
                    'position_title' => trim($_POST['position_title'] ?? '') ?: admin_role_label($role),
                    'academic_qualifications' => trim($_POST['academic_qualifications'] ?? ''),
                    'progress_percent' => array_key_exists('progress_percent', $_POST)
                        ? max(0, min(100, (int) $_POST['progress_percent']))
                        : (int) ($existingFaculty['progress_percent'] ?? 0),
                    'performance_notes' => trim($_POST['performance_notes'] ?? ''),
                ];

                $facultyId = (int) ($existingFaculty['id'] ?? $facultyId);

                if ($facultyId > 0) {
                    $facultyParams['id'] = $facultyId;
                    db()->prepare(
                        'UPDATE faculty SET full_name=:full_name, email=:email, phone=:phone, department=:department,
                         program_code=:program_code, position_title=:position_title, academic_qualifications=:academic_qualifications,
                         progress_percent=:progress_percent, performance_notes=:performance_notes WHERE id=:id'
                    )->execute($facultyParams);
                } else {
                    db()->prepare(
                        'INSERT INTO faculty (full_name, email, phone, department, program_code, position_title, academic_qualifications,
                         progress_percent, performance_notes)
                         VALUES (:full_name, :email, :phone, :department, :program_code, :position_title, :academic_qualifications,
                        :progress_percent, :performance_notes)'
                    )->execute($facultyParams);
                }

                if ($role === 'dean') {
                    db()->prepare(
                        'UPDATE departments
                         SET dean_user_id = :dean_user_id
                         WHERE id = :department_id'
                    )->execute([
                        'dean_user_id' => $id,
                        'department_id' => (int) $departmentRecord['id'],
                    ]);
                }

                if ($role === 'program_head') {
                    db()->prepare(
                        'UPDATE programs
                         SET program_head_user_id = :program_head_user_id
                         WHERE department_id = :department_id AND program_code = :program_code'
                    )->execute([
                        'program_head_user_id' => $id,
                        'department_id' => (int) $departmentRecord['id'],
                        'program_code' => $programCode,
                    ]);
                }

                // ── Auto-create evaluation assignments ──────────────────────────
                if ($role === 'dean') {
                    $deptCode = admin_one('SELECT department_code FROM departments WHERE id = :id', ['id' => (int) $departmentRecord['id']]);
                    if ($deptCode !== null) {
                        $code = $deptCode['department_code'];
                        $currentPeriod = admin_one("SELECT period_name FROM appraisal_periods WHERE status = 'open' ORDER BY date_start DESC LIMIT 1");
                        $cycleName = $currentPeriod['period_name'] ?? date('Y') . ' Appraisal Cycle';

                        $facultyMembers = admin_all(
                            'SELECT id FROM faculty WHERE department = :department',
                            ['department' => $code]
                        );

                        $deadline = date('Y-m-d', strtotime('+14 days'));
                        $insertAssignment = db()->prepare(
                            'INSERT IGNORE INTO peer_assignments
                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
                             VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "dean", "dean", "pending", :deadline)'
                        );

                        $created = 0;
                        foreach ($facultyMembers as $faculty) {
                            $insertAssignment->execute([
                                'cycle_name' => $cycleName,
                                'evaluator_user_id' => $id,
                                'evaluatee_faculty_id' => (int) $faculty['id'],
                                'deadline' => $deadline,
                            ]);
                            $created += $insertAssignment->rowCount();
                        }

                        if ($created > 0) {
                            admin_activity('Auto-created ' . $created . ' dean evaluation assignments for ' . $code . '.');
                        }
                    }
                }

                if ($role === 'program_head') {
                    $deptCode = admin_one('SELECT department_code FROM departments WHERE id = :id', ['id' => (int) $departmentRecord['id']]);
                    if ($deptCode !== null) {
                        $code = $deptCode['department_code'];
                        $currentPeriod = admin_one("SELECT period_name FROM appraisal_periods WHERE status = 'open' ORDER BY date_start DESC LIMIT 1");
                        $cycleName = $currentPeriod['period_name'] ?? date('Y') . ' Appraisal Cycle';

                        $facultyMembers = admin_all(
                            'SELECT id FROM faculty WHERE department = :department AND program_code = :program_code',
                            ['department' => $code, 'program_code' => $programCode]
                        );

                        $deadline = date('Y-m-d', strtotime('+14 days'));
                        $insertAssignment = db()->prepare(
                            'INSERT IGNORE INTO peer_assignments
                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
                             VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "dean", "dean", "pending", :deadline)'
                        );

                        $created = 0;
                        foreach ($facultyMembers as $faculty) {
                            $insertAssignment->execute([
                                'cycle_name' => $cycleName,
                                'evaluator_user_id' => $id,
                                'evaluatee_faculty_id' => (int) $faculty['id'],
                                'deadline' => $deadline,
                            ]);
                            $created += $insertAssignment->rowCount();
                        }

                        if ($created > 0) {
                            admin_activity('Auto-created ' . $created . ' program head evaluation assignments for ' . $code . '.');
                        }
                    }
                }

                if ($role === 'teacher') {
                    $facultyRow = admin_one(
                        'SELECT id FROM faculty WHERE email = :email',
                        ['email' => $email]
                    );
                    if ($facultyRow !== null) {
                        $currentPeriod = admin_one("SELECT period_name FROM appraisal_periods WHERE status = 'open' ORDER BY date_start DESC LIMIT 1");
                        $cycleName = $currentPeriod['period_name'] ?? date('Y') . ' Appraisal Cycle';

                        $deadline = date('Y-m-d', strtotime('+14 days'));
                        db()->prepare(
                            'INSERT IGNORE INTO peer_assignments
                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
                             VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "teacher", "self", "pending", :deadline)'
                        )->execute([
                            'cycle_name' => $cycleName,
                            'evaluator_user_id' => $id,
                            'evaluatee_faculty_id' => (int) $facultyRow['id'],
                            'deadline' => $deadline,
                        ]);
                    }
                }
            }

            // Notify only on NEW user creation (not profile edits)
            if ((int) ($_POST['id'] ?? 0) === 0 && $id > 0 && !in_array($role, ['admin_hr'], true)) {
                $roleLabel = admin_role_label($role);
                $deptInfo = $department !== '' ? ' in the ' . $department . ' department' : '';
                $progInfo = $programCode !== '' ? ', program ' . $programCode : '';
                notify_create($id, 'account_activity', 'Account Setup Complete',
                    'Your account has been created with the ' . $roleLabel . ' role' . $deptInfo . $progInfo . '.',
                    '/dashboards/' . $role . '.php'
                );
            }
            admin_activity('Saved person profile: ' . $email);
            db()->commit();
            admin_redirect('people', 'Person profile saved.');
        }

        if ($action === 'save_user') {
            admin_ensure_faculty_program_schema();
            $id = (int) ($_POST['id'] ?? 0);
            $password = trim($_POST['password'] ?? '');
            $programCode = strtoupper(trim($_POST['program_code'] ?? ''));
            $params = [
                'full_name' => trim($_POST['full_name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'role' => $_POST['role'] ?? 'teacher',
                'phone' => trim($_POST['phone'] ?? ''),
                'department' => trim($_POST['department'] ?? ''),
                'program' => $programCode,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];

            if ($params['role'] !== 'dean') {
                $params['program'] = '';
            }

            if ($params['role'] === 'dean' && $params['program'] !== '') {
                $departmentRecord = admin_one(
                    'SELECT id
                     FROM departments
                     WHERE is_active = 1 AND (department_name = :department OR department_code = :department)
                     LIMIT 1',
                    ['department' => $params['department']]
                );

                if ($departmentRecord === null) {
                    throw new RuntimeException('Select a valid department before assigning a Dean program.');
                }

                $selectedProgram = admin_one(
                    'SELECT p.id
                     FROM programs p
                     WHERE p.is_active = 1
                       AND p.department_id = :department_id
                       AND p.program_code = :program_code
                     LIMIT 1',
                    [
                        'department_id' => (int) $departmentRecord['id'],
                        'program_code' => $params['program'],
                    ]
                );

                if ($selectedProgram === null) {
                    throw new RuntimeException('The selected program/course does not belong to this department.');
                }
            }

            if ($id === 0 && $params['role'] === 'admin_hr') {
                throw new RuntimeException('Only the existing super admin can have the Admin/HR role.');
            }

            if ($params['role'] === 'vpaa') {
                $existingVpaa = admin_one(
                    'SELECT id FROM users WHERE role = "vpaa" AND is_active = 1 AND id <> :id LIMIT 1',
                    ['id' => $id]
                );

                if ($existingVpaa !== null) {
                    throw new RuntimeException('Only one VPAA account is allowed in the system.');
                }
            }

            if ($id > 0) {
                $sql = 'UPDATE users SET full_name=:full_name, email=:email, role=:role, phone=:phone,
                        department=:department, program=:program, is_active=:is_active';
                if ($password !== '') {
                    $sql .= ', password_hash=:password_hash';
                    $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=:id';
                $params['id'] = $id;
                db()->prepare($sql)->execute($params);
                if ($id === (int) $user['id']) {
                    $_SESSION['user']['full_name'] = $params['full_name'];
                    $_SESSION['user']['email'] = $params['email'];
                    $_SESSION['user']['role'] = $params['role'];
                }

                $profileImage = admin_profile_image_upload($id);
                if ($profileImage !== null) {
                    db()->prepare('UPDATE users SET profile_image = :profile_image WHERE id = :id')->execute([
                        'profile_image' => $profileImage,
                        'id' => $id,
                    ]);
                    if ($id === (int) $user['id']) {
                        $_SESSION['user']['profile_image'] = $profileImage;
                    }
                }
                admin_activity('Updated user account: ' . $params['email']);
            } else {
                if ($password === '') {
                    throw new RuntimeException('Password is required for new users.');
                }
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $insert = db()->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, phone, department, program, is_active)
                     VALUES (:full_name, :email, :password_hash, :role, :phone, :department, :program, :is_active)'
                );
                $insert->execute($params);
                $newUserId = (int) db()->lastInsertId();
                $id = $newUserId;
                $profileImage = admin_profile_image_upload($newUserId);
                if ($profileImage !== null) {
                    db()->prepare('UPDATE users SET profile_image = :profile_image WHERE id = :id')->execute([
                        'profile_image' => $profileImage,
                        'id' => $newUserId,
                    ]);
                }
                admin_activity('Created user account: ' . $params['email']);
            }

            if ($params['role'] === 'vpaa') {
                vpaa_sync_departments_for_user($id, (string) $params['department']);
            } else {
                db()->prepare('DELETE FROM vpaa_departments WHERE vpaa_user_id = :id')->execute(['id' => $id]);
            }
            admin_redirect('users');
        }

        if ($action === 'delete_user') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $user['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }
            db()->beginTransaction();
            try {
                db()->prepare('UPDATE users SET is_active = 0, department = NULL WHERE id = :id')->execute(['id' => $id]);
                db()->prepare('UPDATE departments SET dean_user_id = NULL WHERE dean_user_id = :id')->execute(['id' => $id]);
                db()->prepare('UPDATE programs SET program_head_user_id = NULL WHERE program_head_user_id = :id')->execute(['id' => $id]);
                db()->prepare('DELETE FROM vpaa_departments WHERE vpaa_user_id = :id')->execute(['id' => $id]);
                admin_ensure_archive_schema();
                db()->prepare('UPDATE peer_assignments SET is_archived = 1, archived_at = NOW(), archived_by = :archived_by WHERE evaluator_user_id = :id AND status = "pending"')->execute(['id' => $id, 'archived_by' => (int) ($_SESSION['user']['id'] ?? 0)]);
                db()->commit();
            } catch (Throwable $exception) {
                db()->rollBack();
                throw $exception;
            }
            admin_activity('Archived a user account.');
            admin_redirect('people', 'User archived.');
        }

        if ($action === 'save_faculty') {
            admin_ensure_faculty_program_schema();
            $id = (int) ($_POST['id'] ?? 0);
            $params = [
                'full_name' => trim($_POST['full_name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'department' => trim($_POST['department'] ?? ''),
                'program_code' => strtoupper(trim($_POST['program_code'] ?? '')),
                'position_title' => trim($_POST['position_title'] ?? ''),
                'academic_qualifications' => trim($_POST['academic_qualifications'] ?? ''),
                'progress_percent' => max(0, min(100, (int) ($_POST['progress_percent'] ?? 0))),
                'performance_notes' => trim($_POST['performance_notes'] ?? ''),
            ];

            if ($id > 0) {
                $params['id'] = $id;
                db()->prepare(
                    'UPDATE faculty SET full_name=:full_name, email=:email, phone=:phone, department=:department,
                     program_code=:program_code, position_title=:position_title, academic_qualifications=:academic_qualifications,
                     progress_percent=:progress_percent, performance_notes=:performance_notes WHERE id=:id'
                )->execute($params);
                admin_activity('Updated faculty record: ' . $params['full_name']);
            } else {
                db()->prepare(
                    'INSERT INTO faculty (full_name, email, phone, department, program_code, position_title, academic_qualifications,
                     progress_percent, performance_notes)
                     VALUES (:full_name, :email, :phone, :department, :program_code, :position_title, :academic_qualifications,
                     :progress_percent, :performance_notes)'
                )->execute($params);
                admin_activity('Created faculty record: ' . $params['full_name']);
            }
            admin_redirect('faculty');
        }

        if ($action === 'delete_faculty') {
            db()->prepare('UPDATE faculty SET is_archived = 1 WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
            admin_activity('Archived a faculty record.');
            admin_redirect('people', 'Faculty record archived.');
        }

        if ($action === 'save_evaluation' || $action === 'delete_evaluation') {
            throw new RuntimeException('Admin/HR can monitor evaluations only and cannot edit scoring records here.');
        }

        if ($action === 'send_reminders') {
            // Notify users with pending evaluations due within 7 days
            $reminderUsers = admin_all(
                "SELECT DISTINCT u.id, u.full_name
                 FROM evaluations e
                 JOIN users u ON u.id = e.evaluator_user_id
                 WHERE e.status != 'completed'
                   AND e.deadline <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                   AND e.deadline >= CURDATE()"
            );
            foreach ($reminderUsers as $reminderUser) {
                notify_create(
                    (int) $reminderUser['id'],
                    'system_update',
                    'Evaluation Deadline Approaching',
                    'You have pending evaluations due within 7 days. Please complete them before the deadline.',
                    '/dashboards/teacher.php?section=evaluate'
                );
            }
            db()->prepare(
                "UPDATE evaluations SET reminder_sent_at = NOW()
                 WHERE status != 'completed' AND deadline <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
            )->execute();
            $reminderCount = count($reminderUsers);
            admin_activity('Sent automated reminders for pending evaluations.');
            admin_redirect('evaluations', 'Reminders sent to ' . $reminderCount . ' user(s) with evaluations due within 7 days.');
        }

        if ($action === 'save_period') {
            db()->prepare(
                'INSERT INTO appraisal_periods (period_name, date_start, date_end, status)
                 VALUES (:period_name, :date_start, :date_end, :status)
                 ON DUPLICATE KEY UPDATE date_start=VALUES(date_start), date_end=VALUES(date_end), status=VALUES(status)'
            )->execute([
                'period_name' => trim($_POST['period_name'] ?? ''),
                'date_start' => $_POST['date_start'] ?? date('Y-m-d'),
                'date_end' => $_POST['date_end'] ?? date('Y-m-d'),
                'status' => $_POST['status'] ?? 'draft',
            ]);
            admin_save_setting('current_appraisal_cycle', trim($_POST['period_name'] ?? 'Current Appraisal Cycle'));
                        // Only notify if the period is transitioning TO open (not already open)
            $currentPeriod = admin_one(
                'SELECT status FROM appraisal_periods WHERE period_name = :name LIMIT 1',
                ['name' => trim($_POST['period_name'] ?? '')]
            );
            $wasAlreadyOpen = $currentPeriod !== null && $currentPeriod['status'] === 'open';
            if ($_POST['status'] === 'open' && !$wasAlreadyOpen && trim($_POST['period_name'] ?? '') !== '') {
                notify_role('teacher', 'system_update', 'Appraisal Period Opened',
                    'The ' . trim($_POST['period_name']) . ' appraisal period is now open. Please submit your evaluations on time.',
                    '/dashboards/teacher.php?section=evaluate'
                );
                notify_role('dean', 'system_update', 'Appraisal Period Opened',
                    'The ' . trim($_POST['period_name']) . ' appraisal period is now open. Please submit your evaluations on time.',
                    '/dashboards/dean.php?section=evaluate'
                );
                notify_role('program_head', 'system_update', 'Appraisal Period Opened',
                    'The ' . trim($_POST['period_name']) . ' appraisal period is now open. Please submit your evaluations on time.',
                    '/dashboards/program_head.php?section=evaluate'
                );
                notify_role('vpaa', 'system_update', 'Appraisal Period Opened',
                    'The ' . trim($_POST['period_name']) . ' appraisal period is now open. Please monitor evaluations across all departments.',
                    '/dashboards/vpaa.php'
                );
            }
            admin_activity('Saved appraisal period.');
            admin_redirect('assignments', 'Appraisal period saved.');
        }

        if ($action === 'save_factor') {
            db()->prepare(
                'INSERT INTO performance_factors (factor_name, weight_percent, description, is_active)
                 VALUES (:factor_name, :weight_percent, :description, 1)
                 ON DUPLICATE KEY UPDATE weight_percent=VALUES(weight_percent), description=VALUES(description), is_active=1'
            )->execute([
                'factor_name' => trim($_POST['factor_name'] ?? ''),
                'weight_percent' => (float) ($_POST['weight_percent'] ?? 0),
                'description' => trim($_POST['description'] ?? ''),
            ]);
            admin_activity('Saved performance factor and weight.');
            admin_redirect('assignments', 'Performance factor saved.');
        }

        if ($action === 'save_question') {
            db()->prepare(
                'INSERT INTO appraisal_questionnaires (factor_id, question_text, question_type, is_active)
                 VALUES (:factor_id, :question_text, :question_type, 1)'
            )->execute([
                'factor_id' => (int) ($_POST['factor_id'] ?? 0),
                'question_text' => trim($_POST['question_text'] ?? ''),
                'question_type' => $_POST['question_type'] ?? 'rating',
            ]);
            admin_activity('Added appraisal questionnaire item.');
            admin_redirect('assignments', 'Questionnaire item added.');
        }

        if ($action === 'upload_questionnaire') {
            $imported = admin_import_questionnaire_csv($_FILES['questionnaire_csv'] ?? []);
            admin_activity('Uploaded appraisal questionnaire CSV with ' . $imported . ' item(s).');
            admin_redirect('assignments', $imported . ' questionnaire item(s) imported.');
        }

        if ($action === 'save_department') {
            $departmentCode = strtoupper(trim($_POST['department_code'] ?? ''));
            $departmentName = admin_normalize_department_name(trim($_POST['department_name'] ?? ''));
            $deanUserId = ($_POST['dean_user_id'] ?? '') === '' ? null : (int) $_POST['dean_user_id'];

            if ($departmentCode === '' || $departmentName === '') {
                throw new RuntimeException('Department code and department name are required.');
            }

            if ($deanUserId !== null) {
                $dean = admin_one('SELECT id FROM users WHERE id = :id AND role = "dean" AND is_active = 1', ['id' => $deanUserId]);
                if ($dean === null) {
                    throw new RuntimeException('Selected dean is not a valid active Dean account.');
                }
            }

            $departmentLogo = admin_department_logo_upload($departmentCode);

            db()->prepare(
                'INSERT INTO departments (department_code, department_name, dean_user_id, logo_image, is_active)
                 VALUES (:department_code, :department_name, :dean_user_id, :logo_image, 1)
                 ON DUPLICATE KEY UPDATE
                    department_name=VALUES(department_name),
                    dean_user_id=VALUES(dean_user_id),
                    logo_image=COALESCE(VALUES(logo_image), logo_image),
                    is_active=1'
            )->execute([
                'department_code' => $departmentCode,
                'department_name' => $departmentName,
                'dean_user_id' => $deanUserId,
                'logo_image' => $departmentLogo,
            ]);
            if ($deanUserId !== null) {
                notify_create($deanUserId, 'account_activity', 'Dean Assignment',
                    'You have been assigned as the Dean of ' . $departmentName . ' (' . $departmentCode . ').',
                    '/dashboards/dean.php'
                );
            }
            admin_activity('Saved department.');
            if (admin_redirect_section() === 'department') {
                $savedDepartment = admin_one('SELECT id FROM departments WHERE department_code = :department_code', ['department_code' => $departmentCode]);
                $departmentId = (int) ($savedDepartment['id'] ?? 0);
                if ($departmentId > 0) {
                    $_SESSION['flash'] = 'Department saved successfully.';
                    redirect('/dashboards/admin_hr.php?section=department&department_id=' . $departmentId);
                }
            }
            admin_redirect(admin_redirect_section(), 'Department saved successfully.');
        }

        if ($action === 'assign_dean') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $deanUserId = ($_POST['dean_user_id'] ?? '') === '' ? null : (int) $_POST['dean_user_id'];

            if ($departmentId <= 0) {
                throw new RuntimeException('Department is required.');
            }

            if ($deanUserId !== null) {
                $dean = admin_one('SELECT id FROM users WHERE id = :id AND role IN ("admin_hr", "dean") AND is_active = 1', ['id' => $deanUserId]);
                if ($dean === null) {
                    throw new RuntimeException('Selected user is not a valid active admin or dean account.');
                }
            }

            db()->prepare(
                'UPDATE departments SET dean_user_id = :dean_user_id WHERE id = :department_id'
            )->execute([
                'dean_user_id' => $deanUserId,
                'department_id' => $departmentId,
            ]);

            // ── Auto-create dean evaluation assignments ─────────────────────
            if ($deanUserId !== null) {
                $dept = admin_one('SELECT department_code FROM departments WHERE id = :id', ['id' => $departmentId]);
                if ($dept !== null) {
                    $deptCode = $dept['department_code'];

                    // Get the current open appraisal period to use as cycle name
                    $currentPeriod = admin_one("SELECT period_name FROM appraisal_periods WHERE status = 'open' ORDER BY date_start DESC LIMIT 1");
                    $cycleName = $currentPeriod['period_name'] ?? date('Y') . ' Appraisal Cycle';

                    // Find all faculty in this department
                    $facultyMembers = admin_all(
                        'SELECT id FROM faculty WHERE department = :department',
                        ['department' => $deptCode]
                    );

                    // Create a peer_assignment for each faculty member (evaluator = dean)
                    $deadline = date('Y-m-d', strtotime('+14 days'));
                    $insertAssignment = db()->prepare(
                        'INSERT IGNORE INTO peer_assignments
                         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
                         VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "dean", "dean", "pending", :deadline)'
                    );

                    $created = 0;
                    foreach ($facultyMembers as $faculty) {
                        $insertAssignment->execute([
                            'cycle_name' => $cycleName,
                            'evaluator_user_id' => $deanUserId,
                            'evaluatee_faculty_id' => (int) $faculty['id'],
                            'deadline' => $deadline,
                        ]);
                        $created += $insertAssignment->rowCount();
                    }

                    if ($created > 0) {
                        admin_activity('Auto-created ' . $created . ' dean evaluation assignments for ' . $deptCode . '.');
                    }
                }
            }

            if ($deanUserId !== null) {
                notify_create($deanUserId, 'account_activity', 'Dean Assignment',
                    'You have been assigned as the Dean of your department. Evaluation tasks are now available.',
                    '/dashboards/dean.php'
                );
            }
            admin_activity('Updated department dean.');
            $_SESSION['flash'] = 'Department dean updated successfully.';
            redirect('/dashboards/admin_hr.php?section=dept_management&dept_mgmt_id=' . $departmentId);
        }

        if ($action === 'save_program') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $programCode = strtoupper(trim($_POST['program_code'] ?? ''));
            $programName = trim($_POST['program_name'] ?? '');
            $programHeadUserId = ($_POST['program_head_user_id'] ?? '') === '' ? null : (int) $_POST['program_head_user_id'];

            if ($departmentId <= 0 || $programCode === '' || $programName === '') {
                throw new RuntimeException('Department, program code, and program name are required.');
            }

            $department = admin_one('SELECT id FROM departments WHERE id = :id AND is_active = 1', ['id' => $departmentId]);
            if ($department === null) {
                throw new RuntimeException('Selected department is not valid.');
            }

            if ($programHeadUserId !== null) {
                $programHead = admin_one('SELECT id FROM users WHERE id = :id AND role = "program_head" AND is_active = 1', ['id' => $programHeadUserId]);
                if ($programHead === null) {
                    throw new RuntimeException('Selected program head is not a valid active Program Head account.');
                }
            }

            db()->prepare(
                'INSERT INTO programs (department_id, program_code, program_name, program_head_user_id, is_active)
                 VALUES (:department_id, :program_code, :program_name, :program_head_user_id, 1)
                 ON DUPLICATE KEY UPDATE program_name=VALUES(program_name), program_head_user_id=VALUES(program_head_user_id), is_active=1'
            )->execute([
                'department_id' => $departmentId,
                'program_code' => $programCode,
                'program_name' => $programName,
                'program_head_user_id' => $programHeadUserId,
            ]);
            if ($programHeadUserId !== null) {
                $savedProgram = admin_one(
                    'SELECT program_name FROM programs WHERE program_code = :code AND department_id = :dept_id',
                    ['code' => $programCode, 'dept_id' => $departmentId]
                );
                $progName = $savedProgram['program_name'] ?? $programName;
                notify_create($programHeadUserId, 'account_activity', 'Program Head Assignment',
                    'You have been assigned as Program Head for ' . $programCode . ' - ' . $progName . '.',
                    '/dashboards/program_head.php'
                );
            }
            admin_activity('Saved program.');
            admin_redirect(admin_redirect_section(), 'Program saved successfully.');
        }

        if ($action === 'save_rule') {
            db()->prepare(
                'INSERT INTO evaluation_rules (rule_name, evaluator_role, evaluatee_role, assignment_type, peer_count, is_confidential, is_active)
                 VALUES (:rule_name, :evaluator_role, :evaluatee_role, :assignment_type, :peer_count, :is_confidential, 1)
                 ON DUPLICATE KEY UPDATE evaluator_role=VALUES(evaluator_role), evaluatee_role=VALUES(evaluatee_role),
                 assignment_type=VALUES(assignment_type), peer_count=VALUES(peer_count), is_confidential=VALUES(is_confidential), is_active=1'
            )->execute([
                'rule_name' => trim($_POST['rule_name'] ?? ''),
                'evaluator_role' => $_POST['evaluator_role'] ?? 'teacher',
                'evaluatee_role' => $_POST['evaluatee_role'] ?? 'teacher',
                'assignment_type' => $_POST['assignment_type'] ?? 'peer',
                'peer_count' => max(1, (int) ($_POST['peer_count'] ?? 1)),
                'is_confidential' => isset($_POST['is_confidential']) ? 1 : 0,
            ]);
            admin_activity('Saved evaluation rule.');
            admin_redirect('assignments', 'Evaluation assignment rule saved.');
        }

        if ($action === 'randomize_peers') {
            $cycle = trim($_POST['cycle_name'] ?? 'Current Appraisal Cycle');
            $teachers = admin_all("SELECT id, email FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY RAND()");
            foreach ($teachers as $teacher) {
                $faculty = admin_one(
                    'SELECT id FROM faculty WHERE email != :email ORDER BY RAND() LIMIT 1',
                    ['email' => $teacher['email']]
                );
                if (!$faculty) {
                    continue;
                }
                $deadline = date('Y-m-d', strtotime('+14 days'));
                db()->prepare(
                    'INSERT IGNORE INTO peer_assignments
                     (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
                     VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "teacher", "peer", "pending", :deadline)'
                )->execute([
                    'cycle_name' => $cycle,
                    'evaluator_user_id' => $teacher['id'],
                    'evaluatee_faculty_id' => $faculty['id'],
                    'deadline' => $deadline,
                ]);
            }
            admin_activity('Randomized confidential peer evaluators for ' . $cycle . '.');
            notify_role('teacher', 'system_update', 'New Peer Evaluation Assigned',
                'Confidential peer evaluation assignments have been created for the ' . $cycle . '. Please check your evaluation tasks.',
                '/dashboards/teacher.php?section=evaluate'
            );
            admin_redirect('assignments', 'Peer evaluators randomized confidentially.');
        }

        if ($action === 'assign_leadership_evaluations') {
            $cycle = trim($_POST['cycle_name'] ?? 'Current Appraisal Cycle');
            $teachers = admin_all(
                "SELECT id, department FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY full_name"
            );
            $created = 0;

            foreach ($teachers as $teacher) {
                foreach (admin_leadership_evaluatees((string) ($teacher['department'] ?? '')) as $leader) {
                    if ((int) $leader['faculty_id'] === 0) {
                        continue;
                    }

                    $deadline = date('Y-m-d', strtotime('+14 days'));
                    $insertAssignment = db()->prepare(
                        'INSERT IGNORE INTO peer_assignments
                         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
                         VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "teacher", :assignment_type, "pending", :deadline)'
                    );
                    $insertAssignment->execute([
                        'cycle_name' => $cycle,
                        'evaluator_user_id' => (int) $teacher['id'],
                        'evaluatee_faculty_id' => (int) $leader['faculty_id'],
                        'assignment_type' => $leader['role'] === 'dean' ? 'dean' : 'program_head',
                        'deadline' => $deadline,
                    ]);

                    $created += $insertAssignment->rowCount();
                }
            }

            admin_activity('Assigned teacher evaluations for Deans and Program Heads for ' . $cycle . '.');
            if ($created > 0) {
                notify_role('teacher', 'system_update', 'Leadership Evaluation Tasks Available',
                    $created . ' evaluation tasks for Deans and Program Heads have been created for ' . $cycle . '. Please complete your evaluations.',
                    '/dashboards/teacher.php?section=evaluate'
                );
            }
            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');
        }

        if ($action === 'generate_peer_to_peer') {
            $cycle = trim($_POST['cycle_name'] ?? 'Current Appraisal Cycle');
            $deadline = date('Y-m-d', strtotime('+14 days'));

            $result = dipascaf_generate_peer_to_peer_assignments($cycle, $deadline);

            $created = $result['created'];
            $skipped = $result['skipped_existing'];
            $groups = $result['groups_processed'];
            $invalid = $result['invalid_groups'];

            $invalidMessages = [];
            foreach ($invalid as $group) {
                $invalidMessages[] = $group['scope'] . ' (' . $group['eligible'] . ' eligible)';
            }

            $message = 'Peer-to-peer evaluations generated. Created: ' . $created . ', Skipped: ' . $skipped . ', Groups: ' . $groups;
            if ($invalidMessages !== []) {
                $message .= '. Insufficient members: ' . implode('; ', $invalidMessages);
            }
            $message .= '.';

            if ($created > 0 || $skipped > 0) {
                admin_activity($message);
                if ($created > 0) {
                    notify_role('teacher', 'system_update', 'Peer-to-Peer Evaluation Assignments Ready',
                        $created . ' peer-to-peer evaluations have been generated for ' . $cycle . '. Please check your evaluation tasks.',
                        '/dashboards/teacher.php?section=evaluate'
                    );
                }
                admin_redirect('assignments', $message);
            } else {
                $_SESSION['flash_error'] = 'Could not generate peer-to-peer evaluations. ' . ($invalidMessages !== [] ? 'Insufficient members in: ' . implode('; ', $invalidMessages) : 'No eligible groups found.');
                redirect('/dashboards/admin_hr.php?section=assignments');
            }
        }

        if ($action === 'update_intervention') {
            db()->prepare('UPDATE intervention_plans SET status = :status WHERE id = :id')->execute([
                'status' => $_POST['status'] ?? 'planned',
                'id' => (int) ($_POST['id'] ?? 0),
            ]);
            admin_activity('Updated intervention completion status.');
            admin_redirect('ai_actions');
        }

        if ($action === 'save_settings') {
            admin_save_setting('notifications_enabled', isset($_POST['notifications_enabled']) ? '1' : '0');
            admin_save_setting('teacher_results_released', isset($_POST['teacher_results_released']) ? '1' : '0');
            admin_save_setting('self_evaluation_enabled', isset($_POST['self_evaluation_enabled']) ? '1' : '0');
            admin_save_setting('dashboard_refresh_seconds', (string) max(5, (int) ($_POST['dashboard_refresh_seconds'] ?? 10)));
            admin_save_setting('default_report_format', $_POST['default_report_format'] ?? 'csv');

            $params = [
                'id' => $user['id'],
                'full_name' => trim($_POST['full_name'] ?? $user['full_name']),
                'email' => trim($_POST['email'] ?? $user['email']),
            ];
            db()->prepare('UPDATE users SET full_name=:full_name, email=:email WHERE id=:id')->execute($params);
            db()->prepare(
                'UPDATE faculty
                 SET full_name = :full_name, email = :email
                 WHERE user_id = :id OR email = :old_email'
            )->execute([
                'full_name' => $params['full_name'],
                'email' => $params['email'],
                'id' => $user['id'],
                'old_email' => $user['email'],
            ]);

            $profileImage = admin_profile_image_upload((int) $user['id']);
            if ($profileImage !== null) {
                db()->prepare('UPDATE users SET profile_image=:profile_image WHERE id=:id')->execute([
                    'profile_image' => $profileImage,
                    'id' => $user['id'],
                ]);
                $_SESSION['user']['profile_image'] = $profileImage;
            }

            $password = trim($_POST['password'] ?? '');
            if ($password !== '') {
                db()->prepare('UPDATE users SET password_hash=:password_hash WHERE id=:id')->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
            }
            $_SESSION['user']['full_name'] = $params['full_name'];
            $_SESSION['user']['email'] = $params['email'];
            admin_activity('Updated Admin/HR settings.');
            notify_create((int) $user['id'], 'account_activity', 'Settings Updated',
                'Your system settings have been saved successfully.',
                '/dashboards/admin_hr.php?section=settings'
            );
            admin_redirect('settings');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash_error'] = $exception->getMessage();
        redirect('/dashboards/admin_hr.php?section=' . $section);
    }
}

$flash = $_SESSION['flash'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

$showAdminAiPrompt = $section === 'dashboard' && empty($_SESSION['admin_ai_prompt_shown']);
if ($showAdminAiPrompt) {
    $_SESSION['admin_ai_prompt_shown'] = true;
}
$adminAiSuggestions = [
    'How many evaluations are still pending?',
    'Generate faculty performance reports.',
    'Show departments with low completion rates.',
    'Which faculty members need improvement?',
    'View AI-generated recommendations.',
    'Show overall evaluation statistics.',
    'Who has not submitted evaluations yet?',
    'Generate printable appraisal results.',
    'Check faculty with the highest ratings.',
    'Show recent system activities.',
];

$freshUser = admin_one('SELECT id, full_name, email, role, profile_image FROM users WHERE id = :id', ['id' => (int) $user['id']]);
if ($freshUser !== null) {
    $_SESSION['user'] = array_merge($_SESSION['user'], $freshUser);
    $user = $_SESSION['user'];
}

$stats = admin_stats();
admin_ensure_evaluation_role_schema();
$users = admin_users();
$faculty = admin_faculty();
$evaluations = admin_evaluations();
$aiInsights = admin_ai_insights();
$interventions = admin_interventions();
$peerAssignments = admin_peer_assignments();
$leadershipEvaluatees = admin_leadership_evaluatees();
$peerAssignmentsFull = admin_peer_assignments_full();
$departments = admin_departments();
$programs = admin_programs();
$departmentDirectory = admin_department_directory();
$selectedDepartment = $section === 'department'
    ? admin_department_directory_by_id((int) ($_GET['department_id'] ?? 0))
    : null;
$periods = admin_periods();
$factors = admin_factors();
$questionnaires = admin_questionnaires();
$rules = admin_rules();
$departmentWeakAreas = admin_department_weak_areas();
$editUser = isset($_GET['edit_user']) ? admin_one('SELECT * FROM users WHERE id = :id', ['id' => (int) $_GET['edit_user']]) : null;
$editFaculty = isset($_GET['edit_faculty']) ? admin_one('SELECT * FROM faculty WHERE id = :id', ['id' => (int) $_GET['edit_faculty']]) : null;
if ($editUser === null && $editFaculty !== null) {
    $editUser = admin_one('SELECT * FROM users WHERE email = :email', ['email' => $editFaculty['email']]);
}
$editPersonFaculty = $editFaculty;
if ($editPersonFaculty === null && $editUser !== null) {
    $editPersonFaculty = admin_one('SELECT * FROM faculty WHERE email = :email', ['email' => $editUser['email']]);
}
$personDepartmentValue = trim((string) ($editUser['department'] ?? $editPersonFaculty['department'] ?? ''));
$personDepartmentMatched = $personDepartmentValue === '';
foreach ($departments as $departmentOption) {
    if (
        strcasecmp($personDepartmentValue, (string) $departmentOption['department_name']) === 0
        || strcasecmp($personDepartmentValue, (string) $departmentOption['department_code']) === 0
    ) {
        $personDepartmentMatched = true;
        break;
    }
}
$readUser = isset($_GET['read_user']) ? admin_one('SELECT * FROM users WHERE id = :id', ['id' => (int) $_GET['read_user']]) : null;
$readFaculty = isset($_GET['read_faculty']) ? admin_one('SELECT * FROM faculty WHERE id = :id', ['id' => (int) $_GET['read_faculty']]) : null;
if ($readUser === null && $readFaculty !== null) {
    $readUser = admin_one('SELECT * FROM users WHERE email = :email', ['email' => $readFaculty['email']]);
}
if ($readFaculty === null && $readUser !== null) {
    $readFaculty = admin_one('SELECT * FROM faculty WHERE email = :email', ['email' => $readUser['email']]);
}
$editEvaluation = isset($_GET['edit_evaluation']) ? admin_one('SELECT * FROM evaluations WHERE id = :id', ['id' => (int) $_GET['edit_evaluation']]) : null;
$userFormRoles = ROLES;
if (($editUser['role'] ?? '') !== 'admin_hr') {
    unset($userFormRoles['admin_hr']);
}

$displayUsers = array_values(array_filter($users, fn (array $personUser): bool => !admin_is_leadership_role((string) ($personUser['role'] ?? ''))));
$displayFaculty = array_values(array_filter($faculty, function (array $facultyRow) use ($users): bool {
    if (admin_is_leadership_position((string) ($facultyRow['position_title'] ?? ''))) {
        return false;
    }

    foreach ($users as $personUser) {
        if (
            strcasecmp((string) ($personUser['email'] ?? ''), (string) ($facultyRow['email'] ?? '')) === 0
            && admin_is_leadership_role((string) ($personUser['role'] ?? ''))
        ) {
            return false;
        }
    }

    return true;
}));

$activities = admin_all(
    'SELECT a.description, a.created_at, u.full_name
     FROM activity_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC
     LIMIT 8'
);
$peopleRows = [];
foreach ($displayUsers as $personUser) {
    $matchingFaculty = null;
    foreach ($displayFaculty as $facultyRow) {
        if (strcasecmp((string) $facultyRow['email'], (string) $personUser['email']) === 0) {
            $matchingFaculty = $facultyRow;
            break;
        }
    }
    $peopleRows[] = [
        'user' => $personUser,
        'faculty' => $matchingFaculty,
    ];
}
foreach ($displayFaculty as $facultyRow) {
    $hasAccount = false;
    foreach ($displayUsers as $personUser) {
        if (strcasecmp((string) $personUser['email'], (string) $facultyRow['email']) === 0) {
            $hasAccount = true;
            break;
        }
    }
    if (!$hasAccount) {
        $peopleRows[] = [
            'user' => null,
            'faculty' => $facultyRow,
        ];
    }
}
$visibleUsers = admin_filter_rows($displayUsers, $searchQuery, ['full_name', 'email', 'role', 'department', 'phone']);
$visibleFaculty = admin_filter_rows($displayFaculty, $searchQuery, ['full_name', 'email', 'department', 'program_code', 'position_title', 'phone', 'academic_qualifications']);
$visibleEvaluations = admin_filter_rows($evaluations, $searchQuery, ['faculty_name', 'title', 'evaluation_type', 'deadline', 'status', 'remarks']);
$adminEvaluationTotal = count($evaluations);
$adminEvaluationCompleted = count(array_filter($evaluations, fn (array $row): bool => ($row['status'] ?? '') === 'completed'));
$adminEvaluationPending = max(0, $adminEvaluationTotal - $adminEvaluationCompleted);
$adminEvaluationPercent = $adminEvaluationTotal > 0 ? round(($adminEvaluationCompleted / $adminEvaluationTotal) * 100) : 0;
$adminEvaluationPendingPercent = $adminEvaluationTotal > 0 ? round(($adminEvaluationPending / $adminEvaluationTotal) * 100) : 0;
$visibleAiInsights = admin_filter_rows($aiInsights, $searchQuery, ['faculty_name', 'department', 'program_code', 'weak_area', 'strength_area', 'analysis_summary']);
$visibleInterventions = admin_filter_rows($interventions, $searchQuery, ['faculty_name', 'department', 'program_code', 'weak_area', 'recommendation', 'action_type', 'status', 'target_date']);
$visiblePeerAssignments = admin_filter_rows($peerAssignments, $searchQuery, ['cycle_name', 'evaluator_name', 'evaluatee_name', 'assignment_type', 'status', 'department']);
$visibleLeadershipEvaluatees = [];
$visibleQuestionnaires = admin_filter_rows($questionnaires, $searchQuery, ['factor_name', 'question_text', 'question_type']);
$visibleDepartmentWeakAreas = admin_filter_rows($departmentWeakAreas, $searchQuery, ['department', 'weak_area']);
$visibleDepartmentDirectory = admin_filter_department_directory($departmentDirectory, $searchQuery);
$visiblePeopleRows = admin_filter_rows($peopleRows, $searchQuery);
$today = strtotime(date('Y-m-d'));
$nextWeek = strtotime('+7 days', $today);
$dueSoonEvaluations = count(array_filter($evaluations, function (array $row) use ($today, $nextWeek): bool {
    $deadline = strtotime((string) ($row['deadline'] ?? ''));

    return ($row['status'] ?? '') !== 'completed'
        && $deadline !== false
        && $deadline >= $today
        && $deadline <= $nextWeek;
}));
$departmentsWithoutDean = count(array_filter($departments, fn (array $row): bool => empty($row['dean_user_id'])));
$programsWithoutHead = count(array_filter($programs, fn (array $row): bool => empty($row['program_head_user_id'])));
$lowProgressFaculty = count(array_filter($faculty, fn (array $row): bool => (int) ($row['progress_percent'] ?? 0) < 50));
$actionCenterItems = [
    [
        'label' => 'Overdue evaluations',
        'count' => $stats['overdueEvaluations'],
        'detail' => 'Need immediate follow-up',
        'href' => BASE_URL . '/dashboards/admin_hr.php?section=evaluations',
        'cta' => 'Review',
        'tone' => 'danger',
        'initial' => 'O',
    ],
    [
        'label' => 'Due this week',
        'count' => $dueSoonEvaluations,
        'detail' => 'Evaluation deadlines approaching',
        'href' => BASE_URL . '/dashboards/admin_hr.php?section=evaluations',
        'cta' => 'Check',
        'tone' => 'warning',
        'initial' => 'D',
    ],
    [
        'label' => 'Pending peer assignments',
        'count' => $stats['pendingPeerAssignments'],
        'detail' => 'Awaiting completion',
        'href' => BASE_URL . '/dashboards/admin_hr.php?section=assignments',
        'cta' => 'Open',
        'tone' => 'info',
        'initial' => 'P',
    ],
    [
        'label' => 'Departments need dean',
        'count' => $departmentsWithoutDean,
        'detail' => 'Unassigned department leadership',
        'href' => BASE_URL . '/dashboards/admin_hr.php?section=dept_management',
        'cta' => 'Assign',
        'tone' => 'warning',
        'initial' => 'D',
    ],
    [
        'label' => 'Programs need head',
        'count' => $programsWithoutHead,
        'detail' => 'Program head not assigned',
        'href' => BASE_URL . '/dashboards/admin_hr.php?section=department',
        'cta' => 'Manage',
        'tone' => 'info',
        'initial' => 'H',
    ],
    [
        'label' => 'Faculty below 50%',
        'count' => $lowProgressFaculty,
        'detail' => 'Progress records need attention',
        'href' => BASE_URL . '/dashboards/admin_hr.php?section=people',
        'cta' => 'View',
        'tone' => 'danger',
        'initial' => 'F',
    ],
];
$actionCenterTotal = array_sum(array_column($actionCenterItems, 'count'));
$actionCenterReadyCount = count(array_filter($actionCenterItems, fn (array $item): bool => (int) $item['count'] === 0));

$adminReportTypes = [
    'evaluation_status' => [
        'title' => 'Evaluation Status Report',
        'description' => 'Shows pending, in-progress, completed, and overdue evaluations with assigned faculty and deadlines.',
        'best_for' => 'Daily HR monitoring',
        'badge' => (string) $stats['pendingEvaluations'] . ' pending',
        'category' => 'Operations',
        'icon' => 'activity',
        'progress' => $stats['evaluationCount'] > 0 ? round(($stats['pendingEvaluations'] / $stats['evaluationCount']) * 100) : 0,
    ],
    'department_summary' => [
        'title' => 'Department Summary Report',
        'description' => 'Groups faculty, completion totals, pending work, and average progress by department.',
        'best_for' => 'Dean and department review',
        'badge' => count($departmentDirectory) . ' departments',
        'category' => 'Department',
        'icon' => 'building',
        'progress' => min(100, count($departmentDirectory) * 18),
    ],
    'faculty_performance' => [
        'title' => 'Faculty Performance Report',
        'description' => 'Lists each faculty member with role, department, progress, completed evaluations, and average score.',
        'best_for' => 'Individual performance tracking',
        'badge' => (string) count($faculty) . ' faculty',
        'category' => 'Performance',
        'icon' => 'users',
        'progress' => $stats['completionRate'],
    ],
    'peer_assignments' => [
        'title' => 'Peer Assignment Report',
        'description' => 'Audits peer, dean, and program head evaluation assignments without exposing confidential comments.',
        'best_for' => 'Confidential assignment checking',
        'badge' => (string) $stats['peerAssignments'] . ' assignments',
        'category' => 'Peer Review',
        'icon' => 'network',
        'progress' => min(100, $stats['peerAssignments'] * 8),
    ],
    'ai_training' => [
        'title' => 'AI Insights and Training Report',
        'description' => 'Combines weak areas, strengths, recommended seminars, training plans, and intervention status.',
        'best_for' => 'Development planning',
        'badge' => (string) $stats['activeInterventions'] . ' active plans',
        'category' => 'AI Analytics',
        'icon' => 'spark',
        'progress' => min(100, max(12, $stats['activeInterventions'] * 16)),
    ],
    'complete_export' => [
        'title' => 'Complete Evaluation Export',
        'description' => 'Downloads the detailed evaluation list using the selected date, faculty, and type filters.',
        'best_for' => 'Records and backup',
        'badge' => (string) $stats['evaluationCount'] . ' records',
        'category' => 'Export',
        'icon' => 'download',
        'progress' => 100,
    ],
];

$nav = [
    'dashboard' => ['dashboard', 'Dashboard'],
    'people' => ['users', 'Add Users'],
    'directory' => ['book', 'Department Directory'],
    'dept_management' => ['building', 'Department Management'],
    'assignments' => ['assignments', 'Evaluation Assignment'],
    'evaluations' => ['evaluations', 'Evaluations'],
    'reports' => ['reports', 'Reports'],
    'settings' => ['settings', 'Settings'],
    'form_b_categories' => ['categories', 'PMAS Categories'],
    'form_b_questionnaire' => ['questionnaire', 'Questionnaire'],
    'evaluation_periods' => ['calendar', 'Evaluation Periods'],
    'ai_assistance' => ['ai', 'AI Assistance'],
    'computation_rules' => ['calculator', 'Computation Rules'],
    'form_a_categories' => ['categories', 'PMAS Form A Categories'],
    'form_a_questionnaire' => ['questionnaire', 'Form A Questionnaire'],
    'form_a_ai_assistance' => ['ai', 'Form A AI Assistance'],
    'form_a_periods' => ['calendar', 'Form A Periods'],
];
$pageTitle = $section === 'department'
    ? ($selectedDepartment['department']['department_code'] ?? 'Department')
    : $nav[$section][1];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin/HR Dashboard | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-9">
</head>
<body class="admin-body">
    <button class="sidebar-overlay" type="button" aria-label="Close menu"></button>
    <aside class="admin-sidebar" aria-label="Admin navigation">
        <div class="sidebar-brand">
            <span class="brand-icon">D</span>
            <span class="sidebar-brand-copy">
                <strong><?= e(APP_NAME) ?></strong>
                <small>Admin Dashboard</small>
            </span>
            <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar"></button>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($nav as $key => [$icon, $label]): ?>
                <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=<?= e($key) ?>">
                    <span class="menu-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
                    <span class="sidebar-item-label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <a class="sidebar-logout" href="<?= BASE_URL ?>/logout.php">
                <span class="menu-icon" data-icon="logout" aria-hidden="true"></span>
                <span class="sidebar-item-label">Logout</span>
            </a>
            <label class="dark-mode-switch">
                <span class="menu-icon" data-icon="moon" aria-hidden="true"></span>
                <span class="sidebar-item-label">Dark Mode</span>
                <input class="dark-mode-input" type="checkbox" aria-label="Toggle dark mode">
                <span class="toggle-track" aria-hidden="true"></span>
            </label>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <h1><?= e($pageTitle) ?></h1>
            <form class="admin-search" action="<?= BASE_URL ?>/dashboards/admin_hr.php" method="get">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <?php if ($section === 'department' && isset($_GET['department_id'])): ?>
                    <input type="hidden" name="department_id" value="<?= e((string) $_GET['department_id']) ?>">
                <?php endif; ?>
                <label for="admin-search">Search</label>
                <input id="admin-search" type="search" name="search" placeholder="Search" value="<?= e($searchQuery) ?>">
            </form>
            <div class="admin-actions" aria-label="Notifications and profile">
                <button class="notification-button" type="button" onclick="location.href='<?= BASE_URL ?>/dashboards/admin_hr.php?section=evaluations'" aria-label="Open pending evaluations">
                    <span class="notification-badge" id="pending-count"><?= e((string) $stats['pendingEvaluations']) ?></span>
                </button>
                <button class="notification-button warning" type="button" onclick="location.href='<?= BASE_URL ?>/dashboards/admin_hr.php?section=evaluations'" aria-label="Open overdue evaluations">
                    <span class="notification-badge" id="overdue-count"><?= e((string) $stats['overdueEvaluations']) ?></span>
                </button>
                <a class="profile-button" href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=settings" aria-label="Open profile settings"><?= admin_avatar_markup($user) ?></a>
            </div>
        </header>

        <section class="admin-content admin-module <?= $section === 'people' ? 'people-content' : '' ?> <?= $section === 'reports' ? 'reports-analytics-content' : '' ?>">
            <?php if ($flash !== ''): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?>
            <?php if ($flashError !== ''): ?><div class="notice error"><?= e($flashError) ?></div><?php endif; ?>

            <?php if ($section === 'dashboard'): ?>
                <div class="admin-home-hero module-wide">
                    <div>
                        <h2>Welcome back, <?= e(display_name($user['full_name'] ?? null, 'Admin')) ?></h2>
                        <p>Real-time admin dashboard. Track system performance, faculty evaluations, and institutional insights.</p>
                    </div>
                    <div class="hero-side">
                        <img class="hero-robot" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="" aria-hidden="true">
                        <div class="admin-home-update">
                            <span>Last updated</span>
                            <strong id="stat-updated"><?= e($stats['updatedAt']) ?></strong>
                        </div>
                    </div>
                </div>

                <section class="admin-home-metrics module-wide" aria-label="Main dashboard numbers">
                    <article>
                        <span>Total Users</span>
                        <strong id="stat-users"><?= e((string) $stats['totalUsers']) ?></strong>
                        <small><?= e((string) $stats['activeUsers']) ?> active</small>
                    </article>
                    <article>
                        <span>Faculty Records</span>
                        <strong id="stat-faculty"><?= e((string) $stats['facultyCount']) ?></strong>
                        <small>In system</small>
                    </article>
                    <article class="needs-attention">
                        <span>Pending Evaluations</span>
                        <strong id="stat-pending"><?= e((string) $stats['pendingEvaluations']) ?></strong>
                        <small><?= e((string) $stats['overdueEvaluations']) ?> overdue</small>
                    </article>
                    <article>
                        <span>Completion Rate</span>
                        <strong id="stat-rate"><?= e((string) $stats['completionRate']) ?>%</strong>
                        <small><?= e((string) $stats['completedEvaluations']) ?> completed</small>
                    </article>
                </section>

                <section class="admin-box admin-action-center module-wide" aria-labelledby="action-center-title">
                    <div class="action-center-head">
                        <div>
                            <p class="eyebrow">Priority Board</p>
                            <h2 id="action-center-title">Action Center</h2>
                            <p>Focus on the admin tasks that need attention before routine monitoring.</p>
                        </div>
                        <div class="action-center-summary <?= $actionCenterTotal > 0 ? 'has-alerts' : 'is-clear' ?>">
                            <strong><?= e((string) $actionCenterTotal) ?></strong>
                            <span><?= $actionCenterTotal > 0 ? 'items need attention' : 'all clear' ?></span>
                            <small><?= e((string) $actionCenterReadyCount) ?> checks clear</small>
                        </div>
                    </div>
                    <div class="action-center-grid">
                        <?php foreach ($actionCenterItems as $item): ?>
                            <a class="action-card <?= e($item['tone']) ?> <?= (int) $item['count'] === 0 ? 'is-done' : '' ?>" href="<?= e($item['href']) ?>">
                                <span class="action-card-icon" aria-hidden="true"><?= e($item['initial']) ?></span>
                                <span class="action-card-copy">
                                    <strong><?= e((string) $item['count']) ?></strong>
                                    <span><?= e($item['label']) ?></span>
                                    <small><?= e($item['detail']) ?></small>
                                </span>
                                <span class="action-card-cta"><?= (int) $item['count'] === 0 ? 'Clear' : e($item['cta']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="admin-box admin-quick-actions module-wide">
                    <div class="box-title"><h2>Quick Actions</h2><span>Common tasks</span></div>
                    <div class="quick-action-grid">
                        <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people"><span class="quick-action-icon" aria-hidden="true">👥</span><span class="quick-action-label">Manage Users</span></a>
                        <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=assignments"><span class="quick-action-icon" aria-hidden="true">📋</span><span class="quick-action-label">Create Assignments</span></a>
                        <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=evaluations"><span class="quick-action-icon" aria-hidden="true">📊</span><span class="quick-action-label">Evaluations</span></a>
                        <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=reports"><span class="quick-action-icon" aria-hidden="true">📈</span><span class="quick-action-label">Reports</span></a>
                    </div>
                </section>

                <section class="admin-box module-half admin-progress-card">
                    <div class="box-title"><h2>Evaluation Progress</h2><span>Overall</span></div>
                    <strong><?= e((string) $stats['completionRate']) ?>%</strong>
                    <div class="progress-bar"><span id="completion-bar" data-progress="<?= e((string) $stats['completionRate']) ?>"></span></div>
                    <p class="module-copy">Completed evaluations vs. total records in the system.</p>
                </section>

                <section class="admin-box module-half admin-attention-card">
                    <div class="box-title"><h2>Key Metrics</h2><span>Today</span></div>
                    <ul class="simple-status-list">
                        <li><strong id="stat-peer"><?= e((string) $stats['peerAssignments']) ?></strong><span>Peer assignments</span></li>
                        <li><strong id="stat-ai"><?= e((string) $stats['aiInsightCount']) ?></strong><span>AI insights generated</span></li>
                        <li><strong id="stat-interventions"><?= e((string) $stats['activeInterventions']) ?></strong><span>Development plans</span></li>
                    </ul>
                </section>

                <section class="admin-box module-half">
                    <div class="box-title"><h2>Recent Activity</h2><span>Assignments</span></div>
                    <ul class="activity-list friendly-list">
                        <?php foreach (array_slice($peerAssignments, 0, 4) as $assignment): ?>
                            <li><strong><?= e($assignment['evaluator_name']) ?></strong><span><?= e(admin_status_label($assignment['status'])) ?> → <?= e($assignment['evaluatee_name']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="admin-box module-half">
                    <div class="box-title"><h2>Development Plans</h2><span>In Progress</span></div>
                    <ul class="activity-list friendly-list">
                        <?php foreach (array_slice($interventions, 0, 4) as $plan): ?>
                            <li><strong><?= e($plan['program_code']) ?> - <?= e($plan['faculty_name']) ?></strong><span><?= e($plan['recommendation']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($section === 'users'): ?>
                <section class="admin-box module-form">
                    <div class="box-title"><h2><?= $editUser ? 'Update User' : 'Add User' ?></h2><span>RBAC</span></div>
                    <form method="post" class="admin-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_user">
                        <input type="hidden" name="id" value="<?= e((string) ($editUser['id'] ?? 0)) ?>">
                        <div class="profile-upload-preview">
                            <?= admin_avatar_markup($editUser ?? [], 'profile-preview') ?>
                        </div>
                        <label>Full Name<input name="full_name" required value="<?= e($editUser['full_name'] ?? '') ?>"></label>
                        <label>Email<input type="email" name="email" required value="<?= e($editUser['email'] ?? '') ?>"></label>
                        <label>Role<select name="role"><?php foreach ($userFormRoles as $key => $label): ?><option value="<?= e($key) ?>" <?= ($editUser['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label>Phone<input name="phone" value="<?= e($editUser['phone'] ?? '') ?>"></label>
                        <label>Department<input name="department" value="<?= e($editUser['department'] ?? '') ?>"></label>
                        <label data-user-role-program-field>Program
                            <select name="program_code">
                                <option value="">Optional for Dean</option>
                                <?php foreach ($programs as $programOption): ?>
                                    <option
                                        value="<?= e($programOption['program_code']) ?>"
                                        data-department="<?= e($programOption['department_name']) ?>"
                                        data-department-code="<?= e($programOption['department_code']) ?>"
                                        <?= strcasecmp((string) ($editUser['program'] ?? ''), (string) $programOption['program_code']) === 0 ? 'selected' : '' ?>
                                    >
                                        <?= e($programOption['program_code'] . ' - ' . $programOption['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                                                <label>Profile Picture<input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label>Password<input type="password" name="password" placeholder="<?= $editUser ? 'Leave blank to keep current password' : 'Required for new user' ?>"></label>
                        <label class="check-row"><input type="checkbox" name="is_active" <?= (int) ($editUser['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Active account</label>
                        <button type="submit"><?= $editUser ? 'Update User' : 'Add User' ?></button>
                    </form>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>User Profiles</h2><span><?= count($visibleUsers) ?> records</span></div>
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleUsers as $row): ?>
                            <tr>
                                <td data-label="Name">
                                    <div class="profile-cell">
                                        <?= admin_avatar_markup($row, 'table-avatar') ?>
                                        <span><?= e($row['full_name']) ?></span>
                                    </div>
                                </td>
                                <td data-label="Email"><?= e($row['email']) ?></td>
                                <td data-label="Role"><?= e(admin_role_label($row['role'])) ?></td>
                                <td data-label="Status"><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                                <td data-label="Actions" class="table-actions">
                                    <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people&edit_user=<?= e((string) $row['id']) ?>">Edit</a>
                                        <form method="post" onsubmit="return confirm('Archive this user?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                            <button type="submit">Archive</button>
                                        </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visibleUsers === []): ?>
                            <tr><td colspan="5">No users match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <?php if ($section === 'people'): ?>
                <section class="admin-box module-wide people-controls">
                    <div class="box-title">
                        <h2>People Section</h2>
                        <span>Choose what you want to manage</span>
                    </div>
                    <div class="inline-action people-action-row">
                        <button type="button" data-open-panel="user-form-panel">Add User</button>
                        <button type="button" data-open-panel="department-form-panel">Add Department</button>
                        <button type="button" data-open-panel="user-search-panel">Search User</button>
                    </div>
                </section>

                <?php if ($readUser !== null || $readFaculty !== null): ?>
                    <section id="user-read-panel" class="admin-box module-table">
                        <div class="box-title">
                            <h2>User Details</h2>
                            <span>Read-only profile</span>
                        </div>
                        <table class="data-table">
                            <tbody>
                                <tr><th>Name</th><td><?= e($readUser['full_name'] ?? $readFaculty['full_name'] ?? '') ?></td></tr>
                                <tr><th>Email</th><td><?= e($readUser['email'] ?? $readFaculty['email'] ?? '') ?></td></tr>
                                <tr><th>Role / Position</th><td><?= e($readUser ? admin_role_label($readUser['role']) : ($readFaculty['position_title'] ?? 'Faculty')) ?></td></tr>
                                <?php if ($readFaculty !== null): ?>
                                    <tr><th>Program</th><td><?= e($readFaculty['program_code'] ?: 'Unassigned Program') ?></td></tr>
                                <?php endif; ?>
                                <tr><th>Phone</th><td><?= e($readUser['phone'] ?? $readFaculty['phone'] ?? '') ?></td></tr>
                                <tr><th>Status</th><td><?= $readUser === null ? 'Faculty record only' : ((int) $readUser['is_active'] === 1 ? 'Active' : 'Archived') ?></td></tr>
                                <?php if ($readFaculty !== null): ?>
                                    <tr><th>Qualifications</th><td><?= e($readFaculty['academic_qualifications'] ?? '') ?></td></tr>
                                    <tr><th>Notes</th><td><?= e($readFaculty['performance_notes'] ?? '') ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <section id="user-form-panel" class="admin-box module-form people-form-card" <?= $editUser || $editPersonFaculty ? '' : 'hidden' ?>>
                    <div class="box-title">
                        <h2><?= $editUser || $editPersonFaculty ? 'Update User' : 'Add User' ?></h2>
                        <span>Account and faculty profile in one form</span>
                    </div>
                    <form method="post" class="admin-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_person">
                        <input type="hidden" name="id" value="<?= e((string) ($editUser['id'] ?? 0)) ?>">
                        <input type="hidden" name="faculty_id" value="<?= e((string) ($editPersonFaculty['id'] ?? 0)) ?>">

                        <div class="profile-upload-preview">
                            <?= admin_avatar_markup($editUser ?? [], 'profile-preview') ?>
                            <small>Profile picture</small>
                        </div>

                        <label>Full Name<input name="full_name" required value="<?= e($editUser['full_name'] ?? $editPersonFaculty['full_name'] ?? '') ?>"></label>
                        <label>Email<input type="email" name="email" required value="<?= e($editUser['email'] ?? $editPersonFaculty['email'] ?? '') ?>"></label>
                        <label>Role
                            <select name="role">
                                <?php foreach ($userFormRoles as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= ($editUser['role'] ?? 'teacher') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Phone<input name="phone" value="<?= e($editUser['phone'] ?? $editPersonFaculty['phone'] ?? '') ?>"></label>
                        <label>Department
                            <select name="department" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $departmentOption): ?>
                                    <?php
                                        $departmentName = (string) $departmentOption['department_name'];
                                        $departmentCode = (string) $departmentOption['department_code'];
                                        $isSelectedDepartment = strcasecmp($personDepartmentValue, $departmentName) === 0
                                            || strcasecmp($personDepartmentValue, $departmentCode) === 0;
                                    ?>
                                    <option value="<?= e($departmentName) ?>" <?= $isSelectedDepartment ? 'selected' : '' ?>>
                                        <?= e($departmentCode . ' - ' . $departmentName) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$personDepartmentMatched): ?>
                                    <option value="<?= e($personDepartmentValue) ?>" selected><?= e($personDepartmentValue) ?> (not in department list)</option>
                                <?php endif; ?>
                            </select>
                        </label>
                        <label data-person-program-field>Program
                            <select name="program_code" data-program-select>
                                <option value="">Unassigned Program</option>
                                <?php foreach ($programs as $programOption): ?>
                                    <option
                                        value="<?= e($programOption['program_code']) ?>"
                                        data-department="<?= e($programOption['department_name']) ?>"
                                        data-department-code="<?= e($programOption['department_code']) ?>"
                                        <?= strcasecmp((string) ($editPersonFaculty['program_code'] ?? $editUser['program'] ?? ''), (string) $programOption['program_code']) === 0 ? 'selected' : '' ?>
                                    >
                                        <?= e($programOption['program_code'] . ' - ' . $programOption['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Position / Instructor Type<input name="position_title" placeholder="Instructor I, Program Head, Dean" value="<?= e($editPersonFaculty['position_title'] ?? '') ?>"></label>
                        <label>Profile Picture<input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label>Password<input type="password" name="password" placeholder="<?= $editUser ? 'Leave blank to keep current password' : 'Required for new account' ?>"></label>
                        <label class="full-field">Academic Qualifications<textarea name="academic_qualifications" placeholder="Example: MIT, BS Information Technology"><?= e($editPersonFaculty['academic_qualifications'] ?? '') ?></textarea></label>
                        <label class="full-field">Notes<textarea name="performance_notes" placeholder="Optional notes for evaluation tracking"><?= e($editPersonFaculty['performance_notes'] ?? '') ?></textarea></label>
                        <button type="submit"><?= $editUser || $editPersonFaculty ? 'Update User' : 'Add User' ?></button>
                    </form>
                </section>

                <section id="department-form-panel" class="admin-box module-form" hidden>
                    <div class="box-title">
                        <h2>Add Department</h2>
                        <span>Available for user profiles</span>
                    </div>
                    <form method="post" class="admin-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_department">
                        <input type="hidden" name="return_section" value="people">
                        <label>Department Code<input name="department_code" placeholder="CITE" required></label>
                        <label>Department Name<input name="department_name" placeholder="College of Information Technology Engineering" required></label>
                        <label class="full-field">Department Logo<input type="file" name="department_logo" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label>Dean
                            <select name="dean_user_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $departmentUser): ?>
                                    <?php if ($departmentUser['role'] === 'dean'): ?>
                                        <option value="<?= e((string) $departmentUser['id']) ?>"><?= e($departmentUser['full_name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit">Add Department</button>
                    </form>
                </section>

                <section id="user-search-panel" class="admin-box module-table" <?= $searchQuery !== '' || $readUser !== null || $readFaculty !== null ? '' : 'hidden' ?>>
                    <div class="box-title">
                        <h2>Search User</h2>
                        <span>Read, update, or archive records</span>
                    </div>
                    <form class="admin-form" method="get" action="<?= BASE_URL ?>/dashboards/admin_hr.php">
                        <input type="hidden" name="section" value="people">
                        <label class="full-field">Search by name, email, role, or department
                            <input type="search" name="search" value="<?= e($searchQuery) ?>" placeholder="Example: Maria, Dean, CITE">
                        </label>
                        <button type="submit">Search User</button>
                    </form>
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Role / Position</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($visiblePeopleRows as $personRow): ?>
                            <?php
                                $rowUser = $personRow['user'];
                                $rowFaculty = $personRow['faculty'];
                                $displayName = $rowUser['full_name'] ?? $rowFaculty['full_name'] ?? '';
                                $displayEmail = $rowUser['email'] ?? $rowFaculty['email'] ?? '';
                                $displayRole = $rowUser ? admin_role_label($rowUser['role']) : ($rowFaculty['position_title'] ?? 'Faculty');
                                $readUrl = $rowUser
                                    ? BASE_URL . '/dashboards/admin_hr.php?section=people&read_user=' . e((string) $rowUser['id']) . '&search=' . e(urlencode($searchQuery))
                                    : BASE_URL . '/dashboards/admin_hr.php?section=people&read_faculty=' . e((string) $rowFaculty['id']) . '&search=' . e(urlencode($searchQuery));
                                $updateUrl = $rowUser
                                    ? BASE_URL . '/dashboards/admin_hr.php?section=people&edit_user=' . e((string) $rowUser['id'])
                                    : BASE_URL . '/dashboards/admin_hr.php?section=people&edit_faculty=' . e((string) $rowFaculty['id']);
                            ?>
                            <tr>
                                <td data-label="Name"><?= e($displayName) ?></td>
                                <td data-label="Email"><?= e($displayEmail) ?></td>
                                <td data-label="Role / Position"><?= e($displayRole) ?></td>
                                <td data-label="Actions" class="table-actions">
                                    <a href="<?= $readUrl ?>">Read</a>
                                    <a href="<?= $updateUrl ?>">Update</a>
                                    <?php if ($rowUser): ?>
                                        <form method="post" onsubmit="return confirm('Archive this user?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= e((string) $rowUser['id']) ?>">
                                            <button type="submit">Archive</button>
                                        </form>
                                    <?php elseif ($rowFaculty): ?>
                                        <form method="post" onsubmit="return confirm('Archive this faculty record?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_faculty">
                                            <input type="hidden" name="id" value="<?= e((string) $rowFaculty['id']) ?>">
                                            <button type="submit">Archive</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visiblePeopleRows === []): ?>
                            <tr><td colspan="5">No users match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="admin-box module-table people-table-card" data-people-default>
                    <div class="box-title"><h2>Department and Program Directory</h2><span><?= count($visibleDepartmentDirectory) ?> departments · <?= count($visiblePeopleRows) ?> user records</span></div>
                    <div class="department-directory">
                        <?php foreach ($visibleDepartmentDirectory as $group): ?>
                            <?php
                                $department = $group['department'];
                                $activeUsers = array_values(array_filter($group['users'], fn (array $row): bool => (int) $row['is_active'] === 1));
                                $previewUsers = array_slice($activeUsers, 0, 3);
                                $departmentLogoUrl = admin_department_logo_url($department);
                            ?>
                            <article class="department-card">
                                <div class="department-card-cover">
                                    <img class="department-logo" src="<?= e($departmentLogoUrl) ?>" alt="<?= e($department['department_name']) ?> logo">
                                    <span><?= e($department['department_code']) ?></span>
                                </div>

                                <div class="department-card-body">
                                    <h3><?= e($department['department_name']) ?></h3>
                                    <p class="department-meta">
                                        <span><?= e($department['department_code']) ?></span>
                                        <span><?= count($activeUsers) ?> user(s)</span>
                                    </p>
                                    <p class="department-summary">
                                        Dean: <?= e($department['dean_name'] ?? 'Unassigned') ?>. <?= count($group['programs']) ?> program(s) and <?= count($group['faculty']) ?> faculty record(s) are listed under this department.
                                    </p>

                                    <div class="department-people-preview">
                                        <?php foreach ($previewUsers as $departmentUser): ?>
                                            <span><?= e($departmentUser['full_name']) ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($previewUsers === []): ?>
                                            <span>No active users yet</span>
                                        <?php endif; ?>
                                    </div>

                                    <span class="department-badge"><?= (int) $department['id'] > 0 ? 'Active Department' : 'Needs Department Setup' ?></span>

                                    <div class="department-card-footer">
                                        <small><?= count($group['programs']) ?> program(s)</small>
                                        <?php if ((int) $department['id'] > 0): ?>
                                            <a class="department-detail-link" href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=department&department_id=<?= e((string) $department['id']) ?>">View Details</a>
                                        <?php else: ?>
                                            <span class="department-badge">Create department to assign</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($visibleDepartmentDirectory === []): ?>
                            <p class="module-copy">No departments, users, or faculty records match your search.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($section === 'faculty'): ?>
                <section class="admin-box module-form">
                    <div class="box-title"><h2>Departments & Programs</h2><span>Organization</span></div>
                    <form method="post" class="admin-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_department">
                        <input type="hidden" name="return_section" value="people">
                        <label>Department Code<input name="department_code" placeholder="CITE" required></label>
                        <label>Department Name<input name="department_name" placeholder="College of Information Technology Engineering" required></label>
                        <label class="full-field">Department Logo<input type="file" name="department_logo" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label>Dean<select name="dean_user_id"><option value="">Unassigned</option><?php foreach ($users as $u): ?><?php if ($u['role'] === 'dean'): ?><option value="<?= e((string) $u['id']) ?>"><?= e($u['full_name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                        <button type="submit">Save Department</button>
                    </form>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_program">
                        <input type="hidden" name="return_section" value="people">
                        <label>Department<select name="department_id" required><?php foreach ($departments as $d): ?><option value="<?= e((string) $d['id']) ?>"><?= e($d['department_code']) ?></option><?php endforeach; ?></select></label>
                        <label>Program Code<input name="program_code" placeholder="BSIT" required></label>
                        <label>Program Name<input name="program_name" placeholder="Bachelor of Science in Information Technology" required></label>
                        <label>Program Head<select name="program_head_user_id"><option value="">Unassigned</option><?php foreach ($users as $u): ?><?php if ($u['role'] === 'program_head'): ?><option value="<?= e((string) $u['id']) ?>"><?= e($u['full_name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                        <button type="submit">Save Program</button>
                    </form>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Department and Program Directory</h2><span><?= count($visibleDepartmentDirectory) ?> departments</span></div>
                    <div class="department-directory">
                        <?php foreach ($visibleDepartmentDirectory as $group): ?>
                            <?php
                                $department = $group['department'];
                                $departmentLogoUrl = admin_department_logo_url($department);
                            ?>
                            <article class="department-card">
                                <div class="department-card-cover">
                                    <img class="department-logo" src="<?= e($departmentLogoUrl) ?>" alt="<?= e($department['department_name']) ?> logo">
                                    <span><?= e($department['department_code']) ?></span>
                                </div>

                                <div class="department-card-body">
                                    <h3><?= e($department['department_name']) ?></h3>

                                    <p class="department-meta">
                                        <span><?= e($department['department_code']) ?></span>
                                        <span><?= count($group['users']) ?> user(s)</span>
                                    </p>

                                    <p class="department-summary">
                                        Dean: <?= e($department['dean_name'] ?? 'Unassigned') ?>. <?= count($group['programs']) ?> program(s) and <?= count($group['faculty']) ?> faculty record(s) are listed under this department.
                                    </p>

                                    <span class="department-badge"><?= (int) $department['id'] > 0 ? 'Active Department' : 'Needs Department Setup' ?></span>

                                    <div class="department-card-footer">
                                        <small><?= count($group['programs']) ?> program(s)</small>
                                        <?php if ((int) $department['id'] > 0): ?>
                                            <a class="department-detail-link" href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=department&department_id=<?= e((string) $department['id']) ?>">View Details</a>
                                        <?php else: ?>
                                            <span class="department-badge">Create department to assign</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($visibleDepartmentDirectory === []): ?>
                            <p class="module-copy">No departments match your search.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="admin-box module-form">
                    <div class="box-title"><h2><?= $editFaculty ? 'Update Faculty' : 'Add Faculty' ?></h2><span>Records</span></div>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_faculty">
                        <input type="hidden" name="id" value="<?= e((string) ($editFaculty['id'] ?? 0)) ?>">
                        <label>Full Name<input name="full_name" required value="<?= e($editFaculty['full_name'] ?? '') ?>"></label>
                        <label>Email<input type="email" name="email" required value="<?= e($editFaculty['email'] ?? '') ?>"></label>
                        <label>Phone<input name="phone" value="<?= e($editFaculty['phone'] ?? '') ?>"></label>
                                                <label>Program
                            <select name="program_code">
                                <option value="">Unassigned Program</option>
                                <?php foreach ($programs as $programOption): ?>
                                    <option value="<?= e($programOption['program_code']) ?>" <?= strcasecmp((string) ($editFaculty['program_code'] ?? ''), (string) $programOption['program_code']) === 0 ? 'selected' : '' ?>>
                                        <?= e($programOption['program_code'] . ' - ' . $programOption['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Position<input name="position_title" required value="<?= e($editFaculty['position_title'] ?? '') ?>"></label>
                        <label>Progress %<input type="number" min="0" max="100" name="progress_percent" value="<?= e((string) ($editFaculty['progress_percent'] ?? 0)) ?>"></label>
                        <label class="full-field">Academic Qualifications<textarea name="academic_qualifications"><?= e($editFaculty['academic_qualifications'] ?? '') ?></textarea></label>
                        <label class="full-field">Performance Notes<textarea name="performance_notes"><?= e($editFaculty['performance_notes'] ?? '') ?></textarea></label>
                        <button type="submit"><?= $editFaculty ? 'Update Faculty' : 'Add Faculty' ?></button>
                    </form>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Faculty Records</h2><span>Progress tracking</span></div>
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Program</th><th>Position</th><th>Progress</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleFaculty as $row): ?>
                            <tr>
                                <td data-label="Name"><?= e($row['full_name']) ?></td>
                                <td data-label="Program"><?= e($row['program_code'] ?: 'Unassigned Program') ?></td>
                                <td data-label="Position"><?= e($row['position_title']) ?></td>
                                <td data-label="Progress"><div class="mini-progress"><span data-progress="<?= e((string) $row['progress_percent']) ?>"></span></div><?= e((string) $row['progress_percent']) ?>%</td>
                                <td data-label="Actions" class="table-actions">
                                    <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people&edit_faculty=<?= e((string) $row['id']) ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Archive this faculty record?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_faculty">
                                        <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                        <button type="submit">Archive</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visibleFaculty === []): ?>
                            <tr><td colspan="5">No faculty records match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <?php if ($section === 'department'): ?>
                <?php if ($selectedDepartment === null): ?>
                    <section class="admin-box module-wide">
                        <div class="box-title"><h2>Department Not Found</h2><span>Directory</span></div>
                        <p class="module-copy">The selected department could not be found.</p>
                        <div class="inline-action"><a class="department-detail-link compact-link" href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people">Back to Directory</a></div>
                    </section>
                <?php else: ?>
                    <?php $department = $selectedDepartment['department']; ?>
                    <section class="admin-box module-wide department-detail-hero">
                        <div class="department-detail-copy">
                            <a class="back-link" href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people">Back to Department Directory</a>
                            <img class="department-detail-logo" src="<?= e(admin_department_logo_url($department)) ?>" alt="<?= e($department['department_name']) ?> logo">
                            <span><?= e($department['department_code']) ?></span>
                            <h2><?= e($department['department_name']) ?></h2>
                            <p>Review the Dean, Program Heads, system users, and faculty records assigned to this department.</p>
                        </div>
                        <div class="department-detail-stats">
                            <article><strong><?= count($selectedDepartment['programs']) ?></strong><span>Programs</span></article>
                            <article><strong><?= count($selectedDepartment['users']) ?></strong><span>Users</span></article>
                            <article><strong><?= count($selectedDepartment['faculty']) ?></strong><span>Faculty</span></article>
                        </div>
                    </section>

                    <section class="admin-box module-form">
                        <div class="box-title"><h2>Department Profile</h2><span>Logo and details</span></div>
                        <form method="post" class="admin-form" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_department">
                            <input type="hidden" name="return_section" value="department">
                            <label>Department Code<input name="department_code" value="<?= e($department['department_code']) ?>" required></label>
                            <label>Department Name<input name="department_name" value="<?= e($department['department_name']) ?>" required></label>
                            <label>Dean
                                <select name="dean_user_id">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $departmentUser): ?>
                                        <?php if ($departmentUser['role'] === 'dean'): ?>
                                            <option value="<?= e((string) $departmentUser['id']) ?>" <?= (int) ($department['dean_user_id'] ?? 0) === (int) $departmentUser['id'] ? 'selected' : '' ?>><?= e($departmentUser['full_name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="full-field">Replace Department Logo<input type="file" name="department_logo" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                            <button type="submit">Update Department</button>
                        </form>
                    </section>

                    <section class="admin-box module-half">
                        <div class="box-title"><h2>Dean</h2><span>Department Head</span></div>
                        <div class="directory-detail-list">
                            <article>
                                <strong><?= e($department['dean_name'] ?? 'Unassigned') ?></strong>
                                <small><?= e($department['dean_email'] ?? '') ?></small>
                            </article>
                        </div>
                    </section>

                    <section class="admin-box module-half">
                        <div class="box-title"><h2>Programs</h2><span>Program Heads</span></div>
                        <div class="directory-detail-list">
                            <?php if ($selectedDepartment['programs'] === []): ?>
                                <article><strong>No programs listed</strong><small>Add a program from the Faculty page.</small></article>
                            <?php else: ?>
                                <?php foreach ($selectedDepartment['programs'] as $program): ?>
                                    <article>
                                        <span><?= e($program['program_code']) ?></span>
                                        <strong><?= e($program['program_name']) ?></strong>
                                        <small>Head: <?= e($program['program_head_name'] ?? 'Unassigned') ?> <?= ($program['program_head_email'] ?? '') !== '' ? '(' . e($program['program_head_email']) . ')' : '' ?></small>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="admin-box module-table">
                        <div class="box-title"><h2>Users Under This Department</h2><span><?= count($selectedDepartment['users']) ?> user(s)</span></div>
                        <div class="department-user-cards">
                            <?php foreach ($selectedDepartment['users'] as $departmentUser): ?>
                                <article class="department-user-card">
                                    <div class="department-user-cover">
                                        <?= admin_avatar_markup($departmentUser, 'department-user-avatar') ?>
                                    </div>
                                    <div class="department-user-body">
                                        <h3><?= e($departmentUser['full_name']) ?></h3>
                                        <p class="user-role"><?= e(admin_role_label($departmentUser['role'])) ?></p>
                                        <p><?= e($departmentUser['email']) ?></p>
                                        <p><?= e($departmentUser['department'] ?: $department['department_code']) ?></p>
                                        <div class="department-user-actions">
                                            <span class="status <?= (int) $departmentUser['is_active'] === 1 ? 'completed' : 'hold' ?>"><?= (int) $departmentUser['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                            <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people&edit_user=<?= e((string) $departmentUser['id']) ?>">Edit</a>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            <?php if ($selectedDepartment['users'] === []): ?>
                                <article class="department-user-card empty-card">
                                    <div class="department-user-body">
                                        <h3>No users listed</h3>
                                        <p>Add or update users from the Add Users page and assign them to this department.</p>
                                    </div>
                                </article>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="admin-box module-table">
                        <div class="box-title"><h2>Faculty Records</h2><span><?= count($selectedDepartment['faculty']) ?> record(s)</span></div>
                        <table class="data-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Program</th><th>Position</th><th>Progress</th></tr></thead>
                            <tbody>
                            <?php foreach ($selectedDepartment['faculty'] as $facultyMember): ?>
                                <tr>
                                    <td data-label="Name"><?= e($facultyMember['full_name']) ?></td>
                                    <td data-label="Email"><?= e($facultyMember['email']) ?></td>
                                    <td data-label="Program"><?= e($facultyMember['program_code'] ?: 'Unassigned Program') ?></td>
                                    <td data-label="Position"><?= e($facultyMember['position_title']) ?></td>
                                    <td data-label="Progress"><?= e((string) $facultyMember['progress_percent']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($selectedDepartment['faculty'] === []): ?>
                                <tr><td colspan="5">No faculty records listed for this department.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'assignments'): ?>
                <section class="admin-box module-wide">
                    <div class="box-title">
                        <h2>Admin Assignment Monitor</h2>
                        <button type="button" class="compact-link" data-toggle-assignment-setup aria-expanded="false">Open Evaluation Assignment Setup</button>
                    </div>
                    <p class="module-copy">Manage evaluation assignment tools, peer randomization, leadership reviews, and evaluator assignment tracking from this page.</p>
                </section>

                <div id="evaluation-assignment-setup" class="assignment-setup-panel" hidden>
                <section class="admin-box module-form">
                    <div class="box-title"><h2>Appraisal Periods</h2><span>Evaluation cycle</span></div>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_period">
                        <label>Period Name<input name="period_name" placeholder="2026 Midyear Appraisal" required></label>
                        <label>Start Date<input type="date" name="date_start" required></label>
                        <label>End Date<input type="date" name="date_end" required></label>
                        <label>Status<select name="status"><option value="draft">Draft</option><option value="open">Open</option><option value="closed">Closed</option></select></label>
                        <button type="submit">Save Period</button>
                    </form>
                </section>

                <section class="admin-box module-form">
                    <div class="box-title"><h2>Performance Factors & Questionnaires</h2><span>Evaluation form setup</span></div>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_factor">
                        <label>Factor Name<input name="factor_name" placeholder="Job Commitment" required></label>
                        <label>Weight %<input type="number" step="0.01" min="0" max="100" name="weight_percent" required></label>
                        <label class="full-field">Description<textarea name="description"></textarea></label>
                        <button type="submit">Save Factor</button>
                    </form>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_question">
                        <label>Factor<select name="factor_id"><?php foreach ($factors as $factor): ?><option value="<?= e((string) $factor['id']) ?>"><?= e($factor['factor_name']) ?> (<?= e((string) $factor['weight_percent']) ?>%)</option><?php endforeach; ?></select></label>
                        <label>Question Type<select name="question_type"><option value="rating">Rating</option><option value="comment">Comment</option></select></label>
                        <label class="full-field">Question<textarea name="question_text" required></textarea></label>
                        <button type="submit">Add Question</button>
                    </form>
                    <form method="post" class="admin-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_questionnaire">
                        <label class="full-field">Upload Questionnaire CSV<input type="file" name="questionnaire_csv" accept=".csv,text/csv" required></label>
                        <p class="module-copy">CSV headers supported: factor_name, question_text, question_type, weight_percent, description.</p>
                        <button type="submit">Upload Questionnaire</button>
                    </form>
                </section>

                <section class="admin-box module-form">
                    <div class="box-title"><h2>Evaluation Rules</h2><span>Assignment setup</span></div>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_rule">
                        <label>Rule Name<input name="rule_name" placeholder="Teacher Peer Evaluation" required></label>
                        <label>Evaluator Role<select name="evaluator_role"><option value="teacher">Teacher</option><option value="program_head">Program Head</option><option value="dean">Dean</option></select></label>
                        <label>Evaluatee Role<select name="evaluatee_role"><option value="teacher">Teacher</option><option value="program_head">Program Head</option><option value="dean">Dean</option></select></label>
                        <label>Assignment Type<select name="assignment_type"><option value="peer">Peer</option><option value="program_head">Program Head</option><option value="dean">Dean</option><option value="self">Self</option></select></label>
                        <label>Peer Count<input type="number" min="1" name="peer_count" value="1"></label>
                        <label class="check-row"><input type="checkbox" name="is_confidential" checked> Keep evaluator identity confidential</label>
                        <button type="submit">Save Rule</button>
                    </form>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Current Evaluation Assignment Setup</h2><span><?= count($rules) ?> rule(s)</span></div>
                    <div class="stat-grid compact setup-summary-grid">
                        <?php foreach ($periods as $period): ?>
                            <article>
                                <span><?= e($period['status']) ?></span>
                                <strong><?= e($period['period_name']) ?></strong>
                                <small><?= e($period['date_start']) ?> to <?= e($period['date_end']) ?></small>
                            </article>
                        <?php endforeach; ?>
                        <?php foreach ($factors as $factor): ?>
                            <article>
                                <span><?= e((string) $factor['weight_percent']) ?>%</span>
                                <strong><?= e($factor['factor_name']) ?></strong>
                                <small><?= e($factor['description'] ?? '') ?></small>
                            </article>
                        <?php endforeach; ?>
                        <?php foreach ($rules as $rule): ?>
                            <article>
                                <span><?= e(admin_status_label($rule['assignment_type'])) ?></span>
                                <strong><?= e($rule['rule_name']) ?></strong>
                                <small><?= (int) $rule['is_confidential'] === 1 ? 'Confidential' : 'Visible' ?> · <?= e(admin_role_label($rule['evaluator_role'])) ?> to <?= e(admin_role_label($rule['evaluatee_role'])) ?></small>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($rules === []): ?>
                            <p class="module-copy">No evaluation assignment rules configured yet.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Questionnaire Bank</h2><span><?= count($visibleQuestionnaires) ?> items</span></div>
                    <table class="data-table">
                        <thead><tr><th>Factor</th><th>Question</th><th>Type</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleQuestionnaires as $q): ?>
                            <tr>
                                <td data-label="Factor"><?= e($q['factor_name']) ?></td>
                                <td data-label="Question"><?= e($q['question_text']) ?></td>
                                <td data-label="Type"><?= e($q['question_type']) ?></td>
                                <td data-label="Status"><?= (int) $q['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visibleQuestionnaires === []): ?>
                            <tr><td colspan="4">No questionnaire items match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
                </div>

                <section class="admin-box module-form">
                    <div class="box-title"><h2>Randomize Peer Evaluators</h2><span>Confidential</span></div>
                    <p class="module-copy"><?= e(APP_NAME) ?> assigns peer evaluators automatically. Evaluator identities should only be visible to Admin/HR to protect confidentiality.</p>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="randomize_peers">
                        <label>Cycle Name<input name="cycle_name" value="<?= e($periods[0]['period_name'] ?? 'Current Appraisal Cycle') ?>" required></label>
                        <button type="submit">Randomize Peer Evaluators</button>
                    </form>
                </section>

                <section class="admin-box module-form">
                    <div class="box-title"><h2>Assign Dean and Program Head Reviews</h2><span>Teacher evaluation</span></div>
                    <p class="module-copy">Creates confidential teacher evaluation tasks for Deans and Program Heads whose user accounts are linked to faculty records.</p>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="assign_leadership_evaluations">
                        <label>Cycle Name<input name="cycle_name" value="<?= e($periods[0]['period_name'] ?? 'Current Appraisal Cycle') ?>" required></label>
                        <button type="submit">Assign Leadership Reviews</button>
                    </form>
                </section>

                <section class="admin-box module-form">
                    <div class="box-title"><h2>Peer-to-Peer Evaluation Assignment</h2><span>Group-based peer matching</span></div>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="generate_peer_to_peer">
                        <label>Cycle Name<input name="cycle_name" value="<?= e($periods[0]['period_name'] ?? 'Current Appraisal Cycle') ?>" required></label>
                        <button type="submit">Generate Peer Evaluation Assignment</button>
                    </form>
                </section>

                <?php if ($visibleLeadershipEvaluatees !== []): ?>
                    <section class="admin-box module-table">
                        <div class="box-title"><h2>Peer-to-Peer Assignment Summary</h2><span>Eligibility check</span></div>
                        <table class="data-table">
                            <thead><tr><th>Group</th><th>Eligible Members</th></tr></thead>
                            <tbody>
                            <?php
                                $deans = admin_all("SELECT id FROM users WHERE role = 'dean' AND is_active = 1");
                                $programHeads = admin_all("SELECT COALESCE(NULLIF(u.department, ''), 'Unassigned') AS dept FROM users u WHERE u.role = 'program_head' AND u.is_active = 1 GROUP BY dept");
                                $facultyGroups = admin_all("SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS prog FROM faculty f JOIN users u ON (u.id = f.user_id OR u.email = f.email) WHERE u.role = 'teacher' AND u.is_active = 1 AND f.is_archived = 0 GROUP BY prog");
                            ?>
                                <tr><td data-label="Group">Deans</td><td data-label="Eligible Members"><?= count($deans) ?> members</td></tr>
                                <tr><td data-label="Group">Program Heads</td><td data-label="Eligible Members"><?= count($programHeads) ?> departments</td></tr>
                                <tr><td data-label="Group">Faculty</td><td data-label="Eligible Members"><?= count($facultyGroups) ?> programs</td></tr>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Peer Assignment Monitor</h2><span><?= count($peerAssignmentsFull) ?> total</span></div>
                    <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Evaluator</th><th>Role</th><th>Evaluatee</th><th>Position</th><th>Department</th><th>Program</th><th>Type</th><th>Status</th><th>Deadline</th><th>Assigned</th></tr></thead>
                        <tbody>
                        <?php foreach ($peerAssignmentsFull as $pa): ?>
                            <tr>
                                <td data-label="Evaluator"><?= e($pa['evaluator_name']) ?></td>
                                <td data-label="Role"><?= e(admin_role_label($pa['evaluator_role_name'])) ?></td>
                                <td data-label="Evaluatee"><?= e($pa['evaluatee_name']) ?></td>
                                <td data-label="Position"><?= e($pa['position_title'] ?: 'N/A') ?></td>
                                <td data-label="Department"><?= e($pa['department'] ?: 'N/A') ?></td>
                                <td data-label="Program"><?= e($pa['program_code']) ?></td>
                                <td data-label="Type"><?= e($pa['assignment_type'] === 'peer' ? 'Peer' : ($pa['assignment_type'] === 'dean' ? 'Dean' : ($pa['assignment_type'] === 'self' ? 'Self' : admin_status_label($pa['assignment_type'])))) ?></td>
                                <td data-label="Status"><span class="status-badge status-<?= e($pa['display_status']) ?>"><?= e(admin_status_label($pa['status'])) ?></span></td>
                                <td data-label="Deadline"><?= e($pa['deadline'] ?? 'N/A') ?></td>
                                <td data-label="Assigned"><?= e(date('M j, Y', strtotime((string) $pa['assigned_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($peerAssignmentsFull === []): ?>
                            <tr><td colspan="10">No peer assignments found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
                <?php if ($visibleLeadershipEvaluatees !== []): ?>
                    <section class="admin-box module-table">
                        <div class="box-title"><h2>Leadership Evaluatee Readiness</h2><span><?= count($visibleLeadershipEvaluatees) ?> account(s)</span></div>
                        <table class="data-table">
                            <thead><tr><th>Name</th><th>Role</th><th>Faculty Link</th></tr></thead>
                            <tbody>
                            <?php foreach ($visibleLeadershipEvaluatees as $leader): ?>
                                <tr>
                                    <td data-label="Name"><?= e($leader['full_name']) ?></td>
                                    <td data-label="Role"><?= e(admin_role_label($leader['role'])) ?></td>
                                    <td data-label="Faculty Link"><?= (int) $leader['faculty_id'] > 0 ? 'Ready' : 'Missing faculty record with matching email' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Evaluator Assignments</h2><span><?= count($visiblePeerAssignments) ?> recent</span></div>
                    <table class="data-table">
                        <thead><tr><th>Cycle</th><th>Evaluator</th><th>Evaluatee</th><th>Type</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($visiblePeerAssignments as $assignment): ?>
                            <tr>
                                <td data-label="Cycle"><?= e($assignment['cycle_name']) ?></td>
                                <td data-label="Evaluator"><?= e($assignment['evaluator_name']) ?></td>
                                <td data-label="Evaluatee"><?= e($assignment['evaluatee_name']) ?></td>
                                <td data-label="Type"><?= e(admin_status_label($assignment['assignment_type'])) ?></td>
                                <td data-label="Status"><?= e(admin_status_label($assignment['status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visiblePeerAssignments === []): ?>
                            <tr><td colspan="5">No assignments match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <?php if ($section === 'evaluations'): ?>
                <section class="evaluation-completion-card module-wide" style="--completion-rate: <?= e((string) $adminEvaluationPercent) ?>; --pending-rate: <?= e((string) $adminEvaluationPendingPercent) ?>;" aria-label="Overall completion <?= e((string) $adminEvaluationPercent) ?> percent">
                    <div class="evaluation-completion-chart">
                        <svg viewBox="0 0 120 120" aria-hidden="true">
                            <circle class="evaluation-completion-track" cx="60" cy="60" r="48" pathLength="100"></circle>
                            <circle class="evaluation-completion-pending" cx="60" cy="60" r="48" pathLength="100"></circle>
                            <circle class="evaluation-completion-done" cx="60" cy="60" r="48" pathLength="100"></circle>
                        </svg>
                        <strong><?= e((string) $adminEvaluationPercent) ?>%</strong>
                    </div>
                    <div class="evaluation-completion-details">
                        <h3>Overall Completion</h3>
                        <p><?= e((string) $adminEvaluationCompleted) ?> completed, <?= e((string) $adminEvaluationPending) ?> pending, <?= e((string) $adminEvaluationTotal) ?> total evaluation records.</p>
                        <div class="completion-legend" aria-label="Completion chart legend">
                            <span class="done">Completed</span>
                            <span class="pending">Pending</span>
                            <span class="overall">Overall</span>
                        </div>
                    </div>
                </section>
                <section class="admin-box module-form">
                    <div class="box-title"><h2>Evaluation Monitoring</h2><span>Read-only oversight</span></div>
                    <p class="module-copy">Admin/HR monitors evaluation progress, deadlines, and completion status here. Scoring and evaluator submissions are handled by assigned reviewers.</p>
                    <form method="post" class="inline-action">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="send_reminders">
                        <button type="submit">Send Pending Reminders</button>
                    </form>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Evaluation Tracker</h2><span><?= count($visibleEvaluations) ?> assigned</span></div>
                    <table class="data-table">
                        <thead><tr><th>Faculty</th><th>Evaluation</th><th>Deadline</th><th>Status</th><th>Access</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleEvaluations as $row): ?>
                            <tr>
                                <td data-label="Faculty"><?= e($row['faculty_name'] ?? 'Unassigned') ?></td>
                                <td data-label="Evaluation"><?= e($row['title']) ?></td>
                                <td data-label="Deadline"><?= e($row['deadline']) ?></td>
                                <td data-label="Status"><span class="status <?= e($row['status'] === 'in_progress' ? 'progress' : $row['status']) ?>"><?= e(admin_status_label($row['status'])) ?></span></td>
                                <td data-label="Access"><span class="status pending">Monitor only</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visibleEvaluations === []): ?>
                            <tr><td colspan="5">No evaluations match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <?php if ($section === 'ai_actions'): ?>
                <section class="admin-box module-table">
                    <div class="box-title"><h2>Low-Performing Areas by Department</h2><span>AI Analysis</span></div>
                    <div class="stat-grid compact">
                        <?php foreach ($visibleDepartmentWeakAreas as $area): ?>
                            <article>
                                <span><?= e($area['department']) ?> / <?= e($area['program_code']) ?></span>
                                <strong><?= e($area['weak_area']) ?></strong>
                                <small><?= e((string) $area['weak_count']) ?> detected case(s)</small>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($visibleDepartmentWeakAreas === []): ?>
                            <p class="module-copy">No weak-area summaries match your search.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>AI-Generated Performance Summaries</h2><span>Strengths and weaknesses</span></div>
                    <table class="data-table">
                        <thead><tr><th>Faculty</th><th>Program</th><th>Weak Area</th><th>Strength</th><th>Summary</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleAiInsights as $insight): ?>
                            <tr>
                                <td data-label="Faculty"><?= e($insight['faculty_name']) ?></td>
                                <td data-label="Program"><?= e($insight['program_code'] ?: 'Unassigned Program') ?></td>
                                <td data-label="Weak Area"><?= e($insight['weak_area']) ?></td>
                                <td data-label="Strength"><?= e($insight['strength_area'] ?? '') ?></td>
                                <td data-label="Summary"><?= e($insight['analysis_summary']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visibleAiInsights === []): ?>
                            <tr><td colspan="5">No AI insights match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Recommended Seminars, Trainings, and Interventions by Program</h2><span>Track completion</span></div>
                    <table class="data-table">
                        <thead><tr><th>Program</th><th>Faculty</th><th>Weak Area</th><th>Recommendation</th><th>Target</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleInterventions as $plan): ?>
                            <tr>
                                <td data-label="Program"><?= e($plan['program_code']) ?></td>
                                <td data-label="Faculty"><?= e($plan['faculty_name']) ?></td>
                                <td data-label="Weak Area"><?= e($plan['weak_area']) ?></td>
                                <td data-label="Recommendation"><?= e($plan['recommendation']) ?></td>
                                <td data-label="Target"><?= e((string) $plan['target_date']) ?></td>
                                <td data-label="Status">
                                    <form method="post" class="table-actions">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_intervention">
                                        <input type="hidden" name="id" value="<?= e((string) $plan['id']) ?>">
                                        <select name="status">
                                            <?php foreach (['planned', 'assigned', 'completed'] as $status): ?>
                                                <option value="<?= e($status) ?>" <?= $plan['status'] === $status ? 'selected' : '' ?>><?= e(admin_status_label($status)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit">Update</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($visibleInterventions === []): ?>
                            <tr><td colspan="6">No interventions match your search.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <?php if ($section === 'reports'): ?>
                <section class="admin-box admin-report-intro analytics-report-intro module-wide">
                    <div>
                        <span class="eyebrow">Admin Reports</span>
                        <h2>Reports & Analytics Dashboard</h2>
                        <p>Generate AI-ready evaluation exports, department summaries, and faculty performance reports from one premium analytics workspace.</p>
                    </div>
                    <div class="admin-report-completion" aria-label="Overall completion <?= e((string) $stats['completionRate']) ?> percent">
                        <div class="completion-donut" style="--completion-rate: <?= e((string) $stats['completionRate']) ?>">
                            <svg viewBox="0 0 120 120" role="img" aria-hidden="true">
                                <circle class="completion-donut-track" cx="60" cy="60" r="48" pathLength="100"></circle>
                                <circle id="report-completion-ring" class="completion-donut-progress" cx="60" cy="60" r="48" pathLength="100"></circle>
                            </svg>
                            <strong id="report-completion-rate"><?= e((string) $stats['completionRate']) ?>%</strong>
                        </div>
                        <span>Overall Completion</span>
                    </div>
                </section>

                <section class="admin-report-grid module-wide" aria-label="Specific admin report types">
                    <?php $reportIndex = 0; ?>
                    <?php foreach ($adminReportTypes as $reportKey => $report): ?>
                        <article class="admin-report-card" style="--card-delay: <?= e((string) ($reportIndex * 80)) ?>ms;">
                            <div class="admin-report-card-top">
                                <span class="admin-report-stat"><?= e($report['badge']) ?></span>
                                <span class="admin-report-icon" data-icon="<?= e($report['icon']) ?>" aria-hidden="true"></span>
                            </div>
                            <span class="admin-report-badge"><?= e($report['category']) ?></span>
                            <div class="admin-report-title-row">
                                <span class="admin-report-title-icon" data-icon="<?= e($report['icon']) ?>" aria-hidden="true"></span>
                                <h3><?= e($report['title']) ?></h3>
                            </div>
                            <p><?= e($report['description']) ?></p>
                            <small>Best for: <?= e($report['best_for']) ?></small>
                            <div class="admin-report-progress" aria-label="Report readiness <?= e((string) $report['progress']) ?> percent">
                                <span style="--progress-value: <?= e((string) $report['progress']) ?>%;"></span>
                            </div>
                            <form method="get" action="<?= BASE_URL ?>/reports/download.php" class="admin-report-actions">
                                <input type="hidden" name="report_type" value="<?= e($reportKey) ?>">
                                <label>
                                    Format
                                    <select name="format">
                                        <option value="csv">CSV</option>
                                        <option value="excel">Excel</option>
                                        <option value="pdf">PDF</option>
                                    </select>
                                </label>
                                <button type="submit" data-tooltip="Generates <?= e(strtolower($report['title'])) ?> as the selected export format.">
                                    <span class="report-button-text">Generate</span>
                                    <span class="report-button-loader" aria-hidden="true"></span>
                                </button>
                            </form>
                        </article>
                        <?php $reportIndex++; ?>
                    <?php endforeach; ?>
                </section>

                <section class="admin-box module-form">
                    <div class="box-title"><h2>Custom Filtered Report</h2><span>Optional date, faculty, and type filters</span></div>
                    <form method="get" action="<?= BASE_URL ?>/reports/download.php" class="admin-form">
                        <input type="hidden" name="report_type" value="complete_export">
                        <label>Date From<input type="date" name="date_from"></label>
                        <label>Date To<input type="date" name="date_to"></label>
                        <label>Faculty<select name="faculty_id"><option value="0">All Faculty</option><?php foreach ($faculty as $f): ?><option value="<?= e((string) $f['id']) ?>"><?= e($f['full_name']) ?></option><?php endforeach; ?></select></label>
                        <label>Evaluation Type<input name="evaluation_type" placeholder="Annual, Midyear, Research"></label>
                        <label>Format<select name="format"><option value="csv">CSV</option><option value="excel">Excel</option><option value="pdf">PDF</option></select></label>
                        <button type="submit">Download Custom Report</button>
                    </form>
                    <div class="inline-action"><button type="button" onclick="window.print()">Print Current Summary</button></div>
                </section>
                <section class="admin-box module-table">
                    <div class="box-title"><h2>Evaluation Summary</h2><span>Real-time</span></div>
                    <div class="stat-grid compact">
                        <article><span>Total</span><strong><?= e((string) $stats['evaluationCount']) ?></strong></article>
                        <article><span>Pending</span><strong><?= e((string) $stats['pendingEvaluations']) ?></strong></article>
                        <article><span>Completed</span><strong><?= e((string) $stats['completedEvaluations']) ?></strong></article>
                        <article><span>Overdue</span><strong><?= e((string) $stats['overdueEvaluations']) ?></strong></article>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($section === 'directory'): ?>
                <?php include __DIR__ . '/department_directory.php'; ?>
            <?php endif; ?>

            <?php if ($section === 'dept_management'): ?>
                <?php include __DIR__ . '/department_management.php'; ?>
            <?php endif; ?>

            <?php if ($section === 'settings'): ?>
                <section class="admin-box module-form">
                    <div class="box-title"><h2>Profile & System Settings</h2><span>Admin/HR</span></div>
                    <form method="post" class="admin-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_settings">
                        <div class="profile-upload-preview">
                            <?= admin_avatar_markup($user, 'profile-preview') ?>
                        </div>
                        <label>Full Name<input name="full_name" value="<?= e($user['full_name']) ?>"></label>
                        <label>Email<input type="email" name="email" value="<?= e($user['email']) ?>"></label>
                        <label>Profile Picture<input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label>New Password<input type="password" name="password" placeholder="Leave blank to keep current password"></label>
                        <label>Dashboard Refresh Seconds<input type="number" min="5" name="dashboard_refresh_seconds" value="<?= e(admin_setting('dashboard_refresh_seconds', '10')) ?>"></label>
                        <label>Default Report Format<select name="default_report_format"><option value="csv" <?= admin_setting('default_report_format', 'csv') === 'csv' ? 'selected' : '' ?>>CSV</option><option value="excel" <?= admin_setting('default_report_format') === 'excel' ? 'selected' : '' ?>>Excel</option></select></label>
                        <label class="check-row"><input type="checkbox" name="notifications_enabled" <?= admin_setting('notifications_enabled', '1') === '1' ? 'checked' : '' ?>> Enable notifications and reminders</label>
                        <label class="check-row"><input type="checkbox" name="teacher_results_released" <?= admin_setting('teacher_results_released', '1') === '1' ? 'checked' : '' ?>> Release personal evaluation results to teachers</label>
                        <label class="check-row"><input type="checkbox" name="self_evaluation_enabled" <?= admin_setting('self_evaluation_enabled', '1') === '1' ? 'checked' : '' ?>> Enable teacher self-evaluation</label>
                        <button type="submit">Save Settings</button>
                    </form>
                </section>
        <?php endif; ?>
        </section>
    </main>

    <button id="floating-chat-toggle" class="floating-chat-toggle" type="button" aria-label="Open <?= e(APP_NAME) ?> assistant" aria-expanded="false">
        <img class="floating-chat-logo" src="<?= BASE_URL ?>/assets/images/Black%20White%20Simple%20Minimal%20Flat%20%20AI%20Robot%20Technology%20Logo_20260512_001623_0000.svg" alt="" aria-hidden="true">
    </button>

    <section id="floating-chat-panel" class="floating-chat-panel" aria-label="<?= e(APP_NAME) ?> assistant" hidden>
        <div class="floating-chat-header">
            <div>
                <strong><?= e(APP_NAME) ?> Assistant</strong>
                <span>Live data</span>
            </div>
            <button id="floating-chat-close" type="button" aria-label="Close assistant">x</button>
        </div>
        <div id="chat-log" class="chat-log floating-chat-log">
            <div class="chat-message assistant"><div class="chat-bubble"><strong>Assistant</strong>Hello Admin/HR. Ask about weak areas, reports, pending evaluations, or recommended trainings.</div></div>
        </div>
        <div class="floating-chat-samples" aria-label="Suggested assistant questions">
            <?php foreach ($adminAiSuggestions as $suggestion): ?>
                <button type="button" data-chat-sample="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
            <?php endforeach; ?>
        </div>
        <form id="chat-form" class="chat-form floating-chat-form">
            <input id="chat-message" name="message" placeholder="Ask <?= e(APP_NAME) ?> assistant..." autocomplete="off">
            <button type="submit">Send</button>
        </form>
    </section>

    <?php if ($showAdminAiPrompt): ?>
        <aside id="floating-chat-nudge" class="floating-chat-nudge" role="status" aria-live="polite" hidden>
            <p>Hi Admin! I'm your AI Assistant. Need help with evaluations, reports, or faculty insights?</p>
            <button id="floating-chat-nudge-close" class="floating-chat-nudge-close" type="button" aria-label="Close AI assistant message">x</button>
        </aside>
    <?php endif; ?>

    <script>
        const refreshSeconds = <?= (int) admin_setting('dashboard_refresh_seconds', '10') ?>;
        const baseUrl = '<?= BASE_URL ?>';
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebarOverlay = document.querySelector('.sidebar-overlay');
        const sidebarCollapse = document.querySelector('.sidebar-collapse');
        const darkModeInput = document.querySelector('.dark-mode-input');
        const darkModeLabel = document.querySelector('.dark-mode-switch .sidebar-item-label');
        const chatToggle = document.getElementById('floating-chat-toggle');
        const chatPanel = document.getElementById('floating-chat-panel');
        const chatClose = document.getElementById('floating-chat-close');
        const floatingChatNudge = document.getElementById('floating-chat-nudge');
        const floatingChatNudgeClose = document.getElementById('floating-chat-nudge-close');
        const peoplePanels = ['user-form-panel', 'department-form-panel', 'user-search-panel', 'user-read-panel'];

        document.querySelectorAll('[data-progress]').forEach((bar) => {
            bar.style.width = `${Math.max(0, Math.min(100, Number(bar.dataset.progress || 0)))}%`;
        });

        if (localStorage.getItem('pmas-sidebar-collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }

        function syncThemeMode(enabled) {
            document.body.classList.toggle('dark-mode', enabled);
            if (darkModeInput) darkModeInput.checked = enabled;
            if (darkModeLabel) darkModeLabel.textContent = enabled ? 'Light Mode' : 'Dark Mode';
        }

        syncThemeMode(localStorage.getItem('pmas-dark-mode') === '1');

        if (sidebarCollapse) {
            sidebarCollapse.addEventListener('click', () => {
                const collapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('pmas-sidebar-collapsed', collapsed ? '1' : '0');
                sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            });
        }

        if (darkModeInput) {
            darkModeInput.addEventListener('change', () => {
                syncThemeMode(darkModeInput.checked);
                localStorage.setItem('pmas-dark-mode', darkModeInput.checked ? '1' : '0');
            });
        }

        function showPeoplePanel(panelId) {
            peoplePanels.forEach((id) => {
                const panel = document.getElementById(id);
                if (panel) panel.hidden = id !== panelId;
            });

            const activePanel = document.getElementById(panelId);
            if (!activePanel) return;
            const firstField = activePanel.querySelector('input:not([type="hidden"]), select, textarea');
            const panelRect = activePanel.getBoundingClientRect();
            const targetTop = window.scrollY + panelRect.top - ((window.innerHeight - panelRect.height) / 2);
            window.scrollTo({
                top: Math.max(0, targetTop),
                behavior: 'smooth',
            });
            if (firstField) {
                window.setTimeout(() => firstField.focus({ preventScroll: true }), 250);
            }
        }

        document.querySelectorAll('[data-open-panel]').forEach((button) => {
            button.addEventListener('click', () => {
                showPeoplePanel(button.dataset.openPanel);
            });
        });

        if (document.body.contains(document.getElementById('user-form-panel')) && <?= $editUser || $editPersonFaculty ? 'true' : 'false' ?>) {
            showPeoplePanel('user-form-panel');
        }

        const userDepartmentSelect = document.querySelector('#user-form-panel select[name="department"]');
        const userProgramSelect = document.querySelector('#user-form-panel select[name="program_code"]');
        const userRoleSelect = document.querySelector('#user-form-panel select[name="role"]');
        const userProgramField = document.querySelector('#user-form-panel [data-person-program-field]');

        function syncUserProgramOptions() {
            if (!userDepartmentSelect || !userProgramSelect) return;

            const selectedDepartment = userDepartmentSelect.value;
            const selectedDepartmentOption = userDepartmentSelect.options[userDepartmentSelect.selectedIndex];
            const selectedDepartmentText = selectedDepartmentOption ? selectedDepartmentOption.textContent || '' : '';
            const selectedDepartmentCode = selectedDepartmentText.split('-')[0].trim().toUpperCase();
            let selectedOptionVisible = true;

            Array.from(userProgramSelect.options).forEach((option) => {
                const optionDepartment = option.dataset.department || '';
                const optionDepartmentCode = (option.dataset.departmentCode || '').toUpperCase();
                const isPlaceholder = option.value === '';
                const isVisible = isPlaceholder
                    || optionDepartment === selectedDepartment
                    || (selectedDepartmentCode && optionDepartmentCode === selectedDepartmentCode);
                option.hidden = !isVisible;
                option.disabled = !isVisible && !isPlaceholder;
                if (option.selected && !isVisible) {
                    selectedOptionVisible = false;
                }
            });

            if (!selectedOptionVisible) {
                userProgramSelect.value = '';
            }
        }

        function syncUserProgramApplicability() {
            if (!userRoleSelect || !userProgramSelect || !userProgramField) return;

            const selectedRole = userRoleSelect.value;
            const roleUsesProgram = ['dean', 'program_head', 'teacher'].includes(selectedRole);
            userProgramField.hidden = !roleUsesProgram;
            userProgramSelect.disabled = !roleUsesProgram;
            userProgramSelect.required = ['program_head', 'teacher'].includes(selectedRole);

            const placeholder = userProgramSelect.querySelector('option[value=""]');
            if (placeholder) {
                placeholder.textContent = selectedRole === 'dean' ? 'Optional for Dean' : 'Select Program';
            }

            if (!roleUsesProgram) {
                userProgramSelect.value = '';
            }
        }

        if (userDepartmentSelect && userProgramSelect) {
            userDepartmentSelect.addEventListener('change', syncUserProgramOptions);
            if (userRoleSelect) {
                userRoleSelect.addEventListener('change', () => {
                    syncUserProgramApplicability();
                    syncUserProgramOptions();
                });
            }
            syncUserProgramApplicability();
            syncUserProgramOptions();
        }

        const accountRoleSelect = document.querySelector('section.module-form select[name="role"]');
        const accountProgramField = document.querySelector('[data-user-role-program-field]');
        const accountProgramSelect = accountProgramField ? accountProgramField.querySelector('select[name="program_code"]') : null;

        function syncAccountProgramApplicability() {
            if (!accountRoleSelect || !accountProgramField || !accountProgramSelect) return;

            const isDean = accountRoleSelect.value === 'dean';
            accountProgramField.hidden = !isDean;
            accountProgramSelect.disabled = !isDean;

            if (!isDean) {
                accountProgramSelect.value = '';
            }
        }

        if (accountRoleSelect && accountProgramField) {
            accountRoleSelect.addEventListener('change', syncAccountProgramApplicability);
            syncAccountProgramApplicability();
        }

        const assignmentSetupToggle = document.querySelector('[data-toggle-assignment-setup]');
        const assignmentSetupPanel = document.getElementById('evaluation-assignment-setup');
        if (assignmentSetupToggle && assignmentSetupPanel) {
            assignmentSetupToggle.addEventListener('click', () => {
                const isOpening = assignmentSetupPanel.hidden;
                assignmentSetupPanel.hidden = !isOpening;
                assignmentSetupToggle.setAttribute('aria-expanded', isOpening ? 'true' : 'false');
                assignmentSetupToggle.textContent = isOpening ? 'Close Evaluation Assignment Setup' : 'Open Evaluation Assignment Setup';
            });
        }

        function setSidebar(open) {
            document.body.classList.toggle('sidebar-open', open);
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                menuToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            }
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                setSidebar(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', () => setSidebar(false));
        }

        document.querySelectorAll('.sidebar-menu a, .sidebar-logout').forEach((link) => {
            link.addEventListener('click', () => setSidebar(false));
        });

        function setChatPanel(open) {
            if (!chatPanel || !chatToggle) return;
            chatPanel.hidden = !open;
            chatToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && typeof hideFloatingChatNudge === 'function') {
                hideFloatingChatNudge();
            }
            if (open) {
                const input = document.getElementById('chat-message');
                if (input) input.focus();
            }
        }

        if (chatToggle) {
            chatToggle.addEventListener('click', () => {
                setChatPanel(chatPanel ? chatPanel.hidden : true);
            });
        }

        if (chatClose) {
            chatClose.addEventListener('click', () => setChatPanel(false));
        }

        document.querySelectorAll('[data-chat-sample]').forEach((sampleButton) => {
            sampleButton.addEventListener('click', () => {
                const input = document.getElementById('chat-message');
                setChatPanel(true);
                if (input) {
                    input.value = sampleButton.dataset.chatSample || '';
                    input.focus();
                }
            });
        });

        document.querySelectorAll('.admin-report-actions').forEach((form) => {
            form.addEventListener('submit', () => {
                form.classList.add('is-loading');
                const button = form.querySelector('button[type="submit"] .report-button-text');
                if (button) button.textContent = 'Preparing';
            });
        });

        function hideFloatingChatNudge() {
            if (!floatingChatNudge) return;
            floatingChatNudge.classList.remove('is-visible');
            window.setTimeout(() => {
                floatingChatNudge.hidden = true;
            }, 350);
        }

        if (floatingChatNudge) {
            floatingChatNudge.hidden = true;
            window.setTimeout(() => {
                floatingChatNudge.hidden = false;
                requestAnimationFrame(() => floatingChatNudge.classList.add('is-visible'));
                window.setTimeout(hideFloatingChatNudge, 7000);
            }, 2600);
        }

        if (floatingChatNudgeClose) {
            floatingChatNudgeClose.addEventListener('click', hideFloatingChatNudge);
        }

        if (chatPanel) {
            chatPanel.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    setChatPanel(false);
                }
            });
        }

        async function refreshAdminStats() {
            try {
                const response = await fetch(`${baseUrl}/api/admin.php?type=stats`, { credentials: 'same-origin' });
                const payload = await response.json();
                if (!payload.ok) return;
                const data = payload.data;
                const setText = (id, value) => {
                    const node = document.getElementById(id);
                    if (node) node.textContent = value;
                };
                setText('pending-count', data.pendingEvaluations);
                setText('overdue-count', data.overdueEvaluations);
                setText('stat-users', data.totalUsers);
                setText('stat-pending', data.pendingEvaluations);
                setText('stat-faculty', data.facultyCount);
                setText('stat-completed', data.completedEvaluations);
                setText('stat-rate', `${data.completionRate}% completion`);
                setText('report-completion-rate', `${data.completionRate}%`);
                setText('stat-peer', data.peerAssignments);
                setText('stat-ai', data.aiInsightCount);
                setText('stat-interventions', data.activeInterventions);
                setText('stat-updated', data.updatedAt);
                const bar = document.getElementById('completion-bar');
                if (bar) bar.style.width = `${data.completionRate}%`;
                const reportRing = document.getElementById('report-completion-ring');
                if (reportRing) {
                    const completionValue = Math.max(0, Math.min(100, Number(data.completionRate || 0)));
                    const completionCard = reportRing.closest('.admin-report-completion');
                    reportRing.closest('.completion-donut')?.style.setProperty('--completion-rate', completionValue);
                    reportRing.style.strokeDashoffset = `${100 - completionValue}`;
                    completionCard?.setAttribute('aria-label', `Overall completion ${completionValue} percent`);
                }
            } catch (error) {
                console.error(error);
            }
        }

        setInterval(refreshAdminStats, Math.max(refreshSeconds, 5) * 1000);
        refreshAdminStats();

        const chatForm = document.getElementById('chat-form');
        if (chatForm) {
            chatForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const input = document.getElementById('chat-message');
                const log = document.getElementById('chat-log');
                const message = input.value.trim();
                if (!message) return;
                const escapeHtml = (value) => value.replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char]));
                log.insertAdjacentHTML('beforeend', `<div class="chat-message user"><div class="chat-bubble"><strong>You</strong>${escapeHtml(message)}</div></div>`);
                input.value = '';
                const body = new URLSearchParams({ message });
                const response = await fetch(`${baseUrl}/api/admin.php?type=chatbot`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const payload = await response.json();
                log.insertAdjacentHTML('beforeend', `<div class="chat-message assistant"><div class="chat-bubble"><strong>Assistant</strong>${escapeHtml(payload.answer || '')}</div></div>`);
                log.scrollTop = log.scrollHeight;
            });
        }
    </script>
</body>
</html>
