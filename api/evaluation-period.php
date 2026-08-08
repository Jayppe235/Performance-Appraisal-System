<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';
require_once __DIR__ . '/../includes/notifications.php';

notify_ensure_schema();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedDevOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5175',
];

if (in_array($origin, $allowedDevOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if ((string) ($_GET['action'] ?? '') === 'periods') {
            $periods = dipascaf_period_list_payload();
            if (in_array((string)($user['role'] ?? ''), ['teacher','program_head','dean'], true)) {
                $periods = array_values(array_filter(
                    $periods,
                    static fn(array $period): bool => dipascaf_user_can_access_period((int)$user['id'], (int)$period['id'])
                ));
            }
            echo json_encode([
                'ok' => true,
                'data' => $periods,
                'current' => dipascaf_period_payload(),
            ]);
            exit;
        }

        $period = dipascaf_current_evaluation_period();
        echo json_encode(['ok' => true, 'data' => dipascaf_period_payload($period)]);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    require_role('admin_hr');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $action = (string) ($input['action'] ?? '');
    $db = db();
    dipascaf_ensure_evaluation_period_schema();

    if ($action === 'open') {
        $periodId = (int) ($input['period_id'] ?? 0);
        $periodName = trim((string) ($input['period_name'] ?? ''));
        $schoolYear = trim((string) ($input['school_year'] ?? ''));
        $semester = null;
        $dateStart = trim((string) ($input['date_start'] ?? ''));
        $dateEnd = trim((string) ($input['date_end'] ?? ''));

        if ($periodName === '' || $schoolYear === '' || $dateStart === '' || $dateEnd === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Period name, academic year, start date, and due date are required.']);
            exit;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Start date and due date must use YYYY-MM-DD format.']);
            exit;
        }

        if (strtotime($dateEnd) < strtotime($dateStart)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Due date cannot be earlier than the start date.']);
            exit;
        }

        // Schema DDL must run before the transaction because MariaDB
        // implicitly commits active transactions around CREATE/ALTER TABLE.
        admin_ensure_faculty_program_schema();
        dipascaf_ensure_period_participation_schema();
        if ($periodId <= 0) {
            $stmt = $db->prepare(
                'INSERT INTO appraisal_periods (period_name,school_year,semester,date_start,date_end,status)
                 VALUES (:period_name,:school_year,NULL,:date_start,:date_end,"draft")
                 ON DUPLICATE KEY UPDATE school_year=VALUES(school_year),date_start=VALUES(date_start),
                   date_end=VALUES(date_end),status=IF(status="open",status,"draft")'
            );
            $stmt->execute([
                'period_name'=>$periodName,'school_year'=>$schoolYear,
                'date_start'=>$dateStart,'date_end'=>$dateEnd,
            ]);
            $draft = admin_one('SELECT id FROM appraisal_periods WHERE period_name=:name ORDER BY id DESC LIMIT 1', ['name'=>$periodName]);
            $draftId = (int)($draft['id'] ?? 0);
            dipascaf_seed_period_participants($draftId, (int)$user['id']);
            echo json_encode([
                'ok'=>true,
                'message'=>'Draft period created. Finalize participants, assign and validate peers, then activate it.',
                'data'=>dipascaf_period_payload(admin_one('SELECT * FROM appraisal_periods WHERE id=:id', ['id'=>$draftId])),
                'workflow_step'=>'participants',
            ]);
            exit;
        }
        $readiness = admin_one(
            'SELECT participants_finalized_at,peer_assignments_validated_at FROM appraisal_periods WHERE id=:id',
            ['id'=>$periodId]
        );
        if (empty($readiness['participants_finalized_at'])) {
            throw new DomainException('Finalize Evaluation Period Participants before activation.');
        }
        if (empty($readiness['peer_assignments_validated_at'])) {
            throw new DomainException('Assign and validate Peer Assignments before activation.');
        }
        $db->beginTransaction();
        try {
            $db->exec("UPDATE appraisal_periods SET status = 'locked', locked_at = NOW() WHERE status = 'open'");

            if ($periodId > 0) {
                $existing = admin_one('SELECT id, period_name FROM appraisal_periods WHERE id = ?', [$periodId]);
                if ($existing === null) {
                    throw new RuntimeException('Selected evaluation period was not found.');
                }
                $previousPeriodName = (string) ($existing['period_name'] ?? '');

                $stmt = $db->prepare(
                    'UPDATE appraisal_periods
                     SET period_name = :period_name,
                         school_year = :school_year,
                         semester = :semester,
                         date_start = :date_start,
                         date_end = :date_end,
                         status = "open",
                         opened_at = NOW(),
                         locked_at = NULL
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $periodId,
                    'period_name' => $periodName,
                    'school_year' => $schoolYear,
                    'semester' => $semester,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO appraisal_periods (period_name, school_year, semester, date_start, date_end, status, opened_at)
                     VALUES (:period_name, :school_year, :semester, :date_start, :date_end, "open", NOW())
                     ON DUPLICATE KEY UPDATE
                        school_year = VALUES(school_year),
                        semester = VALUES(semester),
                        date_start = VALUES(date_start),
                        date_end = VALUES(date_end),
                        status = "open",
                        opened_at = NOW(),
                        locked_at = NULL'
                );
                $stmt->execute([
                    'period_name' => $periodName,
                    'school_year' => $schoolYear,
                    'semester' => $semester,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                ]);
            }

            admin_save_setting('current_appraisal_cycle', $periodName);
            $sync = dipascaf_upsert_required_assignments_for_period($periodName, $dateEnd);
            $deadlineSync = dipascaf_sync_period_assignment_deadlines(
                $periodName,
                $dateEnd,
                $previousPeriodName ?? null,
                $periodId > 0 ? $periodId : null
            );
            if (($sync['expected'] ?? 0) > 0) {
                $openedPeriod = admin_one(
                    'SELECT id FROM appraisal_periods WHERE period_name = ? ORDER BY id DESC LIMIT 1',
                    [$periodName]
                );
                $schedulePeriodId = (int) ($openedPeriod['id'] ?? $periodId);
                if ($schedulePeriodId > 0 && ($periodId <= 0 || empty($deadlineSync['schedules_updated']))) {
                    $scheduleDeadlineSync = dipascaf_sync_period_assignment_deadlines($periodName, $dateEnd, null, $schedulePeriodId);
                    $deadlineSync['assignments_updated'] = (int) ($deadlineSync['assignments_updated'] ?? 0)
                        + (int) ($scheduleDeadlineSync['assignments_updated'] ?? 0);
                    $deadlineSync['schedules_updated'] = (int) ($deadlineSync['schedules_updated'] ?? 0)
                        + (int) ($scheduleDeadlineSync['schedules_updated'] ?? 0);
                }
                $db->prepare('UPDATE evaluation_schedules SET total_assignments = :total WHERE evaluation_period_id = :period_id')
                    ->execute(['total' => (int) $sync['expected'], 'period_id' => $schedulePeriodId]);
            }
            admin_activity('Opened evaluation period: ' . $periodName);
            $openedPeriodId = (int) ($schedulePeriodId ?? $periodId ?? 0);
            foreach (['teacher', 'program_head', 'dean', 'vpaa'] as $recipientRole) {
                notify_role(
                    $recipientRole,
                    'evaluation',
                    'Evaluation Period Opened',
                    'A new evaluation period is now open. Please complete your assigned evaluations.',
                    '/faculty/evaluate',
                    'evaluation_period',
                    $openedPeriodId
                );
            }
            if ($db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        $period = dipascaf_open_evaluation_period();
        echo json_encode([
            'ok' => true,
            'message' => 'Evaluation period opened successfully.',
            'data' => dipascaf_period_payload($period),
            'sync' => $sync ?? null,
            'deadline_sync' => $deadlineSync ?? null,
        ]);
        exit;
    }

    if ($action === 'lock') {
        $periodId = (int) ($input['period_id'] ?? 0);
        $period = $periodId > 0
            ? admin_one('SELECT * FROM appraisal_periods WHERE id = ? LIMIT 1', [$periodId])
            : dipascaf_open_evaluation_period();

        if ($period === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Selected evaluation period was not found.']);
            exit;
        }

        if ((string) ($period['status'] ?? '') !== 'open') {
            echo json_encode(['ok' => true, 'message' => 'Evaluation period is already locked.', 'data' => dipascaf_period_payload($period)]);
            exit;
        }

        $db->prepare("UPDATE appraisal_periods SET status = 'locked', locked_at = NOW() WHERE id = ?")
            ->execute([(int) $period['id']]);
        admin_activity('Locked evaluation period: ' . (string) $period['period_name']);

        foreach (['teacher', 'program_head', 'dean', 'vpaa'] as $recipientRole) {
            notify_role(
                $recipientRole,
                'warning',
                'Evaluation Period Locked',
                'The evaluation period has been closed. Evaluation forms are no longer available for submission.',
                '/faculty/evaluate',
                'evaluation_period',
                (int) $period['id']
            );
        }

        $locked = admin_one('SELECT * FROM appraisal_periods WHERE id = ?', [(int) $period['id']]);
        echo json_encode(['ok' => true, 'message' => 'Evaluation period locked successfully. All evaluator forms are now read-only and protected.', 'data' => dipascaf_period_payload($locked)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unknown evaluation period action.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
