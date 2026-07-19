/**
 * Unit tests for evalKeyboardNav.js
 *
 * Tests cover:
 * - Pure logic functions (calculateNextFocusIndex, calculateNewRating, etc.)
 * - DOM helper functions (using jsdom)
 * - Edge cases (empty questions, boundaries, textarea ignore)
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  // Pure logic
  calculateNextFocusIndex,
  calculateNewRating,
  buildCategoryPayload,
  determineRequiredType,
  isCategoryComplete,
  calculateTotalWeighted,
  countIncomplete,

  // DOM helpers
  getQuestions,
  clearFocusedClass,
  applyFocusToQuestion,
  getRatingGroup,
  getRatingRadios,
  getCheckedRating,
  selectAndTriggerRadio,
  getIndicatorClass,
  pctComplete,
  shouldIgnoreKeyboardEvent,
  ratingDeltaFromKey,

  // Category state computation
  computeCategoryState,
  computeProgressSummary,
} from './evalKeyboardNav.js';

// ── Pure Logic Tests ───────────────────────────────────────────────

describe('calculateNextFocusIndex', () => {
  it('returns -1 when there are zero questions', () => {
    expect(calculateNextFocusIndex(0, -1, 1)).toBe(-1);
    expect(calculateNextFocusIndex(0, -1, -1)).toBe(-1);
  });

  it('returns -1 when questionCount is negative', () => {
    expect(calculateNextFocusIndex(-1, -1, 1)).toBe(-1);
  });

  it('focuses first question (index 0) when pressing ArrowDown with no focus', () => {
    expect(calculateNextFocusIndex(5, -1, 1)).toBe(0);
  });

  it('focuses last question when pressing ArrowUp with no focus', () => {
    expect(calculateNextFocusIndex(5, -1, -1)).toBe(4);
  });

  it('moves down by 1 when pressing ArrowDown', () => {
    expect(calculateNextFocusIndex(10, 3, 1)).toBe(4);
  });

  it('moves up by 1 when pressing ArrowUp', () => {
    expect(calculateNextFocusIndex(10, 3, -1)).toBe(2);
  });

  it('stays at first index when pressing ArrowUp at index 0', () => {
    expect(calculateNextFocusIndex(5, 0, -1)).toBe(0);
  });

  it('stays at last index when pressing ArrowDown at last index', () => {
    expect(calculateNextFocusIndex(5, 4, 1)).toBe(4);
  });

  it('handles single question', () => {
    expect(calculateNextFocusIndex(1, -1, 1)).toBe(0);
    expect(calculateNextFocusIndex(1, 0, 1)).toBe(0);
    expect(calculateNextFocusIndex(1, 0, -1)).toBe(0);
  });

  it('clamps negative direction from no focus to last index', () => {
    expect(calculateNextFocusIndex(3, -1, -1)).toBe(2);
  });
});

describe('calculateNewRating', () => {
  it('increases rating by 1', () => {
    expect(calculateNewRating(3, 1)).toBe(4);
  });

  it('decreases rating by 1', () => {
    expect(calculateNewRating(3, -1)).toBe(2);
  });

  it('clamps to 1 when decreasing from 1', () => {
    expect(calculateNewRating(1, -1)).toBe(1);
  });

  it('clamps to 5 when increasing from 5', () => {
    expect(calculateNewRating(5, 1)).toBe(5);
  });

  it('returns 1 when currentValue is 0 and pressing ArrowRight', () => {
    expect(calculateNewRating(0, 1)).toBe(1);
  });

  it('clamps at minimum of 1 when currentValue is 0 and pressing ArrowLeft', () => {
    expect(calculateNewRating(0, -1)).toBe(1);
  });

  it('handles boundary values correctly', () => {
    expect(calculateNewRating(2, 10)).toBe(5);
    expect(calculateNewRating(4, -10)).toBe(1);
  });

  it('handles undefined currentValue', () => {
    expect(calculateNewRating(undefined, 1)).toBe(1);
    expect(calculateNewRating(null, 1)).toBe(1);
  });
});

describe('determineRequiredType', () => {
  it('returns "none" when avg is 0', () => {
    expect(determineRequiredType(0)).toBe('none');
  });

  it('returns "high" when avg is >= 4.51', () => {
    expect(determineRequiredType(4.51)).toBe('high');
    expect(determineRequiredType(5)).toBe('high');
    expect(determineRequiredType(4.75)).toBe('high');
  });

  it('returns "low" when avg is <= 3', () => {
    expect(determineRequiredType(3)).toBe('low');
    expect(determineRequiredType(1.5)).toBe('low');
    expect(determineRequiredType(2)).toBe('low');
  });

  it('returns "reason" when avg is between 3.01 and 4.50', () => {
    expect(determineRequiredType(3.01)).toBe('reason');
    expect(determineRequiredType(4.5)).toBe('reason');
    expect(determineRequiredType(4)).toBe('reason');
  });
});

describe('isCategoryComplete', () => {
  it('returns false when not all questions are answered', () => {
    expect(isCategoryComplete(3, 5, 4, '', '', '')).toBe(false);
  });

  it('returns false when totalQuestions is 0', () => {
    expect(isCategoryComplete(0, 0, 0, '', '', '')).toBe(false);
  });

  it('returns true when all answered and avg is reason range with reason filled', () => {
    expect(isCategoryComplete(5, 5, 4, '', 'Good work', '')).toBe(true);
  });

  it('returns false when reason is required but not provided', () => {
    expect(isCategoryComplete(5, 5, 4, '', '', '')).toBe(false);
  });

  it('returns true when avg is high range with evidence filled', () => {
    expect(isCategoryComplete(5, 5, 4.75, 'Excellent teaching', '', '')).toBe(true);
  });

  it('returns false when high rating requires evidence but none provided', () => {
    expect(isCategoryComplete(5, 5, 4.75, '', '', '')).toBe(false);
  });

  it('returns true when low rating with both evidence and recommendation', () => {
    expect(isCategoryComplete(5, 5, 2.5, 'Needs improvement', '', 'Take training')).toBe(true);
  });

  it('returns false when low rating missing recommendation', () => {
    expect(isCategoryComplete(5, 5, 2.5, 'Needs improvement', '', '')).toBe(false);
  });

  it('returns false when low rating missing evidence', () => {
    expect(isCategoryComplete(5, 5, 2.5, '', '', 'Take training')).toBe(false);
  });

  it('trims whitespace for evidence check', () => {
    expect(isCategoryComplete(5, 5, 4.75, '  ', '', '')).toBe(false);
    expect(isCategoryComplete(5, 5, 4.75, 'Has content', '', '')).toBe(true);
  });
});

describe('buildCategoryPayload', () => {
  it('builds a valid payload object from state', () => {
    const state = {
      cid: '1',
      answers: { '10': 4, '11': 5 },
      avg: 4.5,
      weight: 30,
      weighted: 1.35,
      totalQuestions: 2,
      evidence: 'Good',
      reason: 'Well done',
      recommendation: '',
    };
    const payload = buildCategoryPayload(state);
    expect(payload.category_id).toBe(1);
    expect(payload.total_rate).toBe(9);
    expect(payload.average_rating).toBe(4.5);
    expect(payload.factor_weight).toBe(30);
    expect(payload.weighted_score).toBe(1.35);
    expect(payload.behavioral_evidence).toBe('Good');
    expect(payload.reason_for_rating).toBe('Well done');
    expect(payload.recommendation).toBe('');
  });

  it('handles empty answers', () => {
    const state = {
      cid: '2',
      answers: {},
      avg: 0,
      weight: 0,
      weighted: 0,
      totalQuestions: 3,
      evidence: '',
      reason: '',
      recommendation: '',
    };
    const payload = buildCategoryPayload(state);
    expect(payload.total_rate).toBe(0);
    expect(payload.average_rating).toBe(0);
  });
});

describe('calculateTotalWeighted', () => {
  it('sums weighted scores', () => {
    expect(calculateTotalWeighted([
      { weighted: 1.2 },
      { weighted: 0.8 },
      { weighted: 0.5 },
    ])).toBe(2.5);
  });

  it('returns 0 for empty array', () => {
    expect(calculateTotalWeighted([])).toBe(0);
  });

  it('handles missing weighted field', () => {
    expect(calculateTotalWeighted([{}, { weighted: 1 }])).toBe(1);
  });
});

describe('countIncomplete', () => {
  it('counts incomplete categories with questions', () => {
    expect(countIncomplete([
      { complete: true, totalQuestions: 5 },
      { complete: false, totalQuestions: 5 },
      { complete: false, totalQuestions: 3 },
      { complete: true, totalQuestions: 2 },
    ])).toBe(2);
  });

  it('excludes categories with totalQuestions of 0', () => {
    expect(countIncomplete([
      { complete: false, totalQuestions: 0 },
      { complete: false, totalQuestions: 5 },
    ])).toBe(1);
  });

  it('returns 0 when all complete', () => {
    expect(countIncomplete([
      { complete: true, totalQuestions: 5 },
    ])).toBe(0);
  });

  it('returns 0 for empty array', () => {
    expect(countIncomplete([])).toBe(0);
  });
});

describe('getIndicatorClass', () => {
  it('returns "completed" when state.complete is true', () => {
    expect(getIndicatorClass({ complete: true, answered: 5 })).toBe('completed');
  });

  it('returns "in-progress" when answered > 0 but not complete', () => {
    expect(getIndicatorClass({ complete: false, answered: 3 })).toBe('in-progress');
  });

  it('returns "not-started" when answered is 0', () => {
    expect(getIndicatorClass({ complete: false, answered: 0 })).toBe('not-started');
  });
});

describe('pctComplete', () => {
  it('calculates percentage correctly', () => {
    expect(pctComplete(5, 10)).toBe(50);
    expect(pctComplete(10, 10)).toBe(100);
    expect(pctComplete(0, 10)).toBe(0);
  });

  it('returns 0 when total is 0', () => {
    expect(pctComplete(0, 0)).toBe(0);
  });

  it('rounds to nearest integer', () => {
    expect(pctComplete(1, 3)).toBe(33);
  });
});

describe('shouldIgnoreKeyboardEvent', () => {
  it('returns true for TEXTAREA element', () => {
    const el = document.createElement('TEXTAREA');
    expect(shouldIgnoreKeyboardEvent(el)).toBe(true);
  });

  it('returns false for non-textarea elements', () => {
    const div = document.createElement('DIV');
    const input = document.createElement('INPUT');
    const label = document.createElement('LABEL');
    expect(shouldIgnoreKeyboardEvent(div)).toBe(false);
    expect(shouldIgnoreKeyboardEvent(input)).toBe(false);
    expect(shouldIgnoreKeyboardEvent(label)).toBe(false);
  });
});

describe('ratingDeltaFromKey', () => {
  it('returns 1 for ArrowRight', () => {
    expect(ratingDeltaFromKey('ArrowRight')).toBe(1);
  });

  it('returns -1 for ArrowLeft', () => {
    expect(ratingDeltaFromKey('ArrowLeft')).toBe(-1);
  });

  it('returns 0 for ArrowUp and ArrowDown', () => {
    expect(ratingDeltaFromKey('ArrowUp')).toBe(0);
    expect(ratingDeltaFromKey('ArrowDown')).toBe(0);
  });

  it('returns 0 for other keys', () => {
    expect(ratingDeltaFromKey('Enter')).toBe(0);
    expect(ratingDeltaFromKey('Escape')).toBe(0);
  });
});

// Make scrollIntoView available in jsdom
beforeEach(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

// ── DOM Helper Tests ───────────────────────────────────────────────

describe('getQuestions', () => {
  it('returns matching question elements', () => {
    document.body.innerHTML = `
      <form>
        <div class="eval-question"></div>
        <div class="eval-question"></div>
        <div class="form-b-question-row"></div>
        <div class="other"></div>
      </form>
    `;
    const form = document.querySelector('form');
    const questions = getQuestions(form);
    expect(questions.length).toBe(3);
  });

  it('returns empty NodeList when form has no questions', () => {
    document.body.innerHTML = `<form><div></div></form>`;
    const form = document.querySelector('form');
    expect(getQuestions(form).length).toBe(0);
  });
});

describe('clearFocusedClass', () => {
  it('removes focused class from all elements', () => {
    document.body.innerHTML = `
      <div class="eval-question focused"></div>
      <div class="eval-question focused"></div>
      <div class="eval-question"></div>
    `;
    const els = document.querySelectorAll('.eval-question');
    clearFocusedClass(els);
    els.forEach(el => expect(el.classList.contains('focused')).toBe(false));
  });
});

describe('applyFocusToQuestion', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('adds focused class to the question', () => {
    const q = document.createElement('div');
    q.classList.add('eval-question');
    document.body.appendChild(q);
    applyFocusToQuestion(q);
    expect(q.classList.contains('focused')).toBe(true);
  });

  it('opens parent details section', () => {
    const details = document.createElement('details');
    details.classList.add('eval-section');
    const q = document.createElement('div');
    q.classList.add('eval-question');
    details.appendChild(q);
    document.body.appendChild(details);
    expect(details.open).toBe(false);
    applyFocusToQuestion(q);
    expect(details.open).toBe(true);
  });

  it('does not error when there is no parent details section', () => {
    const q = document.createElement('div');
    q.classList.add('eval-question');
    document.body.appendChild(q);
    expect(() => applyFocusToQuestion(q)).not.toThrow();
  });
});

describe('getRatingGroup', () => {
  it('returns the rating group within a question', () => {
    document.body.innerHTML = `
      <div class="eval-question">
        <div class="eval-rating-group"></div>
      </div>
    `;
    const q = document.querySelector('.eval-question');
    expect(getRatingGroup(q)).not.toBeNull();
  });

  it('returns null when no rating group exists', () => {
    const q = document.createElement('div');
    expect(getRatingGroup(q)).toBeNull();
  });
});

describe('getRatingRadios', () => {
  it('returns all radio inputs in a group', () => {
    document.body.innerHTML = `
      <div class="eval-rating-group">
        <input type="radio" class="eval-rating-radio" value="1">
        <input type="radio" class="eval-rating-radio" value="2">
        <input type="radio" class="eval-rating-radio" value="3">
      </div>
    `;
    const group = document.querySelector('.eval-rating-group');
    expect(getRatingRadios(group).length).toBe(3);
  });
});

describe('getCheckedRating', () => {
  it('returns the checked radio and its value', () => {
    document.body.innerHTML = `
      <div class="eval-rating-group">
        <input type="radio" class="eval-rating-radio" value="1">
        <input type="radio" class="eval-rating-radio" value="3" checked>
        <input type="radio" class="eval-rating-radio" value="5">
      </div>
    `;
    const group = document.querySelector('.eval-rating-group');
    const { element, value } = getCheckedRating(group);
    expect(element).not.toBeNull();
    expect(value).toBe(3);
  });

  it('returns null and 0 when nothing is checked', () => {
    document.body.innerHTML = `
      <div class="eval-rating-group">
        <input type="radio" class="eval-rating-radio" value="1">
        <input type="radio" class="eval-rating-radio" value="2">
      </div>
    `;
    const group = document.querySelector('.eval-rating-group');
    const { element, value } = getCheckedRating(group);
    expect(element).toBeNull();
    expect(value).toBe(0);
  });
});

describe('selectAndTriggerRadio', () => {
  it('selects the radio with the matching value', () => {
    document.body.innerHTML = `
      <div class="eval-rating-group">
        <input type="radio" class="eval-rating-radio" value="1">
        <input type="radio" class="eval-rating-radio" value="2">
        <input type="radio" class="eval-rating-radio" value="3">
      </div>
    `;
    const group = document.querySelector('.eval-rating-group');
    const radios = getRatingRadios(group);
    const selected = selectAndTriggerRadio(radios, 2);
    expect(selected).not.toBeNull();
    expect(selected.value).toBe('2');
    expect(selected.checked).toBe(true);
    expect(radios[0].checked).toBe(false);
    expect(radios[2].checked).toBe(false);
  });

  it('dispatches a change event on the selected radio', () => {
    document.body.innerHTML = `
      <div class="eval-rating-group">
        <input type="radio" class="eval-rating-radio" value="1">
        <input type="radio" class="eval-rating-radio" value="2">
      </div>
    `;
    const radios = document.querySelectorAll('.eval-rating-radio');
    let eventFired = false;
    radios[1].addEventListener('change', () => { eventFired = true; });
    selectAndTriggerRadio(radios, 2);
    expect(eventFired).toBe(true);
  });

  it('returns null when no radio matches the value', () => {
    document.body.innerHTML = `
      <div class="eval-rating-group">
        <input type="radio" class="eval-rating-radio" value="1">
        <input type="radio" class="eval-rating-radio" value="2">
      </div>
    `;
    const radios = document.querySelectorAll('.eval-rating-radio');
    const result = selectAndTriggerRadio(radios, 99);
    expect(result).toBeNull();
  });
});

// ── computeCategoryState Tests ────────────────────────────────────

describe('computeCategoryState', () => {
  it('computes avg and weighted from answers', () => {
    const result = computeCategoryState({
      cid: '1',
      weight: 30,
      answers: { 10: 4, 11: 5 },
      totalQuestions: 2,
      evidence: '',
      reason: '',
      recommendation: '',
    });
    expect(result.cid).toBe('1');
    expect(result.answered).toBe(2);
    expect(result.totalQuestions).toBe(2);
    expect(result.avg).toBe(4.5);
    expect(result.weighted).toBe(4.5 * 0.3);
    expect(result.complete).toBe(false); // reason required for avg 4.5
  });

  it('returns complete=true for avg 4.5 with reason filled', () => {
    const result = computeCategoryState({
      cid: '1', weight: 30, answers: { 10: 5, 11: 4 }, totalQuestions: 2,
      evidence: '', reason: 'Good performance', recommendation: '',
    });
    expect(result.avg).toBe(4.5);
    expect(result.complete).toBe(true);
  });

  it('returns complete=true for high avg (>=4.51) with evidence', () => {
    const result = computeCategoryState({
      cid: '2', weight: 20, answers: { 20: 5, 21: 5 }, totalQuestions: 2,
      evidence: 'Excellent teaching observed', reason: '', recommendation: '',
    });
    expect(result.avg).toBe(5);
    expect(result.requiredType).toBe('high');
    expect(result.complete).toBe(true);
  });

  it('returns complete=false for high avg missing evidence', () => {
    const result = computeCategoryState({
      cid: '2', weight: 20, answers: { 20: 5, 21: 5 }, totalQuestions: 2,
      evidence: '', reason: '', recommendation: '',
    });
    expect(result.complete).toBe(false);
  });

  it('returns complete=true for low avg (<=3) with evidence + recommendation', () => {
    const result = computeCategoryState({
      cid: '3', weight: 50, answers: { 30: 2, 31: 2 }, totalQuestions: 2,
      evidence: 'Needs improvement', reason: '', recommendation: 'Take training course',
    });
    expect(result.avg).toBe(2);
    expect(result.requiredType).toBe('low');
    expect(result.complete).toBe(true);
  });

  it('returns complete=false for low avg missing recommendation', () => {
    const result = computeCategoryState({
      cid: '3', weight: 50, answers: { 30: 2, 31: 2 }, totalQuestions: 2,
      evidence: 'Needs improvement', reason: '', recommendation: '',
    });
    expect(result.complete).toBe(false);
  });

  it('returns not complete when not all questions answered', () => {
    const result = computeCategoryState({
      cid: '1', weight: 30, answers: { 10: 4 }, totalQuestions: 5,
      evidence: '', reason: 'Okay', recommendation: '',
    });
    expect(result.answered).toBe(1);
    expect(result.totalQuestions).toBe(5);
    expect(result.avg).toBe(0);
    expect(result.complete).toBe(false);
  });

  it('handles empty answers gracefully', () => {
    const result = computeCategoryState({
      cid: '1', weight: 30, answers: {}, totalQuestions: 3,
    });
    expect(result.answered).toBe(0);
    expect(result.avg).toBe(0);
    expect(result.weighted).toBe(0);
    expect(result.complete).toBe(false);
  });

  it('returns requiredType correctly for boundary averages', () => {
    // 4.51 is high
    expect(computeCategoryState({ cid: '1', weight: 10, answers: { 1: 5, 2: 5, 3: 4 }, totalQuestions: 3 }).requiredType).toBe('high');
    // 3.0 is low (≤ 3)
    expect(computeCategoryState({ cid: '2', weight: 10, answers: { 1: 3, 2: 3, 3: 3 }, totalQuestions: 3 }).requiredType).toBe('low');
    // 4.0 is reason
    expect(computeCategoryState({ cid: '3', weight: 10, answers: { 1: 4, 2: 4, 3: 4 }, totalQuestions: 3 }).requiredType).toBe('reason');
  });

  it('trims whitespace from evidence fields', () => {
    const result = computeCategoryState({
      cid: '1', weight: 20, answers: { 10: 5, 11: 5 }, totalQuestions: 2,
      evidence: '  Has evidence  ', reason: '', recommendation: '',
    });
    expect(result.evidence).toBe('Has evidence');
    expect(result.complete).toBe(true);
  });

  it('handles zero-weight categories', () => {
    const result = computeCategoryState({
      cid: '1', weight: 0, answers: { 10: 4 }, totalQuestions: 1,
      evidence: '', reason: 'Fine', recommendation: '',
    });
    expect(result.weight).toBe(0);
    expect(result.weighted).toBe(0);
  });

  it('uses defaults for missing optional fields', () => {
    const result = computeCategoryState({ cid: '1' });
    expect(result.answers).toEqual({});
    expect(result.weight).toBe(0);
    expect(result.totalQuestions).toBe(0);
    expect(result.avg).toBe(0);
    expect(result.complete).toBe(false);
  });

  it('handles single question category', () => {
    const result = computeCategoryState({
      cid: '1', weight: 100, answers: { 1: 3 }, totalQuestions: 1,
      evidence: '', reason: 'Average', recommendation: '',
    });
    expect(result.avg).toBe(3);
    expect(result.requiredType).toBe('low');
    expect(result.weighted).toBe(3 * 1);
  });
});

// ── computeProgressSummary Tests ───────────────────────────────────

describe('computeProgressSummary', () => {
  it('aggregates multiple category states', () => {
    const states = [
      { answered: 3, totalQuestions: 3, weighted: 1.5, complete: true, cid: '1' },
      { answered: 2, totalQuestions: 3, weighted: 0.8, complete: false, cid: '2' },
    ];
    const result = computeProgressSummary(states);
    expect(result.totalWeighted).toBe(2.3);
    expect(result.totalAnsweredAll).toBe(5);
    expect(result.totalQuestionsAll).toBe(6);
    expect(result.remaining).toBe(1);
    expect(result.pctComplete).toBe(83);
    expect(result.allComplete).toBe(false);
    expect(result.anyAnswered).toBe(true);
    expect(result.pending).toBe(1);
  });

  it('returns allComplete=true when all categories complete', () => {
    const states = [
      { answered: 3, totalQuestions: 3, weighted: 1.5, complete: true },
      { answered: 2, totalQuestions: 2, weighted: 1.0, complete: true },
    ];
    const result = computeProgressSummary(states);
    expect(result.allComplete).toBe(true);
    expect(result.pending).toBe(0);
  });

  it('returns anyAnswered=false when nothing answered', () => {
    const states = [
      { answered: 0, totalQuestions: 3, weighted: 0, complete: false },
      { answered: 0, totalQuestions: 2, weighted: 0, complete: false },
    ];
    const result = computeProgressSummary(states);
    expect(result.anyAnswered).toBe(false);
    expect(result.pctComplete).toBe(0);
    expect(result.allComplete).toBe(false);
  });

  it('returns zeros for empty array', () => {
    const result = computeProgressSummary([]);
    expect(result.totalWeighted).toBe(0);
    expect(result.totalQuestionsAll).toBe(0);
    expect(result.pending).toBe(0);
    expect(result.pctComplete).toBe(0);
    expect(result.allComplete).toBe(false);
    expect(result.anyAnswered).toBe(false);
  });

  it('handles non-array input gracefully', () => {
    const result = computeProgressSummary(null);
    expect(result.totalWeighted).toBe(0);
    expect(result.pending).toBe(0);

    const result2 = computeProgressSummary(undefined);
    expect(result2.totalWeighted).toBe(0);
  });

  it('excludes categories with zero totalQuestions from allComplete', () => {
    const states = [
      { answered: 0, totalQuestions: 0, weighted: 0, complete: false },
      { answered: 3, totalQuestions: 3, weighted: 1.5, complete: true },
    ];
    const result = computeProgressSummary(states);
    // The zero-TQ category should not block allComplete
    expect(result.allComplete).toBe(true);
    expect(result.pending).toBe(0);
  });

  it('handles partial completion correctly', () => {
    const states = [
      { answered: 5, totalQuestions: 5, weighted: 2.0, complete: true },
      { answered: 3, totalQuestions: 5, weighted: 1.2, complete: false },
      { answered: 0, totalQuestions: 4, weighted: 0, complete: false },
    ];
    const result = computeProgressSummary(states);
    expect(result.totalAnsweredAll).toBe(8);
    expect(result.totalQuestionsAll).toBe(14);
    expect(result.remaining).toBe(6);
    expect(result.pctComplete).toBe(57);
    expect(result.pending).toBe(2);
    expect(result.anyAnswered).toBe(true);
    expect(result.allComplete).toBe(false);
  });

  it('handles all categories with zero questions', () => {
    const states = [
      { answered: 0, totalQuestions: 0, weighted: 0, complete: true },
      { answered: 0, totalQuestions: 0, weighted: 0, complete: true },
    ];
    const result = computeProgressSummary(states);
    expect(result.totalQuestionsAll).toBe(0);
    expect(result.pctComplete).toBe(0);
    expect(result.allComplete).toBe(false);
    expect(result.pending).toBe(0);
  });

  it('handles missing fields with defaults', () => {
    const states = [
      { answered: 3 }, // missing totalQuestions, weighted, complete
    ];
    const result = computeProgressSummary(states);
    expect(result.totalAnsweredAll).toBe(3);
    expect(result.totalQuestionsAll).toBe(0);
    expect(result.totalWeighted).toBe(0);
  });

  it('computes pctComplete correctly with even split', () => {
    const states = [
      { answered: 5, totalQuestions: 10, weighted: 1.0, complete: false },
      { answered: 5, totalQuestions: 10, weighted: 1.0, complete: false },
    ];
    const result = computeProgressSummary(states);
    expect(result.pctComplete).toBe(50);
    expect(result.remaining).toBe(10);
  });

  it('computes progress properly with single complete category', () => {
    const states = [
      { answered: 5, totalQuestions: 5, weighted: 2.5, complete: true },
    ];
    const result = computeProgressSummary(states);
    expect(result.totalWeighted).toBe(2.5);
    expect(result.allComplete).toBe(true);
    expect(result.anyAnswered).toBe(true);
    expect(result.pctComplete).toBe(100);
    expect(result.pending).toBe(0);
  });

  it('handles very low percentages rounding edge case', () => {
    const states = [
      { answered: 1, totalQuestions: 100, weighted: 0.1, complete: false },
    ];
    const result = computeProgressSummary(states);
    expect(result.pctComplete).toBe(1);
  });
});

// ── Integration-style tests ────────────────────────────────────────

describe('keyboard navigation integration (focus + rating)', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <form id="testForm">
        <details class="eval-section" data-cid="1">
          <summary>Category 1</summary>
          <div class="eval-section-body">
            <div class="eval-question" data-qidx="0">
              <div class="eval-rating-group" data-qid="10">
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_1_10" value="1"><span>1</span></label>
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_1_10" value="2"><span>2</span></label>
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_1_10" value="3"><span>3</span></label>
              </div>
            </div>
            <div class="eval-question" data-qidx="1">
              <div class="eval-rating-group" data-qid="11">
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_1_11" value="1"><span>1</span></label>
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_1_11" value="2"><span>2</span></label>
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_1_11" value="3"><span>3</span></label>
              </div>
            </div>
          </div>
        </details>
        <details class="eval-section" data-cid="2">
          <summary>Category 2</summary>
          <div class="eval-section-body">
            <div class="eval-question" data-qidx="2">
              <div class="eval-rating-group" data-qid="20">
                <label class="eval-rating-btn"><input type="radio" class="eval-rating-radio" name="q_2_20" value="1"><span>1</span></label>
              </div>
            </div>
          </div>
        </details>
      </form>
    `;
  });

  it('navigates between questions across categories', () => {
    const form = document.getElementById('testForm');
    const questions = form.querySelectorAll('.eval-question');
    expect(questions.length).toBe(3);

    // Navigate to first question
    let idx = calculateNextFocusIndex(questions.length, -1, 1);
    expect(idx).toBe(0);
    clearFocusedClass(questions);
    applyFocusToQuestion(questions[idx]);
    expect(questions[0].classList.contains('focused')).toBe(true);
    expect(document.querySelector('details').open).toBe(true);

    // Navigate to next question
    idx = calculateNextFocusIndex(questions.length, idx, 1);
    expect(idx).toBe(1);
    clearFocusedClass(questions);
    applyFocusToQuestion(questions[idx]);
    expect(questions[1].classList.contains('focused')).toBe(true);
  });

  it('selects a rating via keyboard simulation', () => {
    const form = document.getElementById('testForm');
    const questions = form.querySelectorAll('.eval-question');

    // Focus first question
    let idx = calculateNextFocusIndex(questions.length, -1, 1);
    clearFocusedClass(questions);
    applyFocusToQuestion(questions[idx]);

    // Simulate rating increase (ArrowRight)
    const group = getRatingGroup(questions[idx]);
    const radios = getRatingRadios(group);
    const { value: currentVal } = getCheckedRating(group);
    const newVal = calculateNewRating(currentVal, 1);
    selectAndTriggerRadio(radios, newVal);

    const { value: updatedVal } = getCheckedRating(group);
    expect(updatedVal).toBe(1);
  });

  it('clamps focus index at boundaries', () => {
    const questions = document.querySelectorAll('.eval-question');
    const count = questions.length;

    // At last question, pressing ArrowDown stays at last
    let idx = calculateNextFocusIndex(count, count - 1, 1);
    expect(idx).toBe(count - 1);

    // At first question, pressing ArrowUp stays at first
    idx = calculateNextFocusIndex(count, 0, -1);
    expect(idx).toBe(0);
  });

  it('ignores keyboard events on TEXTAREA', () => {
    const ta = document.createElement('TEXTAREA');
    expect(shouldIgnoreKeyboardEvent(ta)).toBe(true);
  });

  it('does not ignore keyboard events on rating inputs or labels', () => {
    const label = document.createElement('LABEL');
    const radio = document.createElement('INPUT');
    radio.type = 'radio';
    expect(shouldIgnoreKeyboardEvent(label)).toBe(false);
    expect(shouldIgnoreKeyboardEvent(radio)).toBe(false);
  });
});
