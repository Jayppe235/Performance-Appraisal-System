import { useMemo } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { ArrowRight, CheckCircle2, ClipboardList, Clock3 } from 'lucide-react';
import { Doughnut } from 'react-chartjs-2';
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js';
import Hero from '../components/common/Hero.jsx';
import MetricGrid from '../components/common/MetricGrid.jsx';
import EvaluationDashboard from '../components/evaluations/EvaluationDashboard.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import PersonalPerformanceSummary from '../components/evaluations/PersonalPerformanceSummary.jsx';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';

ChartJS.register(ArcElement, Tooltip, Legend);

export default function FacultyDashboard({ role }) {
  const { section = 'overview' } = useParams();
  const { selectedPeriodId } = useEvaluationPeriod();

  // Real-time metrics from backend API - auto-refreshes every 5 seconds
  const { metrics, actionCenter: apiActionCenter, loading, error, timestamp } = useRealtimeMetrics('faculty', {
    userId: role?.user?.id || '',
    periodId: selectedPeriodId,
  });

  const actionCenter = useMemo(() => {
    const pendingMetric = Number(metrics.find((item) => String(item.label).toLowerCase() === 'pending evaluations')?.value) || 0;
    const sourceItems = apiActionCenter || (pendingMetric > 0 ? [{
      label: 'Pending evaluations', count: pendingMetric, detail: 'Ratings still waiting for submission',
      href: '/faculty/evaluate', cta: 'Continue', tone: 'warning',
    }] : []);
    const items = sourceItems.map((item) => ({ ...item, count: Number(item.count) || 0 }));
    return {
      items,
      total: items.reduce((total, item) => total + item.count, 0),
      ready: items.filter((item) => item.count === 0).length,
    };
  }, [apiActionCenter, metrics]);

  const evaluationProgress = useMemo(() => {
    const metricByLabel = new Map(metrics.map((item) => [String(item.label || '').toLowerCase(), Number(item.value) || 0]));
    const assigned = metricByLabel.get('assigned tasks') || 0;
    const pending = metricByLabel.get('pending evaluations') || 0;
    const submitted = metricByLabel.get('submitted evaluations') || 0;
    const total = Math.max(assigned, pending + submitted);
    const percentage = total > 0 ? Math.round((submitted / total) * 100) : 0;
    return { assigned: total, pending, submitted, percentage };
  }, [metrics]);

  const progressChartData = useMemo(() => ({
    labels: ['Submitted', 'Pending'],
    datasets: [{
      data: [evaluationProgress.submitted, evaluationProgress.pending],
      backgroundColor: ['#22c55e', '#f59e0b'],
      borderColor: ['#ffffff', '#ffffff'],
      borderWidth: 4,
      hoverOffset: 6,
    }],
  }), [evaluationProgress.pending, evaluationProgress.submitted]);

  const progressChartOptions = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '72%',
    animation: { duration: 1400, easing: 'easeOutQuart', animateRotate: true, animateScale: true },
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw}` } },
    },
  }), []);

  const dashboardCards = useMemo(() => {
    const metricByLabel = new Map(metrics.map((item) => [String(item.label || '').toLowerCase(), item]));
    const actionByLabel = new Map(actionCenter.items.map((item) => [String(item.label || '').toLowerCase(), item]));
    const metric = (label, fallback = 0) => metricByLabel.get(label.toLowerCase())?.value ?? fallback;
    const action = (label, fallback = 0) => actionByLabel.get(label.toLowerCase())?.count ?? fallback;

    return [
      { label: 'Assigned Tasks', value: metric('Assigned Tasks'), help: 'Confidential appraisal cards assigned to you', href: '/faculty/evaluate', cta: 'Open tasks', tone: 'primary' },
      { label: 'Pending Evaluations', value: metric('Pending Evaluations'), help: 'Ratings still waiting for submission', href: '/faculty/evaluate', cta: 'Start pending', tone: 'warning' },
      { label: 'Submitted Evaluations', value: metric('Submitted Evaluations'), help: 'Completed faculty evaluation records', href: '/faculty/evaluate', cta: 'Review work', tone: 'success' },
      { label: 'Evaluations Received', value: metric('Evaluations Received'), help: 'Feedback and performance records available', href: '/faculty/results', cta: 'View results', tone: 'accent' },
      { label: 'Performance Summary', value: metric('Evaluations Received'), help: 'Personal appraisal insights and ratings', href: '/faculty/results', cta: 'Open summary', tone: 'info' },
      { label: 'Checks Clear', value: actionCenter.ready, help: 'Faculty dashboard reminders already clear', href: '/faculty/overview', cta: 'View overview', tone: 'success' },
      { label: 'Needs Attention', value: action('Pending evaluations', actionCenter.total), help: 'Tasks that still need action', href: '/faculty/evaluate', cta: 'Resolve now', tone: 'warning' },
    ];
  }, [actionCenter.items, actionCenter.ready, actionCenter.total, metrics]);

  return (
    <section className="admin-content admin-module dean-content faculty-content">
      {section === 'overview' && (
        <>
          <Hero
            className="welcome-dashboard-hero"
            eyebrow="Teacher Dashboard"
            title={`Welcome back, ${role.user.name}`}
            actions={<PeriodSelector compact />}
          >
            Review assigned confidential evaluation tasks, track submissions, and monitor your personal appraisal progress.
          </Hero>
          <section className="admin-dashboard-unified module-wide page-enter faculty-dashboard-unified" aria-labelledby="faculty-dashboard-unified-title">
            <div className="action-center-head">
              <div>
                <p className="eyebrow">Dashboard</p>
                <h2 id="faculty-dashboard-unified-title">Dashboard Summary</h2>
                <p>{error ? `Live refresh paused: ${error}` : `Review your current faculty appraisal status${timestamp ? `, updated ${new Date(timestamp * 1000).toLocaleTimeString()}` : ''}.`}</p>
              </div>
              <div className={`action-center-summary ${actionCenter.total > 0 ? 'has-alerts' : 'is-clear'}`}>
                <strong>{actionCenter.total}</strong>
                <span>{loading ? 'refreshing' : actionCenter.total > 0 ? 'items need attention' : 'all clear'}</span>
                <small>{actionCenter.ready} checks clear</small>
              </div>
            </div>
            <div className="faculty-dashboard-focus-grid">
              <section className="faculty-progress-panel" aria-labelledby="faculty-progress-title">
                <div className="faculty-focus-copy">
                  <p className="eyebrow">Evaluation Progress</p>
                  <h3 id="faculty-progress-title">Your appraisal completion</h3>
                  <p>{evaluationProgress.assigned > 0 ? `${evaluationProgress.submitted} of ${evaluationProgress.assigned} assigned evaluations submitted.` : 'No evaluations are assigned for this period.'}</p>
                  <div className="faculty-progress-legend">
                    <span className="submitted"><i />Submitted <strong>{evaluationProgress.submitted}</strong></span>
                    <span className="pending"><i />Pending <strong>{evaluationProgress.pending}</strong></span>
                  </div>
                </div>
                <div className="faculty-progress-chart">
                  {evaluationProgress.assigned > 0 ? <Doughnut data={progressChartData} options={progressChartOptions} aria-label={`${evaluationProgress.percentage}% of assigned evaluations submitted`} /> : <div className="faculty-progress-empty"><CheckCircle2 size={42} /><span>All clear</span></div>}
                  {evaluationProgress.assigned > 0 && <div className="faculty-progress-center"><strong>{evaluationProgress.percentage}%</strong><span>complete</span></div>}
                </div>
              </section>
              <section className={`faculty-next-action ${evaluationProgress.pending > 0 ? 'has-pending' : 'is-complete'}`} aria-labelledby="faculty-next-action-title">
                <div className="faculty-next-icon">{evaluationProgress.pending > 0 ? <ClipboardList /> : <CheckCircle2 />}</div>
                <div>
                  <p className="eyebrow">Next Action</p>
                  <h3 id="faculty-next-action-title">{evaluationProgress.pending > 0 ? `${evaluationProgress.pending} evaluation${evaluationProgress.pending === 1 ? '' : 's'} awaiting submission` : 'You are up to date'}</h3>
                  <p>{evaluationProgress.pending > 0 ? 'Continue your assigned appraisal tasks and submit completed ratings before the current evaluation period closes.' : 'There are no pending evaluation tasks requiring your attention right now.'}</p>
                </div>
                <div className="faculty-next-meta"><Clock3 size={16} /><span>{evaluationProgress.pending > 0 ? 'Current evaluation period' : 'No outstanding deadlines'}</span></div>
                <Link to={evaluationProgress.pending > 0 ? '/faculty/evaluate' : '/faculty/results'}>{evaluationProgress.pending > 0 ? 'Continue evaluation' : 'View performance'}<ArrowRight size={17} /></Link>
              </section>
            </div>
            <MetricGrid items={dashboardCards.length > 0 ? dashboardCards : [{ label: 'Loading...', value: '...' }]} compact />
          </section>
        </>
      )}
      {section === 'evaluate' && (
        <>
          <EvaluationDashboard eyebrow="Faculty Evaluation" title="Evaluate Dean, Program Heads, and Peer Faculty" subtitle="Peer-to-peer evaluations are confidential. Use filters to focus on pending or completed tasks." evaluatorRole={role.key} role={role} />
        </>
      )}
      {section === 'self-evaluation' && <Navigate to="/faculty/evaluate" replace />}
      {section === 'results' && (
        <>
          <PersonalPerformanceSummary receivedCount={metrics.find(m => m.label === 'Evaluations Received')?.value || 0} />
        </>
      )}
    </section>
  );
}
