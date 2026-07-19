CREATE TABLE IF NOT EXISTS password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  completed_by_user_id INT NULL,
  pending_user_id INT GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN user_id ELSE NULL END) STORED,
  UNIQUE KEY uq_password_reset_pending_user (pending_user_id),
  KEY idx_password_reset_status_requested (status, requested_at),
  KEY idx_password_reset_completed_by (completed_by_user_id),
  CONSTRAINT fk_password_reset_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_password_reset_request_admin FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
