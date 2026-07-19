-- Migration 011: Add deadline column to peer_assignments table
-- This enables accurate overdue/due-soon calculations in dashboards
-- instead of relying on assigned_at as a proxy.

ALTER TABLE `peer_assignments`
  ADD COLUMN `deadline` DATE NULL DEFAULT NULL AFTER `assigned_at`;
