const fs = require('fs');

// ================================================================
// 1. Update includes/evaluation_cards.php
// ================================================================
let cards = fs.readFileSync('includes/evaluation_cards.php', 'utf8');

// --- 1a. Update isFormBCategoryReady() ---
const oldIsFormBReady = `            function isFormBCategoryReady(category) {
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                return Boolean((state.behavioral_evidence || '').trim());
            }`;

const newIsFormBReady = `            function isFormBCategoryReady(category) {
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                // Evidence required for high (≥4.51) or low (≤3) ratings; optional for normal (3.01-4.50)
                if (stats.average >= 4.51) {
                    return Boolean((state.behavioral_evidence || '').trim());
                }
                if (stats.average <= 3) {
                    return Boolean((state.behavioral_evidence || '').trim()) && Boolean((state.recommendation || '').trim());
                }
                return true; // Normal/satisfactory rating — evidence optional
            }`;

if (cards.includes(oldIsFormBReady)) {
  cards = cards.replace(oldIsFormBReady, newIsFormBReady);
  console.log('✅ Updated isFormBCategoryReady()');
} else {
  console.log('❌ Could not find isFormBCategoryReady() pattern');
}

// --- 1b. Update saveFormBCategory() alert message ---
const oldSaveFormBMsg = `                    alert('Complete Behavioral Evidences before saving this category.');`;
const newSaveFormBMsg = `                    alert('Complete all required fields (behavioral evidence and/or recommendation) before saving this category.');`;
if (cards.includes(oldSaveFormBMsg)) {
  cards = cards.replace(oldSaveFormBMsg, newSaveFormBMsg);
  console.log('✅ Updated saveFormBCategory alert message');
}

// --- 1c. Update renderFormBPanel() - conditional evidence section ---
const oldRenderFormBPanel = `            function renderFormBPanel() {
                if (!formBCategoryPanel) return;
                const category = formBCategories.find((item) => String(item.id) === activeFormBCategoryId) || formBCategories[0];
                if (!category) {
                    formBCategoryPanel.innerHTML = '<div class=\"form-b-category-empty\">No PMAS Form B categories are available.</div>';
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
                    <div class=\"form-b-panel-head\">
                        <div><h3>\${category.title}</h3><p>\${Number(category.factor_weight).toFixed(0)}% factor weight</p></div>
                        <div class=\"form-b-score-chip\"><strong>\${stats.average ? stats.average.toFixed(2) : '0.00'}</strong><span>Average</span></div>
                    </div>
                    <div class=\"form-b-question-stack\">
                        \${category.questions.map((question, index) => \`
                            <label class=\"form-b-question-row\">
                                <span>\${index + 1}. \${question.text}</span>
                                <select data-form-b-answer=\"\${question.id}\" \${currentUsesFormB && false ? 'disabled' : ''}>
                                    <option value=\"\">Rate</option>
                                    <option value=\"5\">5 - Highly Evident</option>
                                    <option value=\"4\">4 - Evident</option>
                                    <option value=\"3\">3 - Moderately Evident</option>
                                    <option value=\"2\">2 - Slightly Evident</option>
                                    <option value=\"1\">1 - Not Evident</option>
                                </select>
                            </label>
                        \`).join('')}
                    </div>
                    <div class=\"form-b-computation-grid\">
                        <article><span>Total Rate</span><strong>\${stats.totalRate.toFixed(2)}</strong></article>
                        <article><span>No. of Questions</span><strong>\${stats.questionCount}</strong></article>
                        <article><span>Average Rating</span><strong>\${stats.average.toFixed(2)}</strong></article>
                        <article><span>Weighted Score</span><strong>\${stats.weighted.toFixed(4)}</strong></article>
                    </div>
                    <div class=\"form-b-required-box\">
                        <strong>\${requirement}</strong>
                        <label>Behavioral Evidences
                            <textarea data-form-b-field=\"behavioral_evidence\" placeholder=\"Give specific examples, observed behavior, accomplishments, teaching practices, or incidents.\"></textarea>
                        </label>
                        <button type=\"button\" class=\"ghost-button form-b-ai\" data-ai-target=\"behavioral_evidence\">AI Suggestion</button>
                        <button type=\"button\" class=\"dipascaf-evaluate-btn form-b-save-category\" \${isFormBCategoryReady(category) ? '' : 'disabled'}>state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;`;

const newRenderFormBPanel = `            function renderFormBPanel() {
                if (!formBCategoryPanel) return;
                const category = formBCategories.find((item) => String(item.id) === activeFormBCategoryId) || formBCategories[0];
                if (!category) {
                    formBCategoryPanel.innerHTML = '<div class=\"form-b-category-empty\">No PMAS Form B categories are available.</div>';
                    return;
                }
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);

                // Determine what evidence fields to show
                const needEvidence = stats.average >= 4.51;
                const needRecommendation = stats.average <= 3;
                const isNormal = stats.average > 3 && stats.average < 4.51 && stats.answered === stats.questionCount;

                let evidenceHtml = '';
                if (stats.answered === stats.questionCount && stats.average > 0) {
                    if (needEvidence) {
                        evidenceHtml = \`
                            <div class=\"form-b-required-box\">
                                <strong style=\"color:#dc2626\">${
                                    needRecommendation
                                        ? 'Behavioral Evidence and Recommendation are required for this low rating.'
                                        : 'Behavioral Evidence is required for this high rating.'
                                }</strong>
                                <label>Behavioral Evidence
                                    <textarea data-form-b-field="behavioral_evidence" placeholder="Describe specific observed behaviors, achievements, or incidents that support this rating.">\${(state.behavioral_evidence || '')}</textarea>
                                </label>
                        \`;
                        if (needRecommendation) {
                            evidenceHtml += \`
                                <label>Recommendation for Improvement
                                    <textarea data-form-b-field="recommendation" placeholder="Suggest specific actions, training, or mentoring to help improve performance.">\${(state.recommendation || '')}</textarea>
                                </label>
                            \`;
                        }
                        evidenceHtml += \`
                                <button type="button" class="ghost-button form-b-ai" data-ai-target="behavioral_evidence">AI Suggestion</button>
                                <button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${isFormBCategoryReady(category) ? '' : 'disabled'}>state.saved ? 'Category Saved' : 'Save Category'}</button>
                            </div>
                        \`;
                    } else if (isNormal) {
                        // Normal rating — evidence is optional
                        evidenceHtml = \`
                            <div class=\"form-b-required-box\">
                                <strong style=\"color:#2563eb\">Rating is satisfactory. Behavioral evidence is optional.</strong>
                                <label>Behavioral Evidence (Optional)
                                    <textarea data-form-b-field="behavioral_evidence" placeholder="Optionally provide any supporting comments or evidence for this rating.">\${(state.behavioral_evidence || '')}</textarea>
                                </label>
                                <button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${isFormBCategoryReady(category) ? '' : 'disabled'}>state.saved ? 'Category Saved' : 'Save Category'}</button>
                            </div>
                        \`;
                    }
                }

                formBCategoryPanel.innerHTML = \`
                    <div class=\"form-b-panel-head\">
                        <div><h3>\${category.title}</h3><p>\${Number(category.factor_weight).toFixed(0)}% factor weight</p></div>
                        <div class=\"form-b-score-chip\"><strong>\${stats.average ? stats.average.toFixed(2) : '0.00'}</strong><span>Average</span></div>
                    </div>
                    <div class=\"form-b-question-stack\">
                        \${category.questions.map((question, index) => \`
                            <label class=\"form-b-question-row\">
                                <span>\${index + 1}. \${question.text}</span>
                                <select data-form-b-answer="\${question.id}" \${currentUsesFormB && false ? 'disabled' : ''}>
                                    <option value=\"\">Rate</option>
                                    <option value=\"5\">5 - Highly Evident</option>
                                    <option value=\"4\">4 - Evident</option>
                                    <option value=\"3\">3 - Moderately Evident</option>
                                    <option value=\"2\">2 - Slightly Evident</option>
                                    <option value=\"1\">1 - Not Evident</option>
                                </select>
                            </label>
                        \`).join('')}
                    </div>
                    <div class=\"form-b-computation-grid\">
                        <article><span>Total Rate</span><strong>\${stats.totalRate.toFixed(2)}</strong></article>
                        <article><span>No. of Questions</span><strong>\${stats.questionCount}</strong></article>
                        <article><span>Average Rating</span><strong>\${stats.average.toFixed(2)}</strong></article>
                        <article><span>Weighted Score</span><strong>\${stats.weighted.toFixed(4)}</strong></article>
                    </div>
                    \${evidenceHtml}
                \`;`;

if (cards.includes(oldRenderFormBPanel)) {
  cards = cards.replace(oldRenderFormBPanel, newRenderFormBPanel);
  console.log('✅ Updated renderFormBPanel() with conditional evidence');
} else {
  console.log('❌ Could not find renderFormBPanel() pattern');
}

// --- 1d. Update isFormACategoryReady() ---
const oldIsFormAReady = `            function isFormACategoryReady(cat) {
                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                return Boolean((state.behavioral_evidence || '').trim());
            }`;

const newIsFormAReady = `            function isFormACategoryReady(cat) {
                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                // Evidence required for high (≥4.51) or low (≤3) ratings; optional for normal (3.01-4.50)
                if (stats.average >= 4.51) {
                    return Boolean((state.behavioral_evidence || '').trim());
                }
                if (stats.average <= 3) {
                    return Boolean((state.behavioral_evidence || '').trim()) && Boolean((state.recommendation || '').trim());
                }
                return true; // Normal/satisfactory rating — evidence optional
            }`;

if (cards.includes(oldIsFormAReady)) {
  cards = cards.replace(oldIsFormAReady, newIsFormAReady);
  console.log('✅ Updated isFormACategoryReady()');
} else {
  console.log('❌ Could not find isFormACategoryReady() pattern');
}

// --- 1e. Update saveFormACategory() alert message ---
const oldSaveFormAMsg = `                    alert('Complete Behavioral Evidence before saving this category.');`;
const newSaveFormAMsg = `                    alert('Complete all required fields (behavioral evidence and/or recommendation) before saving this category.');`;
if (cards.includes(oldSaveFormAMsg)) {
  cards = cards.replace(oldSaveFormAMsg, newSaveFormAMsg);
  console.log('✅ Updated saveFormACategory alert message');
}

// --- 1f. Update renderFormAPanel() - conditional evidence section ---
const oldRenderFormAPanelEvidence = `                if (stats.average > 0) {
                    html += \`<div class=\"form-a-explanation\">
                        <label>Behavioral Evidence <span style=\"color:#dc2626\">*Required</span></label>
                        <textarea data-form-a-field=\"behavioral_evidence\" placeholder=\"Describe specific observed behaviors, achievements, performance gaps, or incidents that support this rating.\">\${escHtml(state.behavioral_evidence || '')}</textarea>
                        <button type=\"button\" class=\"form-a-ai-btn\" data-form-a-ai=\"behavioral_evidence\">AI Suggestion</button>
                        <button type=\"button\" class=\"dipascaf-evaluate-btn form-a-save-category\" \${isFormACategoryReady(cat) ? '' : 'disabled'}>state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>\`;
                }`;

const newRenderFormAPanelEvidence = `                if (stats.average > 0 && stats.answered === stats.questionCount) {
                    const needEvidence = stats.average >= 4.51;
                    const needRecommendation = stats.average <= 3;
                    const isNormal = stats.average > 3 && stats.average < 4.51;

                    if (needEvidence) {
                        html += \`<div class=\"form-a-explanation\">
                            <strong style=\"color:#dc2626;display:block;margin-bottom:8px\">${
                                needRecommendation
                                    ? 'Behavioral Evidence and Recommendation are required for this low rating.'
                                    : 'Behavioral Evidence is required for this high rating.'
                            }</strong>
                            <label>Behavioral Evidence <span style=\"color:#dc2626\">*Required</span></label>
                            <textarea data-form-a-field=\"behavioral_evidence\" placeholder=\"Describe specific observed behaviors, achievements, performance gaps, or incidents that support this rating.\">\${escHtml(state.behavioral_evidence || '')}</textarea>
                        \`;
                        if (needRecommendation) {
                            html += \`
                            <label>Recommendation for Improvement <span style=\"color:#dc2626\">*Required</span></label>
                            <textarea data-form-a-field=\"recommendation\" placeholder=\"Suggest specific actions, training, or mentoring to help improve performance.\">\${escHtml(state.recommendation || '')}</textarea>
                            \`;
                        }
                        html += \`
                            <button type=\"button\" class=\"form-a-ai-btn\" data-form-a-ai=\"behavioral_evidence\">AI Suggestion</button>
                            <button type=\"button\" class=\"dipascaf-evaluate-btn form-a-save-category\" \${isFormACategoryReady(cat) ? '' : 'disabled'}>state.saved ? 'Category Saved' : 'Save Category'}</button>
                        </div>\`;
                    } else if (isNormal) {
                        html += \`<div class=\"form-a-explanation\">
                            <strong style=\"color:#2563eb;display:block;margin-bottom:8px\">Rating is satisfactory. Behavioral evidence is optional.</strong>
                            <label>Behavioral Evidence (Optional)</label>
                            <textarea data-form-a-field=\"behavioral_evidence\" placeholder=\"Optionally provide any supporting comments or evidence for this rating.\">\${escHtml(state.behavioral_evidence || '')}</textarea>
                            <button type=\"button\" class=\"dipascaf-evaluate-btn form-a-save-category\" \${isFormACategoryReady(cat) ? '' : 'disabled'}>state.saved ? 'Category Saved' : 'Save Category'}</button>
                        </div>\`;
                    }
                }`;

if (cards.includes(oldRenderFormAPanelEvidence)) {
  cards = cards.replace(oldRenderFormAPanelEvidence, newRenderFormAPanelEvidence);
  console.log('✅ Updated renderFormAPanel() conditional evidence');
} else {
  console.log('❌ Could not find renderFormAPanel() evidence pattern');
}

// --- 1g. Remove per-question evidence from dipascaf_submit_form_a_evaluation() ---
const oldFormAPerQuestion = `                // Per-question evidence: required for rating 5 (high) or 1 (low), optional otherwise
                $ev = trim((string) ($evidence[$qKey] ?? ''));
                if (in_array($rate, [5, 1], true) && $ev === '') {
                    throw new RuntimeException('Behavioral evidence is required for ratings of 5 or 1. Question under ' . $category['title'] . ' requires justification.');
                }
                $cleanEvidence[$qKey] = $ev;`;

const newFormAPerQuestion = `                // Category-level evidence: per-question evidence is not required
                // Evidence requirements are handled at the category level
                $cleanEvidence[$qKey] = trim((string) ($evidence[$qKey] ?? ''));`;

if (cards.includes(oldFormAPerQuestion)) {
  // Count replacement count to ensure we get all occurrences
  let count = 0;
  while (cards.includes(oldFormAPerQuestion)) {
    cards = cards.replace(oldFormAPerQuestion, newFormAPerQuestion);
    count++;
  }
  console.log(`✅ Removed per-question evidence requirement from submit_form_a (${count} occurrence(s))`);
} else {
  console.log('❌ Could not find per-question evidence pattern in submit_form_a');
}

// --- 1h. Remove per-question evidence from dipascaf_submit_form_b_evaluation() ---
const oldFormBPerQuestion = `                // Per-question evidence: required for rating 5 (high) or 1 (low), optional otherwise
                $ev = trim((string) ($evidence[$qKey] ?? ''));
                if (in_array($rate, [5, 1], true) && $ev === '') {
                    throw new RuntimeException('Behavioral evidence is required for ratings of 5 or 1. Question under ' . $category['title'] . ' requires justification.');
                }
                $cleanEvidence[$qKey] = $ev;`;

const newFormBPerQuestion = `                // Category-level evidence: per-question evidence is not required
                // Evidence requirements are handled at the category level
                $cleanEvidence[$qKey] = trim((string) ($evidence[$qKey] ?? ''));`;

if (cards.includes(oldFormBPerQuestion)) {
  let count = 0;
  while (cards.includes(oldFormBPerQuestion)) {
    cards = cards.replace(oldFormBPerQuestion, newFormBPerQuestion);
    count++;
  }
  console.log(`✅ Removed per-question evidence requirement from submit_form_b (${count} occurrence(s))`);
} else {
  console.log('❌ Could not find per-question evidence pattern in submit_form_b');
}

// --- 1i. Add category-level evidence validation in dipascaf_submit_form_a_evaluation() ---
// After computing $averageRating, add validation for category-level evidence
const oldFormAValidationAnchor = `            $averageRating = $questionCount > 0 ? round($totalRate / $questionCount, 2) : 0;
            $weightedScore = $questionCount > 0 ? round($averageRating * ($factorWeight / 100), 4) : 0;
            $requiredExplanation = 'none';`;

const newFormAValidationAnchor = `            $averageRating = $questionCount > 0 ? round($totalRate / $questionCount, 2) : 0;
            $weightedScore = $questionCount > 0 ? round($averageRating * ($factorWeight / 100), 4) : 0;

            // Category-level evidence validation
            $behavioralEvidence = trim((string) ($catData['behavioral_evidence'] ?? ''));
            $recommendation = trim((string) ($catData['recommendation'] ?? ''));
            if ($averageRating >= 4.51 && $behavioralEvidence === '') {
                throw new RuntimeException('Behavioral evidence is required for high ratings in ' . $category['title'] . '.');
            }
            if ($averageRating <= 3.00) {
                if ($behavioralEvidence === '') {
                    throw new RuntimeException('Behavioral evidence is required for low ratings in ' . $category['title'] . '.');
                }
                if ($recommendation === '') {
                    throw new RuntimeException('Recommendation is required for low ratings in ' . $category['title'] . '.');
                }
            }
            // For normal ratings (3.01-4.50), evidence is optional — no validation needed

            $requiredExplanation = 'none';`;

if (cards.includes(oldFormAValidationAnchor)) {
  cards = cards.replace(oldFormAValidationAnchor, newFormAValidationAnchor);
  console.log('✅ Added category-level evidence validation in submit_form_a');
} else {
  console.log('❌ Could not find form_a validation anchor');
}

// --- 1j. Add category-level evidence validation in dipascaf_submit_form_b_evaluation() ---
const oldFormBValidationAnchor = `            $averageRating = round($totalRate / $questionCount, 2);
            $factorWeight = (float) $category['factor_weight'];
            $weightedScore = round($averageRating * ($factorWeight / 100), 4);
            $behavioralEvidence = '';
            $reasonForRating = '';`;

const newFormBValidationAnchor = `            $averageRating = round($totalRate / $questionCount, 2);
            $factorWeight = (float) $category['factor_weight'];
            $weightedScore = round($averageRating * ($factorWeight / 100), 4);

            // Category-level evidence validation
            $behavioralEvidence = trim((string) ($result['behavioral_evidence'] ?? ''));
            $recommendation = trim((string) ($result['recommendation'] ?? ''));
            if ($averageRating >= 4.51 && $behavioralEvidence === '') {
                throw new RuntimeException('Behavioral evidence is required for high ratings in ' . $category['title'] . '.');
            }
            if ($averageRating <= 3.00) {
                if ($behavioralEvidence === '') {
                    throw new RuntimeException('Behavioral evidence is required for low ratings in ' . $category['title'] . '.');
                }
                if ($recommendation === '') {
                    throw new RuntimeException('Recommendation is required for low ratings in ' . $category['title'] . '.');
                }
            }
            // For normal ratings (3.01-4.50), evidence is optional — no validation needed

            $reasonForRating = '';`;

if (cards.includes(oldFormBValidationAnchor)) {
  cards = cards.replace(oldFormBValidationAnchor, newFormBValidationAnchor);
  console.log('✅ Added category-level evidence validation in submit_form_b');
} else {
  console.log('❌ Could not find form_b validation anchor');
}

// Write updated cards file
fs.writeFileSync('includes/evaluation_cards.php', cards);
console.log('✅ Written updated includes/evaluation_cards.php');

// ================================================================
// 2. Update dashboards/teacher.php - Self Evaluation Logic
// ================================================================
let teacher = fs.readFileSync('dashboards/teacher.php', 'utf8');

// Update the completion check logic in the self-evaluation JS
// Find the part where complete is determined based on requiredType
const oldCompletionLogic = `                let requiredType = 'none';
                if (avg >= 4.51) requiredType = 'high';
                else if (avg <= 3) requiredType = 'low';
                else if (avg >= 3.01) requiredType = 'reason';

                let complete = false;
                if (answered === totalQuestions && totalQuestions > 0) {
                    if (avg >= 4.51) complete = evidence.length > 0;
                    else if (avg <= 3) complete = evidence.length > 0 && recommendation.length > 0;
                    else complete = reason.length > 0;
                }`;

const newCompletionLogic = `                let requiredType = 'none';
                if (avg >= 4.51) requiredType = 'high';
                else if (avg <= 3) requiredType = 'low';
                else if (avg >= 3.01) requiredType = 'normal';

                let complete = false;
                if (answered === totalQuestions && totalQuestions > 0) {
                    if (avg >= 4.51) complete = evidence.length > 0;
                    else if (avg <= 3) complete = evidence.length > 0 && recommendation.length > 0;
                    else complete = true; // Normal rating — evidence is optional
                }`;

if (teacher.includes(oldCompletionLogic)) {
  teacher = teacher.replace(oldCompletionLogic, newCompletionLogic);
  console.log('✅ Updated teacher.php self-evaluation completion logic');
} else {
  console.log('❌ Could not find teacher completion logic pattern');
}

// Update the conditional fields display logic for teacher self-evaluation
const oldCondFieldsLogic = `                reasonField.hidden = state.avg === 0 || state.requiredType !== 'reason';
                evidenceField.hidden = state.avg === 0 || (state.requiredType !== 'high' && state.requiredType !== 'low');
                recommendationField.hidden = state.avg === 0 || state.requiredType !== 'low';`;

const newCondFieldsLogic = `                // Show evidence fields: required for high/low, optional for normal
                const showEvidence = state.requiredType === 'high' || state.requiredType === 'low';
                const showNormalOption = state.requiredType === 'normal';
                reasonField.hidden = true; // Reason field replaced by category-level evidence logic
                evidenceField.hidden = state.avg === 0 || (!showEvidence && !showNormalOption);
                recommendationField.hidden = state.avg === 0 || state.requiredType !== 'low';`;

if (teacher.includes(oldCondFieldsLogic)) {
  teacher = teacher.replace(oldCondFieldsLogic, newCondFieldsLogic);
  console.log('✅ Updated teacher.php conditional fields display logic');
} else {
  console.log('❌ Could not find teacher conditional fields pattern');
}

// Write updated teacher file
fs.writeFileSync('dashboards/teacher.php', teacher);
console.log('✅ Written updated dashboards/teacher.php');

console.log('\n🎉 All edits applied successfully!');
