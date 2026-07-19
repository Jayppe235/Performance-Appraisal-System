/**
 * evalKeyboardNav.js
 *
 * Pure and DOM-helper functions for keyboard navigation in evaluation forms.
 * Extracted from the inline JS in evaluation_cards.php and teacher.php.
 * Designed to be unit-testable with vitest + jsdom.
 */

// ── Pure / logic functions ──────────────────────────────────────────

/**
 * Calculate which question index to navigate to based on current index and direction.
 *
 * @param {number} questionCount - Total number of questions
 * @param {number} currentIdx - Current focused index (-1 if none)
 * @param {number} direction - +1 for next (ArrowDown), -1 for previous (ArrowUp)
 * @returns {number} The new focused index, clamped to [0, questionCount - 1]
 */
export function calculateNextFocusIndex(questionCount, currentIdx, direction) {
  if (questionCount <= 0) return -1;
  if (currentIdx < 0) {
    return direction === 1 ? 0 : questionCount - 1;
  }
  return Math.max(0, Math.min(questionCount - 1, currentIdx + direction));
}

/**
 * Calculate the new rating value after applying a delta, clamped to [1, 5].
 *
 * @param {number} currentValue - Current rating value (0 if none selected)
 * @param {number} delta - +1 for increase (ArrowRight), -1 for decrease (ArrowLeft)
 * @returns {number} Clamped rating value between 1 and 5
 */
export function calculateNewRating(currentValue, delta) {
  return Math.max(1, Math.min(5, (currentValue || 0) + delta));
}

/**
 * Build a payload category object from a category's state.
 *
 * @param {object} state - Category state from getCategoryState()
 * @returns {object} Payload-safe category object
 */
export function buildCategoryPayload(state) {
  const answersObj = {};
  for (const [key, val] of Object.entries(state.answers || {})) {
    answersObj[String(key)] = Number(val);
  }
  const answerValues = Object.values(answersObj);
  const totalRate = answerValues.reduce((a, b) => a + b, 0);

  return {
    category_id: Number(state.cid),
    answers: answersObj,
    total_rate: Number(totalRate.toFixed(2)),
    question_count: state.totalQuestions,
    average_rating: Number((state.avg || 0).toFixed(2)),
    factor_weight: Number(state.weight || 0),
    weighted_score: Number((state.weighted || 0).toFixed(4)),
    behavioral_evidence: state.evidence || '',
    reason_for_rating: state.reason || '',
    recommendation: state.recommendation || '',
  };
}

/**
 * Determine the required explanation type based on average rating.
 *
 * @param {number} avg - Average rating for the category
 * @returns {string} 'high' (≥4.51), 'low' (≤3), 'reason' (3.01–4.50), or 'none' (avg=0)
 */
export function determineRequiredType(avg) {
  if (avg === 0) return 'none';
  if (avg >= 4.51) return 'high';
  if (avg <= 3) return 'low';
  return 'reason';
}

/**
 * Determine if a category is complete based on answered count, required type, and evidence fields.
 *
 * @param {number} answered - Number of answered questions
 * @param {number} totalQuestions - Total questions in category
 * @param {number} avg - Average rating
 * @param {string} evidence - Behavioral evidence text
 * @param {string} reason - Reason for rating text
 * @param {string} recommendation - Recommendation text
 * @returns {boolean} Whether the category is complete
 */
export function isCategoryComplete(answered, totalQuestions, avg, evidence, reason, recommendation) {
  if (answered !== totalQuestions || totalQuestions <= 0) return false;
  const reqType = determineRequiredType(avg);
  if (reqType === 'high') return evidence.trim().length > 0;
  if (reqType === 'low') return evidence.trim().length > 0 && recommendation.trim().length > 0;
  if (reqType === 'reason') return reason.trim().length > 0;
  return true;
}

/**
 * Calculate the total weighted score from an array of category states.
 *
 * @param {Array<{weighted: number}>} categoryStates
 * @returns {number} Sum of all weighted scores
 */
export function calculateTotalWeighted(categoryStates) {
  return categoryStates.reduce((sum, s) => sum + (s.weighted || 0), 0);
}

/**
 * Count incomplete categories.
 *
 * @param {Array<{complete: boolean, totalQuestions: number}>} categoryStates
 * @returns {number} Number of categories that are not complete and have questions
 */
export function countIncomplete(categoryStates) {
  return categoryStates.filter(s => !s.complete && s.totalQuestions > 0).length;
}

// ── DOM helper functions ───────────────────────────────────────────

/**
 * Collect all question rows from a form.
 *
 * @param {HTMLElement} form - The form element
 * @param {string} selector - CSS selector for question rows
 * @returns {NodeList} The question row elements
 */
export function getQuestions(form, selector = '.eval-question, .form-b-question-row') {
  return form.querySelectorAll(selector);
}

/**
 * Clear the 'focused' class from all elements in a NodeList.
 *
 * @param {NodeList|Array} elements
 */
export function clearFocusedClass(elements) {
  elements.forEach(el => el.classList.remove('focused'));
}

/**
 * Apply focus visual to a question element: add 'focused' class, open parent
 * details section, and scroll into view.
 *
 * @param {HTMLElement} q - The question element to focus
 */
export function applyFocusToQuestion(q) {
  q.classList.add('focused');
  const parentSection = q.closest('.eval-section, .form-b-category-section');
  if (parentSection && parentSection.tagName === 'DETAILS') {
    parentSection.open = true;
  }
  q.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/**
 * Get the rating group container within a question row.
 *
 * @param {HTMLElement} q - The question element
 * @param {string} selector - CSS selector for the rating group
 * @returns {HTMLElement|null}
 */
export function getRatingGroup(q, selector = '.eval-rating-group, .form-b-rating-group') {
  return q.querySelector(selector);
}

/**
 * Get all radio inputs in a rating group.
 *
 * @param {HTMLElement} group - The rating group element
 * @param {string} selector - CSS selector for radio inputs
 * @returns {NodeList}
 */
export function getRatingRadios(group, selector = '.eval-rating-radio, .form-b-rating-radio') {
  return group.querySelectorAll(selector);
}

/**
 * Find the checked radio in a group and return its value.
 *
 * @param {HTMLElement} group - The rating group element
 * @param {string} selector - CSS selector for radio inputs
 * @returns {{element: HTMLElement|null, value: number}}
 */
export function getCheckedRating(group, selector = '.eval-rating-radio:checked, .form-b-rating-radio:checked') {
  const checked = group.querySelector(selector);
  return {
    element: checked,
    value: checked ? parseInt(checked.value, 10) : 0,
  };
}

/**
 * Select the radio with a specific value and dispatch a change event.
 *
 * @param {NodeList} radios - Radio input elements
 * @param {number} value - The value to select
 * @returns {HTMLElement|null} The selected radio element, or null
 */
export function selectAndTriggerRadio(radios, value) {
  let selected = null;
  radios.forEach(r => {
    if (parseInt(r.value, 10) === value) {
      r.checked = true;
      selected = r;
    }
  });
  if (selected) {
    selected.dispatchEvent(new Event('change', { bubbles: true }));
  }
  return selected;
}

/**
 * Determine the status indicator class for a category.
 *
 * @param {object} state - Category state with complete and answered fields
 * @returns {string} CSS class name: 'completed', 'in-progress', or 'not-started'
 */
export function getIndicatorClass(state) {
  if (state.complete) return 'completed';
  if (state.answered > 0) return 'in-progress';
  return 'not-started';
}

/**
 * Calculate completion percentage.
 *
 * @param {number} answered
 * @param {number} total
 * @returns {number} Percentage 0–100
 */
export function pctComplete(answered, total) {
  if (total <= 0) return 0;
  return Math.round((answered / total) * 100);
}

/**
 * Check if a keyboard event target should be ignored (e.g., textarea).
 *
 * @param {EventTarget} target
 * @returns {boolean} True if the event should be ignored
 */
export function shouldIgnoreKeyboardEvent(target) {
  return target.tagName === 'TEXTAREA';
}

/**
 * Get the direction (+1 or -1) for a rating key press.
 *
 * @param {string} key - 'ArrowRight' or 'ArrowLeft'
 * @returns {number} +1 for ArrowRight, -1 for ArrowLeft
 */
export function ratingDeltaFromKey(key) {
  if (key === 'ArrowRight') return 1;
  if (key === 'ArrowLeft') return -1;
  return 0;
}

// ── Category state computation (pure) ──────────────────────────────

/**
 * Compute a category state object from raw inputs (no DOM).
 * This is the pure computation extracted from getCategoryState() in the PHP inline JS.
 *
 * @param {object} inputs
 * @param {string} inputs.cid - Category ID
 * @param {number} inputs.weight - Factor weight (0-100)
 * @param {object} inputs.answers - { qid: rating } of checked radios
 * @param {number} inputs.totalQuestions - Total questions in this category
 * @param {string} inputs.evidence - Behavioral evidence text (trimmed)
 * @param {string} inputs.reason - Reason for rating text (trimmed)
 * @param {string} inputs.recommendation - Recommendation text (trimmed)
 * @returns {{ cid: string, answers: object, avg: number, weighted: number, evidence: string, reason: string, recommendation: string, requiredType: string, answered: number, totalQuestions: number, complete: boolean, weight: number }}
 */
export function computeCategoryState(inputs) {
  const { cid, weight = 0, answers = {}, totalQuestions = 0, evidence = '', reason = '', recommendation = '' } = inputs;

  const answered = Object.keys(answers).length;
  const total = Object.values(answers).reduce((sum, v) => sum + Number(v), 0);
  const avg = answered === totalQuestions && totalQuestions > 0 ? total / totalQuestions : 0;
  const weighted = avg * (Number(weight) / 100);
  const requiredType = determineRequiredType(avg);

  let complete = false;
  if (answered === totalQuestions && totalQuestions > 0) {
    if (avg >= 4.51) complete = evidence.trim().length > 0;
    else if (avg <= 3) complete = evidence.trim().length > 0 && recommendation.trim().length > 0;
    else complete = reason.trim().length > 0;
  }

  return {
    cid: String(cid),
    answers,
    avg,
    weighted,
    evidence: evidence.trim(),
    reason: reason.trim(),
    recommendation: recommendation.trim(),
    requiredType,
    answered,
    totalQuestions: Number(totalQuestions),
    complete,
    weight: Number(weight),
  };
}

/**
 * Compute the overall progress summary from an array of category states.
 * This is the pure aggregation extracted from updateStatuses() in the PHP inline JS.
 *
 * @param {Array<object>} categoryStates - Array of state objects from computeCategoryState()
 * @returns {{
 *   totalWeighted: number,
 *   allComplete: boolean,
 *   anyAnswered: boolean,
 *   totalQuestionsAll: number,
 *   totalAnsweredAll: number,
 *   remaining: number,
 *   pctComplete: number,
 *   pending: number
 * }}
 */
export function computeProgressSummary(categoryStates) {
  if (!Array.isArray(categoryStates) || categoryStates.length === 0) {
    return {
      totalWeighted: 0,
      allComplete: false,
      anyAnswered: false,
      totalQuestionsAll: 0,
      totalAnsweredAll: 0,
      remaining: 0,
      pctComplete: 0,
      pending: 0,
    };
  }

  let totalWeighted = 0;
  let allComplete = true;
  let anyAnswered = false;
  let totalQuestionsAll = 0;
  let totalAnsweredAll = 0;
  let pending = 0;

  categoryStates.forEach(s => {
    totalWeighted += s.weighted || 0;
    if (!s.complete && (s.totalQuestions || 0) > 0) allComplete = false;
    if ((s.answered || 0) > 0) anyAnswered = true;
    totalQuestionsAll += s.totalQuestions || 0;
    totalAnsweredAll += s.answered || 0;
    if (!s.complete && (s.totalQuestions || 0) > 0) pending++;
  });

  const remaining = totalQuestionsAll - totalAnsweredAll;
  const pct = totalQuestionsAll > 0 ? Math.round((totalAnsweredAll / totalQuestionsAll) * 100) : 0;

  return {
    totalWeighted,
    allComplete: totalQuestionsAll > 0 ? allComplete : false,
    anyAnswered,
    totalQuestionsAll,
    totalAnsweredAll,
    remaining: Math.max(0, remaining),
    pctComplete: pct,
    pending,
  };
}
