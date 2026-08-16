<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/notifications.php';
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(static function (Throwable $error): void {
    error_log('Goals Record Sheet API error: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load Goal Record Sheets. Please refresh and try again.',
    ]);
});

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS pmas_goals_records (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, period_id INT NOT NULL,
 template_id INT UNSIGNED NULL, template_version INT UNSIGNED NULL,
 employee_name VARCHAR(190) NOT NULL, position_title VARCHAR(190) NOT NULL DEFAULT '',
 department VARCHAR(190) NOT NULL DEFAULT '', appraisal_period VARCHAR(190) NOT NULL DEFAULT '',
 goals_json LONGTEXT NOT NULL, template_snapshot_json LONGTEXT NULL,
 status ENUM('draft','submitted','under_review','approved','returned','reopened') NOT NULL DEFAULT 'draft',
 reviewer_id INT NULL, reviewer_name VARCHAR(190) NOT NULL DEFAULT '', review_comment TEXT NULL,
 submitted_at DATETIME NULL, reviewed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_goals_user_period (user_id, period_id), KEY idx_goals_status (status), KEY idx_goals_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS pmas_goals_record_revisions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, record_id INT UNSIGNED NOT NULL, revision_no INT NOT NULL,
 snapshot_json LONGTEXT NOT NULL, action VARCHAR(40) NOT NULL, actor_id INT NOT NULL,
 actor_name VARCHAR(190) NOT NULL DEFAULT '', comment TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_goal_revision_record (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS pmas_goals_form_templates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, template_key VARCHAR(80) NOT NULL DEFAULT 'pmas_form_1',
 version INT UNSIGNED NOT NULL DEFAULT 1, template_json LONGTEXT NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_goals_template_version (template_key, version), KEY idx_goals_template_active (template_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function goal_has_column(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }
    $statement = $pdo->prepare(
        'SELECT 1
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table_name
            AND COLUMN_NAME = :column_name
          LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (bool)$statement->fetchColumn();
}

foreach ([
    'template_id' => 'ALTER TABLE pmas_goals_records ADD COLUMN template_id INT UNSIGNED NULL AFTER period_id',
    'template_version' => 'ALTER TABLE pmas_goals_records ADD COLUMN template_version INT UNSIGNED NULL AFTER template_id',
    'template_snapshot_json' => 'ALTER TABLE pmas_goals_records ADD COLUMN template_snapshot_json LONGTEXT NULL AFTER goals_json',
] as $column => $sql) {
    if (!goal_has_column($pdo, 'pmas_goals_records', $column)) {
        $pdo->exec($sql);
    }
}
$pdo->exec("ALTER TABLE pmas_goals_records MODIFY status ENUM('draft','submitted','under_review','approved','returned','reopened') NOT NULL DEFAULT 'draft'");

function goal_default_template(): array
{
    return [
        'formCode' => 'PMAS FORM 1',
        'institution' => 'NOTRE DAME OF MIDSAYAP COLLEGE',
        'title' => 'Goals Record Sheet',
        'instructions' => 'Complete this goal record sheet by formulating the work goals you intend to achieve within the rating period. Align the goals with departmental objectives and organizational directions.',
        'minimumGoals' => 1,
        'totalWeight' => 100,
        'requireTotalWeight' => true,
        'sectionOrder' => ['profile', 'instructions', 'goals', 'approval'],
        'goalFields' => [
            ['key' => 'keyResultArea', 'label' => 'Key Result Area', 'type' => 'text', 'required' => true],
            ['key' => 'goalStatement', 'label' => 'Goal Statement', 'type' => 'textarea', 'required' => true],
            ['key' => 'weight', 'label' => 'Goal Weight', 'type' => 'number', 'required' => true],
        ],
        'standardsTitle' => 'Performance Standards',
        'standardFields' => [
            ['key' => 'exceptional', 'label' => 'Exceptional', 'required' => true],
            ['key' => 'exceeds', 'label' => 'Exceeds Expectations', 'required' => true],
            ['key' => 'meets', 'label' => 'Meets Expectations', 'required' => true],
            ['key' => 'meetsMost', 'label' => 'Meets Most Expectations', 'required' => true],
            ['key' => 'doesNotMeet', 'label' => 'Does Not Meet Expectations', 'required' => true],
        ],
        'approval' => [
            'employeeSubmissionRequired' => true,
            'reviewerApprovalRequired' => true,
            'returnCommentRequired' => true,
            'reopenCommentRequired' => true,
        ],
    ];
}

function goal_clean_template(array $input): array
{
    $default = goal_default_template();
    $legacyInstructions = 'Complete this goal record sheet by formulating at least five work goals that you intend to achieve within the rating period. Align the goals with departmental objectives and organizational directions.';
    $instructions = trim((string)($input['instructions'] ?? $default['instructions']));
    if ($instructions === $legacyInstructions) $instructions = $default['instructions'];
    $clean = [
        'formCode' => trim((string)($input['formCode'] ?? $default['formCode'])),
        'institution' => trim((string)($input['institution'] ?? $default['institution'])),
        'title' => trim((string)($input['title'] ?? $default['title'])),
        'instructions' => $instructions,
        'minimumGoals' => 1,
        'totalWeight' => max(1, min(1000, (float)($input['totalWeight'] ?? 100))),
        'requireTotalWeight' => filter_var($input['requireTotalWeight'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'standardsTitle' => trim((string)($input['standardsTitle'] ?? $default['standardsTitle'])),
    ];
    $requestedSectionOrder = array_values(array_intersect(
        array_map('strval', (array)($input['sectionOrder'] ?? $default['sectionOrder'])),
        ['profile', 'instructions', 'goals', 'approval']
    ));
    $clean['sectionOrder'] = array_values(array_unique(array_merge($requestedSectionOrder, $default['sectionOrder'])));
    $clean['goalFields'] = [];
    $allowedGoalKeys = ['keyResultArea', 'goalStatement', 'weight'];
    foreach ((array)($input['goalFields'] ?? $default['goalFields']) as $field) {
        $key = (string)($field['key'] ?? '');
        if (!in_array($key, $allowedGoalKeys, true)) continue;
        $clean['goalFields'][] = [
            'key' => $key,
            'label' => trim((string)($field['label'] ?? $key)) ?: $key,
            'type' => in_array((string)($field['type'] ?? ''), ['text', 'textarea', 'number'], true) ? (string)$field['type'] : 'text',
            'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
    if ($clean['goalFields'] === []) $clean['goalFields'] = $default['goalFields'];
    $clean['standardFields'] = [];
    foreach ((array)($input['standardFields'] ?? $default['standardFields']) as $index => $field) {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($field['key'] ?? 'standard_' . ($index + 1)));
        if ($key === '') continue;
        $clean['standardFields'][] = [
            'key' => $key,
            'label' => trim((string)($field['label'] ?? $key)) ?: $key,
            'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
    if ($clean['standardFields'] === []) $clean['standardFields'] = $default['standardFields'];
    $approval = is_array($input['approval'] ?? null) ? $input['approval'] : [];
    $clean['approval'] = [
        'employeeSubmissionRequired' => filter_var($approval['employeeSubmissionRequired'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'reviewerApprovalRequired' => filter_var($approval['reviewerApprovalRequired'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'returnCommentRequired' => filter_var($approval['returnCommentRequired'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'reopenCommentRequired' => filter_var($approval['reopenCommentRequired'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ];
    return $clean;
}

function goal_active_template(PDO $pdo): array
{
    $row = $pdo->query("SELECT * FROM pmas_goals_form_templates WHERE template_key='pmas_form_1' AND is_active=1 ORDER BY version DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $template = goal_default_template();
        $stmt = $pdo->prepare("INSERT INTO pmas_goals_form_templates(template_key,version,template_json,is_active) VALUES('pmas_form_1',1,?,1)");
        $stmt->execute([json_encode($template, JSON_UNESCAPED_UNICODE)]);
        return ['id' => (int)$pdo->lastInsertId(), 'version' => 1, 'template' => $template];
    }
    return [
        'id' => (int)$row['id'],
        'version' => (int)$row['version'],
        'template' => goal_clean_template(json_decode((string)$row['template_json'], true) ?: []),
    ];
}

function goal_snapshot(PDO $pdo, int $id, string $action, array $user, string $comment = ''): void
{
    $stmt = $pdo->prepare("SELECT * FROM pmas_goals_records WHERE id=?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) return;
    $next = $pdo->prepare("SELECT COALESCE(MAX(revision_no),0)+1 FROM pmas_goals_record_revisions WHERE record_id=?");
    $next->execute([$id]);
    $pdo->prepare("INSERT INTO pmas_goals_record_revisions(record_id,revision_no,snapshot_json,action,actor_id,actor_name,comment) VALUES(?,?,?,?,?,?,?)")
        ->execute([$id, (int)$next->fetchColumn(), json_encode($data), $action, (int)$user['id'], (string)($user['full_name'] ?? ''), $comment]);
}

function goal_decode_record(array $row): array
{
    $row['goals'] = json_decode((string)$row['goals_json'], true) ?: [];
    $row['templateSnapshot'] = json_decode((string)($row['template_snapshot_json'] ?? ''), true) ?: null;
    unset($row['goals_json'], $row['template_snapshot_json']);
    return $row;
}

function goal_attach_assigned_reviewer(PDO $pdo, array $row): array
{
    $employeeRole = (string)($row['employee_role'] ?? '');
    $assignedName = null;
    $periodContext = null;
    if ((int)($row['period_id'] ?? 0) > 0 && (int)($row['user_id'] ?? 0) > 0) {
        $contextStmt = $pdo->prepare('SELECT role_snapshot,department_id,program_id FROM evaluation_period_participation WHERE evaluation_period_id=? AND user_id=? LIMIT 1');
        $contextStmt->execute([(int)$row['period_id'], (int)$row['user_id']]);
        $periodContext = $contextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($periodContext && (string)$periodContext['role_snapshot'] !== '') $employeeRole = (string)$periodContext['role_snapshot'];
    }
    if ($employeeRole === '') {
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $roleStmt->execute([(int)($row['user_id'] ?? 0)]);
        $employeeRole = (string)($roleStmt->fetchColumn() ?: '');
    }
    if ($employeeRole === 'dean') {
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE role='vpaa' AND id<>? ORDER BY id LIMIT 1");
        $stmt->execute([(int)($row['user_id'] ?? 0)]);
        $assignedRole = 'VPAA';
    } else {
        $stmt = $pdo->prepare(
            "SELECT u.full_name
               FROM evaluation_period_deans epd
               JOIN users u ON u.id=epd.user_id AND u.is_active=1
              WHERE epd.evaluation_period_id=?
                AND epd.department_id=COALESCE(?,epd.department_id)
                AND epd.user_id<>?
              ORDER BY epd.is_acting DESC,epd.id
              LIMIT 1"
        );
        $stmt->execute([(int)$row['period_id'], $periodContext['department_id'] ?? null, (int)($row['user_id'] ?? 0)]);
        $assignedRole = 'Dean';
        $assignedName = (string)($stmt->fetchColumn() ?: '');
        if ($assignedName === '') {
            $stmt = $pdo->prepare("SELECT full_name FROM users WHERE role='dean' AND department=? AND is_active=1 AND id<>? ORDER BY id LIMIT 1");
            $stmt->execute([(string)($row['department'] ?? ''), (int)($row['user_id'] ?? 0)]);
            $assignedName = (string)($stmt->fetchColumn() ?: '');
        }
    }
    $row['assigned_reviewer_name'] = $assignedName ?? (string)($stmt->fetchColumn() ?: '');
    $row['assigned_reviewer_role'] = $assignedRole;
    return $row;
}

function goal_notify_assigned_reviewer(PDO $pdo, array $record, string $title, string $message, string $eventKey): array
{
    $assigned = goal_attach_assigned_reviewer($pdo, $record);
    $name = trim((string)($assigned['assigned_reviewer_name'] ?? ''));
    if ($name === '') return notify_delivery_result(false, null, 'no_recipient', 'No assigned reviewer was found.');
    $stmt = $pdo->prepare('SELECT id,role FROM users WHERE full_name=? AND is_active=1 LIMIT 1');
    $stmt->execute([$name]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $recipientId = (int)($recipient['id'] ?? 0);
    $actionUrl = match ((string)($recipient['role'] ?? '')) {
        'vpaa' => '/vpaa/self-evaluation-review',
        'dean' => '/dean/self-evaluation-review',
        default => '/faculty/evaluate',
    };
    return notify_send_with_result([
        'recipient_id' => $recipientId,
        'type' => 'approval',
        'title' => $title,
        'message' => $message,
        'action_url' => $actionUrl,
        'module' => 'goals_records',
        'related_record_id' => (int)$record['id'],
        'event_key' => $eventKey,
    ]);
}

function goal_can_review(PDO $pdo, array $reviewer, array $record): bool
{
    if ((int)$reviewer['id'] === (int)$record['user_id']) return false;
    $role = (string)($reviewer['role'] ?? '');
    $employeeRole = (string)($record['employee_role'] ?? '');
    if ($role === 'vpaa') return $employeeRole === 'dean';
    if ($role === 'dean') {
        if (!in_array($employeeRole, ['teacher', 'faculty', 'program_head'], true)) return false;

        $periodId = (int)($record['period_id'] ?? 0);
        $employeeId = (int)($record['user_id'] ?? 0);
        if ($periodId > 0 && $employeeId > 0) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM evaluation_period_deans epd
                   JOIN evaluation_period_participation epp
                     ON epp.evaluation_period_id=epd.evaluation_period_id
                    AND epp.department_id=epd.department_id
                  WHERE epd.evaluation_period_id=?
                    AND epd.user_id=?
                    AND epp.user_id=?"
            );
            $stmt->execute([$periodId, (int)$reviewer['id'], $employeeId]);
            if ((int)$stmt->fetchColumn() > 0) return true;
        }

        // Compatibility fallback for historical periods without assignment snapshots.
        $reviewerDepartment = strtolower(trim((string)($reviewer['department'] ?? '')));
        $recordDepartment = strtolower(trim((string)($record['department'] ?? '')));
        return $reviewerDepartment !== '' && hash_equals($reviewerDepartment, $recordDepartment);
    }
    return false;
}

$role = (string)($user['role'] ?? '');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');
$activeTemplate = goal_active_template($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_template') {
    if ($role !== 'admin_hr') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only the administrator can update the Goals Record Sheet template.']);
        exit;
    }
    $template = goal_clean_template(is_array($input['template'] ?? null) ? $input['template'] : []);
    $pdo->beginTransaction();
    try {
        $nextVersion = (int)$pdo->query("SELECT COALESCE(MAX(version),0)+1 FROM pmas_goals_form_templates WHERE template_key='pmas_form_1' FOR UPDATE")->fetchColumn();
        $pdo->exec("UPDATE pmas_goals_form_templates SET is_active=0 WHERE template_key='pmas_form_1'");
        $stmt = $pdo->prepare("INSERT INTO pmas_goals_form_templates(template_key,version,template_json,is_active,updated_by) VALUES('pmas_form_1',?,?,1,?)");
        $stmt->execute([$nextVersion, json_encode($template, JSON_UNESCAPED_UNICODE), (int)$user['id']]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Goals Record Sheet template saved and published.', 'template' => $template, 'version' => $nextVersion]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    exit;
}

$periodId = (int)($_GET['period_id'] ?? $input['period_id'] ?? 0);
if ($periodId <= 0) {
    $periodId = (int)($pdo->query("SELECT id FROM appraisal_periods WHERE status='open' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
}
$periodStmt = $periodId ? $pdo->prepare("SELECT * FROM appraisal_periods WHERE id=?") : null;
if ($periodStmt) {
    $periodStmt->execute([$periodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} else {
    $period = [];
}
if ($periodId <= 0 || !$period) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Select a valid Self-Evaluation Review period.']);
    exit;
}
$periodLabel = (string)($period['period_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['mode'] ?? 'mine');
    if ($mode === 'template') {
        echo json_encode(['ok' => true, 'template' => $activeTemplate['template'], 'templateId' => $activeTemplate['id'], 'version' => $activeTemplate['version']]);
        exit;
    }
    if ($mode === 'mine') {
        $stmt = $pdo->prepare("SELECT * FROM pmas_goals_records WHERE user_id=? AND period_id=?");
        $stmt->execute([(int)$user['id'], $periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'period' => $period, 'record' => $row ? goal_decode_record(goal_attach_assigned_reviewer($pdo, $row)) : null, 'template' => $activeTemplate['template'], 'templateVersion' => $activeTemplate['version']]);
        exit;
    }
    $stats = [];
    if ($role === 'admin_hr') {
        $stmt = $pdo->prepare("SELECT status,COUNT(*) total FROM pmas_goals_records WHERE period_id=? GROUP BY status");
        $stmt->execute([$periodId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) $stats[(string)$item['status']] = (int)$item['total'];
    }
    $sql = "SELECT g.*,u.role employee_role FROM pmas_goals_records g JOIN users u ON u.id=g.user_id WHERE g.period_id=?";
    $params = [$periodId];
    if ($role === 'dean') {
        $sql .= " AND g.department=? AND u.role IN('teacher','faculty','program_head')";
        $params[] = (string)($user['department'] ?? '');
    } elseif ($role === 'vpaa') {
        $sql .= " AND u.role='dean'";
    } elseif ($role === 'admin_hr') {
        $sql .= " AND g.status='approved'";
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Forbidden']);
        exit;
    }
    if (in_array($role, ['dean', 'vpaa'], true)) {
        $mark = $pdo->prepare("UPDATE pmas_goals_records SET status='under_review' WHERE period_id=? AND status='submitted'");
        $mark->execute([$periodId]);
    }
    $sql .= " ORDER BY g.updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = array_map(fn($row) => goal_decode_record(goal_attach_assigned_reviewer($pdo, $row)), $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['ok' => true, 'period' => $period, 'records' => $records, 'stats' => $stats, 'template' => $activeTemplate['template'], 'templateVersion' => $activeTemplate['version']]);
    exit;
}

if (in_array($action, ['save', 'submit'], true)) {
    $template = $activeTemplate['template'];
    $goals = is_array($input['goals'] ?? null) ? $input['goals'] : [];
    $clean = [];
    $seen = [];
    $total = 0.0;
    foreach ($goals as $goal) {
        $standards = [];
        foreach ($template['standardFields'] as $field) {
            $standards[$field['key']] = trim((string)($goal['standards'][$field['key']] ?? ''));
        }
        $item = [
            'keyResultArea' => trim((string)($goal['keyResultArea'] ?? '')),
            'goalStatement' => trim((string)($goal['goalStatement'] ?? '')),
            'weight' => (float)($goal['weight'] ?? 0),
            'standards' => $standards,
        ];
        if ($item['goalStatement'] !== '') {
            $key = mb_strtolower($item['goalStatement']);
            if (isset($seen[$key])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Duplicate goal statements are not allowed.']);
                exit;
            }
            $seen[$key] = true;
        }
        $total += $item['weight'];
        $clean[] = $item;
    }
    if ($action === 'submit') {
        if (count($clean) < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Enter a work goal before submitting.']);
            exit;
        }
        foreach ($clean as $goal) {
            foreach ($template['goalFields'] as $field) {
                if (!$field['required']) continue;
                $value = $goal[$field['key']] ?? '';
                if (($field['key'] === 'weight' && (float)$value <= 0) || ($field['key'] !== 'weight' && trim((string)$value) === '')) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'message' => 'Complete every required goal field.']);
                    exit;
                }
            }
            foreach ($template['standardFields'] as $field) {
                if ($field['required'] && trim((string)($goal['standards'][$field['key']] ?? '')) === '') {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'message' => 'Complete every required performance standard.']);
                    exit;
                }
            }
        }
        if ($template['requireTotalWeight'] && abs($total - (float)$template['totalWeight']) > 0.001) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Combined goal weights must equal ' . $template['totalWeight'] . '%.']);
            exit;
        }
    }
    $existing = $pdo->prepare("SELECT id,status FROM pmas_goals_records WHERE user_id=? AND period_id=?");
    $existing->execute([(int)$user['id'], $periodId]);
    $old = $existing->fetch(PDO::FETCH_ASSOC);
    if ($old && !in_array((string)$old['status'], ['draft', 'returned', 'reopened'], true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'This Goals Record Sheet can no longer be edited.']);
        exit;
    }
    $status = $action === 'submit' ? 'submitted' : 'draft';
    $position = (string)($user['position_title'] ?? ucwords(str_replace('_', ' ', $role)));
    $templateJson = json_encode($template, JSON_UNESCAPED_UNICODE);
    if ($old) {
        goal_snapshot($pdo, (int)$old['id'], $action, $user);
        $stmt = $pdo->prepare("UPDATE pmas_goals_records SET goals_json=?,template_id=?,template_version=?,template_snapshot_json=?,status=?,review_comment=NULL,submitted_at=IF(?='submitted',NOW(),submitted_at) WHERE id=?");
        $stmt->execute([json_encode($clean), $activeTemplate['id'], $activeTemplate['version'], $templateJson, $status, $status, (int)$old['id']]);
        $id = (int)$old['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO pmas_goals_records(user_id,period_id,template_id,template_version,employee_name,position_title,department,appraisal_period,goals_json,template_snapshot_json,status,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,IF(?='submitted',NOW(),NULL))");
        $stmt->execute([(int)$user['id'], $periodId, $activeTemplate['id'], $activeTemplate['version'], (string)$user['full_name'], $position, (string)($user['department'] ?? ''), $periodLabel, json_encode($clean), $templateJson, $status, $status]);
        $id = (int)$pdo->lastInsertId();
    }
    $delivery = null;
    if ($action === 'submit') {
        $recordStmt = $pdo->prepare("SELECT g.*,u.role employee_role FROM pmas_goals_records g JOIN users u ON u.id=g.user_id WHERE g.id=?");
        $recordStmt->execute([$id]);
        $record = $recordStmt->fetch(PDO::FETCH_ASSOC);
        if ($record) $delivery = goal_notify_assigned_reviewer($pdo, $record, 'Goals Record Sheet Pending Review', (string)$user['full_name'] . ' submitted a Goals Record Sheet for review.', 'goals:submitted:' . $id . ':' . time());
    }
    echo json_encode(['ok' => true, 'message' => $action === 'submit' ? 'Goals Record Sheet submitted for review.' : 'Draft saved.', 'record_id' => $id, 'notification_delivery' => $delivery]);
    exit;
}

if ($action === 'reviewer_save') {
    $id = (int)($input['record_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT g.*,u.role employee_role FROM pmas_goals_records g JOIN users u ON u.id=g.user_id WHERE g.id=? AND g.period_id=?");
    $stmt->execute([$id, $periodId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !goal_can_review($pdo, $user, $row)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You are not authorized to edit this Goals Record Sheet.']);
        exit;
    }
    if (!in_array((string)$row['status'], ['submitted', 'under_review', 'reopened'], true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Only a submitted, under-review, or reopened record can be edited.']);
        exit;
    }
    $template = goal_clean_template(json_decode((string)($row['template_snapshot_json'] ?? ''), true) ?: $activeTemplate['template']);
    $goals = is_array($input['goals'] ?? null) ? $input['goals'] : [];
    $clean = [];
    $total = 0.0;
    foreach ($goals as $goal) {
        $standards = [];
        foreach ($template['standardFields'] as $field) {
            $standards[$field['key']] = trim((string)($goal['standards'][$field['key']] ?? ''));
        }
        $item = [
            'keyResultArea' => trim((string)($goal['keyResultArea'] ?? '')),
            'goalStatement' => trim((string)($goal['goalStatement'] ?? '')),
            'weight' => (float)($goal['weight'] ?? 0),
            'standards' => $standards,
        ];
        foreach ($template['goalFields'] as $field) {
            $value = $item[$field['key']] ?? '';
            if ($field['required'] && (($field['key'] === 'weight' && (float)$value <= 0) || ($field['key'] !== 'weight' && trim((string)$value) === ''))) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Complete every required goal field before saving.']);
                exit;
            }
        }
        foreach ($template['standardFields'] as $field) {
            if ($field['required'] && $standards[$field['key']] === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Complete every required performance standard before saving.']);
                exit;
            }
        }
        $total += $item['weight'];
        $clean[] = $item;
    }
    if ($clean === []) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'At least one work goal is required.']);
        exit;
    }
    if ($template['requireTotalWeight'] && abs($total - (float)$template['totalWeight']) > 0.001) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Combined goal weights must equal ' . $template['totalWeight'] . '%.']);
        exit;
    }
    goal_snapshot($pdo, $id, 'reviewer_edit', $user, 'Goals edited by assigned reviewer.');
    $stmt = $pdo->prepare("UPDATE pmas_goals_records SET goals_json=?,reviewer_id=?,reviewer_name=? WHERE id=?");
    $stmt->execute([json_encode($clean), (int)$user['id'], (string)$user['full_name'], $id]);
    echo json_encode(['ok' => true, 'message' => 'Reviewer changes saved.', 'goals' => $clean]);
    exit;
}

if (in_array($action, ['approve', 'return', 'reopen', 'resubmit'], true)) {
    $id = (int)($input['record_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT g.*,u.role employee_role FROM pmas_goals_records g JOIN users u ON u.id=g.user_id WHERE g.id=? AND g.period_id=?");
    $stmt->execute([$id, $periodId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !goal_can_review($pdo, $user, $row)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You are not authorized to review this Goals Record Sheet.']);
        exit;
    }
    $comment = trim((string)($input['comment'] ?? ''));
    $approval = $activeTemplate['template']['approval'];
    if (($action === 'return' && $approval['returnCommentRequired']) || ($action === 'reopen' && $approval['reopenCommentRequired'])) {
        if ($comment === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'A reviewer comment is required for this action.']);
            exit;
        }
    }
    if ($action === 'approve' && !in_array((string)$row['status'], ['submitted', 'under_review'], true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Only a submitted record can be approved.']);
        exit;
    }
    if ($action === 'reopen' && (string)$row['status'] !== 'approved') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Only an approved record can be reopened.']);
        exit;
    }
    if ($action === 'resubmit' && (string)$row['status'] !== 'reopened') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Only a reopened record can be re-submitted.']);
        exit;
    }
    $status = ['approve' => 'approved', 'return' => 'returned', 'reopen' => 'reopened', 'resubmit' => 'submitted'][$action];
    $pdo->beginTransaction();
    try {
        goal_snapshot($pdo, $id, $action, $user, $comment);
        $stmt = $pdo->prepare("UPDATE pmas_goals_records SET status=?,reviewer_id=?,reviewer_name=?,review_comment=?,reviewed_at=NOW() WHERE id=?");
        $stmt->execute([$status, (int)$user['id'], (string)$user['full_name'], $comment, $id]);
        $verified = $pdo->prepare('SELECT status,reviewer_id,reviewer_name,review_comment,reviewed_at FROM pmas_goals_records WHERE id=? FOR UPDATE');
        $verified->execute([$id]);
        $savedReview = $verified->fetch(PDO::FETCH_ASSOC);
        if (!$savedReview || (string)$savedReview['status'] !== $status || (int)$savedReview['reviewer_id'] !== (int)$user['id']) {
            throw new RuntimeException('The review status could not be verified after saving.');
        }
        $pdo->commit();
    } catch (Throwable $reviewError) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $reviewError;
    }
    $messages = ['approve' => 'Goals Record Sheet approved.', 'return' => 'Goals Record Sheet returned for revision.', 'reopen' => 'Goals Record Sheet reopened for revision.', 'resubmit' => 'Goals Record Sheet re-submitted for review.'];
    try {
        if ($action === 'resubmit') {
            $delivery = goal_notify_assigned_reviewer($pdo, $row, 'Goals Record Sheet Re-submitted', (string)$row['employee_name'] . ' re-submitted a Goals Record Sheet.', 'goals:resubmitted:' . $id . ':' . time());
        } else {
            $delivery = notify_send_with_result([
                'recipient_id' => (int)$row['user_id'],
                'type' => $action === 'approve' ? 'success' : 'revision',
                'title' => $messages[$action],
                'message' => $comment !== '' ? $comment : $messages[$action],
                'action_url' => '/faculty/evaluate',
                'module' => 'goals_records',
                'related_record_id' => $id,
                'event_key' => 'goals:' . $action . ':' . $id . ':' . time(),
            ]);
        }
    } catch (Throwable $notificationError) {
        error_log('Goals Record Sheet notification failed after ' . $action . ': ' . $notificationError->getMessage());
        $delivery = notify_delivery_result(false, null, 'delivery_error', 'Record updated, but the notification could not be delivered.');
    }
    echo json_encode(['ok' => true, 'message' => $messages[$action], 'review' => $savedReview, 'notification_delivery' => $delivery]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
