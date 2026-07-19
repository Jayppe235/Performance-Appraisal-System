import { useEffect, useState, useMemo, useCallback, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useLocation } from 'react-router-dom';
import {
  Building2, Users, ClipboardCheck, Clock, AlertTriangle,
  TrendingUp, TrendingDown, ChevronRight, ChevronLeft,
  BarChart3, Search, RefreshCw,
  X, Award, Lightbulb, Target,
  Eye, Brain, CheckCircle2, RotateCcw, Loader2,
} from 'lucide-react';
import apiFetch from '../../data/api.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import useLiveRefresh from '../../hooks/useLiveRefresh.js';
import PeriodSelector from './PeriodSelector.jsx';
import { addToast } from '../common/Toast.jsx';
import { confirmProceed } from '../common/ConfirmationModal.jsx';

const API_BASE = '/api/admin-evaluation-monitor.php';

// ─── Utility helpers ─────────────────────────────────────────────────
function statusBadge(status, value, title = '') {
  const styles = {
    completed: 'badge-success',
    pending: 'badge-warning',
    overdue: 'badge-danger',
    submitted: 'badge-success',
    reassigned: 'badge-warning',
    replaced: 'badge-warning',
    cancelled: 'badge-default',
    not_required: 'badge-info',
    tba: 'badge-default',
  };
  const labels = {
    completed: 'Completed',
    pending: 'Pending',
    overdue: 'Overdue',
    submitted: 'Submitted',
    reassigned: 'Reassigned',
    replaced: 'Replaced',
    cancelled: 'Cancelled',
    not_required: 'No Evaluation Required',
    tba: 'TBA',
  };
  return (
    <span className={`eval-monitor-badge ${styles[status] || 'badge-default'}`} title={title || undefined}>
      {value !== undefined ? `${labels[status] || status}: ${value}` : labels[status] || status}
    </span>
  );
}

function completionBar(pct) {
  const hue = pct >= 75 ? '#22c55e' : pct >= 40 ? '#eab308' : '#ef4444';
  return (
    <div className="eval-monitor-bar-wrapper">
      <div className="eval-monitor-bar">
        <span style={{ width: `${Math.min(pct, 100)}%`, background: hue }} />
      </div>
      <small>{pct}%</small>
    </div>
  );
}

function recommendationTone(status) {
  const value = String(status?.recommendation_status || status || '').toLowerCase();
  if (value === 'final') return 'final';
  if (value === 'interim') return 'interim';
  return 'preliminary';
}

function RecommendationStatusBanner({ status, compact = false }) {
  if (!status) return null;
  const tone = recommendationTone(status);
  const pct = Number(status.completion_percentage || 0);
  const pending = Number(status.pending_count || 0);
  const submitted = Number(status.submitted_count || 0);
  const total = Number(status.total_assigned || 0);
  const title = tone === 'final'
    ? 'Final Recommendation'
    : tone === 'interim'
      ? `Interim Recommendation - ${pct}% complete`
      : `Preliminary Recommendation - ${pct}% complete`;
  return (
    <div className={`ai-rec-status-banner ${tone} ${compact ? 'compact' : ''}`}>
      <div className="ai-rec-status-head">
        {tone === 'final' ? <ClipboardCheck size={15} /> : <AlertTriangle size={15} />}
        <strong>{title}</strong>
        <span>{submitted}/{total} submitted</span>
      </div>
      <div className="ai-rec-status-bar" aria-hidden="true">
        <span style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} />
      </div>
      {tone !== 'final' && (
        <details className="ai-rec-pending-details">
          <summary>{pending} pending evaluator{pending === 1 ? '' : 's'} may change this recommendation</summary>
          {(status.pending_evaluators || []).length > 0 ? (
            <div className="ai-rec-pending-list">
              {status.pending_evaluators.slice(0, 6).map((item, index) => (
                <div key={`${item.id || index}-${item.name}`} className={`ai-rec-pending-item ${item.overdue ? 'overdue' : ''}`}>
                  <span>{item.name || 'Evaluator'}</span>
                  <small>{item.role || 'Evaluator'}{item.deadline ? ` - Due ${formatDate(item.deadline)}` : ''}</small>
                  <button type="button" disabled>Reminder</button>
                </div>
              ))}
            </div>
          ) : (
            <p>Pending evaluator names are available in faculty drill-down.</p>
          )}
        </details>
      )}
    </div>
  );
}

function recommendedSessionForField(fieldName) {
  const key = String(fieldName || '').trim().toLowerCase();
  if (key.includes('communication')) return 'Communication skills and constructive feedback workshop';
  if (key.includes('classroom')) return 'Classroom management and learner engagement seminar';
  if (key.includes('job knowledge') || key.includes('quality')) return 'Job knowledge and work excellence mentoring';
  if (key.includes('leadership') || key.includes('management')) return 'Leadership planning and management coaching';
  if (key.includes('teamwork') || key.includes('interpersonal')) return 'Team collaboration and interpersonal sensitivity seminar';
  if (key.includes('initiative') || key.includes('resourcefulness') || key.includes('creativity')) return 'Innovation, initiative, and resourcefulness workshop';
  if (key.includes('institutional')) return 'Institutional commitment and values alignment session';
  if (key.includes('commitment') || key.includes('responsibility')) return 'Professional responsibility and job commitment coaching';
  return `Targeted professional development session for ${fieldName || 'the identified weak area'}`;
}

function programRecommendationSummary(fieldName) {
  const field = String(fieldName || '').trim();
  if (!field) return 'Review completed evaluation results before assigning a development activity.';
  return `Priority focus: ${field}. Suggested support: ${recommendedSessionForField(field)}.`;
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  try {
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function normalizeLookup(value) {
  return String(value || '').trim().toLowerCase();
}

function categoryEvaluatorKey(row) {
  return String(row?.evaluator_id || row?.evaluatorId || row?.assignment_id || row?.assignmentId || row?.id || row?.evaluator_name || row?.evaluatorName || '');
}

function categoryEvaluationType(row) {
  const explicit = String(row?.evaluation_type || '').trim();
  if (explicit) return explicit;
  const type = String(row?.assignment_type || row?.type || '').trim().toLowerCase();
  const role = String(row?.evaluator_role || row?.evaluatorRole || '').trim().toLowerCase();
  if (type === 'self') return 'Self-Assessment';
  if (type === 'peer') return 'Peer Evaluation';
  if (type === 'program_head' || role === 'program_head') return 'Program Head Evaluation';
  if (type === 'dean' || role === 'dean') return 'Dean Evaluation';
  if (type === 'vpaa' || role === 'vpaa') return 'VPAA Evaluation';
  return 'Evaluator Review';
}

function categoryEvaluatorLabel(row) {
  const name = String(row?.evaluator_name || row?.evaluatorName || '').trim();
  if (name && name.toLowerCase() !== 'evaluator') return name;
  const assignmentId = row?.assignment_id || row?.assignmentId || row?.id;
  if (assignmentId) return `Assignment #${assignmentId}`;
  return 'Submitted Evaluator';
}

function selfEvalStatusLabel(status) {
  const value = String(status || '').toLowerCase();
  if (value === 'submitted') return 'Submitted by Faculty';
  if (value === 'reopened') return 'Reopened for Faculty Revision';
  if (value === 'draft') return 'Faculty Draft';
  return status || 'Not submitted';
}

function deanReviewStatusLabel(status) {
  const value = String(status || '').toLowerCase();
  if (value === 'approved') return 'Approved by Dean';
  if (value === 'submitted_to_admin') return 'Submitted to Admin';
  if (value === 'reopened') return 'Reopened for Faculty Revision';
  return 'Pending Dean Review';
}

function adminReviewStatusLabel(status) {
  const value = String(status || '').toLowerCase();
  if (value === 'reviewed') return 'Reviewed by Admin';
  if (value === 'returned_to_dean') return 'Returned to Dean';
  if (value === 'pending') return 'Pending Admin Review';
  return 'Submitted to Admin';
}

function selfEvalBadgeClass(label) {
  return String(label || '').toLowerCase().replaceAll(' ', '-');
}

function selfEvalArray(value) {
  return Array.isArray(value) ? value : [];
}

function selfEvalObjectEntries(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? Object.entries(value) : [];
}

// ─── Loading skeleton ────────────────────────────────────────────────
function LoadingSkeleton() {
  return (
    <div className="eval-monitor-skeleton">
      {[1, 2, 3].map((i) => (
        <div key={i} className="eval-monitor-skeleton-card">
          <div className="skeleton-line w-24" />
          <div className="skeleton-line w-32" />
          <div className="skeleton-line w-full" />
        </div>
      ))}
    </div>
  );
}

function SelfEvaluationSubmissionSection({ submission, onUpdated }) {
  const [busyAction, setBusyAction] = useState('');
  const [returnReason, setReturnReason] = useState('');
  const [returnOpen, setReturnOpen] = useState(false);
  const answers = submission?.answers || {};
  const statusLabel = selfEvalStatusLabel(submission?.status);
  const deanLabel = deanReviewStatusLabel(submission?.dean_review_status);
  const adminLabel = adminReviewStatusLabel(submission?.admin_review_status);
  const canMarkReviewed = submission && submission.admin_review_status !== 'reviewed';
  const canReturn = Boolean(submission);

  async function markReviewed() {
    if (!submission?.id) return;
    const confirmed = await confirmProceed({
      title: 'Mark as Reviewed?',
      message: 'This will mark the Dean approved self evaluation as Reviewed by Admin.',
      confirmText: 'Mark as Reviewed',
    });
    if (!confirmed) return;
    setBusyAction('reviewed');
    try {
      const payload = await apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_self_evaluation_reviewed', record_id: submission.id }),
      });
      addToast({ type: 'success', text: payload.message || 'Self evaluation reviewed.' });
      onUpdated?.({ ...submission, admin_review_status: 'reviewed', admin_review_label: 'Reviewed by Admin', admin_reviewed_at: new Date().toISOString() });
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to mark self evaluation as reviewed.' });
    } finally {
      setBusyAction('');
    }
  }

  async function returnToDean() {
    const reason = returnReason.trim();
    if (!submission?.id || !reason) {
      addToast({ type: 'error', text: 'Return reason is required.' });
      return;
    }
    const confirmed = await confirmProceed({
      title: 'Return to Dean?',
      message: 'This will notify the Department Dean and record the return reason in the audit trail.',
      confirmText: 'Return to Dean',
    });
    if (!confirmed) return;
    setBusyAction('return');
    try {
      const payload = await apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'return_self_evaluation_to_dean', record_id: submission.id, reason }),
      });
      addToast({ type: 'success', text: payload.message || 'Self evaluation returned to Dean.' });
      setReturnOpen(false);
      setReturnReason('');
      onUpdated?.({ ...submission, admin_review_status: 'returned_to_dean', admin_review_label: 'Returned to Dean', admin_return_reason: reason, admin_reviewed_at: new Date().toISOString() });
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to return self evaluation to Dean.' });
    } finally {
      setBusyAction('');
    }
  }

  if (!submission) {
    return (
      <div className="eval-monitor-section self-eval-submission-section">
        <h4><ClipboardCheck size={16} /> Self Evaluation Submission</h4>
        <div className="eval-monitor-empty compact">No Dean approved self evaluation has been submitted to Admin for this faculty member.</div>
      </div>
    );
  }

  return (
    <div className="eval-monitor-section self-eval-submission-section">
      <div className="eval-monitor-section-head">
        <div>
          <h4><ClipboardCheck size={16} /> Self Evaluation Submission</h4>
          <span>Submitted After Dean Approval</span>
        </div>
        <div className="self-eval-admin-actions">
          <button type="button" className="eval-monitor-btn ghost compact" onClick={markReviewed} disabled={!canMarkReviewed || busyAction === 'reviewed'}>
            {busyAction === 'reviewed' ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />} Mark as Reviewed
          </button>
          <button type="button" className="eval-monitor-btn ghost compact danger" onClick={() => setReturnOpen((open) => !open)} disabled={!canReturn || busyAction === 'return'}>
            <RotateCcw size={14} /> Return to Dean
          </button>
        </div>
      </div>

      <div className="self-eval-admin-status-grid">
        <article><span>Status</span><strong className={`self-eval-admin-badge ${selfEvalBadgeClass(statusLabel)}`}>{statusLabel}</strong></article>
        <article><span>Dean Review Status</span><strong className={`self-eval-admin-badge ${selfEvalBadgeClass(deanLabel)}`}>{deanLabel}</strong></article>
        <article><span>Admin Review Status</span><strong className={`self-eval-admin-badge ${selfEvalBadgeClass(adminLabel)}`}>{adminLabel}</strong></article>
        <article><span>Submitted Date</span><strong>{formatDate(submission.submitted_at)}</strong></article>
        <article><span>Evaluation Period</span><strong>{submission.evaluation_period || 'Current period'}</strong></article>
        <article><span>Overall Rating</span><strong>{submission.overall_rating ? Number(submission.overall_rating).toFixed(2) : 'Pending'}</strong></article>
      </div>

      {returnOpen && (
        <div className="self-eval-return-panel">
          <label>Return Reason
            <textarea rows={4} value={returnReason} onChange={(event) => setReturnReason(event.target.value)} placeholder="Explain what the Dean needs to correct or clarify." />
          </label>
          <div>
            <button type="button" className="eval-monitor-btn ghost compact" onClick={() => setReturnOpen(false)}>Cancel</button>
            <button type="button" className="eval-monitor-btn compact" onClick={returnToDean} disabled={!returnReason.trim() || busyAction === 'return'}>
              {busyAction === 'return' ? <Loader2 size={14} className="animate-spin" /> : <RotateCcw size={14} />} Return to Dean
            </button>
          </div>
        </div>
      )}

      <details className="self-eval-admin-details" open>
        <summary>Overview</summary>
        <div className="self-eval-admin-copy-grid">
          <p><strong>Performance Level:</strong> {submission.performance_level || 'Pending'}</p>
          <p><strong>Faculty Comments:</strong> {answers.comments || 'No comments recorded.'}</p>
          <p><strong>Rating Basis:</strong> {answers.ratingBasis || 'No rating basis recorded.'}</p>
          <p><strong>Further Contribution / Faculty Goals:</strong> {answers.furtherContribution || 'No goals recorded.'}</p>
        </div>
      </details>

      <details className="self-eval-admin-details">
        <summary>Ratings, Behavioral Evidence, and Goals</summary>
        <div className="self-eval-admin-detail-stack">
          {selfEvalObjectEntries(answers.selfRatings).length > 0 && (
            <div className="self-eval-admin-mini-grid">
              {selfEvalObjectEntries(answers.selfRatings).map(([key, value]) => (
                <article key={key}><span>{key}</span><strong>{value}</strong></article>
              ))}
            </div>
          )}
          {selfEvalObjectEntries(answers.selfEvidence).map(([key, value]) => (
            <article className="self-eval-admin-evidence" key={key}>
              <strong>{key}</strong>
              <p>{value || 'No behavioral evidence recorded.'}</p>
            </article>
          ))}
          {selfEvalArray(answers.achievedGoals).map((row, index) => (
            <article className="self-eval-admin-evidence" key={`goal-${index}`}>
              <strong>{row.goals || 'Goal not specified'}</strong>
              <p>{row.accomplishment || 'No accomplishment details recorded.'}</p>
            </article>
          ))}
        </div>
      </details>

      <details className="self-eval-admin-details">
        <summary>Strengths and Areas for Improvement</summary>
        <div className="self-eval-admin-detail-stack">
          <p><strong>Strengths:</strong> {answers.personalStrengths || answers.appraiseeStrengths || 'No strengths recorded.'}</p>
          {selfEvalArray(answers.improvementPlans).map((row, index) => (
            <article className="self-eval-admin-evidence" key={`improvement-${index}`}>
              <strong>{row.area || 'Area not specified'}</strong>
              <p>{row.actionPlan || 'No action plan recorded.'}{row.timeFrame ? ` (${row.timeFrame})` : ''}</p>
            </article>
          ))}
        </div>
      </details>

      <details className="self-eval-admin-details" open>
        <summary>Dean Review Notes</summary>
        <div className="self-eval-admin-copy-grid">
          <p><strong>Dean Reviewer:</strong> {submission.dean_reviewer_name || 'Not recorded'}</p>
          <p><strong>Dean Approval Date:</strong> {formatDate(submission.dean_reviewed_at)}</p>
          <p><strong>Dean Review Notes:</strong> {submission.dean_review_notes || 'No Dean review notes recorded.'}</p>
          {submission.admin_return_reason && <p><strong>Return Reason:</strong> {submission.admin_return_reason}</p>}
        </div>
      </details>

      <details className="self-eval-admin-details">
        <summary>Evaluation Audit Trail</summary>
        <div className="self-eval-admin-audit">
          {(submission.audit_logs || []).length === 0 ? <p>No audit trail entries recorded.</p> : (submission.audit_logs || []).map((log) => (
            <article key={log.id}>
              <strong>{String(log.action_type || '').replaceAll('_', ' ')}</strong>
              <span>{log.actor_name || log.user_role || 'System'} • {formatDate(log.created_at)}</span>
              {(log.remarks || log.new_value) && <p>{log.remarks || log.new_value}</p>}
            </article>
          ))}
        </div>
      </details>
    </div>
  );
}

// ─── Faculty Detail Modal ─────────────────────────────────────────────
function FacultyDetailModal({ faculty, onClose }) {
  const modalRef = useRef(null);
  const modalBodyRef = useRef(null);
  const onCloseRef = useRef(onClose);
  const safeFaculty = faculty || {};
  const avgScore = safeFaculty.average_score;
  const strengths = safeFaculty.strengths || [];
  const weaknesses = safeFaculty.weaknesses || [];
  const assignments = safeFaculty.evaluator_assignments || [];
  const insights = safeFaculty.ai_insights || [];
  const interventions = safeFaculty.interventions || [];
  const results = safeFaculty.category_results || [];
  const [selfEvaluationSubmission, setSelfEvaluationSubmission] = useState(safeFaculty.self_evaluation_submission || null);
  const [categoryFilter, setCategoryFilter] = useState('all');
  const [selectedCategoryIndex, setSelectedCategoryIndex] = useState(0);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    const previouslyFocused = document.activeElement;
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onCloseRef.current?.();
    };

    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', handleKeyDown);
    window.requestAnimationFrame(() => {
      if (modalRef.current) modalRef.current.scrollTop = 0;
      if (modalBodyRef.current) modalBodyRef.current.scrollTop = 0;
      modalRef.current?.focus?.({ preventScroll: true });
    });

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
      previouslyFocused?.focus?.({ preventScroll: true });
    };
  }, [faculty?.id]);

  const assignmentLookup = useMemo(() => {
    const lookup = new Map();
    assignments.forEach((assignment) => {
      const id = String(assignment.id || '');
      if (!id) return;
      lookup.set(id, assignment);
    });
    return lookup;
  }, [assignments]);

  const categoryRows = useMemo(() => (
    results.map((row) => {
      const assignment = assignmentLookup.get(String(row.assignment_id || ''));
      if (!assignment) return row;
      return {
        ...row,
        evaluator_name: row.evaluator_name || assignment.evaluator_name,
        evaluator_role: row.evaluator_role || assignment.evaluator_role,
        assignment_type: row.assignment_type || assignment.type,
        evaluation_type: row.evaluation_type || categoryEvaluationType(assignment),
      };
    })
  ), [results, assignmentLookup]);

  const categoryFilterOptions = useMemo(() => {
    const options = [{ value: 'all', label: 'All Evaluators' }];
    const evaluatorMap = new Map();
    categoryRows.forEach((row) => {
      const key = categoryEvaluatorKey(row);
      if (!key || evaluatorMap.has(key)) return;
      evaluatorMap.set(key, {
        value: `evaluator:${key}`,
        label: categoryEvaluatorLabel(row),
      });
    });

    return [...options, ...evaluatorMap.values()];
  }, [categoryRows]);

  const filteredCategoryResults = useMemo(() => {
    if (!categoryFilter || categoryFilter === 'all') return categoryRows;
    if (categoryFilter.startsWith('evaluator:')) {
      const evaluatorKey = categoryFilter.slice(10);
      return categoryRows.filter((row) => categoryEvaluatorKey(row) === evaluatorKey);
    }
    return categoryRows;
  }, [categoryRows, categoryFilter]);

  const selectedCategory = filteredCategoryResults[selectedCategoryIndex] || filteredCategoryResults[0] || null;
  const visibleStrengths = useMemo(() => (
    filteredCategoryResults
      .filter((row) => Number(row.score || 0) >= 4)
      .sort((a, b) => Number(b.score || 0) - Number(a.score || 0))
      .map((row) => ({
        category: row.category,
        score: Number(row.score || 0),
        evaluator: row.evaluator_name || '',
        type: categoryEvaluationType(row),
      }))
  ), [filteredCategoryResults]);
  const visibleWeaknesses = useMemo(() => (
    filteredCategoryResults
      .filter((row) => Number(row.score || 0) <= 3 && Number(row.score || 0) > 0)
      .sort((a, b) => Number(a.score || 0) - Number(b.score || 0))
      .map((row) => ({
        category: row.category,
        score: Number(row.score || 0),
        recommendation: row.recommendation || row.reason_for_rating || '',
        evaluator: row.evaluator_name || '',
        type: categoryEvaluationType(row),
      }))
  ), [filteredCategoryResults]);

  useEffect(() => {
    setSelectedCategoryIndex(0);
  }, [categoryFilter, faculty?.id]);

  useEffect(() => {
    setSelfEvaluationSubmission(faculty?.self_evaluation_submission || null);
  }, [faculty?.id, faculty?.self_evaluation_submission]);

  if (!faculty) return null;

  const modal = (
    <div className="eval-monitor-modal-backdrop" onClick={onClose}>
      <div className="eval-monitor-modal" ref={modalRef} tabIndex={-1} onClick={(e) => e.stopPropagation()}>
        <div className="eval-monitor-modal-header">
          <div>
            <h3>{faculty.full_name}</h3>
            <p>{faculty.position_title} • {faculty.department} / {faculty.program_code}</p>
          </div>
          <button type="button" className="eval-monitor-icon-btn" onClick={onClose}>
            <X size={20} />
          </button>
        </div>

        <div className="eval-monitor-modal-body" ref={modalBodyRef}>
          {/* Score Overview */}
          <div className="eval-monitor-faculty-score">
            <div className="eval-monitor-score-ring" style={{ '--score': avgScore ? `${(avgScore / 5) * 100}%` : '0%' }}>
              <strong>{avgScore || '—'}</strong>
              <span>/5.0</span>
            </div>
            <div className="eval-monitor-faculty-stats">
              <span><Users size={14} /> {faculty.total_assignments} Assignments</span>
              <span><ClipboardCheck size={14} /> {faculty.completed_evaluations} Completed</span>
              <span><Clock size={14} /> {faculty.pending_evaluations} Pending</span>
            </div>
          </div>
          <RecommendationStatusBanner status={faculty.recommendation_status} />

          <SelfEvaluationSubmissionSection
            submission={selfEvaluationSubmission}
            onUpdated={setSelfEvaluationSubmission}
          />

          {/* Category Results */}
          {results.length > 0 && (
            <div className="eval-monitor-section">
              <div className="eval-monitor-section-head eval-monitor-category-toolbar">
                <div>
                  <h4><BarChart3 size={16} /> Category Scores</h4>
                  <span>Click a category to inspect details</span>
                </div>
                <label className="eval-monitor-category-filter">
                  <span>View by evaluator</span>
                  <select value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)}>
                    {categoryFilterOptions.map((option) => (
                      <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                  </select>
                </label>
              </div>
              {filteredCategoryResults.length === 0 ? (
                <div className="eval-monitor-empty">
                  <ClipboardCheck size={28} />
                  <strong>No category scores found</strong>
                  <p>No submitted category scores are available for the selected evaluator filter.</p>
                </div>
              ) : (
                <div className="eval-monitor-category-grid">
                {filteredCategoryResults.map((r, i) => (
                  <button
                    key={`${r.category}-${i}`}
                    type="button"
                    className={`eval-monitor-category-card ${selectedCategoryIndex === i ? 'is-selected' : ''}`}
                    onClick={() => setSelectedCategoryIndex(i)}
                  >
                    <div className="eval-monitor-category-head">
                      <strong>{r.category}</strong>
                      <span className={r.score >= 4 ? 'high' : r.score >= 3 ? 'mid' : 'low'}>
                        {r.score}
                      </span>
                    </div>
                    <div className="eval-monitor-category-meta">
                      <span>{categoryEvaluationType(r)}</span>
                      <small>{categoryEvaluatorLabel(r)}{r.form ? ` - ${r.form}` : ''}</small>
                    </div>
                    {completionBar(r.score * 20)}
                    {r.reason_for_rating && (
                      <small className="eval-monitor-evidence"><strong>Comment:</strong> {r.reason_for_rating}</small>
                    )}
                    {r.behavioral_evidence && (
                      <small className="eval-monitor-evidence"><strong>Evidence:</strong> {r.behavioral_evidence}</small>
                    )}
                    {r.recommendation && (
                      <small className="eval-monitor-recommendation"><strong>Recommendation:</strong> {r.recommendation}</small>
                    )}
                  </button>
                ))}
              </div>
              )}
              {selectedCategory && (
                <div className="eval-monitor-category-detail">
                  <div>
                    <span>Selected Category</span>
                    <strong>{selectedCategory.category}</strong>
                  </div>
                  <div>
                    <span>Score</span>
                    <strong>{Number(selectedCategory.score || 0).toFixed(2)}/5</strong>
                  </div>
                  <div>
                    <span>Weighted Score</span>
                    <strong>{Number(selectedCategory.weighted_score || 0).toFixed(4)}</strong>
                  </div>
                  <div>
                    <span>Completion</span>
                    <strong>{Math.round(Number(selectedCategory.score || 0) * 20)}%</strong>
                  </div>
                  <div>
                    <span>Evaluator</span>
                    <strong>{categoryEvaluatorLabel(selectedCategory)}</strong>
                  </div>
                  {(selectedCategory.reason_for_rating || selectedCategory.behavioral_evidence || selectedCategory.recommendation) && (
                    <p>
                      {selectedCategory.reason_for_rating || selectedCategory.behavioral_evidence || selectedCategory.recommendation}
                    </p>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Strengths & Weaknesses */}
          <div className="eval-monitor-strength-weak-grid">
            {visibleStrengths.length > 0 && (
              <div className="eval-monitor-strengths">
                <h4><TrendingUp size={16} /> Strengths</h4>
                {visibleStrengths.map((s, i) => (
                  <div key={i} className="eval-monitor-strength-item" style={{ '--item-index': i }}>
                    <Award size={14} />
                    <span>{s.category}{s.evaluator && <small>{s.evaluator} - {s.type}</small>}</span>
                    <strong className="eval-monitor-item-score">{Number(s.score || 0).toFixed(2)}/5</strong>
                  </div>
                ))}
              </div>
            )}
            {visibleWeaknesses.length > 0 && (
              <div className="eval-monitor-weaknesses">
                <h4><TrendingDown size={16} /> Areas for Improvement</h4>
                {visibleWeaknesses.map((w, i) => (
                  <div key={i} className="eval-monitor-weakness-item" style={{ '--item-index': i }}>
                    <Target size={14} />
                    <div>
                      <span>{w.category}</span>
                      {w.evaluator && <small>{w.evaluator} - {w.type}</small>}
                      {w.recommendation && <small>{w.recommendation}</small>}
                    </div>
                    <strong className="eval-monitor-item-score">{Number(w.score || 0).toFixed(2)}/5</strong>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Evaluator Assignments */}
          {assignments.length > 0 && (
            <div className="eval-monitor-section">
              <h4><Users size={16} /> Evaluator Assignments</h4>
              <table className="eval-monitor-table">
                <thead>
                  <tr>
                    <th>Evaluator</th>
                    <th>Role</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Deadline</th>
                  </tr>
                </thead>
                <tbody>
                  {assignments.map((a, i) => (
                    <tr key={i}>
                      <td>{a.evaluator_name}</td>
                      <td>{a.evaluator_role}</td>
                      <td>{a.type}</td>
                      <td>
                        {statusBadge(a.status, undefined, a.status_note)}
                        {a.status_note && <small className="eval-monitor-status-note">{a.status_note}</small>}
                      </td>
                      <td>{formatDate(a.submitted_at)}</td>
                      <td>{formatDate(a.deadline)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* AI Insights */}
          {insights.length > 0 && (
            <div className="eval-monitor-section">
              <h4><Lightbulb size={16} /> AI Insights</h4>
              {insights.map((ins, i) => (
                <div key={i} className="eval-monitor-insight-card">
                  <div className="eval-monitor-insight-tags">
                    {ins.weak_area && <span className="tag-weak">{ins.weak_area}</span>}
                    {ins.strength_area && <span className="tag-strength">{ins.strength_area}</span>}
                  </div>
                  {ins.analysis_summary && <p>{ins.analysis_summary}</p>}
                  <small>{formatDate(ins.created_at)}</small>
                </div>
              ))}
            </div>
          )}

          {/* Intervention Plans */}
          {interventions.length > 0 && (
            <div className="eval-monitor-section">
              <h4><Target size={16} /> Intervention Plans</h4>
              {interventions.map((p, i) => (
                <div key={i} className="eval-monitor-intervention-item">
                  <div className="eval-monitor-intervention-head">
                    <span className="eval-monitor-intervention-area">{p.weak_area}</span>
                    <span className={`eval-monitor-intervention-status ${p.status}`}>{p.status}</span>
                  </div>
                  <p>{p.recommendation}</p>
                  {p.target_date && <small>Target: {formatDate(p.target_date)}</small>}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );

  return createPortal(modal, document.body);
}

// ─── Main Component ──────────────────────────────────────────────────
export default function AdminEvaluationMonitor() {
  const location = useLocation();
  // Navigation state
  const [view, setView] = useState('departments'); // departments | programs | faculty
  const [selectedDept, setSelectedDept] = useState(null);
  const [selectedProgram, setSelectedProgram] = useState(null);
  const [selectedFaculty, setSelectedFaculty] = useState(null);

  // Data
  const [departments, setDepartments] = useState([]);
  const [programs, setPrograms] = useState([]);
  const [facultyMembers, setFacultyMembers] = useState([]);
  const [weakAreas, setWeakAreas] = useState({});
  const [departmentInfo, setDepartmentInfo] = useState(null);
  const [programInfo, setProgramInfo] = useState(null);
  const [departmentSummary, setDepartmentSummary] = useState(null);
  // eslint-disable-next-line no-unused-vars
  const deptSummary = departmentSummary;
  const [programSummary, setProgramSummary] = useState('');
  const [aiAnalysis, setAiAnalysis] = useState([]);

  // UI state
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshMessage, setRefreshMessage] = useState('');
  const [refreshError, setRefreshError] = useState('');
  const [manualRefreshing, setManualRefreshing] = useState(false);
  const [activeFaculty, setActiveFaculty] = useState(null);
  const { selectedPeriodId, selectedPeriod } = useEvaluationPeriod();
  const [searchQuery, setSearchQuery] = useState('');
  const consumedFocusRef = useRef('');
  const loadedPeriodRef = useRef(null);

  // ─── Load departments ──────────────────────────────────────────────
  const loadDepartments = useCallback(async (background = false) => {
    if (!background) setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ scope: 'departments' });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
      if (payload.ok && Array.isArray(payload.data)) {
        setDepartments(payload.data);
        setWeakAreas(payload.weakAreas || {});
        const s = payload.summary || {};
        s.overall_completion_rate = s.total_completed > 0
          ? Math.round((s.total_completed / (s.total_completed + s.total_pending)) * 100)
          : 0;
        setDepartmentSummary(s);
        return payload;
      }
    } catch (err) {
      setError(err.message);
    } finally {
      if (!background) setLoading(false);
    }
    return null;
  }, [selectedPeriodId]);

  // ─── Load programs ─────────────────────────────────────────────────
  const loadPrograms = useCallback(async (deptId, background = false) => {
    if (!background) setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ scope: 'programs', department_id: String(deptId) });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
      if (payload.ok) {
        setPrograms(Array.isArray(payload.data) ? payload.data : []);
        setDepartmentInfo(payload.department || null);
        setAiAnalysis(payload.aiAnalysis || []);
        return payload;
      }
    } catch (err) {
      setError(err.message);
    } finally {
      if (!background) setLoading(false);
    }
    return null;
  }, [selectedPeriodId]);

  // ─── Load faculty ──────────────────────────────────────────────────
  const loadFaculty = useCallback(async (progId, background = false) => {
    if (!background) setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ scope: 'faculty', program_id: String(progId) });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
      if (payload.ok) {
        const nextFacultyMembers = Array.isArray(payload.data) ? payload.data : [];
        setFacultyMembers(nextFacultyMembers);
        setActiveFaculty((current) => {
          if (!current) return current;
          return nextFacultyMembers.find((item) => Number(item.id) === Number(current.id)) || current;
        });
        setProgramInfo(payload.program || null);
        setProgramSummary(payload.summary || '');
        return payload;
      }
    } catch (err) {
      setError(err.message);
    } finally {
      if (!background) setLoading(false);
    }
    return null;
  }, [selectedPeriodId]);

  const refreshCurrentView = useCallback(async (background = true) => {
    if (view === 'faculty' && selectedProgram?.id) {
      return loadFaculty(selectedProgram.id, background);
    }
    if (view === 'programs' && selectedDept?.id) {
      return loadPrograms(selectedDept.id, background);
    }
    return loadDepartments(background);
  }, [loadDepartments, loadFaculty, loadPrograms, selectedDept?.id, selectedProgram?.id, view]);

  const { refreshing: liveRefreshing } = useLiveRefresh(refreshCurrentView, [selectedPeriodId, view, selectedDept?.id, selectedProgram?.id], {
    intervalMs: 6000,
  });

  const handleManualRefresh = useCallback(async () => {
    setManualRefreshing(true);
    setRefreshMessage('');
    setRefreshError('');
    try {
      const payload = await refreshCurrentView(false);
      if (!payload) {
        throw new Error('Refresh failed');
      }
      setRefreshMessage('Data refreshed successfully.');
      addToast('Data refreshed successfully.', 'success');
      window.setTimeout(() => setRefreshMessage(''), 3500);
    } catch {
      setRefreshError('Unable to refresh data. Please try again.');
      addToast('Unable to refresh data. Please try again.', 'error');
    } finally {
      setManualRefreshing(false);
    }
  }, [refreshCurrentView]);

  useEffect(() => {
    const periodKey = selectedPeriodId || '';
    if (loadedPeriodRef.current === periodKey) return;
    loadedPeriodRef.current = periodKey;
    setSearchQuery('');
    refreshCurrentView(false);
  }, [selectedPeriodId, refreshCurrentView]);

  useEffect(() => {
    const focus = location.state?.source === 'department-profile' ? location.state : null;
    if (!focus || departments.length === 0) return;

    const focusKey = [
      focus.focusUserId || '',
      focus.focusUserEmail || '',
      focus.focusUserName || '',
      focus.focusProgram || '',
      focus.focusProgramId || '',
      selectedPeriodId || '',
    ].join('|');
    if (consumedFocusRef.current === focusKey) return;
    consumedFocusRef.current = focusKey;

    let cancelled = false;

    async function openFocusedOverallEvaluation() {
      const departmentId = Number(focus.focusDepartmentId);
      const departmentCode = normalizeLookup(focus.focusDepartmentCode);
      const departmentName = normalizeLookup(focus.focusDepartmentName);
      const targetUserId = Number(focus.focusUserId);
      const targetName = normalizeLookup(focus.focusUserName);
      const targetEmail = normalizeLookup(focus.focusUserEmail);
      const targetProgram = normalizeLookup(focus.focusProgram || focus.focusProgramCode || focus.focusProgramName);
      let targetFacultyId = Number(focus.focusFacultyId || 0);
      let directProgramId = 0;

      const matchedDept = departments.find((dept) => {
        // Prefer numeric ID matching (most reliable)
        if (departmentId && Number(dept.id) === departmentId) return true;
        const code = normalizeLookup(dept.department_code);
        const name = normalizeLookup(dept.department_name);
        return (departmentCode && code === departmentCode)
          || (departmentName && name === departmentName)
          || (departmentName && name.includes(departmentName));
      });

      if (!matchedDept || cancelled) return;

      try {
        const personParams = new URLSearchParams({ scope: 'faculty_person' });
        if (targetUserId) personParams.set('user_id', String(targetUserId));
        if (focus.focusUserEmail) personParams.set('email', focus.focusUserEmail);
        if (focus.focusUserName) personParams.set('name', focus.focusUserName);
        if (matchedDept.id) personParams.set('department_id', String(matchedDept.id));

        const personPayload = await apiFetch(`${API_BASE}?${personParams.toString()}`);
        if (!cancelled && personPayload?.ok && personPayload.faculty) {
          targetFacultyId = Number(personPayload.faculty.id || targetFacultyId || 0);
          directProgramId = Number(personPayload.faculty.program_id || 0);
        }
      } catch (_) {
        // Fall back to program/name/email search below.
      }

      setSearchQuery('');
      setSelectedDept(matchedDept);
      setSelectedProgram(null);
      setActiveFaculty(null);
      setView('programs');

      if (targetFacultyId) {
        const directParams = new URLSearchParams({
          scope: 'faculty',
          faculty_id: String(targetFacultyId),
        });
        if (selectedPeriodId) directParams.set('period_id', selectedPeriodId);

        const directFacultyPayload = await apiFetch(`${API_BASE}?${directParams.toString()}`);
        if (cancelled) return;

        const directFacultyRows = Array.isArray(directFacultyPayload?.data) ? directFacultyPayload.data : [];
        const directFaculty = directFacultyRows[0] || null;
        if (directFaculty) {
          const directProgram = directFacultyPayload.program || null;
          setSelectedProgram(directProgram);
          setProgramInfo(directProgram);
          setProgramSummary(directFacultyPayload.summary || '');
          setFacultyMembers(directFacultyRows);
          setSearchQuery('');
          setView('faculty');
          setActiveFaculty(directFaculty);
          return;
        }
      }

      const programPayload = await loadPrograms(matchedDept.id);
      if (cancelled) return;

      const availablePrograms = Array.isArray(programPayload?.data) ? programPayload.data : [];
      const targetProgramId = Number(focus.focusProgramId);
      const matchedProgram = availablePrograms.find((program) => {
        // Prefer numeric ID matching (most reliable)
        if (directProgramId && Number(program.id) === directProgramId) return true;
        if (targetProgramId && Number(program.id) === targetProgramId) return true;
        const code = normalizeLookup(program.program_code);
        const name = normalizeLookup(program.program_name);
        const head = normalizeLookup(program.program_head_name);
        return (targetProgram && (code === targetProgram || name === targetProgram || name.includes(targetProgram)))
          || (targetName && head === targetName);
      });

      function findMatchingFaculty(availableFaculty) {
        return availableFaculty.find((faculty) => {
          if (targetFacultyId && Number(faculty.id || 0) === targetFacultyId) return true;
          if (targetUserId && Number(faculty.user_id || 0) === targetUserId) return true;
          const email = normalizeLookup(faculty.email);
          if (targetEmail && email === targetEmail) return true;
          const name = normalizeLookup(faculty.full_name);
          return (targetName && name === targetName)
            || (targetName && name.includes(targetName));
        });
      }

      async function openProgramFaculty(program) {
        setSelectedProgram(program);
        setView('faculty');

        const facultyPayload = await loadFaculty(program.id);
        if (cancelled) return false;

        const availableFaculty = Array.isArray(facultyPayload?.data) ? facultyPayload.data : [];
        const matchedFaculty = findMatchingFaculty(availableFaculty);
        if (!matchedFaculty) return false;

        setSearchQuery('');
        setActiveFaculty(matchedFaculty);
        return true;
      }

      const orderedPrograms = matchedProgram
        ? [matchedProgram, ...availablePrograms.filter((program) => Number(program.id) !== Number(matchedProgram.id))]
        : availablePrograms;

      for (const program of orderedPrograms) {
        const opened = await openProgramFaculty(program);
        if (cancelled || opened) return;
      }

      if (matchedProgram) {
        setSelectedProgram(matchedProgram);
        setView('faculty');
      }
      setSearchQuery(focus.focusUserName || focus.focusProgram || '');
    }

    openFocusedOverallEvaluation();

    return () => {
      cancelled = true;
    };
  }, [departments, loadFaculty, loadPrograms, location.state, selectedPeriodId]);

  // ─── Navigation handlers ───────────────────────────────────────────
  function drillToPrograms(dept) {
    setSelectedDept(dept);
    setView('programs');
    setPrograms([]);
    loadPrograms(dept.id);
  }

  function drillToFaculty(prog) {
    setSelectedProgram(prog);
    setView('faculty');
    setFacultyMembers([]);
    loadFaculty(prog.id);
  }

  function goBack() {
    if (view === 'faculty') {
      setView('programs');
      setSelectedProgram(null);
      setFacultyMembers([]);
    } else if (view === 'programs') {
      setView('departments');
      setSelectedDept(null);
      setSelectedProgram(null);
      setPrograms([]);
    }
  }

  // ─── Filtering ─────────────────────────────────────────────────────
  const filteredDepartments = useMemo(() => {
    if (!searchQuery) return departments;
    const q = searchQuery.toLowerCase();
    return departments.filter(
      (d) =>
        (d.department_name || '').toLowerCase().includes(q) ||
        (d.department_code || '').toLowerCase().includes(q) ||
        (d.dean_name || '').toLowerCase().includes(q)
    );
  }, [departments, searchQuery]);

  const filteredPrograms = useMemo(() => {
    if (!searchQuery) return programs;
    const q = searchQuery.toLowerCase();
    return programs.filter(
      (p) =>
        (p.program_name || '').toLowerCase().includes(q) ||
        (p.program_code || '').toLowerCase().includes(q) ||
        (p.program_head_name || '').toLowerCase().includes(q)
    );
  }, [programs, searchQuery]);

  const filteredFaculty = useMemo(() => {
    if (!searchQuery) return facultyMembers;
    const q = searchQuery.toLowerCase();
    return facultyMembers.filter(
      (f) =>
        (f.full_name || '').toLowerCase().includes(q) ||
        (f.position_title || '').toLowerCase().includes(q) ||
        (f.email || '').toLowerCase().includes(q)
    );
  }, [facultyMembers, searchQuery]);

  // ─── Summary stats ─────────────────────────────────────────────────
  const deptStats = useMemo(() => {
    if (departments.length === 0) return null;
    return {
      total: departments.length,
      faculty: departments.reduce((s, d) => s + d.total_faculty, 0),
      completed: departments.reduce((s, d) => s + d.completed, 0),
      pending: departments.reduce((s, d) => s + d.pending, 0),
      overdue: departments.reduce((s, d) => s + d.overdue, 0),
      pct:
        departments.reduce((s, d) => s + d.total_assignments, 0) > 0
          ? Math.round(
              (departments.reduce((s, d) => s + d.completed, 0) /
                departments.reduce((s, d) => s + d.total_assignments, 0)) *
                100
            )
          : 0,
    };
  }, [departments]);

  // ─── Department-level view ─────────────────────────────────────────
  function renderDepartments() {
    return (
      <>
        {/* Hero Section */}
        <div className="eval-monitor-hero">
          <div>
            <p className="eyebrow">Admin Evaluation Monitor</p>
            <h2>Hierarchical Evaluation Monitoring</h2>
            <p>
              Centralized overview of all departments. Drill down to view programs, faculty,
              and detailed evaluation results.
            </p>
            {selectedPeriod && (
              <div className="eval-monitor-period-note">
                Showing data for <strong>{selectedPeriod.period_name}</strong>
                {selectedPeriod.school_year ? ` (${selectedPeriod.school_year})` : ''}
                {selectedPeriod.semester ? ` - ${selectedPeriod.semester}` : ''}
              </div>
            )}
          </div>
          {deptStats && (
            <div className="eval-monitor-hero-chart">
              <div className="eval-monitor-donut" style={{ '--pct': deptStats.pct }}>
                <strong>{deptStats.pct}%</strong>
                <span>Complete</span>
              </div>
              <div className="eval-monitor-hero-stats">
                <span><Building2 size={14} /> {deptStats.total} Depts</span>
                <span><Users size={14} /> {deptStats.faculty} Faculty</span>
                <span><ClipboardCheck size={14} /> {deptStats.completed} Done</span>
                <span className="text-warning"><Clock size={14} /> {deptStats.pending} Pending</span>
                <span className="text-danger"><AlertTriangle size={14} /> {deptStats.overdue} Overdue</span>
              </div>
            </div>
          )}
        </div>

        {/* Metrics Row */}
        {deptStats && (
          <div className="eval-monitor-metrics">
            <article className="metric-primary">
              <span>Departments</span>
              <strong>{deptStats.total}</strong>
              <small>Total departments</small>
            </article>
            <article className="metric-info">
              <span>Faculty Count</span>
              <strong>{deptStats.faculty}</strong>
              <small>Active faculty members</small>
            </article>
            <article className="metric-success">
              <span>Completed</span>
              <strong>{deptStats.completed}</strong>
              <small>{deptStats.pct}% completion rate</small>
            </article>
            <article className="metric-warning">
              <span>Pending</span>
              <strong>{deptStats.pending}</strong>
              <small>Awaiting submission</small>
            </article>
            <article className="metric-danger">
              <span>Overdue</span>
              <strong>{deptStats.overdue}</strong>
              <small>Past deadline</small>
            </article>
          </div>
        )}

        {/* Department Table */}
        <div className="eval-monitor-table-container">
          <div className="eval-monitor-toolbar">
            <div className="eval-monitor-search">
              <Search size={16} />
              <input
                type="search"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search departments..."
              />
            </div>
            <div className="eval-monitor-toolbar-actions">
              {liveRefreshing && <span className="live-refresh-indicator compact">Syncing...</span>}
              {refreshMessage && <span className="live-refresh-indicator compact success">{refreshMessage}</span>}
              {refreshError && <span className="live-refresh-indicator compact error">{refreshError}</span>}
              <PeriodSelector compact className="eval-monitor-period-filter" showRefresh={false} />
              <button type="button" className="eval-monitor-btn ghost" onClick={handleManualRefresh} disabled={manualRefreshing || loading}>
                {manualRefreshing ? <Loader2 size={16} className="animate-spin" /> : <RefreshCw size={16} />} Refresh Data
              </button>
            </div>
          </div>

          {loading ? (
            <LoadingSkeleton />
          ) : error ? (
            <div className="eval-monitor-empty error">{error}</div>
          ) : filteredDepartments.length === 0 ? (
            <div className="eval-monitor-empty">No departments found.</div>
          ) : (
            <div className="eval-monitor-dept-grid">
              {filteredDepartments.map((dept) => {
                const deptWeakAreas = weakAreas[dept.department_name] || weakAreas[dept.department_code] || [];
                return (
                  <div
                    key={dept.id}
                    className="eval-monitor-dept-card"
                    onClick={() => drillToPrograms(dept)}
                  >
                    <div className="eval-monitor-dept-card-header">
                      <div className="eval-monitor-dept-card-icon">
                        <Building2 size={22} />
                      </div>
                      <div>
                        <h3>{dept.department_name}</h3>
                        <span className="eval-monitor-dept-code">{dept.department_code}</span>
                      </div>
                      <ChevronRight size={20} className="eval-monitor-chevron" />
                    </div>

                    <div className="eval-monitor-dept-card-body">
                      <div className="eval-monitor-dept-card-meta">
                        <span><Users size={14} /> {dept.total_faculty} Faculty</span>
                        {dept.archived_faculty_count > 0 && (
                          <span className="archived-faculty-badge">
                            {dept.archived_faculty_count} Archived
                          </span>
                        )}
                        <span>Dean: {dept.dean_name}</span>
                      </div>

                      <div className="eval-monitor-dept-card-stats">
                        <div className="eval-monitor-dept-stat">
                          <span>Completed</span>
                          <strong className="text-success">{dept.completed}</strong>
                        </div>
                        <div className="eval-monitor-dept-stat">
                          <span>Pending</span>
                          <strong className="text-warning">{dept.pending}</strong>
                        </div>
                        <div className="eval-monitor-dept-stat">
                          <span>Overdue</span>
                          <strong className="text-danger">{dept.overdue}</strong>
                        </div>
                      </div>

                      {completionBar(dept.completion_pct)}

                      <div className="eval-monitor-dept-card-badges">
                        {statusBadge('completed', dept.completed)}
                        {dept.pending > 0 && statusBadge('pending', dept.pending)}
                        {dept.overdue > 0 && statusBadge('overdue', dept.overdue)}
                      </div>

                      {dept.all_evaluated && deptWeakAreas.length > 0 && (
                        <div className="eval-monitor-dept-weak-areas">
                          {deptWeakAreas.slice(0, 2).map((wa, i) => (
                            <span key={i} className="tag-weak">{wa.weak_area}</span>
                          ))}
                        </div>
                      )}

                      <div className="eval-monitor-dept-ai-recommendation" onClick={(event) => event.stopPropagation()}>
                        <div className="eval-monitor-dept-ai-recommendation-head">
                          <Brain size={15} />
                          <span>Department AI Recommendation</span>
                        </div>
                        <RecommendationStatusBanner status={dept.recommendation_status} compact />
                        {deptWeakAreas.length > 0 ? (
                          <>
                            <strong>{recommendedSessionForField(deptWeakAreas[0]?.weak_area)}</strong>
                            <p>
                              {dept.recommendation_status?.caveat_text} Focus on {deptWeakAreas.slice(0, 3).map((area) => area.weak_area).join(', ')} across the department.
                            </p>
                          </>
                        ) : (
                          <p>{dept.recommendation_status?.caveat_text || 'No priority weak area was detected for this department yet.'}</p>
                        )}
                      </div>

                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </>
    );
  }

  // ─── Program-level view ────────────────────────────────────────────
  function renderPrograms() {
    return (
      <>
        <div className="eval-monitor-breadcrumb">
          <button type="button" onClick={goBack}>
            <ChevronLeft size={16} /> All Departments
          </button>
          <span>/</span>
          <span>{departmentInfo?.department_name || selectedDept?.department_name || 'Department'}</span>
        </div>

        <div className="eval-monitor-hero compact">
          <div>
            <p className="eyebrow">Programs</p>
            <h2>{departmentInfo?.department_name || selectedDept?.department_name}</h2>
            <p>
              {departmentInfo?.dean_name ? `Dean: ${departmentInfo.dean_name} • ` : ''}
              {programs.length} program{programs.length !== 1 ? 's' : ''} under this department.
            </p>
          </div>
        </div>

        {/* AI Analysis Cards */}
        {aiAnalysis.length > 0 && (
          <div className="eval-monitor-ai-summary">
            {aiAnalysis.map((item, i) => (
              <div key={i} className={`eval-monitor-ai-card ${item.type}`}>
                {item.type === 'attention' ? <AlertTriangle size={18} /> : <TrendingUp size={18} />}
                <span>{item.message}</span>
              </div>
            ))}
          </div>
        )}

        <div className="eval-monitor-table-container">
          <div className="eval-monitor-toolbar">
            <div className="eval-monitor-search">
              <Search size={16} />
              <input
                type="search"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search programs..."
              />
            </div>
            <div className="eval-monitor-toolbar-actions">
              {liveRefreshing && <span className="live-refresh-indicator compact">Syncing...</span>}
              <button type="button" className="eval-monitor-btn ghost" onClick={() => loadPrograms(selectedDept.id, false)}>
                <RefreshCw size={16} /> Refresh
              </button>
            </div>
          </div>

          {loading ? (
            <LoadingSkeleton />
          ) : filteredPrograms.length === 0 ? (
            <div className="eval-monitor-empty">No programs found under this department.</div>
          ) : (
            <div className="eval-monitor-program-grid">
              {filteredPrograms.map((prog) => (
                <div
                  key={prog.id}
                  className="eval-monitor-program-card"
                  onClick={() => drillToFaculty(prog)}
                >
                  <div className="eval-monitor-program-card-header">
                    <div className="eval-monitor-program-card-icon">
                      <Award size={20} />
                    </div>
                    <div>
                      <h3>{prog.program_name}</h3>
                      <span className="eval-monitor-dept-code">{prog.program_code}</span>
                    </div>
                    <ChevronRight size={20} className="eval-monitor-chevron" />
                  </div>

                  <div className="eval-monitor-program-card-body">
                    <div className="eval-monitor-program-meta">
                      <span><Users size={14} /> {prog.total_faculty} Faculty</span>
                      <span>Head: {prog.program_head_name}</span>
                    </div>

                    <div className="eval-monitor-program-stats">
                      <div className="eval-monitor-program-stat primary">
                        <span>Completion</span>
                        <strong>{prog.completion_pct}%</strong>
                      </div>
                      <div className="eval-monitor-program-stat accent">
                        <span>Avg Score</span>
                        <strong>{prog.average_score || '—'}</strong>
                      </div>
                    </div>

                    {completionBar(prog.completion_pct)}

                    <div className="eval-monitor-dept-card-badges">
                      {statusBadge('completed', prog.completed)}
                      {prog.pending > 0 && statusBadge('pending', prog.pending)}
                      {prog.overdue > 0 && statusBadge('overdue', prog.overdue)}
                    </div>

                    <div className="eval-monitor-dept-ai-recommendation program" onClick={(event) => event.stopPropagation()}>
                      <div className="eval-monitor-dept-ai-recommendation-head">
                        <Brain size={15} />
                        <span>Program AI Recommendation</span>
                      </div>
                      <RecommendationStatusBanner status={prog.recommendation_status} compact />
                      {Array.isArray(prog.fields) && prog.fields.length > 0 ? (
                        <>
                          <strong>{recommendedSessionForField(prog.fields[0]?.name)}</strong>
                          <p>{programRecommendationSummary(prog.fields[0]?.name)}</p>
                        </>
                      ) : (
                        <p>No completed category results are available yet for this program.</p>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </>
    );
  }

  // ─── Faculty-level view ─────────────────────────────────────────────
  function renderFaculty() {
    return (
      <>
        <div className="eval-monitor-breadcrumb">
          <button type="button" onClick={goBack}>
            <ChevronLeft size={16} /> {departmentInfo?.department_name || selectedDept?.department_name || 'Department'}
          </button>
          <span>/</span>
          <span>{programInfo?.program_name || selectedProgram?.program_name || 'Program'}</span>
        </div>

        <div className="eval-monitor-hero compact">
          <div>
            <p className="eyebrow">Faculty Members</p>
            <h2>{programInfo?.program_name || selectedProgram?.program_name}</h2>
            <p>
              {programInfo?.program_head_name ? `Head: ${programInfo.program_head_name} • ` : ''}
              {facultyMembers.length} faculty member{facultyMembers.length !== 1 ? 's' : ''}.
            </p>
            {programSummary && <div className="eval-monitor-program-summary">{programSummary}</div>}
          </div>
        </div>

        <div className="eval-monitor-table-container">
          <div className="eval-monitor-toolbar">
            <div className="eval-monitor-search">
              <Search size={16} />
              <input
                type="search"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search faculty..."
              />
            </div>
            <div className="eval-monitor-toolbar-actions">
              {liveRefreshing && <span className="live-refresh-indicator compact">Syncing...</span>}
              <button type="button" className="eval-monitor-btn ghost" onClick={() => loadFaculty(selectedProgram.id, false)}>
                <RefreshCw size={16} /> Refresh
              </button>
            </div>
          </div>

          {loading ? (
            <LoadingSkeleton />
          ) : filteredFaculty.length === 0 ? (
            <div className="eval-monitor-empty">No faculty members found under this program.</div>
          ) : (
            <div className="eval-monitor-faculty-grid">
              {filteredFaculty.map((fac) => {
                const avgScore = fac.average_score;
                return (
                  <div
                    key={fac.id}
                    className="eval-monitor-faculty-card"
                    onClick={() => setActiveFaculty(fac)}
                  >
                    <div className="eval-monitor-faculty-card-head">
                      <div className="eval-monitor-faculty-avatar">
                        {(fac.full_name || '?').charAt(0).toUpperCase()}
                      </div>
                      <div className="eval-monitor-faculty-info">
                        <h3>{fac.full_name}</h3>
                        <span>{fac.position_title}</span>
                      </div>
                      {avgScore !== null && avgScore !== undefined ? (
                        <div
                          className={`eval-monitor-score-pill ${
                            avgScore >= 4 ? 'high' : avgScore >= 3 ? 'mid' : 'low'
                          }`}
                        >
                          {avgScore.toFixed(1)}
                        </div>
                      ) : (
                        <div className="eval-monitor-score-pill none">—</div>
                      )}
                    </div>

                    <div className="eval-monitor-faculty-card-body">
                      <div className="eval-monitor-faculty-meta">
                        <span>{fac.department} / {fac.program_code}</span>
                      </div>

                      <div className="eval-monitor-faculty-stats-row">
                        {fac.total_assignments > 0 ? (
                          <>
                            <span className="stat-item">
                              <ClipboardCheck size={14} /> {fac.completed_evaluations}/{fac.total_assignments}
                            </span>
                            {fac.pending_evaluations > 0 && (
                              <span className="stat-item warning">
                                <Clock size={14} /> {fac.pending_evaluations} pending
                              </span>
                            )}
                          </>
                        ) : (
                          <span className="stat-item warning">
                            <ClipboardCheck size={14} /> No assignments for this period
                          </span>
                        )}
                      </div>

                      {fac.strengths && fac.strengths.length > 0 && (
                        <div className="eval-monitor-faculty-tags">
                          {fac.strengths.slice(0, 2).map((s, i) => (
                            <span key={i} className="tag-strength">{s.category}</span>
                          ))}
                        </div>
                      )}
                      {fac.weaknesses && fac.weaknesses.length > 0 && (
                        <div className="eval-monitor-faculty-tags">
                          {fac.weaknesses.slice(0, 2).map((w, i) => (
                            <span key={i} className="tag-weak">{w.category}</span>
                          ))}
                        </div>
                      )}

                      {fac.ai_recommendation && (
                        <div className="eval-monitor-faculty-ai-recommendation">
                          <div>
                            <Brain size={14} />
                            <span>AI Recommendation</span>
                          </div>
                          <RecommendationStatusBanner status={fac.recommendation_status} compact />
                          <p>{fac.ai_recommendation}</p>
                        </div>
                      )}

                      <div className="eval-monitor-faculty-actions">
                        <button
                          type="button"
                          className="eval-monitor-btn ghost compact"
                          onClick={(e) => {
                            e.stopPropagation();
                            setActiveFaculty(fac);
                          }}
                        >
                          <Eye size={14} /> View Details
                        </button>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </>
    );
  }

  // ─── Scroll to top when navigating between views ─────────────
  const mainRef = useRef(null);
  useEffect(() => {
    if (mainRef.current) {
      mainRef.current.scrollTo?.({ top: 0, behavior: 'smooth' });
      mainRef.current.scrollTop = 0;
    }
  }, [view]);

  return (
    <div className="eval-monitor-container module-wide page-enter">
      <div className="eval-monitor-main" ref={mainRef}>
        <div className="eval-monitor-header">
          <h2><BarChart3 size={22} /> Evaluation Monitor</h2>
        </div>

        {view === 'departments' && renderDepartments()}
        {view === 'programs' && renderPrograms()}
        {view === 'faculty' && renderFaculty()}
      </div>

      {/* Faculty Detail Modal */}
      {activeFaculty && (
        <FacultyDetailModal
          faculty={activeFaculty}
          onClose={() => setActiveFaculty(null)}
        />
      )}
    </div>
  );
}
