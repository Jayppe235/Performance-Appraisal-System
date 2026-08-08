-- Evaluation periods are managed by academic year, not semester.
UPDATE appraisal_periods SET semester = NULL WHERE semester IS NOT NULL;
