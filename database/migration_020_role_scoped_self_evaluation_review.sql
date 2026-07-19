-- Independent review state for Program Head and VPAA self-evaluation workflows.
ALTER TABLE pmas_self_evaluations
  ADD COLUMN IF NOT EXISTS program_head_review_status ENUM('pending','approved','reopened') NOT NULL DEFAULT 'pending' AFTER dean_review_notes,
  ADD COLUMN IF NOT EXISTS program_head_reviewed_by INT NULL AFTER program_head_review_status,
  ADD COLUMN IF NOT EXISTS program_head_reviewed_at DATETIME NULL AFTER program_head_reviewed_by,
  ADD COLUMN IF NOT EXISTS program_head_review_notes TEXT NULL AFTER program_head_reviewed_at,
  ADD COLUMN IF NOT EXISTS vpaa_review_status ENUM('pending','approved','reopened') NOT NULL DEFAULT 'pending' AFTER program_head_review_notes,
  ADD COLUMN IF NOT EXISTS vpaa_reviewed_by INT NULL AFTER vpaa_review_status,
  ADD COLUMN IF NOT EXISTS vpaa_reviewed_at DATETIME NULL AFTER vpaa_reviewed_by,
  ADD COLUMN IF NOT EXISTS vpaa_review_notes TEXT NULL AFTER vpaa_reviewed_at;

