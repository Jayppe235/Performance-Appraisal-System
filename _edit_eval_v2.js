const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'includes', 'evaluation_cards.php');
let content = fs.readFileSync(filePath, 'utf8');
let changes = 0;

// ===== CHANGE 1: Remove per-question evidence in dipascaf_submit_form_a_evaluation() =====
const patternA1 = `                // Per-question evidence: required for rating 5 (high) or 1 (low), optional otherwise
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                if (in_array(\$rate, [5, 1], true) && \$ev === '') {
                    throw new RuntimeException('Behavioral evidence is required for ratings of 5 or 1. Question under ' . \$category['title'] . ' requires justification.');
                }
                \$cleanEvidence[\$qKey] = \$ev;`;

const replacementA1 = `                \$cleanEvidence[\$qKey] = '';`;

if (content.includes(patternA1)) {
    content = content.replace(patternA1, replacementA1);
    changes++;
    console.log('CHANGE 1: Removed per-question evidence from submit_form_a_evaluation()');
} else {
    console.log('CHANGE 1: Pattern NOT FOUND in submit_form_a_evaluation()');
}

// ===== CHANGE 2: Remove per-question evidence in dipascaf_submit_form_b_evaluation() =====
const patternA2 = `                // Per-question evidence: required for rating 5 (high) or 1 (low), optional otherwise
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                if (in_array(\$rate, [5, 1], true) && \$ev === '') {
                    throw new RuntimeException('Behavioral evidence is required for ratings of 5 or 1. Question under ' . \$category['title'] . ' requires justification.');
                }
                \$cleanEvidence[\$qKey] = \$ev;`;

if (content.includes(patternA2)) {
    content = content.replace(patternA2, replacementA1);
    changes++;
    console.log('CHANGE 2: Removed per-question evidence from submit_form_b_evaluation()');
} else {
    console.log('CHANGE 2: Pattern NOT FOUND in submit_form_b_evaluation()');
}

// ===== CHANGE 3: Make validate_category_explanation() optional for normal ratings =====
const patternA3 = `
    if (\$averageRating > 3.00 && \$averageRating < 4.51 && \$reasonForRating === '') {
        throw new RuntimeException('Reason for Rating is required for acceptable ratings in ' . \$categoryTitle . '.');
    }`;

if (content.includes(patternA3)) {
    content = content.replace(patternA3, '');
    changes++;
    console.log('CHANGE 3: Removed reason_for_rating requirement for normal ratings in validate_category_explanation()');
} else {
    console.log('CHANGE 3: Pattern NOT FOUND in validate_category_explanation()');
}

// ===== CHANGE 4: Update isFormBCategoryReady() to be conditional on score =====
const patternB1 = `            function isFormBCategoryReady(category) {
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                return Boolean((state.behavioral_evidence || '').trim());
            }`;

const replacementB1 = `            function isFormBCategoryReady(category) {
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                // High rating: behavioral evidence required
                if (stats.average >= 4.51) {
                    return Boolean((state.behavioral_evidence || '').trim());
                }
                // Low rating: behavioral evidence + recommendation required
                if (stats.average <= 3) {
                    return Boolean((state.behavioral_evidence || '').trim()) && Boolean((state.recommendation || '').trim());
                }
                // Normal rating (3.01-4.50): evidence optional
                return true;
            }`;

if (content.includes(patternB1)) {
    content = content.replace(patternB1, replacementB1);
    changes++;
    console.log('CHANGE 4: Updated isFormBCategoryReady() conditional');
} else {
    console.log('CHANGE 4: Pattern NOT FOUND for isFormBCategoryReady()');
}

// ===== CHANGE 5: Update isFormACategoryReady() to be conditional on score =====
const patternAReady = `            function isFormACategoryReady(cat) {
                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                return Boolean((state.behavioral_evidence || '').trim());
            }`;

const replacementAReady = `            function isFormACategoryReady(cat) {
                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                // High rating: behavioral evidence required
                if (stats.average >= 4.51) {
                    return Boolean((state.behavioral_evidence || '').trim());
                }
                // Low rating: behavioral evidence + recommendation required
                if (stats.average <= 3) {
                    return Boolean((state.behavioral_evidence || '').trim()) && Boolean((state.recommendation || '').trim());
                }
                // Normal rating (3.01-4.50): evidence optional
                return true;
            }`;

if (content.includes(patternAReady)) {
    content = content.replace(patternAReady, replacementAReady);
    changes++;
    console.log('CHANGE 5: Updated isFormACategoryReady() conditional');
} else {
    console.log('CHANGE 5: Pattern NOT FOUND for isFormACategoryReady()');
}

// ===== CHANGE 6: Update saveFormBCategory() with better messages =====
const patternSaveB = `            function saveFormBCategory(category) {
                if (!isFormBCategoryReady(category)) {
                    alert('Complete Behavioral Evidences before saving this category.');
                    return false;
                }`;

if (content.includes(patternSaveB)) {
    content = content.replace(patternSaveB, `            function saveFormBCategory(category) {
                if (!isFormBCategoryReady(category)) {
                    const stats = formBStats(category);
                    if (stats.average >= 4.51) {
                        alert('Behavioral Evidence is required for this high rating before saving.');
                    } else if (stats.average <= 3) {
                        alert('Behavioral Evidence and Recommendation are required for this low rating before saving.');
                    } else {
                        alert('Complete all ratings before saving this category.');
                    }
                    return false;
                }`);
    changes++;
    console.log('CHANGE 6: Updated saveFormBCategory() messages');
} else {
    console.log('CHANGE 6: Pattern NOT FOUND for saveFormBCategory()');
}

// ===== CHANGE 7: Update saveFormACategory() with better messages =====
const patternSaveA = `            function saveFormACategory(cat) {
                if (!isFormACategoryReady(cat)) {
                    alert('Complete Behavioral Evidence before saving this category.');
                    return false;
                }`;

if (content.includes(patternSaveA)) {
    content = content.replace(patternSaveA, `            function saveFormACategory(cat) {
                if (!isFormACategoryReady(cat)) {
                    const stats = formAStats(cat);
                    if (stats.average >= 4.51) {
                        alert('Behavioral Evidence is required for this high rating before saving.');
                    } else if (stats.average <= 3) {
                        alert('Behavioral Evidence and Recommendation are required for this low rating before saving.');
                    } else {
                        alert('Complete all ratings before saving this category.');
                    }
                    return false;
                }`);
    changes++;
    console.log('CHANGE 7: Updated saveFormACategory() messages');
} else {
    console.log('CHANGE 7: Pattern NOT FOUND for saveFormACategory()');
}

// ===== CHANGE 8: Update renderFormBPanel() to show conditional evidence at bottom after score =====
// This is the big one - restructure the panel to show computation grid first, then conditional evidence
const patternBPanelStart = `            function renderFormBPanel() {
                if (!formBCategoryPanel) return;
                const category = formBCategories.find((item) => String(item.id) === activeFormBCategoryId) || formBCategories[0];
                if (!category) {
                    formBCategoryPanel.innerHTML = '<div class="form-b-category-empty">No PMAS Form B categories are available.</div>';
                    return;
                }
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                const requirement = stats.average >= 4.51
                    ? 'Behavioral Evidences are required for this high rating.'
                    : stats.average <= 3
                        ? 'Behavioral Evidences are required for this low rating.'
                        : 'Behavioral Evidences are required for this rating.';
                formBCategoryPanel.innerHTML = \`
                    <div class="form-b-panel-head">
                        <div><h3>\${category.title}</h3><p>\${Number(category.factor_weight).toFixed(0)}% factor weight</p></div>
                        <div class="form-b-score-chip"><strong>\${stats.average ? stats.average.toFixed(2) : '0.00'}</strong><span>Average</span></div>
                    </div>
                    <div class="form-b-question-stack">
                        \${category.questions.map((question, index) => \`
                            <label class="form-b-question-row">
                                <span>\${index + 1}. \${question.text}</span>
                                <select data-form-b-answer="\${question.id}" \${currentUsesFormB && false ? 'disabled' : ''}>
                                    <option value="">Rate</option>
                                    <option value="5">5 - Highly Evident</option>
                                    <option value="4">4 - Evident</option>
                                    <option value="3">3 - Moderately Evident</option>
                                    <option value="2">2 - Slightly Evident</option>
                                    <option value="1">1 - Not Evident</option>
                                </select>
                            </label>
                        \`).join('')}
                    </div>
                    <div class="form-b-computation-grid">
                        <article><span>Total Rate</span><strong>\${stats.totalRate.toFixed(2)}</strong></article>
                        <article><span>No. of Questions</span><strong>\${stats.questionCount}</strong></article>
                        <article><span>Average Rating</span><strong>\${stats.average.toFixed(2)}</strong></article>
                        <article><span>Weighted Score</span><strong>\${stats.weighted.toFixed(4)}</strong></article>
                    </div>
                    <div class="form-b-required-box">
                        <strong>\${requirement}</strong>
                        <label>Behavioral Evidences
                            <textarea data-form-b-field="behavioral_evidence" placeholder="Give specific examples, observed behavior, accomplishments, teaching practices, or incidents."></textarea>
                        </label>
                        <button type="button" class="ghost-button form-b-ai" data-ai-target="behavioral_evidence">AI Suggestion</button>
                        <button type="button" class="ghost-button form-b-ai" data-ai-target="reason_for_rating">AI Reason</button>
                        <button type="button" class="ghost-button form-b-ai" data-ai-target="recommendation">AI Recommendation</button>
                    </div>
                    <div class="form-b-actions">
                        <button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${isFormBCategoryReady(category) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;`;

const replacementBPanel = `            function renderFormBPanel() {
                if (!formBCategoryPanel) return;
                const category = formBCategories.find((item) => String(item.id) === activeFormBCategoryId) || formBCategories[0];
                if (!category) {
                    formBCategoryPanel.innerHTML = '<div class="form-b-category-empty">No PMAS Form B categories are available.</div>';
                    return;
                }
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                const allAnswered = stats.answered === stats.questionCount && stats.questionCount > 0;
                let evidenceHtml = '';
                let saveDisabled = !isFormBCategoryReady(category);

                if (allAnswered && stats.average > 0) {
                    let requirementText = '';
                    let evidenceRequired = false;
                    let recommendationRequired = false;
                    let showEvidence = false;
                    let showRecommendation = false;

                    if (stats.average >= 4.51) {
                        requirementText = 'High rating. Behavioral Evidence is required.';
                        evidenceRequired = true;
                        showEvidence = true;
                    } else if (stats.average <= 3) {
                        requirementText = 'Low rating. Behavioral Evidence and Recommendation are required.';
                        evidenceRequired = true;
                        recommendationRequired = true;
                        showEvidence = true;
                        showRecommendation = true;
                    } else {
                        requirementText = 'Satisfactory rating. Evidence is optional.';
                        showEvidence = false;
                        showRecommendation = false;
                    }

                    evidenceHtml = \`
                    <div class="form-b-computation-grid">
                        <article><span>Total Rate</span><strong>\${stats.totalRate.toFixed(2)}</strong></article>
                        <article><span>No. of Questions</span><strong>\${stats.questionCount}</strong></article>
                        <article><span>Average Rating</span><strong>\${stats.average.toFixed(2)}</strong></article>
                        <article><span>Weighted Score</span><strong>\${stats.weighted.toFixed(4)}</strong></article>
                    </div>
                    <div class="form-b-required-box">
                        <strong>\${requirementText}</strong>
                        \${showEvidence ? \`<label>Behavioral Evidence\${evidenceRequired ? ' <span class="required-mark">*Required</span>' : ' <span class="optional-mark">(Optional)</span>'}</label>
                            <textarea data-form-b-field="behavioral_evidence" placeholder="Give specific examples, observed behavior, accomplishments, teaching practices, or incidents.">\${escHtml(state.behavioral_evidence || '')}</textarea>
                            <button type="button" class="ghost-button form-b-ai" data-ai-target="behavioral_evidence">AI Suggestion</button>\` : ''}
                        \${showRecommendation ? \`<label>Recommendation <span class="required-mark">*Required</span></label>
                            <textarea data-form-b-field="recommendation" placeholder="Suggest specific actions for improvement.">\${escHtml(state.recommendation || '')}</textarea>
                            <button type="button" class="ghost-button form-b-ai" data-ai-target="recommendation">AI Recommendation</button>\` : ''}
                        \${!showEvidence && !showRecommendation ? \`<label>Additional Comments (Optional)</label>
                            <textarea data-form-b-field="reason_for_rating" placeholder="Any additional comments or observations.">\${escHtml(state.reason_for_rating || '')}</textarea>\` : ''}
                    </div>\`;
                }

                formBCategoryPanel.innerHTML = \`
                    <div class="form-b-panel-head">
                        <div><h3>\${category.title}</h3><p>\${Number(category.factor_weight).toFixed(0)}% factor weight</p></div>
                        <div class="form-b-score-chip"><strong>\${stats.average ? stats.average.toFixed(2) : '0.00'}</strong><span>Average</span></div>
                    </div>
                    <div class="form-b-question-stack">
                        \${category.questions.map((question, index) => \`
                            <label class="form-b-question-row">
                                <span>\${index + 1}. \${question.text}</span>
                                <select data-form-b-answer="\${question.id}" \${currentUsesFormB && false ? 'disabled' : ''}>
                                    <option value="">Rate</option>
                                    <option value="5">5 - Highly Evident</option>
                                    <option value="4">4 - Evident</option>
                                    <option value="3">3 - Moderately Evident</option>
                                    <option value="2">2 - Slightly Evident</option>
                                    <option value="1">1 - Not Evident</option>
                                </select>
                            </label>
                        \`).join('')}
                    </div>
                    \${evidenceHtml}
                    <div class="form-b-actions">
                        <button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${saveDisabled ? 'disabled' : ''}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;`;

if (content.includes(patternBPanelStart)) {
    content = content.replace(patternBPanelStart, replacementBPanel);
    changes++;
    console.log('CHANGE 8: Updated renderFormBPanel() with conditional evidence at bottom');
} else {
    console.log('CHANGE 8: Pattern NOT FOUND for renderFormBPanel()');
}

// ===== CHANGE 9: Update renderFormAPanel() to show conditional evidence after computation =====
const patternAPanelStart = `            function renderFormAPanel() {
                if (!formACategoryPanel) return;
                const cat = formACategories.find((c) => String(c.id) === activeFormACategoryId);
                if (!cat) {
                    formACategoryPanel.innerHTML = '<div class="form-a-panel-empty">Select a category to begin rating</div>';
                    return;
                }

                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                const answers = state.answers || {};

                let html = \`<div class="form-a-panel-header">
                    <h4>\${escHtml(cat.title)}</h4>
                    <span class="panel-weight">Factor Weight: \${Number(cat.factor_weight).toFixed(0)}% · Average: \${stats.average > 0 ? stats.average.toFixed(2) : '—'}</span>
                </div>\`;

                html += '<div class="form-a-questions">';
                cat.questions.forEach((q) => {
                    const val = answers[String(q.id)] || '';
                    html += \`<div class="form-a-question-row">
                        <span class="form-a-question-text">\${escHtml(q.question_text || '')}</span>
                        <select class="form-a-scale-select" data-form-a-answer="\${q.id}">
                            <option value="">—</option>
                            <option value="5" \${val === '5' ? 'selected' : ''}>5 – Highly Evident</option>
                            <option value="4" \${val === '4' ? 'selected' : ''}>4 – Evident</option>
                            <option value="3" \${val === '3' ? 'selected' : ''}>3 – Moderately Evident</option>
                            <option value="2" \${val === '2' ? 'selected' : ''}>2 – Slightly Evident</option>
                            <option value="1" \${val === '1' ? 'selected' : ''}>1 – Not Evident</option>
                        </select>
                    </div>\`;
                });
                html += '</div>';

                // Computation grid
                html += \`<div class="form-a-computation">
                    <div class="form-a-comp-item"><span class="comp-label">Total Rate</span><span class="comp-value">\${stats.totalRate}</span></div>
                    <div class="form-a-comp-item"><span class="comp-label">Questions</span><span class="comp-value">\${stats.answered}/\${stats.questionCount}</span></div>
                    <div class="form-a-comp-item"><span class="comp-label">Average Rating</span><span class="comp-value">\${stats.average > 0 ? stats.average.toFixed(2) : '—'}</span></div>
                    <div class="form-a-comp-item"><span class="comp-label">Weighted Score</span><span class="comp-value">\${stats.weighted > 0 ? stats.weighted.toFixed(4) : '—'}</span></div>
                </div>\`;

                if (stats.average > 0) {
                    html += \`<div class="form-a-explanation">
                        <label>Behavioral Evidence <span style="color:#dc2626">*Required</span></label>
                        <textarea data-form-a-field="behavioral_evidence" placeholder="Describe specific observed behaviors, achievements, performance gaps, or incidents that support this rating.">\${escHtml(state.behavioral_evidence || '')}</textarea>
                        <button type="button" class="form-a-ai-btn" data-form-a-ai="behavioral_evidence">AI Suggestion</button>
                        <button type="button" class="dipascaf-evaluate-btn form-a-save-category" \${isFormACategoryReady(cat) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>\`;
                }

                formACategoryPanel.innerHTML = html;`;

const replacementAPanel = `            function renderFormAPanel() {
                if (!formACategoryPanel) return;
                const cat = formACategories.find((c) => String(c.id) === activeFormACategoryId);
                if (!cat) {
                    formACategoryPanel.innerHTML = '<div class="form-a-panel-empty">Select a category to begin rating</div>';
                    return;
                }

                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                const answers = state.answers || {};
                const allAnswered = stats.answered === stats.questionCount && stats.questionCount > 0;

                let html = \`<div class="form-a-panel-header">
                    <h4>\${escHtml(cat.title)}</h4>
                    <span class="panel-weight">Factor Weight: \${Number(cat.factor_weight).toFixed(0)}% · Average: \${stats.average > 0 ? stats.average.toFixed(2) : '—'}</span>
                </div>\`;

                html += '<div class="form-a-questions">';
                cat.questions.forEach((q) => {
                    const val = answers[String(q.id)] || '';
                    html += \`<div class="form-a-question-row">
                        <span class="form-a-question-text">\${escHtml(q.question_text || '')}</span>
                        <select class="form-a-scale-select" data-form-a-answer="\${q.id}">
                            <option value="">—</option>
                            <option value="5" \${val === '5' ? 'selected' : ''}>5 – Highly Evident</option>
                            <option value="4" \${val === '4' ? 'selected' : ''}>4 – Evident</option>
                            <option value="3" \${val === '3' ? 'selected' : ''}>3 – Moderately Evident</option>
                            <option value="2" \${val === '2' ? 'selected' : ''}>2 – Slightly Evident</option>
                            <option value="1" \${val === '1' ? 'selected' : ''}>1 – Not Evident</option>
                        </select>
                    </div>\`;
                });
                html += '</div>';

                // Computation grid (category result with score)
                html += \`<div class="form-a-computation">
                    <div class="form-a-comp-item"><span class="comp-label">Total Rate</span><span class="comp-value">\${stats.totalRate}</span></div>
                    <div class="form-a-comp-item"><span class="comp-label">Questions</span><span class="comp-value">\${stats.answered}/\${stats.questionCount}</span></div>
                    <div class="form-a-comp-item"><span class="comp-label">Average Rating</span><span class="comp-value">\${stats.average > 0 ? stats.average.toFixed(2) : '—'}</span></div>
                    <div class="form-a-comp-item"><span class="comp-label">Weighted Score</span><span class="comp-value">\${stats.weighted > 0 ? stats.weighted.toFixed(4) : '—'}</span></div>
                </div>\`;

                // Conditional evidence fields based on score
                if (allAnswered && stats.average > 0) {
                    let evidenceRequired = false;
                    let showRecommendation = false;

                    if (stats.average >= 4.51) {
                        evidenceRequired = true;
                        html += \`<div class="form-a-explanation">
                            <div class="explanation-heading"><strong>High Rating - Behavioral Evidence Required</strong></div>
                            <label>Behavioral Evidence <span class="required-mark">*Required</span></label>
                            <textarea data-form-a-field="behavioral_evidence" placeholder="Describe specific observed behaviors, achievements, or performance gaps that support this rating.">\${escHtml(state.behavioral_evidence || '')}</textarea>
                            <button type="button" class="form-a-ai-btn" data-form-a-ai="behavioral_evidence">AI Suggestion</button>
                        </div>\`;
                    } else if (stats.average <= 3) {
                        evidenceRequired = true;
                        showRecommendation = true;
                        html += \`<div class="form-a-explanation">
                            <div class="explanation-heading"><strong>Low Rating - Behavioral Evidence and Recommendation Required</strong></div>
                            <label>Behavioral Evidence <span class="required-mark">*Required</span></label>
                            <textarea data-form-a-field="behavioral_evidence" placeholder="Describe specific observed behaviors or performance gaps that support this rating.">\${escHtml(state.behavioral_evidence || '')}</textarea>
                            <button type="button" class="form-a-ai-btn" data-form-a-ai="behavioral_evidence">AI Suggestion</button>
                            <label>Recommendation <span class="required-mark">*Required</span></label>
                            <textarea data-form-a-field="recommendation" placeholder="Suggest specific actions or interventions for improvement.">\${escHtml(state.recommendation || '')}</textarea>
                            <button type="button" class="form-a-ai-btn" data-form-a-ai="recommendation">AI Recommendation</button>
                        </div>\`;
                    } else {
                        html += \`<div class="form-a-explanation">
                            <div class="explanation-heading"><strong>Satisfactory Rating - Evidence Optional</strong></div>
                            <label>Additional Comments (Optional)</label>
                            <textarea data-form-a-field="reason_for_rating" placeholder="Any additional comments or observations about this category.">\${escHtml(state.reason_for_rating || '')}</textarea>
                        </div>\`;
                    }
                    html += \`<button type="button" class="dipascaf-evaluate-btn form-a-save-category" \${isFormACategoryReady(cat) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>\`;
                } else if (stats.answered > 0) {
                    html += \`<div class="form-a-progress-hint">Answer all questions to see category result and evidence fields.</div>\`;
                }

                formACategoryPanel.innerHTML = html;`;

if (content.includes(patternAPanelStart)) {
    content = content.replace(patternAPanelStart, replacementAPanel);
    changes++;
    console.log('CHANGE 9: Updated renderFormAPanel() with conditional evidence');
} else {
    console.log('CHANGE 9: Pattern NOT FOUND for renderFormAPanel()');
}

// Write changes
fs.writeFileSync(filePath, content, 'utf8');
console.log(`\nTotal changes applied: ${changes}`);

// Verify no syntax issues by looking for broken template literals
const formBPanelCount = (content.match(/formBCategoryPanel\.innerHTML = \`/g) || []).length;
const formAPanelCount = (content.match(/formACategoryPanel\.innerHTML = \`/g) || []).length;
console.log(`renderFormBPanel innerHTML assignments: ${formBPanelCount}`);
console.log(`renderFormAPanel innerHTML assignments: ${formAPanelCount}`);
