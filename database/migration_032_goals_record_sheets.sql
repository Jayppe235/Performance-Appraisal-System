CREATE TABLE IF NOT EXISTS pmas_goals_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  period_id INT NOT NULL,
  employee_name VARCHAR(190) NOT NULL,
  position_title VARCHAR(190) NOT NULL DEFAULT '',
  department VARCHAR(190) NOT NULL DEFAULT '',
  appraisal_period VARCHAR(190) NOT NULL DEFAULT '',
  goals_json LONGTEXT NOT NULL,
  status ENUM('draft','submitted','under_review','approved','returned') NOT NULL DEFAULT 'draft',
  reviewer_id INT NULL,
  reviewer_name VARCHAR(190) NOT NULL DEFAULT '',
  review_comment TEXT NULL,
  submitted_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_goals_user_period (user_id, period_id),
  KEY idx_goals_status (status),
  KEY idx_goals_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pmas_goals_record_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_id INT UNSIGNED NOT NULL,
  revision_no INT NOT NULL,
  snapshot_json LONGTEXT NOT NULL,
  action VARCHAR(40) NOT NULL,
  actor_id INT NOT NULL,
  actor_name VARCHAR(190) NOT NULL DEFAULT '',
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_goal_revision_record (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
