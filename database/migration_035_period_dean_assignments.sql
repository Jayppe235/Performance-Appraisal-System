-- Period-scoped Dean authority and participant work state.
ALTER TABLE evaluation_period_participation
  MODIFY role_snapshot ENUM('teacher','program_head','dean') NULL,
  ADD COLUMN work_status ENUM('active','no_assignments') NOT NULL DEFAULT 'active' AFTER participation_status;

CREATE TABLE IF NOT EXISTS evaluation_period_deans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_period_id INT NOT NULL,
  department_id INT NOT NULL,
  user_id INT NOT NULL,
  is_acting TINYINT(1) NOT NULL DEFAULT 0,
  assignment_source ENUM('master','inferred','admin') NOT NULL DEFAULT 'admin',
  authorization_reason VARCHAR(500) NULL,
  replaced_user_id INT NULL,
  replaced_dean_action ENUM('faculty','excluded','no_assignments') NULL,
  authorized_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_period_department_dean (evaluation_period_id,department_id),
  UNIQUE KEY uq_period_dean_user_department (evaluation_period_id,user_id,department_id),
  KEY idx_period_deans_user (user_id,evaluation_period_id),
  CONSTRAINT fk_epd_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_epd_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_epd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_epd_replaced_user FOREIGN KEY (replaced_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_epd_authorizer FOREIGN KEY (authorized_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
