-- Migration: Add program column to users table
-- This enables storing which program (e.g., BSIT, BSED, BSCS) a user is associated with
-- which is needed for enforcing one Program Head per program validation

ALTER TABLE users ADD COLUMN IF NOT EXISTS program VARCHAR(30) NULL AFTER department;
