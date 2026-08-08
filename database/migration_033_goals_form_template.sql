CREATE TABLE IF NOT EXISTS pmas_goals_form_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(80) NOT NULL DEFAULT 'pmas_form_1',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  template_json LONGTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_goals_template_version (template_key, version),
  KEY idx_goals_template_active (template_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pmas_goals_records
  ADD COLUMN IF NOT EXISTS template_id INT UNSIGNED NULL AFTER period_id,
  ADD COLUMN IF NOT EXISTS template_version INT UNSIGNED NULL AFTER template_id,
  ADD COLUMN IF NOT EXISTS template_snapshot_json LONGTEXT NULL AFTER goals_json;

ALTER TABLE pmas_goals_records
  MODIFY status ENUM('draft','submitted','under_review','approved','returned','reopened')
  NOT NULL DEFAULT 'draft';
