-- Preserve evaluator history across leadership changes without duplicating
-- the normal required Program Head evaluation for the same cycle.

ALTER TABLE peer_assignments
  MODIFY status ENUM(
    'pending', 'in_progress', 'submitted', 'reassigned', 'cancelled',
    'replaced', 'reopened', 'not_required'
  ) NOT NULL DEFAULT 'pending',
  ADD COLUMN effective_from DATETIME NULL AFTER assigned_at,
  ADD COLUMN effective_to DATETIME NULL AFTER effective_from,
  ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1 AFTER effective_to,
  ADD COLUMN replaced_by_assignment_id INT NULL AFTER is_current,
  ADD COLUMN replacement_reason VARCHAR(500) NULL AFTER replaced_by_assignment_id,
  ADD COLUMN is_additional TINYINT(1) NOT NULL DEFAULT 0 AFTER replacement_reason,
  ADD COLUMN evaluator_name_snapshot VARCHAR(190) NULL AFTER is_additional,
  ADD COLUMN evaluator_role_snapshot VARCHAR(40) NULL AFTER evaluator_name_snapshot,
  ADD INDEX idx_assignment_cycle_requirement
    (cycle_name, evaluatee_faculty_id, assignment_type, status, is_current, is_additional),
  ADD CONSTRAINT fk_assignment_replaced_by
    FOREIGN KEY (replaced_by_assignment_id) REFERENCES peer_assignments(id) ON DELETE SET NULL;

UPDATE peer_assignments pa
JOIN users u ON u.id = pa.evaluator_user_id
SET pa.effective_from = COALESCE(pa.effective_from, pa.assigned_at),
    pa.evaluator_name_snapshot = COALESCE(pa.evaluator_name_snapshot, u.full_name),
    pa.evaluator_role_snapshot = COALESCE(pa.evaluator_role_snapshot, pa.evaluator_role);

-- Repair the existing case where a newly assigned current Program Head was
-- added as pending even though the cycle already had an official submission.
UPDATE peer_assignments current_assignment
JOIN faculty target_faculty ON target_faculty.id = current_assignment.evaluatee_faculty_id
JOIN programs current_program
  ON current_program.is_active = 1
 AND current_program.program_code = target_faculty.program_code
 AND current_program.program_head_user_id = current_assignment.evaluator_user_id
JOIN peer_assignments submitted_assignment
  ON submitted_assignment.cycle_name = current_assignment.cycle_name
 AND submitted_assignment.evaluatee_faculty_id = current_assignment.evaluatee_faculty_id
 AND submitted_assignment.assignment_type = 'program_head'
 AND submitted_assignment.status = 'submitted'
 AND submitted_assignment.id <> current_assignment.id
SET current_assignment.status = 'not_required',
    current_assignment.is_current = 1,
    current_assignment.effective_from = COALESCE(current_assignment.effective_from, NOW()),
    current_assignment.replacement_reason = 'Current Program Head starts with the next evaluation cycle because this cycle already has an official submitted Program Head evaluation.',
    submitted_assignment.is_current = 0,
    submitted_assignment.effective_to = COALESCE(submitted_assignment.effective_to, NOW())
WHERE current_assignment.assignment_type = 'program_head'
  AND current_assignment.status IN ('pending', 'in_progress', 'reopened')
  AND COALESCE(current_assignment.is_archived, 0) = 0;

CREATE TABLE IF NOT EXISTS evaluator_assignment_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assignment_id INT NULL,
  faculty_id INT NOT NULL,
  evaluator_id INT NOT NULL,
  evaluator_name VARCHAR(190) NOT NULL,
  evaluator_role VARCHAR(40) NOT NULL,
  evaluation_type VARCHAR(40) NOT NULL,
  evaluation_cycle VARCHAR(120) NOT NULL,
  effective_from DATETIME NULL,
  effective_to DATETIME NULL,
  status VARCHAR(40) NOT NULL,
  previous_assignment_id INT NULL,
  replacement_reason VARCHAR(500) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_assignment_history_lookup (faculty_id, evaluation_cycle, evaluation_type),
  CONSTRAINT fk_assignment_history_assignment FOREIGN KEY (assignment_id) REFERENCES peer_assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_assignment_history_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignment_history_evaluator FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignment_history_previous FOREIGN KEY (previous_assignment_id) REFERENCES peer_assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_assignment_history_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
