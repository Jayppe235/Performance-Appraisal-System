import { useEffect, useState } from 'react';
import {
  Building2, Users, ClipboardCheck, Clock, AlertTriangle,
  TrendingUp, TrendingDown, ChevronLeft, Download,
  BarChart3, Award, Sparkles, ChevronDown, ChevronRight,
  Lightbulb, GraduationCap, Target, FileText, CheckCircle2,
} from 'lucide-react';
import apiFetch from '../../data/api.js';

const API_BASE = '/api/vpaa-evaluation-monitor.php';

// ─── Utility helpers ─────────────────────────────────────────────────
function statusBadge(status, value) {
  const styles = { completed: 'badge-success', pending: 'badge-warning', overdue: 'badge-danger' };
  const labels = { completed: 'Completed', pending: 'Pending', overdue: 'Overdue' };
  return (
    <span className={`eval-monitor-badge ${styles[status] || 'badge-default'}`}>
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

function scorePill(score) {
  if (!score || score === 0) return <span className="eval-monitor-score-pill none">—</span>;
  const cls = score >= 4 ? 'high' : score >= 3 ? 'mid' : 'low';
  return <span className={`eval-monitor-score-pill ${cls}`}>{score.toFixed(1)}</span>;
}

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

// ─── Score Analysis Section ──────────────────────────────────────────
function ScoreAnalysis({ categoryScores, averageScore }) {
  if (!categoryScores || categoryScores.length === 0) return null;

  const weakest = categoryScores[0];
  const strongest = categoryScores[categoryScores.length - 1];

  return (
    <div className="eval-monitor-section" style={{ marginTop: '1.5rem' }}>
      <h4 style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginBottom: '1rem' }}>
        <BarChart3 size={18} /> Category Score Analysis
      </h4>

      <div className="eval-monitor-category-grid">
        {categoryScores.map((cs, i) => (
          <div key={i} className="eval-monitor-category-card">
            <div className="eval-monitor-category-head">
              <strong>{cs.category}</strong>
              <span className={cs.score >= 4 ? 'high' : cs.score >= 3 ? 'mid' : 'low'}>
                {cs.score.toFixed(2)}
              </span>
            </div>
            {completionBar(cs.score * 20)}
            <small className="eval-monitor-evidence">
              Weight: {cs.weight}% | Weighted: {cs.weighted_score.toFixed(2)}
            </small>
          </div>
        ))}
      </div>

      {averageScore > 0 && (
        <div style={{ display: 'flex', gap: '1rem', marginTop: '1rem', flexWrap: 'wrap' }}>
          {weakest && (
            <div className="eval-monitor-ai-insight-weak" style={{ flex: 1, minWidth: '200px' }}>
              <TrendingDown size={14} />
              <div>
                <small>Weakest Area</small>
                <strong>{weakest.category}</strong>
                <span className="score">{weakest.score.toFixed(2)}/5</span>
              </div>
            </div>
          )}
          {strongest && (
            <div className="eval-monitor-ai-insight-strong" style={{ flex: 1, minWidth: '200px' }}>
              <TrendingUp size={14} />
              <div>
                <small>Strongest Area</small>
                <strong>{strongest.category}</strong>
                <span className="score">{strongest.score.toFixed(2)}/5</span>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Main Report Component ───────────────────────────────────────────
// ─── Recommendations Section ──────────────────────────────────────────
function RecommendationsSection({ departmentId, periodId, departmentName }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expandedAreas, setExpandedAreas] = useState({});
  const [expandedPlans, setExpandedPlans] = useState(false);

  useEffect(() => {
    let alive = true;
    async function loadData() {
      setLoading(true);
      setError('');
      try {
        const params = new URLSearchParams({ scope: 'recommendations', department_id: String(departmentId) });
        if (periodId) params.set('period_id', periodId);
        const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
        if (alive) {
          if (payload.ok && payload.data) {
            setData(payload.data);
          } else {
            setError(payload.message || 'Failed to load recommendations.');
          }
        }
      } catch (err) {
        if (alive) setError(err.message);
      } finally {
        if (alive) setLoading(false);
      }
    }
    loadData();
    return () => { alive = false; };
  }, [departmentId, periodId]);

  if (loading) {
    return (
      <div className="eval-monitor-section" style={{ marginTop: '1.5rem' }}>
        <div className="eval-monitor-skeleton">
          {[1, 2].map((i) => (
            <div key={i} className="eval-monitor-skeleton-card">
              <div className="skeleton-line w-32" />
              <div className="skeleton-line w-full" />
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return <div className="eval-monitor-empty error" style={{ marginTop: '1.5rem' }}>{error}</div>;
  }

  if (!data) return null;

  const { weak_areas, all_categories, intervention_plans, summary } = data;

  function toggleArea(category) {
    setExpandedAreas((prev) => ({ ...prev, [category]: !prev[category] }));
  }

  return (
    <div className="eval-monitor-section" style={{ marginTop: '1.5rem' }}>
      {/* ── Summary Banner ── */}
      <div style={{
        background: 'linear-gradient(135deg, #1a4a3a, #2e6f5e)',
        borderRadius: '12px',
        padding: '1.25rem 1.5rem',
        marginBottom: '1.5rem',
        color: '#fff',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.75rem' }}>
          <Lightbulb size={22} />
          <h3 style={{ margin: 0, fontWeight: 700, fontSize: '1.1rem' }}>Recommendations &amp; Development Plan</h3>
        </div>
        <p style={{ opacity: 0.9, fontSize: '0.875rem', margin: 0 }}>
          Department-wide analytics showing weak areas, recommended seminars, and active intervention plans for {departmentName}.
        </p>
        {summary && (
          <div style={{ display: 'flex', gap: '1rem', marginTop: '0.75rem', flexWrap: 'wrap' }}>
            <span style={{ background: 'rgba(255,255,255,0.15)', borderRadius: '6px', padding: '0.25rem 0.75rem', fontSize: '0.8rem' }}>
              <strong>{summary.total_weak_areas}</strong> weak areas identified
            </span>
            <span style={{ background: 'rgba(255,255,255,0.15)', borderRadius: '6px', padding: '0.25rem 0.75rem', fontSize: '0.8rem' }}>
              <strong>{summary.total_interventions}</strong> intervention plan{summary.total_interventions !== 1 ? 's' : ''}
            </span>
            <span style={{ background: 'rgba(255,255,255,0.15)', borderRadius: '6px', padding: '0.25rem 0.75rem', fontSize: '0.8rem' }}>
              <strong>{summary.active_interventions}</strong> active
            </span>
          </div>
        )}
      </div>

      {/* ── Weak Areas with Recommended Seminars ── */}
      <h4 style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginBottom: '1rem' }}>
        <Target size={18} /> Weak Areas &amp; Recommended Seminars
      </h4>

      {weak_areas.length === 0 ? (
        <div className="eval-monitor-ai-card praise" style={{ marginBottom: '1.5rem' }}>
          <CheckCircle2 size={18} />
          <span>No significant weak areas detected. All categories are performing at satisfactory or higher levels.</span>
        </div>
      ) : (
        <div style={{ display: 'grid', gap: '0.75rem', marginBottom: '1.5rem' }}>
          {weak_areas.map((area, i) => (
            <div key={i} style={{
              background: '#fff',
              border: '1px solid #e2e8f0',
              borderRadius: '10px',
              overflow: 'hidden',
              boxShadow: '0 1px 3px rgba(0,0,0,0.06)',
            }}>
              <button
                type="button"
                onClick={() => toggleArea(area.weak_area)}
                aria-expanded={!!expandedAreas[area.weak_area]}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  width: '100%',
                  padding: '0.875rem 1rem',
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  textAlign: 'left',
                  fontSize: '0.95rem',
                  fontWeight: 600,
                  color: '#1e293b',
                  gap: '0.5rem',
                  minHeight: 'auto',
                  borderRadius: 0,
                  boxShadow: 'none',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flex: 1 }}>
                  <span style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: '28px',
                    height: '28px',
                    borderRadius: '8px',
                    background: area.average_score <= 3 ? '#fef2f2' : '#fffbeb',
                    color: area.average_score <= 3 ? '#dc2626' : '#d97706',
                    fontSize: '0.75rem',
                    fontWeight: 700,
                    flexShrink: 0,
                  }}>
                    {area.average_score.toFixed(1)}
                  </span>
                  <span>{area.weak_area}</span>
                  <span style={{ fontSize: '0.75rem', color: '#64748b', fontWeight: 400 }}>
                    ({area.faculty_count} faculty affected)
                  </span>
                </div>
                {expandedAreas[area.weak_area] ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
              </button>

              {expandedAreas[area.weak_area] && (
                <div style={{ padding: '0 1rem 1rem', borderTop: '1px solid #f1f5f9' }}>
                  <div style={{ marginTop: '0.75rem', display: 'grid', gap: '0.75rem' }}>
                    <div style={{
                      background: '#f0fdf4',
                      border: '1px solid #bbf7d0',
                      borderRadius: '8px',
                      padding: '0.75rem',
                    }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.8rem', fontWeight: 600, color: '#166534', marginBottom: '0.25rem' }}>
                        <GraduationCap size={14} />
                        <span>Recommended Seminar</span>
                      </div>
                      <p style={{ margin: 0, fontSize: '0.875rem', color: '#14532d' }}>{area.recommended_seminar}</p>
                    </div>

                    <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', fontSize: '0.8rem', color: '#64748b' }}>
                      <span>Average score: <strong>{area.average_score.toFixed(2)}</strong></span>
                      <span>•</span>
                      <span>{area.total_results} total result{area.total_results !== 1 ? 's' : ''}</span>
                    </div>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {/* ── All Categories Performance ── */}
      {all_categories?.length > 0 && (
        <>
          <h4 style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginBottom: '1rem', marginTop: '1.5rem' }}>
            <BarChart3 size={18} /> All Evaluation Categories
          </h4>
          <div style={{ display: 'grid', gap: '0.5rem', marginBottom: '1.5rem' }}>
            {all_categories.map((cat, i) => (
              <div key={i} style={{
                display: 'flex',
                alignItems: 'center',
                gap: '0.75rem',
                padding: '0.625rem 0.875rem',
                background: '#f8fafc',
                borderRadius: '8px',
                fontSize: '0.85rem',
              }}>
                <span style={{
                  width: '6px',
                  height: '6px',
                  borderRadius: '50%',
                  flexShrink: 0,
                  background: cat.rating_level === 'excellent' ? '#22c55e' : cat.rating_level === 'satisfactory' ? '#eab308' : '#ef4444',
                }} />
                <span style={{ flex: 1, fontWeight: 500, color: '#334155' }}>{cat.category}</span>
                <span style={{
                  fontWeight: 700,
                  fontSize: '0.8rem',
                  color: cat.average_score >= 4 ? '#15803d' : cat.average_score >= 3 ? '#a16207' : '#dc2626',
                }}>{cat.average_score.toFixed(2)}</span>
                <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>{cat.result_count} result{cat.result_count !== 1 ? 's' : ''}</span>
                {cat.recommended_seminar && (
                  <span style={{
                    fontSize: '0.7rem',
                    color: '#64748b',
                    maxWidth: '200px',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    background: '#f1f5f9',
                    padding: '0.125rem 0.5rem',
                    borderRadius: '4px',
                  }} title={cat.recommended_seminar}>
                    <GraduationCap size={10} style={{ display: 'inline', marginRight: '0.25rem' }} />
                    {cat.recommended_seminar}
                  </span>
                )}
              </div>
            ))}
          </div>
        </>
      )}

      {/* ── Intervention Plans ── */}
      {intervention_plans?.length > 0 && (
        <>
          <h4 style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginBottom: '1rem', marginTop: '1.5rem' }}>
            <FileText size={18} /> Faculty Intervention Plans
          </h4>
          <div className="eval-monitor-toolbar">
            <div className="eval-monitor-toolbar-actions">
              <button
                type="button"
                className="eval-monitor-btn ghost"
                onClick={() => setExpandedPlans(!expandedPlans)}
                style={{ fontSize: '0.8rem' }}
              >
                {expandedPlans ? 'Collapse all' : 'Expand all'}
              </button>
            </div>
          </div>
          <div style={{ display: 'grid', gap: '0.75rem', marginTop: '0.5rem' }}>
            {intervention_plans.map((plan, i) => (
              <div key={i} style={{
                background: '#fff',
                border: '1px solid #e2e8f0',
                borderRadius: '10px',
                padding: '0.875rem 1rem',
                boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
              }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '0.5rem' }}>
                  <div>
                    <strong style={{ fontSize: '0.9rem', color: '#1e293b' }}>{plan.faculty_name}</strong>
                    <span style={{ fontSize: '0.75rem', color: '#64748b', marginLeft: '0.5rem' }}>{plan.program_code}</span>
                  </div>
                  <span style={{
                    fontSize: '0.7rem',
                    fontWeight: 700,
                    padding: '0.15rem 0.5rem',
                    borderRadius: '999px',
                    background: plan.status === 'Completed' ? '#dcfce7' : plan.status === 'Assigned' ? '#dbeafe' : '#fef9c3',
                    color: plan.status === 'Completed' ? '#15803d' : plan.status === 'Assigned' ? '#1d4ed8' : '#a16207',
                  }}>{plan.status}</span>
                </div>
                <div style={{ fontSize: '0.82rem', color: '#475569', marginBottom: '0.35rem' }}>
                  <span style={{ fontWeight: 500 }}>Weak area: </span>{plan.weak_area}
                </div>
                <div style={{ fontSize: '0.82rem', color: '#475569' }}>
                  <span style={{ fontWeight: 500 }}>Recommendation: </span>{plan.recommendation}
                </div>
                <div style={{ display: 'flex', gap: '0.75rem', marginTop: '0.5rem', fontSize: '0.75rem', color: '#94a3b8' }}>
                  {plan.action_type && <span>Type: {plan.action_type}</span>}
                  {plan.target_date && <span>Target: {plan.target_date}</span>}
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {/* ── Empty state if no data at all ── */}
      {weak_areas.length === 0 && intervention_plans.length === 0 && (
        <div className="eval-monitor-empty" style={{ marginTop: '1rem' }}>
          No recommendations or intervention plans available for this department.
        </div>
      )}
    </div>
  );
}

// ─── Main Report Component ───────────────────────────────────────────
export default function VpaaDepartmentReport({ departmentId, periodId = '', onBack }) {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expandedPrograms, setExpandedPrograms] = useState({});
  const [activeTab, setActiveTab] = useState('overview');

  useEffect(() => {
    let alive = true;
    async function loadReport() {
      setLoading(true);
      setError('');
      try {
        const params = new URLSearchParams({ scope: 'report', department_id: String(departmentId) });
        if (periodId) params.set('period_id', periodId);
        const payload = await apiFetch(`${API_BASE}?${params.toString()}`);
        if (alive) {
          if (payload.ok && payload.data) {
            setReport(payload.data);
          } else {
            setError(payload.message || 'Failed to load report.');
          }
        }
      } catch (err) {
        if (alive) setError(err.message);
      } finally {
        if (alive) setLoading(false);
      }
    }
    loadReport();
    return () => { alive = false; };
  }, [departmentId, periodId]);

  if (loading) {
    return (
      <div className="eval-monitor-container">
        <div className="eval-monitor-main">
          <LoadingSkeleton />
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="eval-monitor-container">
        <div className="eval-monitor-main">
          <div className="eval-monitor-empty error">{error}</div>
        </div>
      </div>
    );
  }

  if (!report) return null;

  const { department, programs, faculty_count, eval_summary, category_scores } = report;
  const period = report.period || null;
  const { total, completed, pending, overdue, completion_pct, average_score } = eval_summary;

  function downloadCSV() {
    let csv = 'Program,Code,Head,Faculty,Assignments,Completed,Pending,Overdue,Completion%,AvgScore\n';
    programs.forEach((p) => {
      csv += `${p.program_name},${p.program_code},${p.program_head_name},${p.total_faculty},${p.total_assignments},${p.completed},${p.pending},${p.overdue},${p.completion_pct},${p.average_score}\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `vpaa-report-${department.department_code}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  function toggleProgram(programId) {
    setExpandedPrograms((current) => ({ ...current, [programId]: !current[programId] }));
  }

  return (
    <div className="eval-monitor-container vpaa-analytics-report">
      <div className="eval-monitor-main">
        {/* Breadcrumb */}
        <div className="eval-monitor-breadcrumb">
          <button type="button" onClick={onBack}>
            <ChevronLeft size={16} /> All Departments
          </button>
          <span>/</span>
          <span>{department.department_name}</span>
        </div>

        {/* Hero */}
        <div className="eval-monitor-hero compact vpaa-analytics-report-hero">
          <div>
            <p className="eyebrow">Evaluation Report</p>
            <h2>{department.department_name}</h2>
            <p>
              Dean: {department.dean_name} • {faculty_count} faculty • {programs.length} program{programs.length !== 1 ? 's' : ''}
            </p>
            {period?.period_name && (
              <p className="vpaa-analytics-period-line">
                Showing {period.period_name}{period.year ? ` • Year ${period.year}` : ''}
              </p>
            )}
          </div>
        </div>

        {/* ── Tab Navigation ── */}
        <div style={{
          display: 'flex',
          gap: '0.25rem',
          marginBottom: '1.5rem',
          background: '#f1f5f9',
          borderRadius: '10px',
          padding: '0.25rem',
        }}>
          <button
            type="button"
            onClick={() => setActiveTab('overview')}
            style={{
              flex: 1,
              padding: '0.625rem 1rem',
              borderRadius: '8px',
              border: 'none',
              background: activeTab === 'overview' ? '#fff' : 'transparent',
              cursor: 'pointer',
              fontWeight: activeTab === 'overview' ? 700 : 500,
              fontSize: '0.875rem',
              color: activeTab === 'overview' ? '#1e293b' : '#64748b',
              boxShadow: activeTab === 'overview' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none',
              transition: 'all 0.15s ease',
              minHeight: 'auto',
            }}
          >
            <BarChart3 size={16} style={{ display: 'inline', marginRight: '0.4rem', verticalAlign: 'text-bottom' }} />
            Overview
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('recommendations')}
            style={{
              flex: 1,
              padding: '0.625rem 1rem',
              borderRadius: '8px',
              border: 'none',
              background: activeTab === 'recommendations' ? '#fff' : 'transparent',
              cursor: 'pointer',
              fontWeight: activeTab === 'recommendations' ? 700 : 500,
              fontSize: '0.875rem',
              color: activeTab === 'recommendations' ? '#1e293b' : '#64748b',
              boxShadow: activeTab === 'recommendations' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none',
              transition: 'all 0.15s ease',
              minHeight: 'auto',
            }}
          >
            <Lightbulb size={16} style={{ display: 'inline', marginRight: '0.4rem', verticalAlign: 'text-bottom' }} />
            Recommendations
          </button>
        </div>

        {activeTab === 'overview' && (
          <>
        {/* Summary Metrics */}
        <div className="eval-monitor-metrics">
          <article className="metric-primary">
            <span>Total Evaluations</span>
            <strong>{total}</strong>
            <small>All assignments</small>
          </article>
          <article className="metric-success">
            <span>Completed</span>
            <strong>{completed}</strong>
            <small>{completion_pct}% completion rate</small>
          </article>
          <article className="metric-warning">
            <span>Pending</span>
            <strong>{pending}</strong>
            <small>Awaiting submission</small>
          </article>
          <article className="metric-danger">
            <span>Overdue</span>
            <strong>{overdue}</strong>
            <small>Past deadline</small>
          </article>
          {average_score > 0 && (
            <article className="metric-accent">
              <span>Avg Score</span>
              <strong>{average_score.toFixed(2)}</strong>
              <small>/5.0 across all programs</small>
            </article>
          )}
        </div>

        {/* Programs Section */}
        <div className="eval-monitor-table-container">
          <div className="eval-monitor-toolbar">
            <h3 style={{ fontSize: '1rem', fontWeight: 600, display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <Award size={18} /> Programs ({programs.length})
            </h3>
            <div className="eval-monitor-toolbar-actions">
              <button type="button" className="eval-monitor-btn ghost" onClick={downloadCSV}>
                <Download size={16} /> CSV
              </button>
            </div>
          </div>

          {programs.length === 0 ? (
            <div className="eval-monitor-empty">No programs found under this department.</div>
          ) : (
            <div className="eval-monitor-program-grid">
              {programs.map((prog) => {
                const weakest = prog.category_scores?.length > 0 ? prog.category_scores[0] : null;
                const strongest = prog.category_scores?.length > 1 ? prog.category_scores[prog.category_scores.length - 1] : null;

                return (
                  <div key={prog.id} className="eval-monitor-program-card vpaa-analytics-program-card" style={{ cursor: 'default' }}>
                    <div className="eval-monitor-program-card-header">
                      <div className="eval-monitor-program-card-icon">
                        <Award size={20} />
                      </div>
                      <div>
                        <h3>{prog.program_name}</h3>
                        <span className="eval-monitor-dept-code">{prog.program_code}</span>
                      </div>
                      {scorePill(prog.average_score)}
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
                          <strong>{prog.average_score > 0 ? prog.average_score.toFixed(2) : '—'}</strong>
                        </div>
                      </div>

                      {completionBar(prog.completion_pct)}

                      <div className="eval-monitor-dept-card-badges">
                        {statusBadge('completed', prog.completed)}
                        {prog.pending > 0 && statusBadge('pending', prog.pending)}
                        {prog.overdue > 0 && statusBadge('overdue', prog.overdue)}
                      </div>

                      {/* Category Scores within program */}
                      {prog.category_scores?.length > 0 && (
                        <div className="eval-monitor-program-expanded vpaa-analytics-program-details">
                          <button
                            type="button"
                            className="vpaa-analytics-detail-toggle"
                            onClick={() => toggleProgram(prog.id)}
                            aria-expanded={!!expandedPrograms[prog.id]}
                          >
                            <span><BarChart3 size={14} /> Category score details</span>
                            {expandedPrograms[prog.id] ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                          </button>

                          {expandedPrograms[prog.id] && (
                            <div className="vpaa-analytics-detail-body">
                              <div className="eval-monitor-ai-field-rankings">
                                {prog.category_scores.map((cs, ci) => (
                                  <div key={ci} className="eval-monitor-ai-field-row">
                                    <span className="eval-monitor-ai-field-name">{cs.category}</span>
                                    <div className="eval-monitor-ai-field-bar">
                                      <span style={{
                                        width: `${(cs.score / 5) * 100}%`,
                                        background: cs.score >= 4 ? '#22c55e' : cs.score >= 3 ? '#eab308' : '#ef4444',
                                      }} />
                                    </div>
                                    <strong className="eval-monitor-ai-field-score">{cs.score.toFixed(2)}</strong>
                                  </div>
                                ))}
                              </div>

                              {weakest && strongest && (
                                <div className="eval-monitor-ai-insights">
                                  <div className="eval-monitor-ai-insight-weak">
                                    <TrendingDown size={14} />
                                    <div>
                                      <small>Weakest</small>
                                      <strong>{weakest.category}</strong>
                                      <span className="score">{weakest.score.toFixed(2)}</span>
                                    </div>
                                  </div>
                                  <div className="eval-monitor-ai-insight-strong">
                                    <TrendingUp size={14} />
                                    <div>
                                      <small>Strongest</small>
                                      <strong>{strongest.category}</strong>
                                      <span className="score">{strongest.score.toFixed(2)}</span>
                                    </div>
                                  </div>
                                </div>
                              )}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Department-wide Category Scores */}
        {category_scores?.length > 0 && (
          <ScoreAnalysis categoryScores={category_scores} averageScore={average_score} />
        )}

        {/* All evaluations complete banner */}
        {total > 0 && pending === 0 && overdue === 0 && (
          <div className="eval-monitor-ai-card praise" style={{ margin: '1rem 0' }}>
            <Sparkles size={18} />
            <span>All evaluations in this department are complete. Final scores shown above.</span>
          </div>
        )}
          </>
        )}

        {activeTab === 'recommendations' && (
          <RecommendationsSection
            departmentId={departmentId}
            periodId={periodId}
            departmentName={department.department_name}
          />
        )}
      </div>
    </div>
  );
}
