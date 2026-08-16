import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, ArrowUp, Building2, CheckCircle2, ChevronLeft, ChevronRight, ClipboardCheck, ClipboardList, Download, Edit3, Eye, FileText, Loader2, Printer, RefreshCcw, RotateCcw, Save, Search, ShieldCheck, X } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { addToast } from '../common/Toast.jsx';
import { confirmProceed } from '../common/ConfirmationModal.jsx';
import PeriodSelector from './PeriodSelector.jsx';
import SelfEvaluationModule from './SelfEvaluationModule.jsx';
import GoalsRecordSheet from './GoalsRecordSheet.jsx';
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
  const [acting, setActing] = useState('');
  const [reopenTarget, setReopenTarget] = useState(null);
  const [reopenReason, setReopenReason] = useState('');
  const [editTarget, setEditTarget] = useState(null);
  const [reviewWorkspace, setReviewWorkspace] = useState('self');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(5);
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

  const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
  const pagedRows = useMemo(
    () => filteredRows.slice((page - 1) * pageSize, page * pageSize),
    [filteredRows, page, pageSize],
  );

  useEffect(() => {
    setPage(1);
  }, [selectedPeriodId, filters.faculty, filters.program, filters.status, pageSize]);

  useEffect(() => {
    setPage((current) => Math.min(current, pageCount));
  }, [pageCount]);

  const cards = summaryCards(periodRows, reviewer, subjectPlural);
  const activeRecord = detail?.record || null;
  const activeAnswers = activeRecord?.answers_json || {};
  const activeConfirmations = activeAnswers.confirmations || {};
  const activeStatus = activeRecord ? displayStatus(activeRecord, reviewer) : '';
  const canReview = activeRecord?.status === 'submitted' && activeRecord?.review_status !== 'approved';
  const signatureNameKey = isVpaa ? 'vpaaReviewer' : isDean ? 'deanReviewer' : 'appraiser';
  const appraiserName = activeConfirmations[signatureNameKey] || activeRecord?.reviewer_name || role?.user?.name || reviewer;

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

  async function approveEvaluation() {
    if (!activeRecord) return;
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

  if (reviewWorkspace === 'goals') {
    return (
      <section className="dean-self-review module-wide page-enter">
        <div className="self-review-workspace-tabs" role="tablist" aria-label={`${reviewer} review modules`}>
          <button type="button" role="tab" aria-selected="false" onClick={() => setReviewWorkspace('self')}><ClipboardList size={17} /><span>Self-Evaluation Reviews</span></button>
          <button type="button" role="tab" aria-selected="true" className="active" onClick={() => setReviewWorkspace('goals')}><FileText size={17} /><span>Goals Record Reviews</span></button>
        </div>
        <GoalsRecordSheet role={role} mode="review" reviewPeriodId={selectedPeriodId} reviewPeriod={selectedPeriod} />
      </section>
    );
  }

  function exportApprovedReview(format) {
    if (activeRecord?.review_status !== 'approved') {
      addToast({ type: 'error', text: `The ${reviewer} must approve this evaluation before it can be exported.` });
      return;
    }
    const paper = document.querySelector('.dean-self-review-modal .review-paper-form');
    if (!paper) return;
    const styles = `<style>
      @page{size:A4 portrait;margin:4mm}
      *{box-sizing:border-box}
      html,body{width:100%;height:auto}
      body{font-family:Arial,sans-serif;color:#111;margin:0;font-size:6.5pt;line-height:1.08;zoom:.74}
      h1,h2,h3,p{margin:1px 0}h1,h2,h3{text-align:center}h1{font-size:11pt}h2{font-size:9.5pt}h3{font-size:7.5pt}
      img{max-width:38px;max-height:38px}.self-eval-paper-head{margin-bottom:2px}.self-eval-school-brand{gap:4px}
      table{width:100%;border-collapse:collapse;margin:2px 0 3px;font-size:6pt;line-height:1.05;page-break-inside:auto}
      tr{page-break-inside:avoid}th,td{border:1px solid #111;padding:1.5px 2px;vertical-align:top}th{background:#eef8f3}
      td p,th p{margin:0}.paper-field{display:inline-block;width:49%;margin:1px 0}.paper-box{border:1px solid #111;min-height:16px;padding:2px;margin:1px 0 2px}
      .paper-section{page-break-inside:auto;margin-top:3px}.paper-section h3{text-align:left;border-bottom:1px solid #111;padding-bottom:1px}
      .self-eval-question{margin:2px 0 1px}.review-paper-rating{display:inline-block;border:1px solid #111;padding:1px 3px;margin:1px}
      .self-eval-paper-fields{gap:1px 5px}.review-paper-form{width:135.13%;max-width:none;padding:0;border:0;box-shadow:none;transform-origin:top left}
      .self-eval-helper,.paper-subtitle,small{font-size:5.8pt;line-height:1.05}.self-eval-approval-summary{padding:2px;margin:2px 0}
      .no-export,.no-print,button{display:none!important}
    </style>`;
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>${activeRecord.full_name || 'Self Evaluation'}</title>${styles}</head><body>${paper.outerHTML}</body></html>`;
    if (format === 'word') {
      const blob = new Blob([html], { type: 'application/msword' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = `${String(activeRecord.full_name || 'employee').replace(/[^a-z0-9]+/gi, '-')}-self-evaluation.doc`;
      link.click();
      URL.revokeObjectURL(link.href);
      return;
    }
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.border = '0';
    frame.style.opacity = '0';
    document.body.appendChild(frame);
    frame.contentDocument.open();
    frame.contentDocument.write(html);
    frame.contentDocument.close();
    window.setTimeout(async () => {
      await Promise.all(Array.from(frame.contentDocument.images).map((image) => image.complete
        ? Promise.resolve()
        : new Promise((resolve) => { image.onload = resolve; image.onerror = resolve; })));
      frame.contentWindow.onafterprint = () => frame.remove();
      frame.contentWindow.focus();
      frame.contentWindow.print();
      window.setTimeout(() => frame.remove(), 60000);
    }, 350);
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
      <div className="self-review-workspace-tabs self-review-workspace-tabs-below" role="tablist" aria-label={`${reviewer} review modules`}>
        <button type="button" role="tab" aria-selected="true" className="active" onClick={() => setReviewWorkspace('self')}><ClipboardList size={17} /><span>Self-Evaluation Reviews</span></button>
        <button type="button" role="tab" aria-selected="false" onClick={() => setReviewWorkspace('goals')}><FileText size={17} /><span>Goals Record Reviews</span></button>
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
            <p>{isDean ? 'Submitted Faculty and Program Head self evaluations in your department will appear here for direct Dean review.' : `Submitted ${subject.toLowerCase()} self evaluations in your allowed scope will appear here for ${reviewer} review.`}</p>
          </div>
        ) : (
          <>
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
                {pagedRows.map((row) => {
                  const label = displayStatus(row, reviewer);
                  return (
                    <tr key={row.id}>
                      <td data-label={`${subject} Name`}><strong>{row.full_name || `Unnamed ${subject.toLowerCase()}`}</strong><small>{row.role === 'program_head' ? 'Program Head' : `Faculty${row.position_title ? ` — ${row.position_title}` : ''}`}</small></td>
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
          <footer className="dean-self-review-pagination" aria-label="Self evaluation review pages">
            <span>
              Showing <strong>{(page - 1) * pageSize + 1}–{Math.min(page * pageSize, filteredRows.length)}</strong> of <strong>{filteredRows.length}</strong> records
            </span>
            <label>
              Rows per page
              <select value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))}>
                <option value={5}>5</option>
                <option value={10}>10</option>
                <option value={25}>25</option>
              </select>
            </label>
            <div className="dean-self-review-page-controls">
              <button type="button" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))} aria-label="Previous page"><ChevronLeft size={17} /><span>Previous</span></button>
              <span className="dean-self-review-page-count">Page <strong>{page}</strong> of <strong>{pageCount}</strong></span>
              <button type="button" disabled={page >= pageCount} onClick={() => setPage((current) => Math.min(pageCount, current + 1))} aria-label="Next page"><span>Next</span><ChevronRight size={17} /></button>
            </div>
          </footer>
          </>
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
                <ReviewPaperForm record={activeRecord} answers={activeAnswers} reviewer={reviewer} reviewerName={appraiserName} />
              </section>

              <aside className="dean-self-review-side">
                <div className="dean-self-review-side-head">
                  <span>Review Decision</span>
                  <strong>{activeRecord.full_name || 'Faculty Self Evaluation'}</strong>
                  <small>Review the completed paper form, add notes, then approve or return it for revision.</small>
                </div>
                <label className="dean-self-review-notes">{reviewer} Review Notes
                  <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={6} disabled={!canReview || savingNotes} placeholder="Add clarification remarks or review notes. Original faculty ratings remain unchanged." />
                </label>
                <div className="dean-self-review-signature has-signature">
                  <div className="dean-self-review-signature-head">
                    <small>{reviewer} Verification</small>
                    <strong>Printed Name of Reviewer</strong>
                    <span>{appraiserName || reviewer}</span>
                  </div>
                </div>
                {activeRecord.review_status === 'approved' && (
                  <div className="dean-self-review-secondary-actions">
                    <button type="button" className="evaluation-nav-btn secondary" onClick={() => exportApprovedReview('pdf')}><Printer size={15} /> Direct Print / PDF</button>
                    <button type="button" className="evaluation-nav-btn secondary" onClick={() => exportApprovedReview('word')}><Download size={15} /> Download Word</button>
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
                      <Edit3 size={15} /> Complete Part II Appraisal
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
                <p>{reviewer} Part II Evaluation and Scoring</p>
                <h2 id="managed-self-eval-editor-title">{editTarget.full_name || 'Faculty Member'}</h2>
                <span>{editTarget.evaluation_period || 'Current evaluation period'} · Complete Part II before approving the faculty record.</span>
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

function ReviewPaperForm({ record, answers, reviewer, reviewerName }) {
  const confirmations = answers.confirmations || {};
  const rows = (items, minimum = 1) => {
    const values = collection(items);
    return values.length ? values : Array.from({ length: minimum }, () => ({}));
  };
  const answer = (value) => String(value || '').trim() || 'Not provided';
  const weightedRating = (row) => {
    const scale = { E: 5, EE: 4, ME: 3, MM: 2, DE: 1 };
    return (((Number(row.weight) || 0) / 100) * (scale[row.rating] || Number(row.rating) || 0)).toFixed(4);
  };
  return (
    <div className="self-eval-paper self-eval-paper-form review-paper-form">
      <header className="self-eval-paper-head">
        <strong className="self-eval-form-code">{record.role === 'faculty' ? 'PMAS FORM 3b' : 'PMAS FORM 3a'}</strong>
        <div className="self-eval-school-brand">
          <img src="/assets/images/ndmc-seal.png" alt="" />
          <div><h1>NOTRE DAME OF MIDSAYAP COLLEGE</h1><h2>Performance Appraisal Sheet</h2><p>({record.role === 'faculty' ? 'Faculty' : 'Administrative'})</p></div>
        </div>
      </header>
      <div className="self-eval-paper-fields">
        <div className="paper-line-field"><span>Name</span><strong>{answer(record.full_name)}</strong></div>
        <div className="paper-line-field"><span>Appraisal Period</span><strong>{answer(record.evaluation_period)}</strong></div>
        <div className="paper-line-field wide"><span>Position Title</span><strong>{answer(record.position_title)}</strong></div>
        <div className="paper-line-field wide"><span>Department</span><strong>{answer(record.faculty_department || record.department)}</strong></div>
      </div>

      <section className="self-eval-section paper-section">
        <h3>Part I - Self-Evaluation</h3>
        <p className="paper-subtitle">(accomplished by employee to be appraised)</p>
        <h4>1. Goals achieved and significant accomplishments</h4>
        <table className="self-eval-table"><thead><tr><th>Goals</th><th>Actual Accomplishment</th></tr></thead><tbody>
          {rows(answers.achievedGoals).map((row, index) => <tr key={index}><td>{answer(row.goals)}</td><td>{answer(row.accomplishment)}</td></tr>)}
        </tbody></table>
        <div className="paper-answer-box"><span>Other Accomplishments Aside From Goals Achievement</span><div>{answer(answers.otherAccomplishments)}</div></div>
        <ReviewAnswer number="2" label="Goals that did not meet the agreed standards and reasons" value={answers.unmetGoalsReason} />
        <ReviewAnswer number="3" label="Personal strengths and their contribution to performance" value={answers.personalStrengths} />
        <ReviewAnswer number="4" label="Overall performance rating" value={`${answer(answers.overallSelfRating)} — ${answer(answers.ratingBasis)}`} />
        <ReviewAnswer number="5" label="Further contribution to the organization" value={answers.furtherContribution} />
      </section>

      <section className="self-eval-section paper-section">
        <h3>Part II - Performance Outputs Appraisal</h3>
        <p className="paper-subtitle">Degree of Achievement of Mutually Agreed Work Goals</p>
        <table className="self-eval-table"><thead><tr><th>Goals</th><th>Weight</th><th>Actual Accomplishment</th><th>Standard Met / Rating</th><th>Weighted Rating</th></tr></thead><tbody>
          {rows(answers.performanceOutputs).map((row, index) => <tr key={index}><td>{answer(row.goals)}</td><td>{row.weight || 0}%</td><td>{answer(row.accomplishment)}</td><td>{answer(row.rating)}</td><td>{weightedRating(row)}</td></tr>)}
        </tbody></table>
      </section>

      <section className="self-eval-section paper-section">
        <h3>Part III - Performance Factors</h3>
        <table className="self-eval-table"><thead><tr><th>Performance Factor</th><th>Self Rating</th><th>Behavioral Evidence</th></tr></thead><tbody>
          {objectEntries(answers.selfRatings).map(([key, value]) => <tr key={key}><td>{reviewCriterionLabel(key)}</td><td>{value} - {ratingDescriptor(value)}</td><td>{answer(answers.selfEvidence?.[key])}</td></tr>)}
          {objectEntries(answers.selfRatings).length === 0 && <tr><td colSpan="3">No performance-factor ratings recorded.</td></tr>}
        </tbody></table>
      </section>

      <section className="self-eval-section paper-section">
        <h3>Part IV - Summary</h3>
        <ReviewAnswer label="Appraisee's Strengths" value={answers.appraiseeStrengths || answers.personalStrengths} />
        <table className="self-eval-table"><thead><tr><th>Areas of Improvement</th><th>Action Plan</th><th>Time Frame</th></tr></thead><tbody>
          {rows(answers.improvementPlans).map((row, index) => <tr key={index}><td>{answer(row.area)}</td><td>{answer(row.actionPlan)}</td><td>{answer(row.timeFrame)}</td></tr>)}
        </tbody></table>
        <ReviewAnswer label="Appraisee's Comments on the Appraisal" value={answers.comments} />
        <table className="self-eval-table paper-signature-table"><thead><tr><th>Printed Name of Appraisee</th><th>Printed Name of Reviewer</th><th>Review Date</th></tr></thead><tbody><tr>
          <td>{answer(confirmations.appraisee || record.full_name)}</td><td>{answer(reviewerName || reviewer)}</td><td>{formatDate(record.reviewed_at)}</td>
        </tr></tbody></table>
      </section>
    </div>
  );
}

function ReviewAnswer({ number = '', label, value }) {
  return <div className="paper-answer-box"><span>{number ? `${number}. ${label}` : label}</span><div>{String(value || '').trim() || 'Not provided'}</div></div>;
}
