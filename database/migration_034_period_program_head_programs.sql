-- Multi-program Program Head scope, frozen per evaluation period.
CREATE TABLE IF NOT EXISTS evaluation_period_program_heads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_period_id INT NOT NULL,
  user_id INT NOT NULL,
  department_id INT NOT NULL,
  program_id INT NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  is_lead_evaluator TINYINT(1) NOT NULL DEFAULT 0,
  co_head_authorized TINYINT(1) NOT NULL DEFAULT 0,
  co_head_reason VARCHAR(500) NULL,
  authorized_by_user_id INT NULL,
  assignment_source ENUM('master','inferred','admin') NOT NULL DEFAULT 'admin',
  lead_program_slot INT GENERATED ALWAYS AS (
    CASE WHEN is_lead_evaluator = 1 THEN program_id ELSE NULL END
  ) STORED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_period_head_program (evaluation_period_id,user_id,program_id),
  UNIQUE KEY uq_period_program_lead (evaluation_period_id,lead_program_slot),
  KEY idx_period_program_heads_scope (evaluation_period_id,program_id,is_lead_evaluator),
  KEY idx_period_program_heads_user (user_id,evaluation_period_id),
  CONSTRAINT fk_epph_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_epph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_epph_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_epph_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_epph_authorizer FOREIGN KEY (authorized_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Preserve every explicit period snapshot already recorded by migration 033.
INSERT IGNORE INTO evaluation_period_program_heads
  (evaluation_period_id,user_id,department_id,program_id,is_primary,is_lead_evaluator,
   co_head_authorized,authorized_by_user_id,assignment_source)
SELECT epp.evaluation_period_id,epp.user_id,epp.department_id,epp.program_id,1,1,0,
       epp.changed_by_user_id,COALESCE(epp.assignment_source,'master')
FROM evaluation_period_participation epp
WHERE epp.role_snapshot='program_head'
  AND epp.department_id IS NOT NULL
  AND epp.program_id IS NOT NULL;

-- Conflict enforcement now lives in the junction table/service so explicitly
-- authorized co-heads can share a program.
ALTER TABLE evaluation_period_participation DROP INDEX uq_period_program_head_slot;
