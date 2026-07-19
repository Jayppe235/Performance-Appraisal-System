-- Migration 016: Allow Dean reopen/edit tracking for faculty self evaluations.

SET @schema_name = DATABASE();

SET @add_reopened_at = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_at DATETIME NULL AFTER submitted_at',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'reopened_at'
);
PREPARE stmt FROM @add_reopened_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_reopened_by = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_by INT NULL AFTER reopened_at',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'pmas_self_evaluations'
    AND COLUMN_NAME = 'reopened_by'
);
PREPARE stmt FROM @add_reopened_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE pmas_self_evaluations
  MODIFY status ENUM('draft','submitted','reopened') NOT NULL DEFAULT 'draft';
