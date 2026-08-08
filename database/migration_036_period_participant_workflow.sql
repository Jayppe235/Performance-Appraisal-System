ALTER TABLE users
  ADD COLUMN start_evaluation_period_id INT NULL AFTER program,
  ADD KEY idx_users_start_evaluation_period (start_evaluation_period_id);

ALTER TABLE evaluation_period_participation
  ADD COLUMN employment_status ENUM(
    'active',
    'newly_added',
    'not_yet_employed',
    'on_leave',
    'inactive'
  ) NOT NULL DEFAULT 'active' AFTER work_status;

ALTER TABLE appraisal_periods
  ADD COLUMN participants_finalized_at DATETIME NULL AFTER opened_at,
  ADD COLUMN participants_finalized_by INT NULL AFTER participants_finalized_at,
  ADD COLUMN peer_assignments_validated_at DATETIME NULL AFTER participants_finalized_by,
  ADD COLUMN peer_assignments_validated_by INT NULL AFTER peer_assignments_validated_at;
