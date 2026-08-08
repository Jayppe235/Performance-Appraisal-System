-- Password changes are optional; users enter their dashboard immediately after login.
ALTER TABLE users ALTER COLUMN must_change_password SET DEFAULT 0;
UPDATE users SET must_change_password = 0 WHERE must_change_password <> 0;

