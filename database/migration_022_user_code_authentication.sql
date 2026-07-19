-- Numeric user-code authentication. Back up the database before applying.
-- Existing foreign keys continue to reference users.id; no historical rows are rewritten.

ALTER TABLE users ADD COLUMN IF NOT EXISTS user_code BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER email_verified_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash;
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

SET @next_user_code := 2025000;
UPDATE users u
JOIN (
  SELECT id, (@next_user_code := @next_user_code + 1) AS assigned_code
  FROM users
  WHERE user_code IS NULL
  ORDER BY created_at, id
) ordered_users ON ordered_users.id = u.id
SET u.user_code = ordered_users.assigned_code,
    u.must_change_password = 1;

ALTER TABLE users MODIFY user_code BIGINT UNSIGNED NOT NULL;
SET @has_user_code_index := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_user_code'
);
SET @user_code_index_sql := IF(@has_user_code_index = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_user_code (user_code)', 'SELECT 1');
PREPARE user_code_index_stmt FROM @user_code_index_sql;
EXECUTE user_code_index_stmt;
DEALLOCATE PREPARE user_code_index_stmt;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_type ENUM('email_verification','password_reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_auth_token_hash (token_hash),
  KEY idx_auth_token_user_type (user_id, token_type, created_at),
  CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_code_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  old_user_code BIGINT UNSIGNED NOT NULL,
  new_user_code BIGINT UNSIGNED NOT NULL,
  changed_by_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_code_audit_user (user_id, created_at),
  CONSTRAINT fk_user_code_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_user_code_audit_actor FOREIGN KEY (changed_by_user_id) REFERENCES users(id)
);

INSERT INTO system_settings (setting_key, setting_value)
SELECT 'next_user_code', CAST(COALESCE(MAX(user_code), 2025000) + 1 AS CHAR) FROM users
ON DUPLICATE KEY UPDATE setting_value = GREATEST(CAST(setting_value AS UNSIGNED), CAST(VALUES(setting_value) AS UNSIGNED));

-- Verification: this must return zero rows.
SELECT user_code, COUNT(*) duplicate_count FROM users GROUP BY user_code HAVING COUNT(*) > 1;

-- Rollback (only before the application starts using the new fields):
-- DROP TABLE user_code_audit; DROP TABLE auth_tokens;
-- DELETE FROM system_settings WHERE setting_key='next_user_code';
-- ALTER TABLE users DROP INDEX uq_users_user_code, DROP COLUMN user_code,
--   DROP COLUMN email_verified_at, DROP COLUMN birth_date,
--   DROP COLUMN must_change_password, DROP COLUMN updated_at;
