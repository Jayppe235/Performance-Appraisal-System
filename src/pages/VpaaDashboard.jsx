import { Link, Navigate, useParams } from 'react-router-dom';
import { useMemo } from 'react';
import { BarChart3, ClipboardCheck, FileText, UserRoundCheck } from 'lucide-react';
import { Pie } from 'react-chartjs-2';
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js';
import Hero from '../components/common/Hero.jsx';
import MetricGrid from '../components/common/MetricGrid.jsx';
import ReportGrid from '../components/common/ReportGrid.jsx';
import EvaluationDashboard from '../components/evaluations/EvaluationDashboard.jsx';
import DeanSelfEvaluationReview from '../components/evaluations/DeanSelfEvaluationReview.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import AdminEvaluationMonitor from '../components/evaluations/AdminEvaluationMonitor.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';

ChartJS.register(ArcElement, Tooltip, Legend);

export default function VpaaDashboard({ role }) {
  const { section = 'overview' } = useParams();
  const activeSection = section;
  const showAnalyticsPage = activeSection === 'analytics';
  const { selectedPeriodId } = useEvaluationPeriod();
  // Real-time metrics from backend API - auto-refreshes every 5 seconds
  const { metrics: liveMetrics, actionCenter: apiActionCenter, loading, error, timestamp } = useRealtimeMetrics(
    'vpaa',
    { periodId: selectedPeriodId }
  );

  const actionCenter = useMemo(() => {
    if (!apiActionCenter) {
      return { items: [], total: 0, ready: 0 };
    }
    const items = apiActionCenter.map((item) => ({
      ...item,
      count: Number(item.count) || 0,
    }));
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
      {
        label: 'Departments',
        value: metric('Departments'),
        help: 'Open institution-level department analysis',
        href: '/vpaa/analytics',
        cta: 'View departments',
        tone: 'primary',
      },
      {
        label: 'Active Faculty',
        value: metric('Active Faculty'),
        help: 'Faculty included in current academic monitoring',
        href: '/vpaa/analytics',
        cta: 'Review faculty',
        tone: 'info',
      },
      {
        label: 'Pending Evaluations',
        value: metric('Pending Evaluations'),
        help: 'Assignments awaiting submission',
        href: '/vpaa/evaluate',
        cta: 'Evaluate deans',
        tone: 'warning',
      },
      {
        label: 'Completed Evaluations',
        value: metric('Completed Evaluations'),
        help: 'Submitted evaluation records',
        href: '/vpaa/evaluate',
        cta: 'View submissions',
        tone: 'success',
      },
      {
        label: 'Completion Rate',
        value: metric('Completion Rate'),
        help: 'Institution evaluation progress',
        href: '/vpaa/analytics',
        cta: 'View progress',
        tone: 'accent',
      },
      {
        label: 'Academic Insights',
        value: metric('AI Insights'),
        help: 'Generated findings from appraisal results',
        href: '/vpaa/analytics',
        cta: 'Open insights',
        tone: 'info',
      },
      {
        label: 'Overdue Evaluations',
        value: action('Overdue evaluations'),
        help: 'Past deadline and still open',
        href: '/vpaa/analytics',
        cta: 'Review overdue',
        tone: 'danger',
      },
      {
        label: 'Weak Areas',
        value: action('Weak areas'),
        help: 'Institutional priority records needing attention',
        href: '/vpaa/analytics',
        cta: 'Analyze',
        tone: 'warning',
      },
      {
        label: 'Development Plans',
        value: action('Development plans'),
        help: 'Recommended interventions and training actions',
        href: '/vpaa/reports',
        cta: 'Open reports',
        tone: 'success',
      },
    ];
  }, [actionCenter.items, liveMetrics]);

  const evaluationChart = useMemo(() => {
    const metrics = new Map(liveMetrics.map((item) => [String(item.label || '').toLowerCase(), Number.parseFloat(item.value) || 0]));
    const completed = metrics.get('completed evaluations') || 0;
    const pending = metrics.get('pending evaluations') || 0;
    const overdue = Number(actionCenter.items.find((item) => String(item.label).toLowerCase() === 'overdue evaluations')?.count || 0);
    return {
      completed,
      pending: Math.max(0, pending - overdue),
      overdue,
      total: completed + pending,
    };
  }, [actionCenter.items, liveMetrics]);

  const sectionClassName = [
    'admin-content',
    'admin-module',
    'dean-content',
    activeSection === 'overview' ? 'vpaa-overview-content vpaa-dashboard-content' : 'vpaa-dashboard-content',
    activeSection === 'reports' ? 'reports-analytics-content' : '',
  ].filter(Boolean).join(' ');

  return (
    <section className={sectionClassName}>
      {activeSection === 'summary' && <Navigate to="/vpaa/analytics" replace />}
      {activeSection === 'overview' && (
        <>
          <Hero
            className="welcome-dashboard-hero"
            eyebrow="VPAA Dashboard"
            title={`Welcome back, ${role.user.name}`}
            actions={<PeriodSelector compact />}
          >
            High-level academic monitoring for assigned departments, evaluation completion, weak-area trends, and intervention follow-through.
          </Hero>
          <section className="admin-dashboard-unified vpaa-role-dashboard module-wide page-enter" aria-labelledby="vpaa-dashboard-unified-title">
            <div className="action-center-head vpaa-role-dashboard-head">
              <div>
                <p className="eyebrow">Academic Affairs Dashboard</p>
                <h2 id="vpaa-dashboard-unified-title">VPAA Dashboard</h2>
                <p>{error ? `Live refresh paused: ${error}` : `Live results from departments and appraisal records within your VPAA scope${timestamp ? `, updated ${new Date(timestamp * 1000).toLocaleTimeString()}` : ''}.`}</p>
              </div>
              <div className={`vpaa-live-status ${error ? 'is-error' : ''}`}><span />{loading ? 'Loading live data' : error ? 'Reconnecting' : 'Live database data'}</div>
            </div>
            <MetricGrid items={dashboardCards} compact className="vpaa-role-metrics" />
            <section className="vpaa-evaluation-chart-panel" aria-labelledby="vpaa-evaluation-chart-title">
              <div className="vpaa-chart-copy">
                <p className="eyebrow">Evaluation Progress</p>
                <h3 id="vpaa-evaluation-chart-title">Current appraisal status</h3>
                <p>A live distribution of completed, pending, and overdue assignments across departments within your VPAA scope.</p>
                <div className="vpaa-chart-total"><strong>{evaluationChart.total}</strong><span>Total evaluation assignments</span></div>
              </div>
              <div className="vpaa-pie-wrap">
                {evaluationChart.total > 0 ? (
                  <Pie
                    aria-label={`Evaluation status: ${evaluationChart.completed} completed, ${evaluationChart.pending} pending, ${evaluationChart.overdue} overdue`}
                    data={{
                      labels: ['Completed', 'Pending', 'Overdue'],
                      datasets: [{
                        data: [evaluationChart.completed, evaluationChart.pending, evaluationChart.overdue],
                        backgroundColor: ['#16a05d', '#efa914', '#dc3545'],
                        borderColor: ['#ffffff', '#ffffff', '#ffffff'],
                        borderWidth: 3,
                        hoverOffset: 7,
                      }],
                    }}
                    options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 10, padding: 18 } }, tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw}` } } } }}
                  />
                ) : <div className="vpaa-chart-empty">No evaluation assignments for this period.</div>}
              </div>
              <div className="vpaa-chart-breakdown" aria-label="Evaluation status totals">
                <span className="completed"><i />Completed<strong>{evaluationChart.completed}</strong></span>
                <span className="pending"><i />Pending<strong>{evaluationChart.pending}</strong></span>
                <span className="overdue"><i />Overdue<strong>{evaluationChart.overdue}</strong></span>
              </div>
            </section>
            <div className="vpaa-role-dashboard-lower">
              <section className="vpaa-priority-panel" aria-labelledby="vpaa-priority-title">
                <div className="vpaa-panel-heading"><div><p className="eyebrow">Priority Queue</p><h3 id="vpaa-priority-title">Items requiring VPAA review</h3></div><strong>{actionCenter.total}</strong></div>
                <div className="vpaa-priority-list">
                  {actionCenter.items.length > 0 ? actionCenter.items.map((item) => (
                    <Link key={item.label} to={item.href === '/vpaa/summary' ? '/vpaa/analytics' : item.href} className={`vpaa-priority-item tone-${item.tone || 'info'}`}>
                      <span className="vpaa-priority-count">{item.count}</span>
                      <span><strong>{item.label}</strong><small>{item.detail}</small></span>
                      <b>{item.cta}</b>
                    </Link>
                  )) : <p className="dipascaf-empty">No VPAA action items are available for this period.</p>}
                </div>
              </section>
              <section className="vpaa-workspace-panel" aria-labelledby="vpaa-workspace-title">
                <div className="vpaa-panel-heading"><div><p className="eyebrow">VPAA Modules</p><h3 id="vpaa-workspace-title">Continue your work</h3></div></div>
                <div className="vpaa-workspace-links">
                  <Link to="/vpaa/evaluate"><ClipboardCheck /><span><strong>Dean Evaluations</strong><small>Complete and review assigned dean appraisals</small></span></Link>
                  <Link to="/vpaa/self-evaluation-review"><UserRoundCheck /><span><strong>Dean Self-Evaluations</strong><small>Review submitted leadership self-assessments</small></span></Link>
                  <Link to="/vpaa/analytics"><BarChart3 /><span><strong>Academic Analytics</strong><small>Compare departments, programs, and weak areas</small></span></Link>
                  <Link to="/vpaa/reports"><FileText /><span><strong>Institution Reports</strong><small>Generate scoped appraisal reports</small></span></Link>
                </div>
              </section>
            </div>
          </section>
        </>
      )}
      {activeSection === 'evaluate' && (
        <>
          <EvaluationDashboard
            eyebrow="VPAA Evaluation"
            title="Evaluate Department Deans"
            subtitle="Complete the assigned dean evaluation forms for your VPAA departments."
            evaluatorRole="vpaa"
            role={role}
          />
        </>
      )}
      {activeSection === 'self-evaluation' && <Navigate to="/vpaa/evaluate" replace />}
      {activeSection === 'self-evaluation-review' && <DeanSelfEvaluationReview role={role} />}
      {showAnalyticsPage && (
        <>
          <AdminEvaluationMonitor initialView="groups" />
        </>
      )}
      {activeSection === 'reports' && <ReportGrid role={role} />}
    </section>
  );
}
