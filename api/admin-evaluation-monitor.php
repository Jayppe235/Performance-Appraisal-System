<?php
declare(strict_types=1);
/**
 * Admin Evaluation Monitor API
 * 
 * Provides hierarchical evaluation monitoring data for the Admin/HR dashboard.
 * Supports drill-down: Department → Program → Faculty
 *
 * GET /api/admin-evaluation-monitor.php
 * GET /api/admin-evaluation-monitor.php?scope=department&department_id=1
 * GET /api/admin-evaluation-monitor.php?scope=program&program_id=1
 * GET /api/admin-evaluation-monitor.php?scope=faculty&faculty_id=1
 * GET /api/admin-evaluation-monitor.php?scope=chatbot&question=...
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/gemini.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/evaluation_consistency_sync.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';

notify_ensure_schema();

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

header('Content-Type: application/json; charset=utf-8');

function admin_monitor_recommended_session(string $fieldName): string
{
    $key = strtolower(trim($fieldName));
    if ($key === '') {
        return 'Review the latest submitted evaluations and assign a targeted faculty development activity.';
    }
    if (str_contains($key, 'communication')) {
        return 'Recommend a communication skills and constructive feedback workshop.';
    }
    if (str_contains($key, 'classroom')) {
        return 'Recommend a classroom management and learner engagement seminar.';
    }
    if (str_contains($key, 'job knowledge') || str_contains($key, 'quality')) {
        return 'Recommend job knowledge and quality-of-work mentoring.';
    }
    if (str_contains($key, 'leadership') || str_contains($key, 'management')) {
        return 'Recommend leadership planning and management coaching.';
    }
    if (str_contains($key, 'teamwork') || str_contains($key, 'interpersonal')) {
        return 'Recommend a team collaboration and interpersonal sensitivity seminar.';
    }
    if (str_contains($key, 'initiative') || str_contains($key, 'resourcefulness') || str_contains($key, 'creativity')) {
        return 'Recommend an innovation, initiative, and resourcefulness workshop.';
    }
    if (str_contains($key, 'institutional')) {
        return 'Recommend an institutional commitment and values alignment session.';
    }
    if (str_contains($key, 'commitment') || str_contains($key, 'responsibility')) {
        return 'Recommend professional responsibility and job commitment coaching.';
    }

    return 'Recommend a targeted professional development session for ' . $fieldName . '.';
}

function admin_monitor_evaluation_type_label(string $assignmentType, string $evaluatorRole): string
{
    $type = strtolower(trim($assignmentType));
    $role = strtolower(trim($evaluatorRole));

    if ($type === 'self') {
        return 'Self-Assessment';
    }
    if ($type === 'peer') {
        return 'Peer Evaluation';
    }
    if ($type === 'program_head' || $role === 'program_head') {
        return 'Program Head Evaluation';
    }
    if ($type === 'dean' || $role === 'dean') {
        return 'Dean Evaluation';
    }
    if ($type === 'vpaa' || $role === 'vpaa') {
        return 'VPAA Evaluation';
    }

    return 'Evaluator Review';
}

function admin_monitor_recommendation_status_payload(int $submitted, int $total, array $pendingEvaluators = []): array
{
    $total = max(0, $total);
    if ($total === 0) {
        return [
            'recommendation_status' => 'none',
            'completion_status' => 'not_assigned',
            'submitted_count' => 0,
            'pending_count' => 0,
            'total_assigned' => 0,
            'completion_percentage' => 0.0,
            'pending_evaluators' => [],
            'will_update_on_date' => null,
            'warning_flag' => false,
            'status_label' => 'NO ASSIGNMENTS',
            'caveat_text' => 'No evaluation assignments are available for this appraisal period.',
        ];
    }
    $submitted = max(0, min($submitted, $total));
    $pending = max(0, $total - $submitted);
    $pct = $total > 0 ? round(($submitted / $total) * 100, 1) : 0.0;
    $status = $pct >= 100 ? 'final' : ($pct >= 50 ? 'interim' : 'preliminary');
    $completionStatus = $pct >= 100 ? 'complete' : ($pct > 0 ? 'partial' : 'incomplete');
    $label = strtoupper($status);
    $nextUpdate = null;
    foreach ($pendingEvaluators as $row) {
        $deadline = trim((string) ($row['deadline'] ?? ''));
        if ($deadline === '') {
            continue;
        }
        $candidate = date('Y-m-d H:i:s', strtotime($deadline . ' +1 day'));
        if ($nextUpdate === null || strcmp($candidate, $nextUpdate) < 0) {
            $nextUpdate = $candidate;
        }
    }
    $prefix = match ($status) {
        'final' => "FINAL RECOMMENDATION - Based on complete evaluation data from all {$total} evaluator" . ($total === 1 ? '' : 's') . '.',
        'interim' => "INTERIM RECOMMENDATION (Based on {$pct}% of evaluations) - This recommendation may change as remaining {$pending} evaluation" . ($pending === 1 ? '' : 's') . ' are received.',
        default => "PRELIMINARY RECOMMENDATION (Based on {$pct}% of evaluations) - Final recommendation will be provided once all evaluations are submitted.",
    };

    return [
        'recommendation_status' => $status,
        'completion_status' => $completionStatus,
        'submitted_count' => $submitted,
        'pending_count' => $pending,
        'total_assigned' => $total,
        'completion_percentage' => $pct,
        'pending_evaluators' => $pendingEvaluators,
        'will_update_on_date' => $nextUpdate,
        'warning_flag' => $pct < 100,
        'status_label' => $label,
        'caveat_text' => $prefix,
    ];
}

function admin_monitor_pending_evaluator_payload(array $assignment): array
{
    $deadline = trim((string) ($assignment['deadline'] ?? ''));
    $days = null;
    $isOverdue = false;
    if ($deadline !== '') {
        $today = new DateTimeImmutable('today');
        $due = new DateTimeImmutable($deadline);
        $days = (int) $today->diff($due)->format('%r%a');
        $isOverdue = $days < 0;
    }

    return [
        'id' => (int) ($assignment['id'] ?? 0),
        'name' => (string) ($assignment['evaluator_name'] ?? 'Evaluator'),
        'role' => admin_monitor_evaluation_type_label((string) ($assignment['assignment_type'] ?? ''), (string) ($assignment['evaluator_role'] ?? '')),
        'status' => $isOverdue ? 'overdue' : (string) ($assignment['assignment_status'] ?? 'pending'),
        'deadline' => $deadline,
        'days_until_deadline' => $days,
        'overdue' => $isOverdue,
    ];
}

function admin_monitor_completion_summary_from_assignments(array $assignments): array
{
    $total = count($assignments);
    $submitted = 0;
    $pending = [];
    foreach ($assignments as $assignment) {
        if ((string) ($assignment['assignment_status'] ?? $assignment['status'] ?? '') === 'submitted') {
            $submitted++;
        } else {
            $pending[] = admin_monitor_pending_evaluator_payload($assignment);
        }
    }
    return admin_monitor_recommendation_status_payload($submitted, $total, $pending);
}

function admin_monitor_apply_recommendation_caveat(string $recommendation, array $status): string
{
    $recommendation = trim($recommendation);
    $caveat = trim((string) ($status['caveat_text'] ?? ''));
    if ($caveat === '') {
        return $recommendation;
    }
    return $recommendation === '' ? $caveat : $caveat . ' ' . $recommendation;
}

function admin_monitor_ensure_self_review_schema(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS pmas_self_evaluation_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            self_evaluation_id INT NOT NULL,
            user_id INT NULL,
            user_role VARCHAR(40) NOT NULL,
            action_type VARCHAR(60) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_self_eval_audit_record (self_evaluation_id, created_at),
            KEY idx_self_eval_audit_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    foreach ([
        'dean_review_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN dean_review_status ENUM('pending','approved','reopened','submitted_to_admin') NOT NULL DEFAULT 'pending' AFTER reopened_by",
        'dean_reviewed_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_reviewed_by INT NULL AFTER dean_review_status',
        'dean_reviewed_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_reviewed_at DATETIME NULL AFTER dean_reviewed_by',
        'dean_review_notes' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_review_notes TEXT NULL AFTER dean_reviewed_at',
        'reopened_reason' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_reason TEXT NULL AFTER dean_review_notes',
        'revision_count' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN revision_count INT NOT NULL DEFAULT 0 AFTER reopened_reason',
        'final_admin_submission_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN final_admin_submission_status ENUM('not_ready','ready_for_admin','submitted_to_admin') NOT NULL DEFAULT 'not_ready' AFTER revision_count",
        'admin_review_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN admin_review_status ENUM('none','pending','reviewed','returned_to_dean') NOT NULL DEFAULT 'none' AFTER final_admin_submission_status",
        'admin_reviewed_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_reviewed_by INT NULL AFTER admin_review_status',
        'admin_reviewed_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_reviewed_by',
        'admin_return_reason' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_return_reason TEXT NULL AFTER admin_reviewed_at',
    ] as $column => $sql) {
        try {
            if (admin_one("SHOW COLUMNS FROM pmas_self_evaluations LIKE '{$column}'") === null) {
                db()->exec($sql);
            }
        } catch (Throwable) {
            // Keep monitor data available on older installs when possible.
        }
    }
}

function admin_monitor_self_audit(int $recordId, int $userId, string $actionType, ?string $oldValue, ?string $newValue, string $remarks): void
{
    db()->prepare(
        'INSERT INTO pmas_self_evaluation_audit_logs
            (self_evaluation_id, user_id, user_role, action_type, old_value, new_value, remarks)
         VALUES
            (:record_id, :user_id, :user_role, :action_type, :old_value, :new_value, :remarks)'
    )->execute([
        'record_id' => $recordId,
        'user_id' => $userId > 0 ? $userId : null,
        'user_role' => 'admin',
        'action_type' => $actionType,
        'old_value' => $oldValue,
        'new_value' => $newValue,
        'remarks' => $remarks,
    ]);
}

function admin_monitor_self_logs(int $recordId): array
{
    return admin_all(
        "SELECT l.*, u.full_name AS actor_name
         FROM pmas_self_evaluation_audit_logs l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.self_evaluation_id = :record_id
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 80",
        ['record_id' => $recordId]
    );
}

function admin_monitor_admin_status_label(string $status): string
{
    return match ($status) {
        'reviewed' => 'Reviewed by Admin',
        'returned_to_dean' => 'Returned to Dean',
        'pending' => 'Pending Admin Review',
        default => 'Submitted to Admin',
    };
}

function admin_monitor_self_evaluation_payload(int $facultyId, string $periodFilter = ''): ?array
{
    $params = ['faculty_id' => $facultyId];
    $periodSql = '';
    if ($periodFilter !== '') {
        $periodSql = ' AND se.evaluation_period = :period_name';
        $params['period_name'] = $periodFilter;
    }

    $record = admin_one(
        "SELECT se.*, f.full_name, f.department AS faculty_department, f.program_code, f.position_title,
                dean.full_name AS dean_reviewer_name, admin.full_name AS admin_reviewer_name
         FROM pmas_self_evaluations se
         JOIN peer_assignments pa ON pa.id = se.assignment_id
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users dean ON dean.id = se.dean_reviewed_by
         LEFT JOIN users admin ON admin.id = se.admin_reviewed_by
         WHERE pa.evaluatee_faculty_id = :faculty_id
           AND se.role = 'faculty'
           AND pa.assignment_type = 'self'
           AND se.status = 'submitted'
           AND (
                se.dean_review_status IN ('approved', 'submitted_to_admin')
                OR se.final_admin_submission_status IN ('ready_for_admin', 'submitted_to_admin')
                OR se.admin_review_status IN ('pending', 'reviewed', 'returned_to_dean')
           )
           {$periodSql}
         ORDER BY se.dean_reviewed_at DESC, se.submitted_at DESC, se.id DESC
         LIMIT 1",
        $params
    );

    if ($record === null) {
        return null;
    }

    $answers = json_decode((string) ($record['answers_json'] ?? ''), true);
    if (!is_array($answers)) {
        $answers = [];
    }

    return [
        'id' => (int) ($record['id'] ?? 0),
        'status' => (string) ($record['status'] ?? ''),
        'dean_review_status' => (string) ($record['dean_review_status'] ?? 'approved'),
        'admin_review_status' => (string) ($record['admin_review_status'] ?? 'pending'),
        'admin_review_label' => admin_monitor_admin_status_label((string) ($record['admin_review_status'] ?? 'pending')),
        'final_admin_submission_status' => (string) ($record['final_admin_submission_status'] ?? 'submitted_to_admin'),
        'submitted_at' => (string) ($record['submitted_at'] ?? ''),
        'evaluation_period' => (string) ($record['evaluation_period'] ?? ''),
        'performance_outputs_score' => $record['performance_outputs_score'],
        'performance_factors_score' => $record['performance_factors_score'],
        'overall_rating' => $record['overall_rating'],
        'performance_level' => (string) ($record['performance_level'] ?? ''),
        'dean_review_notes' => (string) ($record['dean_review_notes'] ?? ''),
        'dean_reviewed_at' => (string) ($record['dean_reviewed_at'] ?? ''),
        'dean_reviewer_name' => (string) ($record['dean_reviewer_name'] ?? ''),
        'admin_reviewed_at' => (string) ($record['admin_reviewed_at'] ?? ''),
        'admin_reviewer_name' => (string) ($record['admin_reviewer_name'] ?? ''),
        'admin_return_reason' => (string) ($record['admin_return_reason'] ?? ''),
        'answers' => $answers,
        'audit_logs' => admin_monitor_self_logs((int) ($record['id'] ?? 0)),
    ];
}

try {
    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'admin_hr') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Admin/HR access is required.']);
        exit;
    }

    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();
    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    dipascaf_ensure_dean_evaluator_assignments();
    admin_monitor_ensure_self_review_schema();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload.']);
            exit;
        }

        $action = (string) ($input['action'] ?? '');
        $recordId = (int) ($input['record_id'] ?? 0);
        $record = $recordId > 0 ? admin_one(
            "SELECT se.*, f.full_name, f.department AS faculty_department, dean.id AS dean_user_id
             FROM pmas_self_evaluations se
             JOIN peer_assignments pa ON pa.id = se.assignment_id
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             LEFT JOIN users dean ON dean.id = se.dean_reviewed_by
             WHERE se.id = :record_id
               AND se.role = 'faculty'
               AND se.status = 'submitted'
               AND (
                    se.dean_review_status IN ('approved', 'submitted_to_admin')
                    OR se.final_admin_submission_status IN ('ready_for_admin', 'submitted_to_admin')
                    OR se.admin_review_status IN ('pending', 'reviewed', 'returned_to_dean')
               )
             LIMIT 1",
            ['record_id' => $recordId]
        ) : null;

        if ($record === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'No Dean approved self evaluation has been submitted to Admin for this faculty member.']);
            exit;
        }

        if ($action === 'mark_self_evaluation_reviewed') {
            $oldValue = admin_monitor_admin_status_label((string) ($record['admin_review_status'] ?? 'pending'));
            db()->beginTransaction();
            try {
                db()->prepare(
                    "UPDATE pmas_self_evaluations
                     SET admin_review_status = 'reviewed',
                         admin_reviewed_by = :user_id,
                         admin_reviewed_at = NOW()
                     WHERE id = :record_id"
                )->execute(['record_id' => $recordId, 'user_id' => (int) $user['id']]);
                admin_monitor_self_audit($recordId, (int) $user['id'], 'reviewed_by_admin', $oldValue, 'Reviewed by Admin', 'Admin marked the self evaluation as reviewed.');
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true, 'message' => 'Self evaluation marked as Reviewed by Admin.']);
            exit;
        }

        if ($action === 'return_self_evaluation_to_dean') {
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Return reason is required before returning the self evaluation to the Dean.']);
                exit;
            }
            $oldValue = admin_monitor_admin_status_label((string) ($record['admin_review_status'] ?? 'pending'));
            db()->beginTransaction();
            try {
                db()->prepare(
                    "UPDATE pmas_self_evaluations
                     SET admin_review_status = 'returned_to_dean',
                         admin_reviewed_by = :user_id,
                         admin_reviewed_at = NOW(),
                         admin_return_reason = :reason
                     WHERE id = :record_id"
                )->execute(['record_id' => $recordId, 'user_id' => (int) $user['id'], 'reason' => $reason]);
                admin_monitor_self_audit($recordId, (int) $user['id'], 'returned_to_dean', $oldValue, 'Returned to Dean', $reason);
                if ((int) ($record['dean_user_id'] ?? 0) > 0) {
                    notify_create(
                        (int) $record['dean_user_id'],
                        'revision',
                        'Self Evaluation Returned to Dean',
                        'A submitted self evaluation has been returned to the Dean for correction.',
                        '/dean/self-evaluation-review',
                        'self_evaluation',
                        $recordId
                    );
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true, 'message' => 'Self evaluation returned to the Dean.']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown Admin self evaluation action.']);
        exit;
    }

    $scope = trim((string) ($_GET['scope'] ?? 'departments'));
    $departmentId = (int) ($_GET['department_id'] ?? 0);
    $programId = (int) ($_GET['program_id'] ?? 0);
    $facultyId = (int) ($_GET['faculty_id'] ?? 0);
    $periodFilter = trim((string) ($_GET['period'] ?? ''));
    $periodId = (int) ($_GET['period_id'] ?? 0);
    if ($periodFilter === '' && $periodId > 0) {
        $periodRow = admin_one('SELECT period_name FROM appraisal_periods WHERE id = ? LIMIT 1', [$periodId]);
        $periodFilter = trim((string) ($periodRow['period_name'] ?? ''));
    }
    dipascaf_sync_evaluation_consistency($periodFilter);
    $departmentFilter = trim((string) ($_GET['department'] ?? ''));

    // ── Chatbot/analytics queries ─────────────────────────────────────
    if ($scope === 'chatbot') {
        $question = strtolower(trim($_GET['question'] ?? ''));
        $stats = admin_evaluation_progress_summary();
        $comparison = admin_completion_by_department($periodFilter);
        $weakAreas = admin_department_weak_areas();
        $interventions = admin_interventions();

        if (str_contains($question, 'form a') || str_contains($question, 'form b') || str_contains($question, 'questionnaire') || str_contains($question, 'questions')) {
            $formACategories = admin_count('SELECT COUNT(*) FROM pmas_form_a_categories WHERE is_active = 1');
            $formAQuestions = admin_count('SELECT COUNT(*) FROM pmas_form_a_questions q JOIN pmas_form_a_categories c ON c.id = q.category_id WHERE q.is_active = 1 AND c.is_active = 1');
            $formBCategories = admin_count('SELECT COUNT(*) FROM pmas_form_b_categories WHERE is_active = 1');
            $formBQuestions = admin_count('SELECT COUNT(*) FROM pmas_form_b_questions q JOIN pmas_form_b_categories c ON c.id = q.category_id WHERE q.is_active = 1 AND c.is_active = 1');
            $answer = "Active questionnaire setup:\n"
                . "- Form A: {$formACategories} active categories, {$formAQuestions} active questions\n"
                . "- Form B: {$formBCategories} active categories, {$formBQuestions} active questions\n"
                . "- Form A is generally for leadership/admin review; Form B is for faculty questionnaire review.";
            echo json_encode(['ok' => true, 'answer' => $answer]);
            exit;
        }

        if (str_contains($question, 'behavioral evidence') || (str_contains($question, 'evidence') && str_contains($question, 'required'))) {
            echo json_encode(['ok' => true, 'answer' => "Behavioral Evidence is required when a rating needs clear justification. It records the observed behavior, output, or reason behind the score, especially for very high or low category averages. Evaluators should complete it before moving to the next category when the form shows the warning."]);
            exit;
        }

        if (str_contains($question, 'before submission') || str_contains($question, 'before submit') || str_contains($question, 'checked before') || str_contains($question, 'check before')) {
            $answer = "Before submission, check:\n"
                . "- Every question is rated\n"
                . "- Required behavioral evidence is provided\n"
                . "- Category average and weighted scores look correct\n"
                . "- Evidence Status is Provided for required categories\n"
                . "- Overall Final Score and Equivalent Rating are reviewed";
            echo json_encode(['ok' => true, 'answer' => $answer]);
            exit;
        }

        if (str_contains($question, 'overdue') || str_contains($question, 'due')) {
            usort($comparison, static fn (array $a, array $b): int => ((int) ($b['overdue'] ?? 0)) <=> ((int) ($a['overdue'] ?? 0)));
            $lines = ['Departments with overdue evaluations:'];
            foreach (array_slice($comparison, 0, 5) as $dept) {
                $lines[] = '- ' . ($dept['department'] ?? 'Unknown') . ': ' . (int) ($dept['overdue'] ?? 0) . ' overdue, ' . (int) ($dept['pending'] ?? 0) . ' pending, ' . ($dept['completion_pct'] ?? 0) . '% complete';
            }
            echo json_encode(['ok' => true, 'answer' => implode("\n", $lines)]);
            exit;
        }

        if (str_contains($question, 'compare') || str_contains($question, 'completion rates')) {
            if ($comparison === []) {
                echo json_encode(['ok' => true, 'answer' => 'No department completion data is available yet.']);
                exit;
            }
            $lines = ['Department completion comparison:'];
            foreach ($comparison as $dept) {
                $lines[] = '- ' . ($dept['department'] ?? 'Unknown') . ': ' . ($dept['completion_pct'] ?? 0) . '% complete (' . ($dept['submitted'] ?? 0) . '/' . ($dept['total_assignments'] ?? 0) . ' submitted, ' . ($dept['pending'] ?? 0) . ' pending)';
            }
            echo json_encode(['ok' => true, 'answer' => implode("\n", $lines)]);
            exit;
        }

        if (str_contains($question, 'weak area') || str_contains($question, 'top weak') || str_contains($question, 'common weak')) {
            if ($weakAreas === []) {
                echo json_encode(['ok' => true, 'answer' => 'No weak-area data is available yet. Weak areas appear after completed evaluations are processed by AI analysis.']);
                exit;
            }
            $counts = [];
            foreach ($weakAreas as $area) {
                $name = (string) ($area['weak_area'] ?? 'Unspecified');
                $counts[$name] = ($counts[$name] ?? 0) + (int) ($area['weak_count'] ?? 0);
            }
            arsort($counts);
            $lines = ['Top weak areas:'];
            foreach (array_slice($counts, 0, 6, true) as $area => $count) {
                $lines[] = "- {$area}: {$count} occurrence(s)";
            }
            echo json_encode(['ok' => true, 'answer' => implode("\n", $lines)]);
            exit;
        }

        if (str_contains($question, 'ai insight') || str_contains($question, 'recent ai')) {
            $insights = admin_ai_insights();
            if ($insights === []) {
                echo json_encode(['ok' => true, 'answer' => 'No AI insights have been generated yet. Insights appear after evaluation records are completed and analyzed.']);
                exit;
            }
            $lines = ['Recent AI insights:'];
            foreach (array_slice($insights, 0, 6) as $insight) {
                $lines[] = '- ' . ($insight['faculty_name'] ?? 'Unknown') . ' - ' . ($insight['weak_area'] ?? 'Unspecified') . ' (' . ($insight['department'] ?? 'No department') . ')';
            }
            echo json_encode(['ok' => true, 'answer' => implode("\n", $lines)]);
            exit;
        }

        // Which department has the most pending evaluations?
        if (str_contains($question, 'most pending') || (str_contains($question, 'department') && str_contains($question, 'pending'))) {
            $mostPending = null;
            $maxPending = -1;
            foreach ($comparison as $dept) {
                $p = (int) ($dept['pending'] ?? 0);
                if ($p > $maxPending) {
                    $maxPending = $p;
                    $mostPending = $dept;
                }
            }
            if ($mostPending) {
                echo json_encode(['ok' => true, 'answer' => "{$mostPending['department']} has the most pending evaluations with {$maxPending} pending out of {$mostPending['total_assignments']} total ({$mostPending['completion_pct']}% completion rate)."]);
            } else {
                echo json_encode(['ok' => true, 'answer' => 'No pending evaluation data available at this time.']);
            }
            exit;
        }

        // Show lowest-performing program
        if (str_contains($question, 'lowest') || (str_contains($question, 'low') && str_contains($question, 'program'))) {
            $programs = admin_all(
                "SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS program_code,
                        COUNT(DISTINCT pa.id) AS total,
                        SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                        ROUND(100.0 * SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT pa.id), 0), 1) AS completion_pct
                 FROM peer_assignments pa
                 JOIN faculty f ON f.id = pa.evaluatee_faculty_id
                 WHERE COALESCE(f.is_archived, 0) = 0
                   AND COALESCE(pa.is_archived, 0) = 0
                 GROUP BY f.program_code
                 ORDER BY completion_pct ASC
                 LIMIT 1"
            );
            if (!empty($programs)) {
                $p = $programs[0];
                echo json_encode(['ok' => true, 'answer' => "The lowest-performing program is {$p['program_code']} with {$p['completion_pct']}% completion rate ({$p['submitted']}/{$p['total']} submitted)."]);
            } else {
                echo json_encode(['ok' => true, 'answer' => 'No program data available.']);
            }
            exit;
        }

        // Which faculty members need intervention?
        if (str_contains($question, 'intervention') || (str_contains($question, 'faculty') && (str_contains($question, 'need') || str_contains($question, 'lowest')))) {
            $needy = admin_all(
                "SELECT f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS program_code,
                        COUNT(DISTINCT i.id) AS insight_count
                 FROM faculty f
                 LEFT JOIN ai_insights i ON i.faculty_id = f.id
                 WHERE COALESCE(f.is_archived, 0) = 0
                 GROUP BY f.id
                 HAVING insight_count > 0
                 ORDER BY insight_count DESC
                 LIMIT 5"
            );
            if (!empty($needy)) {
                $lines = array_map(fn($n) => "{$n['full_name']} ({$n['department']}/{$n['program_code']}) - {$n['insight_count']} insight(s)", $needy);
                echo json_encode(['ok' => true, 'answer' => 'Faculty members needing intervention: ' . implode('; ', $lines)]);
            } else {
                echo json_encode(['ok' => true, 'answer' => 'No faculty members currently flagged for intervention.']);
            }
            exit;
        }

        // Generate department evaluation summary
        if (str_contains($question, 'summary') || str_contains($question, 'overview')) {
            $dept = trim($_GET['department'] ?? '');
            $data = [];
            foreach ($comparison as $d) {
                if ($dept === '' || stripos((string) $d['department'], $dept) !== false) {
                    $data[] = $d;
                }
            }
            if (!empty($data)) {
                $lines = array_map(fn($d) => "{$d['department']}: {$d['completion_pct']}% complete ({$d['submitted']}/{$d['total_assignments']} submitted, {$d['pending']} pending, {$d['overdue']} overdue)", $data);
                echo json_encode(['ok' => true, 'answer' => 'Department Evaluation Summary: ' . implode(' | ', $lines)]);
            } else {
                echo json_encode(['ok' => true, 'answer' => 'No department data available.']);
            }
            exit;
        }

        // Default chatbot answer
        $context = [
            'current_user' => $user,
            'dashboard_stats' => $stats,
            'department_weak_areas' => $weakAreas,
            'priority_interventions' => $interventions,
        ];
        $rawQuestion = trim($_GET['question'] ?? '');
        $answer = 'I can help analyze evaluation data. Try asking: "Which department has the most pending evaluations?", "Show lowest-performing program.", "Which faculty members need intervention?", or "Generate department evaluation summary."';
        $openAiAnswer = openai_answer($rawQuestion, $context);
        if ($openAiAnswer !== null) {
            $answer = $openAiAnswer;
        }
        $geminiAnswer = $openAiAnswer === null ? gemini_answer($rawQuestion, $context) : null;
        if ($geminiAnswer !== null) {
            $answer = $geminiAnswer;
        }
        echo json_encode(['ok' => true, 'answer' => $answer]);
        exit;
    }

    // ── Locate one person's faculty record for direct overall-rating review ──
    if ($scope === 'faculty_person') {
        $targetUserId = (int) ($_GET['user_id'] ?? 0);
        $targetEmail = strtolower(trim((string) ($_GET['email'] ?? '')));
        $targetName = strtolower(trim((string) ($_GET['name'] ?? '')));
        $targetDepartmentId = (int) ($_GET['department_id'] ?? 0);

        $where = ['COALESCE(f.is_archived, 0) = 0'];
        $params = [];
        $identityClauses = [];

        if ($targetUserId > 0) {
            $identityClauses[] = '(f.user_id = ? OR u.id = ?)';
            $params[] = $targetUserId;
            $params[] = $targetUserId;
        }
        if ($targetEmail !== '') {
            $identityClauses[] = 'LOWER(COALESCE(NULLIF(f.email, ""), u.email, "")) = ?';
            $params[] = $targetEmail;
        }
        if ($targetName !== '') {
            $identityClauses[] = '(LOWER(f.full_name) = ? OR LOWER(f.full_name) LIKE ?)';
            $params[] = $targetName;
            $params[] = '%' . $targetName . '%';
        }

        if ($identityClauses === []) {
            echo json_encode(['ok' => false, 'message' => 'A user id, email, or name is required.']);
            exit;
        }

        $where[] = '(' . implode(' OR ', $identityClauses) . ')';

        if ($targetDepartmentId > 0) {
            $department = admin_one('SELECT * FROM departments WHERE id = ? LIMIT 1', [$targetDepartmentId]);
            if ($department !== null) {
                $aliases = admin_department_aliases($department);
                if ($aliases !== []) {
                    $where[] = 'f.department IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
                    $params = array_merge($params, $aliases);
                }
            }
        }

        $faculty = admin_one(
            'SELECT f.id, f.user_id, f.full_name, f.email, f.department, f.program_code
             FROM faculty f
             LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY f.user_id IS NULL ASC, f.full_name ASC
             LIMIT 1',
            $params
        );

        if ($faculty === null) {
            echo json_encode(['ok' => false, 'message' => 'No faculty record was found for that person.']);
            exit;
        }

        $program = null;
        $programCode = trim((string) ($faculty['program_code'] ?? ''));
        if ($programCode !== '') {
            $programParams = [$programCode];
            $programWhere = 'program_code = ? AND is_active = 1';
            if ($targetDepartmentId > 0) {
                $programWhere .= ' AND department_id = ?';
                $programParams[] = $targetDepartmentId;
            }
            $program = admin_one(
                'SELECT id, program_code, program_name, department_id
                 FROM programs
                 WHERE ' . $programWhere . '
                 LIMIT 1',
                $programParams
            );
        }

        echo json_encode([
            'ok' => true,
            'faculty' => [
                'id' => (int) ($faculty['id'] ?? 0),
                'user_id' => (int) ($faculty['user_id'] ?? 0),
                'full_name' => (string) ($faculty['full_name'] ?? ''),
                'email' => (string) ($faculty['email'] ?? ''),
                'department' => (string) ($faculty['department'] ?? ''),
                'program_code' => (string) ($faculty['program_code'] ?? ''),
                'program_id' => $program !== null ? (int) ($program['id'] ?? 0) : 0,
                'program_name' => $program !== null ? (string) ($program['program_name'] ?? '') : '',
            ],
        ]);
        exit;
    }

    // ── Department-level overview ─────────────────────────────────────
    if ($scope === 'departments') {
        $allDepts = admin_departments();
        $comparison = admin_completion_by_department($periodFilter);
        $deptMap = [];
        foreach ($comparison as $row) {
            $deptMap[(string) $row['department']] = $row;
        }

        $departments = [];
        foreach ($allDepts as $dept) {
            $deptName = (string) ($dept['department_name'] ?? '');
            $deptCode = (string) ($dept['department_code'] ?? '');

            // Aggregate stats from ALL alias groups for this department
            // e.g. "CITE" + "College of Information Technology Engineering" + "Computer Studies" → merged
            $aliases = admin_department_aliases($dept);
            $stats = [
                'total_assignments' => 0,
                'submitted' => 0,
                'pending' => 0,
                'overdue' => 0,
                'completion_pct' => 0,
            ];
            foreach ($aliases as $alias) {
                if (isset($deptMap[$alias])) {
                    $stats['total_assignments'] += (int) ($deptMap[$alias]['total_assignments'] ?? 0);
                    $stats['submitted'] += (int) ($deptMap[$alias]['submitted'] ?? 0);
                    $stats['pending'] += (int) ($deptMap[$alias]['pending'] ?? 0);
                    $stats['overdue'] += (int) ($deptMap[$alias]['overdue'] ?? 0);
                }
            }
            // Recalculate completion percentage after merging
            if ($stats['total_assignments'] > 0) {
                $stats['completion_pct'] = round(($stats['submitted'] / $stats['total_assignments']) * 100, 1);
            }

            $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));
            $facultyCount = admin_count(
                "SELECT COUNT(*) FROM faculty WHERE is_archived = 0 AND department IN ($aliasPlaceholders)",
                $aliases
            );
            $archivedFacultyCount = admin_count(
                "SELECT COUNT(*) FROM faculty WHERE is_archived = 1 AND department IN ($aliasPlaceholders)",
                $aliases
            );

            $allEvaluated = ($stats['pending'] ?? 0) === 0 && ($stats['overdue'] ?? 0) === 0 && ($stats['total_assignments'] ?? 0) > 0;
            $recommendationStatus = admin_monitor_recommendation_status_payload(
                (int) ($stats['submitted'] ?? 0),
                (int) ($stats['total_assignments'] ?? 0),
                []
            );

            $departments[] = [
                'id' => (int) $dept['id'],
                'department_code' => $deptCode,
                'department_name' => $deptName,
                'dean_name' => (string) ($dept['dean_name'] ?? 'Unassigned'),
                'total_faculty' => $facultyCount,
                'archived_faculty_count' => $archivedFacultyCount,
                'total_assignments' => (int) ($stats['total_assignments'] ?? 0),
                'completed' => (int) ($stats['submitted'] ?? 0),
                'pending' => (int) ($stats['pending'] ?? 0),
                'overdue' => (int) ($stats['overdue'] ?? 0),
                'completion_pct' => (float) ($stats['completion_pct'] ?? 0),
                'all_evaluated' => $allEvaluated,
                'recommendation_status' => $recommendationStatus,
            ];
        }

        // Build department-level weak area stats
        $weakAreaData = [];
        $weakRows = $periodFilter !== ''
            ? admin_all(
                "SELECT f.department, i.weak_area, COUNT(DISTINCT i.id) AS cnt
                 FROM ai_insights i
                 JOIN faculty f ON f.id = i.faculty_id
                 JOIN peer_assignments pa ON pa.evaluatee_faculty_id = f.id
                 WHERE pa.cycle_name = ?
                 GROUP BY f.department, i.weak_area
                 ORDER BY cnt DESC",
                [$periodFilter]
            )
            : admin_all(
                "SELECT f.department, i.weak_area, COUNT(*) AS cnt
                 FROM ai_insights i
                 JOIN faculty f ON f.id = i.faculty_id
                 GROUP BY f.department, i.weak_area
                 ORDER BY cnt DESC"
            );
        foreach ($weakRows as $w) {
            $dept = (string) ($w['department'] ?? '');
            if (!isset($weakAreaData[$dept])) {
                $weakAreaData[$dept] = [];
            }
            $weakAreaData[$dept][] = [
                'weak_area' => (string) ($w['weak_area'] ?? ''),
                'count' => (int) ($w['cnt'] ?? 0),
            ];
        }

        echo json_encode([
            'ok' => true,
            'data' => $departments,
            'weakAreas' => $weakAreaData,
            'summary' => [
                'total_departments' => count($departments),
                'total_faculty' => array_sum(array_column($departments, 'total_faculty')),
                'total_completed' => array_sum(array_column($departments, 'completed')),
                'total_pending' => array_sum(array_column($departments, 'pending')),
                'total_overdue' => array_sum(array_column($departments, 'overdue')),
                'overall_completion_rate' => 0,
            ],
        ]);
        exit;
    }

    // ── Programs under a department ───────────────────────────────────
    if ($scope === 'programs' && $departmentId > 0) {
        $department = admin_one('SELECT * FROM departments WHERE id = :id', ['id' => $departmentId]);
        if ($department === null) {
            echo json_encode(['ok' => false, 'message' => 'Department not found.']);
            exit;
        }

        $aliases = admin_department_aliases($department);
        $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));

        $programs = admin_all(
            'SELECT p.*, u.full_name AS program_head_name
             FROM programs p
             LEFT JOIN users u ON u.id = p.program_head_user_id
             WHERE p.department_id = :department_id AND p.is_active = 1
             ORDER BY p.program_name',
            ['department_id' => $departmentId]
        );

        $programData = [];
        foreach ($programs as $prog) {
            $progCode = (string) ($prog['program_code'] ?? '');
            $facultyCount = admin_count(
                "SELECT COUNT(*) FROM faculty WHERE is_archived = 0 AND program_code = ? AND department IN ($aliasPlaceholders)",
                array_merge([$progCode], $aliases)
            );

            $periodSql = $periodFilter !== '' ? ' AND pa.cycle_name = ?' : '';
            $periodParams = $periodFilter !== '' ? [$periodFilter] : [];
            $evalStats = admin_one(
                "SELECT COUNT(DISTINCT pa.id) AS total,
                        SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN pa.deadline < CURDATE() AND pa.status != 'submitted' THEN 1 ELSE 0 END) AS overdue
                 FROM peer_assignments pa
                 JOIN faculty f ON f.id = pa.evaluatee_faculty_id
                 WHERE f.program_code = ? AND f.department IN ($aliasPlaceholders) AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0$periodSql",
                array_merge([$progCode], $aliases, $periodParams)
            );

            $total = (int) ($evalStats['total'] ?? 0);
            $completed = (int) ($evalStats['completed'] ?? 0);
            $completionPct = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

            // Average scores from form results
            $avgScore = admin_one(
                "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
                 FROM pmas_form_b_category_results r
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 JOIN faculty f ON f.id = r.evaluatee_faculty_id
                 WHERE f.program_code = :prog_code AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'
                 " . ($periodFilter !== '' ? 'AND r.evaluation_period = :period_name' : ''),
                $periodFilter !== ''
                    ? ['prog_code' => $progCode, 'period_name' => $periodFilter]
                    : ['prog_code' => $progCode]
            );
            $avgScoreA = admin_one(
                "SELECT ROUND(AVG(r.average_rating), 2) AS avg_score
                 FROM pmas_form_a_category_results r
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 JOIN faculty f ON f.id = r.evaluatee_faculty_id
                 WHERE f.program_code = :prog_code AND COALESCE(f.is_archived, 0) = 0 AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND r.status = 'completed'
                 " . ($periodFilter !== '' ? 'AND r.evaluation_period = :period_name' : ''),
                $periodFilter !== ''
                    ? ['prog_code' => $progCode, 'period_name' => $periodFilter]
                    : ['prog_code' => $progCode]
            );

            $fieldRows = array_merge(
                admin_all(
                    "SELECT c.title AS field_name,
                            AVG(r.average_rating) AS avg_score,
                            COUNT(*) AS result_count
                     FROM pmas_form_a_category_results r
                     JOIN pmas_form_a_categories c ON c.id = r.category_id
                     JOIN peer_assignments pa ON pa.id = r.assignment_id
                     JOIN faculty f ON f.id = r.evaluatee_faculty_id
                     WHERE f.program_code = ?
                       AND f.department IN ($aliasPlaceholders)
                       AND COALESCE(f.is_archived, 0) = 0
                       AND COALESCE(r.is_archived, 0) = 0
                       AND COALESCE(pa.is_archived, 0) = 0
                       AND r.status = 'completed'
                       " . ($periodFilter !== '' ? 'AND r.evaluation_period = ?' : '') . "
                     GROUP BY c.title",
                    $periodFilter !== ''
                        ? array_merge([$progCode], $aliases, [$periodFilter])
                        : array_merge([$progCode], $aliases)
                ),
                admin_all(
                    "SELECT c.title AS field_name,
                            AVG(r.average_rating) AS avg_score,
                            COUNT(*) AS result_count
                     FROM pmas_form_b_category_results r
                     JOIN pmas_form_b_categories c ON c.id = r.category_id
                     JOIN peer_assignments pa ON pa.id = r.assignment_id
                     JOIN faculty f ON f.id = r.evaluatee_faculty_id
                     WHERE f.program_code = ?
                       AND f.department IN ($aliasPlaceholders)
                       AND COALESCE(f.is_archived, 0) = 0
                       AND COALESCE(r.is_archived, 0) = 0
                       AND COALESCE(pa.is_archived, 0) = 0
                       AND r.status = 'completed'
                       " . ($periodFilter !== '' ? 'AND r.evaluation_period = ?' : '') . "
                     GROUP BY c.title",
                    $periodFilter !== ''
                        ? array_merge([$progCode], $aliases, [$periodFilter])
                        : array_merge([$progCode], $aliases)
                )
            );
            $fieldBuckets = [];
            foreach ($fieldRows as $fieldRow) {
                $fieldName = (string) ($fieldRow['field_name'] ?? '');
                if ($fieldName === '') {
                    continue;
                }
                $fieldBuckets[$fieldName] ??= ['name' => $fieldName, 'score_total' => 0.0, 'count' => 0];
                $count = max(1, (int) ($fieldRow['result_count'] ?? 1));
                $fieldBuckets[$fieldName]['score_total'] += ((float) ($fieldRow['avg_score'] ?? 0)) * $count;
                $fieldBuckets[$fieldName]['count'] += $count;
            }
            $fields = array_values(array_map(
                static fn (array $field): array => [
                    'name' => $field['name'],
                    'score' => $field['count'] > 0 ? round($field['score_total'] / $field['count'], 2) : 0,
                    'resultCount' => $field['count'],
                ],
                $fieldBuckets
            ));
            usort($fields, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
            $allProgramEvaluated = $total > 0 && (int) ($evalStats['pending'] ?? 0) === 0 && (int) ($evalStats['overdue'] ?? 0) === 0;
            $recommendationStatus = admin_monitor_recommendation_status_payload($completed, $total, []);
            $programRecommendation = '';
            if ($fields !== []) {
                $weakestField = $fields[0];
                $programRecommendation = admin_monitor_apply_recommendation_caveat(
                    'Program insights based on completed evaluations indicate that ' . $weakestField['name'] . ' should be prioritized. ' . admin_monitor_recommended_session((string) $weakestField['name']),
                    $recommendationStatus
                );
            } else {
                $programRecommendation = admin_monitor_apply_recommendation_caveat(
                    'No completed category results are available yet for program-level weak area analysis.',
                    $recommendationStatus
                );
            }

            $programData[] = [
                'id' => (int) $prog['id'],
                'program_code' => $progCode,
                'program_name' => (string) ($prog['program_name'] ?? $progCode),
                'program_head_name' => (string) ($prog['program_head_name'] ?? 'Unassigned'),
                'total_faculty' => $facultyCount,
                'total_assignments' => $total,
                'completed' => $completed,
                'pending' => (int) ($evalStats['pending'] ?? 0),
                'overdue' => (int) ($evalStats['overdue'] ?? 0),
                'completion_pct' => $completionPct,
                'average_score' => max((float) ($avgScore['avg_score'] ?? 0), (float) ($avgScoreA['avg_score'] ?? 0)),
                'all_evaluated' => $allProgramEvaluated,
                'fields' => $fields,
                'recommendation_status' => $recommendationStatus,
                'ai_recommendation' => $programRecommendation,
            ];
        }

        // AI-generated program analysis using completed results with status-aware caveats.
        $aiAnalysis = [];
        if ($programData !== []) {
            $weakest = null;
            $lowestPct = 101;
            foreach ($programData as $pd) {
                if ($pd['completion_pct'] < $lowestPct && $pd['total_assignments'] > 0) {
                    $lowestPct = $pd['completion_pct'];
                    $weakest = $pd;
                }
            }
            if ($weakest) {
                $aiAnalysis[] = [
                    'type' => 'attention',
                    'message' => "{$weakest['program_name']} needs attention with only {$weakest['completion_pct']}% completion rate ({$weakest['pending']} pending).",
                ];
            }
            $best = null;
            $highestPct = -1;
            foreach ($programData as $pd) {
                if ($pd['completion_pct'] > $highestPct && $pd['total_assignments'] > 0) {
                    $highestPct = $pd['completion_pct'];
                    $best = $pd;
                }
            }
            if ($best) {
                $aiAnalysis[] = [
                    'type' => 'praise',
                    'message' => "{$best['program_name']} is performing well with {$best['completion_pct']}% completion rate.",
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'data' => $programData,
            'department' => [
                'id' => (int) $department['id'],
                'department_code' => (string) ($department['department_code'] ?? ''),
                'department_name' => (string) ($department['department_name'] ?? ''),
                'dean_name' => (string) ($department['dean_name'] ?? ''),
            ],
            'aiAnalysis' => $aiAnalysis,
        ]);
        exit;
    }

    // ── Faculty under a program ──────────────────────────────────────
    if ($scope === 'faculty' && ($programId > 0 || $facultyId > 0)) {
        $program = null;
        $programCode = '';
        $facultyMembers = [];

        if ($facultyId > 0) {
            $facultyMembers = admin_all(
                "SELECT f.*, u.full_name AS user_name, u.email AS user_email, u.role AS user_role
                 FROM faculty f
                 LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
                 WHERE f.id = ? AND COALESCE(f.is_archived, 0) = 0
                 LIMIT 1",
                [$facultyId]
            );
            if ($facultyMembers === []) {
                echo json_encode(['ok' => false, 'message' => 'Faculty record not found.']);
                exit;
            }

            $programCode = (string) ($facultyMembers[0]['program_code'] ?? '');
            if ($programCode !== '') {
                $program = admin_one('SELECT * FROM programs WHERE program_code = :code AND is_active = 1 LIMIT 1', ['code' => $programCode]);
            }
            if ($program === null) {
                $program = [
                    'id' => 0,
                    'program_code' => $programCode !== '' ? $programCode : 'Direct Review',
                    'program_name' => $programCode !== '' ? $programCode : 'Direct Review',
                    'program_head_name' => '',
                ];
            }
        } else {
            $program = admin_one('SELECT * FROM programs WHERE id = :id', ['id' => $programId]);
            if ($program === null) {
                echo json_encode(['ok' => false, 'message' => 'Program not found.']);
                exit;
            }

            $programCode = (string) ($program['program_code'] ?? '');
            $deptId = (int) ($program['department_id'] ?? 0);
            $dept = admin_one('SELECT * FROM departments WHERE id = :id', ['id' => $deptId]);
            $aliases = $dept ? admin_department_aliases($dept) : [];
            $aliasPlaceholders = $aliases !== [] ? implode(',', array_fill(0, count($aliases), '?')) : '';

            $facultyMembers = admin_all(
                "SELECT f.*, u.full_name AS user_name, u.email AS user_email, u.role AS user_role
                 FROM faculty f
                 LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
                 WHERE f.is_archived = 0
                   AND f.program_code = ?
                   " . ($aliasPlaceholders !== '' ? "AND f.department IN ($aliasPlaceholders)" : "") . "
                 ORDER BY f.full_name",
                $aliasPlaceholders !== ''
                    ? array_merge([$programCode], $aliases)
                    : [$programCode]
            );
        }

        if ($periodId > 0) {
            $facultyMembers = array_values(array_filter($facultyMembers, static function (array $faculty) use ($periodId): bool {
                $linkedUserId = (int)($faculty['user_id'] ?? 0);
                return $linkedUserId <= 0 || !dipascaf_period_user_is_excluded($periodId, $linkedUserId);
            }));
        }

        $facultyData = [];
        foreach ($facultyMembers as $fac) {
            $facId = (int) $fac['id'];

            // Form B category results (faculty evaluations)
            $formBResults = admin_all(
                "SELECT r.assignment_id, c.title AS category_title, r.average_rating, r.factor_weight, r.weighted_score,
                        r.behavioral_evidence, r.reason_for_rating, r.recommendation,
                        r.ai_suggestion, r.submitted_at, pa.assignment_type,
                        COALESCE(pea.evaluator_id, r.evaluator_user_id, pa.evaluator_user_id) AS evaluator_id,
                        COALESCE(peer_user.full_name, result_user.full_name, u.full_name) AS evaluator_name,
                        COALESCE(peer_user.role, result_user.role, u.role, pa.evaluator_role) AS evaluator_role,
                        'Form B' AS form_name
                 FROM pmas_form_b_category_results r
                 JOIN pmas_form_b_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 LEFT JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
                 LEFT JOIN users peer_user ON peer_user.id = pea.evaluator_id
                 LEFT JOIN users result_user ON result_user.id = r.evaluator_user_id
                 LEFT JOIN users u ON u.id = pa.evaluator_user_id
                 WHERE r.evaluatee_faculty_id = :fac_id AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0
                   AND (pa.assignment_type <> 'peer' OR (pea.id IS NOT NULL AND COALESCE(pea.is_archived, 0) = 0))
                 " . ($periodFilter !== '' ? 'AND r.evaluation_period = :period_name' : '') . "
                 ORDER BY pa.submitted_at DESC, c.sort_order",
                $periodFilter !== ''
                    ? ['fac_id' => $facId, 'period_name' => $periodFilter]
                    : ['fac_id' => $facId]
            );

            // Form A category results (admin evaluations)
            $formAResults = admin_all(
                "SELECT r.assignment_id, c.title AS category_title, r.average_rating, r.factor_weight, r.weighted_score,
                        r.behavioral_evidence, r.reason_for_rating, r.recommendation,
                        r.ai_suggestion, r.submitted_at, pa.assignment_type,
                        COALESCE(pea.evaluator_id, r.evaluator_user_id, pa.evaluator_user_id) AS evaluator_id,
                        COALESCE(peer_user.full_name, result_user.full_name, u.full_name) AS evaluator_name,
                        COALESCE(peer_user.role, result_user.role, u.role, pa.evaluator_role) AS evaluator_role,
                        'Form A' AS form_name
                 FROM pmas_form_a_category_results r
                 JOIN pmas_form_a_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 LEFT JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
                 LEFT JOIN users peer_user ON peer_user.id = pea.evaluator_id
                 LEFT JOIN users result_user ON result_user.id = r.evaluator_user_id
                 LEFT JOIN users u ON u.id = pa.evaluator_user_id
                 WHERE r.evaluatee_faculty_id = :fac_id AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0
                   AND (pa.assignment_type <> 'peer' OR (pea.id IS NOT NULL AND COALESCE(pea.is_archived, 0) = 0))
                 " . ($periodFilter !== '' ? 'AND r.evaluation_period = :period_name' : '') . "
                 ORDER BY pa.submitted_at DESC, c.sort_order",
                $periodFilter !== ''
                    ? ['fac_id' => $facId, 'period_name' => $periodFilter]
                    : ['fac_id' => $facId]
            );

            // Assignments with evaluator info
            $evaluatorAssignments = admin_all(
                "SELECT pa.id, pa.assignment_type, pa.status AS assignment_status,
                        pea.id AS official_peer_id,
                        COALESCE(peer_user.full_name, pa.evaluator_name_snapshot, u.full_name) AS evaluator_name,
                        COALESCE(peer_user.role, pa.evaluator_role_snapshot, pa.evaluator_role, u.role) AS evaluator_role,
                        pa.submitted_at, pa.deadline, pa.is_current, pa.effective_from, pa.effective_to,
                        pa.replacement_reason
                 FROM peer_assignments pa
                 LEFT JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
                 LEFT JOIN users peer_user ON peer_user.id = pea.evaluator_id
                 LEFT JOIN users u ON u.id = pa.evaluator_user_id
                 WHERE pa.evaluatee_faculty_id = :fac_id
                   AND pa.assignment_type IN ('peer', 'dean', 'program_head', 'vpaa')
                   AND COALESCE(pa.is_archived, 0) = 0
                   AND (pa.assignment_type <> 'peer' OR (pea.id IS NOT NULL AND COALESCE(pea.is_archived, 0) = 0))
                 " . ($periodFilter !== '' ? 'AND pa.cycle_name = :period_name' : '') . "
                 ORDER BY FIELD(COALESCE(peer_user.role, u.role), 'vpaa', 'program_head', 'teacher', 'dean'),
                          FIELD(pa.assignment_type, 'vpaa', 'dean', 'program_head', 'peer', 'self'),
                          FIELD(pa.status, 'pending', 'submitted'),
                          COALESCE(peer_user.full_name, u.full_name),
                          pa.assigned_at DESC",
                $periodFilter !== ''
                    ? ['fac_id' => $facId, 'period_name' => $periodFilter]
                    : ['fac_id' => $facId]
            );

            // Use the assignment primary key as the canonical identity. Name/role based
            // deduplication discarded legitimate submissions from repeat evaluators.
            $uniqueEvaluatorAssignments = [];
            $hasPeerAssignment = false;
            foreach ($evaluatorAssignments as $assignmentRow) {
                if ((string) ($assignmentRow['assignment_type'] ?? '') === 'peer') {
                    if ((int) ($assignmentRow['official_peer_id'] ?? 0) === 0) {
                        continue;
                    }
                    $hasPeerAssignment = true;
                }

                $assignmentId = (int) ($assignmentRow['id'] ?? 0);
                if ($assignmentId <= 0) {
                    continue;
                }
                if (!isset($uniqueEvaluatorAssignments[$assignmentId])
                    || ((string) ($assignmentRow['assignment_status'] ?? '') === 'submitted'
                        && (string) ($uniqueEvaluatorAssignments[$assignmentId]['assignment_status'] ?? '') !== 'submitted')) {
                    $uniqueEvaluatorAssignments[$assignmentId] = $assignmentRow;
                }
            }

            // A completed category result is authoritative proof that its assignment was
            // submitted. Reconcile it into the canonical list so scores can never be shown
            // beside a misleading 0/0 completion count.
            foreach (array_merge($formBResults, $formAResults) as $resultRow) {
                $assignmentId = (int) ($resultRow['assignment_id'] ?? 0);
                if ($assignmentId <= 0) {
                    continue;
                }
                if (!isset($uniqueEvaluatorAssignments[$assignmentId])) {
                    $uniqueEvaluatorAssignments[$assignmentId] = [
                        'id' => $assignmentId,
                        'assignment_type' => (string) ($resultRow['assignment_type'] ?? ''),
                        'assignment_status' => 'submitted',
                        'official_peer_id' => 0,
                        'evaluator_name' => (string) ($resultRow['evaluator_name'] ?? 'Evaluator'),
                        'evaluator_role' => (string) ($resultRow['evaluator_role'] ?? ''),
                        'submitted_at' => (string) ($resultRow['submitted_at'] ?? ''),
                        'deadline' => '',
                        'is_current' => 0,
                        'effective_from' => '',
                        'effective_to' => '',
                        'replacement_reason' => '',
                    ];
                } else {
                    $uniqueEvaluatorAssignments[$assignmentId]['assignment_status'] = 'submitted';
                    if (trim((string) ($uniqueEvaluatorAssignments[$assignmentId]['submitted_at'] ?? '')) === '') {
                        $uniqueEvaluatorAssignments[$assignmentId]['submitted_at'] = (string) ($resultRow['submitted_at'] ?? '');
                    }
                }
            }
            $evaluatorAssignments = array_values($uniqueEvaluatorAssignments);
            $evalStats = [
                'total' => count($evaluatorAssignments),
                'completed' => count(array_filter($evaluatorAssignments, static fn (array $row): bool => (string) ($row['assignment_status'] ?? '') === 'submitted')),
                'pending' => count(array_filter($evaluatorAssignments, static fn (array $row): bool => (string) ($row['assignment_status'] ?? '') !== 'submitted')),
            ];
            $recommendationStatus = admin_monitor_completion_summary_from_assignments($evaluatorAssignments);
            $selfEvaluationSubmission = admin_monitor_self_evaluation_payload($facId, $periodFilter);

            if (!$hasPeerAssignment) {
                $peerPlaceholderDeadline = '';
                foreach ($evaluatorAssignments as $assignmentRow) {
                    $deadline = trim((string) ($assignmentRow['deadline'] ?? ''));
                    if ($deadline !== '') {
                        $peerPlaceholderDeadline = $deadline;
                        break;
                    }
                }

                $evaluatorAssignments[] = [
                    'id' => 0,
                    'assignment_type' => 'peer',
                    'assignment_status' => 'tba',
                    'official_peer_id' => 0,
                    'evaluator_name' => 'TBA',
                    'evaluator_role' => 'teacher',
                    'submitted_at' => '',
                    'deadline' => $peerPlaceholderDeadline,
                ];
            }

            // AI Insights
            $insights = admin_all(
                "SELECT weak_area, strength_area, analysis_summary, created_at
                 FROM ai_insights
                 WHERE faculty_id = :fac_id
                 ORDER BY created_at DESC",
                ['fac_id' => $facId]
            );

            // Intervention plans
            $interventions = admin_all(
                "SELECT weak_area, recommendation, action_type, status, target_date
                 FROM intervention_plans
                 WHERE faculty_id = :fac_id
                 ORDER BY target_date ASC",
                ['fac_id' => $facId]
            );

            // Calculate strengths and weaknesses from category results
            $allResults = array_merge($formBResults, $formAResults);
            $strengths = [];
            $weaknesses = [];
            $totalScore = 0;
            $scoreCount = 0;

            foreach ($allResults as $res) {
                $rating = (float) ($res['average_rating'] ?? 0);
                $totalScore += $rating;
                $scoreCount++;
                if ($rating >= 4.0) {
                    $strengths[] = [
                        'category' => (string) ($res['category_title'] ?? ''),
                        'score' => $rating,
                    ];
                } elseif ($rating <= 3.0) {
                    $weaknesses[] = [
                        'category' => (string) ($res['category_title'] ?? ''),
                        'score' => $rating,
                        'recommendation' => secure_decrypt_value($res['recommendation'] ?? ''),
                    ];
                }
            }

            // Sort strengths descending, weaknesses ascending
            usort($strengths, fn($a, $b) => $b['score'] <=> $a['score']);
            usort($weaknesses, fn($a, $b) => $a['score'] <=> $b['score']);

            $allEvalComplete = (int) ($evalStats['total'] ?? 0) > 0 && (int) ($evalStats['pending'] ?? 0) === 0;
            $primaryRecommendation = '';
            foreach ($interventions as $plan) {
                $text = trim((string) ($plan['recommendation'] ?? ''));
                if ($text !== '') {
                    $primaryRecommendation = $text;
                    break;
                }
            }
            if ((int) ($evalStats['total'] ?? 0) === 0) {
                $primaryRecommendation = '';
            } elseif ($primaryRecommendation === '' && $weaknesses !== []) {
                $primaryWeakness = $weaknesses[0];
                $primaryRecommendation = trim((string) ($primaryWeakness['recommendation'] ?? ''));
                if ($primaryRecommendation === '') {
                    $primaryRecommendation = admin_monitor_recommended_session((string) ($primaryWeakness['category'] ?? 'the identified weak area'));
                }
            }
            if ($primaryRecommendation === '' && $scoreCount > 0) {
                $primaryRecommendation = 'Maintain current strengths and continue regular coaching based on the latest completed evaluations.';
            }
            if ($primaryRecommendation === '' && (int) ($evalStats['total'] ?? 0) > 0) {
                $primaryRecommendation = 'No completed category results are available yet. Monitor pending evaluators before issuing a final intervention plan.';
            }
            $primaryRecommendation = admin_monitor_apply_recommendation_caveat($primaryRecommendation, $recommendationStatus);

            $facultyData[] = [
                'id' => $facId,
                'user_id' => (int) ($fac['user_id'] ?? 0),
                'full_name' => (string) ($fac['full_name'] ?? ''),
                'email' => (string) ($fac['email'] ?? ''),
                'position_title' => (string) ($fac['position_title'] ?? 'Faculty'),
                'department' => (string) ($fac['department'] ?? ''),
                'program_code' => (string) ($fac['program_code'] ?? ''),
                'total_assignments' => (int) ($evalStats['total'] ?? 0),
                'completed_evaluations' => (int) ($evalStats['completed'] ?? 0),
                'pending_evaluations' => (int) ($evalStats['pending'] ?? 0),
                'average_score' => $scoreCount > 0 ? round($totalScore / $scoreCount, 2) : null,
                'ai_recommendation' => $primaryRecommendation,
                'recommendation_status' => $recommendationStatus,
                'self_evaluation_submission' => $selfEvaluationSubmission,
                'category_results' => array_map(function ($r) {
                    $assignmentType = (string) ($r['assignment_type'] ?? '');
                    $evaluatorRole = (string) ($r['evaluator_role'] ?? '');
                    return [
                        'assignment_id' => (int) ($r['assignment_id'] ?? 0),
                        'form' => (string) ($r['form_name'] ?? ''),
                        'evaluator_id' => (int) ($r['evaluator_id'] ?? 0),
                        'evaluator_name' => (string) ($r['evaluator_name'] ?? 'Evaluator'),
                        'evaluator_role' => $evaluatorRole,
                        'assignment_type' => $assignmentType,
                        'evaluation_type' => admin_monitor_evaluation_type_label($assignmentType, $evaluatorRole),
                        'category' => (string) ($r['category_title'] ?? ''),
                        'score' => (float) ($r['average_rating'] ?? 0),
                        'weight' => (float) ($r['factor_weight'] ?? 0),
                        'weighted_score' => (float) ($r['weighted_score'] ?? 0),
                        'behavioral_evidence' => secure_decrypt_value($r['behavioral_evidence'] ?? ''),
                        'reason_for_rating' => secure_decrypt_value($r['reason_for_rating'] ?? ''),
                        'recommendation' => secure_decrypt_value($r['recommendation'] ?? ''),
                        'submitted_at' => (string) ($r['submitted_at'] ?? ''),
                    ];
                }, $allResults),
                'evaluator_assignments' => array_map(function ($a) {
                    $status = (string) ($a['assignment_status'] ?? '');
                    $assignmentType = (string) ($a['assignment_type'] ?? '');
                    $evaluatorName = (string) ($a['evaluator_name'] ?? 'Evaluator');
                    $type = admin_monitor_evaluation_type_label($assignmentType, (string) ($a['evaluator_role'] ?? ''));
                    return [
                        'id' => (int) $a['id'],
                        'type' => (string) ($a['assignment_type'] ?? ''),
                        'status' => $status,
                        'status_note' => $status === 'submitted'
                            ? ($assignmentType === 'program_head'
                                ? 'Submitted by ' . $evaluatorName . ', who was the Program Head at the time of submission.'
                                : 'Submitted by ' . $evaluatorName . '.')
                            : ($status === 'not_required'
                                ? $evaluatorName . ' is the current Program Head and will be assigned beginning with the next evaluation cycle.'
                                : ($status === 'reassigned'
                                    ? 'This evaluation was reassigned after the Program Head changed.'
                                    : 'Waiting for ' . $evaluatorName . ' to submit the ' . $type . '.')),
                        'evaluator_name' => $evaluatorName,
                        'evaluator_role' => (string) ($a['evaluator_role'] ?? ''),
                        'submitted_at' => (string) ($a['submitted_at'] ?? ''),
                        'deadline' => (string) ($a['deadline'] ?? ''),
                        'is_current' => (bool) ($a['is_current'] ?? false),
                        'effective_from' => (string) ($a['effective_from'] ?? ''),
                        'effective_to' => (string) ($a['effective_to'] ?? ''),
                        'replacement_reason' => (string) ($a['replacement_reason'] ?? ''),
                    ];
                }, $evaluatorAssignments),
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'ai_insights' => array_map(function ($i) {
                    return [
                        'weak_area' => (string) ($i['weak_area'] ?? ''),
                        'strength_area' => (string) ($i['strength_area'] ?? ''),
                        'analysis_summary' => (string) ($i['analysis_summary'] ?? ''),
                        'created_at' => (string) ($i['created_at'] ?? ''),
                    ];
                }, $insights),
                'interventions' => array_map(function ($p) {
                    return [
                        'weak_area' => (string) ($p['weak_area'] ?? ''),
                        'recommendation' => (string) ($p['recommendation'] ?? ''),
                        'action_type' => (string) ($p['action_type'] ?? ''),
                        'status' => (string) ($p['status'] ?? 'planned'),
                        'target_date' => (string) ($p['target_date'] ?? ''),
                    ];
                }, $interventions),
            ];
        }

        // AI-generated program performance summary — only when ALL faculty are fully evaluated
        $programSummary = '';
        $programAssignmentTotal = array_sum(array_column($facultyData, 'total_assignments'));
        $allFacultyFullyEvaluated = $facultyData !== []
            && $programAssignmentTotal > 0
            && count(array_filter($facultyData, static fn (array $fd): bool => $fd['pending_evaluations'] > 0)) === 0;
        if ($facultyData !== [] && $allFacultyFullyEvaluated) {
            $avgScores = array_filter(array_column($facultyData, 'average_score'));
            $overallAvg = $avgScores !== [] ? round(array_sum($avgScores) / count($avgScores), 2) : 0;
            $totalComplete = array_sum(array_column($facultyData, 'completed_evaluations'));
            $totalAssign = array_sum(array_column($facultyData, 'total_assignments'));
            $overallPct = $totalAssign > 0 ? round(($totalComplete / $totalAssign) * 100, 1) : 0;
            $programSummary = "Program {$programCode} has " . count($facultyData) . " faculty members with {$overallPct}% evaluation completion rate and overall average score of {$overallAvg}/5.";
        }

        echo json_encode([
            'ok' => true,
            'data' => $facultyData,
            'program' => [
                'id' => (int) $program['id'],
                'program_code' => $programCode,
                'program_name' => (string) ($program['program_name'] ?? $programCode),
                'program_head_name' => (string) ($program['program_head_name'] ?? 'Unassigned'),
            ],
            'summary' => $programSummary,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Invalid scope. Use departments, programs, faculty, or chatbot.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
