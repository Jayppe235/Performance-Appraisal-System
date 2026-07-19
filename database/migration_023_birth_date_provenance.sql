-- Track whether a birthday is authoritative or temporarily assigned for account setup.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS birth_date_is_temporary TINYINT(1) NOT NULL DEFAULT 0 AFTER birth_date;

-- Existing non-null dates are treated as actual records. Temporary dates are assigned
-- by scripts/AssignTemporaryBirthDates.php so each password is securely hashed in PHP.
