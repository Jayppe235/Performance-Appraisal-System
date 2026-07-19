import { useMemo } from 'react';
import { Navigate } from 'react-router-dom';
import { useParams } from 'react-router-dom';
import Hero from '../components/common/Hero.jsx';
import MetricGrid from '../components/common/MetricGrid.jsx';
import EvaluationDashboard from '../components/evaluations/EvaluationDashboard.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import PersonalPerformanceSummary from '../components/evaluations/PersonalPerformanceSummary.jsx';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';

export default function FacultyDashboard({ role }) {
  const { section = 'overview' } = useParams();
  const { selectedPeriodId } = useEvaluationPeriod();

  // Real-time metrics from backend API - auto-refreshes every 5 seconds
  const { metrics, actionCenter: apiActionCenter, loading, error, timestamp } = useRealtimeMetrics('faculty', {
    userId: role?.user?.id || '',
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
