import { Link, Navigate, useParams } from 'react-router-dom';
import { useCallback, useMemo, useState } from 'react';
import { AlertTriangle, BarChart3, CheckCircle2, ClipboardList, FileText, GraduationCap, Search, Target, Users } from 'lucide-react';
import { Pie } from 'react-chartjs-2';
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js';
import Hero from '../components/common/Hero.jsx';
import MetricGrid from '../components/common/MetricGrid.jsx';
import DataTable from '../components/common/DataTable.jsx';
import ReportGrid from '../components/common/ReportGrid.jsx';
import EvaluationDashboard from '../components/evaluations/EvaluationDashboard.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import PersonalPerformanceSummary from '../components/evaluations/PersonalPerformanceSummary.jsx';
import DepartmentAiInsights from '../components/ai/DepartmentAiInsights.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';
import useLiveRefresh from '../hooks/useLiveRefresh.js';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';
import apiFetch from '../data/api.js';

ChartJS.register(ArcElement, Tooltip, Legend);

export default function ProgramHeadDashboard({ role }) {
  const { section = 'overview' } = useParams();
  const activeSection = section === 'training' ? 'summary' : section;
  const { selectedPeriodId } = useEvaluationPeriod();
  // Real-time metrics from backend API - auto-refreshes every 5 seconds
  const { metrics: liveMetrics, actionCenter: apiActionCenter, programs: assignedPrograms, loading: dashboardLoading, error: dashboardError, timestamp } = useRealtimeMetrics('program_head', {
    program: role?.user?.program || '',
    department: role?.user?.department || '',
    periodId: selectedPeriodId,
  });
  const assignedProgramLabel = assignedPrograms.length
    ? assignedPrograms.map((program) => program.code).join(', ')
    : (role.user.program || 'your assigned program');

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
      { label: 'Faculty', value: metric('Faculty'), help: 'Faculty members under this program', href: '/program-head/insights', cta: 'View faculty', tone: 'primary' },
      { label: 'Pending', value: metric('Pending'), help: 'Evaluations awaiting submission', href: '/program-head/evaluate', cta: 'Open pending', tone: 'warning' },
      { label: 'Submitted Reviews', value: metric('Submitted Reviews'), help: 'Completed program evaluation records', href: '/program-head/summary', cta: 'Review results', tone: 'success' },
      { label: 'Completion Rate', value: metric('Completion Rate'), help: 'Current program appraisal progress', href: '/program-head/insights', cta: 'View progress', tone: 'accent' },
      { label: 'Active Programs', value: metric('Active Programs'), help: 'Assigned program scope', href: '/program-head/insights', cta: 'Open program', tone: 'info' },
      { label: 'Overdue Evaluations', value: action('Overdue evaluations'), help: 'Past deadline and still pending', href: '/program-head/evaluate', cta: 'Review overdue', tone: 'warning' },
      { label: 'Priority Items', value: actionCenter.total, help: 'Program actions currently requiring attention', href: '/program-head/summary', cta: 'Open priorities', tone: 'danger' },
    ];
  }, [actionCenter.items, actionCenter.total, liveMetrics]);

  const evaluationChart = useMemo(() => {
    const metrics = new Map(liveMetrics.map((item) => [String(item.label || '').toLowerCase(), Number.parseFloat(item.value) || 0]));
    const completed = metrics.get('submitted reviews') || 0;
    const pendingTotal = metrics.get('pending') || 0;
    const overdue = Number(actionCenter.items.find((item) => String(item.label).toLowerCase() === 'overdue evaluations')?.count || 0);
    return { completed, pending: Math.max(0, pendingTotal - overdue), overdue, total: completed + pendingTotal };
  }, [actionCenter.items, liveMetrics]);

  return (
    <section className={`admin-content admin-module dean-content program-head-content ${activeSection === 'overview' ? 'program-head-overview-content' : ''}`}>
      {activeSection === 'overview' && (
        <>
          <Hero
            className="program-head-hero welcome-dashboard-hero"
            eyebrow="Program Head Dashboard"
            title={`Welcome back, ${role.user.name}`}
            actions={<PeriodSelector compact />}
          >
            Evaluate assigned faculty, monitor pending reviews, and prepare improvement plans for Dean review.
          </Hero>
          <section className="admin-dashboard-unified program-head-role-dashboard module-wide page-enter" aria-labelledby="program-head-dashboard-unified-title">
            <div className="action-center-head program-head-role-dashboard-head">
              <div><p className="eyebrow">Program Leadership Dashboard</p><h2 id="program-head-dashboard-unified-title">Program Head Dashboard</h2><p>{dashboardError ? `Live refresh paused: ${dashboardError}` : `Live appraisal results include ${assignedProgramLabel}${role.user.department ? ` in ${role.user.department}` : ''}${timestamp ? `, updated ${new Date(timestamp * 1000).toLocaleTimeString()}` : ''}.`}</p>{assignedPrograms.length>0&&<div className="program-head-assigned-programs" aria-label="Programs assigned for the selected period">{assignedPrograms.map((program)=><span key={program.id}>{program.code}{program.is_lead_evaluator?<b>Lead</b>:<b>Monitor</b>}{program.co_head_authorized?<em>Co-head</em>:null}</span>)}</div>}</div>
              <div className={`program-head-live-status ${dashboardError ? 'is-error' : ''}`}><span />{dashboardLoading ? 'Loading live data' : dashboardError ? 'Reconnecting' : 'Live program data'}</div>
            </div>
            <MetricGrid items={dashboardCards} compact className="program-head-role-metrics" />
            <section className="program-head-evaluation-chart-panel" aria-labelledby="program-head-evaluation-chart-title">
              <div className="program-head-chart-copy"><p className="eyebrow">Evaluation Progress</p><h3 id="program-head-evaluation-chart-title">Program appraisal status</h3><p>A live distribution of faculty evaluations within the Program Head’s assigned academic program.</p><div className="program-head-chart-total"><strong>{evaluationChart.total}</strong><span>Total evaluation assignments</span></div></div>
              <div className="program-head-pie-wrap">{evaluationChart.total > 0 ? <Pie aria-label={`Program evaluations: ${evaluationChart.completed} completed, ${evaluationChart.pending} pending, ${evaluationChart.overdue} overdue`} data={{labels:['Completed','Pending','Overdue'],datasets:[{data:[evaluationChart.completed,evaluationChart.pending,evaluationChart.overdue],backgroundColor:['#22c55e','#f59e0b','#ef4444'],borderColor:['#fff','#fff','#fff'],borderWidth:3,hoverOffset:7}]}} options={{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{usePointStyle:true,boxWidth:10,padding:18}},tooltip:{callbacks:{label:(context)=>`${context.label}: ${context.raw}`}}}}}/>:<div className="program-head-chart-empty">No program assignments for this period.</div>}</div>
              <div className="program-head-chart-breakdown"><span className="completed"><i/>Completed<strong>{evaluationChart.completed}</strong></span><span className="pending"><i/>Pending<strong>{evaluationChart.pending}</strong></span><span className="overdue"><i/>Overdue<strong>{evaluationChart.overdue}</strong></span></div>
            </section>
            <div className="program-head-role-dashboard-lower">
              <section className="program-head-priority-panel" aria-labelledby="program-head-priority-title"><div className="program-head-panel-heading"><div><p className="eyebrow">Priority Queue</p><h3 id="program-head-priority-title">Items requiring Program Head review</h3></div><strong>{actionCenter.total}</strong></div><div className="program-head-priority-list">{actionCenter.items.length ? actionCenter.items.map((item)=><Link key={item.label} to={item.href} className={`program-head-priority-item tone-${item.tone || 'info'}`}><span className="program-head-priority-count">{item.count}</span><span><strong>{item.label}</strong><small>{item.detail}</small></span><b>{item.cta}</b></Link>):<p className="dipascaf-empty">No program action items for this period.</p>}</div></section>
              <section className="program-head-workspace-panel" aria-labelledby="program-head-workspace-title"><div className="program-head-panel-heading"><div><p className="eyebrow">Program Head Modules</p><h3 id="program-head-workspace-title">Continue your work</h3></div></div><div className="program-head-workspace-links"><Link to="/program-head/evaluate"><ClipboardList/><span><strong>Faculty Evaluations</strong><small>Evaluate assigned faculty and authorized peers</small></span></Link><Link to="/program-head/summary"><BarChart3/><span><strong>Program Analytics</strong><small>Review performance, weak areas, and improvement plans</small></span></Link><Link to="/program-head/report"><FileText/><span><strong>Program Reports</strong><small>Generate reports within your authorized program scope</small></span></Link></div></section>
            </div>
          </section>
        </>
      )}
      {activeSection === 'evaluate' && (
        <>
          <EvaluationDashboard eyebrow="Program Head Evaluation" title="Evaluate Assigned Faculty and Peers" subtitle="Use focused menus to switch between Dean, Program Head, Faculty, and Peer appraisal cards." evaluatorRole={role.key} role={role} />
        </>
      )}
      {activeSection === 'peer-assignments' && <Navigate to="/program-head/evaluate" replace />}
      {activeSection === 'self-evaluation' && <Navigate to="/program-head/evaluate" replace />}
      {activeSection === 'self-evaluation-review' && <Navigate to="/program-head/evaluate" replace />}
      {activeSection === 'summary' && (
        <DepartmentAiInsights scope={role.user.program || 'assigned-program'} />
      )}
      {activeSection === 'results' && (
        <PersonalPerformanceSummary />
      )}
      {activeSection === 'insights' && <Navigate to="/program-head/summary" replace />}
      {activeSection === 'report' && <ReportGrid role={role} />}
    </section>
  );
}

function SummaryAndTraining() {
  const { selectedPeriodId } = useEvaluationPeriod();
  const [summaryData, setSummaryData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [weakSearch, setWeakSearch] = useState('');

  const loadSummary = useCallback(async (background = false) => {
    try {
      if (!background) setLoading(true);
      const params = new URLSearchParams();
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`/api/program-head-summary.php?${params.toString()}`, {
        cache: 'no-store',
      });
      setSummaryData(payload.data || null);
      setError('');
    } catch (err) {
      setError(err.message || 'Unable to load program summary.');
    } finally {
      if (!background) setLoading(false);
    }
  }, [selectedPeriodId]);

  const { refreshing: liveRefreshing } = useLiveRefresh(loadSummary, [selectedPeriodId], {
    intervalMs: 5000,
  });

  const facultyResults = summaryData?.facultyResults || [];
  const trainingPlans = summaryData?.trainingPlans || [];
  const weakAreas = summaryData?.weakAreas || [];
  const query = (weakSearch || '').toLowerCase().trim();
  const filteredWeak = query
    ? weakAreas.filter((w) =>
        [w.facultyName, w.department, w.program, w.formTitle, w.weakCategory, w.status, String(w.averageScore)]
          .some((val) => String(val || '').toLowerCase().includes(query))
      )
    : weakAreas;
  const programLabel = (summaryData?.programs || []).map((program) => program.code).join(', ') || 'Assigned Program';
  const reviewedCount = facultyResults.filter((row) => row.averageRating !== 'Pending').length;
  const completionRate = facultyResults.length > 0 ? Math.round((reviewedCount / facultyResults.length) * 100) : 0;

  return (
    <div className="eval-monitor-container program-summary-container module-wide page-enter">
      <div className="role-summary-header">
        <div>
          <p className="eyebrow">Summary and Plans</p>
          <h2>Program Faculty Summary</h2>
          <p>Review submitted faculty results, weak areas, and development plans for {programLabel}.</p>
          {liveRefreshing && <span className="live-refresh-indicator">Updating summary...</span>}
        </div>
        <span className="role-summary-chip"><Target size={14} /> {programLabel}</span>
      </div>

      <div className="role-summary-band">
        <div className="role-summary-donut" style={{ '--pct': `${completionRate}%` }}>
          <strong>{completionRate}%</strong>
          <span>Reviewed</span>
        </div>
        <div className="role-summary-metrics">
          <article><Users size={18} /><span>Faculty</span><strong>{facultyResults.length}</strong></article>
          <article><CheckCircle2 size={18} /><span>Reviewed</span><strong>{reviewedCount}</strong></article>
          <article><AlertTriangle size={18} /><span>Weak Areas</span><strong>{weakAreas.length}</strong></article>
          <article><GraduationCap size={18} /><span>Plans</span><strong>{trainingPlans.length}</strong></article>
        </div>
      </div>

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

      {error && <div className="eval-monitor-empty error">{error}</div>}

      {!loading && !error && (
        <>
          <div className="eval-monitor-table-container role-summary-panel">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <ClipboardList size={16} />
                <span>Program Faculty Results — {programLabel} faculty only</span>
              </div>
            </div>
            {facultyResults.length === 0 ? (
              <div className="eval-monitor-empty">No faculty results are available for this program yet.</div>
            ) : (
              <div className="data-table-scroll">
                <DataTable columns={[
                  { key: 'faculty', label: 'Faculty' },
                  { key: 'program', label: 'Program' },
                  { key: 'averageRating', label: 'Average Rating' },
                  { key: 'weakArea', label: 'Weak Area' },
                  { key: 'result', label: 'Result' },
                  { key: 'seminar', label: 'Recommended Seminar' },
                ]} rows={facultyResults} />
              </div>
            )}
          </div>

          {/* Weak Area Register — Per Evaluation Result */}
          <div className="eval-monitor-table-container">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <AlertTriangle size={16} />
                <span>Weak Area Register — Individual records from completed evaluations</span>
              </div>
              <div className="eval-monitor-toolbar-actions">
                <input
                  type="text"
                  className="weak-area-filter-input"
                  placeholder="Search faculty, category, program..."
                  value={weakSearch}
                  onChange={(e) => setWeakSearch(e.target.value)}
                />
                <small>{filteredWeak.length} record{filteredWeak.length !== 1 ? 's' : ''}</small>
              </div>
            </div>
            {filteredWeak.length === 0 ? (
              <div className="eval-monitor-empty">
                <Search size={28} />
                <strong>{weakAreas.length === 0 ? 'No weak areas detected yet.' : 'No records match your search'}</strong>
                <p>{weakAreas.length === 0 ? 'Weak areas will appear once completed evaluations with scores below 3.5 are submitted.' : 'Try adjusting your search query.'}</p>
              </div>
            ) : (
              <div className="data-table-scroll">
                <DataTable columns={[
                  { key: 'facultyName', label: 'Faculty' },
                  { key: 'department', label: 'Department' },
                  { key: 'program', label: 'Program' },
                  { key: 'formTitle', label: 'Form' },
                  { key: 'weakCategory', label: 'Weak Category' },
                  { key: 'averageScore', label: 'Score' },
                  { key: 'dateSubmitted', label: 'Date Submitted' },
                  { key: 'status', label: 'Status' },
                ]} rows={filteredWeak} />
              </div>
            )}
          </div>

          {/* Development Plans */}
          <div className="eval-monitor-table-container">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <Search size={16} />
                <span>Recommended Seminars and Development Plans</span>
              </div>
              <div className="eval-monitor-toolbar-actions">
                <small>Based on {programLabel} faculty results</small>
              </div>
            </div>
            {trainingPlans.length === 0 ? (
              <div className="eval-monitor-empty">No seminar recommendations are available yet.</div>
            ) : (
              <div className="data-table-scroll">
                <DataTable columns={[
                  { key: 'program', label: 'Program' },
                  { key: 'weakArea', label: 'Weak Area' },
                  { key: 'facultyCount', label: 'Faculty' },
                  { key: 'seminar', label: 'Seminar' },
                  { key: 'recommendation', label: 'Recommendation' },
                  { key: 'status', label: 'Status' },
                ]} rows={trainingPlans} />
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}

function FacultyInsights() {
  const { selectedPeriodId } = useEvaluationPeriod();
  const [insightData, setInsightData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadInsights = useCallback(async (background = false) => {
    if (!background) setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams();
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      const payload = await apiFetch(`/api/program-head-summary.php?${params.toString()}`);
      setInsightData(payload.data || null);
    } catch (err) {
      setError(err.message || 'Unable to load faculty insights.');
    } finally {
      if (!background) setLoading(false);
    }
  }, [selectedPeriodId]);

  const { refreshing: liveRefreshing } = useLiveRefresh(loadInsights, [selectedPeriodId], {
    intervalMs: 6000,
  });

  const facultyRows = insightData?.facultyResults || [];
  const trainingPlans = insightData?.trainingPlans || [];
  const weakAreas = insightData?.weakAreas || [];
  const programLabel = (insightData?.programs || []).map((p) => p.code).join(', ') || 'Assigned Program';
  const firstWeak = weakAreas[0]?.weakArea || 'No weak area yet';

  return (
    <section className="program-insights-shell module-wide page-enter">
      <div className="program-insights-hero">
        <div>
          <p className="eyebrow">Faculty Insights</p>
          <h2>Faculty Performance Analysis</h2>
          <p>Focused AI review for faculty assigned to {programLabel}. Use these signals to prioritize coaching, seminars, and follow-up conversations.</p>
          {liveRefreshing && <span className="live-refresh-indicator">Updating insights...</span>}
        </div>
        <div className="program-insights-scope">
          <span>Program Scope</span>
          <strong>{programLabel}</strong>
        </div>
      </div>

      <div className="program-insights-stats">
        <article><span>Faculty Reviewed</span><strong>{facultyRows.length}</strong><small>With submitted evaluation results</small></article>
        <article><span>Priority Weak Area</span><strong>{firstWeak}</strong><small>Needs the earliest follow-up</small></article>
        <article><span>Development Plans</span><strong>{trainingPlans.length}</strong><small>Recommended actions linked to this scope</small></article>
      </div>

      {loading && <div className="dipascaf-empty">Loading faculty insights...</div>}
      {error && <div className="notice warning">{error}</div>}

      {!loading && !error && facultyRows.length === 0 && (
        <div className="dipascaf-empty">No evaluation results are available for your assigned program yet. Submit program head evaluations first.</div>
      )}

      {!loading && !error && facultyRows.length > 0 && (
        <div className="program-insights-grid">
          {facultyRows.map((row, index) => (
            <article className="program-insight-card" key={row.id || row.faculty}>
              <div className="program-insight-head">
                <div>
                  <span>{row.program}</span>
                  <h3>{row.faculty}</h3>
                </div>
                <strong>{row.averageRating !== 'Pending' ? row.averageRating : '—'}</strong>
              </div>
              <div className="program-insight-signals">
                <div className="signal strength"><span>Result</span><strong>{row.result}</strong></div>
                <div className="signal weak"><span>Needs Attention</span><strong>{row.weakArea}</strong></div>
              </div>
              <p>Recent appraisal signals suggest this faculty member should receive targeted support in <strong>{row.weakArea}</strong> while maintaining current performance levels.</p>
              <div className="program-insight-action"><span>Recommended Seminar</span><strong>{row.seminar}</strong></div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
