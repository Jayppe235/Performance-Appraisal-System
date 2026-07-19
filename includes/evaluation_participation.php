<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';

function dipascaf_ensure_period_participation_schema(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

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

    try {
        db()->exec("ALTER TABLE peer_evaluation_assignments MODIFY status ENUM('pending','completed','overdue','not_required') NOT NULL DEFAULT 'pending'");
    } catch (Throwable) {
        // Older installations may not have the dedicated peer table yet.
    }
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

function dipascaf_period_participants(int $periodId): array
{
    dipascaf_ensure_period_participation_schema();
    return admin_all("SELECT u.id AS user_id,u.user_code,u.full_name,u.email,u.role,u.department,u.program,u.is_active,
            f.id AS faculty_id,ap.period_name,
            COALESCE(epp.participation_status,'included') AS participation_status,
            epp.exclusion_reason,epp.notes,epp.excluded_at,epp.reincluded_at,epp.updated_at,
            changer.full_name AS changed_by,
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
        JOIN users u ON u.role IN ('teacher','program_head')
        JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
        LEFT JOIN evaluation_period_participation epp ON epp.evaluation_period_id=ap.id AND epp.user_id=u.id
        LEFT JOIN users changer ON changer.id=epp.changed_by_user_id
        WHERE ap.id=:period_id AND COALESCE(f.is_archived,0)=0
        GROUP BY u.id,f.id,epp.id,ap.id
        ORDER BY COALESCE(epp.participation_status,'included')='excluded' DESC,u.full_name", ['period_id'=>$periodId]);
}

function dipascaf_set_period_participation(int $periodId, int $userId, string $status, ?string $reason, string $notes, int $actorId): array
{
    dipascaf_ensure_period_participation_schema();
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

        $userStmt = $db->prepare("SELECT u.id,u.full_name,u.is_active,f.id AS faculty_id FROM users u LEFT JOIN faculty f ON f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email) WHERE u.id=? AND u.role IN ('teacher','program_head') LIMIT 1 FOR UPDATE");
        $userStmt->execute([$userId]);
        $member = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || empty($member['faculty_id'])) throw new DomainException('The account must have a linked faculty record.');
        $facultyId = (int) $member['faculty_id'];

        $existingStmt = $db->prepare('SELECT * FROM evaluation_period_participation WHERE evaluation_period_id=? AND user_id=? FOR UPDATE');
        $existingStmt->execute([$periodId,$userId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (($existing['participation_status'] ?? 'included') === $status) throw new DomainException($status === 'excluded' ? 'This faculty member is already excluded from the period.' : 'This faculty member is already included in the period.');

        $db->prepare("INSERT INTO evaluation_period_participation
            (evaluation_period_id,user_id,faculty_id,participation_status,exclusion_reason,notes,changed_by_user_id,excluded_at,reincluded_at)
            VALUES (?,?,?,?,?,?,?,IF(?='excluded',NOW(),NULL),IF(?='included',NOW(),NULL))
            ON DUPLICATE KEY UPDATE faculty_id=VALUES(faculty_id),participation_status=VALUES(participation_status),
              exclusion_reason=VALUES(exclusion_reason),notes=VALUES(notes),changed_by_user_id=VALUES(changed_by_user_id),
              excluded_at=IF(VALUES(participation_status)='excluded',NOW(),excluded_at),
              reincluded_at=IF(VALUES(participation_status)='included',NOW(),reincluded_at)")
            ->execute([$periodId,$userId,$facultyId,$status,$status==='excluded'?$reason:null,$status==='excluded'?trim($notes):null,$actorId,$status,$status]);

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
