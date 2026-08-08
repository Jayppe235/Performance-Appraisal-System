<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function subject_assignments_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS subject_areas (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            department_id INT NOT NULL,
            subject_code VARCHAR(30) NOT NULL,
            subject_name VARCHAR(150) NOT NULL,
            coordinator_faculty_id INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_subject_department_code (department_id, subject_code),
            KEY idx_subject_department_active (department_id, is_active),
            KEY idx_subject_coordinator (coordinator_faculty_id),
            CONSTRAINT fk_subject_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_subject_coordinator FOREIGN KEY (coordinator_faculty_id) REFERENCES faculty(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS faculty_subject_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            faculty_id INT NOT NULL,
            subject_area_id INT NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_faculty_subject (faculty_id, subject_area_id),
            KEY idx_faculty_subject_primary (faculty_id, is_primary),
            KEY idx_subject_faculty (subject_area_id, faculty_id),
            CONSTRAINT fk_fsa_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
            CONSTRAINT fk_fsa_subject FOREIGN KEY (subject_area_id) REFERENCES subject_areas(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS evaluation_period_faculty_subjects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            evaluation_period_id INT NOT NULL,
            user_id INT NOT NULL,
            faculty_id INT NOT NULL,
            subject_area_id INT NOT NULL,
            department_id INT NOT NULL,
            subject_code_snapshot VARCHAR(30) NOT NULL,
            subject_name_snapshot VARCHAR(150) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            is_coordinator TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_period_faculty_subject (evaluation_period_id, faculty_id, subject_area_id),
            KEY idx_period_subject_primary (evaluation_period_id, subject_area_id, is_primary),
            CONSTRAINT fk_epfs_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE,
            CONSTRAINT fk_epfs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_epfs_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
            CONSTRAINT fk_epfs_subject FOREIGN KEY (subject_area_id) REFERENCES subject_areas(id) ON DELETE RESTRICT,
            CONSTRAINT fk_epfs_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // The institution-wide general education subject areas are owned by CAS
    // only. Remove legacy auto-seeded copies from other departments when they
    // have never been assigned or snapshotted.
    $db->exec(
        "DELETE sa FROM subject_areas sa
         JOIN departments d ON d.id=sa.department_id
         LEFT JOIN faculty_subject_assignments fsa ON fsa.subject_area_id=sa.id
         LEFT JOIN evaluation_period_faculty_subjects epfs ON epfs.subject_area_id=sa.id
         WHERE d.department_code<>'CAS'
           AND (
             (sa.subject_code='RE' AND sa.subject_name='Religious Education')
             OR (sa.subject_code='MATH' AND sa.subject_name='Mathematics')
             OR (sa.subject_code='NSTP' AND sa.subject_name='National Service Training Program')
           )
           AND fsa.id IS NULL AND epfs.id IS NULL"
    );
    $seed = $db->prepare(
        "INSERT IGNORE INTO subject_areas (department_id, subject_code, subject_name)
         SELECT id, ?, ? FROM departments WHERE is_active=1 AND department_code='CAS'"
    );
    foreach ([['RE', 'Religious Education'], ['MATH', 'Mathematics'], ['NSTP', 'National Service Training Program']] as [$code, $name]) {
        $seed->execute([$code, $name]);
    }
    $ready = true;
}

function subject_assignments_for_faculty(PDO $db, int $facultyId, int $periodId = 0): array
{
    subject_assignments_ensure_schema();
    if ($facultyId <= 0) {
        return [];
    }
    if ($periodId > 0) {
        $stmt = $db->prepare(
            "SELECT epfs.subject_area_id AS id, epfs.subject_code_snapshot AS subject_code,
                    epfs.subject_name_snapshot AS subject_name, epfs.department_id,
                    epfs.is_primary, epfs.is_coordinator, sa.is_active
             FROM evaluation_period_faculty_subjects epfs
             LEFT JOIN subject_areas sa ON sa.id=epfs.subject_area_id
             WHERE epfs.evaluation_period_id=? AND epfs.faculty_id=?
             ORDER BY epfs.is_primary DESC, epfs.subject_name_snapshot"
        );
        $stmt->execute([$periodId, $facultyId]);
    } else {
        $stmt = $db->prepare(
            "SELECT sa.id, sa.subject_code, sa.subject_name, sa.department_id, sa.is_active,
                    fsa.is_primary, (sa.coordinator_faculty_id=fsa.faculty_id) AS is_coordinator
             FROM faculty_subject_assignments fsa
             JOIN subject_areas sa ON sa.id=fsa.subject_area_id
             WHERE fsa.faculty_id=?
             ORDER BY fsa.is_primary DESC, sa.subject_name"
        );
        $stmt->execute([$facultyId]);
    }
    return array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['department_id'] = (int)$row['department_id'];
        $row['is_active'] = (int)($row['is_active'] ?? 1);
        $row['is_primary'] = (int)$row['is_primary'];
        $row['is_coordinator'] = (int)$row['is_coordinator'];
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function subject_assignments_validate(PDO $db, int $departmentId, array $subjectIds, int $primarySubjectId): array
{
    $subjectIds = array_values(array_unique(array_filter(array_map('intval', $subjectIds))));
    if ($subjectIds === []) {
        throw new DomainException('Select at least one subject assignment.');
    }
    if ($primarySubjectId <= 0 || !in_array($primarySubjectId, $subjectIds, true)) {
        throw new DomainException('Select one assigned subject as the primary subject.');
    }
    $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
    $stmt = $db->prepare(
        "SELECT id FROM subject_areas
         WHERE id IN ($placeholders) AND department_id=? AND is_active=1"
    );
    $stmt->execute([...$subjectIds, $departmentId]);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($validIds);
    $expected = $subjectIds;
    sort($expected);
    if ($validIds !== $expected) {
        throw new DomainException('Every selected subject must be active and belong to the selected department.');
    }
    return $subjectIds;
}

function subject_assignments_sync_faculty(PDO $db, int $facultyId, array $subjectIds, int $primarySubjectId): void
{
    $db->prepare('DELETE FROM faculty_subject_assignments WHERE faculty_id=?')->execute([$facultyId]);
    if ($subjectIds === []) {
        $db->prepare('UPDATE subject_areas SET coordinator_faculty_id=NULL WHERE coordinator_faculty_id=?')->execute([$facultyId]);
        return;
    }
    $insert = $db->prepare(
        'INSERT INTO faculty_subject_assignments (faculty_id,subject_area_id,is_primary) VALUES (?,?,?)'
    );
    foreach ($subjectIds as $subjectId) {
        $insert->execute([$facultyId, $subjectId, $subjectId === $primarySubjectId ? 1 : 0]);
    }
    $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
    $db->prepare(
        "UPDATE subject_areas SET coordinator_faculty_id=NULL
         WHERE coordinator_faculty_id=? AND id NOT IN ($placeholders)"
    )->execute([$facultyId, ...$subjectIds]);
}

function subject_assignments_sync_coordinator_designations(PDO $db, int $facultyId, array $assignedSubjectIds, array $coordinatorSubjectIds): void
{
    $coordinatorSubjectIds = array_values(array_unique(array_filter(array_map('intval', $coordinatorSubjectIds))));
    foreach ($coordinatorSubjectIds as $subjectId) {
        if (!in_array($subjectId, $assignedSubjectIds, true)) {
            throw new DomainException('A Subject Coordinator must also be assigned to that subject.');
        }
    }
    $db->prepare('UPDATE subject_areas SET coordinator_faculty_id=NULL WHERE coordinator_faculty_id=?')->execute([$facultyId]);
    if ($coordinatorSubjectIds !== []) {
        $placeholders = implode(',', array_fill(0, count($coordinatorSubjectIds), '?'));
        $db->prepare("UPDATE subject_areas SET coordinator_faculty_id=? WHERE id IN ($placeholders)")
            ->execute([$facultyId, ...$coordinatorSubjectIds]);
    }
}

function subject_assignments_snapshot_faculty(PDO $db, int $periodId, int $userId, int $facultyId): void
{
    subject_assignments_ensure_schema();
    $exists = $db->prepare(
        'SELECT 1 FROM evaluation_period_faculty_subjects WHERE evaluation_period_id=? AND faculty_id=? LIMIT 1'
    );
    $exists->execute([$periodId, $facultyId]);
    if ($exists->fetchColumn()) {
        return;
    }
    $db->prepare(
        "INSERT INTO evaluation_period_faculty_subjects
            (evaluation_period_id,user_id,faculty_id,subject_area_id,department_id,
             subject_code_snapshot,subject_name_snapshot,is_primary,is_coordinator)
         SELECT ?,?,?,sa.id,sa.department_id,sa.subject_code,sa.subject_name,fsa.is_primary,
                (sa.coordinator_faculty_id=fsa.faculty_id)
         FROM faculty_subject_assignments fsa
         JOIN subject_areas sa ON sa.id=fsa.subject_area_id
         WHERE fsa.faculty_id=?"
    )->execute([$periodId, $userId, $facultyId, $facultyId]);
}
