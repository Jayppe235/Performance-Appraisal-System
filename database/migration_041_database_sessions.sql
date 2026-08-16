CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(128) NOT NULL,
    payload MEDIUMBLOB NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (session_id),
    KEY idx_user_sessions_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
