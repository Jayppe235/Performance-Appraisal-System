-- ============================================================
-- Migration 020: Upgrade notifications for centralized service
-- Adds metadata required by APPRAISIA role/scope workflows while
-- preserving existing user_id/type/description/link columns.
-- ============================================================

ALTER TABLE notifications MODIFY type VARCHAR(40) NOT NULL DEFAULT 'info';

ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS recipient_id INT NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS recipient_role VARCHAR(50) NULL AFTER recipient_id,
  ADD COLUMN IF NOT EXISTS sender_id INT NULL AFTER recipient_role,
  ADD COLUMN IF NOT EXISTS message TEXT NULL AFTER description,
  ADD COLUMN IF NOT EXISTS action_url VARCHAR(500) NULL AFTER link,
  ADD COLUMN IF NOT EXISTS module VARCHAR(80) NULL AFTER action_url,
  ADD COLUMN IF NOT EXISTS related_record_id INT NULL AFTER related_entity_id,
  ADD COLUMN IF NOT EXISTS read_at DATETIME NULL AFTER is_read;

UPDATE notifications SET recipient_id = user_id WHERE recipient_id IS NULL AND user_id IS NOT NULL;
UPDATE notifications SET message = description WHERE message IS NULL AND description IS NOT NULL;
UPDATE notifications SET action_url = link WHERE action_url IS NULL AND link IS NOT NULL;
UPDATE notifications SET module = related_entity_type WHERE module IS NULL AND related_entity_type IS NOT NULL;
UPDATE notifications SET related_record_id = related_entity_id WHERE related_record_id IS NULL AND related_entity_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications (recipient_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_module_record ON notifications (module, related_record_id);
