import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { AlertTriangle, BookOpenCheck, ClipboardList, GraduationCap, Target, TrendingUp, Users, Building2, Search, Clock, Filter, CheckCircle2, Calendar, ChevronDown } from 'lucide-react';
import { Pie } from 'react-chartjs-2';
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js';
import Hero from '../components/common/Hero.jsx';
import MetricGrid from '../components/common/MetricGrid.jsx';
import ReportGrid from '../components/common/ReportGrid.jsx';
import EvaluationDashboard from '../components/evaluations/EvaluationDashboard.jsx';
import DeanSelfEvaluationReview from '../components/evaluations/DeanSelfEvaluationReview.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import AdminEvaluationMonitor from '../components/evaluations/AdminEvaluationMonitor.jsx';
import PersonalPerformanceSummary from '../components/evaluations/PersonalPerformanceSummary.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';
import useLiveRefresh from '../hooks/useLiveRefresh.js';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';
import apiFetch from '../data/api.js';

ChartJS.register(ArcElement, Tooltip, Legend);

export default function DeanDashboard({ role }) {
  const { section = 'overview' } = useParams();
  const activeSection = section === 'training' ? 'summary' : section;
  const { selectedPeriodId } = useEvaluationPeriod();
  // Real-time metrics from backend API - auto-refreshes every 5 seconds
  const { metrics: liveMetrics, actionCenter: apiActionCenter, loading: dashboardLoading, error: dashboardError, timestamp } = useRealtimeMetrics('dean', {
    department: role?.user?.department || '',
    periodId: selectedPeriodId,
  });

  const actionCenter = useMemo(() => {
    if (!apiActionCenter) return { items: [], total: 0, ready: 0 };
    const items = apiActionCenter.map((item) => ({ ...item, count: Number(item.count) || 0 }));
    return {
      items,
      total: items.reduce((total, item) => total + item.count, 0),
      ready: items.filter((item) => item.count === 0).length,
    };
  }, [apiActionCenter]);

  const dashboardCards = useMemo(() => {
    const metricByLabel = new Map(liveMetrics.map((item) => [String(item.label || '').toLowerCase(), item]));
    const actionByLabel = new Map(actionCenter.items.map((item) => [String(item.label || '').toLowerCase(), item]));
    const metric = (label, fallback = 0) => metricByLabel.get(label.toLowerCase())?.value ?? fallback;
    const action = (label, fallback = 0) => actionByLabel.get(label.toLowerCase())?.count ?? fallback;

    return [
      { label: 'Faculty Under Review', value: metric('Faculty Under Review'), help: 'Faculty and program heads under this department', href: '/dean/summary', cta: 'View scope', tone: 'primary' },
      { label: 'Pending Reviews', value: metric('Pending Reviews'), help: 'Evaluations awaiting submission', href: '/dean/evaluate', cta: 'Open pending', tone: 'warning' },
      { label: 'Submitted Reviews', value: metric('Submitted Reviews'), help: 'Completed department evaluation records', href: '/dean/summary', cta: 'Review results', tone: 'success' },
      { label: 'Completion Rate', value: metric('Completion Rate'), help: 'Current department appraisal progress', href: '/dean/summary', cta: 'View progress', tone: 'accent' },
      { label: 'AI Insights', value: metric('AI Insights'), help: 'Department weak-area analysis', href: '/dean/summary', cta: 'Analyze', tone: 'info' },
      { label: 'Training Plans', value: metric('Training Plans'), help: 'Recommended development actions', href: '/dean/summary', cta: 'View plans', tone: 'success' },
      { label: 'Overdue Reviews', value: action('Overdue reviews'), help: 'Past deadline and still pending', href: '/dean/evaluate', cta: 'Review overdue', tone: 'warning' },
      { label: 'Priority Items', value: actionCenter.total, help: 'Department actions currently requiring attention', href: '/dean/summary', cta: 'Open priorities', tone: 'danger' },
    ];
  }, [actionCenter.items, actionCenter.total, liveMetrics]);

  const evaluationChart = useMemo(() => {
    const metrics = new Map(liveMetrics.map((item) => [String(item.label || '').toLowerCase(), Number.parseFloat(item.value) || 0]));
    const completed = metrics.get('submitted reviews') || 0;
    const pendingTotal = metrics.get('pending reviews') || 0;
    const overdue = Number(actionCenter.items.find((item) => String(item.label).toLowerCase() === 'overdue reviews')?.count || 0);
    return { completed, pending: Math.max(0, pendingTotal - overdue), overdue, total: completed + pendingTotal };
  }, [actionCenter.items, liveMetrics]);

  const evaluationChartData = useMemo(() => ({
    labels: ['Completed', 'Pending', 'Overdue'],
    datasets: [{
      data: [evaluationChart.completed, evaluationChart.pending, evaluationChart.overdue],
      backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
      borderColor: ['#fff', '#fff', '#fff'],
      borderWidth: 3,
      hoverOffset: 7,
    }],
  }), [evaluationChart.completed, evaluationChart.pending, evaluationChart.overdue]);

  const evaluationChartOptions = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: {
      duration: 1400,
      easing: 'easeOutQuart',
      animateRotate: true,
      animateScale: true,
    },
    transitions: {
      active: { animation: { duration: 300 } },
      resize: { animation: { duration: 450, easing: 'easeOutCubic' } },
    },
    plugins: {
      legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 10, padding: 18 } },
      tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw}` } },
    },
  }), []);

  return (
    <section className={`admin-content admin-module dean-content ${activeSection === 'overview' ? 'dean-overview-content' : ''} ${activeSection === 'report' ? 'reports-analytics-content' : ''}`}>
      {activeSection === 'overview' && (
        <>
          <Hero
            className="welcome-dashboard-hero"
            eyebrow="Dean Dashboard"
            title={`Welcome back, ${role.user.name}`}
            actions={<PeriodSelector compact />}
          >
            Track appraisal progress, faculty performance, and department-level insights automatically for {role.user.department}.
          </Hero>
          <section className="admin-dashboard-unified dean-role-dashboard module-wide page-enter" aria-label="Department appraisal dashboard">
            <div className="action-center-head dean-role-dashboard-head">
              <div><p className="eyebrow">Department Leadership Dashboard</p><h2>Dean Dashboard</h2><p>{dashboardError ? `Live refresh paused: ${dashboardError}` : `Live appraisal results limited to ${role.user.department || 'your assigned department'}${timestamp ? `, updated ${new Date(timestamp * 1000).toLocaleTimeString()}` : ''}.`}</p></div>
              <div className={`dean-live-status ${dashboardError ? 'is-error' : ''}`}><span />{dashboardLoading ? 'Loading live data' : dashboardError ? 'Reconnecting' : 'Live department data'}</div>
            </div>
            <MetricGrid items={dashboardCards} compact className="dean-role-metrics" />
            <section className="dean-evaluation-chart-panel" aria-labelledby="dean-evaluation-chart-title">
              <div className="dean-chart-copy"><p className="eyebrow">Evaluation Progress</p><h3 id="dean-evaluation-chart-title">Department appraisal status</h3><p>A live distribution of faculty and program-head evaluations within the Dean’s assigned department.</p><div className="dean-chart-total"><strong>{evaluationChart.total}</strong><span>Total evaluation assignments</span></div></div>
              <div className="dean-pie-wrap">
                {evaluationChart.total > 0 ? <Pie aria-label={`Department evaluations: ${evaluationChart.completed} completed, ${evaluationChart.pending} pending, ${evaluationChart.overdue} overdue`} data={evaluationChartData} options={evaluationChartOptions}/>:<div className="dean-chart-empty">No department assignments for this period.</div>}
              </div>
              <div className="dean-chart-breakdown"><span className="completed"><i/>Completed<strong>{evaluationChart.completed}</strong></span><span className="pending"><i/>Pending<strong>{evaluationChart.pending}</strong></span><span className="overdue"><i/>Overdue<strong>{evaluationChart.overdue}</strong></span></div>
            </section>
            <div className="dean-role-dashboard-lower">
              <section className="dean-priority-panel" aria-labelledby="dean-priority-title"><div className="dean-panel-heading"><div><p className="eyebrow">Priority Queue</p><h3 id="dean-priority-title">Items requiring Dean review</h3></div><strong>{actionCenter.total}</strong></div><div className="dean-priority-list">{actionCenter.items.length ? actionCenter.items.map((item)=><Link key={item.label} to={item.href} className={`dean-priority-item tone-${item.tone || 'info'}`}><span className="dean-priority-count">{item.count}</span><span><strong>{item.label}</strong><small>{item.detail}</small></span><b>{item.cta}</b></Link>):<p className="dipascaf-empty">No department action items for this period.</p>}</div></section>
              <section className="dean-workspace-panel" aria-labelledby="dean-workspace-title"><div className="dean-panel-heading"><div><p className="eyebrow">Dean Modules</p><h3 id="dean-workspace-title">Continue your work</h3></div></div><div className="dean-workspace-links"><Link to="/dean/evaluate"><ClipboardList/><span><strong>Faculty Evaluations</strong><small>Evaluate faculty and program heads in your department</small></span></Link><Link to="/dean/self-evaluation-review"><CheckCircle2/><span><strong>Self-Evaluation Reviews</strong><small>Review department leadership and faculty submissions</small></span></Link><Link to="/dean/results"><TrendingUp/><span><strong>My Evaluation Results</strong><small>View your personal overall score and category results</small></span></Link><Link to="/dean/summary"><TrendingUp/><span><strong>Department Analytics</strong><small>Review performance, weak areas, and development plans</small></span></Link><Link to="/dean/report"><BookOpenCheck/><span><strong>Department Reports</strong><small>Generate reports within your authorized scope</small></span></Link></div></section>
            </div>
          </section>
        </>
      )}
      {activeSection === 'evaluate' && (
        <EvaluationDashboard eyebrow="Dean Evaluation" title="Evaluate Program Heads and Faculty" subtitle="Review every assigned Program Head, Faculty, and Peer appraisal card under your department." evaluatorRole={role.key} role={role} />
      )}
      {activeSection === 'self-evaluation-review' && <DeanSelfEvaluationReview role={role} />}
      {activeSection === 'self-evaluation' && <Navigate to="/dean/evaluate" replace />}
      {activeSection === 'summary' && <AdminEvaluationMonitor initialView="groups" />}
      {activeSection === 'results' && <PersonalPerformanceSummary />}
      {activeSection === 'insights' && <Navigate to="/dean/summary" replace />}
      {activeSection === 'report' && <ReportGrid role={role} />}
    </section>
  );
}

function abbreviateFactor(value) {
  const text = String(value || '').trim();
  const replacements = [
    [/Job Knowledge,\s*Quality of Work,\s*and Excellence/i, 'JK & Excellence'],
    [/Interpersonal Sensitivity,\s*Teamwork,\s*and Collaboration/i, 'Teamwork & Collaboration'],
    [/Flexibility and Adaptability,\s*Resourcefulness,\s*Creativity,\s*and Innovativeness/i, 'Adaptability & Innovation'],
    [/Job Commitment and Responsibility/i, 'Commitment & Responsibility'],
    [/Institutional Sensitivity/i, 'Institutional Values'],
    [/Attendance and Punctuality/i, 'Attendance'],
    [/Professional Decorum/i, 'Decorum'],
    [/Classroom Management/i, 'Classroom Mgmt.'],
    [/Communication Skills/i, 'Communication'],
  ];
  for (const [pattern, replacement] of replacements) {
    if (pattern.test(text)) return replacement;
  }
  return text;
}

function scoreTone(score) {
  const value = Number(score || 0);
  if (value <= 3) return 'weak';
  if (value <= 3.5) return 'medium';
  return 'high';
}

function statusTone(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized.includes('complete')) return 'completed';
  if (normalized.includes('assign') || normalized.includes('plan')) return 'planned';
  if (normalized.includes('weak') || normalized.includes('identified')) return 'weak';
  return 'default';
}

function planPriority(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized.includes('weak') || normalized.includes('identified')) return 0;
  if (normalized.includes('assign')) return 1;
  if (normalized.includes('plan')) return 2;
  if (normalized.includes('complete')) return 3;
  return 4;
}

function ScoreGauge({ score }) {
  const numeric = Math.max(0, Math.min(5, Number(score || 0)));
  const percent = Math.round((numeric / 5) * 100);
  const tone = scoreTone(numeric);
  return (
    <div className={`dean-score-gauge ${tone}`}>
      <div className="dean-score-gauge-head">
        <strong>{numeric.toFixed(2)}</strong>
        <span>/5</span>
      </div>
      <div className="dean-score-track" aria-label={`Score ${numeric.toFixed(2)} of 5`}>
        <span style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}

function DeanStatusBadge({ status }) {
  return <span className={`dean-status-badge ${statusTone(status)}`}>{status || 'Planned'}</span>;
}

function SeminarPill({ children }) {
  if (!children) return null;
  return <span className="dean-seminar-pill">{children}</span>;
}

function handleDetailsToggle(event) {
  if (!event.currentTarget.open) return;
  window.requestAnimationFrame(() => {
    event.currentTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
}

function WeakAreaCard({ item }) {
  const category = item.weakCategory || item.factor || 'Weak Area';
  return (
    <article className="dean-record-card priority">
      <div className="dean-card-topline">
        <div>
          <span className="dean-card-kicker">{item.program || 'Unassigned Program'}</span>
          <h3 title={category}>{abbreviateFactor(category)}</h3>
        </div>
        <DeanStatusBadge status={item.status || 'Weak'} />
      </div>
      <div className="dean-card-main">
        <div>
          <strong>{item.facultyName || 'Faculty'}</strong>
          <small>{item.formTitle || item.department || 'Completed evaluation'}</small>
        </div>
        <ScoreGauge score={item.averageScore} />
      </div>
      <div className="dean-card-warning">
        <AlertTriangle size={15} />
        <span>{abbreviateFactor(category)} needs attention</span>
      </div>
      <details className="dean-card-details" onToggle={handleDetailsToggle}>
        <summary>View Details <ChevronDown size={14} /></summary>
        <div>
          <p><strong>Department:</strong> {item.department || 'N/A'}</p>
          <p><strong>Date:</strong> {item.dateSubmitted || 'N/A'}</p>
          <div className="dean-card-actions">
            <button type="button">View Details</button>
            <button type="button">Assign Training</button>
          </div>
          <SeminarPill>{item.seminar}</SeminarPill>
        </div>
      </details>
    </article>
  );
}

function FactorCard({ item }) {
  const title = item.factor || item.weakArea || 'Performance Factor';
  return (
    <article className="dean-record-card">
      <div className="dean-card-topline">
        <div>
          <span className="dean-card-kicker">{item.weight || 'N/A'} weight</span>
          <h3 title={title}>{abbreviateFactor(title)}</h3>
        </div>
        <DeanStatusBadge status={Number(item.averageScore || 0) <= 3.5 ? 'Weak' : 'Completed'} />
      </div>
      <ScoreGauge score={item.averageScore} />
      <details className="dean-card-details" onToggle={handleDetailsToggle}>
        <summary>Recommended Seminar <ChevronDown size={14} /></summary>
        <div><SeminarPill>{item.seminar}</SeminarPill></div>
      </details>
    </article>
  );
}

function PlanCard({ plan }) {
  const scope = plan.scope || 'Plan';
  const subject = plan.facultyName || plan.program || 'Department';
  const location = [plan.department, plan.program].filter(Boolean).join(' · ') || 'Unassigned Program';
  return (
    <article className="dean-record-card plan">
      <div className="dean-card-topline">
        <div>
          <span className="dean-card-kicker">{scope} · {location}</span>
          <h3 title={plan.weakArea}>{abbreviateFactor(plan.weakArea || 'Development Plan')}</h3>
        </div>
        <DeanStatusBadge status={plan.status} />
      </div>
      <div className="dean-plan-meta">
        <span><Users size={14} /> {scope === 'Faculty' ? subject : `${plan.facultyCount || 1} faculty`}</span>
        <span><Calendar size={14} /> Deadline: TBD</span>
      </div>
      <SeminarPill>{plan.seminar}</SeminarPill>
      <details className="dean-card-details" onToggle={handleDetailsToggle}>
        <summary>Recommendation <ChevronDown size={14} /></summary>
        <p>{plan.recommendation || 'Assign targeted training for this weak area.'}</p>
      </details>
    </article>
  );
}

export function SummaryAndPlans({
  endpoint = '/api/dean-summary.php',
  statsRole = 'dean',
  title = 'Department Development Summary',
  description = 'Clean view of evaluation trends, common weak areas, and faculty development actions for the selected period.',
  showDepartmentFilter = false,
}) {
  const { selectedPeriodId } = useEvaluationPeriod();
  const [summaryData, setSummaryData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filters, setFilters] = useState({ search: '', department: '', program: '', status: '', performance: '', category: '' });

  const loadSummary = useCallback(async (background = false) => {
    try {
      if (!background) setLoading(true);
      const params = new URLSearchParams();
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      params.set('_', String(Date.now()));
      const queryString = params.toString();
      const payload = await apiFetch(`${endpoint}${queryString ? `?${queryString}` : ''}`);
      let nextData = payload.data || null;

      try {
        const statsParams = new URLSearchParams();
        if (selectedPeriodId) statsParams.set('period_id', selectedPeriodId);
        statsParams.set('role', statsRole);
        statsParams.set('_', String(Date.now()));
        const statsPayload = await apiFetch(`/api/evaluation-stats.php?${statsParams.toString()}`);
        if (statsPayload.data && nextData) {
          nextData = { ...nextData, _stats: statsPayload.data };
        }
      } catch (_) {
        // Stats endpoint is supplementary; fail silently
      }

      setSummaryData(nextData);
      setError('');
    } catch (err) {
      setError(err.message || 'Unable to load dean summary.');
    } finally {
      if (!background) setLoading(false);
    }
  }, [endpoint, selectedPeriodId, statsRole]);

  const { refreshing: liveRefreshing } = useLiveRefresh(loadSummary, [endpoint, selectedPeriodId, statsRole], {
    intervalMs: 6000,
  });

  const factorSummary = summaryData?.factorSummary || [];
  const trainingPlans = summaryData?.trainingPlans || [];
  const weakAreas = summaryData?.weakAreas || [];
  const stats = summaryData?._stats;
  const summary = summaryData?.summary || {};
  const canRevealWeakAreas = Number(stats?.totalAssignments || 0) > 0
    && Number(stats?.pendingAssignments || 0) === 0
    && Number(stats?.completionRate || 0) >= 100;
  const visibleWeakAreas = canRevealWeakAreas ? weakAreas : [];
  const visibleFactorSummary = canRevealWeakAreas ? factorSummary : [];
  const visibleTrainingPlans = canRevealWeakAreas ? trainingPlans : [];
  const averageScore = factorSummary.length > 0
    ? (factorSummary.reduce((sum, item) => sum + Number(item.averageScore || 0), 0) / factorSummary.length).toFixed(2)
    : '0.00';
  const priorityFactor = canRevealWeakAreas
    ? (factorSummary[0]?.weakArea || visibleWeakAreas[0]?.weakArea || 'No priority yet')
    : 'Pending completion';
  const plannedCount = visibleTrainingPlans.filter((plan) => String(plan.status || '').toLowerCase().includes('planned')).length;
  const completedPlans = visibleTrainingPlans.filter((plan) => String(plan.status || '').toLowerCase().includes('completed')).length;
  const reviewedCount = canRevealWeakAreas ? (summary.reviewed ?? visibleFactorSummary.length) : 0;
  const facultyWithWeakAreas = new Set(visibleWeakAreas.map((item) => item.facultyName).filter((name) => name && name !== '—')).size || visibleWeakAreas.length;

  const programOptions = useMemo(() => [...new Set([
    ...visibleWeakAreas.map((item) => item.program),
    ...visibleTrainingPlans.map((item) => item.program),
  ].filter(Boolean))].sort(), [visibleTrainingPlans, visibleWeakAreas]);

  const departmentOptions = useMemo(() => [...new Set([
    ...visibleWeakAreas.map((item) => item.department),
    ...visibleTrainingPlans.map((item) => item.department),
  ].filter(Boolean))].sort(), [visibleTrainingPlans, visibleWeakAreas]);

  const categoryOptions = useMemo(() => [...new Set([
    ...visibleWeakAreas.map((item) => item.weakCategory),
    ...visibleFactorSummary.map((item) => item.factor || item.weakArea),
    ...visibleTrainingPlans.map((item) => item.weakArea),
  ].filter(Boolean))].sort(), [visibleFactorSummary, visibleTrainingPlans, visibleWeakAreas]);

  const statusOptions = useMemo(() => [...new Set([
    ...visibleWeakAreas.map((item) => item.status),
    ...visibleTrainingPlans.map((item) => item.status),
  ].filter(Boolean))].sort(), [visibleTrainingPlans, visibleWeakAreas]);

  const query = filters.search.toLowerCase().trim();
  const matchesCommonFilters = (row) => {
    const score = Number(row.averageScore || 0);
    const performance = score <= 3 ? 'weak' : score <= 3.5 ? 'medium' : 'high';
    return (!query || [row.facultyName, row.department, row.program, row.formTitle, row.weakCategory, row.factor, row.status, row.seminar]
      .some((val) => String(val || '').toLowerCase().includes(query)))
      && (!filters.department || row.department === filters.department)
      && (!filters.program || row.program === filters.program)
      && (!filters.status || row.status === filters.status)
      && (!filters.category || row.weakCategory === filters.category || row.factor === filters.category || row.weakArea === filters.category)
      && (!filters.performance || performance === filters.performance);
  };

  const filteredWeak = useMemo(() => visibleWeakAreas
    .filter(matchesCommonFilters)
    .sort((a, b) => Number(a.averageScore || 0) - Number(b.averageScore || 0)), [visibleWeakAreas, filters]);

  const filteredFactors = useMemo(() => visibleFactorSummary
    .map((item) => ({ ...item, weakCategory: item.factor || item.weakArea, status: Number(item.averageScore || 0) <= 3.5 ? 'Weak' : 'Stable' }))
    .filter(matchesCommonFilters)
    .sort((a, b) => Number(a.averageScore || 0) - Number(b.averageScore || 0)), [visibleFactorSummary, filters]);

  const filteredPlans = useMemo(() => visibleTrainingPlans
    .map((item) => ({ ...item, weakCategory: item.weakArea, averageScore: 0 }))
    .filter((row) => (!query || [row.scope, row.facultyName, row.department, row.program, row.weakArea, row.seminar, row.status, row.recommendation]
      .some((val) => String(val || '').toLowerCase().includes(query)))
      && (!filters.department || row.department === filters.department)
      && (!filters.program || row.program === filters.program)
      && (!filters.status || row.status === filters.status)
      && (!filters.category || row.weakArea === filters.category))
    .sort((a, b) => planPriority(a.status) - planPriority(b.status)), [visibleTrainingPlans, filters]);

  function updateFilter(name, value) {
    setFilters((current) => ({ ...current, [name]: value }));
  }

  function resetFilters() {
    setFilters({ search: '', department: '', program: '', status: '', performance: '', category: '' });
  }

  return (
    <div className="eval-monitor-container module-wide page-enter dean-development-summary">
      {/* Loading Skeleton */}
      {loading && (
        <div className="eval-monitor-skeleton">
          {[1, 2, 3].map((i) => (
            <div key={i} className="eval-monitor-skeleton-card">
              <div className="skeleton-line w-24" />
              <div className="skeleton-line w-32" />
              <div className="skeleton-line w-full" />
            </div>
          ))}
        </div>
      )}

      {/* Error */}
      {error && <div className="eval-monitor-empty error">{error}</div>}

      {!loading && !error && (
        <>
          <div className="dean-development-overview">
            <div className="role-summary-header">
              <div>
                <p className="eyebrow">Summary and Plans</p>
                <h2>{title}</h2>
                <p>{description}</p>
                {liveRefreshing && <span className="live-refresh-indicator">Updating summary...</span>}
              </div>
              <span className="role-summary-chip"><Clock size={14} /> Period filtered</span>
            </div>

            <div className="role-summary-band">
              <div className="eval-monitor-hero-chart">
                <div className="eval-monitor-donut" style={{ '--pct': `${averageScore ? Math.round((parseFloat(averageScore) / 5) * 100) : 0}%` }}>
                  <strong>{averageScore}</strong>
                  <span>Avg Score</span>
                </div>
                <div className="eval-monitor-hero-stats">
                  <span><Building2 size={14} /> Faculty: {summary.faculty ?? 0}</span>
                  <span><AlertTriangle size={14} /> Weak Areas: {canRevealWeakAreas ? visibleWeakAreas.length : 'Hidden'}</span>
                  <span><GraduationCap size={14} /> Plans: {canRevealWeakAreas ? (plannedCount || visibleTrainingPlans.length) : 'Hidden'}</span>
                  <span><Target size={14} /> Focus: {priorityFactor}</span>
                </div>
              </div>
            </div>
          </div>

          <div className="eval-monitor-metrics">
            <article className="metric-primary">
              <span>Faculty with Weak Areas</span>
              <strong>{facultyWithWeakAreas}</strong>
              <small>Highest priority first</small>
            </article>
            <article className="metric-info">
              <span>Performance Factors</span>
              <strong>{canRevealWeakAreas ? reviewedCount : 'Hidden'}</strong>
              <small>{canRevealWeakAreas ? 'Factors with submitted ratings' : 'Pending completion'}</small>
            </article>
            <article className="metric-warning">
              <span>Training Plans Scheduled</span>
              <strong>{canRevealWeakAreas ? (plannedCount || visibleTrainingPlans.length) : 'Hidden'}</strong>
              <small>{canRevealWeakAreas ? 'Planned development actions' : 'Pending completion'}</small>
            </article>
            <article className="metric-success">
              <span>Completed</span>
              <strong>{canRevealWeakAreas ? completedPlans : 'Hidden'}</strong>
              <small>{canRevealWeakAreas ? 'Closed development plans' : 'Pending completion'}</small>
            </article>
          </div>

          <div className="dean-summary-filters">
            <label><Search size={15} /> <input value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder="Search faculty, factor, seminar..." /></label>
            {showDepartmentFilter && <label><Building2 size={15} /> <select value={filters.department} onChange={(event) => updateFilter('department', event.target.value)}><option value="">All departments</option>{departmentOptions.map((item) => <option key={item}>{item}</option>)}</select></label>}
            <label><Filter size={15} /> <select value={filters.program} onChange={(event) => updateFilter('program', event.target.value)}><option value="">All programs</option>{programOptions.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label><Target size={15} /> <select value={filters.category} onChange={(event) => updateFilter('category', event.target.value)}><option value="">All weak categories</option>{categoryOptions.map((item) => <option key={item}>{abbreviateFactor(item)}</option>)}</select></label>
            <label><CheckCircle2 size={15} /> <select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}><option value="">All status</option>{statusOptions.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label><TrendingUp size={15} /> <select value={filters.performance} onChange={(event) => updateFilter('performance', event.target.value)}><option value="">All levels</option><option value="weak">Weak</option><option value="medium">Medium</option><option value="high">High</option></select></label>
            <button type="button" onClick={resetFilters}>Clear</button>
          </div>

          <section className="dean-card-section dean-weak-section">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <AlertTriangle size={16} />
                <span>Weak Areas</span>
              </div>
              <div className="eval-monitor-toolbar-actions">
                <small>{canRevealWeakAreas ? `${filteredWeak.length} priority record${filteredWeak.length !== 1 ? 's' : ''}` : 'Pending completion'}</small>
              </div>
            </div>
            {filteredWeak.length === 0 ? (
              <div className="eval-monitor-empty">
                <Search size={28} />
                <strong>{!canRevealWeakAreas ? 'Weak areas are hidden until all evaluations are complete.' : (weakAreas.length === 0 ? 'No weak areas detected yet.' : 'No records match your filters')}</strong>
                <p>{!canRevealWeakAreas ? 'Complete all evaluations in this scope before the system reveals weak areas and recommendations.' : (weakAreas.length === 0 ? 'Weak areas will appear once completed evaluations with scores below 3.5 are submitted.' : 'Try adjusting search, program, category, status, or performance level.')}</p>
              </div>
            ) : (
              <div className="dean-card-grid">
                {filteredWeak.map((item, index) => <WeakAreaCard key={`${item.facultyName}-${item.weakCategory}-${index}`} item={item} />)}
              </div>
            )}
          </section>

          <section className="dean-card-section">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <ClipboardList size={16} />
                <span>Performance Factors</span>
              </div>
              <div className="eval-monitor-toolbar-actions">
                <small>{canRevealWeakAreas ? `${filteredFactors.length} of ${visibleFactorSummary.length} factor${visibleFactorSummary.length !== 1 ? 's' : ''}` : 'Pending completion'}</small>
              </div>
            </div>
            {filteredFactors.length === 0 ? (
              <div className="eval-monitor-empty">
                <BookOpenCheck size={28} />
                <strong>{!canRevealWeakAreas ? 'Performance factors are hidden until all evaluations are complete.' : 'No evaluation results yet'}</strong>
                <p>{!canRevealWeakAreas ? 'Complete all evaluations in this scope before factor trends are shown.' : 'Submit completed evaluations first so the system can compute factor trends.'}</p>
              </div>
            ) : (
              <div className="dean-card-grid factor-grid">
                {filteredFactors.map((item) => <FactorCard key={item.factor} item={item} />)}
              </div>
            )}
          </section>

          <section className="dean-card-section">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <GraduationCap size={16} />
                <span>Development Plans</span>
              </div>
              <div className="eval-monitor-toolbar-actions">
                <small>{canRevealWeakAreas ? `${filteredPlans.length} action${filteredPlans.length !== 1 ? 's' : ''}` : 'Pending completion'}</small>
              </div>
            </div>
            {filteredPlans.length === 0 ? (
              <div className="eval-monitor-empty">
                <GraduationCap size={28} />
                <strong>{!canRevealWeakAreas ? 'Development plans are hidden until all evaluations are complete.' : (trainingPlans.length === 0 ? 'No training plans have been created yet' : 'No plans match your filters')}</strong>
                <p>{!canRevealWeakAreas ? 'Complete all evaluations in this scope before recommended development actions are shown.' : (trainingPlans.length === 0 ? 'Weak areas will auto-generate recommendations once evaluations are submitted.' : 'Try adjusting the active filters above.')}</p>
              </div>
            ) : (
              <div className="dean-card-grid plan-grid">
                {filteredPlans.map((plan, index) => <PlanCard key={`${plan.program}-${plan.weakArea}-${index}`} plan={plan} />)}
              </div>
            )}
          </section>
        </>
      )}
    </div>
  );
}
