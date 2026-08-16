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
        $participationSql = " AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp WHERE epp.evaluation_period_id=:participation_period_id AND epp.user_id=u.id AND (epp.participation_status='excluded' OR epp.work_status='no_assignments'))";
        $participationParams['participation_period_id'] = $evaluationPeriodId;
    }
    $users = admin_all(
        "SELECT u.id, u.full_name,
                COALESCE(epp.role_snapshot,u.role) AS role,
                u.email,u.is_active,f.id AS faculty_id,
                COALESCE(epp.department_snapshot,f.department,u.department) AS department,
                COALESCE(epp.program_snapshot,f.program_code,u.program) AS program
         FROM users u
         JOIN faculty f ON f.user_id = u.id
         LEFT JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id=:context_period_id AND epp.user_id=u.id
         WHERE u.is_active = 1
           AND COALESCE(epp.role_snapshot,u.role) IN ('vpaa','dean','program_head','teacher')
           AND f.is_active = 1
           AND f.is_archived = 0{$participationSql}
         ORDER BY u.role, f.department, u.full_name"
    , ['context_period_id'=>(int)($evaluationPeriodId ?? 0)] + $participationParams);

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
                    ], (int) $evaluator['id'], $evaluatorRole, $evaluationPeriodId)) {
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

    // Every active participant must always have one period-specific self
    // evaluation, even when an installation's evaluation_rules table only
    // contains the legacy Faculty self rule.
    foreach ($users as $participant) {
        $userId = (int)($participant['id'] ?? 0);
        $facultyId = (int)($participant['faculty_id'] ?? 0);
        $role = (string)($participant['role'] ?? '');
        if ($userId <= 0 || $facultyId <= 0 || $role === '') {
            continue;
        }
        $key = implode('|', [(string)$userId, (string)$facultyId, 'self']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $assignments[] = [
            'evaluator_user_id' => $userId,
            'evaluatee_faculty_id' => $facultyId,
            'evaluator_role' => $role,
            'assignment_type' => 'self',
            'questionnaire_type' => dipascaf_assignment_questionnaire_type($role),
        ];
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

/**
 * Synchronize only the newly created/updated participant's evaluatee-side
 * requirements. This avoids rebuilding every evaluator/evaluatee pair in the
 * institution after a single account save.
 */
function dipascaf_upsert_user_requirements_for_period(
    int $userId,
    int $evaluationPeriodId,
    string $periodName,
    string $deadline
): array {
    if ($userId <= 0 || $evaluationPeriodId <= 0 || $periodName === '' || $deadline === '') {
        return ['expected' => 0, 'inserted' => 0, 'updated' => 0];
    }

    $participant = admin_one(
        "SELECT u.id user_id,COALESCE(epp.role_snapshot,u.role) role,
                f.id faculty_id,
                COALESCE(epp.department_id,d.id) department_id,
                COALESCE(epp.program_id,p.id) program_id
         FROM users u
         JOIN faculty f ON f.user_id=u.id
         LEFT JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id=:period_id AND epp.user_id=u.id
         LEFT JOIN departments d
           ON d.department_name=COALESCE(NULLIF(epp.department_snapshot,''),f.department,u.department)
           OR d.department_code=COALESCE(NULLIF(epp.department_snapshot,''),f.department,u.department)
         LEFT JOIN programs p
           ON UPPER(p.program_code)=UPPER(COALESCE(NULLIF(epp.program_snapshot,''),f.program_code,u.program))
         WHERE u.id=:user_id AND u.is_active=1
           AND f.is_active=1 AND COALESCE(f.is_archived,0)=0
           AND (epp.id IS NULL OR (epp.participation_status='included' AND COALESCE(epp.work_status,'active')<>'no_assignments'))
         LIMIT 1",
        ['period_id'=>$evaluationPeriodId,'user_id'=>$userId]
    );
    if ($participant === null) {
        return ['expected' => 0, 'inserted' => 0, 'updated' => 0];
    }

    $role = (string)$participant['role'];
    $facultyId = (int)$participant['faculty_id'];
    $requirements = [[
        'evaluator_user_id'=>$userId,
        'evaluator_role'=>$role,
        'assignment_type'=>'self',
    ]];

    if (in_array($role, ['teacher','program_head'], true)) {
        $departmentId = (int)($participant['department_id'] ?? 0);
        $dean = $departmentId > 0 ? admin_one(
            "SELECT COALESCE(epd.user_id,d.dean_user_id) user_id
             FROM departments d
             LEFT JOIN evaluation_period_deans epd
               ON epd.evaluation_period_id=:period_id AND epd.department_id=d.id
             JOIN users du ON du.id=COALESCE(epd.user_id,d.dean_user_id)
             WHERE d.id=:department_id AND d.is_active=1 AND du.is_active=1
             LIMIT 1",
            ['period_id'=>$evaluationPeriodId,'department_id'=>$departmentId]
        ) : null;
        if ((int)($dean['user_id'] ?? 0) > 0 && (int)$dean['user_id'] !== $userId) {
            $requirements[] = [
                'evaluator_user_id'=>(int)$dean['user_id'],
                'evaluator_role'=>'dean',
                'assignment_type'=>'dean',
            ];
        }
    }

    if ($role === 'teacher') {
        $programId = (int)($participant['program_id'] ?? 0);
        $programHead = $programId > 0 ? admin_one(
            "SELECT COALESCE(
                    (SELECT epph.user_id FROM evaluation_period_program_heads epph
                     WHERE epph.evaluation_period_id=:period_id_role AND epph.program_id=p.id
                     ORDER BY epph.is_lead_evaluator DESC,epph.is_primary DESC,epph.id LIMIT 1),
                    p.program_head_user_id
                ) user_id,
                (SELECT role FROM users WHERE id=COALESCE(
                    (SELECT epph.user_id FROM evaluation_period_program_heads epph
                     WHERE epph.evaluation_period_id=:period_id AND epph.program_id=p.id
                     ORDER BY epph.is_lead_evaluator DESC,epph.is_primary DESC,epph.id LIMIT 1),
                    p.program_head_user_id
                )) user_role
             FROM programs p
             WHERE p.id=:program_id AND p.is_active=1
             LIMIT 1",
            ['period_id'=>$evaluationPeriodId,'period_id_role'=>$evaluationPeriodId,'program_id'=>$programId]
        ) : null;
        // A Dean may also be assigned administrative responsibility for a
        // program. That assignment must not create a second Program Head
        // evaluation; the same person evaluates strictly in the Dean role.
        if ((int)($programHead['user_id'] ?? 0) > 0
            && (int)$programHead['user_id'] !== $userId
            && (string)($programHead['user_role'] ?? '') !== 'dean') {
            $requirements[] = [
                'evaluator_user_id'=>(int)$programHead['user_id'],
                'evaluator_role'=>'program_head',
                'assignment_type'=>'program_head',
            ];
        }
    }

    $statement = db()->prepare(
        "INSERT INTO peer_assignments
         (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,
          questionnaire_type,status,assigned_at,deadline)
         VALUES (:cycle,:evaluator,:faculty,:evaluator_role,:assignment_type,:questionnaire,'pending',NOW(),:deadline)
         ON DUPLICATE KEY UPDATE evaluator_role=VALUES(evaluator_role),
          questionnaire_type=VALUES(questionnaire_type),deadline=VALUES(deadline),
          status=IF(status='submitted',status,'pending'),is_archived=0,archived_at=NULL,archived_by=NULL"
    );
    $inserted = $updated = 0;
    foreach ($requirements as $requirement) {
        $statement->execute([
            'cycle'=>$periodName,
            'evaluator'=>$requirement['evaluator_user_id'],
            'faculty'=>$facultyId,
            'evaluator_role'=>$requirement['evaluator_role'],
            'assignment_type'=>$requirement['assignment_type'],
            'questionnaire'=>dipascaf_assignment_questionnaire_type($role),
            'deadline'=>$deadline,
        ]);
        if ($statement->rowCount() === 1) $inserted++;
        elseif ($statement->rowCount() === 2) $updated++;
    }
    return ['expected'=>count($requirements),'inserted'=>$inserted,'updated'=>$updated];
}

/**
 * Synchronize an account with every actionable evaluation period at or after
 * its configured start period. The reconciliation is intentionally additive:
 * completed work is never removed or reassigned, while missing evaluator- and
 * evaluatee-side requirements are inserted through the existing idempotent
 * upsert path.
 */
function dipascaf_sync_account_evaluation_periods(int $userId, int $actorId = 0): array
{
    if ($userId <= 0) {
        return ['periods' => 0, 'assignments' => 0, 'peer_assignments' => 0, 'peer_review_required' => 0];
    }

    $account = admin_one(
        'SELECT id,role,is_active,start_evaluation_period_id FROM users WHERE id=:id LIMIT 1',
        ['id' => $userId]
    );
    if ($account === null || (int)$account['is_active'] !== 1
        || !in_array((string)$account['role'], ['dean','program_head','teacher'], true)
        || (int)($account['start_evaluation_period_id'] ?? 0) <= 0) {
        return ['periods' => 0, 'assignments' => 0, 'peer_assignments' => 0, 'peer_review_required' => 0];
    }

    dipascaf_sync_user_start_period($userId, $actorId);
    $periods = admin_all(
        "SELECT ap.id,ap.period_name,ap.date_end
         FROM appraisal_periods ap
         JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id=ap.id AND epp.user_id=:user_id
         WHERE ap.status IN ('draft','open')
           AND epp.participation_status='included'
           AND COALESCE(epp.work_status,'active')='active'
           AND epp.employment_status IN ('active','newly_added')
         ORDER BY ap.id",
        ['user_id' => $userId]
    );

    $summary = ['periods' => 0, 'assignments' => 0, 'peer_assignments' => 0, 'peer_review_required' => 0];
    foreach ($periods as $period) {
        $periodId = (int)$period['id'];
        $periodName = (string)$period['period_name'];
        $deadline = (string)($period['date_end'] ?: date('Y-m-d', strtotime('+30 days')));
        $result = dipascaf_upsert_required_assignments_for_period($periodName, $deadline);
        $summary['periods']++;
        $summary['assignments'] += (int)$result['inserted'] + (int)$result['updated'];

        // Peer synchronization is optional at this layer so the core helper can
        // also be reused by maintenance scripts that do not load the algorithm.
        if (function_exists('dipascaf_sync_incremental_peers_for_account')) {
            $peer = dipascaf_sync_incremental_peers_for_account($userId, $periodId, $periodName, $deadline);
            $summary['peer_assignments'] += (int)($peer['created'] ?? 0);
            if (!empty($peer['review_required'])) {
                $summary['peer_review_required']++;
                db()->prepare(
                    'UPDATE appraisal_periods SET peer_assignments_validated_at=NULL,peer_assignments_validated_by=NULL WHERE id=?'
                )->execute([$periodId]);
            }
        }
    }

    return $summary;
}

/** Repair missing active/draft-period snapshots and requirements safely. */
function dipascaf_repair_actionable_period_account_sync(int $actorId = 0): array
{
    $users = admin_all(
        "SELECT DISTINCT u.id
         FROM users u
         JOIN appraisal_periods start_period ON start_period.id=u.start_evaluation_period_id
         WHERE u.is_active=1 AND u.role IN ('dean','program_head','teacher')
           AND EXISTS (SELECT 1 FROM appraisal_periods ap WHERE ap.status IN ('draft','open'))
         ORDER BY u.id"
    );
    $summary = ['users' => 0, 'periods' => 0, 'assignments' => 0, 'peer_assignments' => 0, 'peer_review_required' => 0];
    foreach ($users as $user) {
        dipascaf_sync_user_start_period((int)$user['id'], $actorId);
        $summary['users']++;
    }

    $periods = admin_all("SELECT id,period_name,date_end FROM appraisal_periods WHERE status IN ('draft','open') ORDER BY id");
    foreach ($periods as $period) {
        $periodId = (int)$period['id'];
        $periodName = (string)$period['period_name'];
        $deadline = (string)($period['date_end'] ?: date('Y-m-d', strtotime('+30 days')));
        $result = dipascaf_upsert_required_assignments_for_period($periodName, $deadline);
        $summary['periods']++;
        $summary['assignments'] += (int)$result['inserted'] + (int)$result['updated'];

        if (!function_exists('dipascaf_generate_peer_evaluation_assignments')) continue;
        $requiresReview = false;
        foreach (['department','dean'] as $peerGroup) {
            try {
                $peer = dipascaf_generate_peer_evaluation_assignments(
                    $periodId, $periodName, $deadline, true, false, [], $peerGroup
                );
                $summary['peer_assignments'] += (int)($peer['created'] ?? 0);
                $requiresReview = $requiresReview || !empty($peer['invalidGroups']);
            } catch (RuntimeException) {
                $requiresReview = true;
            }
        }
        if ($requiresReview) {
            $summary['peer_review_required']++;
            db()->prepare('UPDATE appraisal_periods SET peer_assignments_validated_at=NULL,peer_assignments_validated_by=NULL WHERE id=?')
                ->execute([$periodId]);
        }
    }
    return $summary;
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
    $hasReplacementReason = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'replacement_reason'") !== null;
    $stmt = $db->prepare(
        "INSERT INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES
            (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, :evaluator_role, :assignment_type, :questionnaire_type, 'pending', NOW(), :deadline)
         ON DUPLICATE KEY UPDATE
            evaluator_role = VALUES(evaluator_role),
            questionnaire_type = VALUES(questionnaire_type),
            deadline = VALUES(deadline),
            status = IF(status = 'submitted', status, 'pending'),
            is_archived = 0,
            archived_at = NULL,
            archived_by = NULL"
            . ($hasReplacementReason ? ", replacement_reason = IF(status = 'submitted', replacement_reason, NULL)" : '')
    );

    $inserted = 0;
    $updated = 0;
    foreach ($assignments as $assignment) {
        if ($assignment['assignment_type'] === 'program_head'
            && $assignment['evaluator_role'] === 'program_head') {
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
 * Submitted work is immutable and remains historical. The Program Head assigned
 * by the administrator for this period still receives a current requirement;
 * pending work is reassigned instead of silently hiding the current head.
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
        $reason = 'Current Program Head assigned by Admin for this evaluation period; the earlier submitted evaluation remains unchanged.';

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
            if ((string) $currentRow['status'] !== 'submitted') {
                $db->prepare(
                    "UPDATE peer_assignments SET status = 'pending', is_current = 1,
                     effective_from = COALESCE(effective_from, NOW()), replacement_reason = :reason,
                     evaluator_name_snapshot = :name, evaluator_role_snapshot = 'program_head',
                     is_archived = 0, archived_at = NULL, archived_by = NULL, deadline = :deadline
                     WHERE id = :id"
                )->execute(['reason' => $reason, 'name' => $evaluatorName, 'deadline' => $deadline, 'id' => $currentId]);
            }
        }
        dipascaf_log_assignment_history($currentId, $facultyId, $evaluatorId, $evaluatorName,
            'program_head', $periodName, 'pending', (int) $submitted['id'], $reason, $actorId);
        return 'current_added';
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
