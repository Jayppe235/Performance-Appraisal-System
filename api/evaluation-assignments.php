<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';
require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

try {
    require_role('admin_hr');

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            handleCreateSchedule();
            break;
        case 'GET':
            $action = $_GET['action'] ?? 'list';
            if ($action === 'periods') {
                handleListPeriods();
            } else {
                handleListSchedules();
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            if (($input['action'] ?? '') === 'update') {
                handleUpdateSchedule($input);
            } else {
                handleRestoreSchedule($input);
            }
            break;
        case 'DELETE':
            handleDeleteSchedule();
            break;
        default:
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/**
 * Handle creating a new evaluation schedule and auto-generating assignments.
 * Expects JSON body:
 * {
 *   "school_year": string,
 *   "semester": string,
 *   "period_name": string,
 *   "date_start": "YYYY-MM-DD",
 *   "due_date": "YYYY-MM-DD"
 * }
 * The appraisal period is auto-created or updated from the submitted period details.
 */
function handleCreateSchedule(): void
{
    $input = json_decode(file_get_contents('php://input'), true);

    $periodName = trim((string) ($input['period_name'] ?? ''));
    $schoolYear = trim((string) ($input['school_year'] ?? ''));
    $semester = trim((string) ($input['semester'] ?? ''));
    $dateStart = trim((string) ($input['date_start'] ?? $input['start_date'] ?? ''));
    $periodId = (int) ($input['period_id'] ?? 0);
    $dueDate = trim((string) ($input['due_date'] ?? $input['date_end'] ?? ''));

    if (!$periodName && $periodId <= 0) {
        $currentPeriod = dipascaf_current_evaluation_period();
        $periodName = trim((string) ($currentPeriod['period_name'] ?? ''));
        $schoolYear = trim((string) ($currentPeriod['school_year'] ?? ''));
        $semester = trim((string) ($currentPeriod['semester'] ?? ''));
        $dateStart = trim((string) ($currentPeriod['date_start'] ?? ''));
        $periodId = (int) ($currentPeriod['id'] ?? 0);
    }

    if ((!$periodName && $periodId <= 0) || $schoolYear === '' || $semester === '' || $dateStart === '' || $dueDate === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'School year, semester, period name, start date, and due date are required.']);
        return;
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Start date and due date must be in YYYY-MM-DD format.']);
        return;
    }

    if (strtotime($dueDate) < strtotime($dateStart)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Due date cannot be earlier than the start date.']);
        return;
    }

    $db = db();
    dipascaf_ensure_evaluation_period_schema();

    // Find or create the appraisal period by name
    $period = $periodId > 0
        ? admin_one("SELECT id, period_name, status FROM appraisal_periods WHERE id = ?", [$periodId])
        : admin_one("SELECT id, period_name, status FROM appraisal_periods WHERE period_name = ?", [$periodName]);

    if (!$period) {
        // Auto-create the period from the submitted schedule details.
        $db->prepare("
            INSERT INTO appraisal_periods (period_name, school_year, semester, date_start, date_end, status)
            VALUES (?, ?, ?, ?, ?, 'open')
        ")->execute([$periodName, $schoolYear, $semester, $dateStart, $dueDate]);
        $period = [
            'id' => $db->lastInsertId(),
            'period_name' => $periodName,
            'status' => 'open',
        ];
    } else {
        $db->prepare("
            UPDATE appraisal_periods
            SET period_name = ?, school_year = ?, semester = ?, date_start = ?, date_end = ?
            WHERE id = ?
        ")->execute([$periodName, $schoolYear, $semester, $dateStart, $dueDate, (int) $period['id']]);
        $period['period_name'] = $periodName;
    }

    $evaluationPeriodId = (int) $period['id'];

    // Check if a schedule already exists for this period
    $existing = admin_one(
        "SELECT id FROM evaluation_schedules WHERE evaluation_period_id = ?",
        [$evaluationPeriodId]
    );

    if ($existing) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'A schedule already exists for this evaluation period.']);
        return;
    }

    $createdBy = (int) ($_SESSION['user']['id'] ?? 0);

    // Generate non-peer assignments here. Department peer-to-peer assignments
    // are generated separately by each Dean from the Dean dashboard.
    $assignments = dipascaf_required_evaluation_assignments($evaluationPeriodId);

    $db->beginTransaction();
    try {
        // Save the evaluation schedule
        $stmt = $db->prepare("
            INSERT INTO evaluation_schedules (evaluation_period_id, due_date, status, total_assignments, created_by)
            VALUES (?, ?, 'active', ?, ?)
        ");
        $stmt->execute([$evaluationPeriodId, $dueDate, count($assignments), $createdBy]);
        $scheduleId = $db->lastInsertId();

        // Insert generated assignments
        if (!empty($assignments)) {
            dipascaf_upsert_required_assignments_for_period((string) $period['period_name'], $dueDate);
        }

        $db->commit();

        echo json_encode([
            'ok' => true,
            'data' => [
                'schedule_id' => (int) $scheduleId,
                'evaluation_period_id' => $evaluationPeriodId,
                'period_name' => $period['period_name'],
                'due_date' => $dueDate,
                'total_assignments' => count($assignments),
                'status' => 'active',
            ],
            'message' => 'Evaluation assignment schedule created successfully.',
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Generate evaluation assignments based on existing evaluation_rules and user data.
 *
 * Algorithm:
 * 1. Get all active evaluation rules
 * 2. For each rule, find matching evaluator/evaluatee pairs by role
 * 3. Group by department for peer evaluations
 * 4. Determine questionnaire type based on role relationship
 */
function generateAssignments(int $evaluationPeriodId, string $dueDate): array
{
    $db = db();

    // Get all active rules
    $rules = admin_all(
        "SELECT * FROM evaluation_rules WHERE is_active = 1"
    );

    if (empty($rules)) {
        return [];
    }

    // Get all active users joined to their faculty records via user_id FK
    $users = admin_all(
        "SELECT u.id, u.full_name, u.role, u.email,
                f.id AS faculty_id, f.department
         FROM users u
         JOIN faculty f ON f.user_id = u.id
         WHERE u.role IN ('vpaa', 'dean', 'program_head', 'teacher')
         ORDER BY u.role, f.department"
    );

    if (empty($users)) {
        return [];
    }

    $assignments = [];
    $seen = [];
    $userToFaculty = [];
    foreach ($users as $u) {
        if ($u['faculty_id']) {
            $userToFaculty[(int) $u['id']] = (int) $u['faculty_id'];
        }
    }

    $sameDepartment = static function (array $evaluator, array $evaluatee): bool {
        $aliases = array_map(
            static fn (string $department): string => strtolower($department),
            admin_matching_department_aliases((string) ($evaluator['department'] ?? ''))
        );

        return in_array(strtolower((string) ($evaluatee['department'] ?? '')), $aliases, true);
    };

    foreach ($rules as $rule) {
        $evalRole = $rule['evaluator_role'];
        $evalteeRole = $rule['evaluatee_role'];
        $assignType = $rule['assignment_type'];

        if ($assignType === 'peer') {
            // Peer work is handled separately by Deans in their own department scope.
            continue;
        }

        foreach ($users as $eval) {
            if ((string) $eval['role'] !== (string) $evalRole) {
                continue;
            }

            foreach ($users as $evaltee) {
                if ((string) $evaltee['role'] !== (string) $evalteeRole) {
                    continue;
                }

                $evalFacultyId = $userToFaculty[(int) $eval['id']] ?? null;
                $evalteeFacultyId = (int) $evaltee['faculty_id'];

                if ($assignType === 'self') {
                    if ($evalFacultyId !== $evalteeFacultyId) {
                        continue;
                    }
                } else {
                    if ($evalFacultyId && $evalFacultyId === $evalteeFacultyId) {
                        continue;
                    }
                    if (!dipascaf_assignment_relationship_allowed([
                        'evaluatee_faculty_id' => $evalteeFacultyId,
                        'assignment_type' => $assignType,
                    ], (int) $eval['id'], (string) $eval['role'])) {
                        continue;
                    }
                }

                $key = $eval['id'] . '-' . $evalteeFacultyId . '-' . $assignType;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $assignments[] = [
                    'evaluator_user_id' => (int) $eval['id'],
                    'evaluatee_faculty_id' => $evalteeFacultyId,
                    'evaluator_role' => $eval['role'],
                    'assignment_type' => $assignType,
                    'questionnaire_type' => getQuestionnaireType($eval['role'], $evaltee['role']),
                ];
            }
        }
    }

    return $assignments;
}

/**
 * Determine the questionnaire type based on evaluator and evaluatee roles.
 *
 * Rules:
 * - If evaluatee is 'teacher' (faculty): PMAS Form B / Faculty Evaluation
 * - If evaluatee is 'dean' or 'program_head': PMAS Form A / Administrative Evaluation
 */
function getQuestionnaireType(string $evaluatorRole, string $evaluateeRole): string
{
    // Evaluatee is a teacher/faculty member -> Form B (Faculty Evaluation)
    if ($evaluateeRole === 'teacher') {
        return 'faculty';
    }

    // Evaluatee is Dean or Program Head -> Form A (Administrative Evaluation)
    // This covers:
    // - Dean evaluates Program Head -> Form A
    // - Program Head evaluates Dean -> Form A
    // - Faculty evaluates Dean or Program Head -> Form A
    return 'admin';
}

/**
 * GET: List all evaluation schedules with stats.
 */
function handleListSchedules(): void
{
    $schedules = admin_all("
        SELECT
            s.id,
            s.evaluation_period_id,
            s.due_date,
            s.status,
            s.total_assignments,
            s.created_by,
            s.created_at,
            s.updated_at,
            ap.period_name AS evaluation_period_name,
            ap.school_year,
            ap.semester,
            ap.date_start AS period_start,
            ap.date_end AS period_end,
            u.full_name AS created_by_name
        FROM evaluation_schedules s
        JOIN appraisal_periods ap ON ap.id = s.evaluation_period_id
        JOIN users u ON u.id = s.created_by
        ORDER BY s.created_at DESC
    ");

    echo json_encode(['ok' => true, 'data' => $schedules]);
}

/**
 * GET: List all available appraisal periods.
 */
function handleListPeriods(): void
{
    $periods = admin_all("
        SELECT id, period_name, school_year, semester, date_start, date_end, status
        FROM appraisal_periods
        WHERE status IN ('open', 'draft')
        ORDER BY date_start DESC
    ");

    echo json_encode(['ok' => true, 'data' => $periods]);
}

function evaluation_schedule_date_label(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M d, Y', $timestamp) : $date;
}

function evaluation_schedule_notify_date_change(array $recipients, string $periodName, string $oldStartDate, string $newStartDate, string $oldDueDate, string $newDueDate, int $periodId): int
{
    if ($recipients === []) {
        return 0;
    }

    $parts = [];
    if ($oldStartDate !== $newStartDate) {
        $parts[] = 'start date from ' . evaluation_schedule_date_label($oldStartDate) . ' to ' . evaluation_schedule_date_label($newStartDate);
    }
    if ($oldDueDate !== $newDueDate) {
        $parts[] = 'deadline from ' . evaluation_schedule_date_label($oldDueDate) . ' to ' . evaluation_schedule_date_label($newDueDate);
    }

    if ($parts === []) {
        return 0;
    }

    $roleLinks = [
        'teacher' => '/faculty/evaluate',
        'faculty' => '/faculty/evaluate',
        'program_head' => '/program-head/evaluate',
        'dean' => '/dean/evaluate',
        'vpaa' => '/vpaa/analytics',
    ];

    $message = 'The evaluation schedule for ' . $periodName . ' was updated: '
        . implode(' and ', $parts)
        . '. Please review your pending evaluation assignments.';

    $sent = 0;
    foreach ($recipients as $recipient) {
        $userId = (int) ($recipient['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $role = strtolower(trim((string) ($recipient['role'] ?? 'teacher')));
        $link = $roleLinks[$role] ?? '/faculty/evaluate';

        if (notify_send([
            'recipient_id' => $userId,
            'type' => 'evaluation',
            'title' => 'Evaluation Schedule Updated',
            'message' => $message,
            'action_url' => $link,
            'module' => 'evaluation_period',
            'related_record_id' => $periodId,
            'dedupe' => false,
        ]) !== null) {
            $sent++;
        }
    }

    return $sent;
}

/**
 * DELETE: Delete a schedule and its associated assignments.
 */
function handleDeleteSchedule(): void
{
    $scheduleId = $_GET['id'] ?? null;

    if (!$scheduleId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Schedule ID is required.']);
        return;
    }

    $db = db();

    // Get the schedule to find the period name
    $schedule = admin_one(
        "SELECT s.*, ap.period_name
         FROM evaluation_schedules s
         JOIN appraisal_periods ap ON ap.id = s.evaluation_period_id
         WHERE s.id = ?",
        [$scheduleId]
    );

    if (!$schedule) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
        return;
    }

    $db->beginTransaction();
    try {
        dipascaf_ensure_peer_evaluation_schema();
        // Keep historical assignments/results intact. Only cancel the schedule record.
        $db->prepare("UPDATE evaluation_schedules SET status = 'cancelled' WHERE id = ?")
           ->execute([$scheduleId]);

        $db->commit();

        echo json_encode(['ok' => true, 'message' => 'Schedule cancelled. Historical assignments and submitted results were preserved.']);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * PUT: Update a schedule period and due date without regenerating assignments.
 */
function handleUpdateSchedule(?array $input = null): void
{
    $input = $input ?? json_decode(file_get_contents('php://input'), true) ?? [];
    $scheduleId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    $periodName = trim((string) ($input['period_name'] ?? ''));
    $schoolYear = trim((string) ($input['school_year'] ?? ''));
    $semester = trim((string) ($input['semester'] ?? ''));
    $dateStart = trim((string) ($input['date_start'] ?? $input['start_date'] ?? ''));
    $dueDate = trim((string) ($input['due_date'] ?? $input['date_end'] ?? ''));

    if ($scheduleId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Schedule ID is required.']);
        return;
    }

    if ($periodName === '' || $schoolYear === '' || $semester === '' || $dateStart === '' || $dueDate === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'School year, semester, period name, start date, and due date are required.']);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Start date and due date must be in YYYY-MM-DD format.']);
        return;
    }

    if (strtotime($dueDate) < strtotime($dateStart)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Due date cannot be earlier than the start date.']);
        return;
    }

    $db = db();
    dipascaf_ensure_evaluation_period_schema();

    $schedule = admin_one(
        "SELECT
            s.*,
            ap.id AS period_id,
            ap.period_name,
            ap.date_start AS period_start,
            ap.date_end AS period_end
         FROM evaluation_schedules s
         JOIN appraisal_periods ap ON ap.id = s.evaluation_period_id
         WHERE s.id = ?",
        [$scheduleId]
    );

    if (!$schedule) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
        return;
    }

    $oldPeriodName = (string) ($schedule['period_name'] ?? '');
    $oldStartDate = (string) ($schedule['period_start'] ?? '');
    $oldDueDate = (string) ($schedule['due_date'] ?? $schedule['period_end'] ?? '');
    $dateChanged = $oldStartDate !== $dateStart || $oldDueDate !== $dueDate;
    $affectedRecipients = [];

    if ($dateChanged && $oldPeriodName !== '') {
        $affectedRecipients = admin_all(
            "SELECT DISTINCT u.id, u.role
             FROM peer_assignments pa
             JOIN users u ON u.id = pa.evaluator_user_id
             WHERE pa.cycle_name = ?
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status <> 'submitted'
               AND u.is_active = 1",
            [$oldPeriodName]
        );
    }

    $duplicate = admin_one(
        "SELECT id FROM appraisal_periods WHERE period_name = ? AND id <> ? LIMIT 1",
        [$periodName, (int) $schedule['period_id']]
    );

    if ($duplicate) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Another schedule period already uses this period name.']);
        return;
    }

    $db->beginTransaction();
    try {
        dipascaf_ensure_peer_evaluation_schema();

        $db->prepare("
            UPDATE appraisal_periods
            SET period_name = ?, school_year = ?, semester = ?, date_start = ?, date_end = ?
            WHERE id = ?
        ")->execute([$periodName, $schoolYear, $semester, $dateStart, $dueDate, (int) $schedule['period_id']]);

        $db->prepare("UPDATE evaluation_schedules SET due_date = ? WHERE id = ?")
           ->execute([$dueDate, $scheduleId]);

        $deadlineSync = dipascaf_sync_period_assignment_deadlines(
            $periodName,
            $dueDate,
            $oldPeriodName,
            (int) $schedule['period_id']
        );

        $db->commit();

        $notificationCount = 0;
        if ($dateChanged) {
            try {
                $notificationCount = evaluation_schedule_notify_date_change(
                    $affectedRecipients,
                    $periodName,
                    $oldStartDate,
                    $dateStart,
                    $oldDueDate,
                    $dueDate,
                    (int) $schedule['period_id']
                );
            } catch (Throwable $notificationError) {
                notify_log('Schedule update notification failed: ' . $notificationError->getMessage());
            }
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Schedule period updated successfully.',
            'notifications_sent' => $notificationCount,
            'deadline_sync' => $deadlineSync,
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * PUT: Restore a cancelled schedule back to active status.
 * Expects JSON body with schedule id.
 */
function handleRestoreSchedule(?array $input = null): void
{
    $input = $input ?? json_decode(file_get_contents('php://input'), true) ?? [];
    $scheduleId = (int) ($input['id'] ?? $_GET['id'] ?? 0);

    if (!$scheduleId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Schedule ID is required.']);
        return;
    }

    $db = db();

    $schedule = admin_one(
        "SELECT s.*, ap.period_name
         FROM evaluation_schedules s
         JOIN appraisal_periods ap ON ap.id = s.evaluation_period_id
         WHERE s.id = ?",
        [$scheduleId]
    );

    if (!$schedule) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Schedule not found.']);
        return;
    }

    if ($schedule['status'] !== 'cancelled') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only cancelled schedules can be restored.']);
        return;
    }

    $db->prepare("UPDATE evaluation_schedules SET status = 'active' WHERE id = ?")
       ->execute([$scheduleId]);

    echo json_encode(['ok' => true, 'message' => 'Schedule restored to active status successfully.']);
}
