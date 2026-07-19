import { Navigate, useParams } from 'react-router-dom';
import { useMemo } from 'react';
import Hero from '../components/common/Hero.jsx';
import MetricGrid from '../components/common/MetricGrid.jsx';
import ReportGrid from '../components/common/ReportGrid.jsx';
import EvaluationDashboard from '../components/evaluations/EvaluationDashboard.jsx';
import DeanSelfEvaluationReview from '../components/evaluations/DeanSelfEvaluationReview.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import DepartmentAiInsights from '../components/ai/DepartmentAiInsights.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';

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
        href: '/vpaa/analytics',
        cta: 'Open pending',
        tone: 'warning',
      },
      {
        label: 'Completed Evaluations',
        value: metric('Completed Evaluations'),
        help: 'Submitted evaluation records',
        href: '/vpaa/analytics',
        cta: 'View completed',
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
        label: 'AI Insights',
        value: metric('AI Insights'),
        help: 'Weak areas and generated academic insights',
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
        tone: 'warning',
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
        href: '/vpaa/analytics',
        cta: 'View plans',
        tone: 'success',
      },
    ];
  }, [actionCenter.items, liveMetrics]);

  const sectionClassName = [
    'admin-content',
    'admin-module',
    'dean-content',
    activeSection === 'overview' ? 'vpaa-overview-content' : '',
    activeSection === 'overview' ? '' : 'vpaa-dashboard-content',
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
          <section className="admin-dashboard-unified module-wide page-enter" aria-labelledby="vpaa-dashboard-unified-title">
            <div className="action-center-head">
              <div>
                <p className="eyebrow">Dashboard</p>
                <h2 id="vpaa-dashboard-unified-title">Dashboard Summary</h2>
                <p>{error ? `Live refresh paused: ${error}` : `Review current institution appraisal status${timestamp ? `, updated ${new Date(timestamp * 1000).toLocaleTimeString()}` : ''}.`}</p>
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
          <DepartmentAiInsights />
        </>
      )}
      {activeSection === 'reports' && <ReportGrid role={role} />}
    </section>
  );
}
