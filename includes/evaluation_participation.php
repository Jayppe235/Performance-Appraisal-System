<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';
require_once __DIR__ . '/subject_assignments.php';

final class PeriodProgramHeadConflictException extends DomainException
{
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('One or more selected programs already have an active Program Head for this evaluation period.');
    }
}

final class PeriodDeanConflictException extends DomainException
{
    public function __construct(public readonly array $conflict)
    {
        parent::__construct('This department already has a Dean for the selected evaluation period.');
    }
}

function dipascaf_ensure_period_participation_schema(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    subject_assignments_ensure_schema();

    db()->exec("CREATE TABLE IF NOT EXISTS evaluation_period_participation (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        evaluation_period_id INT NOT NULL,
        user_id INT NOT NULL,
        faculty_id INT NULL,
        participation_status ENUM('included','excluded') NOT NULL DEFAULT 'included',
        exclusion_reason ENUM('resignation','retirement','leave','transfer','role_change','other') NULL,
        notes VARCHAR(1000) NULL,
        changed_by_user_id INT NULL,
        excluded_at DATETIME NULL,
        reincluded_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period_participation (evaluation_period_id,user_id),
        KEY idx_period_participation_status (evaluation_period_id,participation_status),
        KEY idx_period_participation_user (user_id,evaluation_period_id),
        CONSTRAINT fk_period_participation_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE,
        CONSTRAINT fk_period_participation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_period_participation_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE SET NULL,
        CONSTRAINT fk_period_participation_actor FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $columns = [
        'role_snapshot' => "ALTER TABLE evaluation_period_participation ADD COLUMN role_snapshot ENUM('teacher','program_head','dean') NULL AFTER faculty_id",
        'department_id' => 'ALTER TABLE evaluation_period_participation ADD COLUMN department_id INT NULL AFTER role_snapshot',
        'program_id' => 'ALTER TABLE evaluation_period_participation ADD COLUMN program_id INT NULL AFTER department_id',
        'department_snapshot' => 'ALTER TABLE evaluation_period_participation ADD COLUMN department_snapshot VARCHAR(190) NULL AFTER program_id',
        'program_snapshot' => 'ALTER TABLE evaluation_period_participation ADD COLUMN program_snapshot VARCHAR(40) NULL AFTER department_snapshot',
        'assignment_source' => "ALTER TABLE evaluation_period_participation ADD COLUMN assignment_source ENUM('master','inferred','admin') NOT NULL DEFAULT 'master' AFTER program_snapshot",
        'needs_review' => 'ALTER TABLE evaluation_period_participation ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER assignment_source',
        'program_head_slot' => 'ALTER TABLE evaluation_period_participation ADD COLUMN program_head_slot INT NULL AFTER needs_review',
        'work_status' => "ALTER TABLE evaluation_period_participation ADD COLUMN work_status ENUM('active','no_assignments') NOT NULL DEFAULT 'active' AFTER participation_status",
        'employment_status' => "ALTER TABLE evaluation_period_participation ADD COLUMN employment_status ENUM('active','newly_added','not_yet_employed','on_leave','inactive') NOT NULL DEFAULT 'active' AFTER work_status",
    ];
    foreach ($columns as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM evaluation_period_participation LIKE '{$column}'") === null) {
            db()->exec($sql);
        }
    }
    if (admin_one("SHOW INDEX FROM evaluation_period_participation WHERE Key_name='uq_period_program_head_slot'") !== null) {
        db()->exec('ALTER TABLE evaluation_period_participation DROP INDEX uq_period_program_head_slot');
    }
    if (admin_one("SHOW INDEX FROM evaluation_period_participation WHERE Key_name='idx_period_participation_assignment'") === null) {
        db()->exec('ALTER TABLE evaluation_period_participation ADD KEY idx_period_participation_assignment (evaluation_period_id,role_snapshot,department_id,program_id)');
    }
    $foreignKeys = [
        'fk_period_participation_department' => 'ALTER TABLE evaluation_period_participation ADD CONSTRAINT fk_period_participation_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL',
        'fk_period_participation_program' => 'ALTER TABLE evaluation_period_participation ADD CONSTRAINT fk_period_participation_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL',
    ];
    foreach ($foreignKeys as $constraint => $sql) {
        $found = admin_one(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME="evaluation_period_participation"
               AND CONSTRAINT_NAME=:name LIMIT 1',
            ['name'=>$constraint]
        );
        if ($found === null) db()->exec($sql);
    }
    db()->exec("ALTER TABLE evaluation_period_participation MODIFY role_snapshot ENUM('teacher','program_head','dean') NULL");
    db()->exec("CREATE TABLE IF NOT EXISTS evaluation_period_deans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,evaluation_period_id INT NOT NULL,department_id INT NOT NULL,user_id INT NOT NULL,
        is_acting TINYINT(1) NOT NULL DEFAULT 0,assignment_source ENUM('master','inferred','admin') NOT NULL DEFAULT 'admin',
        authorization_reason VARCHAR(500) NULL,replaced_user_id INT NULL,
        replaced_dean_action ENUM('faculty','excluded','no_assignments') NULL,authorized_by_user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period_department_dean (evaluation_period_id,department_id),
        UNIQUE KEY uq_period_dean_user_department (evaluation_period_id,user_id,department_id),
        KEY idx_period_deans_user (user_id,evaluation_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS evaluation_period_program_heads (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        evaluation_period_id INT NOT NULL,
        user_id INT NOT NULL,
        department_id INT NOT NULL,
        program_id INT NOT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        is_lead_evaluator TINYINT(1) NOT NULL DEFAULT 0,
        co_head_authorized TINYINT(1) NOT NULL DEFAULT 0,
        co_head_reason VARCHAR(500) NULL,
        authorized_by_user_id INT NULL,
        assignment_source ENUM('master','inferred','admin') NOT NULL DEFAULT 'admin',
        lead_program_slot INT GENERATED ALWAYS AS (
          CASE WHEN is_lead_evaluator=1 THEN program_id ELSE NULL END
        ) STORED,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period_head_program (evaluation_period_id,user_id,program_id),
        UNIQUE KEY uq_period_program_lead (evaluation_period_id,lead_program_slot),
        KEY idx_period_program_heads_scope (evaluation_period_id,program_id,is_lead_evaluator),
        KEY idx_period_program_heads_user (user_id,evaluation_period_id),
        CONSTRAINT fk_epph_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE,
        CONSTRAINT fk_epph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_epph_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
        CONSTRAINT fk_epph_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
        CONSTRAINT fk_epph_authorizer FOREIGN KEY (authorized_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (admin_one("SHOW COLUMNS FROM evaluation_period_program_heads LIKE 'lead_program_slot'") === null) {
        db()->exec("ALTER TABLE evaluation_period_program_heads
            ADD COLUMN lead_program_slot INT GENERATED ALWAYS AS (
              CASE WHEN is_lead_evaluator=1 THEN program_id ELSE NULL END
            ) STORED AFTER assignment_source,
            ADD UNIQUE KEY uq_period_program_lead (evaluation_period_id,lead_program_slot)");
    }

    try {
        db()->exec("ALTER TABLE peer_evaluation_assignments MODIFY status ENUM('pending','completed','overdue','not_required') NOT NULL DEFAULT 'pending'");
    } catch (Throwable) {
        // Older installations may not have the dedicated peer table yet.
    }

    if (admin_one("SHOW COLUMNS FROM users LIKE 'start_evaluation_period_id'") === null) {
        db()->exec('ALTER TABLE users ADD COLUMN start_evaluation_period_id INT NULL AFTER program');
        db()->exec('ALTER TABLE users ADD KEY idx_users_start_evaluation_period (start_evaluation_period_id)');
    }
    foreach ([
        'participants_finalized_at' => 'ALTER TABLE appraisal_periods ADD COLUMN participants_finalized_at DATETIME NULL AFTER opened_at',
        'participants_finalized_by' => 'ALTER TABLE appraisal_periods ADD COLUMN participants_finalized_by INT NULL AFTER participants_finalized_at',
        'peer_assignments_validated_at' => 'ALTER TABLE appraisal_periods ADD COLUMN peer_assignments_validated_at DATETIME NULL AFTER participants_finalized_by',
        'peer_assignments_validated_by' => 'ALTER TABLE appraisal_periods ADD COLUMN peer_assignments_validated_by INT NULL AFTER peer_assignments_validated_at',
    ] as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM appraisal_periods LIKE '{$column}'") === null) {
            db()->exec($sql);
        }
    }
}

function dipascaf_period_participation_is_finalized(int $periodId): bool
{
    $row = admin_one(
        'SELECT participants_finalized_at FROM appraisal_periods WHERE id=:id LIMIT 1',
        ['id'=>$periodId]
    );
    return !empty($row['participants_finalized_at']);
}

function dipascaf_assert_period_participation_editable(int $periodId): array
{
    $period = admin_one('SELECT * FROM appraisal_periods WHERE id=:id LIMIT 1', ['id'=>$periodId]);
    if (!$period) throw new DomainException('Evaluation period was not found.');
    if (!empty($period['participants_finalized_at'])) {
        throw new DomainException('Participants are finalized for this period. Reopen them before making changes.');
    }
    if ((string)($period['status'] ?? '') === 'open') {
        throw new DomainException('Participants cannot be changed while the evaluation period is active.');
    }
    return $period;
}

function dipascaf_period_start_year(array $period): int
{
    foreach (['school_year', 'period_name', 'date_start'] as $field) {
        if (preg_match('/\b(20\d{2})\b/', (string)($period[$field] ?? ''), $matches) === 1) {
            return (int)$matches[1];
        }
    }
    return 0;
}

/**
 * Synchronize one account into its explicitly selected start period.
 * This is intentionally allowed for an open period: creating or editing an
 * account with that start period is an explicit administrative roster action.
 */
function dipascaf_sync_user_start_period(int $userId, int $actorId = 0): void
{
    dipascaf_ensure_period_participation_schema();
    $row = admin_one(
        "SELECT u.id user_id,u.role,u.is_active,u.start_evaluation_period_id,
                f.id faculty_id,u.department department_snapshot,u.program program_snapshot,
                d.id department_id,p.id program_id,
                sp.period_name start_period_name,sp.school_year start_school_year,sp.date_start start_date
         FROM users u
         LEFT JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
         LEFT JOIN departments d ON d.department_name=u.department OR d.department_code=u.department
         LEFT JOIN programs p ON UPPER(p.program_code)=UPPER(u.program)
          AND (d.id IS NULL OR p.department_id=d.id)
         LEFT JOIN appraisal_periods sp ON sp.id=u.start_evaluation_period_id
         WHERE u.id=:user_id LIMIT 1",
        ['user_id'=>$userId]
    );
    if (!$row || empty($row['start_evaluation_period_id'])
        || !in_array((string)$row['role'], ['teacher','program_head','dean'], true)) {
        return;
    }

    $roleSnapshot = $row['role'] === 'dean'
        ? 'dean'
        : ($row['role'] === 'program_head' ? 'program_head' : 'teacher');
    $included = (int)$row['is_active'] === 1;
    $startYear = dipascaf_period_start_year([
        'period_name'=>$row['start_period_name'] ?? '',
        'school_year'=>$row['start_school_year'] ?? '',
        'date_start'=>$row['start_date'] ?? '',
    ]);
    $eligiblePeriods = admin_all('SELECT id,period_name,school_year,date_start FROM appraisal_periods ORDER BY id');
    $stmt = db()->prepare(
        "INSERT INTO evaluation_period_participation
          (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,program_id,
           department_snapshot,program_snapshot,assignment_source,needs_review,
           participation_status,work_status,employment_status,changed_by_user_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           faculty_id=VALUES(faculty_id),role_snapshot=VALUES(role_snapshot),
           department_id=VALUES(department_id),program_id=VALUES(program_id),
           department_snapshot=VALUES(department_snapshot),program_snapshot=VALUES(program_snapshot),
           assignment_source='master',needs_review=VALUES(needs_review),
           participation_status=CASE
             WHEN VALUES(employment_status) IN ('inactive','not_yet_employed') THEN 'excluded'
             WHEN employment_status='not_yet_employed' THEN VALUES(participation_status)
             ELSE participation_status END,
           work_status=CASE
             WHEN VALUES(employment_status) IN ('inactive','not_yet_employed') THEN 'no_assignments'
             WHEN employment_status='not_yet_employed' THEN VALUES(work_status)
             ELSE work_status END,
           employment_status=CASE
             WHEN VALUES(employment_status) IN ('inactive','not_yet_employed') THEN VALUES(employment_status)
             WHEN employment_status='not_yet_employed' THEN VALUES(employment_status)
             ELSE employment_status END,
           changed_by_user_id=VALUES(changed_by_user_id)"
    );
    foreach ($eligiblePeriods as $period) {
        $periodYear = dipascaf_period_start_year($period);
        $beforeStart = $startYear > 0 && $periodYear > 0
            ? $periodYear < $startYear
            : ((string)($period['date_start'] ?? '') !== ''
                && (string)($row['start_date'] ?? '') !== ''
                && (string)$period['date_start'] < (string)$row['start_date']);

        $isStart = (int)$period['id'] === (int)$row['start_evaluation_period_id'];
        $stmt->execute([
            (int)$period['id'],
            $userId,
            $row['faculty_id'] ?: null,
            $roleSnapshot,
            $row['department_id'] ?: null,
            $row['program_id'] ?: null,
            $row['department_snapshot'] ?: null,
            $row['program_snapshot'] ?: null,
            'master',
            empty($row['department_id']) || ($roleSnapshot === 'program_head' && empty($row['program_id'])) ? 1 : 0,
            $included && !$beforeStart ? 'included' : 'excluded',
            $included && !$beforeStart ? 'active' : 'no_assignments',
            $included ? ($beforeStart ? 'not_yet_employed' : ($isStart ? 'newly_added' : 'active')) : 'inactive',
            $actorId > 0 ? $actorId : null,
        ]);

        // A roster entry that predates employment must never remain actionable.
        // Keep submitted history intact, but close every unfinished assignment on
        // either side of the employment boundary.
        if ($beforeStart && !empty($row['faculty_id'])) {
            // Period role mappings describe the actionable roster, not submitted
            // history. Remove stale mappings that predate this user's employment.
            db()->prepare(
                'DELETE FROM evaluation_period_program_heads WHERE evaluation_period_id=? AND user_id=?'
            )->execute([(int)$period['id'],$userId]);
            db()->prepare(
                'DELETE FROM evaluation_period_deans WHERE evaluation_period_id=? AND user_id=?'
            )->execute([(int)$period['id'],$userId]);
            db()->prepare(
                "UPDATE peer_assignments
                 SET status='not_required'
                 WHERE cycle_name=? AND (evaluator_user_id=? OR evaluatee_faculty_id=?)
                   AND status IN ('pending','in_progress','reopened')
                   AND COALESCE(is_archived,0)=0"
            )->execute([(string)$period['period_name'],$userId,(int)$row['faculty_id']]);
            db()->prepare(
                "UPDATE peer_evaluation_assignments
                 SET status='not_required'
                 WHERE evaluation_period_id=? AND (evaluator_id=? OR evaluatee_id=?)
                   AND status IN ('pending','overdue') AND COALESCE(is_archived,0)=0"
            )->execute([(int)$period['id'],$userId,$userId]);
        }

        if ($included && !$beforeStart && !empty($row['faculty_id'])) {
            subject_assignments_snapshot_faculty(
                db(),
                (int)$period['id'],
                $userId,
                (int)$row['faculty_id']
            );
        }
    }
}

function dipascaf_seed_period_participants(int $periodId, int $actorId = 0): int
{
    dipascaf_ensure_period_participation_schema();
    $period = dipascaf_assert_period_participation_editable($periodId);
    $dateStart = (string)($period['date_start'] ?? '');
    $periodStartYear = dipascaf_period_start_year($period);
    $eligible = admin_all(
        "SELECT u.id user_id,u.start_evaluation_period_id,f.id faculty_id,
                CASE WHEN u.role='dean' THEN 'dean' WHEN u.role='program_head' THEN 'program_head' ELSE 'teacher' END role_snapshot,
                u.department department_snapshot,u.program program_snapshot,d.id department_id,p.id program_id,
                sp.date_start start_date,sp.school_year start_school_year,sp.period_name start_period_name
         FROM users u
         LEFT JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
         LEFT JOIN departments d ON d.department_name=u.department OR d.department_code=u.department
         LEFT JOIN programs p ON p.program_code=u.program AND (d.id IS NULL OR p.department_id=d.id)
         LEFT JOIN appraisal_periods sp ON sp.id=u.start_evaluation_period_id
         WHERE u.role IN ('teacher','program_head','dean')"
    );
    $inserted = 0;
    $stmt = db()->prepare(
        "INSERT IGNORE INTO evaluation_period_participation
          (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,program_id,department_snapshot,program_snapshot,
           assignment_source,needs_review,participation_status,work_status,employment_status,changed_by_user_id)
         VALUES
          (:period_id,:user_id,:faculty_id,:role_snapshot,:department_id,:program_id,:department_snapshot,:program_snapshot,
           'master',:needs_review,:participation_status,:work_status,:employment_status,:actor_id)"
    );
    foreach ($eligible as $row) {
        if (empty($row['start_evaluation_period_id'])) continue;
        $startPeriodYear = dipascaf_period_start_year([
            'school_year' => $row['start_school_year'] ?? '',
            'period_name' => $row['start_period_name'] ?? '',
            'date_start' => $row['start_date'] ?? '',
        ]);
        $beforeStart = $periodStartYear > 0 && $startPeriodYear > 0
            ? $periodStartYear < $startPeriodYear
            : ($dateStart !== '' && (string)$row['start_date'] !== '' && $dateStart < (string)$row['start_date']);
        $isStart = (int)$row['start_evaluation_period_id'] === $periodId;
        $stmt->execute([
            'period_id'=>$periodId,
            'user_id'=>(int)$row['user_id'],
            'faculty_id'=>$row['faculty_id'] ?: null,
            'role_snapshot'=>$row['role_snapshot'],
            'department_id'=>$row['department_id'] ?: null,
            'program_id'=>$row['program_id'] ?: null,
            'department_snapshot'=>$row['department_snapshot'] ?: null,
            'program_snapshot'=>$row['program_snapshot'] ?: null,
            'needs_review'=>empty($row['department_id'])
                || ($row['role_snapshot'] === 'program_head' && empty($row['program_id']))
                || ($row['role_snapshot'] === 'teacher' && empty($row['program_id']) && empty($row['faculty_id']))
                ? 1 : 0,
            'participation_status'=>$beforeStart ? 'excluded' : 'included',
            'work_status'=>$beforeStart ? 'no_assignments' : 'active',
            'employment_status'=>$beforeStart ? 'not_yet_employed' : ($isStart ? 'newly_added' : 'active'),
            'actor_id'=>$actorId > 0 ? $actorId : null,
        ]);
        if (!empty($row['faculty_id'])) {
            subject_assignments_snapshot_faculty(
                db(),
                $periodId,
                (int)$row['user_id'],
                (int)$row['faculty_id']
            );
            if ($row['role_snapshot'] === 'teacher' && empty($row['program_id'])) {
                $subjectCheck = db()->prepare(
                    'SELECT 1 FROM evaluation_period_faculty_subjects WHERE evaluation_period_id=? AND faculty_id=? LIMIT 1'
                );
                $subjectCheck->execute([$periodId, (int)$row['faculty_id']]);
                if (!$subjectCheck->fetchColumn()) {
                    db()->prepare(
                        'UPDATE evaluation_period_participation SET needs_review=1 WHERE evaluation_period_id=? AND user_id=?'
                    )->execute([$periodId, (int)$row['user_id']]);
                }
            }
        }
        $inserted += $stmt->rowCount();
    }
    return $inserted;
}

function dipascaf_period_participation_validation(int $periodId): array
{
    dipascaf_ensure_period_participation_schema();
    $errors = [];
    $missingStarts = admin_all(
        "SELECT u.id,u.full_name FROM users u
         WHERE u.role IN ('teacher','program_head','dean') AND u.start_evaluation_period_id IS NULL
         ORDER BY u.full_name"
    );
    foreach ($missingStarts as $row) {
        $errors[] = ['code'=>'missing_start_period','user_id'=>(int)$row['id'],'message'=>$row['full_name'].' has no Start Evaluation Period.'];
    }
    $invalid = admin_all(
        "SELECT epp.user_id,u.full_name,epp.role_snapshot,epp.department_id,epp.program_id,epp.needs_review
         FROM evaluation_period_participation epp JOIN users u ON u.id=epp.user_id
         WHERE epp.evaluation_period_id=:period_id AND epp.participation_status='included'
           AND epp.employment_status NOT IN ('not_yet_employed','on_leave','inactive')
           AND (epp.role_snapshot IS NULL OR epp.department_id IS NULL
                OR (epp.role_snapshot='program_head' AND epp.program_id IS NULL)
                OR (epp.needs_review=1 AND NOT (epp.role_snapshot='teacher' AND epp.department_id IS NOT NULL)))",
        ['period_id'=>$periodId]
    );
    foreach ($invalid as $row) {
        $errors[] = ['code'=>'invalid_assignment','user_id'=>(int)$row['user_id'],'message'=>$row['full_name'].' needs a valid period role and department assignment. Program Heads must also have a program assignment.'];
    }
    $included = (int)(admin_one(
        "SELECT COUNT(*) total FROM evaluation_period_participation
         WHERE evaluation_period_id=:period_id AND participation_status='included'
           AND employment_status IN ('active','newly_added')",
        ['period_id'=>$periodId]
    )['total'] ?? 0);
    if ($included === 0) $errors[] = ['code'=>'empty_participants','message'=>'Include at least one eligible participant.'];
    return ['valid'=>$errors === [],'errors'=>$errors,'included_count'=>$included];
}

function dipascaf_finalize_period_participants(int $periodId, int $actorId): array
{
    dipascaf_seed_period_participants($periodId, $actorId);
    $validation = dipascaf_period_participation_validation($periodId);
    if (!$validation['valid']) throw new DomainException($validation['errors'][0]['message']);
    db()->prepare(
        'UPDATE appraisal_periods SET participants_finalized_at=NOW(),participants_finalized_by=:actor,
         peer_assignments_validated_at=NULL,peer_assignments_validated_by=NULL WHERE id=:id'
    )->execute(['actor'=>$actorId,'id'=>$periodId]);
    return $validation;
}

function dipascaf_reopen_period_participants(int $periodId, int $actorId): void
{
    $period = admin_one('SELECT status FROM appraisal_periods WHERE id=:id LIMIT 1', ['id'=>$periodId]);
    if (!$period) throw new DomainException('Evaluation period was not found.');
    if ((string)$period['status'] === 'open') throw new DomainException('Lock the evaluation period before reopening participants.');
    db()->prepare(
        'UPDATE appraisal_periods SET participants_finalized_at=NULL,participants_finalized_by=NULL,
         peer_assignments_validated_at=NULL,peer_assignments_validated_by=NULL WHERE id=:id'
    )->execute(['id'=>$periodId]);
    db()->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')
        ->execute([$actorId,'Reopened evaluation period participants for period #'.$periodId.'.']);
}

function dipascaf_period_program_head_programs(int $periodId, int $userId, bool $fallback = true): array
{
    if ($periodId <= 0 || $userId <= 0) return [];
    dipascaf_ensure_period_participation_schema();
    $participation = admin_one(
        'SELECT participation_status,work_status,employment_status
         FROM evaluation_period_participation
         WHERE evaluation_period_id=:period_id AND user_id=:user_id LIMIT 1',
        ['period_id'=>$periodId,'user_id'=>$userId]
    );
    if ($participation !== null && (
        (string)$participation['participation_status'] !== 'included'
        || (string)$participation['work_status'] !== 'active'
        || in_array((string)$participation['employment_status'], ['not_yet_employed','inactive'], true)
    )) {
        return [];
    }
    $rows = admin_all(
        "SELECT epph.program_id,epph.department_id,p.program_code,p.program_name,d.department_code,d.department_name,
                epph.is_primary,epph.is_lead_evaluator,epph.co_head_authorized,epph.co_head_reason,
                epph.assignment_source,authorizer.full_name AS authorized_by
         FROM evaluation_period_program_heads epph
         JOIN programs p ON p.id=epph.program_id
         JOIN departments d ON d.id=epph.department_id
         JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id=epph.evaluation_period_id
          AND epp.user_id=epph.user_id
          AND epp.participation_status='included'
          AND epp.work_status='active'
          AND epp.employment_status NOT IN ('not_yet_employed','inactive')
         LEFT JOIN users authorizer ON authorizer.id=epph.authorized_by_user_id
         WHERE epph.evaluation_period_id=:period_id AND epph.user_id=:user_id
         ORDER BY epph.is_primary DESC,p.program_name",
        ['period_id'=>$periodId,'user_id'=>$userId]
    );
    if ($rows !== [] || !$fallback) return $rows;

    $snapshot = admin_all(
        "SELECT p.id AS program_id,p.department_id,p.program_code,p.program_name,d.department_code,d.department_name,
                1 AS is_primary,1 AS is_lead_evaluator,0 AS co_head_authorized,NULL AS co_head_reason,
                COALESCE(epp.assignment_source,'inferred') AS assignment_source,NULL AS authorized_by
         FROM evaluation_period_participation epp
         JOIN programs p ON p.id=epp.program_id
         JOIN departments d ON d.id=p.department_id
         WHERE epp.evaluation_period_id=:period_id AND epp.user_id=:user_id
           AND epp.role_snapshot='program_head' AND epp.participation_status='included'
         LIMIT 1",
        ['period_id'=>$periodId,'user_id'=>$userId]
    );
    if ($snapshot !== []) return $snapshot;

    return admin_all(
        "SELECT p.id AS program_id,p.department_id,p.program_code,p.program_name,d.department_code,d.department_name,
                1 AS is_primary,1 AS is_lead_evaluator,0 AS co_head_authorized,NULL AS co_head_reason,
                'master' AS assignment_source,NULL AS authorized_by
         FROM programs p JOIN departments d ON d.id=p.department_id
         WHERE p.program_head_user_id=:user_id AND p.is_active=1
         ORDER BY p.program_name",
        ['user_id'=>$userId]
    );
}

function dipascaf_period_program_head_scope(int $periodId, int $userId, bool $leadOnly = false): array
{
    $programs = dipascaf_period_program_head_programs($periodId, $userId);
    if ($leadOnly) {
        $programs = array_values(array_filter($programs, static fn(array $row): bool => (int)$row['is_lead_evaluator'] === 1));
    }
    return [
        'programs'=>$programs,
        'program_ids'=>array_values(array_unique(array_map('intval', array_column($programs, 'program_id')))),
        'program_codes'=>array_values(array_unique(array_filter(array_map('strval', array_column($programs, 'program_code'))))),
        'department_ids'=>array_values(array_unique(array_map('intval', array_column($programs, 'department_id')))),
        'departments'=>array_values(array_unique(array_filter(array_map('strval', array_column($programs, 'department_name'))))),
    ];
}

function dipascaf_period_user_is_excluded(int $periodId, int $userId): bool
{
    if ($periodId <= 0 || $userId <= 0) return false;
    dipascaf_ensure_period_participation_schema();
    $stmt = db()->prepare("SELECT 1 FROM evaluation_period_participation WHERE evaluation_period_id=? AND user_id=? AND participation_status='excluded' LIMIT 1");
    $stmt->execute([$periodId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function dipascaf_period_exclusion_sql(string $userExpression, string $periodExpression): string
{
    return "NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp_filter
        WHERE epp_filter.evaluation_period_id = {$periodExpression}
          AND epp_filter.user_id = {$userExpression}
          AND epp_filter.participation_status = 'excluded')";
}

function dipascaf_period_dean_scope(int $periodId, int $userId = 0): array
{
    dipascaf_ensure_period_participation_schema();
    $params = ['period_id'=>$periodId];
    $userSql = '';
    if ($userId > 0) { $userSql = ' AND epd.user_id=:user_id'; $params['user_id']=$userId; }
    $rows = admin_all(
        "SELECT epd.*,d.department_code,d.department_name,u.full_name,approver.full_name AS authorized_by
         FROM evaluation_period_deans epd JOIN departments d ON d.id=epd.department_id
         JOIN users u ON u.id=epd.user_id LEFT JOIN users approver ON approver.id=epd.authorized_by_user_id
         LEFT JOIN evaluation_period_participation epp ON epp.evaluation_period_id=epd.evaluation_period_id AND epp.user_id=epd.user_id
         WHERE epd.evaluation_period_id=:period_id{$userSql}
           AND COALESCE(epp.participation_status,'included')='included'
           AND COALESCE(epp.work_status,'active')='active' ORDER BY d.department_name",
        $params
    );
    if ($rows !== []) return $rows;
    return admin_all(
        "SELECT :period_id evaluation_period_id,d.id department_id,d.dean_user_id user_id,0 is_acting,
                'master' assignment_source,NULL authorization_reason,NULL replaced_user_id,NULL replaced_dean_action,
                NULL authorized_by_user_id,d.department_code,d.department_name,u.full_name,NULL authorized_by
         FROM departments d JOIN users u ON u.id=d.dean_user_id
         WHERE d.is_active=1" . ($userId > 0 ? ' AND d.dean_user_id=:user_id' : '') . "
           AND NOT EXISTS (SELECT 1 FROM evaluation_period_deans x WHERE x.evaluation_period_id=:period_id AND x.department_id=d.id)
         ORDER BY d.department_name",
        $params
    );
}

function dipascaf_period_participants(int $periodId): array
{
    dipascaf_ensure_period_participation_schema();
    $rows = admin_all("SELECT u.id AS user_id,u.user_code,u.full_name,u.email,u.role AS master_role,
            u.department AS master_department,u.program AS master_program,u.is_active,
            f.id AS faculty_id,ap.period_name,
            COALESCE(epp.participation_status,'excluded') AS participation_status,
            COALESCE(epp.work_status,'no_assignments') AS work_status,
            COALESCE(epp.employment_status,
              CASE WHEN u.start_evaluation_period_id IS NULL THEN 'inactive'
                   WHEN sp.date_start IS NOT NULL AND ap.date_start < sp.date_start THEN 'not_yet_employed'
                   ELSE 'active' END) AS employment_status,
            CASE WHEN epp.id IS NULL THEN 0 ELSE 1 END AS is_configured,
            u.start_evaluation_period_id,
            COALESCE(epp.role_snapshot,CASE WHEN u.role='dean' THEN 'dean' WHEN u.role='program_head' THEN 'program_head' ELSE 'teacher' END) AS role,
            COALESCE(epp.department_snapshot,u.department,f.department) AS department,
            COALESCE(epp.program_snapshot,u.program,f.program_code) AS program,
            epp.department_id,epp.program_id,
            COALESCE(epp.assignment_source,'master') AS assignment_source,
            COALESCE(epp.needs_review,0) AS needs_review,
            epp.exclusion_reason,epp.notes,epp.excluded_at,epp.reincluded_at,epp.updated_at,
            changer.full_name AS changed_by,
            (SELECT COUNT(*)
               FROM evaluation_period_participation history
               JOIN appraisal_periods history_period ON history_period.id=history.evaluation_period_id
              WHERE history.user_id=u.id
                AND history.participation_status='included'
                AND history.employment_status IN ('active','newly_added')
                AND history_period.date_start<=ap.date_start) AS assigned_period_count,
            CASE WHEN epp.employment_status='newly_added' THEN 1 ELSE 0 END AS is_new_account_for_period,
            (SELECT COUNT(*) FROM peer_assignments pa WHERE pa.cycle_name=ap.period_name
                AND (pa.evaluator_user_id=u.id OR pa.evaluatee_faculty_id=f.id)
                AND pa.status='submitted' AND COALESCE(pa.is_archived,0)=0) AS submitted_count,
            (SELECT COUNT(*) FROM peer_assignments pa WHERE pa.cycle_name=ap.period_name
                AND (pa.evaluator_user_id=u.id OR pa.evaluatee_faculty_id=f.id)
                AND pa.status IN ('pending','in_progress','reopened','overdue') AND COALESCE(pa.is_archived,0)=0) AS open_count,
            (SELECT COUNT(*) FROM peer_assignments pa WHERE pa.cycle_name=ap.period_name
                AND (pa.evaluator_user_id=u.id OR pa.evaluatee_faculty_id=f.id)
                AND pa.status='not_required' AND COALESCE(pa.is_archived,0)=0) AS not_required_count
        FROM appraisal_periods ap
        JOIN users u ON u.role IN ('teacher','program_head','dean')
        JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
        LEFT JOIN appraisal_periods sp ON sp.id=u.start_evaluation_period_id
        LEFT JOIN evaluation_period_participation epp ON epp.evaluation_period_id=ap.id AND epp.user_id=u.id
        LEFT JOIN users changer ON changer.id=epp.changed_by_user_id
        WHERE ap.id=:period_id AND COALESCE(f.is_archived,0)=0
        GROUP BY u.id,f.id,epp.id,ap.id
        ORDER BY COALESCE(epp.participation_status,'included')='excluded' DESC,u.full_name", ['period_id'=>$periodId]);
    $deanByUser = [];
    foreach (dipascaf_period_dean_scope($periodId) as $dean) $deanByUser[(int)$dean['user_id']] = $dean;
    foreach ($rows as &$row) {
        if (isset($deanByUser[(int)$row['user_id']])) {
            $dean = $deanByUser[(int)$row['user_id']];
            $row['role'] = 'dean';
            $row['department_id'] = $dean['department_id'];
            $row['department'] = $dean['department_name'];
            $row['is_acting_dean'] = (int)$dean['is_acting'];
            $row['dean_assignment_source'] = $dean['assignment_source'];
            $row['dean_authorization_reason'] = $dean['authorization_reason'];
            $row['dean_authorized_by'] = $dean['authorized_by'];
        } else {
            $row['is_acting_dean'] = 0;
        }
        $row['programs'] = (string)$row['role'] === 'program_head'
            ? dipascaf_period_program_head_programs($periodId, (int)$row['user_id'])
            : [];
    }
    unset($row);
    return $rows;
}

function dipascaf_period_assignment_options(): array
{
    return [
        'departments' => admin_all(
            'SELECT id,department_code,department_name FROM departments WHERE is_active=1 ORDER BY department_name'
        ),
        'programs' => admin_all(
            'SELECT p.id,p.program_code,p.program_name,p.department_id,d.department_name
             FROM programs p JOIN departments d ON d.id=p.department_id
             WHERE p.is_active=1 AND d.is_active=1 ORDER BY d.department_name,p.program_code'
        ),
    ];
}

function dipascaf_period_user_context(int $periodId, int $userId): ?array
{
    if ($periodId <= 0 || $userId <= 0) return null;
    dipascaf_ensure_period_participation_schema();
    return admin_one(
        "SELECT u.id AS user_id,f.id AS faculty_id,
                epp.role_snapshot AS role,
                epp.department_snapshot AS department,
                epp.program_snapshot AS program,
                epp.department_id,epp.program_id,
                epp.participation_status,
                epp.work_status,epp.employment_status
         FROM users u
         JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
         JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id=:period_id AND epp.user_id=u.id
         WHERE u.id=:user_id AND COALESCE(f.is_archived,0)=0 LIMIT 1",
        ['period_id'=>$periodId,'user_id'=>$userId]
    );
}

function dipascaf_user_can_access_period(int $userId, int $periodId): bool
{
    $context = dipascaf_period_user_context($periodId, $userId);
    return $context !== null
        && (string)($context['participation_status'] ?? '') === 'included'
        && (string)($context['work_status'] ?? '') === 'active'
        && in_array((string)($context['employment_status'] ?? ''), ['active','newly_added'], true);
}

function dipascaf_require_user_period_access(int $userId, int $periodId): array
{
    $context = dipascaf_period_user_context($periodId, $userId);
    if ($context === null || !dipascaf_user_can_access_period($userId, $periodId)) {
        throw new DomainException('This evaluation period is not assigned to your account.');
    }
    return $context;
}

function dipascaf_set_period_assignment(
    int $periodId,
    int $userId,
    string $role,
    int $departmentId,
    array $programIds,
    int $primaryProgramId,
    array $leadProgramIds,
    bool $allowCoHead,
    string $coHeadReason,
    int $actorId
): array {
    dipascaf_ensure_period_participation_schema();
    dipascaf_assert_period_participation_editable($periodId);
    admin_ensure_archive_schema();
    if (!in_array($role, ['teacher','program_head'], true)) {
        throw new DomainException('Select Faculty or Program Head.');
    }
    $programIds = array_values(array_unique(array_filter(array_map('intval', $programIds))));
    $leadProgramIds = array_values(array_unique(array_filter(array_map('intval', $leadProgramIds))));
    if ($periodId <= 0 || $userId <= 0 || $departmentId <= 0 || ($role === 'program_head' && $programIds === [])) {
        throw new DomainException('Role and department are required. Program Heads must also have a program.');
    }
    if ($role === 'teacher' && count($programIds) > 1) {
        throw new DomainException('Faculty participants may have at most one program assignment.');
    }
    if ($programIds !== [] && !in_array($primaryProgramId, $programIds, true)) {
        throw new DomainException('Select a primary program from the assigned programs.');
    }
    if (array_diff($leadProgramIds, $programIds) !== []) {
        throw new DomainException('Lead evaluator programs must be included in the assigned programs.');
    }
    $coHeadReason = trim($coHeadReason);

    $db = db();
    $db->beginTransaction();
    try {
        $periodStmt = $db->prepare('SELECT id,period_name,date_end FROM appraisal_periods WHERE id=? FOR UPDATE');
        $periodStmt->execute([$periodId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) throw new DomainException('Evaluation period was not found.');

        $memberStmt = $db->prepare(
            "SELECT u.id,u.full_name,f.id AS faculty_id
             FROM users u JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
             WHERE u.id=? AND u.role IN ('teacher','program_head') AND COALESCE(f.is_archived,0)=0
             LIMIT 1 FOR UPDATE"
        );
        $memberStmt->execute([$userId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) throw new DomainException('The participant must have a linked faculty record.');

        $departmentStmt = $db->prepare(
            'SELECT id,department_name FROM departments WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE'
        );
        $departmentStmt->execute([$departmentId]);
        $department = $departmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$department) throw new DomainException('Select an active department.');

        $programs = [];
        $placeholders = '';
        if ($programIds !== []) {
            $placeholders = implode(',', array_fill(0, count($programIds), '?'));
            $programStmt = $db->prepare(
                "SELECT p.id,p.program_code,p.program_name,p.department_id,d.department_name
                 FROM programs p JOIN departments d ON d.id=p.department_id
                 WHERE p.id IN ($placeholders) AND p.department_id=? AND p.is_active=1 AND d.is_active=1
                 ORDER BY p.program_name FOR UPDATE"
            );
            $programStmt->execute([...$programIds,$departmentId]);
            $programs = $programStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (count($programs) !== count($programIds)) {
            throw new DomainException('Every selected program must be active and belong to the selected department.');
        }
        $programById = [];
        foreach ($programs as $program) $programById[(int)$program['id']] = $program;

        $conflicts = [];
        if ($role === 'program_head') {
            $conflictStmt = $db->prepare(
                "SELECT epph.program_id,epph.user_id,u.full_name,epph.is_lead_evaluator
                 FROM evaluation_period_program_heads epph
                 JOIN users u ON u.id=epph.user_id
                 LEFT JOIN evaluation_period_participation epp
                   ON epp.evaluation_period_id=epph.evaluation_period_id AND epp.user_id=epph.user_id
                 WHERE epph.evaluation_period_id=? AND epph.program_id IN ($placeholders)
                   AND epph.user_id<>? AND COALESCE(epp.participation_status,'included')='included'
                 ORDER BY epph.program_id,epph.is_lead_evaluator DESC FOR UPDATE"
            );
            $conflictStmt->execute([$periodId,...$programIds,$userId]);
            foreach ($conflictStmt->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
                $program = $programById[(int)$conflict['program_id']];
                $conflicts[] = [
                    'program_id'=>(int)$conflict['program_id'],
                    'program_code'=>$program['program_code'],
                    'program_name'=>$program['program_name'],
                    'existing_head_user_id'=>(int)$conflict['user_id'],
                    'existing_head_name'=>$conflict['full_name'],
                    'existing_head_is_lead'=>(int)$conflict['is_lead_evaluator'] === 1,
                ];
            }
            $legacyConflictStmt = $db->prepare(
                "SELECT p.id AS program_id,u.id AS user_id,u.full_name
                 FROM users u
                 JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
                 LEFT JOIN evaluation_period_participation epp
                   ON epp.evaluation_period_id=? AND epp.user_id=u.id
                 JOIN programs p ON p.department_id=?
                   AND UPPER(p.program_code)=UPPER(COALESCE(epp.program_snapshot,u.program,f.program_code))
                 WHERE p.id IN ($placeholders) AND u.id<>?
                   AND COALESCE(epp.participation_status,'included')='included'
                   AND COALESCE(epp.role_snapshot,IF(u.role='program_head','program_head','teacher'))='program_head'
                   AND NOT EXISTS (
                     SELECT 1 FROM evaluation_period_program_heads mapped
                     WHERE mapped.evaluation_period_id=? AND mapped.user_id=u.id
                   )
                 FOR UPDATE"
            );
            $legacyConflictStmt->execute([$periodId,$departmentId,...$programIds,$userId,$periodId]);
            foreach ($legacyConflictStmt->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
                $duplicate = array_filter($conflicts, static fn(array $row): bool =>
                    (int)$row['program_id'] === (int)$conflict['program_id']
                    && (int)$row['existing_head_user_id'] === (int)$conflict['user_id']
                );
                if ($duplicate !== []) continue;
                $program = $programById[(int)$conflict['program_id']];
                $conflicts[] = [
                    'program_id'=>(int)$conflict['program_id'],
                    'program_code'=>$program['program_code'],
                    'program_name'=>$program['program_name'],
                    'existing_head_user_id'=>(int)$conflict['user_id'],
                    'existing_head_name'=>$conflict['full_name'],
                    'existing_head_is_lead'=>true,
                ];
            }
            if ($conflicts !== [] && !$allowCoHead) throw new PeriodProgramHeadConflictException($conflicts);
            if ($conflicts !== [] && $coHeadReason === '') {
                throw new DomainException('Enter a reason for authorizing the co-head arrangement.');
            }
            foreach ($programIds as $programId) {
                $existingLead = array_filter(
                    $conflicts,
                    static fn(array $row): bool => (int)$row['program_id'] === $programId && $row['existing_head_is_lead']
                );
                if (!in_array($programId, $leadProgramIds, true) && $existingLead === []) {
                    throw new DomainException($programById[$programId]['program_code'] . ' must have one lead evaluator.');
                }
            }
        }

        $existingStmt = $db->prepare(
            'SELECT participation_status FROM evaluation_period_participation
             WHERE evaluation_period_id=? AND user_id=? FOR UPDATE'
        );
        $existingStmt->execute([$periodId,$userId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        $participationStatus = (string)($existing['participation_status'] ?? 'included');
        $primaryProgram = $primaryProgramId > 0 ? ($programById[$primaryProgramId] ?? null) : null;

        $db->prepare(
            "INSERT INTO evaluation_period_participation
             (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,program_id,
              department_snapshot,program_snapshot,assignment_source,needs_review,program_head_slot,
              participation_status,changed_by_user_id)
             VALUES (?,?,?,?,?,?,?,?, 'admin',0,?,?,?)
             ON DUPLICATE KEY UPDATE faculty_id=VALUES(faculty_id),role_snapshot=VALUES(role_snapshot),
              department_id=VALUES(department_id),program_id=VALUES(program_id),
              department_snapshot=VALUES(department_snapshot),program_snapshot=VALUES(program_snapshot),
              assignment_source='admin',needs_review=0,program_head_slot=VALUES(program_head_slot),
              changed_by_user_id=VALUES(changed_by_user_id)"
        )->execute([
            $periodId,$userId,(int)$member['faculty_id'],$role,$departmentId,$primaryProgramId ?: null,
            (string)$department['department_name'],(string)($primaryProgram['program_code'] ?? ''),null,$participationStatus,$actorId
        ]);

        $previous = admin_all(
            'SELECT program_id,is_lead_evaluator FROM evaluation_period_program_heads
             WHERE evaluation_period_id=:period_id AND user_id=:user_id',
            ['period_id'=>$periodId,'user_id'=>$userId]
        );
        $previousProgramIds = array_map('intval', array_column($previous, 'program_id'));
        $db->prepare('DELETE FROM evaluation_period_deans WHERE evaluation_period_id=? AND user_id=?')
            ->execute([$periodId,$userId]);
        $db->prepare('DELETE FROM evaluation_period_program_heads WHERE evaluation_period_id=? AND user_id=?')
            ->execute([$periodId,$userId]);
        if ($role === 'program_head') {
            $insert = $db->prepare(
                "INSERT INTO evaluation_period_program_heads
                 (evaluation_period_id,user_id,department_id,program_id,is_primary,is_lead_evaluator,
                  co_head_authorized,co_head_reason,authorized_by_user_id,assignment_source)
                 VALUES (?,?,?,?,?,?,?,?,?,'admin')"
            );
            $conflictProgramIds = array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['program_id'], $conflicts
            )));
            foreach ($programIds as $programId) {
                $isCoHead = in_array($programId, $conflictProgramIds, true);
                if (in_array($programId,$leadProgramIds,true)) {
                    $db->prepare(
                        'UPDATE evaluation_period_program_heads SET is_lead_evaluator=0
                         WHERE evaluation_period_id=? AND program_id=? AND user_id<>?'
                    )->execute([$periodId,$programId,$userId]);
                }
                $insert->execute([
                    $periodId,$userId,$departmentId,$programId,$programId === $primaryProgramId ? 1 : 0,
                    in_array($programId,$leadProgramIds,true) ? 1 : 0,$isCoHead ? 1 : 0,
                    $isCoHead ? $coHeadReason : null,$isCoHead ? $actorId : null,
                ]);
            }
        }
        $affectedProgramIds = array_values(array_unique(array_merge($previousProgramIds, $programIds)));
        if ($affectedProgramIds !== []) {
            $affectedPlaceholders = implode(',', array_fill(0, count($affectedProgramIds), '?'));
            $leadAudit = $db->prepare(
                "SELECT program_id,COUNT(*) AS head_count,SUM(is_lead_evaluator=1) AS lead_count
                 FROM evaluation_period_program_heads
                 WHERE evaluation_period_id=? AND program_id IN ($affectedPlaceholders)
                 GROUP BY program_id HAVING head_count>0 AND lead_count<>1"
            );
            $leadAudit->execute([$periodId,...$affectedProgramIds]);
            $invalidLead = $leadAudit->fetch(PDO::FETCH_ASSOC);
            if ($invalidLead) {
                $invalidProgram = admin_one(
                    'SELECT program_code FROM programs WHERE id=:id',
                    ['id'=>(int)$invalidLead['program_id']]
                );
                throw new DomainException(
                    ((string)($invalidProgram['program_code'] ?? 'The program'))
                    . ' must have exactly one lead evaluator before this assignment can be saved.'
                );
            }
        }

        $reason = '[PERIOD_ASSIGNMENT:' . $periodId . '] Role/program assignment changed by Admin HR.';
        $hasReplacementReason = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'replacement_reason'") !== null;
        $archive = $db->prepare(
            "UPDATE peer_assignments SET is_archived=1,archived_at=NOW(),archived_by=?"
                . ($hasReplacementReason ? ',replacement_reason=?' : '') . "
             WHERE cycle_name=? AND (evaluator_user_id=? OR evaluatee_faculty_id=?)
               AND status IN ('pending','in_progress','reopened','overdue','not_required')
               AND NOT (
                    assignment_type='peer'
                    AND EXISTS (
                        SELECT 1
                        FROM peer_evaluation_assignments locked_peer
                        JOIN peer_evaluation_locks peer_lock
                          ON peer_lock.evaluation_period_id=locked_peer.evaluation_period_id
                         AND peer_lock.status='locked'
                        WHERE locked_peer.peer_assignment_id=peer_assignments.id
                          AND locked_peer.evaluation_period_id=?
                          AND COALESCE(locked_peer.is_archived,0)=0
                    )
               )
               AND COALESCE(is_archived,0)=0"
        );
        $archiveParams = [$actorId];
        if ($hasReplacementReason) $archiveParams[] = $reason;
        array_push($archiveParams,$period['period_name'],$userId,(int)$member['faculty_id'],$periodId);
        $archive->execute($archiveParams);
        $changedAssignments = $archive->rowCount();
        $db->prepare(
            "UPDATE peer_evaluation_assignments pea
             JOIN peer_assignments pa ON pa.id=pea.peer_assignment_id
             SET pea.is_archived=1,pea.archived_at=NOW(),pea.archived_by=?
             WHERE pea.evaluation_period_id=? AND pa.is_archived=1"
                . ($hasReplacementReason ? ' AND pa.replacement_reason=?' : '')
        )->execute($hasReplacementReason ? [$actorId,$periodId,$reason] : [$actorId,$periodId]);

        $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([
            $actorId,
            'Updated ' . $member['full_name'] . ' to ' . ($role === 'program_head' ? 'Program Head' : 'Faculty')
                . ' for ' . implode(', ', array_column($programs, 'program_code')) . ' in ' . $period['period_name']
                . ($conflicts !== [] ? ' (authorized co-head: ' . $coHeadReason . ')' : '') . '.'
        ]);
        $db->commit();

        require_once __DIR__ . '/evaluation_assignment_generator.php';
        $generated = dipascaf_upsert_required_assignments_for_period(
            (string)$period['period_name'],
            (string)($period['date_end'] ?: date('Y-m-d', strtotime('+30 days')))
        );
        return [
            'period_name'=>$period['period_name'],
            'faculty_name'=>$member['full_name'],
            'role'=>$role,
            'programs'=>array_values(array_column($programs, 'program_code')),
            'previous_program_ids'=>$previousProgramIds,
            'assignments_archived'=>$changedAssignments,
            'generated'=>$generated,
        ];
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function dipascaf_set_period_dean_assignment(int $periodId, int $userId, int $departmentId, string $reason, string $replacedDeanAction, int $actorId, bool $confirmReplacement = false): array
{
    dipascaf_ensure_period_participation_schema();
    dipascaf_assert_period_participation_editable($periodId);
    admin_ensure_archive_schema();
    $reason=trim($reason);
    if ($periodId<=0 || $userId<=0 || $departmentId<=0 || $reason==='') throw new DomainException('Department and acting Dean authorization reason are required.');
    if (!in_array($replacedDeanAction,['faculty','excluded','no_assignments'],true)) throw new DomainException('Select how the previous Dean will participate in this period.');
    $db=db(); $db->beginTransaction();
    try {
        $period=admin_one('SELECT id,period_name,date_end FROM appraisal_periods WHERE id=:id FOR UPDATE',['id'=>$periodId]);
        $department=admin_one('SELECT id,department_name,department_code,dean_user_id FROM departments WHERE id=:id AND is_active=1 FOR UPDATE',['id'=>$departmentId]);
        $member=admin_one("SELECT u.id,u.full_name,u.role,f.id faculty_id FROM users u JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email) WHERE u.id=:id AND u.is_active=1 AND u.role IN ('program_head','dean') LIMIT 1 FOR UPDATE",['id'=>$userId]);
        if (!$period || !$department) throw new DomainException('Evaluation period or department was not found.');
        if (!$member) throw new DomainException('Only an active Program Head or Dean with a linked faculty record can become Acting Dean.');
        $existing=admin_one("SELECT epd.user_id,u.full_name FROM evaluation_period_deans epd JOIN users u ON u.id=epd.user_id WHERE epd.evaluation_period_id=:period AND epd.department_id=:department FOR UPDATE",['period'=>$periodId,'department'=>$departmentId]);
        $replacedUserId=(int)($existing['user_id'] ?? $department['dean_user_id'] ?? 0);
        if ($replacedUserId===$userId) throw new DomainException('This participant is already the Dean for the selected department and period.');
        if ($replacedUserId>0 && !$confirmReplacement) {
            $replaced = $existing ?: admin_one('SELECT id user_id,full_name FROM users WHERE id=:id',['id'=>$replacedUserId]);
            throw new PeriodDeanConflictException([
                'department_id'=>$departmentId,'department_name'=>$department['department_name'],
                'existing_dean_user_id'=>$replacedUserId,'existing_dean_name'=>$replaced['full_name'] ?? 'Current Dean',
            ]);
        }

        $db->prepare("INSERT INTO evaluation_period_deans
          (evaluation_period_id,department_id,user_id,is_acting,assignment_source,authorization_reason,replaced_user_id,replaced_dean_action,authorized_by_user_id)
          VALUES (?,?,?,1,'admin',?,?,?,?)
          ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),is_acting=1,assignment_source='admin',authorization_reason=VALUES(authorization_reason),
          replaced_user_id=VALUES(replaced_user_id),replaced_dean_action=VALUES(replaced_dean_action),authorized_by_user_id=VALUES(authorized_by_user_id)")
          ->execute([$periodId,$departmentId,$userId,$reason,$replacedUserId?:null,$replacedUserId?$replacedDeanAction:null,$actorId]);
        $db->prepare("INSERT INTO evaluation_period_participation
          (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,department_snapshot,assignment_source,participation_status,work_status,changed_by_user_id)
          VALUES (?,?,?,'dean',?,?,'admin','included','active',?)
          ON DUPLICATE KEY UPDATE role_snapshot='dean',department_id=VALUES(department_id),department_snapshot=VALUES(department_snapshot),
          program_id=NULL,program_snapshot=NULL,assignment_source='admin',participation_status='included',work_status='active',changed_by_user_id=VALUES(changed_by_user_id)")
          ->execute([$periodId,$userId,(int)$member['faculty_id'],$departmentId,$department['department_name'],$actorId]);
        $db->prepare('DELETE FROM evaluation_period_program_heads WHERE evaluation_period_id=? AND user_id=?')->execute([$periodId,$userId]);

        if ($replacedUserId>0) {
            $old=admin_one("SELECT u.id,u.full_name,f.id faculty_id,f.program_code FROM users u JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email) WHERE u.id=:id LIMIT 1",['id'=>$replacedUserId]);
            if ($old) {
                $oldStatus=$replacedDeanAction==='excluded'?'excluded':'included';
                $oldWork=$replacedDeanAction==='no_assignments'?'no_assignments':'active';
                $oldRole=$replacedDeanAction==='faculty'?'teacher':'dean';
                $db->prepare("INSERT INTO evaluation_period_participation
                  (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,department_snapshot,program_snapshot,assignment_source,participation_status,work_status,exclusion_reason,changed_by_user_id)
                  VALUES (?,?,?,?,?,?,?,'admin',?,?,?,?)
                  ON DUPLICATE KEY UPDATE role_snapshot=VALUES(role_snapshot),department_id=VALUES(department_id),department_snapshot=VALUES(department_snapshot),
                  assignment_source='admin',participation_status=VALUES(participation_status),work_status=VALUES(work_status),
                  exclusion_reason=VALUES(exclusion_reason),changed_by_user_id=VALUES(changed_by_user_id)")
                  ->execute([$periodId,$replacedUserId,(int)$old['faculty_id'],$oldRole,$departmentId,$department['department_name'],$old['program_code'],$oldStatus,$oldWork,$replacedDeanAction==='excluded'?'role_change':null,$actorId]);
            }
        }
        $affected=array_values(array_filter([$userId,$replacedUserId])); $marks=implode(',',array_fill(0,count($affected),'?'));
        $archiveReason='[PERIOD_DEAN_REPLACEMENT:'.$periodId.'] '.$reason;
        $hasReplacementReason=admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'replacement_reason'") !== null;
        $db->prepare("UPDATE peer_assignments SET is_archived=1,archived_at=NOW(),archived_by=?"
          . ($hasReplacementReason ? ',replacement_reason=?' : '') . "
          WHERE cycle_name=? AND evaluator_user_id IN ($marks) AND evaluator_role IN ('dean','program_head')
          AND status IN ('pending','in_progress','reopened','overdue','not_required') AND COALESCE(is_archived,0)=0")
          ->execute($hasReplacementReason
            ? [$actorId,$archiveReason,$period['period_name'],...$affected]
            : [$actorId,$period['period_name'],...$affected]);
        $db->prepare('INSERT INTO activity_logs(user_id,description) VALUES (?,?)')->execute([$actorId,'Assigned '.$member['full_name'].' as Acting Dean of '.$department['department_name'].' for '.$period['period_name'].'. Reason: '.$reason]);
        $db->commit();
        require_once __DIR__.'/evaluation_assignment_generator.php';
        $generated=dipascaf_upsert_required_assignments_for_period((string)$period['period_name'],(string)($period['date_end']?:date('Y-m-d',strtotime('+30 days'))));
        return ['role'=>'dean','is_acting_dean'=>true,'department'=>$department['department_name'],'replaced_user_id'=>$replacedUserId?:null,'replaced_dean_action'=>$replacedDeanAction,'generated'=>$generated];
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}

function dipascaf_set_period_participation(int $periodId, int $userId, string $status, ?string $reason, string $notes, int $actorId): array
{
    dipascaf_ensure_period_participation_schema();
    dipascaf_assert_period_participation_editable($periodId);
    if (!in_array($status, ['included','excluded'], true)) throw new DomainException('Invalid participation status.');
    $allowedReasons = ['resignation','retirement','leave','transfer','role_change','other'];
    if ($status === 'excluded' && !in_array((string) $reason, $allowedReasons, true)) throw new DomainException('Select a valid exclusion reason.');
    if ($status === 'excluded' && $reason === 'other' && trim($notes) === '') throw new DomainException('Notes are required when the reason is Other.');

    $db = db();
    $db->beginTransaction();
    try {
        $periodStmt = $db->prepare('SELECT id,period_name,status FROM appraisal_periods WHERE id=? FOR UPDATE');
        $periodStmt->execute([$periodId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) throw new DomainException('Evaluation period was not found.');

        $userStmt = $db->prepare("SELECT u.id,u.full_name,u.is_active,f.id AS faculty_id FROM users u LEFT JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email) WHERE u.id=? AND u.role IN ('teacher','program_head','dean') LIMIT 1 FOR UPDATE");
        $userStmt->execute([$userId]);
        $member = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || empty($member['faculty_id'])) throw new DomainException('The account must have a linked faculty record.');
        $facultyId = (int) $member['faculty_id'];

        $existingStmt = $db->prepare('SELECT * FROM evaluation_period_participation WHERE evaluation_period_id=? AND user_id=? FOR UPDATE');
        $existingStmt->execute([$periodId,$userId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (($existing['participation_status'] ?? 'included') === $status) throw new DomainException($status === 'excluded' ? 'This faculty member is already excluded from the period.' : 'This faculty member is already included in the period.');

        $db->prepare("INSERT INTO evaluation_period_participation
            (evaluation_period_id,user_id,faculty_id,participation_status,work_status,employment_status,exclusion_reason,notes,changed_by_user_id,excluded_at,reincluded_at)
            VALUES (?,?,?,?,?,?,?,?,?,IF(?='excluded',NOW(),NULL),IF(?='included',NOW(),NULL))
            ON DUPLICATE KEY UPDATE faculty_id=VALUES(faculty_id),participation_status=VALUES(participation_status),
              work_status=VALUES(work_status),employment_status=VALUES(employment_status),
              exclusion_reason=VALUES(exclusion_reason),notes=VALUES(notes),changed_by_user_id=VALUES(changed_by_user_id),
              excluded_at=IF(VALUES(participation_status)='excluded',NOW(),excluded_at),
              reincluded_at=IF(VALUES(participation_status)='included',NOW(),reincluded_at),
              program_head_slot=NULL")
            ->execute([
                $periodId,$userId,$facultyId,$status,
                $status === 'included' ? 'active' : 'no_assignments',
                $status === 'included'
                    ? (($existing['employment_status'] ?? '') === 'newly_added' ? 'newly_added' : 'active')
                    : ($reason === 'leave' ? 'on_leave' : (in_array($reason, ['resignation','retirement'], true) ? 'inactive' : 'active')),
                $status==='excluded'?$reason:null,$status==='excluded'?trim($notes):null,$actorId,$status,$status,
            ]);
        $db->prepare(
            "UPDATE evaluation_period_participation epp
             JOIN users u ON u.id=epp.user_id
             LEFT JOIN departments d ON d.is_active=1
               AND (d.department_name=u.department OR d.department_code=u.department)
             LEFT JOIN programs p ON p.is_active=1 AND p.department_id=d.id
               AND UPPER(p.program_code)=UPPER(u.program)
             SET epp.role_snapshot=COALESCE(epp.role_snapshot,CASE WHEN u.role='dean' THEN 'dean' WHEN u.role='program_head' THEN 'program_head' ELSE 'teacher' END),
                 epp.department_id=COALESCE(epp.department_id,d.id),
                 epp.program_id=COALESCE(epp.program_id,p.id),
                 epp.department_snapshot=COALESCE(epp.department_snapshot,d.department_name,u.department),
                 epp.program_snapshot=COALESCE(epp.program_snapshot,p.program_code,u.program),
                 epp.program_head_slot=NULL
             WHERE epp.evaluation_period_id=? AND epp.user_id=?"
        )->execute([$periodId,$userId]);

        $marker = '[PERIOD_EXCLUSION:' . $periodId . '] ' . str_replace('_',' ',(string) $reason) . ($notes !== '' ? ' - ' . trim($notes) : '');
        if ($status === 'excluded') {
            $stmt = $db->prepare("UPDATE peer_assignments SET status='not_required',replacement_reason=?
                WHERE cycle_name=? AND (evaluator_user_id=? OR evaluatee_faculty_id=?)
                  AND status IN ('pending','in_progress','reopened') AND COALESCE(is_archived,0)=0");
            $stmt->execute([$marker,$period['period_name'],$userId,$facultyId]);
            $closed = $stmt->rowCount();
            $stmt = $db->prepare("UPDATE peer_evaluation_assignments SET status='not_required'
                WHERE evaluation_period_id=? AND (evaluator_id=? OR evaluatee_id=?) AND status IN ('pending','overdue') AND COALESCE(is_archived,0)=0");
            $stmt->execute([$periodId,$userId,$userId]);
        } else {
            $stmt = $db->prepare("UPDATE peer_assignments SET status='pending',replacement_reason=NULL
                WHERE cycle_name=? AND (evaluator_user_id=? OR evaluatee_faculty_id=?)
                  AND status='not_required' AND replacement_reason LIKE ? AND COALESCE(is_archived,0)=0
                  AND NOT EXISTS (SELECT 1 FROM evaluation_submissions es WHERE es.assignment_id=peer_assignments.id)");
            $stmt->execute([$period['period_name'],$userId,$facultyId,'[PERIOD_EXCLUSION:' . $periodId . ']%']);
            $closed = $stmt->rowCount();
            $db->prepare("UPDATE peer_evaluation_assignments pea JOIN peer_assignments pa ON pa.id=pea.peer_assignment_id
                SET pea.status='pending' WHERE pea.evaluation_period_id=? AND (pea.evaluator_id=? OR pea.evaluatee_id=?)
                  AND pea.status='not_required' AND pa.status='pending'")->execute([$periodId,$userId,$userId]);
        }

        $description = ($status === 'excluded' ? 'Excluded ' : 'Re-included ') . $member['full_name'] . ' ' . ($status === 'excluded' ? 'from ' : 'in ') . $period['period_name'] . ($status === 'excluded' ? ' (' . str_replace('_',' ',(string)$reason) . ').' : '.');
        $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([$actorId,$description]);
        $db->commit();
        return ['status'=>$status,'assignments_changed'=>$closed,'period_name'=>$period['period_name'],'faculty_name'=>$member['full_name']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
