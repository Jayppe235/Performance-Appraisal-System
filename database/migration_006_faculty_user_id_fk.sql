-- Migration 006: Add user_id foreign key to faculty table
-- This replaces the fragile email-based join between users and faculty

-- Step 1: Add the user_id column (nullable initially for backfill)
ALTER TABLE `faculty`
    ADD COLUMN `user_id` INT NULL DEFAULT NULL AFTER `id`,
    ADD INDEX `idx_faculty_user_id` (`user_id`);

-- Step 2: Backfill user_id for existing faculty records that have matching users
UPDATE `faculty` f
    JOIN `users` u ON u.email = f.email
SET f.user_id = u.id
WHERE f.user_id IS NULL;

-- Step 3: Add foreign key constraint
ALTER TABLE `faculty`
    ADD CONSTRAINT `fk_faculty_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

-- Step 4: Add unique constraint on user_id (each user links to at most one faculty record)
ALTER TABLE `faculty`
    ADD UNIQUE INDEX `uq_faculty_user_id` (`user_id`);
