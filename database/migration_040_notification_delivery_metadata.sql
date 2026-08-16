-- Migration 040: durable notification event metadata and delivery diagnostics.
-- Existing notification history is preserved.

ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS event_key VARCHAR(191) NULL AFTER related_record_id,
  ADD COLUMN IF NOT EXISTS event_payload JSON NULL AFTER event_key,
  ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(30) NOT NULL DEFAULT 'created' AFTER event_payload,
  ADD COLUMN IF NOT EXISTS delivery_error TEXT NULL AFTER delivery_status;

CREATE INDEX IF NOT EXISTS idx_notifications_event_key ON notifications (event_key);
CREATE INDEX IF NOT EXISTS idx_notifications_recipient_created ON notifications (recipient_id, created_at);

UPDATE notifications SET delivery_status = 'created' WHERE delivery_status IS NULL OR delivery_status = '';
