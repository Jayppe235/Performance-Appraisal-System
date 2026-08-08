-- Legacy accounts without a configured start period existed before period
-- participation was introduced. Assign only those accounts to every stored
-- period. Accounts already configured with a start period are not changed.

SET @legacy_start_period_id := (
  SELECT id
  FROM appraisal_periods
  ORDER BY date_start, id
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS legacy_period_accounts;
CREATE TEMPORARY TABLE legacy_period_accounts (
  user_id INT PRIMARY KEY
);

INSERT INTO legacy_period_accounts (user_id)
SELECT id
FROM users
WHERE role IN ('teacher','program_head','dean')
  AND start_evaluation_period_id IS NULL;

UPDATE users u
JOIN legacy_period_accounts legacy ON legacy.user_id=u.id
SET u.start_evaluation_period_id=@legacy_start_period_id;

INSERT INTO evaluation_period_participation (
  evaluation_period_id,
  user_id,
  faculty_id,
  role_snapshot,
  department_id,
  program_id,
  department_snapshot,
  program_snapshot,
  assignment_source,
  needs_review,
  participation_status,
  work_status,
  employment_status
)
SELECT
  ap.id,
  u.id,
  (SELECT f.id FROM faculty f
    WHERE f.user_id=u.id OR (f.user_id IS NULL AND f.email=u.email)
    ORDER BY f.user_id IS NULL, f.id LIMIT 1),
  CASE
    WHEN u.role='dean' THEN 'dean'
    WHEN u.role='program_head' THEN 'program_head'
    ELSE 'teacher'
  END,
  (SELECT d.id FROM departments d
    WHERE d.department_name=u.department OR d.department_code=u.department
    ORDER BY d.is_active DESC,d.id LIMIT 1),
  (SELECT p.id FROM programs p
    JOIN departments d ON d.id=p.department_id
    WHERE UPPER(p.program_code)=UPPER(u.program)
      AND (d.department_name=u.department OR d.department_code=u.department)
    ORDER BY p.is_active DESC,p.id LIMIT 1),
  u.department,
  u.program,
  'master',
  0,
  'included',
  'active',
  'active'
FROM legacy_period_accounts legacy
JOIN users u ON u.id=legacy.user_id
CROSS JOIN appraisal_periods ap
WHERE 1=1
ON DUPLICATE KEY UPDATE
  participation_status=VALUES(participation_status),
  work_status=VALUES(work_status),
  employment_status=VALUES(employment_status);

DROP TEMPORARY TABLE legacy_period_accounts;
