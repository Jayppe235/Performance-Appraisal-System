-- Require users to replace temporary passwords on first sign-in.
ALTER TABLE users ALTER COLUMN must_change_password SET DEFAULT 1;

-- Accounts still using the standardized migration-028 hash must change it.
UPDATE users
SET must_change_password = 1
WHERE password_hash = '$2y$10$nWYKxP4i4oPD4.ObVjDZaeRJVwH6LjYm5l4dPoOC.4cZ/d/U83Z86';
