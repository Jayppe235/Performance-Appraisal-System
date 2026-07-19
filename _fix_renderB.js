const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'includes', 'evaluation_cards.php');
let content = fs.readFileSync(filePath, 'utf8');

// Find the exact renderFormBPanel function
const startMarker = 'function renderFormBPanel() {';
const endMarker = 'function renderFormB() {';

const startIdx = content.indexOf(startMarker);
const endIdx = content.indexOf(endMarker, startIdx);

if (startIdx === -1 || endIdx === -1) {
    console.error('Could not find renderFormBPanel boundaries');
    process.exit(1);
}

const oldFunction = content.substring(startIdx, endIdx);
console.log('Found renderFormBPanel, length:', oldFunction.length);

const newFunction = `function renderFormBPanel() {
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

                if (allAnswered && stats.average > 0) {
                    let requirementText = '';
                    let showEvidence = false;
                    let showRecommendation = false;
                    let evidenceRequired = false;

                    if (stats.average >= 4.51) {
                        requirementText = 'High Rating — Behavioral Evidence Required';
                        showEvidence = true;
                        evidenceRequired = true;
                    } else if (stats.average <= 3) {
                        requirementText = 'Low Rating — Behavioral Evidence and Recommendation Required';
                        showEvidence = true;
                        showRecommendation = true;
                        evidenceRequired = true;
                    } else {
                        requirementText = 'Satisfactory Rating — Evidence Optional';
                    }

                    const esc = (str) => String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                    const behEv = esc(state.behavioral_evidence || '');
                    const rec = esc(state.recommendation || '');
                    const reason = esc(state.reason_for_rating || '');

                    let fieldsHtml = '';
                    if (showEvidence) {
                        fieldsHtml += '<label>Behavioral Evidence' + (evidenceRequired ? ' <span class="required-mark">*Required</span>' : ' <span class="optional-mark">(Optional)</span>') + '</label>';
                        fieldsHtml += '<textarea data-form-b-field="behavioral_evidence" placeholder="Give specific examples, observed behavior, accomplishments, teaching practices, or incidents.">' + behEv + '</textarea>';
                        fieldsHtml += '<button type="button" class="ghost-button form-b-ai" data-ai-target="behavioral_evidence">AI Suggestion</button>';
                    }
                    if (showRecommendation) {
                        fieldsHtml += '<label>Recommendation <span class="required-mark">*Required</span></label>';
                        fieldsHtml += '<textarea data-form-b-field="recommendation" placeholder="Suggest specific actions for improvement.">' + rec + '</textarea>';
                        fieldsHtml += '<button type="button" class="ghost-button form-b-ai" data-ai-target="recommendation">AI Recommendation</button>';
                    }
                    if (!showEvidence && !showRecommendation) {
                        fieldsHtml += '<label>Additional Comments (Optional)</label>';
                        fieldsHtml += '<textarea data-form-b-field="reason_for_rating" placeholder="Any additional comments or observations.">' + reason + '</textarea>';
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
                        \${fieldsHtml}
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
                        <button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${isFormBCategoryReady(category) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;
            `;

content = content.substring(0, startIdx) + newFunction + content.substring(endIdx);

fs.writeFileSync(filePath, content, 'utf8');
console.log('renderFormBPanel replaced successfully');
console.log('New function length:', newFunction.length);
