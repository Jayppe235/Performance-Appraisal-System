-- PMAS category-level explanation monitoring for Form A and Form B.

ALTER TABLE pmas_form_a_category_results
  MODIFY ai_decision ENUM('none','pending_review','accepted','edited','rejected') NOT NULL DEFAULT 'none';

ALTER TABLE pmas_form_a_category_results
  ADD COLUMN required_explanation ENUM('behavioral_evidence','reason_for_rating','behavioral_evidence_recommendation') NOT NULL DEFAULT 'reason_for_rating' AFTER ai_decision;

ALTER TABLE pmas_form_a_category_results
  ADD COLUMN explanation_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER required_explanation;

ALTER TABLE pmas_form_b_category_results
  MODIFY ai_decision ENUM('none','pending_review','accepted','edited','rejected') NOT NULL DEFAULT 'none';

ALTER TABLE pmas_form_b_category_results
  ADD COLUMN required_explanation ENUM('behavioral_evidence','reason_for_rating','behavioral_evidence_recommendation') NOT NULL DEFAULT 'reason_for_rating' AFTER ai_decision;

ALTER TABLE pmas_form_b_category_results
  ADD COLUMN explanation_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER required_explanation;

UPDATE pmas_form_a_category_results
SET required_explanation = CASE
      WHEN average_rating >= 4.51 THEN 'behavioral_evidence'
      WHEN average_rating <= 3.00 THEN 'behavioral_evidence_recommendation'
      ELSE 'reason_for_rating'
    END,
    explanation_complete = CASE
      WHEN average_rating >= 4.51 AND COALESCE(behavioral_evidence, '') <> '' THEN 1
      WHEN average_rating <= 3.00 AND COALESCE(behavioral_evidence, '') <> '' AND COALESCE(recommendation, '') <> '' THEN 1
      WHEN average_rating > 3.00 AND average_rating < 4.51 AND COALESCE(reason_for_rating, '') <> '' THEN 1
      ELSE 0
    END;

UPDATE pmas_form_b_category_results
SET required_explanation = CASE
      WHEN average_rating >= 4.51 THEN 'behavioral_evidence'
      WHEN average_rating <= 3.00 THEN 'behavioral_evidence_recommendation'
      ELSE 'reason_for_rating'
    END,
    explanation_complete = CASE
      WHEN average_rating >= 4.51 AND COALESCE(behavioral_evidence, '') <> '' THEN 1
      WHEN average_rating <= 3.00 AND COALESCE(behavioral_evidence, '') <> '' AND COALESCE(recommendation, '') <> '' THEN 1
      WHEN average_rating > 3.00 AND average_rating < 4.51 AND COALESCE(reason_for_rating, '') <> '' THEN 1
      ELSE 0
    END;
