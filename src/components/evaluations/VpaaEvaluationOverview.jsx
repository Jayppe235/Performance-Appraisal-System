import { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import {
  Building2, Search, Users, ClipboardCheck, Clock, AlertTriangle,
  ChevronDown, ChevronRight, X, Filter, BookOpen, UserCheck,
  UserPlus, RefreshCw,
} from 'lucide-react';
import apiFetch from '../../data/api.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import useLiveRefresh from '../../hooks/useLiveRefresh.js';

const API_BASE = '/api/vpaa-evaluation-monitor.php';

// ─── Status Badge ──────────────────────────────────────────────────
function StatusBadge({ status, label }) {
  let cls = 'vpaa-badge vpaa-badge-';
  if (status === 'completed' || status === 'submitted') cls += 'success';
  else if (status === 'pending') cls += 'warning';
  else if (status === 'overdue') cls += 'danger';
  else cls += 'default';
  return <span className={cls}>{label || status}</span>;
}

// ─── Loading Skeleton ──────────────────────────────────────────────
function LoadingSkeleton() {
  return (
    <div className="vpaa-skeleton">
      {[1, 2, 3].map((i) => (
        <div key={i} className="vpaa-skeleton-card">
          <div className="vpaa-skeleton-line w-32" />
          <div className="vpaa-skeleton-line w-48" />
          <div className="vpaa-skeleton-line w-full" />
        </div>
      ))}
    </div>
  );
}

// ─── Empty State ───────────────────────────────────────────────────
function EmptyState({ message, icon }) {
  const Icon = icon || ClipboardCheck;
  return (
    <div className="vpaa-empty-state">
      <Icon size={40} strokeWidth={1.5} />
      <p>{message}</p>
    </div>
  );
}

// ─── Evaluation Section (expandable subsection) ───────────────────
function EvaluationSection({ title, icon: Icon, evaluations, defaultOpen }) {
  const [open, setOpen] = useState(defaultOpen !== false);

  if (!evaluations || evaluations.length === 0) return null;

  const done = evaluations.filter((e) => e.status === 'submitted').length;
  const pending = evaluations.filter((e) => e.status === 'pending').length;
  const overdue = evaluations.filter((e) => e.status === 'overdue').length;

  return (
    <div className="vpaa-eval-section">
      <button
        type="button"
        className="vpaa-eval-section-header"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
      >
        <div className="vpaa-eval-section-title">
          <Icon size={18} strokeWidth={1.5} />
          <strong>{title}</strong>
          <span className="vpaa-eval-count">{evaluations.length}</span>
        </div>
        <div className="vpaa-eval-section-summary">
          {done > 0 && <StatusBadge status="completed" label={`${done} Done`} />}
          {pending > 0 && <StatusBadge status="pending" label={`${pending} Pending`} />}
          {overdue > 0 && <StatusBadge status="overdue" label={`${overdue} Overdue`} />}
          {open ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
        </div>
      </button>

      {open && (
        <div className="vpaa-eval-section-body">
          <div className="vpaa-eval-table">
            <div className="vpaa-eval-table-head">
              <span className="vpaa-eval-col-name">Evaluatee</span>
              <span className="vpaa-eval-col-evaluator">Evaluator</span>
              <span className="vpaa-eval-col-status">Status</span>
              <span className="vpaa-eval-col-deadline">Deadline</span>
            </div>
            {evaluations.map((ev) => (
              <div key={ev.id} className="vpaa-eval-table-row">
                <span className="vpaa-eval-col-name">{ev.evaluatee_name}</span>
                <span className="vpaa-eval-col-evaluator">{ev.evaluator_name}</span>
                <span className="vpaa-eval-col-status">
                  <StatusBadge
                    status={ev.status}
                    label={ev.status === 'submitted' ? 'Completed' : ev.status === 'overdue' ? 'Overdue' : 'Pending'}
                  />
                </span>
                <span className="vpaa-eval-col-deadline">{ev.deadline || '—'}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Program Card (expandable) ────────────────────────────────────
function ProgramCard({ program }) {
  const [open, setOpen] = useState(true);

  const { evaluations = {} } = program;
  const facultyEvals = evaluations.faculty || [];
  const deanEvals = evaluations.dean || [];
  const programHeadEvals = evaluations.program_head || [];
  const peerEvals = evaluations.peer || [];

  const hasContent = facultyEvals.length > 0 || deanEvals.length > 0 || programHeadEvals.length > 0 || peerEvals.length > 0;
  const totalEvals = program.total_evaluations || 0;
  const doneEvals = program.completed || 0;
  const pct = totalEvals > 0 ? Math.round((doneEvals / totalEvals) * 100) : 0;
  const progressClass = pct >= 75 ? 'high' : pct >= 40 ? 'medium' : 'low';

  return (
    <div className="vpaa-program-card">
      <button
        type="button"
        className="vpaa-program-card-header"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
      >
        <div className="vpaa-program-card-title">
          <BookOpen size={20} strokeWidth={1.5} />
          <div>
            <strong>{program.program_name}</strong>
            <span className="vpaa-program-code">{program.program_code}</span>
          </div>
        </div>
        <div className="vpaa-program-card-meta">
          <span className="vpaa-program-head">{program.program_head_name}</span>
          <span className="vpaa-program-stats">
            {doneEvals}/{totalEvals}
          </span>
          <div className="vpaa-program-bar-wrapper">
            <div className={`vpaa-program-bar ${progressClass}`}>
              <span style={{ width: `${pct}%` }} />
            </div>
            <small>{pct}%</small>
          </div>
          {open ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
        </div>
      </button>

      {open && (
        <div className="vpaa-program-card-body">
          {!hasContent ? (
            <div className="vpaa-empty-inset">No evaluations assigned for this program.</div>
          ) : (
            <>
              <EvaluationSection title="Faculty Evaluations" icon={Users} evaluations={facultyEvals} />
              <EvaluationSection title="Dean Evaluations" icon={UserCheck} evaluations={deanEvals} />
              <EvaluationSection title="Program Head Evaluations" icon={UserPlus} evaluations={programHeadEvals} />
              <EvaluationSection title="Peer Evaluations" icon={Users} evaluations={peerEvals} />
            </>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Summary Cards ────────────────────────────────────────────────
function SummaryCards({ summary }) {
  if (!summary) return null;

  return (
    <section className="vpaa-summary-section" aria-label="Evaluation summary">
      <div className="vpaa-summary-heading">
        <div>
          <p>Evaluation Summary</p>
          <strong>Current department snapshot</strong>
        </div>
      </div>
      <div className="vpaa-summary-grid">
        <article className="vpaa-summary-card vpaa-summary-primary">
          <Building2 size={22} strokeWidth={1.5} />
          <div>
            <strong>{summary.total_departments ?? 1}</strong>
            <span>Department{summary.total_departments !== 1 ? 's' : ''}</span>
          </div>
        </article>
        <article className="vpaa-summary-card vpaa-summary-info">
          <BookOpen size={22} strokeWidth={1.5} />
          <div>
            <strong>{summary.total_programs ?? 0}</strong>
            <span>Programs</span>
          </div>
        </article>
        <article className="vpaa-summary-card vpaa-summary-primary">
          <ClipboardCheck size={22} strokeWidth={1.5} />
          <div>
            <strong>{summary.total_evaluations ?? 0}</strong>
            <span>Total Evaluations</span>
          </div>
        </article>
        <article className="vpaa-summary-card vpaa-summary-warning">
          <Clock size={22} strokeWidth={1.5} />
          <div>
            <strong>{summary.pending ?? 0}</strong>
            <span>Pending</span>
          </div>
        </article>
        <article className="vpaa-summary-card vpaa-summary-danger">
          <AlertTriangle size={22} strokeWidth={1.5} />
          <div>
            <strong>{summary.overdue ?? 0}</strong>
            <span>Overdue</span>
          </div>
        </article>
        <article className="vpaa-summary-card vpaa-summary-success">
          <ClipboardCheck size={22} strokeWidth={1.5} />
          <div>
            <strong>{summary.completed ?? 0}</strong>
            <span>Completed</span>
          </div>
        </article>
      </div>
    </section>
  );
}

// ─── Main Component ───────────────────────────────────────────────
export default function VpaaEvaluationOverview() {
  const { selectedPeriodId } = useEvaluationPeriod();

  // Departments list for the filter dropdown
  const [departments, setDepartments] = useState([]);
  const [deptLoading, setDeptLoading] = useState(true);
  const [deptError, setDeptError] = useState('');

  // Selected department + data
  const [selectedDeptId, setSelectedDeptId] = useState('');
  const [deptData, setDeptData] = useState(null);
  const [dataLoading, setDataLoading] = useState(false);
  const [dataError, setDataError] = useState('');

  // Search filter for department dropdown
  const [deptSearch, setDeptSearch] = useState('');
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const dropdownRef = useRef(null);
  const searchInputRef = useRef(null);

  // Load departments on mount
  const loadDepartments = useCallback(async (background = false) => {
    if (!background) setDeptLoading(true);
    setDeptError('');
    try {
      const params = new URLSearchParams({ scope: 'departments' });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
      if (payload.ok && Array.isArray(payload.data)) {
        setDepartments(payload.data);
      } else {
        setDeptError(payload.message || 'Failed to load departments.');
      }
    } catch (err) {
      setDeptError(err.message);
    } finally {
      if (!background) setDeptLoading(false);
    }
  }, [selectedPeriodId]);

  const { refreshing: deptRefreshing } = useLiveRefresh(loadDepartments, [selectedPeriodId], {
    intervalMs: 8000,
  });

  const loadEvaluationData = useCallback(async (background = false) => {
    if (!selectedDeptId) {
      setDeptData(null);
      return;
    }
    if (!background) setDataLoading(true);
    setDataError('');
    try {
      const params = new URLSearchParams({ scope: 'evaluations', department_id: selectedDeptId });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      params.set('_', String(Date.now()));
      const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
      if (payload.ok && payload.data) {
        setDeptData(payload.data);
      } else {
        setDataError(payload.message || 'Failed to load evaluation data.');
      }
    } catch (err) {
      setDataError(err.message);
    } finally {
      if (!background) setDataLoading(false);
    }
  }, [selectedDeptId, selectedPeriodId]);

  const { refreshing: dataRefreshing } = useLiveRefresh(loadEvaluationData, [selectedDeptId, selectedPeriodId], {
    enabled: !!selectedDeptId,
    intervalMs: 6000,
  });

  // Filter departments by search
  const filteredDepts = useMemo(() => {
    if (!deptSearch) return departments;
    const q = deptSearch.toLowerCase();
    return departments.filter(
      (d) =>
        (d.department_name || '').toLowerCase().includes(q) ||
        (d.department_code || '').toLowerCase().includes(q)
    );
  }, [departments, deptSearch]);

  // Close dropdown on outside click
  useEffect(() => {
    function handleClick(e) {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setDropdownOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);

  // Focus search input when dropdown opens
  useEffect(() => {
    if (dropdownOpen && searchInputRef.current) {
      searchInputRef.current.focus();
      loadDepartments(true);
    }
  }, [dropdownOpen, loadDepartments]);

  // Get selected department name
  const selectedDept = departments.find((d) => String(d.id) === selectedDeptId);

  // Build summary for selected department
  const summary = useMemo(() => {
    if (!deptData) return null;
    return {
      total_departments: 1,
      total_programs: deptData.programs?.length || 0,
      total_evaluations: deptData.summary?.total_evaluations || 0,
      pending: deptData.summary?.pending || 0,
      overdue: deptData.summary?.overdue || 0,
      completed: deptData.summary?.completed || 0,
    };
  }, [deptData]);

  return (
    <div className="vpaa-overview">
      {/* ── Sticky Filter Bar ─────────────────────────── */}
      <div className="vpaa-filter-bar">
        <div className="vpaa-filter-bar-inner">
          <div className="vpaa-filter-label">
            <Filter size={16} strokeWidth={1.5} />
            <span>Department</span>
          </div>

          <div className="vpaa-dropdown" ref={dropdownRef}>
            <button
              type="button"
              className="vpaa-dropdown-trigger"
              onClick={() => setDropdownOpen(!dropdownOpen)}
            >
              <Building2 size={16} strokeWidth={1.5} />
              <span className={selectedDept ? '' : 'vpaa-placeholder'}>
                {selectedDept ? selectedDept.department_name : 'Select a department...'}
              </span>
              <ChevronDown size={16} className={`vpaa-dropdown-arrow ${dropdownOpen ? 'open' : ''}`} />
            </button>

            {dropdownOpen && (
              <div className="vpaa-dropdown-menu">
                <div className="vpaa-dropdown-search">
                  <Search size={14} />
                  <input
                    ref={searchInputRef}
                    type="text"
                    value={deptSearch}
                    onChange={(e) => setDeptSearch(e.target.value)}
                    placeholder="Search departments..."
                  />
                </div>
                <div className="vpaa-dropdown-options">
                  {deptLoading ? (
                    <div className="vpaa-dropdown-empty">Loading...</div>
                  ) : deptError ? (
                    <div className="vpaa-dropdown-empty error">{deptError}</div>
                  ) : filteredDepts.length === 0 ? (
                    <div className="vpaa-dropdown-empty">No departments found.</div>
                  ) : (
                    filteredDepts.map((dept) => (
                      <button
                        key={dept.id}
                        type="button"
                        className={`vpaa-dropdown-option ${String(dept.id) === selectedDeptId ? 'active' : ''}`}
                        onClick={() => {
                          setSelectedDeptId(String(dept.id));
                          setDropdownOpen(false);
                          setDeptSearch('');
                        }}
                      >
                        <div className="vpaa-dropdown-option-info">
                          <strong>{dept.department_name}</strong>
                          <span className="vpaa-dropdown-option-code">{dept.department_code}</span>
                        </div>
                        <span className="vpaa-dropdown-option-dean">{dept.dean_name}</span>
                      </button>
                    ))
                  )}
                </div>
              </div>
            )}
          </div>

          {selectedDeptId && (
            <button
              type="button"
              className="vpaa-clear-btn"
              onClick={() => {
                setSelectedDeptId('');
                setDeptData(null);
              }}
              title="Clear department selection"
            >
              <X size={16} />
            </button>
          )}

          <button
            type="button"
            className="vpaa-refresh-btn"
            onClick={() => {
              loadDepartments(false);
              if (selectedDeptId) {
                loadEvaluationData(false);
              }
            }}
            title="Refresh"
          >
            <RefreshCw size={16} className={(deptRefreshing || dataRefreshing) ? 'spin-soft' : ''} />
          </button>
          {(deptRefreshing || dataRefreshing) && <span className="live-refresh-indicator compact">Syncing...</span>}
        </div>
      </div>

      {/* ── Summary Cards ─────────────────────────────── */}
      {summary && <SummaryCards summary={summary} />}

      {/* ── Content Area ──────────────────────────────── */}
      <div className="vpaa-content">
        {!selectedDeptId ? (
          <EmptyState
            icon={Building2}
            message="Select a department above to view its evaluation overview."
          />
        ) : dataLoading ? (
          <LoadingSkeleton />
        ) : dataError ? (
          <div className="vpaa-error">{dataError}</div>
        ) : deptData ? (
          <>
            {/* Department Info */}
            <div className="vpaa-dept-hero">
              <div className="vpaa-dept-hero-icon">
                <Building2 size={28} strokeWidth={1.5} />
              </div>
              <div className="vpaa-dept-hero-info">
                <p className="vpaa-dept-hero-eyebrow">Department Evaluation Overview</p>
                <h2>{deptData.department.department_name}</h2>
                <p className="vpaa-dept-hero-details">
                  <span>Dean: {deptData.department.dean_name}</span>
                  <span className="vpaa-dept-hero-sep">•</span>
                  <span>{deptData.faculty_count} Faculty</span>
                  <span className="vpaa-dept-hero-sep">•</span>
                  <span>{deptData.programs.length} Program{deptData.programs.length !== 1 ? 's' : ''}</span>
                </p>
              </div>
            </div>

            {/* Programs with Evaluations */}
            <div className="vpaa-programs-list">
              {deptData.programs.length === 0 ? (
                <EmptyState icon={BookOpen} message="No programs found under this department." />
              ) : (
                deptData.programs.map((prog) => (
                  <ProgramCard key={prog.id} program={prog} />
                ))
              )}
            </div>
          </>
        ) : null}
      </div>
    </div>
  );
}
