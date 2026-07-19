-- Migration 006: Repair questionnaire routing for existing assignments.
-- Form A (admin) is used when the evaluatee is a Dean or Program Head.
-- Form B (faculty) is used for faculty/teacher evaluatees.

ALTER TABLE pmas_form_a_categories
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order;

UPDATE peer_assignments pa
JOIN faculty f ON f.id = pa.evaluatee_faculty_id
SET pa.questionnaire_type = CASE
  WHEN LOWER(f.position_title) LIKE '%dean%'
    OR LOWER(f.position_title) LIKE '%program head%'
    THEN 'admin'
  ELSE 'faculty'
END
WHERE pa.questionnaire_type IS NULL;
