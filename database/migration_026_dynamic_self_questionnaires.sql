-- Dynamic, revisioned self-evaluation questionnaire definitions.
ALTER TABLE pmas_self_evaluation_templates
  ADD COLUMN IF NOT EXISTS revision INT NOT NULL DEFAULT 1 AFTER template_json;

ALTER TABLE pmas_self_evaluations
  ADD COLUMN IF NOT EXISTS questionnaire_revision INT NULL AFTER form_type,
  ADD COLUMN IF NOT EXISTS questionnaire_snapshot JSON NULL AFTER form_payload_json;
