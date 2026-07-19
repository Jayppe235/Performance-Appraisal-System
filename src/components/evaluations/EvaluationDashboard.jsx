import { useCallback, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import EvaluationCard from './EvaluationCard.jsx';
import EvaluationModal from './EvaluationModal.jsx';
import PeriodSelector from './PeriodSelector.jsx';
import SelfEvaluationModule from './SelfEvaluationModule.jsx';
import { isSelfEvaluationAssignment, normalizeRoleForSelfEvaluation } from './selfEvaluationUtils.js';
import apiFetch from '../../data/api.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import useLiveRefresh, { notifyLiveDataChanged } from '../../hooks/useLiveRefresh.js';

const sections = [
  ['all', 'All Evaluations'],
  ['self', 'Self Evaluation'],
  ['dean', 'Dean Evaluation'],
  ['program_head', 'Program Head Evaluation'],
  ['faculty', 'Faculty Evaluation'],
  ['peer', 'Peer Evaluation'],
];

const statusOptions = [
  ['pending', 'Pending'],
  ['in_progress', 'In Progress'],
  ['submitted', 'Done'],
  ['overdue', 'Overdue'],
];

const sectionLabels = {
  all: 'All Evaluations',
  self: 'Self Evaluation',
  dean: 'Dean Evaluation',
  program_head: 'Program Head Evaluation',
  faculty: 'Faculty Evaluation',
  peer: 'Peer Evaluation',
};

function matchesStatusFilter(itemStatus, selectedStatus) {
  if (!selectedStatus) return true;
  if (selectedStatus === 'submitted') return itemStatus === 'submitted' || itemStatus === 'done';
  if (selectedStatus === 'in_progress') return itemStatus === 'in_progress' || itemStatus === 'progress';
  return itemStatus === selectedStatus;
}

function searchableText(item) {
  return [
    item.fullName,
    item.evaluateeName,
    item.role,
    item.evaluateeRole,
    item.position,
    item.evaluateePosition,
    item.department,
    item.program,
    item.status,
    item.assignmentType,
    item.relationshipTag,
    sectionLabels[item.section] || item.section,
  ].filter(Boolean).join(' ').toLowerCase();
}

function draftKeyForEvaluation(item) {
  if (!item?.id) return '';
  const questionnaireType = String(item.questionnaireType || item.questionnaire_type || '').toLowerCase();
  const formType = questionnaireType === 'admin' ? 'form_a' : 'form_b';
  return `pmas-evaluation-draft:${formType}:${item.id}`;
}

function draftProgressForEvaluation(item) {
  if (typeof window === 'undefined') return null;
  const key = draftKeyForEvaluation(item);
  if (!key) return null;
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return null;
    const draft = JSON.parse(raw);
    const progressPercent = Number(draft?.progressPercent);
    const answeredQuestions = Number(draft?.answeredQuestions);
    const totalQuestions = Number(draft?.totalQuestions);
    if (!Number.isFinite(progressPercent) || progressPercent <= 0) return null;
    return {
      progressPercent: Math.max(0, Math.min(99, Math.round(progressPercent))),
      answeredQuestions: Number.isFinite(answeredQuestions) ? answeredQuestions : 0,
      totalQuestions: Number.isFinite(totalQuestions) ? totalQuestions : 0,
    };
  } catch (_) {
    return null;
  }
}

function applyDraftProgress(item) {
  if (item.status === 'submitted' || item.status === 'overdue') return item;
  const draft = draftProgressForEvaluation(item);
  if (!draft) return item;
  return {
    ...item,
    status: 'in_progress',
    progressPercent: draft.progressPercent,
    answeredQuestions: draft.answeredQuestions,
    totalQuestions: draft.totalQuestions,
  };
}

export default function EvaluationDashboard({ eyebrow, title, subtitle, setupPanel = null, viewOnly = false, evaluatorRole = '', role = null }) {
  const isVpaaEvaluation = evaluatorRole === 'vpaa';
  const isDeanEvaluation = evaluatorRole === 'dean';
  const isProgramHeadEvaluation = evaluatorRole === 'programHead' || evaluatorRole === 'program_head';
  const isFacultyEvaluation = evaluatorRole === 'faculty' || evaluatorRole === 'teacher';
  const showDepartmentStatusFilters = !isProgramHeadEvaluation && !isFacultyEvaluation;
  const [evaluations, setEvaluations] = useState([]);
  const [period, setPeriod] = useState(null);
  const [peerLifecycle, setPeerLifecycle] = useState({ status: 'unlocked', isLocked: false });
  const [loadingAssignments, setLoadingAssignments] = useState(false);
  const [assignmentError, setAssignmentError] = useState('');
  const [section, setSection] = useState('all');
  const [filters, setFilters] = useState({ search: '', role: '', department: '', status: '' });
  const [active, setActive] = useState(null);
  const [selfEvaluationTask, setSelfEvaluationTask] = useState(null);
  const [initializingSelf, setInitializingSelf] = useState(false);
  const [openingEvaluationId, setOpeningEvaluationId] = useState(null);
  const [setupOpen, setSetupOpen] = useState(false);

  useEffect(() => {
    if (section === 'peer' && filters.role) {
      setFilters((current) => ({ ...current, role: '' }));
    }
  }, [filters.role, section]);

  useEffect(() => {
    if (section === 'peer' && !peerLifecycle.isLocked) {
      setSection('all');
    }
  }, [peerLifecycle.isLocked, section]);

  const { selectedPeriodId } = useEvaluationPeriod();

  const loadAssignments = useCallback(async (background = false) => {
      if (!evaluatorRole || evaluatorRole === 'admin') {
        setEvaluations([]);
        return;
      }
      if (!background) {
        setLoadingAssignments(true);
        setAssignmentError('');
        setActive(null);
        setEvaluations([]);
      }
      try {
        const params = new URLSearchParams({ role: evaluatorRole });
        if (selectedPeriodId) params.set('period_id', selectedPeriodId);
        const payload = await apiFetch(`/api/evaluations.php?${params.toString()}`);
        if (!payload.ok) {
          throw new Error(payload.message || 'Unable to load evaluation assignments.');
        }
        const nextEvaluations = Array.isArray(payload.data) ? payload.data.map(applyDraftProgress) : [];
        const nextPeerLifecycle = payload.peerLifecycle || { status: 'unlocked', isLocked: false };
        setEvaluations(nextEvaluations);
        setPeriod(payload.period || null);
        setPeerLifecycle(nextPeerLifecycle);
        setActive((current) => {
          if (!current) return current;
          const stillAvailable = nextEvaluations.some((item) => Number(item.id) === Number(current.id));
          if (!stillAvailable || (current.section === 'peer' && !nextPeerLifecycle.isLocked)) {
            return null;
          }
          return current;
        });
        if (payload.message && payload.period && !payload.period.is_open) {
          setAssignmentError(payload.message);
        }
      } catch (error) {
        if (!background) {
          setAssignmentError(error.message || 'Unable to load evaluation assignments.');
          setEvaluations([]);
        }
      } finally {
        if (!background) setLoadingAssignments(false);
      }
  }, [evaluatorRole, selectedPeriodId]);

  const { refreshing: liveRefreshing } = useLiveRefresh(loadAssignments, [selectedPeriodId, evaluatorRole], {
    enabled: !!evaluatorRole && evaluatorRole !== 'admin',
    intervalMs: 5000,
  });

  const departments = [...new Set(evaluations.map((item) => item.department).filter(Boolean))];
  const programCourses = [...new Set(evaluations.map((item) => item.program || item.department).filter(Boolean))];
  const isLocked = !!(period && !period.is_open);
  const accessibleEvaluations = isLocked
    ? evaluations.filter((item) => item.status === 'submitted')
    : evaluations;
  const lockedHiddenCount = isLocked ? Math.max(0, evaluations.length - accessibleEvaluations.length) : 0;
  // Compute deadline urgency alerts
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  const deadlineAlerts = accessibleEvaluations
    .filter((item) => item.status !== 'submitted' && item.deadline)
    .map((item) => {
      const due = new Date(item.deadline);
      due.setHours(0, 0, 0, 0);
      const diffDays = Math.round((due - now) / (1000 * 60 * 60 * 24));
      return { ...item, daysRemaining: diffDays };
    });
  const overdueCount = deadlineAlerts.filter((item) => item.daysRemaining < 0).length;
  const dueTodayCount = deadlineAlerts.filter((item) => item.daysRemaining === 0).length;
  const dueSoonCount = deadlineAlerts.filter((item) => item.daysRemaining > 0 && item.daysRemaining <= 3).length;

  const summary = {
    total: accessibleEvaluations.length,
    done: accessibleEvaluations.filter((item) => item.status === 'submitted').length,
    inProgress: accessibleEvaluations.filter((item) => item.status === 'in_progress' || item.status === 'progress').length,
    overdue: overdueCount,
  };
  summary.pending = accessibleEvaluations.filter((item) => item.status !== 'submitted' && item.status !== 'in_progress' && item.status !== 'progress' && item.status !== 'overdue').length;
  summary.percent = summary.total > 0 ? Math.round((summary.done / summary.total) * 100) : 0;

  const visible = useMemo(() => {
    let filtered = accessibleEvaluations.filter((item) => {
      const search = searchableText(item);
      const query = filters.search.trim().toLowerCase();
      return (section === 'all' || item.section === section)
        && (!query || search.includes(query))
        && (!filters.role || item.role === filters.role)
        && (!filters.department || item.department === filters.department || item.program === filters.department)
        && matchesStatusFilter(item.status, filters.status);
    });

    // Sort by due date (earliest deadline first) when sort is active
    if (filters.sort === 'deadline') {
      filtered = [...filtered].sort((a, b) => {
        if (!a.deadline) return 1;
        if (!b.deadline) return -1;
        return new Date(a.deadline) - new Date(b.deadline);
      });
    } else if (filters.sort === 'name') {
      filtered = [...filtered].sort((a, b) => {
        const nameA = (a.fullName || a.evaluateeName || '').toLowerCase();
        const nameB = (b.fullName || b.evaluateeName || '').toLowerCase();
        return nameA.localeCompare(nameB);
      });
    }

    return filtered;
  }, [accessibleEvaluations, filters, section]);

  function updateFilter(name, value) {
    setFilters((current) => ({ ...current, [name]: value }));
  }

  function submitEvaluation(id, score, options = {}) {
    setEvaluations((current) => current.map((item) => item.id === id ? { ...item, status: 'submitted', score } : item));
    if (!options.keepOpen) {
      setActive(null);
    }
    notifyLiveDataChanged({ source: 'evaluation-submit', assignmentId: id });
  }

  const updateEvaluationProgress = useCallback((id, progress) => {
    setEvaluations((current) => current.map((item) => {
      if (Number(item.id) !== Number(id) || item.status === 'submitted' || item.status === 'overdue') return item;
      const progressPercent = Math.max(0, Math.min(99, Number(progress?.progressPercent || 0)));
      const nextStatus = progressPercent > 0 ? 'in_progress' : 'pending';
      if (
        item.status === nextStatus &&
        Number(item.progressPercent || 0) === progressPercent &&
        Number(item.answeredQuestions || 0) === Number(progress?.answeredQuestions || 0) &&
        Number(item.totalQuestions || 0) === Number(progress?.totalQuestions || 0)
      ) {
        return item;
      }
      return {
        ...item,
        status: nextStatus,
        progressPercent,
        answeredQuestions: Number(progress?.answeredQuestions || 0),
        totalQuestions: Number(progress?.totalQuestions || 0),
      };
    }));
  }, []);

  async function ensureQuestionnaireReady(evaluation) {
    if (!evaluation || evaluation.status === 'submitted' || viewOnly || isLocked) return true;

    const assignmentId = evaluation.id || evaluation.assignmentId;
    if (!assignmentId) {
      throw new Error('This evaluation assignment is missing an assignment ID.');
    }

    const params = new URLSearchParams({
      action: 'categories',
      assignment_id: String(assignmentId),
      role: evaluatorRole,
    });
    if (selectedPeriodId) params.set('period_id', selectedPeriodId);

    const payload = await apiFetch(`/api/evaluations.php?${params.toString()}`);
    if (!payload.ok) {
      throw new Error(payload.message || 'This evaluation form is not available yet.');
    }

    const categories = Array.isArray(payload.categories) ? payload.categories : [];
    const hasQuestions = categories.some((category) => Array.isArray(category.questions) && category.questions.length > 0);
    if (categories.length === 0 || !hasQuestions) {
      const formName = (payload.form_type || evaluation.questionnaireType) === 'form_a' || evaluation.questionnaireType === 'admin'
        ? 'PMAS Form A'
        : 'PMAS Form B';
      throw new Error(`${formName} has not been distributed yet. Please wait for Admin/HR to publish the questionnaire before evaluating this faculty member.`);
    }

    return true;
  }

  async function openNextEvaluation(evaluation) {
    await openEvaluation(evaluation);
  }

  function selfEvaluationRole() {
    const selfRole = normalizeRoleForSelfEvaluation(evaluatorRole);
    return selfRole === 'program_head' ? 'programHead' : selfRole;
  }

  function openSelfEvaluation(assignmentId = null) {
    setSelfEvaluationTask({ assignmentId });
  }

  async function initializeSelfEvaluationAssignment() {
    setInitializingSelf(true);
    try {
      const payload = await apiFetch('/api/evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'init_self_assignment', role: evaluatorRole, period_id: selectedPeriodId || null }),
      });
      if (!payload.ok) throw new Error(payload.message || 'Unable to create self-evaluation assignment.');
      notifyLiveDataChanged({ source: 'self-evaluation-init', assignmentId: payload.assignment_id || null });
      await loadAssignments();
      if (payload.assignment_id) openSelfEvaluation(payload.assignment_id);
    } catch (error) {
      setAssignmentError(error.message || 'Unable to create self-evaluation assignment.');
    } finally {
      setInitializingSelf(false);
    }
  }

  async function openEvaluation(evaluation) {
    if (isSelfEvaluationAssignment(evaluation)) {
      openSelfEvaluation(evaluation?.id || evaluation?.assignmentId || null);
      return;
    }
    const assignmentId = evaluation?.id || evaluation?.assignmentId || null;
    setAssignmentError('');
    setOpeningEvaluationId(assignmentId);
    try {
      await ensureQuestionnaireReady(evaluation);
      setActive(evaluation);
    } catch (error) {
      setActive(null);
      setAssignmentError(error.message || 'This evaluation form is not available yet.');
    } finally {
      setOpeningEvaluationId(null);
    }
  }

  const hiddenSections = new Set([
    ...(isVpaaEvaluation ? ['all', 'dean', 'program_head', 'faculty', 'peer'] : []),
    ...(isDeanEvaluation ? ['dean'] : []),
    ...(isProgramHeadEvaluation ? ['program_head'] : []),
    ...(isFacultyEvaluation ? ['faculty'] : []),
    ...(!peerLifecycle.isLocked ? ['peer'] : []),
  ]);
  const visibleSections = sections.filter(([key]) => !hiddenSections.has(key));
  const userSelfEvaluationRole = normalizeRoleForSelfEvaluation(evaluatorRole);
  const canOpenSelfEvaluation = !viewOnly && ['faculty', 'dean', 'vpaa', 'programHead'].includes(userSelfEvaluationRole);
  const selfEvaluationFormName = userSelfEvaluationRole === 'faculty' ? 'Faculty Self Evaluation' : 'Leadership Self Evaluation';
  const selfEvaluationAudience = userSelfEvaluationRole === 'faculty'
    ? 'Faculty Member'
    : userSelfEvaluationRole === 'vpaa'
    ? 'VPAA'
    : userSelfEvaluationRole === 'dean'
    ? 'Dean'
    : 'Program Head';
  const activeIsSelfEvaluation = isSelfEvaluationAssignment(active);
  const hasSelfEvaluationCard = accessibleEvaluations.some((item) => isSelfEvaluationAssignment(item) || item.section === 'self');
  return (
    <>
      <section className="dipascaf-evaluation-shell module-wide page-enter">
        <div className="dipascaf-eval-hero">
          <div className="evaluation-hero-copy">
            <p className="eyebrow">{eyebrow}</p>
            <h2>{title}</h2>
            <p>{subtitle}</p>
            {period && (
              <div className="evaluation-access-banner">
                <span className={`peer-status-badge ${period.is_open ? 'success' : 'danger'}`}>{period.is_open ? 'Open' : 'Locked'}</span>
                <strong>{period.period_name}</strong>
                <small>{period.school_year || 'School year not set'} • {period.semester || 'Semester not set'} • {period.date_start || 'Start date'} to {period.date_end || 'Due date'}</small>
              </div>
            )}
            <div className={`evaluation-page-period-selector evaluation-page-period-selector-bottom ${evaluatorRole === 'vpaa' ? 'vpaa-period-selector' : ''}`}>
              <PeriodSelector compact />
            </div>
            {liveRefreshing && <span className="live-refresh-indicator">Syncing latest evaluation data...</span>}
            {setupPanel && (
              <button type="button" className="compact-link" aria-expanded={setupOpen} onClick={() => setSetupOpen((open) => !open)}>
                {setupOpen ? 'Close Evaluation Assignment Setup' : 'Open Evaluation Assignment Setup'}
              </button>
            )}
          </div>
          <div className="evaluation-hero-chart" style={{ '--completion-rate': summary.percent, '--pending-rate': 100 - summary.percent }}>
            <div className="evaluation-completion-chart">
              <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle className="evaluation-completion-track" cx="60" cy="60" r="48" pathLength="100" />
                <circle className="evaluation-completion-pending" cx="60" cy="60" r="48" pathLength="100" />
                <circle className="evaluation-completion-done" cx="60" cy="60" r="48" pathLength="100" />
              </svg>
              <strong>{summary.percent}%</strong>
            </div>
            <div>
              <h3>Overall Completion</h3>
              <p>
                {isLocked
                  ? `${summary.done} completed evaluation${summary.done === 1 ? '' : 's'} available to view${lockedHiddenCount > 0 ? `, ${lockedHiddenCount} locked pending` : ''}.`
                  : `${summary.done} completed, ${summary.pending} pending, ${summary.total} total tasks.`}
              </p>
              <div className="completion-legend"><span className="done">Completed</span><span className="pending">Pending</span></div>
            </div>
          </div>
        </div>
        {setupPanel && setupOpen && <div className="assignment-setup-panel">{setupPanel}</div>}
        {assignmentError && <div className="notice warning">{assignmentError}</div>}
        {loadingAssignments && <div className="dipascaf-empty">Loading assigned evaluations from MySQL...</div>}

        {isLocked && (
          <div className="period-locked-banner">
            <div className="period-locked-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
              </svg>
            </div>
            <div className="period-locked-content">
              <h3>This Evaluation Period is Locked</h3>
              <p>The period <strong>"{period?.period_name || 'Selected Period'}"</strong> is currently locked. Completed evaluations can be viewed as results only.</p>
              <p>{lockedHiddenCount > 0 ? `${lockedHiddenCount} pending assignment${lockedHiddenCount === 1 ? ' is' : 's are'} hidden while this period is locked.` : 'No pending assignments are available while this period is locked.'}</p>
            </div>
          </div>
        )}

        <>
        {/* Deadline alert banner */}
        {!isLocked && (overdueCount > 0 || dueTodayCount > 0 || dueSoonCount > 0) && (
          <div className={`deadline-alert-banner ${overdueCount > 0 ? 'alert-overdue' : dueTodayCount > 0 ? 'alert-today' : 'alert-soon'}`}>
            {overdueCount > 0 && (
              <span><strong>{overdueCount}</strong> evaluation{overdueCount > 1 ? 's' : ''} overdue! </span>
            )}
            {dueTodayCount > 0 && (
              <span><strong>{dueTodayCount}</strong> evaluation{dueTodayCount > 1 ? 's' : ''} due today! </span>
            )}
            {dueSoonCount > 0 && overdueCount === 0 && dueTodayCount === 0 && (
              <span><strong>{dueSoonCount}</strong> evaluation{dueSoonCount > 1 ? 's' : ''} due within 3 days </span>
            )}
            Complete pending items to avoid late submissions.
          </div>
        )}

          <div className="dipascaf-stat-row">
            <article><span>Done</span><strong>{summary.done}</strong></article>
          {!isLocked && <article><span>In Progress</span><strong>{summary.inProgress}</strong></article>}
          <article><span>{isLocked ? 'Locked Hidden' : 'Pending'}</span><strong>{isLocked ? lockedHiddenCount : summary.pending}</strong></article>
          {!isLocked && summary.overdue > 0 && <article className="stat-overdue"><span>Overdue</span><strong>{summary.overdue}</strong></article>}
          <article><span>{isLocked ? 'Viewable' : 'Total'}</span><strong>{summary.total}</strong></article>
        </div>

          {!isVpaaEvaluation && (
            <nav className="dipascaf-eval-menu" aria-label="Evaluation sections">
              {visibleSections.map(([key, label]) => <button key={key} type="button" className={section === key ? 'active' : ''} onClick={() => setSection(key)}>{label}</button>)}
            </nav>
          )}
          <div className="dipascaf-filters">
            <label>Search<input type="search" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder={isVpaaEvaluation ? 'Search dean or department' : 'Search name or program'} /></label>
            {!isVpaaEvaluation && !isDeanEvaluation && section !== 'peer' && (
              <label>Role<select value={filters.role} onChange={(event) => updateFilter('role', event.target.value)}><option value="">All roles</option><option>Dean</option><option>Program Head</option><option>Faculty</option></select></label>
            )}
            {showDepartmentStatusFilters && (
              <label>{isVpaaEvaluation ? 'Department' : isDeanEvaluation ? 'Program/Course' : 'Department/Program'}<select value={filters.department} onChange={(event) => updateFilter('department', event.target.value)}><option value="">{isVpaaEvaluation ? 'All departments' : isDeanEvaluation ? 'All programs/courses' : 'All departments'}</option>{(isDeanEvaluation ? programCourses : departments).map((item) => <option key={item}>{item}</option>)}</select></label>
            )}
            {!isVpaaEvaluation && (
              <label>Status<select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}><option value="">All status</option>{statusOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            )}
            <label>Sort by<select value={filters.sort || ''} onChange={(event) => updateFilter('sort', event.target.value)}><option value="">Default</option><option value="deadline">Closest deadline</option><option value="name">Name A-Z</option></select></label>
          </div>
          <div className="dipascaf-card-grid eval-card-grid">
            {canOpenSelfEvaluation && !isLocked && !hasSelfEvaluationCard && (section === 'all' || section === 'self') && (
              <article className="dipascaf-eval-card eval-assignment-card pending card-pop self-eval-init-card">
                <div className="dipascaf-card-cover" aria-hidden="true" />
                <div className="dipascaf-card-top">
                  <div className="dipascaf-avatar">S</div>
                  <div className="dipascaf-card-badges">
                    <span className="dipascaf-status pending">Pending</span>
                  </div>
                </div>
                <h3>{selfEvaluationFormName}</h3>
                <p>{selfEvaluationAudience}</p>
                <div className="dipascaf-card-meta">
                  <span className="dipascaf-meta-row full">
                    <small>Status</small>
                    <strong>Assignment not initialized</strong>
                  </span>
                </div>
                <div className="peer-confidential-note">
                  <strong>Create your self-evaluation assignment for this period.</strong>
                </div>
                <div className="dipascaf-card-actions">
                  <button type="button" className="dipascaf-evaluate-btn" onClick={initializeSelfEvaluationAssignment} disabled={initializingSelf}>
                    {initializingSelf ? 'Creating...' : 'Create Self-Evaluation'}
                  </button>
                </div>
              </article>
            )}
            {visible.map((evaluation) => (
              <EvaluationCard
                key={evaluation.id}
                evaluation={evaluation}
                onOpen={openEvaluation}
                readOnly={viewOnly || isLocked}
                periodLocked={isLocked}
                busy={Number(openingEvaluationId) === Number(evaluation.id)}
              />
            ))}
          </div>
          {!loadingAssignments && visible.length === 0 && (
            <div className="dipascaf-empty">
              {section === 'peer' && !peerLifecycle.isLocked
                ? 'No peer to peer evaluator assigned yet.'
                : isLocked
                ? 'This evaluation period is locked. Only completed evaluations are available to view, and no completed evaluations match the current filters.'
                : section === 'peer'
                ? 'No peer to peer evaluator assigned yet.'
                : 'No assigned evaluation forms are available for your account in the selected evaluation period.'}
            </div>
          )}
        </>
      </section>
      <EvaluationModal
        evaluation={activeIsSelfEvaluation ? null : active}
        onClose={() => setActive(null)}
        onSubmit={submitEvaluation}
        readOnly={viewOnly || isLocked}
        evaluatorRole={evaluatorRole}
        period={period}
        assignments={accessibleEvaluations}
        onEvaluateNext={openNextEvaluation}
        onProgress={updateEvaluationProgress}
      />
      {selfEvaluationTask && createPortal((
        <div className="dipascaf-modal-backdrop self-eval-task-backdrop" onClick={(event) => event.target === event.currentTarget && setSelfEvaluationTask(null)}>
          <div className="dipascaf-modal-panel eval-form-panel self-eval-task-panel" role="dialog" aria-modal="true" aria-label={selfEvaluationFormName}>
            <button type="button" className="dipascaf-modal-close modal-icon-close" onClick={() => setSelfEvaluationTask(null)} aria-label="Close self evaluation form">
              <X size={18} />
            </button>
            <SelfEvaluationModule
              role={role || { key: selfEvaluationRole(), user: {} }}
              assignmentId={selfEvaluationTask.assignmentId}
              displayMode="task"
              onSubmitted={async () => {
                notifyLiveDataChanged({ source: 'self-evaluation-submit', assignmentId: selfEvaluationTask.assignmentId || null });
                await loadAssignments(false);
              }}
              pendingEvaluations={accessibleEvaluations.filter((item) => item.section !== 'self' && item.status !== 'submitted' && item.status !== 'overdue')}
              onEvaluateNext={(item) => {
                setSelfEvaluationTask(null);
                openNextEvaluation(item);
              }}
              onFinish={() => setSelfEvaluationTask(null)}
            />
          </div>
        </div>
      ), document.body)}
    </>
  );
}
