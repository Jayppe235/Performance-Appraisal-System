ALTER TABLE appraisal_periods
  MODIFY status ENUM('draft', 'open', 'locked', 'closed') NOT NULL DEFAULT 'draft';

ALTER TABLE appraisal_periods
  ADD COLUMN school_year VARCHAR(20) NULL AFTER period_name,
  ADD COLUMN semester VARCHAR(40) NULL AFTER school_year,
  ADD COLUMN locked_at DATETIME NULL AFTER status,
  ADD COLUMN opened_at DATETIME NULL AFTER locked_at;

