import { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import {
  AlertTriangle, Building2, CheckCircle2, ClipboardList, Eye, RefreshCw, Search,
  TrendingUp, Users,
} from 'lucide-react';
import apiFetch from '../../data/api.js';
import VpaaDepartmentReport from './VpaaDepartmentReport.jsx';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';

const API_BASE = '/api/vpaa-evaluation-monitor.php';

// ─── Loading Skeleton ────────────────────────────────────────────────
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

// ─── Department List View ────────────────────────────────────────────
function DepartmentListView({ onSelectDepartment, selectedPeriodId }) {
  const [departments, setDepartments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [searchQuery, setSearchQuery] = useState('');

  const loadDepartments = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ scope: 'departments' });
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
      if (payload.ok && Array.isArray(payload.data)) {
        setDepartments(payload.data);
      } else {
        setError(payload.message || 'Failed to load departments.');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }, [selectedPeriodId]);

  useEffect(() => {
    loadDepartments();
  }, [loadDepartments]);

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

  const totals = useMemo(() => departments.reduce((acc, dept) => {
    acc.departments += 1;
    acc.programs += Number(dept.program_count || 0);
    acc.evaluations += Number(dept.total_evaluations || 0);
    acc.completed += Number(dept.completed || 0);
    acc.pending += Number(dept.pending || 0);
    acc.overdue += Number(dept.overdue || 0);
    return acc;
  }, {
    departments: 0,
    programs: 0,
    evaluations: 0,
    completed: 0,
    pending: 0,
    overdue: 0,
  }), [departments]);

  const completionRate = totals.evaluations > 0 ? Math.round((totals.completed / totals.evaluations) * 100) : 0;

  const mainRef = useRef(null);
  useEffect(() => {
    if (mainRef.current) {
      mainRef.current.scrollTo?.({ top: 0, behavior: 'smooth' });
      mainRef.current.scrollTop = 0;
    }
  }, []);

  return (
    <div className="eval-monitor-container vpaa-analytics-list">
      <div className="eval-monitor-main" ref={mainRef}>
        <div className="role-summary-header">
          <div>
            <p className="eyebrow">VPAA Summary</p>
            <h2><Eye size={22} /> Academic Evaluation Overview</h2>
            <p>Select a department to review completion, average scores, weak areas, and program performance for the active period.</p>
          </div>
          <button type="button" className="eval-monitor-btn ghost" onClick={loadDepartments}>
            <RefreshCw size={16} /> Refresh
          </button>
        </div>

        <div className="role-summary-band vpaa-analytics-list-hero">
          <div className="role-summary-donut" style={{ '--pct': `${completionRate}%` }}>
            <strong>{completionRate}%</strong>
            <span>Complete</span>
          </div>
          <div className="role-summary-metrics">
            <article><Building2 size={18} /><span>Departments</span><strong>{totals.departments}</strong></article>
            <article><ClipboardList size={18} /><span>Evaluations</span><strong>{totals.evaluations}</strong></article>
            <article><CheckCircle2 size={18} /><span>Completed</span><strong>{totals.completed}</strong></article>
            <article><AlertTriangle size={18} /><span>Pending</span><strong>{totals.pending}</strong></article>
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
                placeholder="Search departments..."
              />
            </div>
            <div className="eval-monitor-toolbar-actions">
              <span className="role-summary-chip"><TrendingUp size={14} /> {filteredDepartments.length} shown</span>
            </div>
          </div>

          {loading ? (
            <LoadingSkeleton />
          ) : error ? (
            <div className="eval-monitor-empty error">{error}</div>
          ) : filteredDepartments.length === 0 ? (
            <div className="eval-monitor-empty">{searchQuery ? 'No departments match your search.' : 'No departments assigned.'}</div>
          ) : (
            <div className="role-summary-list">
              {filteredDepartments.map((dept) => (
                <div
                  key={dept.id}
                  className="role-summary-row clickable"
                  onClick={() => onSelectDepartment(dept.id)}
                  onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelectDepartment(dept.id); } }}
                  tabIndex={0}
                  role="button"
                  aria-label={`View report for ${dept.department_name}`}
                >
                  <div className="role-summary-row-main">
                    <div className="role-summary-row-icon">
                      <Building2 size={22} />
                    </div>
                    <div>
                      <h3>{dept.department_name}</h3>
                      <span>{dept.department_code} · Dean: {dept.dean_name || 'Unassigned'}</span>
                      {dept.archived_faculty_count > 0 && (
                        <span className="archived-faculty-badge" style={{ marginLeft: '0.5rem' }}>
                          {dept.archived_faculty_count} Archived
                        </span>
                      )}
                    </div>
                  </div>
                  <div className="role-summary-row-stats">
                    <span><Users size={14} /> {dept.program_count || 0} programs</span>
                    <span><ClipboardList size={14} /> {dept.total_evaluations || 0} tasks</span>
                    <span><CheckCircle2 size={14} /> {dept.completed || 0} done</span>
                    <strong>{dept.completion_pct ?? 0}%</strong>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Router: manages list ↔ report switching ────────────────────────
export default function VpaaEvaluationMonitorRouter() {
  const { selectedPeriodId } = useEvaluationPeriod();
  const [selectedDeptId, setSelectedDeptId] = useState(null);

  if (selectedDeptId) {
    return (
      <VpaaDepartmentReport
        departmentId={selectedDeptId}
        periodId={selectedPeriodId}
        onBack={() => setSelectedDeptId(null)}
      />
    );
  }

  return <DepartmentListView onSelectDepartment={setSelectedDeptId} selectedPeriodId={selectedPeriodId} />;
}
