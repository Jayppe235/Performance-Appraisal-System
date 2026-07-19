-- Migration 005: Add questionnaire_type column to peer_assignments
-- This connects each assignment to the correct questionnaire form (admin/faculty)
-- Form A (admin) = PMAS Form A / Administrative Evaluation
-- Form B (faculty) = PMAS Form B / Faculty Evaluation

ALTER TABLE `peer_assignments`
    ADD COLUMN `questionnaire_type` ENUM('admin', 'faculty') NULL DEFAULT NULL AFTER `assignment_type`,
    ADD INDEX `idx_questionnaire_type` (`questionnaire_type`);
