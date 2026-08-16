CREATE TABLE IF NOT EXISTS report_ai_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  filter_hash CHAR(64) NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  filters_json JSON NOT NULL,
  evidence_json JSON NOT NULL,
  recommendation_json JSON NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'scoped_database_rules',
  provider VARCHAR(80) NULL,
  model VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_snapshot (user_id, filter_hash, evidence_hash),
  KEY idx_report_snapshot_lookup (user_id, filter_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
