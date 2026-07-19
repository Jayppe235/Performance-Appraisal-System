-- Period-scoped participation preserves accounts/history while removing a
-- person from one evaluation cycle only.
CREATE TABLE IF NOT EXISTS evaluation_period_participation (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE peer_evaluation_assignments
  MODIFY status ENUM('pending','completed','overdue','not_required') NOT NULL DEFAULT 'pending';
