import { Link } from 'react-router-dom';
import { Building2, CheckCircle2, ClipboardList, Lightbulb, Users, UserRoundCheck } from 'lucide-react';

const metricIcons = [
  Users,
  ClipboardList,
  UserRoundCheck,
  CheckCircle2,
  Building2,
  Lightbulb,
];

function metricTone(label, index) {
  const lower = String(label || '').toLowerCase();
  if (lower.includes('pending') || lower.includes('overdue')) return 'warning';
  if (lower.includes('completed') || lower.includes('submitted')) return 'success';
  if (lower.includes('department') || lower.includes('program')) return 'info';
  return ['primary', 'warning', 'info', 'success', 'accent', 'primary'][index % 6];
}

export default function MetricGrid({ items, compact = false, className = '' }) {
  return (
    <section className={`admin-box stat-grid module-wide dashboard-metric-grid ${compact ? 'compact' : ''} ${className}`.trim()}>
      {items.map((item, index) => {
        const Icon = metricIcons[index % metricIcons.length];
        const CardTag = item.href ? Link : 'article';
        const cardProps = item.href ? { to: item.href } : {};
        return (
        <CardTag key={item.label} {...cardProps} className={`card-pop dashboard-metric-card metric-${item.tone || metricTone(item.label, index)} ${item.href ? 'is-clickable' : ''}`}>
          <div className="metric-card-head">
            <span>{item.label}</span>
            <span className="metric-card-icon" aria-hidden="true"><Icon size={18} strokeWidth={2.4} /></span>
          </div>
          <strong>{item.value}</strong>
          {item.help && <small>{item.help}</small>}
          {item.cta && <span className="dashboard-metric-cta">{item.cta}</span>}
        </CardTag>
        );
      })}
    </section>
  );
}
