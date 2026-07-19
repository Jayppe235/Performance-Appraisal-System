ALTER TABLE ai_insights
  ADD COLUMN IF NOT EXISTS completion_status ENUM('complete','partial','incomplete') NOT NULL DEFAULT 'incomplete',
  ADD COLUMN IF NOT EXISTS submitted_evaluations_count INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_expected_evaluations INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS completion_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS pending_evaluators JSON NULL,
  ADD COLUMN IF NOT EXISTS will_update_on_date DATETIME NULL,
  ADD COLUMN IF NOT EXISTS recommendation_status ENUM('preliminary','interim','final') NOT NULL DEFAULT 'preliminary';

CREATE TABLE IF NOT EXISTS ai_recommendation_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ai_insight_id INT NULL,
  faculty_id INT NULL,
  department VARCHAR(120) NULL,
  program VARCHAR(120) NULL,
  status_before VARCHAR(40) NULL,
  status_after VARCHAR(40) NULL,
  submitted_count_before INT NOT NULL DEFAULT 0,
  submitted_count_after INT NOT NULL DEFAULT 0,
  recommendation_changed TINYINT(1) NOT NULL DEFAULT 0,
  changed_by_system TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_audit_faculty (faculty_id),
  KEY idx_ai_audit_insight (ai_insight_id),
  KEY idx_ai_audit_department_program (department, program)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
