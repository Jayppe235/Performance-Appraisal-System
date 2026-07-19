CREATE TABLE IF NOT EXISTS pmas_evaluator_results_summary (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  evaluator_user_id INT NOT NULL,
  evaluator_role VARCHAR(40) NOT NULL,
  evaluator_full_name VARCHAR(180) NULL,
  assignment_id INT NOT NULL,
  evaluation_period VARCHAR(120) NOT NULL,
  form_type ENUM('form_a', 'form_b') NOT NULL,
  submission_status ENUM('pending', 'submitted', 'draft', 'overdue') NOT NULL DEFAULT 'pending',
  submitted_at DATETIME NULL,
  performance_outputs_score DECIMAL(8,4) NULL,
  performance_factors_score DECIMAL(8,4) NULL,
  overall_rating DECIMAL(8,4) NULL,
  performance_level VARCHAR(80) NULL,
  category_count INT NOT NULL DEFAULT 0,
  questions_answered INT NOT NULL DEFAULT 0,
  total_questions INT NOT NULL DEFAULT 0,
  completion_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  average_category_score DECIMAL(8,4) NULL,
  highest_rated_category VARCHAR(220) NULL,
  lowest_rated_category VARCHAR(220) NULL,
  behavioral_evidence_provided TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evaluator_result_assignment (assignment_id),
  KEY idx_evaluator_summary_faculty_period (faculty_id, evaluation_period),
  KEY idx_evaluator_summary_evaluator_faculty (evaluator_user_id, faculty_id),
  KEY idx_evaluator_summary_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_form_a_results_faculty_period ON pmas_form_a_category_results (evaluatee_faculty_id, evaluation_period);
CREATE INDEX idx_form_a_results_evaluator_faculty ON pmas_form_a_category_results (evaluator_user_id, evaluatee_faculty_id);
CREATE INDEX idx_form_a_results_assignment ON pmas_form_a_category_results (assignment_id);

CREATE INDEX idx_form_b_results_faculty_period ON pmas_form_b_category_results (evaluatee_faculty_id, evaluation_period);
CREATE INDEX idx_form_b_results_evaluator_faculty ON pmas_form_b_category_results (evaluator_user_id, evaluatee_faculty_id);
CREATE INDEX idx_form_b_results_assignment ON pmas_form_b_category_results (assignment_id);
