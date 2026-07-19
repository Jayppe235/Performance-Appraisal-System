const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'includes', 'evaluation_cards.php');
let content = fs.readFileSync(filePath, 'utf8');
let changes = 0;

// ============================================================
// CHANGE 1: Fix renderFormBPanel() - conditional evidence UI
// ============================================================

// Find the renderFormBPanel function and replace the innerHTML template
const renderBPanelOld = `            function renderFormBPanel() {
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
                        <button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${isFormBCategoryReady(category) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;`;

const renderBPanelNew = `            function renderFormBPanel() {
                if (!formBCategoryPanel) return;
                const category = formBCategories.find((item) => String(item.id) === activeFormBCategoryId) || formBCategories[0];
                if (!category) {
                    formBCategoryPanel.innerHTML = '<div class="form-b-category-empty">No PMAS Form B categories are available.</div>';
                    return;
                }
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                const allAnswered = stats.answered === stats.questionCount && stats.questionCount > 0;
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
                    <div class="form-b-evidence-box">
                        \${(() => {
                            if (!allAnswered || stats.average <= 0) {
                                return stats.answered > 0 ? \`<div class="form-b-progress-hint">Answer all questions to see evidence fields.</div>\` : '';
                            }
                            if (stats.average >= 4.51) {
                                return \`<div class="form-b-evidence-section">
                                    <div class="evidence-heading"><strong>High Rating - Behavioral Evidence Required</strong></div>
                                    <label>Behavioral Evidence <span class="required-mark">*Required</span></label>
                                    <textarea data-form-b-field="behavioral_evidence" placeholder="Describe specific observed behaviors, achievements, or performance gaps that support this rating.">\${escHtml(state.behavioral_evidence || '')}</textarea>
                                    <button type="button" class="ghost-button form-b-ai" data-ai-target="behavioral_evidence">AI Suggestion</button>
                                </div>\`;
                            }
                            if (stats.average <= 3) {
                                return \`<div class="form-b-evidence-section">
                                    <div class="evidence-heading"><strong>Low Rating - Behavioral Evidence and Recommendation Required</strong></div>
                                    <label>Behavioral Evidence <span class="required-mark">*Required</span></label>
                                    <textarea data-form-b-field="behavioral_evidence" placeholder="Describe specific observed behaviors or performance gaps that support this rating.">\${escHtml(state.behavioral_evidence || '')}</textarea>
                                    <button type="button" class="ghost-button form-b-ai" data-ai-target="behavioral_evidence">AI Suggestion</button>
                                    <label>Recommendation <span class="required-mark">*Required</span></label>
                                    <textarea data-form-b-field="recommendation" placeholder="Suggest specific actions or interventions for improvement.">\${escHtml(state.recommendation || '')}</textarea>
                                    <button type="button" class="ghost-button form-b-ai" data-ai-target="recommendation">AI Recommendation</button>
                                </div>\`;
                            }
                            return \`<div class="form-b-evidence-section">
                                <div class="evidence-heading"><strong>Satisfactory Rating - Evidence Optional</strong></div>
                                <label>Additional Comments (Optional)</label>
                                <textarea data-form-b-field="reason_for_rating" placeholder="Any additional comments or observations about this category.">\${escHtml(state.reason_for_rating || '')}</textarea>
                            </div>\`;
                        })()}
                        \${allAnswered && stats.average > 0
                            ? \`<button type="button" class="dipascaf-evaluate-btn form-b-save-category" \${isFormBCategoryReady(category) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>\`
                            : ''}
                    </div>
                \`;`;

if (content.includes(renderBPanelOld)) {
    content = content.replace(renderBPanelOld, renderBPanelNew);
    console.log('CHANGE 1: renderFormBPanel() - Updated conditional evidence UI');
    changes++;
} else {
    console.log('WARN: renderFormBPanel() old string not found. Checking for partial match...');
    // Try to find the key section
    if (content.includes("'Behavioral Evidences are required for this rating.';")) {
        console.log('  -> Found the line with the requirement string');
    } else {
        console.log('  -> requirement string NOT found - may already be updated?');
    }
}

// ============================================================
// CHANGE 2: Fix dipascaf_submit_form_a_evaluation() - read evidence from payload
// ============================================================

const formAOld = `            $catData = $formData[(string) $catId] ?? $formData[$catId] ?? [];
            $answers = $catData['answers'] ?? [];
            $evidence = $catData['evidence'] ?? [];
            $behavioralEvidence = '';
            $reasonForRating   = '';
            $recommendation    = '';
            $aiSuggestion      = '';
            $aiDecision        = 'none';`;

const formANew = `            $catData = $formData[(string) $catId] ?? $formData[$catId] ?? [];
            $answers = $catData['answers'] ?? [];
            $behavioralEvidence = trim((string) ($catData['behavioral_evidence'] ?? ''));
            $reasonForRating   = trim((string) ($catData['reason_for_rating'] ?? ''));
            $recommendation    = trim((string) ($catData['recommendation'] ?? ''));
            $aiSuggestion      = trim((string) ($catData['ai_suggestion'] ?? ''));
            $aiDecision        = (string) ($catData['ai_decision'] ?? 'none');`;

if (content.includes(formAOld)) {
    content = content.replace(formAOld, formANew);
    console.log('CHANGE 2: dipascaf_submit_form_a_evaluation() - Evidence now read from payload');
    changes++;
} else {
    console.log('WARN: Form A evidence reading not found - may already be updated');
}

// Also fix the $requiredExplanation line in Form A
const formARequiredOld = `            $averageRating = $questionCount > 0 ? round($totalRate / $questionCount, 2) : 0;
            $weightedScore = $questionCount > 0 ? round($averageRating * ($factorWeight / 100), 4) : 0;
            $requiredExplanation = 'none';`;

const formARequiredNew = `            $averageRating = $questionCount > 0 ? round($totalRate / $questionCount, 2) : 0;
            $weightedScore = $questionCount > 0 ? round($averageRating * ($factorWeight / 100), 4) : 0;
            $requiredExplanation = dipascaf_required_explanation_type($averageRating);

            dipascaf_validate_category_explanation(
                $category['title'],
                $averageRating,
                $behavioralEvidence,
                $reasonForRating,
                $recommendation,
                $aiSuggestion,
                $aiDecision
            );`;

if (content.includes(formARequiredOld)) {
    content = content.replace(formARequiredOld, formARequiredNew);
    console.log('CHANGE 3: dipascaf_submit_form_a_evaluation() - required_explanation now dynamic + validation added');
    changes++;
} else {
    console.log('WARN: Form A requiredExplanation line not found');
}

// ============================================================
// CHANGE 4: Fix dipascaf_submit_form_b_evaluation() - read evidence from payload
// ============================================================

const formBOld = `            $behavioralEvidence = '';
            $reasonForRating = '';
            $recommendation = '';
            $aiSuggestion = '';
            $aiDecision = 'none';
            $requiredExplanation = 'none';`;

const formBNew = `            $behavioralEvidence = trim((string) ($result['behavioral_evidence'] ?? ''));
            $reasonForRating = trim((string) ($result['reason_for_rating'] ?? ''));
            $recommendation = trim((string) ($result['recommendation'] ?? ''));
            $aiSuggestion = trim((string) ($result['ai_suggestion'] ?? ''));
            $aiDecision = (string) ($result['ai_decision'] ?? 'none');
            $requiredExplanation = dipascaf_required_explanation_type($averageRating);

            dipascaf_validate_category_explanation(
                $category['title'],
                $averageRating,
                $behavioralEvidence,
                $reasonForRating,
                $recommendation,
                $aiSuggestion,
                $aiDecision
            );`;

if (content.includes(formBOld)) {
    content = content.replace(formBOld, formBNew);
    console.log('CHANGE 4: dipascaf_submit_form_b_evaluation() - Evidence now read from payload + validation');
    changes++;
} else {
    console.log('WARN: Form B evidence section not found');
}

// ============================================================
// Also fix the unused $evidence variable in Form B
// ============================================================

const formBEvidenceOld = `            $evidence = is_array($result['evidence'] ?? null) ? $result['evidence'] : [];
            $questionIds = array_map(static fn (array $question): int => (int) $question['id'], $category['questions']);`;

const formBEvidenceNew = `            $questionIds = array_map(static fn (array $question): int => (int) $question['id'], $category['questions']);`;

// Don't remove $evidence in Form B - it's fine to leave unused
// Actually let me check if $evidence is used anywhere downstream in Form B
// From the code, $cleanEvidence is created separately, so $evidence is unused

const formBCleanEvidenceOld = `            $rates = [];
            $cleanEvidence = [];`;

const formBCleanEvidenceNew = `            $rates = [];`;

if (content.includes(formBCleanEvidenceOld)) {
    content = content.replace(formBCleanEvidenceOld, formBCleanEvidenceNew);
    console.log('CHANGE 5: dipascaf_submit_form_b_evaluation() - Removed unused $cleanEvidence');
    changes++;
}

// Also remove $evidence line in Form B
if (content.includes(formBEvidenceOld)) {
    content = content.replace(formBEvidenceOld, formBEvidenceNew);
    console.log('CHANGE 6: dipascaf_submit_form_b_evaluation() - Removed unused $evidence');
    changes++;
}

// ============================================================
// Also remove unused $evidence in Form A and $cleanEvidence
// ============================================================

const formAEvidenceOld = `            $evidence = $catData['evidence'] ?? [];
            $behavioralEvidence = trim((string) ($catData['behavioral_evidence'] ?? ''));`;

const formAEvidenceNew = `            $behavioralEvidence = trim((string) ($catData['behavioral_evidence'] ?? ''));`;

if (content.includes(formAEvidenceOld)) {
    content = content.replace(formAEvidenceOld, formAEvidenceNew);
    console.log('CHANGE 7: dipascaf_submit_form_a_evaluation() - Removed unused $evidence');
    changes++;
}

// Also fix unused $cleanEvidence in Form A
const formACleanOld = `            $cleanAnswers = [];
            $cleanEvidence = [];`;

const formACleanNew = `            $cleanAnswers = [];`;

if (content.includes(formACleanOld)) {
    content = content.replace(formACleanOld, formACleanNew);
    console.log('CHANGE 8: dipascaf_submit_form_a_evaluation() - Removed unused $cleanEvidence');
    changes++;
}

// Remove $cleanEvidence[$qKey] = ''; line in Form A
const formACleanEvidenceLine = `                $cleanEvidence[$qKey] = '';`;
if (content.includes(formACleanEvidenceLine)) {
    content = content.replace(formACleanEvidenceLine, '');
    console.log('CHANGE 9: dipascaf_submit_form_a_evaluation() - Removed unused cleanEvidence assign');
    changes++;
}

// Remove $cleanEvidence[$qKey] = ''; line in Form B
const formBCleanEvidenceLine = `                $cleanEvidence[$qKey] = '';`;
if (content.includes(formBCleanEvidenceLine)) {
    content = content.replace(formBCleanEvidenceLine, '');
    console.log('CHANGE 10: dipascaf_submit_form_b_evaluation() - Removed unused cleanEvidence assign');
    changes++;
}

// Replace questionnaire_evidence inserts with empty JSON in both forms
const formAEvidenceInsert = `'questionnaire_evidence' => json_encode($cleanEvidence, JSON_THROW_ON_ERROR),`;
const formAEvidenceInsertNew = `'questionnaire_evidence' => json_encode([], JSON_THROW_ON_ERROR),`;

if (content.includes(formAEvidenceInsert)) {
    content = content.replace(formAEvidenceInsert, formAEvidenceInsertNew);
    console.log('CHANGE 11: Form A - questionnaire_evidence uses empty array');
    changes++;
}

const formBEvidenceInsert = `'questionnaire_evidence' => json_encode($cleanEvidence, JSON_THROW_ON_ERROR),`;
if (content.includes(formBEvidenceInsert)) {
    content = content.replace(formBEvidenceInsert, formAEvidenceInsertNew);
    console.log('CHANGE 12: Form B - questionnaire_evidence uses empty array');
    changes++;
}

// ============================================================
// Write the file
// ============================================================

fs.writeFileSync(filePath, content, 'utf8');
console.log(`\nDone! ${changes} changes applied.`);
