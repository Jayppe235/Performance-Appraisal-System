-- Migration 004: Create evaluation_schedules table
-- This table tracks admin-created evaluation assignment schedules
-- Each schedule links an evaluation period to a due date and auto-generates assignments

CREATE TABLE IF NOT EXISTS `evaluation_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `evaluation_period_id` INT NOT NULL,
    `due_date` DATE NOT NULL,
    `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    `total_assignments` INT NOT NULL DEFAULT 0,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`evaluation_period_id`) REFERENCES `appraisal_periods`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_schedule_period` (`evaluation_period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
