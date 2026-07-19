-- ============================================================
-- Migration 015: Create notifications table
-- Structured notification system with types, read status, and
-- user targeting for the PMAS notification center
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL COMMENT 'NULL = system-wide notification for all users',
  type ENUM('system_update', 'account_activity') NOT NULL DEFAULT 'system_update',
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  link VARCHAR(500) NULL COMMENT 'Optional link to relevant page',
  related_entity_type VARCHAR(50) NULL COMMENT 'e.g., evaluation, intervention, profile, period',
  related_entity_id INT NULL COMMENT 'ID of the related entity',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user_read (user_id, is_read),
  INDEX idx_notifications_created (created_at),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
