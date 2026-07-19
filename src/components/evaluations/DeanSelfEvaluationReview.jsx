import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, ArrowUp, Building2, CheckCircle2, ClipboardCheck, ClipboardList, Edit3, Eye, FileText, Loader2, RefreshCcw, RotateCcw, Save, Search, ShieldCheck, Trash2, Upload, X } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { addToast } from '../common/Toast.jsx';
import { confirmProceed } from '../common/ConfirmationModal.jsx';
import PeriodSelector from './PeriodSelector.jsx';
import SelfEvaluationModule from './SelfEvaluationModule.jsx';
import { assetUrl } from '../../data/apiBase.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';

const reviewRobotImage = assetUrl('assets/images/ROBOT 1.svg');

function displayStatus(row = {}, reviewer = 'Dean') {
  const review = String(row.review_status || row.dean_review_status || 'pending');
  if (review === 'approved') return `Approved by ${reviewer}`;
  if (review === 'reopened' || row.status === 'reopened') return `Reopened by ${reviewer}`;
  return `Pending ${reviewer} Review`;
}

function statusClass(label) {
  return label.toLowerCase().replaceAll(' ', '-');
}

function formatDate(value) {
  if (!value) return 'Not recorded';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function score(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number.toFixed(2) : 'Pending';
}

function collection(value) {
  return Array.isArray(value) ? value : [];
}

function objectEntries(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? Object.entries(value) : [];
}

const reviewCriterionLabels = {
  'self-overall-output': 'Overall Performance',
  'self-strengths': 'Strengths and Positive Contributions',
  'self-contribution': 'Future Contribution and Growth',
};

function reviewCriterionLabel(value) {
  const key = String(value || '');
  if (reviewCriterionLabels[key]) return reviewCriterionLabels[key];
  return key
    .replace(/^self[-_]/, '')
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function ratingDescriptor(value) {
  return ({ 5: 'Highly Evident', 4: 'Evident', 3: 'Moderately Evident', 2: 'Slightly Evident', 1: 'Not Evident' })[Number(value)] || 'Not rated';
}

function readReviewSignatureFile(file) {
  return new Promise((resolve, reject) => {
    if (!file?.type?.startsWith('image/')) {
      reject(new Error('Please upload an image file for the signature.'));
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      reject(new Error('Signature image must be 5MB or smaller.'));
      return;
    }

    const reader = new FileReader();
    reader.onerror = () => reject(new Error('Unable to read the signature image.'));
    reader.onload = () => {
      const image = new Image();
      image.onerror = () => reject(new Error('Unable to load the signature image.'));
      image.onload = () => {
        const maxWidth = 720;
        const maxHeight = 240;
        const scale = Math.min(1, maxWidth / image.width, maxHeight / image.height);
        const width = Math.max(1, Math.round(image.width * scale));
        const height = Math.max(1, Math.round(image.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);
        resolve({ dataUrl: canvas.toDataURL('image/jpeg', 0.82), name: file.name });
      };
      image.src = String(reader.result || '');
    };
    reader.readAsDataURL(file);
  });
}

function summaryCards(rows, reviewer, subjectPlural) {
  return [
    { label: subjectPlural, value: rows.length, icon: ClipboardList, tone: 'primary' },
    { label: `Pending ${reviewer} Review`, value: rows.filter((row) => displayStatus(row, reviewer) === `Pending ${reviewer} Review`).length, icon: FileText, tone: 'warning' },
    { label: `Approved by ${reviewer}`, value: rows.filter((row) => displayStatus(row, reviewer) === `Approved by ${reviewer}`).length, icon: ShieldCheck, tone: 'success' },
    { label: `Reopened by ${reviewer}`, value: rows.filter((row) => displayStatus(row, reviewer) === `Reopened by ${reviewer}`).length, icon: RotateCcw, tone: 'danger' },
  ];
}

export default function DeanSelfEvaluationReview({ role }) {
  const { selectedPeriodId, selectedPeriod } = useEvaluationPeriod();
  const roleKey = role?.key === 'programHead' ? 'program_head' : role?.key;
  const isVpaa = roleKey === 'vpaa';
  const isProgramHead = roleKey === 'program_head';
  const isDean = roleKey === 'dean';
  const reviewer = isVpaa ? 'VPAA' : isProgramHead ? 'Program Head' : 'Dean';
  const subject = isVpaa ? 'Dean' : isDean ? 'Employee' : 'Faculty';
  const targetRole = isVpaa ? 'dean' : 'faculty';
  const subjectPlural = isVpaa ? 'Dean Self Evaluations' : isProgramHead ? 'Faculty Self Evaluations under My Program' : 'Faculty and Program Head Self Evaluations';
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [filters, setFilters] = useState({ program: '', faculty: '', status: '' });
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [notes, setNotes] = useState('');
  const [savingNotes, setSavingNotes] = useState(false);
  const [savingSignature, setSavingSignature] = useState(false);
  const [acting, setActing] = useState('');
  const [reopenTarget, setReopenTarget] = useState(null);
  const [reopenReason, setReopenReason] = useState('');
  const [editTarget, setEditTarget] = useState(null);
  const reviewPanelRef = useRef(null);
  const searchInputRef = useRef(null);

  const loadRows = useCallback(async (background = false) => {
    if (background) setRefreshing(true);
    else setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ role: targetRole, _: String(Date.now()) });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`/api/self-evaluations.php?${params.toString()}`, { cache: 'no-store' });
      setRows(Array.isArray(payload.records) ? payload.records : []);
    } catch (err) {
      setError(err.message || 'Unable to load self evaluations for Dean review.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [selectedPeriodId, targetRole]);

  useEffect(() => {
    loadRows(false);
  }, [loadRows]);

  useEffect(() => {
    setFilters((current) => ({ ...current, program: '' }));
    setDetail(null);
  }, [selectedPeriodId]);

  const periodRows = useMemo(() => {
    const selectedName = String(selectedPeriod?.period_name || '').trim().toLowerCase();
    if (!selectedName) return rows;
    return rows.filter((row) => String(row.evaluation_period || row.cycle_name || '').trim().toLowerCase() === selectedName);
  }, [rows, selectedPeriod?.period_name]);

  const options = useMemo(() => ({
    programs: [...new Set(periodRows.map((row) => row.program_code).filter(Boolean))].sort(),
  }), [periodRows]);

  const filteredRows = useMemo(() => {
    const query = filters.faculty.trim().toLowerCase();
    return periodRows
      .filter((row) => String(row.status || '').toLowerCase() === 'submitted' || ['approved', 'reopened'].includes(String(row.review_status || '').toLowerCase()))
      .filter((row) => !filters.program || row.program_code === filters.program)
      .filter((row) => !filters.status || displayStatus(row, reviewer) === filters.status)
      .filter((row) => !query || [row.full_name, row.program_code, row.evaluation_period, row.performance_level].some((value) => String(value || '').toLowerCase().includes(query)));
  }, [filters, periodRows, reviewer]);

  const cards = summaryCards(periodRows, reviewer, subjectPlural);
  const activeRecord = detail?.record || null;
  const activeAnswers = activeRecord?.answers_json || {};
  const activeConfirmations = activeAnswers.confirmations || {};
  const activeStatus = activeRecord ? displayStatus(activeRecord, reviewer) : '';
  const canReview = activeRecord?.status === 'submitted' && activeRecord?.review_status !== 'approved';
  const signatureNameKey = isVpaa ? 'vpaaReviewer' : isDean ? 'deanReviewer' : 'appraiser';
  const signatureImageKey = isVpaa ? 'vpaaReviewerSignature' : isDean ? 'deanReviewerSignature' : 'appraiserSignature';
  const signatureFileNameKey = isVpaa ? 'vpaaReviewerSignatureName' : isDean ? 'deanReviewerSignatureName' : 'appraiserSignatureName';
  const appraiserName = activeConfirmations[signatureNameKey] || activeRecord?.reviewer_name || role?.user?.name || reviewer;
  const appraiserSignature = activeConfirmations[signatureImageKey] || '';
  const showReviewerSignature = (isProgramHead || isDean || isVpaa) && (canReview || appraiserSignature);

  async function openDetail(row) {
    setDetailLoading(true);
    try {
      const payload = await apiFetch(`/api/self-evaluations.php?role=${targetRole}&record_id=${row.id}&_=${Date.now()}`, { cache: 'no-store' });
      setDetail(payload);
      setNotes(payload.record?.review_notes || '');
    } catch (err) {
      addToast({ type: 'error', text: err.message || 'Unable to open Review Self Evaluation details.' });
    } finally {
      setDetailLoading(false);
    }
  }

  async function saveNotes() {
    if (!activeRecord) return;
    setSavingNotes(true);
    try {
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_review_notes', role: targetRole, record_id: activeRecord.id, review_notes: notes }),
      });
      addToast({ type: 'success', text: payload.message || 'Dean Review Notes saved.' });
      await openDetail(activeRecord);
      await loadRows(true);
    } catch (err) {
      addToast({ type: 'error', text: err.message || 'Unable to save Dean Review Notes.' });
    } finally {
      setSavingNotes(false);
    }
  }

  async function saveAppraiserSignature(signature) {
    if (!activeRecord) return;
    setSavingSignature(true);
    try {
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update_review_signature',
          role: targetRole,
          record_id: activeRecord.id,
          appraiser_name: appraiserName,
          appraiser_signature: signature.dataUrl,
          appraiser_signature_name: signature.name,
        }),
      });
      addToast({ type: 'success', text: payload.message || 'Appraiser signature saved.' });
      await openDetail(activeRecord);
      await loadRows(true);
    } catch (err) {
      addToast({ type: 'error', text: err.message || 'Unable to save appraiser signature.' });
    } finally {
      setSavingSignature(false);
    }
  }

  async function handleAppraiserSignatureUpload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    try {
      const signature = await readReviewSignatureFile(file);
      await saveAppraiserSignature(signature);
    } catch (err) {
      addToast({ type: 'error', text: err.message || 'Unable to upload appraiser signature.' });
    }
  }

  async function removeAppraiserSignature() {
    await saveAppraiserSignature({ dataUrl: '', name: '' });
  }

  async function approveEvaluation() {
    if (!activeRecord) return;
    if ((isProgramHead || isDean || isVpaa) && !appraiserSignature) {
      addToast({ type: 'error', text: `Upload the ${reviewer} reviewer signature before approving this ${subject.toLowerCase()} self evaluation.` });
      return;
    }
    const confirmed = await confirmProceed({
      title: 'Approve Evaluation?',
      message: `This will lock ${activeRecord.full_name || `this ${subject.toLowerCase()}`}'s self evaluation${isVpaa ? ' and record the VPAA approval' : ` and mark it ready for ${isDean ? 'Admin review' : 'Dean review'}`}.`,
      confirmText: 'Approve Evaluation',
    });
    if (!confirmed) return;
    setActing('approve');
    try {
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'approve', role: targetRole, record_id: activeRecord.id, review_notes: notes }),
      });
      addToast({ type: 'success', text: payload.message || 'Self evaluation approved.' });
      await openDetail(activeRecord);
      await loadRows(true);
    } catch (err) {
      addToast({ type: 'error', text: err.message || 'Unable to approve evaluation.' });
    } finally {
      setActing('');
    }
  }

  async function submitReopen() {
    const reason = reopenReason.trim();
    if (!reopenTarget || !reason) {
      addToast({ type: 'error', text: 'Revision Reason is required.' });
      return;
    }
    setActing('reopen');
    try {
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reopen', role: targetRole, record_id: reopenTarget.id, reason }),
      });
      addToast({ type: 'success', text: payload.message || 'Self evaluation reopened for faculty revision.' });
      setReopenTarget(null);
      setReopenReason('');
      if (activeRecord?.id === reopenTarget.id) {
        await openDetail(activeRecord);
      }
      await loadRows(true);
    } catch (err) {
      addToast({ type: 'error', text: err.message || 'Unable to reopen evaluation.' });
    } finally {
      setActing('');
    }
  }

  function updateFilter(name, value) {
    setFilters((current) => ({ ...current, [name]: value }));
  }

  function focusReviewPanel() {
    reviewPanelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.setTimeout(() => searchInputRef.current?.focus({ preventScroll: true }), 260);
  }

  return (
    <section className="dean-self-review module-wide page-enter">
      <div className="role-summary-header dean-self-review-head">
        <div className="dean-self-review-campus" aria-hidden="true" />
        <div className="dean-self-review-title">
          <span className="dean-self-review-building" aria-hidden="true">
            <Building2 size={28} strokeWidth={1.8} />
          </span>
          <div>
            <p className="eyebrow">{reviewer} Self Evaluation Review and Approval</p>
            <h2>Review {subject} Self Evaluation</h2>
            <p>{isVpaa ? 'Monitor and review submitted Dean self evaluations.' : isProgramHead ? 'Sign and approve completed faculty self evaluations under your assigned program.' : `Review completed Faculty and Program Head self evaluations in ${role?.user?.department || 'your department'}.`}</p>
            <div className="dean-self-review-actions">
              <PeriodSelector compact showRefresh={false} />
              <button type="button" className="evaluation-nav-btn secondary" onClick={() => loadRows(false)} disabled={loading || refreshing}>
                {refreshing ? <Loader2 size={15} className="animate-spin" /> : <RefreshCcw size={15} />} Refresh
              </button>
            </div>
            {refreshing && <span className="live-refresh-indicator compact">Refreshing review queue...</span>}
          </div>
        </div>
        <img className="dean-self-review-robot" src={reviewRobotImage} alt="" aria-hidden="true" />
      </div>

      <div className="dean-self-review-summary">
        {cards.map((item) => {
          const Icon = item.icon;
          return (
            <article key={item.label} className={`dean-self-review-card tone-${item.tone}`}>
              <span><Icon size={18} /></span>
              <div>
                <strong>{item.value}</strong>
                <small>{item.label}</small>
              </div>
            </article>
          );
        })}
      </div>

      <div className="dean-self-review-panel" ref={reviewPanelRef}>
        <div className="dean-self-review-toolbar">
          <label><Search size={15} /><input ref={searchInputRef} value={filters.faculty} onChange={(event) => updateFilter('faculty', event.target.value)} placeholder={isDean ? 'Search Faculty or Program Head name' : `Search ${subject.toLowerCase()} name`} /></label>
          <select value={filters.program} onChange={(event) => updateFilter('program', event.target.value)} aria-label="Filter by program">
            <option value="">All programs</option>
            {options.programs.map((program) => <option key={program} value={program}>{program}</option>)}
          </select>
          <select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)} aria-label="Filter by status">
            <option value="">All statuses</option>
            <option>{`Pending ${reviewer} Review`}</option>
            <option>{`Approved by ${reviewer}`}</option>
            <option>{`Reopened by ${reviewer}`}</option>
          </select>
        </div>

        {error && <div className="notice warning">{error}</div>}
        {loading ? (
          <div className="dean-self-review-skeleton">
            {[1, 2, 3].map((item) => <span key={item} />)}
          </div>
        ) : filteredRows.length === 0 ? (
          <div className="eval-monitor-empty">
            <ClipboardCheck size={28} />
            <strong>No self evaluations match the current review filters.</strong>
            <p>{isDean ? 'Submitted Program Head evaluations and Program Head-approved faculty evaluations in your department will appear here.' : `Submitted ${subject.toLowerCase()} self evaluations in your allowed scope will appear here for ${reviewer} review.`}</p>
          </div>
        ) : (
          <div className="dean-self-review-table-wrap">
            <table className="dean-self-review-table">
              <thead>
                <tr>
                  <th>{isDean ? 'Appraisee Name' : `${subject} Name`}</th>
                  <th>Program</th>
                  <th>Evaluation Period</th>
                  <th>Completion Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {filteredRows.map((row) => {
                  const label = displayStatus(row, reviewer);
                  return (
                    <tr key={row.id}>
                      <td data-label={`${subject} Name`}><strong>{row.full_name || `Unnamed ${subject.toLowerCase()}`}</strong><small>{row.role === 'program_head' ? 'Program Head' : (row.position_title || row.faculty_department || row.department)}</small></td>
                      <td data-label="Program">{row.program_code || 'Unassigned'}</td>
                      <td data-label="Evaluation Period">{row.evaluation_period || 'Current period'}</td>
                      <td data-label="Completion Date">{formatDate(row.submitted_at)}</td>
                      <td data-label="Status"><span className={`dean-self-status ${statusClass(label)}`}>{label}</span></td>
                      <td data-label="Action">
                        <button type="button" className="evaluation-nav-btn dean-self-review-action" onClick={() => openDetail(row)} disabled={detailLoading}>
                          <span className="dean-self-review-action-icon" aria-hidden="true"><Eye size={18} /></span>
                          <span className="dean-self-review-action-label">Review</span>
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="dean-self-review-fab" aria-label="Review page quick actions">
        <button type="button" onClick={focusReviewPanel} aria-label="Go to review filters">
          <ArrowUp size={18} />
        </button>
        <button type="button" onClick={() => loadRows(false)} disabled={loading || refreshing} aria-label="Refresh review page">
          {refreshing ? <Loader2 size={18} className="animate-spin" /> : <RefreshCcw size={18} />}
        </button>
      </div>

      {detailLoading && (
        <div className="dean-self-review-modal-backdrop">
          <section className="dean-self-review-modal compact"><Loader2 className="animate-spin" size={24} /> Loading Review Self Evaluation...</section>
        </div>
      )}

      {activeRecord && !detailLoading && (
        <div className="dean-self-review-modal-backdrop" onClick={(event) => event.target === event.currentTarget && setDetail(null)}>
          <section className="dean-self-review-modal" role="dialog" aria-modal="true" aria-labelledby="dean-self-review-modal-title">
            <header>
              <div>
                <p className="eyebrow">Review {subject} Self Evaluation</p>
                <h2 id="dean-self-review-modal-title">{activeRecord.full_name ? `${activeRecord.full_name} — ${subject} Self Evaluation` : `${subject} Self Evaluation`}</h2>
                <span className={`dean-self-status ${statusClass(activeStatus)}`}>{activeStatus}</span>
              </div>
              <button type="button" className="modal-icon-close" onClick={() => setDetail(null)} aria-label="Close Review Self Evaluation"><X size={18} /></button>
            </header>

            <div className="dean-self-review-modal-scroll">
              <div className="evaluation-form-meta self-eval-modern-meta">
              <div><span>Program</span><strong>{activeRecord.program_code || 'Unassigned'}</strong></div>
              <div><span>Department</span><strong>{activeRecord.faculty_department || activeRecord.department || 'Not set'}</strong></div>
              <div><span>Evaluation Period</span><strong>{activeRecord.evaluation_period || 'Current period'}</strong></div>
              <div><span>Completion Date</span><strong>{formatDate(activeRecord.submitted_at)}</strong></div>
              <div><span>Reviewer</span><strong>{activeRecord.reviewer_name || reviewer}</strong></div>
              <div><span>Review Date</span><strong>{formatDate(activeRecord.reviewed_at)}</strong></div>
              <div><span>Overall Rating</span><strong>{score(activeRecord.overall_rating)}</strong></div>
              <div><span>Level</span><strong>{activeRecord.performance_level || 'Pending'}</strong></div>
              </div>

              <div className="dean-self-review-modal-grid">
              <section className="dean-self-review-detail">
                <ReviewBlock title="Ratings and Behavioral Evidence">
                  {objectEntries(activeAnswers.selfRatings).length === 0 ? <p>No self rating entries are available.</p> : objectEntries(activeAnswers.selfRatings).map(([key, value]) => (
                    <div className="dean-self-review-mini-row" key={key}>
                      <span>{reviewCriterionLabel(key)}</span>
                      <div className="dean-self-review-rating"><strong>{value}</strong><small>{ratingDescriptor(value)}</small></div>
                    </div>
                  ))}
                  {objectEntries(activeAnswers.selfEvidence).map(([key, value]) => (
                    <div className="dean-self-review-evidence" key={key}><strong>{reviewCriterionLabel(key)}</strong><span>Behavioral Evidence</span><p>{value || 'No evidence provided.'}</p></div>
                  ))}
                </ReviewBlock>
                <ReviewBlock title="Goals and Accomplishments">
                  {collection(activeAnswers.achievedGoals).map((row, index) => <TextPair key={index} leftLabel="Goal" rightLabel="Accomplishment" left={row.goals} right={row.accomplishment} />)}
                  <p><strong>Other Accomplishments:</strong> {activeAnswers.otherAccomplishments || 'None recorded.'}</p>
                </ReviewBlock>
                <ReviewBlock title="Performance Outputs">
                  {collection(activeAnswers.performanceOutputs).map((row, index) => (
                    <div className="dean-self-review-output" key={index}>
                      <strong>{row.goals || 'Goal not specified'}</strong>
                      <p>{row.accomplishment || 'No accomplishment details.'}</p>
                      <div className="dean-self-review-output-meta"><span>Weight {row.weight || 0}%</span><span>Rating {row.rating || 'Pending'}</span></div>
                    </div>
                  ))}
                </ReviewBlock>
                <ReviewBlock title="Strengths, Areas for Improvement, and Faculty Goals">
                  <p><strong>Personal Strengths:</strong> {activeAnswers.personalStrengths || activeAnswers.appraiseeStrengths || 'None recorded.'}</p>
                  <p><strong>Areas for Improvement:</strong></p>
                  {collection(activeAnswers.improvementPlans).map((row, index) => (
                    <TextPair key={index} leftLabel="Development Area" rightLabel="Action Plan and Timeline" left={row.area} right={`${row.actionPlan || ''}${row.timeFrame ? ` (${row.timeFrame})` : ''}`} />
                  ))}
                  <p><strong>Further Contribution / Goals:</strong> {activeAnswers.furtherContribution || 'None recorded.'}</p>
                  <p><strong>Faculty Comments:</strong> {activeAnswers.comments || 'None recorded.'}</p>
                </ReviewBlock>
              </section>

              <aside className="dean-self-review-side">
                <div className="dean-self-review-side-head">
                  <span>Review Decision</span>
                  <strong>{activeRecord.full_name || 'Faculty Self Evaluation'}</strong>
                  <small>Review the evidence, add notes, sign, then approve or return for revision.</small>
                </div>
                <label className="dean-self-review-notes">{reviewer} Review Notes
                  <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={6} disabled={!canReview || savingNotes} placeholder="Add clarification remarks or review notes. Original faculty ratings remain unchanged." />
                </label>
                {showReviewerSignature && (
                  <div className={`dean-self-review-signature ${appraiserSignature ? 'has-signature' : ''} ${canReview && !appraiserSignature ? 'needs-signature' : ''}`}>
                    <div className="dean-self-review-signature-head">
                      <small>{reviewer} Verification</small>
                      <strong>Reviewer Signature</strong>
                      <span>{appraiserName || reviewer}</span>
                    </div>
                    <div className="dean-self-review-signature-preview">
                      {appraiserSignature ? (
                        <img src={appraiserSignature} alt={`${reviewer} reviewer signature`} />
                      ) : (
                        <span>Upload {reviewer} virtual signature</span>
                      )}
                    </div>
                    <div className="dean-self-review-signature-actions">
                      <label className={`evaluation-nav-btn secondary dean-self-review-signature-button ${!canReview || savingSignature ? 'disabled' : ''}`}>
                        {savingSignature ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}
                        <span>{appraiserSignature ? 'Replace Signature' : 'Upload Signature'}</span>
                        <input type="file" accept="image/*" onChange={handleAppraiserSignatureUpload} disabled={!canReview || savingSignature} />
                      </label>
                      {appraiserSignature && canReview && (
                        <button type="button" className="evaluation-nav-btn secondary dean-self-review-signature-button is-remove" onClick={removeAppraiserSignature} disabled={savingSignature}>
                          <Trash2 size={16} /> <span>Remove</span>
                        </button>
                      )}
                    </div>
                    {canReview && !appraiserSignature && <small className="dean-self-review-signature-required">Required before approval</small>}
                    {activeConfirmations[signatureFileNameKey] && <small className="dean-self-review-signature-file" title={activeConfirmations[signatureFileNameKey]}>{activeConfirmations[signatureFileNameKey]}</small>}
                  </div>
                )}
                <div className="dean-self-review-secondary-actions">
                  <button type="button" className="evaluation-nav-btn secondary" onClick={saveNotes} disabled={!canReview || savingNotes}>
                    {savingNotes ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} Save Review Notes
                  </button>
                  {(isProgramHead || isDean) && (
                    <button
                      type="button"
                      className="evaluation-nav-btn secondary"
                      onClick={() => {
                        setEditTarget(activeRecord);
                        setDetail(null);
                      }}
                      disabled={!canReview}
                    >
                      <Edit3 size={15} /> Edit Submitted Evaluation
                    </button>
                  )}
                </div>
                <div className="dean-self-review-decision-actions">
                  <button type="button" className="evaluation-nav-btn evaluation-submit-btn" onClick={approveEvaluation} disabled={!canReview || acting === 'approve'}>
                    {acting === 'approve' ? <Loader2 size={15} className="animate-spin" /> : <CheckCircle2 size={15} />} Approve Evaluation
                  </button>
                  <button type="button" className="evaluation-nav-btn secondary danger" onClick={() => setReopenTarget(activeRecord)} disabled={activeRecord.status !== 'submitted' || acting === 'reopen'}>
                    <RotateCcw size={15} /> Return for Revision
                  </button>
                </div>
                {activeRecord.reopened_reason && <div className="notice warning"><strong>Revision Reason</strong><p>{activeRecord.reopened_reason}</p></div>}
              </aside>
              </div>
            </div>
          </section>
        </div>
      )}

      {editTarget && createPortal((
        <div className="dipascaf-modal-backdrop self-eval-task-backdrop" onClick={(event) => event.target === event.currentTarget && setEditTarget(null)}>
          <div className="dipascaf-modal-panel eval-form-panel self-eval-task-panel managed-self-eval-editor" role="dialog" aria-modal="true" aria-labelledby="managed-self-eval-editor-title">
            <header className="managed-self-eval-editor-head">
              <div>
                <p>{reviewer} Edit Submitted Self Evaluation</p>
                <h2 id="managed-self-eval-editor-title">{editTarget.full_name || 'Faculty Member'}</h2>
                <span>{editTarget.evaluation_period || 'Current evaluation period'} · Changes remain submitted and are recorded.</span>
              </div>
              <button type="button" className="modal-icon-close" onClick={() => setEditTarget(null)} aria-label="Close self evaluation editor">
                <X size={20} />
              </button>
            </header>
            <div className="managed-self-eval-editor-body">
              <SelfEvaluationModule
                key={`${roleKey || 'reviewer'}-self-edit-${editTarget.id}`}
                role={role}
                initialTargetRole={editTarget.role === 'program_head' ? 'program_head' : 'faculty'}
                targetRoleOptions={[{ value: editTarget.role === 'program_head' ? 'program_head' : 'faculty', label: editTarget.role === 'program_head' ? 'Program Head Self Evaluation' : 'Faculty Self Evaluation' }]}
                assignmentId={editTarget.managed_assignment_id || editTarget.assignment_id}
                managedRecordId={editTarget.id}
                displayMode="managed"
                onSubmitted={async () => {
                  setEditTarget(null);
                  await loadRows(false);
                }}
              />
            </div>
          </div>
        </div>
      ), document.body)}

      {reopenTarget && (
        <div className="dean-self-review-modal-backdrop">
          <section className="dean-self-reopen-modal" role="dialog" aria-modal="true" aria-labelledby="dean-self-reopen-title">
            <header>
              <div>
                <p className="eyebrow">Reopen for Revision</p>
                <h2 id="dean-self-reopen-title">Revision Reason</h2>
              </div>
              <button type="button" className="modal-icon-close" onClick={() => setReopenTarget(null)} aria-label="Close reopen modal"><X size={18} /></button>
            </header>
            <div className="notice warning"><AlertTriangle size={16} /> This will return the evaluation to editable revision mode for {reopenTarget.full_name || `the ${subject.toLowerCase()}`}.</div>
            <label>Revision Reason
              <textarea value={reopenReason} onChange={(event) => setReopenReason(event.target.value)} rows={5} placeholder="State the required clarification or correction before faculty revision." />
            </label>
            <div className="dean-self-reopen-actions">
              <button type="button" className="evaluation-nav-btn secondary" onClick={() => setReopenTarget(null)}>Cancel</button>
              <button type="button" className="evaluation-nav-btn evaluation-submit-btn" onClick={submitReopen} disabled={acting === 'reopen' || !reopenReason.trim()}>
                {acting === 'reopen' ? <Loader2 size={15} className="animate-spin" /> : <RotateCcw size={15} />} Reopen for Revision
              </button>
            </div>
          </section>
        </div>
      )}
    </section>
  );
}

function ReviewBlock({ title, children }) {
  return (
    <article className="dean-self-review-block">
      <h3>{title}</h3>
      {children}
    </article>
  );
}

function TextPair({ left, right, leftLabel = 'Item', rightLabel = 'Details' }) {
  return (
    <div className="dean-self-review-text-pair">
      <div><span>{leftLabel}</span><p>{left || 'Not specified.'}</p></div>
      <div><span>{rightLabel}</span><p>{right || 'No details recorded.'}</p></div>
    </div>
  );
}
