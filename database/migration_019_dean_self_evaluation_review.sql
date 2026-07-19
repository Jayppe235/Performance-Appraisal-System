-- Migration 019: Dean self-evaluation review and approval workflow.

SET @schema_name = DATABASE();

SET @add_dean_review_status = (
  SELECT IF(
    COUNT(*) = 0,
    "ALTER TABLE pmas_self_evaluations ADD COLUMN dean_review_status ENUM('pending','approved','reopened','submitted_to_admin') NOT NULL DEFAULT 'pending' AFTER reopened_by",
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'dean_review_status'
);
PREPARE stmt FROM @add_dean_review_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_dean_reviewed_by = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_reviewed_by INT NULL AFTER dean_review_status',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'dean_reviewed_by'
);
PREPARE stmt FROM @add_dean_reviewed_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_dean_reviewed_at = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_reviewed_at DATETIME NULL AFTER dean_reviewed_by',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'dean_reviewed_at'
);
PREPARE stmt FROM @add_dean_reviewed_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_dean_review_notes = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_review_notes TEXT NULL AFTER dean_reviewed_at',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'dean_review_notes'
);
PREPARE stmt FROM @add_dean_review_notes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_reopened_reason = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_reason TEXT NULL AFTER dean_review_notes',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'reopened_reason'
);
PREPARE stmt FROM @add_reopened_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_revision_count = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN revision_count INT NOT NULL DEFAULT 0 AFTER reopened_reason',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'revision_count'
);
PREPARE stmt FROM @add_revision_count;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_final_admin_status = (
  SELECT IF(
    COUNT(*) = 0,
    "ALTER TABLE pmas_self_evaluations ADD COLUMN final_admin_submission_status ENUM('not_ready','ready_for_admin','submitted_to_admin') NOT NULL DEFAULT 'not_ready' AFTER revision_count",
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'final_admin_submission_status'
);
PREPARE stmt FROM @add_final_admin_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_admin_review_status = (
  SELECT IF(
    COUNT(*) = 0,
    "ALTER TABLE pmas_self_evaluations ADD COLUMN admin_review_status ENUM('none','pending','reviewed','returned_to_dean') NOT NULL DEFAULT 'none' AFTER final_admin_submission_status",
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'admin_review_status'
);
PREPARE stmt FROM @add_admin_review_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_admin_reviewed_by = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_reviewed_by INT NULL AFTER admin_review_status',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'admin_reviewed_by'
);
PREPARE stmt FROM @add_admin_reviewed_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_admin_reviewed_at = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_reviewed_by',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'admin_reviewed_at'
);
PREPARE stmt FROM @add_admin_reviewed_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_admin_return_reason = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_return_reason TEXT NULL AFTER admin_reviewed_at',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'admin_return_reason'
);
PREPARE stmt FROM @add_admin_return_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS pmas_self_evaluation_audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  self_evaluation_id INT NOT NULL,
  user_id INT NULL,
  user_role VARCHAR(40) NOT NULL,
  action_type VARCHAR(60) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_self_eval_audit_record (self_evaluation_id, created_at),
  KEY idx_self_eval_audit_user (user_id),
  CONSTRAINT fk_self_eval_audit_record
    FOREIGN KEY (self_evaluation_id) REFERENCES pmas_self_evaluations(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_self_eval_audit_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
