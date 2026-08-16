import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowDown, ArrowLeft, ArrowUp, CheckCircle2, ClipboardList, Eye, FileText,
  Loader2, Plus, Printer, RotateCcw, Save, Send, Settings2, Trash2, Unlock,
} from 'lucide-react';
import apiFetch from '../../data/api.js';
import { apiUrl } from '../../data/apiBase.js';
import { addToast } from '../common/Toast.jsx';
import { confirmProceed } from '../common/ConfirmationModal.jsx';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import PeriodSelector from './PeriodSelector.jsx';

const DEFAULT_TEMPLATE = {
  formCode: 'PMAS FORM 1',
  institution: 'NOTRE DAME OF MIDSAYAP COLLEGE',
  title: 'Goals Record Sheet',
  instructions: 'Complete this goal record sheet by formulating the work goals you intend to achieve within the rating period. Align the goals with departmental objectives and organizational directions.',
  minimumGoals: 1,
  totalWeight: 100,
  requireTotalWeight: true,
  sectionOrder: ['profile', 'instructions', 'goals', 'approval'],
  goalFields: [
    { key: 'keyResultArea', label: 'Key Result Area', type: 'text', required: true },
    { key: 'goalStatement', label: 'Goal Statement', type: 'textarea', required: true },
    { key: 'weight', label: 'Goal Weight', type: 'number', required: true },
  ],
  standardsTitle: 'Performance Standards',
  standardFields: [
    { key: 'exceptional', label: 'Exceptional', required: true },
    { key: 'exceeds', label: 'Exceeds Expectations', required: true },
    { key: 'meets', label: 'Meets Expectations', required: true },
    { key: 'meetsMost', label: 'Meets Most Expectations', required: true },
    { key: 'doesNotMeet', label: 'Does Not Meet Expectations', required: true },
  ],
  approval: {
    employeeSubmissionRequired: true,
    reviewerApprovalRequired: true,
    returnCommentRequired: true,
    reopenCommentRequired: true,
  },
};

const reviewStatuses = [
  { key: 'draft', label: 'Draft', icon: FileText },
  { key: 'submitted', label: 'Submitted', icon: Send },
  { key: 'under_review', label: 'Under Review', icon: Eye },
  { key: 'returned', label: 'Returned', icon: RotateCcw },
  { key: 'reopened', label: 'Reopened', icon: Unlock },
  { key: 'approved', label: 'Approved', icon: CheckCircle2 },
];

function normalizeTemplate(value) {
  const configuredGoalFields = Array.isArray(value?.goalFields) && value.goalFields.length
    ? value.goalFields
    : DEFAULT_TEMPLATE.goalFields;
  const legacyInstructions = 'Complete this goal record sheet by formulating at least five work goals that you intend to achieve within the rating period. Align the goals with departmental objectives and organizational directions.';
  return {
    ...DEFAULT_TEMPLATE,
    ...(value || {}),
    instructions: value?.instructions === legacyInstructions ? DEFAULT_TEMPLATE.instructions : (value?.instructions || DEFAULT_TEMPLATE.instructions),
    minimumGoals: 1,
    // Keep removed legacy fields out of saved templates and old snapshots.
    goalFields: configuredGoalFields.filter((field) => field?.key !== 'accomplishment'),
    standardFields: Array.isArray(value?.standardFields) && value.standardFields.length ? value.standardFields : DEFAULT_TEMPLATE.standardFields,
    approval: { ...DEFAULT_TEMPLATE.approval, ...(value?.approval || {}) },
  };
}

function emptyGoal(template = DEFAULT_TEMPLATE) {
  return {
    keyResultArea: '',
    goalStatement: '',
    weight: '',
    standards: Object.fromEntries(normalizeTemplate(template).standardFields.map((field) => [field.key, ''])),
  };
}

function ensureGoalBoxes(goals, template, minimumBoxes = 1) {
  const next = Array.isArray(goals) ? goals.map((goal) => ({
    ...emptyGoal(template),
    ...goal,
    standards: { ...emptyGoal(template).standards, ...(goal.standards || {}) },
  })) : [];
  while (next.length < Math.max(1, Number(minimumBoxes) || 1)) next.push(emptyGoal(template));
  return next;
}

function formatDate(value) {
  if (!value) return 'Not recorded';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

function statusLabel(status = '') {
  return String(status || 'draft').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function assignedReviewerRole(shown = {}) {
  if (shown.assigned_reviewer_role) return shown.assigned_reviewer_role;
  return String(shown.employee_role || '').toLowerCase() === 'dean' ? 'VPAA' : 'Dean';
}

function GoalsPaper({ shown, template, goals, editable, updateGoal, updateStandard, removeGoal }) {
  const totalWeight = goals.reduce((sum, goal) => sum + (Number(goal.weight) || 0), 0);
  const expectedWeight = Number(template.totalWeight || 100);
  const sectionOrder = Array.isArray(template.sectionOrder) ? template.sectionOrder : DEFAULT_TEMPLATE.sectionOrder;
  const sectionStyle = (key) => ({ order: sectionOrder.indexOf(key) >= 0 ? sectionOrder.indexOf(key) + 1 : 99 });
  const reviewerRole = assignedReviewerRole(shown);
  return (
    <div className="goals-record-paper">
      <header>
        <b>{template.formCode}</b>
        <div>
          <img src="/assets/images/ndmc-seal.png" alt="NDMC seal" />
          <span><h1>{template.institution}</h1><h2>{template.title}</h2></span>
        </div>
      </header>
      <div className="goals-paper-meta" style={sectionStyle('profile')}>
        <span>Name <strong>{shown.employee_name}</strong></span>
        <span>Appraisal Period <strong>{shown.appraisal_period}</strong></span>
        <span>Position Title <strong>{shown.position_title}</strong></span>
        <span>Department <strong>{shown.department}</strong></span>
      </div>
      <p className="goals-paper-instructions" style={sectionStyle('instructions')}>{template.instructions}</p>

      <section className="goals-paper-goals-section" style={sectionStyle('goals')}>
        {goals.map((goal, index) => (
          <article className="goal-paper-card" key={index}>
          <h3>Goal {index + 1}</h3>
          {template.goalFields.map((field) => {
            const value = goal[field.key] ?? '';
            const input = field.type === 'textarea'
              ? <textarea value={value} onChange={(event) => updateGoal?.(index, field.key, event.target.value)} />
              : <input type={field.type === 'number' ? 'number' : 'text'} min={field.key === 'weight' ? '0' : undefined} max={field.key === 'weight' ? String(template.totalWeight || 100) : undefined} value={value} onChange={(event) => updateGoal?.(index, field.key, event.target.value)} />;
            const display = field.key === 'weight' ? `${value || 0}%` : (value || 'Not provided');
            return (
              <label className={`goal-dynamic-field goal-field-${field.key}`} key={field.key}>
                <span>{field.label}{field.required && <em>Required</em>}</span>
                {editable ? input : <strong>{display}</strong>}
              </label>
            );
          })}
          <h4>{template.standardsTitle}</h4>
          {template.standardFields.map((field) => (
            <label className="goal-standard" key={field.key}>
              <span>{field.label}{field.required && <em>Required</em>}</span>
              {editable
                ? <input value={goal.standards?.[field.key] || ''} onChange={(event) => updateStandard?.(index, field.key, event.target.value)} />
                : <strong>{goal.standards?.[field.key] || 'Not provided'}</strong>}
            </label>
          ))}
          {editable && goals.length > 1 && (
            <button type="button" className="goal-remove no-print" onClick={() => removeGoal?.(index)}><Trash2 size={14} /> Remove goal</button>
          )}
          </article>
        ))}
        <div className={`goal-weight-total ${!template.requireTotalWeight || Math.abs(totalWeight - expectedWeight) < .001 ? 'valid' : 'invalid'}`}>
          Total Goal Weight <strong>{totalWeight}% / {expectedWeight}%</strong>
        </div>
      </section>
      <section className="goals-paper-approval-section" style={sectionStyle('approval')}>
        <p className="goal-agreement">We have agreed on these goals as parameters for the appraisal of job performance for the current appraisal period.</p>
        <div className="goal-approvals">
          <span><strong>{shown.employee_name}</strong>Employee · {formatDate(shown.submitted_at)}</span>
          <span><strong>{shown.reviewer_name || shown.assigned_reviewer_name || `Pending ${reviewerRole}`}</strong>{reviewerRole} · {formatDate(shown.reviewed_at)}</span>
        </div>
      </section>
    </div>
  );
}

function EvaluationReview({ record, template, comment, setComment, saving, onAction, onPrint }) {
  const mayDecide = ['submitted', 'under_review'].includes(record.status);
  const mayReopen = record.status === 'approved';
  const mayResubmit = record.status === 'reopened';
  return (
    <section className="goals-evaluation-review no-print" aria-label="Evaluation Review">
      <header>
        <div><span>Authorized reviewer workspace</span><h2>Evaluation Review</h2><p>Examine the submitted goals, reviewer comments, approval details, and current status before taking action.</p></div>
        <span className={`goals-table-status is-${record.status}`}>{statusLabel(record.status)}</span>
      </header>
      <div className="goals-review-detail-grid">
        <article><small>Employee submission</small><strong>{formatDate(record.submitted_at)}</strong></article>
        <article><small>{record.reviewer_name ? 'Last reviewer' : 'Assigned reviewer'}</small><strong>{record.reviewer_name || record.assigned_reviewer_name || 'No reviewer assigned yet'}</strong></article>
        <article><small>Last reviewed</small><strong>{formatDate(record.reviewed_at)}</strong></article>
        <article><small>Template version</small><strong>Version {record.template_version || 'Legacy'}</strong></article>
      </div>
      {record.review_comment && <div className="goals-prior-comment"><strong>Previous Review Comment</strong><p>{record.review_comment}</p></div>}
      {(mayDecide || mayReopen) && (
        <label className="goals-review-comment">
          Reviewer Comment {(mayDecide && template.approval.returnCommentRequired) || (mayReopen && template.approval.reopenCommentRequired) ? <em>Required for return/reopen</em> : null}
          <textarea value={comment} onChange={(event) => setComment(event.target.value)} placeholder="Add approval notes, revision instructions, or the reason for reopening this record." />
        </label>
      )}
      <footer>
        {mayDecide && <button type="button" className="return" disabled={!!saving} onClick={() => onAction('return')}><RotateCcw size={16} /> Return for Revision</button>}
        {mayDecide && <button type="button" className="approve" disabled={!!saving} onClick={() => onAction('approve')}><CheckCircle2 size={16} /> Approve Record</button>}
        {mayReopen && <button type="button" className="print" disabled={!!saving} onClick={onPrint}><Printer size={16} /> Print Approved Record</button>}
        {mayReopen && <button type="button" className="reopen" disabled={!!saving} onClick={() => onAction('reopen')}><Unlock size={16} /> Reopen Record</button>}
        {mayResubmit && <button type="button" className="approve" disabled={!!saving} onClick={() => onAction('resubmit')}><Send size={16} /> Re-submit for Review</button>}
      </footer>
    </section>
  );
}

export default function GoalsRecordSheet({ role, mode = 'employee', reviewPeriodId = '', reviewPeriod = null }) {
  const { selectedPeriodId, selectedPeriod } = useEvaluationPeriod();
  const effectivePeriodId = String(reviewPeriodId || selectedPeriodId || '');
  const effectivePeriod = reviewPeriod || selectedPeriod;
  const [template, setTemplate] = useState(DEFAULT_TEMPLATE);
  const [record, setRecord] = useState(null);
  const [records, setRecords] = useState([]);
  const [stats, setStats] = useState({});
  const [period, setPeriod] = useState({});
  const [goals, setGoals] = useState(() => ensureGoalBoxes([], DEFAULT_TEMPLATE));
  const [selected, setSelected] = useState(null);
  const [comment, setComment] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState('');
  const isEmployee = mode === 'employee';
  const isAdmin = mode === 'monitor';
  const goalsRef = useRef(goals);
  const dirtyRef = useRef(false);
  const autosaveTimerRef = useRef(null);
  const reviewerAutosaveTimerRef = useRef(null);
  const reviewerDirtyRef = useRef(false);
  const autosaveContextRef = useRef({ canEdit: false, periodId: '' });
  const reviewerContextRef = useRef({ canEdit: false, periodId: '', recordId: 0, goals: [] });
  const canEditRecord = isEmployee && (!record || ['draft', 'returned', 'reopened'].includes(record.status));
  const canEditSelectedReview = !isEmployee && !isAdmin && ['submitted', 'under_review', 'reopened'].includes(selected?.status);
  autosaveContextRef.current = { canEdit: canEditRecord, periodId: effectivePeriodId };
  reviewerContextRef.current = { canEdit: canEditSelectedReview, periodId: effectivePeriodId, recordId: Number(selected?.id || 0), goals: selected?.goals || [] };

  const load = useCallback(async () => {
    if (!effectivePeriodId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const query = new URLSearchParams({ period_id: effectivePeriodId, mode: isEmployee ? 'mine' : 'review' });
      const data = await apiFetch(`/api/goals-records.php?${query}`);
      const nextTemplate = normalizeTemplate(data.template);
      setTemplate(nextTemplate);
      setPeriod(data.period || {});
      if (isEmployee) {
        const nextGoals = ensureGoalBoxes(data.record?.goals || [], nextTemplate);
        setRecord(data.record || null);
        goalsRef.current = nextGoals;
        dirtyRef.current = false;
        setGoals(nextGoals);
      } else {
        setSelected(null);
        setRecords(data.records || []);
        setStats(data.stats || {});
      }
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to load Goals Record Sheets.' });
    } finally {
      setLoading(false);
    }
  }, [effectivePeriodId, isEmployee]);

  useEffect(() => { load(); }, [load]);

  const updateGoals = useCallback((updater) => {
    setGoals((current) => {
      const next = typeof updater === 'function' ? updater(current) : updater;
      goalsRef.current = next;
      dirtyRef.current = true;
      return next;
    });
  }, []);

  const autosaveDraft = useCallback(async () => {
    if (!canEditRecord || !effectivePeriodId || !dirtyRef.current) return;
    const snapshot = goalsRef.current;
    try {
      await apiFetch('/api/goals-records.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', period_id: effectivePeriodId, goals: snapshot }),
      });
      if (goalsRef.current === snapshot) dirtyRef.current = false;
    } catch {
      // Keep the draft marked dirty so the next change or page exit retries it.
    }
  }, [canEditRecord, effectivePeriodId]);

  useEffect(() => {
    if (!canEditRecord || !dirtyRef.current) return undefined;
    if (autosaveTimerRef.current) clearTimeout(autosaveTimerRef.current);
    autosaveTimerRef.current = setTimeout(() => {
      autosaveTimerRef.current = null;
      void autosaveDraft();
    }, 700);
    return () => {
      if (autosaveTimerRef.current) {
        clearTimeout(autosaveTimerRef.current);
        autosaveTimerRef.current = null;
      }
    };
  }, [goals, canEditRecord, autosaveDraft]);

  useEffect(() => {
    const flushDraft = () => {
      const { canEdit, periodId } = autosaveContextRef.current;
      if (canEdit && periodId && dirtyRef.current) {
        try {
          void fetch(apiUrl('/api/goals-records.php'), {
            method: 'POST',
            credentials: 'include',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', period_id: periodId, goals: goalsRef.current }),
          });
        } catch {
          // The normal debounced autosave remains the primary save path.
        }
      }
      const reviewerDraft = reviewerContextRef.current;
      if (reviewerDraft.canEdit && reviewerDraft.recordId && reviewerDirtyRef.current) {
        try {
          void fetch(apiUrl('/api/goals-records.php'), {
            method: 'POST',
            credentials: 'include',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reviewer_save', period_id: reviewerDraft.periodId, record_id: reviewerDraft.recordId, goals: reviewerDraft.goals }),
          });
        } catch {
          // The debounced reviewer save remains the primary save path.
        }
      }
    };
    window.addEventListener('pagehide', flushDraft);
    return () => {
      window.removeEventListener('pagehide', flushDraft);
      if (autosaveTimerRef.current) clearTimeout(autosaveTimerRef.current);
      if (reviewerAutosaveTimerRef.current) clearTimeout(reviewerAutosaveTimerRef.current);
      flushDraft();
    };
  }, []);

  useEffect(() => {
    if (!canEditSelectedReview || !reviewerDirtyRef.current) return undefined;
    if (reviewerAutosaveTimerRef.current) clearTimeout(reviewerAutosaveTimerRef.current);
    reviewerAutosaveTimerRef.current = setTimeout(() => {
      reviewerAutosaveTimerRef.current = null;
      void saveReviewerChanges(true);
    }, 700);
    return () => {
      if (reviewerAutosaveTimerRef.current) {
        clearTimeout(reviewerAutosaveTimerRef.current);
        reviewerAutosaveTimerRef.current = null;
      }
    };
  }, [selected?.goals, canEditSelectedReview]);

  function updateGoal(index, field, value) {
    updateGoals((current) => current.map((goal, goalIndex) => goalIndex === index ? { ...goal, [field]: value } : goal));
  }
  function updateStandard(index, key, value) {
    updateGoals((current) => current.map((goal, goalIndex) => goalIndex === index ? { ...goal, standards: { ...goal.standards, [key]: value } } : goal));
  }
  async function save(action) {
    if (autosaveTimerRef.current) {
      clearTimeout(autosaveTimerRef.current);
      autosaveTimerRef.current = null;
    }
    const snapshot = goalsRef.current;
    dirtyRef.current = false;
    setSaving(action);
    try {
      const data = await apiFetch('/api/goals-records.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, period_id: effectivePeriodId, goals: snapshot }),
      });
      addToast({ type: 'success', text: data.message });
      await load();
    } catch (error) {
      dirtyRef.current = true;
      addToast({ type: 'error', text: error.message });
    } finally {
      setSaving('');
    }
  }
  async function review(action) {
    if (action === 'reopen') {
      if (!comment.trim()) {
        addToast({ type: 'error', text: 'Enter a new reason before reopening this approved record.' });
        return;
      }
      const confirmed = await confirmProceed({
        message: 'This will remove the approved status and return the Goals Record Sheet for changes.',
        confirmText: 'Reopen Record',
      });
      if (!confirmed) return;
    }
    if (reviewerDirtyRef.current) {
      const saved = await saveReviewerChanges(true);
      if (!saved) return;
    }
    setSaving(action);
    try {
      const data = await apiFetch('/api/goals-records.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, period_id: effectivePeriodId, record_id: selected.id, comment }),
      });
      addToast({ type: 'success', text: data.message });
      setComment('');
      if (action === 'approve') {
        // Reload the canonical database record instead of leaving an optimistic
        // local status that can disagree with the saved review.
        reviewerDirtyRef.current = false;
        setSelected(null);
        await load();
      } else {
        setSelected(null);
        await load();
      }
    } catch (error) {
      addToast({ type: 'error', text: error.message });
    } finally {
      setSaving('');
    }
  }
  async function saveReviewerChanges(silent = false) {
    if (!selected?.id) return;
    const snapshot = selected.goals;
    reviewerDirtyRef.current = false;
    setSaving('reviewer_save');
    try {
      const data = await apiFetch('/api/goals-records.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reviewer_save', period_id: effectivePeriodId, record_id: selected.id, goals: selected.goals }),
      });
      setSelected((current) => ({ ...current, goals: data.goals || current.goals }));
      if (!silent) addToast({ type: 'success', text: data.message });
      return true;
    } catch (error) {
      if (selected?.goals === snapshot) reviewerDirtyRef.current = true;
      if (!silent) addToast({ type: 'error', text: error.message || 'Unable to save reviewer changes.' });
      return false;
    } finally {
      setSaving('');
    }
  }
  async function closeSelectedReview() {
    if (reviewerDirtyRef.current) {
      const saved = await saveReviewerChanges(true);
      if (!saved) {
        addToast({ type: 'error', text: 'Reviewer changes could not be saved. Please complete the required fields.' });
        return;
      }
    }
    reviewerDirtyRef.current = false;
    setSelected(null);
  }
  function downloadWord(shown) {
    const paper = document.querySelector('.goals-record-paper');
    if (!paper) return;
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial;color:#111}table{border-collapse:collapse;width:100%}.goal-paper-card{border:1px solid #111;margin:18px 0;padding:10px}.goal-standard,.goal-dynamic-field{display:block;border-top:1px solid #111;padding:6px}.goals-watermark{font-size:34px;color:#bbb;text-align:center}.goal-approvals{display:table;width:100%;margin-top:50px}.goal-approvals span{display:table-cell;width:50%;text-align:center;border-top:1px solid #111}</style></head><body>${paper.outerHTML}</body></html>`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([html], { type: 'application/msword' }));
    link.download = `${String(shown.employee_name || 'employee').replace(/[^a-z0-9]+/gi, '-')}-goals-record-sheet.doc`;
    link.click();
    URL.revokeObjectURL(link.href);
  }
  function printApprovedRecord() {
    const paper = document.querySelector('.goals-record-paper');
    if (!paper) return;
    const frame = document.createElement('iframe');
    frame.setAttribute('title', 'Print approved Goals Record Sheet');
    frame.style.position = 'fixed';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.border = '0';
    frame.style.opacity = '0';
    document.body.appendChild(frame);
    const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"], style'))
      .map((node) => node.outerHTML)
      .join('');
    const printOverrides = `<style>
      @page{size:A4 portrait;margin:12mm}
      html,body{margin:0!important;padding:0!important;background:#fff!important;overflow:visible!important}
      body *, .goals-record-paper, .goals-record-paper *{visibility:visible!important}
      .goals-record-paper{position:static!important;display:flex!important;width:100%!important;max-width:none!important;max-height:none!important;margin:0!important;padding:0!important;border:0!important;background:#fff!important;color:#111!important;box-shadow:none!important;transform:none!important;overflow:visible!important}
      .goal-paper-card{break-inside:avoid;page-break-inside:avoid}
      .no-print{display:none!important}
    </style>`;
    const doc = frame.contentDocument;
    doc.open();
    doc.write(`<!doctype html><html><head><base href="${document.baseURI}">${styles}${printOverrides}</head><body>${paper.outerHTML}</body></html>`);
    doc.close();
    const runPrint = async () => {
      try {
        await Promise.all(Array.from(doc.images).map((image) => image.complete ? Promise.resolve() : new Promise((resolve) => { image.onload = resolve; image.onerror = resolve; })));
        frame.contentWindow.onafterprint = () => frame.remove();
        frame.contentWindow.focus();
        frame.contentWindow.print();
      } finally {
        setTimeout(() => frame.remove(), 60000);
      }
    };
    setTimeout(() => void runPrint(), 350);
  }

  if (loading) return <section className="goals-record-page"><div className="empty-state"><Loader2 className="animate-spin" /> Loading Goals Record Sheet...</div></section>;
  if (!effectivePeriodId) return <section className="goals-record-page"><div className="empty-state">Select an evaluation period to open the Goals Record Sheet.</div></section>;

  if (!isEmployee && !selected) return (
    <section className="goals-record-page module-wide">
      <header className="goals-module-head">
        <div className="goals-module-copy"><i><ClipboardList size={23} /></i><div><span>{template.formCode}</span><h2>{isAdmin ? 'Goals Record Sheet Monitoring' : 'Goals Record Sheet Review'}</h2><p>{isAdmin ? 'Approved official records for the selected evaluation period.' : `Uses the same review period as Self-Evaluation Reviews${effectivePeriod?.period_name ? `: ${effectivePeriod.period_name}` : ''}.`}</p></div></div>
        <PeriodSelector compact />
      </header>
      <div className="goals-review-summary">
        {reviewStatuses.map(({ key, label, icon: StatusIcon }) => <article key={key} className={`goals-status-${key}`}><i><StatusIcon size={19} /></i><div><strong>{isAdmin ? (stats[key] || 0) : records.filter((item) => item.status === key).length}</strong><span>{label}</span></div></article>)}
      </div>
      <div className="self-eval-table-wrap"><table className="self-eval-table"><thead><tr><th>Employee</th><th>Position</th><th>Department</th><th>Period</th><th>Total Weight</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {records.length === 0 && <tr><td colSpan="7">No Goals Record Sheets are available.</td></tr>}
        {records.map((item) => <tr key={item.id}><td data-label="Employee">{item.employee_name}</td><td data-label="Position">{item.position_title}</td><td data-label="Department">{item.department}</td><td data-label="Period">{item.appraisal_period}</td><td data-label="Total Weight">{item.goals.reduce((sum, goal) => sum + (Number(goal.weight) || 0), 0)}%</td><td data-label="Status"><span className={`goals-table-status is-${item.status}`}>{statusLabel(item.status)}</span></td><td data-label="Action"><button type="button" onClick={() => { reviewerDirtyRef.current = false; setSelected(item); setComment(item.status === 'approved' ? '' : (item.review_comment || '')); }}><Eye size={15} /> Review</button></td></tr>)}
      </tbody></table></div>
    </section>
  );

  const shown = isEmployee
    ? { ...(record || {}), employee_name: role?.user?.name || role?.user?.full_name || 'Employee', position_title: role?.user?.positionTitle || role?.user?.position || role?.key || '', department: role?.user?.department || '', appraisal_period: period.school_year || period.period_name, goals }
    : selected;
  const shownTemplate = normalizeTemplate(!isEmployee && shown.templateSnapshot ? shown.templateSnapshot : template);
  const shownGoals = isEmployee ? goals : ensureGoalBoxes(shown.goals, shownTemplate);
  const reviewerCanEdit = canEditSelectedReview;
  const editable = canEditRecord || reviewerCanEdit;
  const updateDisplayedGoal = (index, field, value) => {
    if (isEmployee) return updateGoal(index, field, value);
    reviewerDirtyRef.current = true;
    setSelected((current) => ({ ...current, goals: ensureGoalBoxes(current.goals, shownTemplate).map((goal, goalIndex) => goalIndex === index ? { ...goal, [field]: value } : goal) }));
  };
  const updateDisplayedStandard = (index, key, value) => {
    if (isEmployee) return updateStandard(index, key, value);
    reviewerDirtyRef.current = true;
    setSelected((current) => ({ ...current, goals: ensureGoalBoxes(current.goals, shownTemplate).map((goal, goalIndex) => goalIndex === index ? { ...goal, standards: { ...goal.standards, [key]: value } } : goal) }));
  };
  const removeDisplayedGoal = (index) => {
    if (isEmployee) return updateGoals((current) => current.filter((_, goalIndex) => goalIndex !== index));
    reviewerDirtyRef.current = true;
    setSelected((current) => ({ ...current, goals: current.goals.filter((_, goalIndex) => goalIndex !== index) }));
  };
  const addDisplayedGoal = () => {
    if (isEmployee) {
      updateGoals((current) => [...current, emptyGoal(template)]);
      return;
    }
    reviewerDirtyRef.current = true;
    setSelected((current) => ({ ...current, goals: [...current.goals, emptyGoal(shownTemplate)] }));
  };

  return (
    <section className="goals-record-page module-wide">
      {!isEmployee && (
        <div className="goals-record-top-nav no-print">
          <button type="button" onClick={closeSelectedReview}><ArrowLeft size={16} /> Back to Records</button>
        </div>
      )}
      <GoalsPaper
        shown={shown}
        template={shownTemplate}
        goals={shownGoals}
        editable={editable}
        updateGoal={updateDisplayedGoal}
        updateStandard={updateDisplayedStandard}
        removeGoal={removeDisplayedGoal}
      />
      {!isEmployee && !isAdmin && <EvaluationReview record={shown} template={shownTemplate} comment={comment} setComment={setComment} saving={saving} onAction={review} onPrint={printApprovedRecord} />}
      <div className="goals-record-actions no-print">
        {editable && <>
          <button type="button" onClick={addDisplayedGoal}><Plus size={15} /> Add Another Goal</button>
          {isEmployee && <button type="button" className="primary-button" onClick={() => save('submit')} disabled={!!saving}><Send size={15} /> {['returned', 'reopened'].includes(record?.status) ? 'Re-submit' : 'Submit'}</button>}
          {reviewerCanEdit && <button type="button" className="primary-button" onClick={() => saveReviewerChanges(false)} disabled={!!saving}><Save size={15} /> Save Reviewer Changes</button>}
        </>}
        {shown.status === 'approved' && <>
          <button type="button" className="primary-button" onClick={printApprovedRecord}><Printer size={15} /> Print Approved Record</button>
          <button type="button" onClick={() => downloadWord(shown)}><FileText size={15} /> Word</button>
        </>}
        {!isEmployee && <button type="button" onClick={closeSelectedReview}>Back to Records</button>}
      </div>
    </section>
  );
}

export function GoalsRecordSheetPreview({ template: templateProp = DEFAULT_TEMPLATE }) {
  const template = normalizeTemplate(templateProp);
  const goals = ensureGoalBoxes([], template, template.minimumGoals).map((goal) => ({
    ...goal,
    keyResultArea: 'Employee response',
    goalStatement: 'Employee response',
    weight: Math.round(Number(template.totalWeight || 100) / Number(template.minimumGoals || 1)),
    standards: Object.fromEntries(template.standardFields.map((field) => [field.key, 'Employee-defined standard'])),
  }));
  const shown = { employee_name: 'Automatically filled', position_title: 'Automatically filled', department: 'Automatically filled', appraisal_period: 'Selected academic year', status: 'draft' };
  return <div className="goals-questionnaire-preview"><GoalsPaper shown={shown} template={template} goals={goals} editable={false} /></div>;
}

export function GoalsRecordTemplateManager() {
  const { selectedPeriodId } = useEvaluationPeriod();
  const [template, setTemplate] = useState(DEFAULT_TEMPLATE);
  const [version, setVersion] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const params = new URLSearchParams({ mode: 'template' });
        if (selectedPeriodId) params.set('period_id', selectedPeriodId);
        const data = await apiFetch(`/api/goals-records.php?${params}`);
        if (active) { setTemplate(normalizeTemplate(data.template)); setVersion(Number(data.version || 1)); }
      } catch (error) {
        addToast({ type: 'error', text: error.message || 'Unable to load the Goals Record Sheet template.' });
      } finally {
        if (active) setLoading(false);
      }
    })();
    return () => { active = false; };
  }, [selectedPeriodId]);

  function updateField(name, value) { setTemplate((current) => ({ ...current, [name]: value })); }
  function updateGoalField(index, patch) { setTemplate((current) => ({ ...current, goalFields: current.goalFields.map((field, fieldIndex) => fieldIndex === index ? { ...field, ...patch } : field) })); }
  function updateStandardField(index, patch) { setTemplate((current) => ({ ...current, standardFields: current.standardFields.map((field, fieldIndex) => fieldIndex === index ? { ...field, ...patch } : field) })); }
  function moveStandard(index, direction) {
    setTemplate((current) => {
      const target = index + direction;
      if (target < 0 || target >= current.standardFields.length) return current;
      const fields = [...current.standardFields];
      [fields[index], fields[target]] = [fields[target], fields[index]];
      return { ...current, standardFields: fields };
    });
  }
  function moveSection(index, direction) {
    setTemplate((current) => {
      const sections = [...(current.sectionOrder || DEFAULT_TEMPLATE.sectionOrder)];
      const target = index + direction;
      if (target < 0 || target >= sections.length) return current;
      [sections[index], sections[target]] = [sections[target], sections[index]];
      return { ...current, sectionOrder: sections };
    });
  }
  async function saveTemplate() {
    setSaving(true);
    try {
      const data = await apiFetch('/api/goals-records.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_template', template }),
      });
      setTemplate(normalizeTemplate(data.template));
      setVersion(Number(data.version || version + 1));
      addToast({ type: 'success', text: data.message });
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to save the template.' });
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <div className="empty-state"><Loader2 className="animate-spin" /> Loading Form 1 template...</div>;
  return (
    <div className="goals-template-manager">
      <section className="goals-template-editor">
        <header><div><span>Questionnaire Management · Version {version}</span><h2>Goals Record Sheet Form Builder</h2><p>Create, edit, arrange, preview, and publish the sections and approval requirements used by employees and reviewers.</p></div><Settings2 size={24} /></header>
        <div className="goals-template-grid">
          <label>Form Code<input value={template.formCode} onChange={(event) => updateField('formCode', event.target.value)} /></label>
          <label>Form Title<input value={template.title} onChange={(event) => updateField('title', event.target.value)} /></label>
          <label className="wide">Institution<input value={template.institution} onChange={(event) => updateField('institution', event.target.value)} /></label>
          <label className="wide">Instructions<textarea rows="4" value={template.instructions} onChange={(event) => updateField('instructions', event.target.value)} /></label>
          <label>Required Total Weight<input type="number" min="1" value={template.totalWeight} onChange={(event) => updateField('totalWeight', Number(event.target.value))} /></label>
        </div>

        <section className="goals-template-section">
          <header><div><span>Drag-free accessible ordering</span><h3>Form Sections</h3></div></header>
          {(template.sectionOrder || DEFAULT_TEMPLATE.sectionOrder).map((key, index) => (
            <div className="goals-template-row goals-template-section-row" key={key}>
              <span className="reorder"><button type="button" disabled={index === 0} onClick={() => moveSection(index, -1)}><ArrowUp size={14} /></button><button type="button" disabled={index === (template.sectionOrder || DEFAULT_TEMPLATE.sectionOrder).length - 1} onClick={() => moveSection(index, 1)}><ArrowDown size={14} /></button></span>
              <strong>{({ profile: 'Employee and Period Details', instructions: 'Instructions', goals: 'Goals, Fields, and Performance Standards', approval: 'Approval Requirements and Signatures' })[key]}</strong>
            </div>
          ))}
        </section>

        <section className="goals-template-section">
          <header><div><span>Repeating goal section</span><h3>Goal Fields and Questions</h3></div></header>
          {template.goalFields.map((field, index) => <div className="goals-template-row" key={field.key}><code>{field.key}</code><input aria-label={`${field.key} label`} value={field.label} onChange={(event) => updateGoalField(index, { label: event.target.value })} /><label><input type="checkbox" checked={field.required} onChange={(event) => updateGoalField(index, { required: event.target.checked })} /> Required</label></div>)}
        </section>

        <section className="goals-template-section">
          <header><div><span>Arrangeable fields</span><h3>Performance Standards</h3></div><button type="button" onClick={() => setTemplate((current) => ({ ...current, standardFields: [...current.standardFields, { key: `standard_${Date.now()}`, label: 'New Standard', required: false }] }))}><Plus size={14} /> Add Standard</button></header>
          <label className="goals-template-section-title">Section title<input value={template.standardsTitle} onChange={(event) => updateField('standardsTitle', event.target.value)} /></label>
          {template.standardFields.map((field, index) => <div className="goals-template-row" key={field.key}><span className="reorder"><button type="button" disabled={index === 0} onClick={() => moveStandard(index, -1)}><ArrowUp size={14} /></button><button type="button" disabled={index === template.standardFields.length - 1} onClick={() => moveStandard(index, 1)}><ArrowDown size={14} /></button></span><input aria-label={`Standard ${index + 1} label`} value={field.label} onChange={(event) => updateStandardField(index, { label: event.target.value })} /><label><input type="checkbox" checked={field.required} onChange={(event) => updateStandardField(index, { required: event.target.checked })} /> Required</label><button type="button" className="remove" disabled={template.standardFields.length === 1} onClick={() => setTemplate((current) => ({ ...current, standardFields: current.standardFields.filter((_, fieldIndex) => fieldIndex !== index) }))}><Trash2 size={14} /></button></div>)}
        </section>

        <section className="goals-template-section">
          <header><div><span>Workflow controls</span><h3>Approval Requirements</h3></div></header>
          <div className="goals-approval-options">
            <label><input type="checkbox" checked={template.requireTotalWeight} onChange={(event) => updateField('requireTotalWeight', event.target.checked)} /> Require exact total goal weight</label>
            {Object.entries({
              employeeSubmissionRequired: 'Require employee submission',
              reviewerApprovalRequired: 'Require authorized reviewer approval',
              returnCommentRequired: 'Require a comment when returning',
              reopenCommentRequired: 'Require a comment when reopening',
            }).map(([key, label]) => <label key={key}><input type="checkbox" checked={template.approval[key]} onChange={(event) => setTemplate((current) => ({ ...current, approval: { ...current.approval, [key]: event.target.checked } }))} /> {label}</label>)}
          </div>
        </section>
        <footer><button type="button" className="questionnaire-save" disabled={saving} onClick={saveTemplate}>{saving ? <Loader2 className="animate-spin" size={16} /> : <Save size={16} />} Save and Publish Form 1</button></footer>
      </section>
      <section className="goals-template-live-preview"><header><span>Live employee preview</span><h2>{template.title}</h2></header><GoalsRecordSheetPreview template={template} /></section>
    </div>
  );
}
