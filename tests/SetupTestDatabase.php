<?php
/**
 * Creates the test database 'pmas_test_phpunit' with all tables needed
 * for the restored functions unit tests.
 *
 * Schema matches production tables in pmas_db_clean.
 *
 * Usage: php tests/SetupTestDatabase.php
 */

declare(strict_types=1);

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$testDb = 'pmas_test_phpunit';

try {
    // Connect without database to create it
    $pdo = new PDO("mysql:host={$dbHost};port=3306;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
    $pdo->exec("CREATE DATABASE `{$testDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("USE `{$testDb}`");

    // ── users table ──────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin_hr','vpaa','dean','program_head','teacher') NOT NULL DEFAULT 'teacher',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            department VARCHAR(120) DEFAULT NULL,
            program VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            profile_image VARCHAR(255) DEFAULT NULL,
            last_login_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── faculty table ────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS faculty (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            department VARCHAR(120) DEFAULT NULL,
            program_code VARCHAR(30) DEFAULT NULL,
            position_title VARCHAR(120) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            progress_percent DECIMAL(5,2) DEFAULT 0,
            performance_notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_faculty_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── departments table ────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            department_code VARCHAR(20) NOT NULL UNIQUE,
            department_name VARCHAR(120) NOT NULL,
            dean_user_id INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── programs table ───────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS programs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_code VARCHAR(30) NOT NULL UNIQUE,
            program_name VARCHAR(255) NOT NULL,
            department_id INT NOT NULL,
            program_head_user_id INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── peer_assignments table (matches production schema) ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS peer_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cycle_name VARCHAR(120) NOT NULL,
            evaluator_user_id INT NOT NULL,
            evaluatee_faculty_id INT NOT NULL,
            evaluator_role ENUM('vpaa','dean','program_head','teacher') NOT NULL,
            assignment_type ENUM('peer','program_head','dean','self') NOT NULL,
            questionnaire_type ENUM('admin','faculty') DEFAULT NULL,
            status ENUM('pending','submitted') NOT NULL DEFAULT 'pending',
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deadline DATE DEFAULT NULL,
            submitted_at DATETIME DEFAULT NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME DEFAULT NULL,
            archived_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_assignment (cycle_name, evaluator_user_id, evaluatee_faculty_id, assignment_type),
            KEY fk_assignment_evaluator (evaluator_user_id),
            KEY fk_assignment_faculty (evaluatee_faculty_id),
            CONSTRAINT fk_assignment_evaluator FOREIGN KEY (evaluator_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_assignment_faculty FOREIGN KEY (evaluatee_faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── appraisal_periods table ──────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appraisal_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_name VARCHAR(120) NOT NULL,
            school_year VARCHAR(20) DEFAULT NULL,
            semester VARCHAR(40) DEFAULT NULL,
            date_start DATE DEFAULT NULL,
            date_end DATE DEFAULT NULL,
            status ENUM('draft','open','locked','closed') NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── peer_evaluation_assignments table (matches production) ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS peer_evaluation_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            peer_assignment_id INT DEFAULT NULL,
            evaluator_id INT NOT NULL,
            evaluatee_id INT NOT NULL,
            evaluatee_faculty_id INT DEFAULT NULL,
            department_id INT DEFAULT NULL,
            evaluation_period_id INT NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending','completed','overdue') NOT NULL DEFAULT 'pending',
            locked_at DATETIME DEFAULT NULL,
            regenerated_from_id INT DEFAULT NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME DEFAULT NULL,
            archived_by INT DEFAULT NULL,
            UNIQUE KEY uq_peer_eval_evaluator_period (evaluator_id, evaluation_period_id),
            UNIQUE KEY uq_peer_eval_pair_period (evaluator_id, evaluatee_id, evaluation_period_id),
            KEY idx_peer_eval_department_period (department_id, evaluation_period_id),
            KEY idx_peer_eval_status (status),
            KEY fk_peer_eval_assignment (peer_assignment_id),
            KEY fk_peer_eval_faculty (evaluatee_faculty_id),
            KEY fk_peer_eval_period (evaluation_period_id),
            KEY idx_peer_eval_evaluatee (evaluatee_id),
            CONSTRAINT fk_peer_eval_assignment FOREIGN KEY (peer_assignment_id) REFERENCES peer_assignments(id) ON DELETE SET NULL,
            CONSTRAINT fk_peer_eval_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
            CONSTRAINT fk_peer_eval_evaluatee FOREIGN KEY (evaluatee_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_peer_eval_evaluator FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_peer_eval_faculty FOREIGN KEY (evaluatee_faculty_id) REFERENCES faculty(id) ON DELETE SET NULL,
            CONSTRAINT fk_peer_eval_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,status ENUM('pending','completed') NOT NULL DEFAULT 'pending',requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at DATETIME NULL,completed_by_user_id INT NULL,pending_user_id INT GENERATED ALWAYS AS (CASE WHEN status='pending' THEN user_id ELSE NULL END) STORED,UNIQUE KEY uq_password_reset_pending_user(pending_user_id)) ENGINE=InnoDB");

    // ── notifications table (matches production) ────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL COMMENT 'NULL = system-wide notification for all users',
            type ENUM('system_update','account_activity') NOT NULL DEFAULT 'system_update',
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            link VARCHAR(500) DEFAULT NULL COMMENT 'Optional link to relevant page',
            related_entity_type VARCHAR(50) DEFAULT NULL COMMENT 'e.g., evaluation, intervention, profile, period',
            related_entity_id INT DEFAULT NULL COMMENT 'ID of the related entity',
            event_key VARCHAR(191) DEFAULT NULL,
            event_payload JSON DEFAULT NULL,
            delivery_status VARCHAR(30) NOT NULL DEFAULT 'created',
            delivery_error TEXT DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notifications_user_read (user_id, is_read),
            KEY idx_notifications_created (created_at),
            KEY idx_notifications_event_key (event_key),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── system_settings table ────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(120) NOT NULL UNIQUE,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // ── activity_logs table ──────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            description TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    echo "✅ Test database '{$testDb}' created successfully with all tables.\n";

} catch (PDOException $e) {
    echo "❌ Database setup failed: " . $e->getMessage() . "\n";
    exit(1);
}
