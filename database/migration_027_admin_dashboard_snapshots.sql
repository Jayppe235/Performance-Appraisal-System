CREATE TABLE IF NOT EXISTS admin_dashboard_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  period_id INT NOT NULL DEFAULT 0,
  department VARCHAR(160) NOT NULL DEFAULT '',
  program VARCHAR(160) NOT NULL DEFAULT '',
  metrics_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_dashboard_snapshot (snapshot_date, period_id, department, program),
  KEY idx_admin_dashboard_snapshot_lookup (period_id, department, program, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
