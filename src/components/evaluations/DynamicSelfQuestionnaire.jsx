import { ArrowDown, ArrowUp, ChevronDown, ChevronRight, Copy, GripVertical, Plus, Save, Trash2 } from 'lucide-react';

const makeId = (prefix) => `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
const clone = (value) => JSON.parse(JSON.stringify(value));

export function DynamicQuestionnaireRenderer({ definition, answers = {}, onAnswer, disabled = false, preview = false, showApproval = false }) {
  const scales = Object.fromEntries((definition?.scales || []).map((scale) => [scale.id, scale]));
  const sections = (definition?.sections || []).filter((section) => section.visible !== false);
  const systemFields = {
    outputs: ['Goal / Performance Output', 'Weight', 'Actual Accomplishment', 'Rating', 'Weighted Score'],
    summary: ['Performance Outputs Score × 70%', 'Performance Factors Score × 30%', 'Overall Rating', 'Level of Performance'],
    confirmation: ['Comments', 'Printed Name', 'Virtual Signature', 'Confirmation Date'],
    career: ['Most Probable Next Job', 'Career Development Status', 'Development Period', 'Action Plan'],
  };
  return <div className="dynamic-questionnaire-renderer">
    {sections.map((section, sectionIndex) => <section key={section.id} className="dynamic-questionnaire-section">
      <header><span>{String(sectionIndex + 1).padStart(2, '0')}</span><div><h3>{section.title}</h3>{section.instructions && <p>{section.instructions}</p>}</div>{section.weight > 0 && <b>{section.weight}%</b>}</header>
      {section.type !== 'questions' ? <div className="dynamic-system-preview"><span>Protected system section</span><div>{(systemFields[section.type] || []).map((field) => <label key={field}>{field}<input disabled placeholder="Completed in the live evaluation form" /></label>)}</div></div> : <div className="dynamic-question-list">
        {(section.questions || []).map((question, questionIndex) => {
          const value = answers[question.id] ?? '';
          const scale = scales[question.ratingScaleId];
          return <article key={question.id} className="dynamic-question-card">
            <div className="dynamic-question-copy"><span>{questionIndex + 1}</span><div><strong>{question.text}{question.required && <em aria-label="required"> *</em>}</strong>{question.instructions && <small>{question.instructions}</small>}{question.category && <small className="dynamic-question-category">{question.category}</small>}</div></div>
            {question.type === 'rating' && <div className="dynamic-rating-options">{(scale?.options || []).map((option) => <button key={option.value} type="button" disabled={disabled} className={String(value) === String(option.value) ? 'selected' : ''} onClick={() => onAnswer?.(question.id, option.value)}><b>{option.value}</b><span>{option.label}</span></button>)}</div>}
            {question.type === 'short_text' && <input disabled={disabled} value={value} onChange={(e) => onAnswer?.(question.id, e.target.value)} placeholder={preview ? 'Short answer response' : 'Enter your response'} />}
            {question.type === 'long_text' && <textarea disabled={disabled} rows="4" value={value} onChange={(e) => onAnswer?.(question.id, e.target.value)} placeholder={preview ? 'Long answer response' : 'Enter your response'} />}
            {question.evidenceEnabled && <label className="dynamic-supporting-field"><span>Behavioral evidence{question.evidenceRequired && <em> *</em>}</span><textarea disabled={disabled} rows="3" value={answers[`${question.id}__evidence`] || ''} onChange={(e) => onAnswer?.(`${question.id}__evidence`, e.target.value)} placeholder={preview ? 'Supporting behavior, example, or outcome' : 'Describe the behavior, example, or outcome that supports this response'} /></label>}
            {question.commentsEnabled && <label className="dynamic-supporting-field"><span>Comments{question.commentsRequired && <em> *</em>}</span><textarea disabled={disabled} rows="3" value={answers[`${question.id}__comment`] || ''} onChange={(e) => onAnswer?.(`${question.id}__comment`, e.target.value)} placeholder={preview ? 'Employee or reviewer comments' : 'Add a comment'} /></label>}
          </article>;
        })}
      </div>}
    </section>)}
    {showApproval && <section className="dynamic-approval-preview">
      <header><div><span>Approval workflow</span><h3>Review and confirmation requirements</h3></div></header>
      <div>
        {(definition?.approvalRequirements?.reviewers || ['employee', 'dean']).map((reviewer) => <span key={reviewer}>{String(reviewer).replaceAll('_', ' ')}</span>)}
        {definition?.approvalRequirements?.requireEmployeeSignature !== false && <span>Employee signature</span>}
        {definition?.approvalRequirements?.requireReviewerComments && <span>Reviewer comments required</span>}
        {definition?.approvalRequirements?.allowReturn !== false && <span>Return for revision enabled</span>}
      </div>
    </section>}
  </div>;
}

function move(items, index, delta) {
  const next = [...items]; const target = index + delta;
  if (target < 0 || target >= next.length) return next;
  [next[index], next[target]] = [next[target], next[index]];
  return next;
}

export function DynamicQuestionnaireBuilder({ title, definition, revision, saving, onTitleChange, onChange, onSave }) {
  const update = (recipe) => { const next = clone(definition); recipe(next); onChange(next); };
  const scales = definition?.scales || [];
  const addSection = () => update((next) => next.sections.push({ id: makeId('sec'), type: 'questions', title: 'New Question Section', instructions: '', category: 'General', visible: true, required: true, protected: false, weight: 0, questions: [], isOpen: true }));
  const addScale = () => update((next) => next.scales.push({ id: makeId('scale'), name: 'New Rating Scale', options: [{ value: 1, label: 'Low' }, { value: 2, label: 'High' }] }));

  return <div className="dynamic-questionnaire-builder">
    <div className="dynamic-builder-hero"><div><span>Live questionnaire definition</span><input value={title} onChange={(e) => onTitleChange(e.target.value)} aria-label="Questionnaire title" /><p>Changes publish immediately when saved. Faculty forms and previews use this same definition.</p></div><div className="dynamic-builder-actions"><span>Revision {revision}</span><button type="button" onClick={addSection}><Plus size={15}/> Add Section</button><button type="button" className="primary" onClick={onSave} disabled={saving}><Save size={15}/> {saving ? 'Saving…' : 'Save & Publish'}</button></div></div>
    <div className="dynamic-definition-fields"><label>Description<textarea rows="2" value={definition.description || ''} onChange={(e) => update((next) => { next.description = e.target.value; })}/></label><label>Instructions<textarea rows="2" value={definition.instructions || ''} onChange={(e) => update((next) => { next.instructions = e.target.value; })}/></label></div>
    <section className="dynamic-approval-config">
      <header><div><h3>Approval Requirements</h3><p>Configure who confirms the completed evaluation and what reviewers must provide.</p></div></header>
      <div className="dynamic-approval-grid">
        <label>Required reviewers<select multiple value={definition.approvalRequirements?.reviewers || ['employee', 'dean']} onChange={(e) => update((next) => { next.approvalRequirements = { ...(next.approvalRequirements || {}), reviewers: Array.from(e.target.selectedOptions, (option) => option.value) }; })}><option value="employee">Employee</option><option value="dean">Dean</option><option value="vpaa">VPAA</option></select><small>Use Ctrl/Cmd to select more than one reviewer.</small></label>
        <div className="dynamic-approval-checks">
          <label><input type="checkbox" checked={definition.approvalRequirements?.requireEmployeeSignature !== false} onChange={(e) => update((next) => { next.approvalRequirements = { ...(next.approvalRequirements || {}), requireEmployeeSignature: e.target.checked }; })}/> Employee signature required</label>
          <label><input type="checkbox" checked={!!definition.approvalRequirements?.requireReviewerComments} onChange={(e) => update((next) => { next.approvalRequirements = { ...(next.approvalRequirements || {}), requireReviewerComments: e.target.checked }; })}/> Reviewer comments required</label>
          <label><input type="checkbox" checked={definition.approvalRequirements?.allowReturn !== false} onChange={(e) => update((next) => { next.approvalRequirements = { ...(next.approvalRequirements || {}), allowReturn: e.target.checked }; })}/> Allow return for revision</label>
        </div>
      </div>
    </section>

    <section className="dynamic-scales"><header><div><h3>Reusable Rating Scales</h3><p>Create named scales once and select them on rating questions.</p></div><button type="button" onClick={addScale}><Plus size={14}/> Add Scale</button></header>
      <div>{scales.map((scale, scaleIndex) => { const scaleUsed = (definition.sections || []).some((s) => (s.questions || []).some((q) => q.ratingScaleId === scale.id)); return <article key={scale.id}><div className="dynamic-scale-head"><input value={scale.name} onChange={(e) => update((next) => { next.scales[scaleIndex].name = e.target.value; })}/><button type="button" onClick={() => update((next) => next.scales.push({ ...clone(next.scales[scaleIndex]), id: makeId('scale'), name: `${scale.name} Copy` }))}><Copy size={14}/></button><button type="button" disabled={scaleUsed} onClick={() => update((next) => next.scales.splice(scaleIndex, 1))} title={scaleUsed ? 'This scale is currently used by a question' : 'Remove scale'}><Trash2 size={14}/></button></div>
        <div className="dynamic-scale-options">{scale.options.map((option, optionIndex) => <label key={`${scale.id}-${optionIndex}`}><input type="number" value={option.value} onChange={(e) => update((next) => { next.scales[scaleIndex].options[optionIndex].value = Number(e.target.value); })}/><input value={option.label} onChange={(e) => update((next) => { next.scales[scaleIndex].options[optionIndex].label = e.target.value; })}/><button type="button" onClick={() => update((next) => next.scales[scaleIndex].options.splice(optionIndex, 1))}><Trash2 size={12}/></button></label>)}<button type="button" onClick={() => update((next) => next.scales[scaleIndex].options.push({ value: next.scales[scaleIndex].options.length + 1, label: 'Option' }))}><Plus size={13}/> Option</button></div>
      </article>;})}</div>
    </section>

    <div className="dynamic-section-stack">{(definition.sections || []).map((section, sectionIndex) => {
      const setSection = (field, value) => update((next) => { next.sections[sectionIndex][field] = value; });
      const duplicateSection = () => update((next) => { const copy = clone(next.sections[sectionIndex]); copy.id = makeId('sec'); copy.title += ' Copy'; copy.protected = false; copy.type = 'questions'; copy.questions = (copy.questions || []).map((q) => ({ ...q, id: makeId('q') })); next.sections.splice(sectionIndex + 1, 0, copy); });
      return <section key={section.id} className={`dynamic-builder-section ${section.protected ? 'protected' : ''}`}>
        <header><GripVertical size={18}/><button type="button" className="collapse" onClick={() => setSection('isOpen', section.isOpen === false)}>{section.isOpen === false ? <ChevronRight size={17}/> : <ChevronDown size={17}/>}</button><div><input value={section.title} onChange={(e) => setSection('title', e.target.value)}/><span>{section.protected ? 'Protected system section' : 'Custom question section'}</span></div><label className="visibility"><input type="checkbox" checked={section.visible !== false} disabled={section.required} onChange={(e) => setSection('visible', e.target.checked)}/> Visible</label><button type="button" onClick={() => update((next) => { next.sections = move(next.sections, sectionIndex, -1); })} disabled={sectionIndex === 0}><ArrowUp size={14}/></button><button type="button" onClick={() => update((next) => { next.sections = move(next.sections, sectionIndex, 1); })} disabled={sectionIndex === definition.sections.length - 1}><ArrowDown size={14}/></button>{!section.protected && <><button type="button" onClick={duplicateSection}><Copy size={14}/></button><button type="button" onClick={() => update((next) => next.sections.splice(sectionIndex, 1))}><Trash2 size={14}/></button></>}</header>
        {section.isOpen !== false && <div className="dynamic-builder-section-body"><div className="dynamic-section-config"><label>Section instructions<textarea rows="2" value={section.instructions || ''} onChange={(e) => setSection('instructions', e.target.value)}/></label>{section.type === 'questions' && <><label>Category<input value={section.category || ''} onChange={(e) => setSection('category', e.target.value)}/></label><label>Rating weight<input type="number" min="0" max="100" value={section.weight || 0} onChange={(e) => setSection('weight', Number(e.target.value))}/></label></>}</div>
          {section.type === 'questions' && <div className="dynamic-question-editors">{(section.questions || []).map((question, questionIndex) => {
            const setQuestion = (field, value) => update((next) => { next.sections[sectionIndex].questions[questionIndex][field] = value; });
            return <article key={question.id}><div className="dynamic-question-toolbar"><GripVertical size={16}/><b>Question {questionIndex + 1}</b><button type="button" onClick={() => update((next) => { next.sections[sectionIndex].questions = move(next.sections[sectionIndex].questions, questionIndex, -1); })} disabled={questionIndex === 0}><ArrowUp size={13}/></button><button type="button" onClick={() => update((next) => { next.sections[sectionIndex].questions = move(next.sections[sectionIndex].questions, questionIndex, 1); })} disabled={questionIndex === section.questions.length - 1}><ArrowDown size={13}/></button><button type="button" onClick={() => update((next) => { const copy = { ...clone(next.sections[sectionIndex].questions[questionIndex]), id: makeId('q') }; next.sections[sectionIndex].questions.splice(questionIndex + 1, 0, copy); })}><Copy size={13}/></button><button type="button" onClick={() => update((next) => next.sections[sectionIndex].questions.splice(questionIndex, 1))}><Trash2 size={13}/></button></div><label>Question text<textarea rows="2" value={question.text} onChange={(e) => setQuestion('text', e.target.value)}/></label><div className="dynamic-question-config"><label>Response type<select value={question.type} onChange={(e) => setQuestion('type', e.target.value)}><option value="rating">Rating</option><option value="short_text">Short text</option><option value="long_text">Long text</option></select></label>{question.type === 'rating' && <label>Rating scale<select value={question.ratingScaleId || ''} onChange={(e) => setQuestion('ratingScaleId', e.target.value)}><option value="">Select scale</option>{scales.map((scale) => <option key={scale.id} value={scale.id}>{scale.name}</option>)}</select></label>}<label>Category<input value={question.category || ''} onChange={(e) => setQuestion('category', e.target.value)}/></label><label>Move to section<select value={section.id} onChange={(e) => { const targetId = e.target.value; if (targetId === section.id) return; update((next) => { const [item] = next.sections[sectionIndex].questions.splice(questionIndex, 1); next.sections.find((candidate) => candidate.id === targetId)?.questions.push(item); }); }}><option value={section.id}>{section.title}</option>{definition.sections.filter((candidate) => candidate.type === 'questions' && candidate.id !== section.id).map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.title}</option>)}</select></label><label className="required"><input type="checkbox" checked={!!question.required} onChange={(e) => setQuestion('required', e.target.checked)}/> Required</label></div><div className="dynamic-question-options"><label><input type="checkbox" checked={!!question.evidenceEnabled} onChange={(e) => setQuestion('evidenceEnabled', e.target.checked)}/> Behavioral evidence field</label><label><input type="checkbox" checked={!!question.evidenceRequired} disabled={!question.evidenceEnabled} onChange={(e) => setQuestion('evidenceRequired', e.target.checked)}/> Evidence required</label><label><input type="checkbox" checked={!!question.commentsEnabled} onChange={(e) => setQuestion('commentsEnabled', e.target.checked)}/> Comments field</label><label><input type="checkbox" checked={!!question.commentsRequired} disabled={!question.commentsEnabled} onChange={(e) => setQuestion('commentsRequired', e.target.checked)}/> Comments required</label></div><label>Instructions<input value={question.instructions || ''} onChange={(e) => setQuestion('instructions', e.target.value)}/></label></article>;
          })}<button type="button" className="dynamic-add-question" onClick={() => update((next) => next.sections[sectionIndex].questions.push({ id: makeId('q'), text: '', type: 'long_text', ratingScaleId: null, category: section.category || '', instructions: '', required: false }))}><Plus size={15}/> Add Question</button></div>}
        </div>}
      </section>;
    })}</div>
  </div>;
}
