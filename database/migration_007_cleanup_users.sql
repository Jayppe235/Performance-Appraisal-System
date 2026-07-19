-- ============================================================
-- Migration 007: Cleanup Users - Keep only admin@dipascaf.edu
-- and mark@dipascaf.edu, remove all other hardcoded users
-- ============================================================

-- Step 1: Delete evaluation_submissions linked to users/faculty being removed
DELETE FROM evaluation_submissions
WHERE evaluator_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 2: Delete peer_assignments linked to users being removed
DELETE FROM peer_assignments
WHERE evaluator_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 3: Delete pmas_form_a results linked to users being removed
DELETE FROM pmas_form_a_category_results
WHERE evaluator_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 4: Delete pmas_form_b results linked to users being removed
DELETE FROM pmas_form_b_category_results
WHERE evaluator_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 5: Delete pmas_form_b reports linked to users being removed
DELETE FROM pmas_form_b_reports
WHERE evaluator_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 6: Delete pmas_form_b ai records linked to removed assignments
DELETE FROM pmas_form_b_ai_records
WHERE assignment_id IN (
  SELECT id FROM peer_assignments WHERE evaluator_user_id IN (
    SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
  )
);

-- Step 7: Delete activity logs for users being removed
DELETE FROM activity_logs
WHERE user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 8: Remove department/program leadership references
UPDATE departments SET dean_user_id = NULL
WHERE dean_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

UPDATE programs SET program_head_user_id = NULL
WHERE program_head_user_id IN (
  SELECT id FROM users WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
);

-- Step 9: Delete faculty entries linked to removed users
DELETE FROM faculty
WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu')
AND email NOT LIKE 'admin@dipascaf.edu'
AND email NOT LIKE 'mark@dipascaf.edu';

-- Step 10: Delete the users
DELETE FROM users
WHERE email NOT IN ('admin@dipascaf.edu', 'mark@dipascaf.edu');

-- Step 11: Ensure mark@dipascaf.edu exists (insert if not already present)
INSERT IGNORE INTO users (full_name, email, password_hash, role)
VALUES ('Mark', 'mark@dipascaf.edu', '$2y$10$xodX7sDsspUnzOyyJ27Jl.dhEp0qG3iX9rfB9t60fKHs2PcWVD/rm', 'teacher');

-- Step 12: Ensure admin@dipascaf.edu exists with the expected password
INSERT INTO users (full_name, email, password_hash, role, is_active)
VALUES ('Admin HR User', 'admin@dipascaf.edu', '$2y$10$3IA8KHQsviRThHoDS5C95.9BWEnLubFNmJoZbIhMY9kczGaJ35K.y', 'admin_hr', 1)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  is_active = 1;

-- Step 13: Ensure faculty entry exists for mark
INSERT IGNORE INTO faculty (full_name, email, phone, department, program_code, position_title, academic_qualifications, progress_percent, performance_notes)
VALUES ('Mark', 'mark@dipascaf.edu', '09170000001', 'Computer Studies', 'BSIT', 'Instructor I', 'MIT, BS Computer Science', 75, 'Faculty member for performance evaluation.');

-- Step 14: Ensure admin@dipascaf.edu faculty entry exists
INSERT IGNORE INTO faculty (full_name, email, phone, department, program_code, position_title, academic_qualifications, progress_percent, performance_notes)
VALUES ('Admin HR User', 'admin@dipascaf.edu', '09170000002', 'Computer Studies', NULL, 'Admin', 'Administrator', 100, 'System administrator account.');
