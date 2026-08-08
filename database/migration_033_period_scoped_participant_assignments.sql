-- Period-scoped role and organizational assignments.
-- Global users/faculty values remain the default for periods without a snapshot.

ALTER TABLE evaluation_period_participation
  ADD COLUMN role_snapshot ENUM('teacher','program_head') NULL AFTER faculty_id,
  ADD COLUMN department_id INT NULL AFTER role_snapshot,
  ADD COLUMN program_id INT NULL AFTER department_id,
  ADD COLUMN department_snapshot VARCHAR(190) NULL AFTER program_id,
  ADD COLUMN program_snapshot VARCHAR(40) NULL AFTER department_snapshot,
  ADD COLUMN assignment_source ENUM('master','inferred','admin') NOT NULL DEFAULT 'master' AFTER program_snapshot,
  ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER assignment_source,
  ADD COLUMN program_head_slot INT NULL AFTER needs_review,
  ADD CONSTRAINT fk_period_participation_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_period_participation_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uq_period_program_head_slot (evaluation_period_id, program_head_slot),
  ADD KEY idx_period_participation_assignment (evaluation_period_id, role_snapshot, department_id, program_id);

-- Existing explicit participation rows are frozen from their current master data.
UPDATE evaluation_period_participation epp
JOIN users u ON u.id = epp.user_id
LEFT JOIN departments d
  ON d.is_active = 1
 AND (d.department_name = u.department OR d.department_code = u.department)
LEFT JOIN programs p
  ON p.is_active = 1
 AND p.department_id = d.id
 AND UPPER(p.program_code) = UPPER(u.program)
SET epp.role_snapshot = CASE WHEN u.role = 'program_head' THEN 'program_head' ELSE 'teacher' END,
    epp.department_id = d.id,
    epp.program_id = p.id,
    epp.department_snapshot = COALESCE(d.department_name, u.department),
    epp.program_snapshot = COALESCE(p.program_code, u.program),
    epp.assignment_source = 'master',
    epp.needs_review = IF(d.id IS NULL OR p.id IS NULL, 1, 0),
    epp.program_head_slot = CASE
      WHEN u.role = 'program_head' AND epp.participation_status = 'included' THEN p.id
      ELSE NULL
    END
WHERE epp.role_snapshot IS NULL;
