const fs = require('fs');
const path = require('path');
const file = path.join(__dirname, 'includes', 'evaluation_cards.php');
let content = fs.readFileSync(file, 'utf8');

// ============================================================
// CHANGE 1: formAStats() - change 'reason' to 'normal' for requiredType
// ============================================================
content = content.replace(
  `requiredType: average >= 4.51 ? 'high' : average <= 3 ? 'low' : 'reason',`,
  `requiredType: average >= 4.51 ? 'high' : average <= 3 ? 'low' : 'normal',`
);

// ============================================================
// CHANGE 2: isFormACategoryReady() - conditional evidence requirement
// ============================================================
content = content.replace(
  `function isFormACategoryReady(cat) {
                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                return Boolean((state.behavioral_evidence || '').trim());
            }`,
  `function isFormACategoryReady(cat) {
                const state = formAState[String(cat.id)] || {};
                const stats = formAStats(cat);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                if (stats.requiredType === 'high') return Boolean((state.behavioral_evidence || '').trim());
                if (stats.requiredType === 'low') return Boolean((state.behavioral_evidence || '').trim()) && Boolean((state.recommendation || '').trim());
                return true;
            }`
);

// ============================================================
// CHANGE 3: isFormACategoryReady check in saveFormACategory alert message
// ============================================================
content = content.replace(
  `alert('Complete Behavioral Evidence before saving this category.');`,
  `alert('Complete all required fields before saving this category.');`
);

// ============================================================
// CHANGE 4: formBStats() - change 'reason' to 'normal' for requiredType
// ============================================================
content = content.replace(
  `requiredType: average >= 4.51 ? 'high' : average <= 3 ? 'low' : 'reason',`,
  `requiredType: average >= 4.51 ? 'high' : average <= 3 ? 'low' : 'normal',`
);

// ============================================================
// CHANGE 5: isFormBCategoryReady() - conditional evidence requirement
// ============================================================
content = content.replace(
  `function isFormBCategoryReady(category) {
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                return Boolean((state.behavioral_evidence || '').trim());
            }`,
  `function isFormBCategoryReady(category) {
                const state = formBState[String(category.id)] || {};
                const stats = formBStats(category);
                if (stats.answered !== stats.questionCount || stats.average <= 0) return false;
                if (stats.requiredType === 'high') return Boolean((state.behavioral_evidence || '').trim());
                if (stats.requiredType === 'low') return Boolean((state.behavioral_evidence || '').trim()) && Boolean((state.recommendation || '').trim());
                return true;
            }`
);

// ============================================================
// CHANGE 6: renderFormBPanel - conditional evidence/recommendation fields
// ============================================================
content = content.replace(
  `                const requirement = stats.average >= 4.51
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
                        <button type=\"button\" class=\"dipascaf-evaluate-btn form-b-save-category\" \${isFormBCategoryReady(category) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;`,
  `                const requirement = stats.average >= 4.51
                    ? 'High category score - Behavioral Evidence is required.'
                    : stats.average <= 3
                        ? 'Low category score - Behavioral Evidence and Recommendation are required.'
                        : 'Satisfactory category score - Evidence is optional.';
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
                    <div class="form-b-category-summary">
                        <span class="category-result-label">Category Result:</span>
                        <span class="category-result-badge \${stats.requiredType}">\${stats.average >= 4.51 ? 'High' : stats.average <= 3 ? 'Low' : 'Satisfactory'}</span>
                        <span class="category-result-score">Score: \${stats.average ? stats.average.toFixed(2) : '0.00'} / 5.00</span>
                    </div>
                    <div class=\"form-b-required-box\">
                        <strong>\${requirement}</strong>
                        <label>Behavioral Evidence \${stats.requiredType === 'normal' ? '<em>(optional)</em>' : '<span style=\"color:#dc2626\">*Required</span>'}
                            <textarea data-form-b-field=\"behavioral_evidence\" placeholder="\${stats.requiredType === 'normal' ? 'Optional: provide specific examples if applicable...' : 'Give specific examples, observed behavior, accomplishments, teaching practices, or incidents.'}"></textarea>
                        </label>
                        \${stats.requiredType === 'low' ? \`
                        <label>Recommendation <span style="color:#dc2626">*Required for low ratings</span>
                            <textarea data-form-b-field="recommendation" placeholder="Suggest specific actions or improvement plans for this area."></textarea>
                        </label>
                        \` : ''}
                        <button type=\"button\" class=\"ghost-button form-b-ai\" data-ai-target=\"behavioral_evidence\">AI Suggestion</button>
                        <button type=\"button\" class=\"dipascaf-evaluate-btn form-b-save-category\" \${isFormBCategoryReady(category) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>
                \`;`
);

// ============================================================
// CHANGE 7: renderFormAPanel - conditional evidence/recommendation fields
// ============================================================
// Replace the behavioral evidence section in renderFormAPanel
content = content.replace(
  `                if (stats.average > 0) {
                    html += \`<div class=\"form-a-explanation\">
                        <label>Behavioral Evidence <span style=\"color:#dc2626\">*Required</span></label>
                        <textarea data-form-a-field=\"behavioral_evidence\" placeholder=\"Describe specific observed behaviors, achievements, performance gaps, or incidents that support this rating.\">\${escHtml(state.behavioral_evidence || '')}</textarea>
                        <button type=\"button\" class=\"form-a-ai-btn\" data-form-a-ai=\"behavioral_evidence\">AI Suggestion</button>
                        <button type=\"button\" class=\"dipascaf-evaluate-btn form-a-save-category\" \${isFormACategoryReady(cat) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>\`;
                }`,
  `                if (stats.average > 0) {
                    html += \`<div class=\"form-a-category-summary\">
                        <span class=\"category-result-label\">Category Result:</span>
                        <span class=\"category-result-badge \${stats.requiredType}\">\${stats.average >= 4.51 ? 'High' : stats.average <= 3 ? 'Low' : 'Satisfactory'}</span>
                        <span class=\"category-result-score\">Score: \${stats.average.toFixed(2)} / 5.00</span>
                    </div>\`;
                    html += \`<div class=\"form-a-explanation\">
                        <label>Behavioral Evidence \${stats.requiredType === 'normal' ? '<em>(optional)</em>' : '<span style=\"color:#dc2626\">*Required</span>'}</label>
                        <textarea data-form-a-field=\"behavioral_evidence\" placeholder=\"\${stats.requiredType === 'normal' ? 'Optional: provide specific examples if applicable...' : 'Describe specific observed behaviors, achievements, performance gaps, or incidents that support this rating.'}\">\${escHtml(state.behavioral_evidence || '')}</textarea>
                        \${stats.requiredType === 'low' ? \`
                        <label>Recommendation <span style="color:#dc2626">*Required for low ratings</span>
                            <textarea data-form-a-field="recommendation" placeholder="Suggest specific actions or improvement plans for this area."></textarea>
                        </label>
                        \` : ''}
                        <button type=\"button\" class=\"form-a-ai-btn\" data-form-a-ai=\"behavioral_evidence\">AI Suggestion</button>
                        <button type=\"button\" class=\"dipascaf-evaluate-btn form-a-save-category\" \${isFormACategoryReady(cat) ? '' : 'disabled'}>\${state.saved ? 'Category Saved' : 'Save Category'}</button>
                    </div>\`;
                }`
);

// ============================================================
// CHANGE 8: Add CSS for category result badges
// ============================================================
// Find the closing </style> tag in the PHP echo block and add styles before it
content = content.replace(
  `.form-b-save-category{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:#1f7a4f;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:.82rem;cursor:pointer;transition:.15s}
.form-b-save-category:disabled{opacity:.4;cursor:not-allowed}`,
  `.form-b-save-category{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:#1f7a4f;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:.82rem;cursor:pointer;transition:.15s}
.form-b-save-category:disabled{opacity:.4;cursor:not-allowed}
.form-b-category-summary,.form-a-category-summary{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;padding:10px 14px;margin:10px 0 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem}
.category-result-label{font-weight:700;color:#475569}
.category-result-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-weight:800;font-size:.75rem;text-transform:uppercase}
.category-result-badge.high{background:#dcfce7;color:#166534}
.category-result-badge.low{background:#fee2e2;color:#991b1b}
.category-result-badge.normal{background:#dbeafe;color:#1e40af}
.category-result-score{font-weight:700;color:#334155;margin-left:auto}`
);

// ============================================================
// CHANGE 9: Server-side - Remove per-question evidence validation in form_a
// ============================================================
content = content.replace(
  `                // Per-question evidence: required for rating 5 (high) or 1 (low), optional otherwise
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                if (in_array(\$rate, [5, 1], true) && \$ev === '') {
                    throw new RuntimeException('Behavioral evidence is required for ratings of 5 or 1. Question under ' . \$category['title'] . ' requires justification.');
                }
                \$cleanEvidence[\$qKey] = \$ev;`,
  `                // Per-question evidence collection (no longer required per question - now handled at category level)
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                \$cleanEvidence[\$qKey] = \$ev;`
);

// ============================================================
// CHANGE 10: Add category-level validation to form_a submission
// ============================================================
// After computing averageRating and before $totalWeightedScore +=, add validation
content = content.replace(
  `\$averageRating = \$questionCount > 0 ? round(\$totalRate / \$questionCount, 2) : 0;
            \$weightedScore = \$questionCount > 0 ? round(\$averageRating * (\$factorWeight / 100), 4) : 0;
            \$requiredExplanation = 'none';
            \$totalWeightedScore += \$weightedScore;`,
  `\$averageRating = \$questionCount > 0 ? round(\$totalRate / \$questionCount, 2) : 0;
            \$weightedScore = \$questionCount > 0 ? round(\$averageRating * (\$factorWeight / 100), 4) : 0;
            \$requiredExplanation = 'none';
            // Category-level validation: evidence required for low/high, optional for satisfactory
            \$catBehavioralEvidence = trim((string) (\$catData['behavioral_evidence'] ?? ''));
            \$catRecommendation = trim((string) (\$catData['recommendation'] ?? ''));
            if (\$averageRating >= 4.51 && \$catBehavioralEvidence === '') {
                throw new RuntimeException('Behavioral Evidence is required for the high rating in \\'' . \$category['title'] . '\\'.');
            }
            if (\$averageRating <= 3.00) {
                if (\$catBehavioralEvidence === '') {
                    throw new RuntimeException('Behavioral Evidence is required for the low rating in \\'' . \$category['title'] . '\\'.');
                }
                if (\$catRecommendation === '') {
                    throw new RuntimeException('Recommendation is required for the low rating in \\'' . \$category['title'] . '\\'.');
                }
            }
            \$totalWeightedScore += \$weightedScore;`
);

// ============================================================
// CHANGE 11: Update the INSERT for form_a to include category-level evidence/recommendation
// ============================================================
// The form_a insert already uses $behavioralEvidence (empty) and $recommendation (empty) - 
// we need to update these to use the category-level values
content = content.replace(
  `\$behavioralEvidence = '';
            \$reasonForRating   = '';
            \$recommendation    = '';
            \$aiSuggestion      = '';
            \$aiDecision        = 'none';

            \$factorWeight = (float) (\$category['factor_weight'] ?? 0);
            \$totalRate = 0;
            \$cleanAnswers = [];
            \$cleanEvidence = [];
            \$questionIds = array_map(static fn (array \$question): int => (int) \$question['id'], \$category['questions'] ?? []);
            foreach (\$questionIds as \$questionId) {
                \$qKey = (string) \$questionId;
                if (!isset(\$answers[\$qKey])) {
                    throw new RuntimeException('Every question under ' . \$category['title'] . ' must be rated.');
                }
                \$rate = max(1, min(5, (int) \$answers[\$qKey]));
                \$cleanAnswers[\$questionId] = \$rate;
                \$totalRate += \$rate;

                // Per-question evidence collection (no longer required per question - now handled at category level)
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                \$cleanEvidence[\$qKey] = \$ev;
            }`,
  `\$aiSuggestion      = '';
            \$aiDecision        = 'none';

            \$factorWeight = (float) (\$category['factor_weight'] ?? 0);
            \$totalRate = 0;
            \$cleanAnswers = [];
            \$cleanEvidence = [];
            \$questionIds = array_map(static fn (array \$question): int => (int) \$question['id'], \$category['questions'] ?? []);
            foreach (\$questionIds as \$questionId) {
                \$qKey = (string) \$questionId;
                if (!isset(\$answers[\$qKey])) {
                    throw new RuntimeException('Every question under ' . \$category['title'] . ' must be rated.');
                }
                \$rate = max(1, min(5, (int) \$answers[\$qKey]));
                \$cleanAnswers[\$questionId] = \$rate;
                \$totalRate += \$rate;

                // Per-question evidence collection (no longer required per question - now handled at category level)
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                \$cleanEvidence[\$qKey] = \$ev;
            }
            \$behavioralEvidence = trim((string) (\$catData['behavioral_evidence'] ?? ''));
            \$reasonForRating   = trim((string) (\$catData['reason_for_rating'] ?? ''));
            \$recommendation    = trim((string) (\$catData['recommendation'] ?? ''));`
);

// ============================================================
// CHANGE 12: Remove per-question evidence validation in form_b submission
// ============================================================
content = content.replace(
  `                // Per-question evidence: required for rating 5 (high) or 1 (low), optional otherwise
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                if (in_array(\$rate, [5, 1], true) && \$ev === '') {
                    throw new RuntimeException('Behavioral evidence is required for ratings of 5 or 1. Question under ' . \$category['title'] . ' requires justification.');
                }
                \$cleanEvidence[\$qKey] = \$ev;`,
  `                // Per-question evidence collection (no longer required per question - now handled at category level)
                \$ev = trim((string) (\$evidence[\$qKey] ?? ''));
                \$cleanEvidence[\$qKey] = \$ev;`
);

// ============================================================
// CHANGE 13: Add category-level validation to form_b submission
// ============================================================
content = content.replace(
  `            \$totalRate = array_sum(\$rates);
            \$averageRating = round(\$totalRate / \$questionCount, 2);
            \$factorWeight = (float) \$category['factor_weight'];
            \$weightedScore = round(\$averageRating * (\$factorWeight / 100), 4);
            \$behavioralEvidence = '';
            \$reasonForRating = '';
            \$recommendation = '';
            \$aiSuggestion = '';
            \$aiDecision = 'none';
            \$requiredExplanation = 'none';`,
  `            \$totalRate = array_sum(\$rates);
            \$averageRating = round(\$totalRate / \$questionCount, 2);
            \$factorWeight = (float) \$category['factor_weight'];
            \$weightedScore = round(\$averageRating * (\$factorWeight / 100), 4);
            // Category-level validation: evidence required for low/high, optional for satisfactory
            \$catBehavioralEvidence = trim((string) (\$result['behavioral_evidence'] ?? ''));
            \$catRecommendation = trim((string) (\$result['recommendation'] ?? ''));
            if (\$averageRating >= 4.51 && \$catBehavioralEvidence === '') {
                throw new RuntimeException('Behavioral Evidence is required for the high rating in \\'' . \$category['title'] . '\\'.');
            }
            if (\$averageRating <= 3.00) {
                if (\$catBehavioralEvidence === '') {
                    throw new RuntimeException('Behavioral Evidence is required for the low rating in \\'' . \$category['title'] . '\\'.');
                }
                if (\$catRecommendation === '') {
                    throw new RuntimeException('Recommendation is required for the low rating in \\'' . \$category['title'] . '\\'.');
                }
            }
            \$behavioralEvidence = \$catBehavioralEvidence;
            \$reasonForRating = trim((string) (\$result['reason_for_rating'] ?? ''));
            \$recommendation = \$catRecommendation;
            \$aiSuggestion = '';
            \$aiDecision = 'none';
            \$requiredExplanation = 'none';`
);

// ============================================================
// CHANGE 14: Update dipascaf_validate_category_explanation for new logic
// ============================================================
content = content.replace(
  `function dipascaf_validate_category_explanation(
    string \$categoryTitle,
    float \$averageRating,
    string \$behavioralEvidence,
    string \$reasonForRating,
    string \$recommendation,
    string \$aiSuggestion,
    string \$aiDecision
): void {
    if (\$averageRating >= 4.51 && \$behavioralEvidence === '') {
        throw new RuntimeException('Behavioral Evidence is required for high ratings in ' . \$categoryTitle . '.');
    }

    if (\$averageRating <= 3.00) {
        if (\$behavioralEvidence === '') {
            throw new RuntimeException('Behavioral Evidence is required for low ratings in ' . \$categoryTitle . '.');
        }
        if (\$recommendation === '') {
            throw new RuntimeException('Recommendation is required for low ratings in ' . \$categoryTitle . '.');
        }
    }

    if (\$averageRating > 3.00 && \$averageRating < 4.51 && \$reasonForRating === '') {
        throw new RuntimeException('Reason for Rating is required for acceptable ratings in ' . \$categoryTitle . '.');
    }

    if (\$aiSuggestion !== '' && !in_array(\$aiDecision, ['accepted', 'edited'], true)) {
        throw new RuntimeException('Review and confirm the AI suggestion for ' . \$categoryTitle . ' before submitting.');
    }
}`,
  `function dipascaf_validate_category_explanation(
    string \$categoryTitle,
    float \$averageRating,
    string \$behavioralEvidence,
    string \$reasonForRating,
    string \$recommendation,
    string \$aiSuggestion,
    string \$aiDecision
): void {
    if (\$averageRating >= 4.51 && \$behavioralEvidence === '') {
        throw new RuntimeException('Behavioral Evidence is required for the high rating in ' . \$categoryTitle . '.');
    }

    if (\$averageRating <= 3.00) {
        if (\$behavioralEvidence === '') {
            throw new RuntimeException('Behavioral Evidence is required for the low rating in ' . \$categoryTitle . '.');
        }
        if (\$recommendation === '') {
            throw new RuntimeException('Recommendation is required for the low rating in ' . \$categoryTitle . '.');
        }
    }

    // For satisfactory ratings (3.01-4.50), evidence is optional
    // Only validate AI decision
    if (\$aiSuggestion !== '' && !in_array(\$aiDecision, ['accepted', 'edited'], true)) {
        throw new RuntimeException('Review and confirm the AI suggestion for ' . \$categoryTitle . ' before submitting.');
    }
}`
);

// ============================================================
// CHANGE 15: Remove old legacy behavioral evidence check in dipascaf_submit_evaluation
// ============================================================
content = content.replace(
  `    if ((in_array(1, \$scores, true) || in_array(5, \$scores, true)) && \$behavioralEvidence === '') {
        throw new RuntimeException('Behavioral evidence is required for very high or low ratings.');
    }`,
  `    // Category-level evidence validation is handled in the Form A/B submission functions`
);

fs.writeFileSync(file, content, 'utf8');
console.log('Changes applied to evaluation_cards.php');
