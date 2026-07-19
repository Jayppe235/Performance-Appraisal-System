<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';
require_once __DIR__ . '/evaluation_period.php';
require_once __DIR__ . '/evaluation_participation.php';

function dipascaf_required_evaluation_assignments(?int $evaluationPeriodId = null): array
{
    admin_ensure_faculty_program_schema();

    $rules = admin_all('SELECT * FROM evaluation_rules WHERE is_active = 1');
    if ($rules === []) {
        return [];
    }

    dipascaf_ensure_period_participation_schema();
    $participationSql = '';
    $participationParams = [];
    if ($evaluationPeriodId !== null && $evaluationPeriodId > 0) {
        $participationSql = " AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp WHERE epp.evaluation_period_id=:participation_period_id AND epp.user_id=u.id AND epp.participation_status='excluded')";
        $participationParams['participation_period_id'] = $evaluationPeriodId;
    }
    $users = admin_all(
        "SELECT u.id, u.full_name, u.role, u.email, u.is_active,
                f.id AS faculty_id, f.department
         FROM users u
         JOIN faculty f ON f.user_id = u.id
         WHERE u.is_active = 1
           AND u.role IN ('vpaa', 'dean', 'program_head', 'teacher')
           AND f.is_active = 1
           AND f.is_archived = 0{$participationSql}
         ORDER BY u.role, f.department, u.full_name"
    , $participationParams);

    if ($users === []) {
        return [];
    }

    $assignments = [];
    $seen = [];
    $userToFaculty = [];
    foreach ($users as $user) {
        $userToFaculty[(int) $user['id']] = (int) $user['faculty_id'];
    }

    foreach ($rules as $rule) {
        $evaluatorRole = (string) ($rule['evaluator_role'] ?? '');
        $evaluateeRole = (string) ($rule['evaluatee_role'] ?? '');
        $assignmentType = (string) ($rule['assignment_type'] ?? '');

        if ($assignmentType === 'peer') {
            continue;
        }

        foreach ($users as $evaluator) {
            if ((string) $evaluator['role'] !== $evaluatorRole) {
                continue;
            }

            foreach ($users as $evaluatee) {
                if ((string) $evaluatee['role'] !== $evaluateeRole) {
                    continue;
                }

                $evaluatorFacultyId = $userToFaculty[(int) $evaluator['id']] ?? 0;
                $evaluateeFacultyId = (int) $evaluatee['faculty_id'];

                if ($assignmentType === 'self') {
                    if ($evaluatorFacultyId !== $evaluateeFacultyId) {
                        continue;
                    }
                } else {
                    if ($evaluatorFacultyId === $evaluateeFacultyId) {
                        continue;
                    }
                    if (!dipascaf_assignment_relationship_allowed([
                        'evaluatee_faculty_id' => $evaluateeFacultyId,
                        'assignment_type' => $assignmentType,
                    ], (int) $evaluator['id'], $evaluatorRole)) {
                        continue;
                    }
                }

                $key = implode('|', [
                    (string) $evaluator['id'],
                    (string) $evaluateeFacultyId,
                    $assignmentType,
                ]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $assignments[] = [
                    'evaluator_user_id' => (int) $evaluator['id'],
                    'evaluatee_faculty_id' => $evaluateeFacultyId,
                    'evaluator_role' => $evaluatorRole,
                    'assignment_type' => $assignmentType,
                    'questionnaire_type' => dipascaf_assignment_questionnaire_type($evaluateeRole),
                ];
            }
        }
    }

    return $assignments;
}

function dipascaf_assignment_same_department(array $evaluator, array $evaluatee): bool
{
    $aliases = array_map(
        static fn (string $department): string => strtolower($department),
        admin_matching_department_aliases((string) ($evaluator['department'] ?? ''))
    );

    return in_array(strtolower((string) ($evaluatee['department'] ?? '')), $aliases, true);
}

function dipascaf_assignment_questionnaire_type(string $evaluateeRole): string
{
    return $evaluateeRole === 'teacher' ? 'faculty' : 'admin';
}

function dipascaf_program_head_transition_policy(array $rows, int $currentEvaluatorId): string
{
    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') === 'submitted') return 'official_submitted';
    }
    foreach ($rows as $row) {
        if ((int) ($row['evaluator_user_id'] ?? 0) !== $currentEvaluatorId
            && in_array((string) ($row['status'] ?? ''), ['pending', 'in_progress', 'reopened'], true)) {
            return 'reassign_active';
        }
    }
    return 'create';
}

function dipascaf_upsert_required_assignments_for_period(string $periodName, string $deadline): array
{
    $period = admin_one('SELECT id FROM appraisal_periods WHERE period_name=:name LIMIT 1', ['name'=>$periodName]);
    $assignments = dipascaf_required_evaluation_assignments($period ? (int)$period['id'] : null);
    if ($periodName === '' || $deadline === '' || $assignments === []) {
        return ['expected' => count($assignments), 'inserted' => 0, 'updated' => 0];
    }

    $db = db();
    $stmt = $db->prepare(
        "INSERT INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES
            (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, :evaluator_role, :assignment_type, :questionnaire_type, 'pending', NOW(), :deadline)
         ON DUPLICATE KEY UPDATE
            questionnaire_type = VALUES(questionnaire_type),
            deadline = VALUES(deadline),
            status = IF(status = 'submitted', status, 'pending')"
    );

    $inserted = 0;
    $updated = 0;
    foreach ($assignments as $assignment) {
        if ($assignment['assignment_type'] === 'program_head') {
            $resolution = dipascaf_reconcile_program_head_assignment($assignment, $periodName, $deadline);
            if ($resolution !== 'create') {
                $updated++;
                continue;
            }
        }
        $stmt->execute([
            'cycle_name' => $periodName,
            'evaluator_user_id' => $assignment['evaluator_user_id'],
            'evaluatee_faculty_id' => $assignment['evaluatee_faculty_id'],
            'evaluator_role' => $assignment['evaluator_role'],
            'assignment_type' => $assignment['assignment_type'],
            'questionnaire_type' => $assignment['questionnaire_type'],
            'deadline' => $deadline,
        ]);

        if ($stmt->rowCount() === 1) {
            $inserted++;
        } elseif ($stmt->rowCount() === 2) {
            $updated++;
        }
    }

    return ['expected' => count($assignments), 'inserted' => $inserted, 'updated' => $updated];
}

/**
 * Enforce one normal Program Head requirement per faculty and cycle.
 * Submitted work is immutable and remains official. A new Program Head is
 * recorded as current but not required until the next cycle. Pending work is
 * reassigned instead of duplicated.
 */
function dipascaf_reconcile_program_head_assignment(array $assignment, string $periodName, string $deadline): string
{
    $db = db();
    $evaluatorId = (int) $assignment['evaluator_user_id'];
    $facultyId = (int) $assignment['evaluatee_faculty_id'];
    $actorId = (int) ($_SESSION['user']['id'] ?? 0) ?: null;
    $evaluator = admin_one('SELECT full_name FROM users WHERE id = :id', ['id' => $evaluatorId]);
    $evaluatorName = trim((string) ($evaluator['full_name'] ?? 'Unknown evaluator'));

    $lock = $db->prepare(
        "SELECT * FROM peer_assignments
         WHERE cycle_name = :cycle AND evaluatee_faculty_id = :faculty
           AND assignment_type = 'program_head' AND COALESCE(is_archived, 0) = 0
         ORDER BY submitted_at DESC, id ASC FOR UPDATE"
    );
    $lock->execute(['cycle' => $periodName, 'faculty' => $facultyId]);
    $rows = $lock->fetchAll(PDO::FETCH_ASSOC);

    $submitted = null;
    $currentRow = null;
    foreach ($rows as $row) {
        if ((string) $row['status'] === 'submitted' && $submitted === null) $submitted = $row;
        if ((int) $row['evaluator_user_id'] === $evaluatorId) $currentRow = $row;
    }

    $policy = dipascaf_program_head_transition_policy($rows, $evaluatorId);
    if ($policy === 'official_submitted' && $submitted !== null) {
        $reason = 'Current Program Head starts with the next evaluation cycle because this cycle already has an official submitted Program Head evaluation.';
        $db->prepare(
            "UPDATE peer_assignments SET is_current = 0, effective_to = COALESCE(effective_to, NOW()),
             evaluator_name_snapshot = COALESCE(evaluator_name_snapshot, (SELECT full_name FROM users WHERE id = evaluator_user_id)),
             evaluator_role_snapshot = COALESCE(evaluator_role_snapshot, evaluator_role)
             WHERE id = :id"
        )->execute(['id' => (int) $submitted['id']]);

        if ($currentRow === null) {
            $db->prepare(
                "INSERT INTO peer_assignments
                 (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type,
                  questionnaire_type, status, assigned_at, effective_from, is_current, replacement_reason,
                  evaluator_name_snapshot, evaluator_role_snapshot, deadline)
                 VALUES (:cycle, :evaluator, :faculty, 'program_head', 'program_head', 'faculty',
                         'not_required', NOW(), NOW(), 1, :reason, :name, 'program_head', :deadline)"
            )->execute(['cycle' => $periodName, 'evaluator' => $evaluatorId, 'faculty' => $facultyId,
                'reason' => $reason, 'name' => $evaluatorName, 'deadline' => $deadline]);
            $currentId = (int) $db->lastInsertId();
        } else {
            $currentId = (int) $currentRow['id'];
            if ((string) $currentRow['status'] !== 'submitted') {
                $db->prepare(
                    "UPDATE peer_assignments SET status = 'not_required', is_current = 1,
                     effective_from = COALESCE(effective_from, NOW()), replacement_reason = :reason,
                     evaluator_name_snapshot = :name, evaluator_role_snapshot = 'program_head'
                     WHERE id = :id"
                )->execute(['reason' => $reason, 'name' => $evaluatorName, 'id' => $currentId]);
            }
        }
        dipascaf_log_assignment_history($currentId, $facultyId, $evaluatorId, $evaluatorName,
            'program_head', $periodName, 'not_required', (int) $submitted['id'], $reason, $actorId);
        return 'not_required';
    }

    $activeOld = null;
    foreach ($rows as $row) {
        if ((int) $row['evaluator_user_id'] !== $evaluatorId
            && in_array((string) $row['status'], ['pending', 'in_progress', 'reopened'], true)) {
            $activeOld = $row;
            break;
        }
    }
    if ($policy !== 'reassign_active' || $activeOld === null) return 'create';

    $reason = 'Program Head changed before the evaluation was submitted.';
    if ($currentRow === null) {
        $db->prepare(
            "INSERT INTO peer_assignments
             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type,
              questionnaire_type, status, assigned_at, effective_from, is_current, replacement_reason,
              evaluator_name_snapshot, evaluator_role_snapshot, deadline)
             VALUES (:cycle, :evaluator, :faculty, 'program_head', 'program_head', 'faculty',
                     'pending', NOW(), NOW(), 1, :reason, :name, 'program_head', :deadline)"
        )->execute(['cycle' => $periodName, 'evaluator' => $evaluatorId, 'faculty' => $facultyId,
            'reason' => $reason, 'name' => $evaluatorName, 'deadline' => $deadline]);
        $currentId = (int) $db->lastInsertId();
    } else {
        $currentId = (int) $currentRow['id'];
    }
    $db->prepare(
        "UPDATE peer_assignments SET status = 'reassigned', is_current = 0, effective_to = NOW(),
         replaced_by_assignment_id = :replacement, replacement_reason = :reason
         WHERE id = :id AND status IN ('pending', 'in_progress', 'reopened')"
    )->execute(['replacement' => $currentId, 'reason' => $reason, 'id' => (int) $activeOld['id']]);
    dipascaf_log_assignment_history($currentId, $facultyId, $evaluatorId, $evaluatorName,
        'program_head', $periodName, 'pending', (int) $activeOld['id'], $reason, $actorId);
    return 'reassigned';
}

function dipascaf_log_assignment_history(int $assignmentId, int $facultyId, int $evaluatorId,
    string $evaluatorName, string $type, string $cycle, string $status,
    ?int $previousId, string $reason, ?int $createdBy): void
{
    db()->prepare(
        "INSERT INTO evaluator_assignment_history
         (assignment_id, faculty_id, evaluator_id, evaluator_name, evaluator_role, evaluation_type,
          evaluation_cycle, effective_from, status, previous_assignment_id, replacement_reason, created_by)
         VALUES (:assignment, :faculty, :evaluator, :name, 'program_head', :type,
                 :cycle, NOW(), :status, :previous, :reason, :created_by)"
    )->execute(['assignment' => $assignmentId, 'faculty' => $facultyId, 'evaluator' => $evaluatorId,
        'name' => $evaluatorName, 'type' => $type, 'cycle' => $cycle, 'status' => $status,
        'previous' => $previousId, 'reason' => $reason, 'created_by' => $createdBy]);
}

function dipascaf_sync_period_assignment_deadlines(
    string $periodName,
    string $deadline,
    ?string $previousPeriodName = null,
    ?int $periodId = null
): array {
    $periodName = trim($periodName);
    $previousPeriodName = trim((string) $previousPeriodName);
    $deadline = trim($deadline);

    if ($periodName === '' || $deadline === '') {
        return ['assignments_updated' => 0, 'schedules_updated' => 0];
    }

    $db = db();
    $assignmentUpdates = 0;
    $scheduleUpdates = 0;

    if ($previousPeriodName !== '' && strcasecmp($previousPeriodName, $periodName) !== 0) {
        $stmt = $db->prepare(
            'UPDATE peer_assignments
             SET cycle_name = :period_name,
                 deadline = :deadline
             WHERE cycle_name = :previous_period_name
               AND COALESCE(is_archived, 0) = 0'
        );
        $stmt->execute([
            'period_name' => $periodName,
            'deadline' => $deadline,
            'previous_period_name' => $previousPeriodName,
        ]);
        $assignmentUpdates += $stmt->rowCount();
    }

    $stmt = $db->prepare(
        'UPDATE peer_assignments
         SET deadline = :deadline
         WHERE cycle_name = :period_name
           AND COALESCE(is_archived, 0) = 0
           AND (deadline IS NULL OR deadline <> :deadline_check)'
    );
    $stmt->execute([
        'deadline' => $deadline,
        'deadline_check' => $deadline,
        'period_name' => $periodName,
    ]);
    $assignmentUpdates += $stmt->rowCount();

    if ($periodId !== null && $periodId > 0) {
        $stmt = $db->prepare(
            'UPDATE evaluation_schedules
             SET due_date = :deadline
             WHERE evaluation_period_id = :period_id
               AND due_date <> :deadline_check'
        );
        $stmt->execute([
            'deadline' => $deadline,
            'deadline_check' => $deadline,
            'period_id' => $periodId,
        ]);
        $scheduleUpdates += $stmt->rowCount();
    }

    return [
        'assignments_updated' => $assignmentUpdates,
        'schedules_updated' => $scheduleUpdates,
    ];
}
