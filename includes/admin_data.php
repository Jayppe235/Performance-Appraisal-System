<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function admin_count(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function admin_all(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function admin_one(string $sql, array $params = []): ?array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function admin_ensure_profile_image_column(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $column = admin_one("SHOW COLUMNS FROM users LIKE 'profile_image'");

        if ($column === null) {
            db()->exec('ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL AFTER department');
        }
    } catch (Throwable) {
        // Database unavailable — skip schema check gracefully
    }
}

/**
 * Upload and validate a profile image for a user.
 * Returns the relative path on success, null if no file uploaded,
 * or throws RuntimeException on validation/storage error.
 */
function admin_profile_image_upload(int $userId): ?string
{
    if (!isset($_FILES['profile_image']) || !is_array($_FILES['profile_image'])) {
        return null;
    }

    $file = $_FILES['profile_image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile picture upload failed. Please try another image.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Profile picture must be 5 MB or smaller.');
    }

    $tmpName = $file['tmp_name'] ?? '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Profile picture must be a JPG, PNG, WEBP, GIF, or SVG image.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/profiles';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Cannot create profile upload folder.');
    }

    $fileName = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mimeType];
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Could not save the profile picture.');
    }

    return 'assets/uploads/profiles/' . $fileName;
}

function admin_ensure_archive_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $column = admin_one("SHOW COLUMNS FROM faculty LIKE 'is_archived'");

        if ($column === null) {
            db()->exec('ALTER TABLE faculty ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER performance_notes');
        }

        $assignmentArchiveColumn = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'is_archived'");
        if ($assignmentArchiveColumn === null) {
            db()->exec('ALTER TABLE peer_assignments ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0');
        }

        $assignmentArchivedAtColumn = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'archived_at'");
        if ($assignmentArchivedAtColumn === null) {
            db()->exec('ALTER TABLE peer_assignments ADD COLUMN archived_at DATETIME NULL');
        }

        $assignmentArchivedByColumn = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'archived_by'");
        if ($assignmentArchivedByColumn === null) {
            db()->exec('ALTER TABLE peer_assignments ADD COLUMN archived_by INT NULL');
        }

        foreach (['pmas_form_a_category_results', 'pmas_form_b_category_results'] as $table) {
            if (admin_one("SHOW TABLES LIKE '{$table}'") === null) {
                continue;
            }

            $resultArchiveColumn = admin_one("SHOW COLUMNS FROM {$table} LIKE 'is_archived'");
            if ($resultArchiveColumn === null) {
                db()->exec("ALTER TABLE {$table} ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
            }

            $resultArchivedAtColumn = admin_one("SHOW COLUMNS FROM {$table} LIKE 'archived_at'");
            if ($resultArchivedAtColumn === null) {
                db()->exec("ALTER TABLE {$table} ADD COLUMN archived_at DATETIME NULL");
            }
        }
    } catch (Throwable) {
        // Database unavailable — skip schema check gracefully
    }
}

function admin_ensure_department_details_schema(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $columns = [
            'description' => 'TEXT NULL',
            'department_type' => "VARCHAR(40) NOT NULL DEFAULT 'Academic Department'",
            'contact_email' => 'VARCHAR(150) NULL',
            'office_location' => 'VARCHAR(150) NULL',
        ];
        foreach ($columns as $name => $definition) {
            if (admin_one("SHOW COLUMNS FROM departments LIKE '{$name}'") === null) {
                db()->exec("ALTER TABLE departments ADD COLUMN {$name} {$definition}");
            }
        }
    } catch (Throwable) {
        // Older deployments remain usable if schema alteration is unavailable.
    }
}

function admin_ensure_faculty_program_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $column = admin_one("SHOW COLUMNS FROM faculty LIKE 'program_code'");

        if ($column === null) {
            db()->exec('ALTER TABLE faculty ADD COLUMN program_code VARCHAR(30) NULL AFTER department');
        }

        $userProgramColumn = admin_one("SHOW COLUMNS FROM users LIKE 'program'");

        if ($userProgramColumn === null) {
            db()->exec('ALTER TABLE users ADD COLUMN program VARCHAR(30) NULL AFTER department');
        }

        $singleProgramDepartments = admin_all(
            'SELECT d.department_name, d.department_code, p.program_code
             FROM departments d
             JOIN programs p ON p.department_id = d.id AND p.is_active = 1
             WHERE (
                 SELECT COUNT(*)
                 FROM programs p2
                 WHERE p2.department_id = d.id AND p2.is_active = 1
             ) = 1'
        );

        foreach ($singleProgramDepartments as $department) {
            foreach (admin_department_aliases($department) as $alias) {
                db()->prepare(
                    'UPDATE faculty
                     SET program_code = :program_code
                     WHERE (program_code IS NULL OR program_code = "") AND department = :department'
                )->execute([
                    'program_code' => $department['program_code'],
                    'department' => $alias,
                ]);
            }
        }
    } catch (Throwable) {
        // Database unavailable — skip schema upgrade gracefully
    }
}

function admin_ensure_department_logo_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $column = admin_one("SHOW COLUMNS FROM departments LIKE 'logo_image'");

        if ($column === null) {
            db()->exec('ALTER TABLE departments ADD COLUMN logo_image VARCHAR(255) NULL AFTER dean_user_id');
        }
    } catch (Throwable) {
        // Database unavailable — skip schema check gracefully
    }
}

/**
 * Upload and validate a department logo/image.
 * Returns a relative path on success or null when no file was uploaded.
 */
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

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Department logo must be 5 MB or smaller.');
    }

    $tmpName = $file['tmp_name'] ?? '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Department logo must be a JPG, PNG, WEBP, GIF, or SVG image.');
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

function admin_ensure_evaluation_role_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        db()->exec("ALTER TABLE users MODIFY role ENUM('admin_hr', 'vpaa', 'dean', 'program_head', 'teacher') NOT NULL");
        db()->exec("ALTER TABLE peer_assignments MODIFY evaluator_role ENUM('vpaa', 'dean', 'program_head', 'teacher') NOT NULL");
        db()->exec("ALTER TABLE evaluation_rules MODIFY evaluator_role ENUM('vpaa', 'dean', 'program_head', 'teacher') NOT NULL");
        db()->exec("ALTER TABLE evaluation_rules MODIFY evaluatee_role ENUM('dean', 'program_head', 'teacher') NOT NULL");
        db()->exec("ALTER TABLE evaluation_rules MODIFY assignment_type ENUM('peer', 'program_head', 'dean', 'self') NOT NULL");
        db()->exec("ALTER TABLE peer_assignments MODIFY assignment_type ENUM('peer', 'program_head', 'dean', 'self') NOT NULL");
    } catch (PDOException $exception) {
        // Existing installations may apply this through database/pmas.sql during setup.
    }
}

function admin_stats(): array
{
    admin_ensure_archive_schema();
    $totalUsers = admin_count('SELECT COUNT(*) FROM users');
    $activeUsers = admin_count('SELECT COUNT(*) FROM users WHERE is_active = 1');
    $facultyCount = admin_count('SELECT COUNT(*) FROM faculty WHERE COALESCE(is_archived, 0) = 0');

    // Use peer_assignments for accurate real-time completion (not legacy evaluations table)
    $peerTotal = admin_count('SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0');
    $pendingPeerAssignments = admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending'");
    $completedPeerAssignments = admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'submitted'");
    $overduePeerAssignments = admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND deadline < CURDATE() AND status != 'submitted'");

    $aiInsightCount = admin_count('SELECT COUNT(*) FROM ai_insights');
    $activeInterventions = admin_count("SELECT COUNT(*) FROM intervention_plans WHERE status IN ('planned', 'assigned')");
    $openPeriods = admin_count("SELECT COUNT(*) FROM appraisal_periods WHERE status = 'open'");
    $factorCount = admin_count('SELECT COUNT(*) FROM performance_factors WHERE is_active = 1');
    $questionCount = admin_count('SELECT COUNT(*) FROM appraisal_questionnaires WHERE is_active = 1');

    $recentUsers = admin_all(
        'SELECT full_name, email, role, last_login_at
         FROM users
         ORDER BY COALESCE(last_login_at, created_at) DESC
         LIMIT 5'
    );

    // Use the active assignment table for report/dashboard totals. The legacy
    // evaluations table can be sparse on current installs.
    $evaluationCount = $peerTotal;
    $pendingEvaluations = $pendingPeerAssignments;
    $completedEvaluations = $completedPeerAssignments;

    $recentEvaluations = admin_all(
        'SELECT e.title, e.status, e.deadline, f.full_name AS faculty_name
         FROM evaluations e
         LEFT JOIN faculty f ON f.id = e.faculty_id
         ORDER BY e.updated_at DESC
         LIMIT 5'
    );

    return [
        'totalUsers' => $totalUsers,
        'activeUsers' => $activeUsers,
        'facultyCount' => $facultyCount,
        'evaluationCount' => $evaluationCount,
        'pendingEvaluations' => $pendingEvaluations,
        'completedEvaluations' => $completedEvaluations,
        'overdueEvaluations' => $overduePeerAssignments,
        'peerAssignments' => $peerTotal,
        'pendingPeerAssignments' => $pendingPeerAssignments,
        'completedPeerAssignments' => $completedPeerAssignments,
        'aiInsightCount' => $aiInsightCount,
        'activeInterventions' => $activeInterventions,
        'openPeriods' => $openPeriods,
        'factorCount' => $factorCount,
        'questionCount' => $questionCount,
        'completionRate' => $peerTotal > 0 ? round(($completedPeerAssignments / $peerTotal) * 100) : 0,
        'recentUsers' => $recentUsers,
        'recentEvaluations' => $recentEvaluations,
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
}

function admin_departments(): array
{
    admin_ensure_department_logo_schema();

    return admin_all(
        'SELECT d.*, u.full_name AS dean_name, u.email AS dean_email
         FROM departments d
         LEFT JOIN users u ON u.id = d.dean_user_id
         ORDER BY d.department_name'
    );
}

function admin_programs(): array
{
    return admin_all(
        'SELECT p.*, d.department_code, d.department_name, u.full_name AS program_head_name, u.email AS program_head_email
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         LEFT JOIN users u ON u.id = p.program_head_user_id
         ORDER BY d.department_code, p.program_name'
    );
}

function admin_normalize_department_name(string $departmentName): string
{
    $name = trim($departmentName);
    $lower = strtolower($name);

    if (
        $lower === 'cite' ||
        $lower === 'cit' ||
        $lower === 'computer studies' ||
        str_contains($lower, 'information technology')
    ) {
        return 'College of Information Technology and Engineering';
    }

    return $name;
}

function admin_matching_department_aliases(string $department): array
{
    $department = trim($department);
    if ($department === '') {
        return [];
    }

    $normalized = admin_normalize_department_name($department);
    $normalizedLower = strtolower($normalized);
    $departmentLower = strtolower($department);
    $aliases = [$department];
    if ($normalized !== $department) {
        $aliases[] = $normalized;
    }

    foreach (admin_departments() as $dept) {
        $deptAliases = array_map('strtolower', admin_department_aliases($dept));
        if (in_array($departmentLower, $deptAliases, true) || in_array($normalizedLower, $deptAliases, true)) {
            $aliases = array_merge($aliases, admin_department_aliases($dept));
            break;
        }
    }

    return array_values(array_unique(array_filter($aliases)));
}

function admin_department_aliases(array $department): array
{
    $aliases = [
        (string) ($department['department_name'] ?? ''),
        (string) ($department['department_code'] ?? ''),
    ];
    $name = strtolower((string) ($department['department_name'] ?? ''));
    $code = strtoupper((string) ($department['department_code'] ?? ''));

    if ($code === 'CITE' || $code === 'CIT' || str_contains($name, 'computer studies') || str_contains($name, 'information technology')) {
        $aliases[] = 'Computer Studies';
    }

    if ($code === 'CITE' || $code === 'CIT' || str_contains($name, 'computer studies') || str_contains($name, 'information technology')) {
        $aliases[] = 'College of Information Technology Engineering';
        $aliases[] = 'College of Information Technology and Engineering';
    }

    if ($code === 'COED' || str_contains($name, 'college of education')) {
        $aliases[] = 'Education';
    }

    if ($code === 'CBA' || str_contains($name, 'business administration')) {
        $aliases[] = 'Business Administration';
    }

    if ($code === 'CAS' || str_contains($name, 'arts and sciences')) {
        $aliases[] = 'Arts and Sciences';
    }

    return array_values(array_unique(array_filter($aliases)));
}

function admin_department_directory(): array
{
    admin_ensure_faculty_program_schema();

    $departments = admin_departments();
    $programs = admin_programs();
    $faculty = admin_faculty();
    $users = admin_users();
    $directory = [];

    foreach ($departments as $department) {
        $directory[(int) $department['id']] = [
            'department' => $department,
            'programs' => [],
            'faculty' => [],
            'users' => [],
        ];
    }

    foreach ($programs as $program) {
        $departmentId = (int) $program['department_id'];
        if (isset($directory[$departmentId])) {
            $directory[$departmentId]['programs'][] = $program;
        }
    }

    foreach ($faculty as $facultyMember) {
        $matchedDepartmentId = null;

        foreach ($directory as $departmentId => $group) {
            $aliases = admin_department_aliases($group['department']);
            foreach ($aliases as $alias) {
                if (strcasecmp((string) $facultyMember['department'], $alias) === 0) {
                    $matchedDepartmentId = $departmentId;
                    break 2;
                }
            }
        }

        if ($matchedDepartmentId !== null) {
            $directory[$matchedDepartmentId]['faculty'][] = $facultyMember;
        }
    }

    foreach ($users as $systemUser) {
        foreach ($directory as $departmentId => $group) {
            $department = $group['department'];
            $aliases = admin_department_aliases($department);
            $isDepartmentUser = false;
            foreach ($aliases as $alias) {
                if (strcasecmp((string) $systemUser['department'], $alias) === 0) {
                    $isDepartmentUser = true;
                    break;
                }
            }
            $isDean = (int) ($department['dean_user_id'] ?? 0) === (int) $systemUser['id'];
            $isProgramHead = false;

            foreach ($group['programs'] as $program) {
                if ((int) ($program['program_head_user_id'] ?? 0) === (int) $systemUser['id']) {
                    $isProgramHead = true;
                    break;
                }
            }

            if ($isDepartmentUser || $isDean || $isProgramHead) {
                $directory[$departmentId]['users'][(int) $systemUser['id']] = $systemUser;
            }
        }
    }

    foreach ($directory as $departmentId => $group) {
        $directory[$departmentId]['users'] = array_values($group['users']);
    }

    return array_values($directory);
}

function admin_department_directory_by_id(int $departmentId): ?array
{
    foreach (admin_department_directory() as $group) {
        if ((int) $group['department']['id'] === $departmentId) {
            return $group;
        }
    }

    return null;
}

function admin_periods(): array
{
    return admin_all('SELECT * FROM appraisal_periods ORDER BY date_start DESC');
}

function admin_factors(): array
{
    return admin_all('SELECT * FROM performance_factors ORDER BY factor_name');
}

function admin_questionnaires(): array
{
    return admin_all(
        'SELECT q.*, f.factor_name
         FROM appraisal_questionnaires q
         JOIN performance_factors f ON f.id = q.factor_id
         ORDER BY f.factor_name, q.created_at DESC'
    );
}

function admin_rules(): array
{
    return admin_all('SELECT * FROM evaluation_rules ORDER BY rule_name');
}

function admin_department_weak_areas(): array
{
    admin_ensure_faculty_program_schema();

    return admin_all(
        'SELECT f.department, COALESCE(NULLIF(f.program_code, ""), "Unassigned Program") AS program_code, i.weak_area, COUNT(*) AS weak_count
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         GROUP BY f.department, program_code, i.weak_area
         ORDER BY f.department, program_code, weak_count DESC'
    );
}

function admin_ai_insights(): array
{
    admin_ensure_faculty_program_schema();

    return admin_all(
        'SELECT i.*, f.full_name AS faculty_name, f.department, f.program_code
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         ORDER BY i.created_at DESC
         LIMIT 8'
    );
}

function admin_interventions(): array
{
    admin_ensure_faculty_program_schema();

    return admin_all(
        'SELECT p.*, f.full_name AS faculty_name, f.department, COALESCE(NULLIF(f.program_code, ""), "Unassigned Program") AS program_code
         FROM intervention_plans p
         JOIN faculty f ON f.id = p.faculty_id
         ORDER BY program_code, FIELD(p.status, "assigned", "planned", "completed"), p.target_date ASC'
    );
}

function admin_peer_assignments(): array
{
    return admin_all(
        'SELECT p.*, u.full_name AS evaluator_name, f.full_name AS evaluatee_name, f.department
         FROM peer_assignments p
         JOIN users u ON u.id = p.evaluator_user_id
         JOIN faculty f ON f.id = p.evaluatee_faculty_id
         ORDER BY p.assigned_at DESC
         LIMIT 10'
    );
}

function admin_peer_assignments_full(): array
{
    return admin_all(
        "SELECT
            pa.id,
            pa.cycle_name AS evaluation_period,
            pa.evaluator_role,
            pa.assignment_type,
            pa.status,
            pa.deadline,
            pa.assigned_at,
            eu.full_name AS evaluator_name,
            eu.role AS evaluator_role_name,
            ef.full_name AS evaluatee_name,
            ef.position_title,
            ef.department,
            COALESCE(NULLIF(ef.program_code, ''), 'Unassigned Program') AS program_code,
            CASE
                WHEN pa.status = 'submitted' THEN 'completed'
                WHEN pa.deadline IS NOT NULL AND pa.deadline < CURDATE() THEN 'overdue'
                ELSE 'pending'
            END AS display_status,
            CASE
                WHEN LOWER(COALESCE(ef.position_title, '')) LIKE '%dean%' THEN 'Dean'
                WHEN LOWER(COALESCE(ef.position_title, '')) LIKE '%program head%' THEN 'Program Head'
                ELSE 'Faculty'
            END AS evaluatee_role_label
        FROM peer_assignments pa
        JOIN users eu ON eu.id = pa.evaluator_user_id
        JOIN faculty ef ON ef.id = pa.evaluatee_faculty_id
        ORDER BY pa.assigned_at DESC"
    );
}

function admin_leadership_evaluatees(string $department = ''): array
{
    $params = [];
    $where = 'u.role IN ("dean", "program_head") AND u.is_active = 1';

    if ($department !== '') {
        $departmentAliases = admin_matching_department_aliases($department);
        if ($departmentAliases === []) {
            $departmentAliases = [$department];
        }

        $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));
        $where .= " AND (u.department IN ($placeholders) OR f.department IN ($placeholders))";
        $params = array_merge($departmentAliases, $departmentAliases);
    }

    return admin_all(
        "SELECT u.id AS user_id, u.full_name, u.email, u.role, COALESCE(f.id, 0) AS faculty_id, f.department
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id
         WHERE $where
         ORDER BY u.role, u.full_name",
        $params
    );
}

function admin_users(): array
{
    admin_ensure_profile_image_column();
    admin_ensure_faculty_program_schema();

    return admin_all(
        'SELECT id, full_name, email, role, is_active, phone, department, program, profile_image, last_login_at, created_at
         FROM users
         WHERE is_active = 1
         ORDER BY created_at DESC'
    );
}

function admin_faculty(): array
{
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();

    return admin_all('SELECT * FROM faculty WHERE is_archived = 0 ORDER BY updated_at DESC, created_at DESC');
}

function admin_evaluations(): array
{
    return admin_all(
        'SELECT e.*, f.full_name AS faculty_name
         FROM evaluations e
         LEFT JOIN faculty f ON f.id = e.faculty_id
         ORDER BY e.deadline ASC, e.updated_at DESC'
    );
}

function admin_role_label(string $role): string
{
    return ROLES[$role] ?? $role;
}

function admin_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function admin_setting(string $key, string $default = ''): string
{
    $row = admin_one('SELECT setting_value FROM system_settings WHERE setting_key = :key', ['key' => $key]);
    return $row['setting_value'] ?? $default;
}

function admin_save_setting(string $key, string $value): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
    } catch (Throwable) {
        // Database unavailable — skip saving setting gracefully
    }
}

function admin_activity(string $description): void
{
    try {
        $user = current_user();
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, description) VALUES (:user_id, :description)'
        );
        $stmt->execute([
            'user_id' => $user['id'] ?? null,
            'description' => $description,
        ]);
    } catch (Throwable) {
        // Database unavailable — skip activity logging gracefully
    }
}

function admin_completion_by_department(string $periodName = ''): array
{
    admin_ensure_archive_schema();
    $periodWhere = "WHERE COALESCE(f.is_archived, 0) = 0
        AND COALESCE(pa.is_archived, 0) = 0
        AND pa.status NOT IN ('not_required','reassigned','cancelled','replaced')";
    $params = [];
    if ($periodName !== '') {
        $periodWhere .= ' AND pa.cycle_name = :period_name';
        $periodWhere .= " AND EXISTS (
            SELECT 1 FROM evaluation_period_participation evaluator_epp
            JOIN appraisal_periods evaluator_ap ON evaluator_ap.id=evaluator_epp.evaluation_period_id
            WHERE evaluator_ap.period_name=pa.cycle_name
              AND evaluator_epp.user_id=pa.evaluator_user_id
              AND evaluator_epp.participation_status='included'
              AND evaluator_epp.work_status='active'
              AND evaluator_epp.employment_status IN ('active','newly_added')
        ) AND EXISTS (
            SELECT 1 FROM evaluation_period_participation evaluatee_epp
            JOIN appraisal_periods evaluatee_ap ON evaluatee_ap.id=evaluatee_epp.evaluation_period_id
            WHERE evaluatee_ap.period_name=pa.cycle_name
              AND evaluatee_epp.user_id=f.user_id
              AND evaluatee_epp.participation_status='included'
              AND evaluatee_epp.work_status='active'
              AND evaluatee_epp.employment_status IN ('active','newly_added')
        )";
        $params['period_name'] = $periodName;
    }

    $raw = admin_all(
        "SELECT COALESCE(NULLIF(f.department, ''), 'Unassigned') AS department,
                COUNT(DISTINCT pa.id) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue,
                ROUND(100.0 * SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT pa.id), 0), 1) AS completion_pct
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         $periodWhere
         GROUP BY f.department
         ORDER BY completion_pct DESC",
        $params
    );

    // Normalize: merge rows whose department aliases belong to the same official department
    // e.g. "CITE", "College of Information Technology Engineering", "Computer Studies" all merge into one.
    $normalized = [];
    foreach ($raw as $row) {
        $dept = (string) ($row['department'] ?? '');
        $normalizedDept = admin_normalize_department_name($dept);

        if (!isset($normalized[$normalizedDept])) {
            $normalized[$normalizedDept] = [
                'department' => $normalizedDept,
                'total_assignments' => 0,
                'submitted' => 0,
                'pending' => 0,
                'overdue' => 0,
                'completion_pct' => 0,
            ];
        }

        $normalized[$normalizedDept]['total_assignments'] += (int) ($row['total_assignments'] ?? 0);
        $normalized[$normalizedDept]['submitted'] += (int) ($row['submitted'] ?? 0);
        $normalized[$normalizedDept]['pending'] += (int) ($row['pending'] ?? 0);
        $normalized[$normalizedDept]['overdue'] += (int) ($row['overdue'] ?? 0);
    }

    // Self-evaluations and absent peer assignments are required work even when no
    // peer_assignments row exists yet. Include them so summary cards cannot
    // report 100% while the program drill-down still shows missing actions.
    if ($periodName !== '') {
        $period = admin_one('SELECT id FROM appraisal_periods WHERE period_name=? LIMIT 1', [$periodName]);
        $periodId = (int)($period['id'] ?? 0);
        if ($periodId > 0) {
            $requirements = admin_all(
                "SELECT u.id,
                        COALESCE(NULLIF(epp.department_snapshot,''),u.department,'Unassigned') department,
                        EXISTS(SELECT 1 FROM peer_assignments px
                               LEFT JOIN peer_evaluation_assignments pex ON pex.peer_assignment_id=px.id
                               WHERE px.cycle_name=? AND px.evaluatee_faculty_id=epp.faculty_id
                                 AND px.assignment_type='peer'
                                 AND px.status NOT IN ('not_required','reassigned','cancelled','replaced')
                                 AND COALESCE(px.is_archived,0)=0
                                 AND pex.id IS NOT NULL AND COALESCE(pex.is_archived,0)=0) has_peer,
                        COALESCE((SELECT se.status FROM pmas_self_evaluations se
                                  WHERE se.evaluation_period=? AND se.user_id=u.id ORDER BY se.id DESC LIMIT 1),'missing') self_evaluation_status
                 FROM evaluation_period_participation epp
                 JOIN users u ON u.id=epp.user_id
                 WHERE epp.evaluation_period_id=? AND u.is_active=1
                   AND epp.participation_status='included' AND epp.work_status='active'
                   AND epp.employment_status IN ('active','newly_added')",
                [$periodName,$periodName,$periodId]
            );
            foreach ($requirements as $requirement) {
                $dept = admin_normalize_department_name((string)$requirement['department']);
                $normalized[$dept] ??= ['department'=>$dept,'total_assignments'=>0,'submitted'=>0,'pending'=>0,'overdue'=>0,'completion_pct'=>0];
                // One Self-Evaluation per included participant.
                $normalized[$dept]['total_assignments']++;
                if ((string)$requirement['self_evaluation_status'] === 'submitted') {
                    $normalized[$dept]['submitted']++;
                } else {
                    $normalized[$dept]['pending']++;
                }
                // One official peer evaluation per included participant.
                if ((int)$requirement['has_peer'] === 0) {
                    $normalized[$dept]['total_assignments']++;
                    $normalized[$dept]['pending']++;
                }
            }
        }
    }

    // Recalculate completion percentages after merge
    foreach ($normalized as &$row) {
        $row['completion_pct'] = $row['total_assignments'] > 0
            ? round(($row['submitted'] / $row['total_assignments']) * 100, 1)
            : 0;
    }
    unset($row);

    // Sort by completion percentage descending
    usort($normalized, fn($a, $b) => $b['completion_pct'] <=> $a['completion_pct']);

    return $normalized;
}

function admin_department_comparison(): array
{
    $stats = admin_completion_by_department();

    $best = $stats !== [] ? $stats[0] : null;
    $worst = $stats !== [] ? $stats[count($stats) - 1] : null;
    $avgCompletion = 0.0;
    $totalDepts = count($stats);

    foreach ($stats as $row) {
        $avgCompletion += (float) ($row['completion_pct'] ?? 0);
    }

    $avgCompletion = $totalDepts > 0 ? round($avgCompletion / $totalDepts, 1) : 0.0;

    return [
        'departments' => $stats,
        'bestPerforming' => $best,
        'worstPerforming' => $worst,
        'averageCompletionRate' => $avgCompletion,
        'departmentCount' => $totalDepts,
    ];
}

function admin_unassigned_leadership(): array
{
    $noDean = admin_all(
        'SELECT id, department_name, department_code
         FROM departments
         WHERE dean_user_id IS NULL'
    );
    $noHead = admin_all(
        'SELECT p.id, p.program_name, p.program_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.program_head_user_id IS NULL'
    );

    return [
        'departments_without_dean' => $noDean,
        'programs_without_head' => $noHead,
        'unassigned_deans_count' => count($noDean),
        'unassigned_heads_count' => count($noHead),
    ];
}

function admin_weak_area_patterns(): array
{
    admin_ensure_faculty_program_schema();

    return admin_all(
        "SELECT i.weak_area, COUNT(*) AS total_count,
                COUNT(DISTINCT i.faculty_id) AS affected_faculty_count,
                COUNT(DISTINCT f.department) AS affected_departments
         FROM ai_insights i
         JOIN faculty f ON f.id = i.faculty_id
         GROUP BY i.weak_area
         ORDER BY total_count DESC"
    );
}

function admin_period_comparison(): array
{
    $periods = admin_all(
        "SELECT id, period_name, school_year, status
         FROM appraisal_periods
         ORDER BY date_start ASC, id ASC"
    );

    $result = [];
    $previousRate = null;

    foreach ($periods as $period) {
        $periodName = (string) ($period['period_name'] ?? '');
        if ($periodName === '') {
            continue;
        }

        $completed = admin_count(
            "SELECT COUNT(*) FROM peer_assignments WHERE cycle_name = :period AND COALESCE(is_archived, 0) = 0 AND status = 'submitted'",
            ['period' => $periodName]
        );
        $total = admin_count(
            "SELECT COUNT(*) FROM peer_assignments WHERE cycle_name = :period AND COALESCE(is_archived, 0) = 0",
            ['period' => $periodName]
        );
        $overdue = admin_count(
            "SELECT COUNT(*) FROM peer_assignments WHERE cycle_name = :period AND COALESCE(is_archived, 0) = 0 AND deadline < CURDATE() AND status != 'submitted'",
            ['period' => $periodName]
        );
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        // Faculty with evaluations in this period
        $facultyWithEvals = admin_count(
            "SELECT COUNT(DISTINCT evaluatee_faculty_id) FROM peer_assignments WHERE cycle_name = :period AND COALESCE(is_archived, 0) = 0",
            ['period' => $periodName]
        );
        $facultyCompleted = admin_count(
            "SELECT COUNT(DISTINCT evaluatee_faculty_id) FROM peer_assignments WHERE cycle_name = :period AND COALESCE(is_archived, 0) = 0 AND status = 'submitted'",
            ['period' => $periodName]
        );

        $change = null;
        if ($previousRate !== null) {
            $change = round($completionRate - $previousRate, 1);
        }
        $previousRate = $completionRate;

        // Weak areas this period
        $weakAreas = admin_all(
            "SELECT i.weak_area, COUNT(*) AS cnt
             FROM ai_insights i
             JOIN peer_assignments pa ON pa.evaluatee_faculty_id = i.faculty_id
             WHERE pa.cycle_name = :period
               AND COALESCE(pa.is_archived, 0) = 0
             GROUP BY i.weak_area
             ORDER BY cnt DESC
             LIMIT 3",
            ['period' => $periodName]
        );

        $avgScore = admin_one(
            "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
             FROM pmas_form_b_category_results r
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE pa.cycle_name = :period AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND r.status = 'completed'",
            ['period' => $periodName]
        );

        $result[] = [
            'period_id' => (int) ($period['id'] ?? 0),
            'period_name' => $periodName,
            'school_year' => (string) ($period['school_year'] ?? ''),
            'status' => (string) ($period['status'] ?? ''),
            'total_assignments' => $total,
            'completed' => $completed,
            'overdue' => $overdue,
            'completion_rate' => $completionRate,
            'change_from_previous' => $change,
            'faculty_with_evaluations' => $facultyWithEvals,
            'faculty_completed' => $facultyCompleted,
            'faculty_completion_rate' => $facultyWithEvals > 0 ? round(($facultyCompleted / $facultyWithEvals) * 100, 1) : 0,
            'weak_areas' => array_map(fn($w) => ['area' => $w['weak_area'] ?? '', 'count' => (int) ($w['cnt'] ?? 0)], $weakAreas),
            'average_score' => $avgScore !== null ? (float) ($avgScore['avg_score'] ?? 0) : null,
        ];
    }

    return $result;
}

function admin_evaluation_progress_summary(): array
{
    admin_ensure_archive_schema();

    // Use peer_assignments for real-time completion data
    $totalEval = admin_count('SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0');
    $pendingEval = admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending'");
    $completedEval = admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'submitted'");
    $openPeriods = admin_count("SELECT COUNT(*) FROM appraisal_periods WHERE status = 'open'");
    $totalFaculty = admin_count('SELECT COUNT(*) FROM faculty WHERE is_archived = 0');

    // Faculty with at least one completed evaluation
    $facultyWithResults = admin_count(
        "SELECT COUNT(DISTINCT pa.evaluatee_faculty_id)
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE pa.status = 'submitted'
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0"
    );

    // Faculty with 100% completion (all their assignments submitted)
    $facultyAllComplete = 0;
    $facultyStats = admin_all(
        "SELECT pa.evaluatee_faculty_id AS fid,
                COUNT(*) AS total,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS done
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0
         GROUP BY pa.evaluatee_faculty_id"
    );
    foreach ($facultyStats as $stat) {
        if ((int) ($stat['total'] ?? 0) > 0 && (int) ($stat['total'] ?? 0) === (int) ($stat['done'] ?? 0)) {
            $facultyAllComplete++;
        }
    }

    return [
        'totalEvaluations' => $totalEval,
        'pendingEvaluations' => $pendingEval,
        'completedEvaluations' => $completedEval,
        'completionRate' => $totalEval > 0 ? round(($completedEval / $totalEval) * 100) : 0,
        'openAppraisalPeriods' => $openPeriods,
        'totalFaculty' => $totalFaculty,
        'facultyWithCompletedEvaluations' => $facultyWithResults,
        'facultyFullyCompleted' => $facultyAllComplete,
        'facultyEvaluationRate' => $totalFaculty > 0 ? round(($facultyWithResults / $totalFaculty) * 100) : 0,
    ];
}

/**
 * Recalculate faculty.progress_percent for ALL faculty members based on peer_assignments.
 * progress_percent = (submitted_assignments / total_assignments) * 100 for each faculty member.
 * Only considers assignments from active (open) evaluation periods so locked/closed cycles
 * do not drag down progress.
 * Call this after any evaluation submission to keep progress in sync.
 */
function admin_recalculate_faculty_progress(?int $facultyId = null): void
{
    admin_ensure_archive_schema();

    // Determine active cycle names (only 'open' periods)
    $activePeriods = admin_all("SELECT period_name FROM appraisal_periods WHERE status = 'open'");
    $activeCycleNames = array_values(array_unique(array_filter(array_map(
        fn (array $p): string => (string) ($p['period_name'] ?? ''),
        $activePeriods
    ))));

    $where = 'COALESCE(pa.is_archived, 0) = 0';
    $params = [];

    // Only count assignments from active periods
    if ($activeCycleNames !== []) {
        $placeholders = [];
        foreach ($activeCycleNames as $i => $name) {
            $key = 'cycle_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $name;
        }
        $where .= ' AND pa.cycle_name IN (' . implode(',', $placeholders) . ')';
    }

    if ($facultyId !== null) {
        $where .= ' AND pa.evaluatee_faculty_id = :faculty_id';
        $params['faculty_id'] = $facultyId;
    }

    $stats = admin_all(
        "SELECT pa.evaluatee_faculty_id AS fid,
                COUNT(*) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count
         FROM peer_assignments pa
         WHERE {$where}
         GROUP BY pa.evaluatee_faculty_id",
        $params
    );

    $stmt = db()->prepare('UPDATE faculty SET progress_percent = :pct WHERE id = :id');
    foreach ($stats as $row) {
        $fid = (int) ($row['fid'] ?? 0);
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted_count'] ?? 0);
        $pct = $total > 0 ? (int) round(($submitted / $total) * 100) : 0;
        if ($fid > 0) {
            $stmt->execute(['pct' => $pct, 'id' => $fid]);
        }
    }

    // Faculty with no peer_assignments in active periods should have 0%
    if ($facultyId === null) {
        if ($activeCycleNames !== []) {
            $placeholders = [];
            $subParams = [];
            foreach ($activeCycleNames as $i => $name) {
                $key = 'sub_cycle_' . $i;
                $placeholders[] = ':' . $key;
                $subParams[$key] = $name;
            }
            $cycleFilter = ' AND pa.cycle_name IN (' . implode(',', $placeholders) . ')';
            $stmtZero = db()->prepare(
                "UPDATE faculty SET progress_percent = 0 WHERE id NOT IN (
                    SELECT DISTINCT pa.evaluatee_faculty_id
                    FROM peer_assignments pa
                    WHERE COALESCE(pa.is_archived, 0) = 0{$cycleFilter}
                ) AND COALESCE(is_archived, 0) = 0"
            );
            $stmtZero->execute($subParams);
        } else {
            db()->exec(
                "UPDATE faculty SET progress_percent = 0 WHERE id NOT IN (
                    SELECT DISTINCT evaluatee_faculty_id
                    FROM peer_assignments
                    WHERE COALESCE(is_archived, 0) = 0
                ) AND COALESCE(is_archived, 0) = 0"
            );
        }
    }
}

function admin_form_b_categories(): array
{
    if (!function_exists('dipascaf_ensure_form_b_schema')) {
        require_once __DIR__ . '/evaluation_cards.php';
    }

    dipascaf_ensure_form_b_schema();

    $categories = admin_all(
        'SELECT c.*,
                (SELECT COUNT(*) FROM pmas_form_b_questions q WHERE q.category_id = c.id AND q.is_active = 1) AS question_count
         FROM pmas_form_b_categories c
         WHERE c.is_active = 1
         ORDER BY c.sort_order, c.id'
    );

    $totalWeight = 0.0;
    foreach ($categories as &$category) {
        $totalWeight += (float) $category['factor_weight'];
    }
    unset($category);

    return ['categories' => $categories, 'total_weight' => $totalWeight];
}

function admin_form_b_questions_by_category(int $categoryId): array
{
    if (!function_exists('dipascaf_ensure_form_b_schema')) {
        require_once __DIR__ . '/evaluation_cards.php';
    }

    dipascaf_ensure_form_b_schema();

    return admin_all(
        'SELECT q.*, c.title AS category_title
         FROM pmas_form_b_questions q
         JOIN pmas_form_b_categories c ON c.id = q.category_id
         WHERE q.category_id = :category_id
         ORDER BY q.sort_order, q.id',
        ['category_id' => $categoryId]
    );
}

function admin_form_b_reports_data(): array
{
    if (!function_exists('dipascaf_ensure_form_b_schema')) {
        require_once __DIR__ . '/evaluation_cards.php';
    }

    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();

    $results = admin_all(
        'SELECT r.*, c.title AS category_title, f.full_name AS faculty_name, f.department,
                f.program_code, u.full_name AS evaluator_name
         FROM pmas_form_b_category_results r
         JOIN pmas_form_b_categories c ON c.id = r.category_id
         JOIN faculty f ON f.id = r.evaluatee_faculty_id
         JOIN peer_assignments pa ON pa.id = r.assignment_id
         LEFT JOIN users u ON u.id = r.evaluator_user_id
         WHERE COALESCE(r.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY r.submitted_at DESC
         LIMIT 200'
    );

    $grouped = [];
    foreach ($results as $row) {
        $key = (string) $row['assignment_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'assignment_id' => (int) $row['assignment_id'],
                'faculty_name' => (string) $row['faculty_name'],
                'department' => (string) $row['department'],
                'program_code' => (string) ($row['program_code'] ?? ''),
                'evaluator_name' => (string) ($row['evaluator_name'] ?? ''),
                'evaluation_period' => (string) $row['evaluation_period'],
                'submitted_at' => (string) $row['submitted_at'],
                'total_weighted_score' => 0.0,
                'categories' => [],
            ];
        }
        $grouped[$key]['categories'][] = [
            'title' => (string) $row['category_title'],
            'average_rating' => (float) $row['average_rating'],
            'factor_weight' => (float) $row['factor_weight'],
            'weighted_score' => (float) $row['weighted_score'],
            'behavioral_evidence' => secure_decrypt_value($row['behavioral_evidence'] ?? ''),
            'reason_for_rating' => secure_decrypt_value($row['reason_for_rating'] ?? ''),
            'recommendation' => secure_decrypt_value($row['recommendation'] ?? ''),
        ];
        $grouped[$key]['total_weighted_score'] += (float) $row['weighted_score'];
    }

    return array_values($grouped);
}
