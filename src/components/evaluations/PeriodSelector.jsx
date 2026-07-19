import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import { Calendar, ChevronDown, RefreshCw, Loader2 } from 'lucide-react';

export default function PeriodSelector({ compact = false, className = '', showRefresh = true }) {
  const { selectedPeriodId, setSelectedPeriodId, periods, selectedPeriod, loading, refresh } = useEvaluationPeriod();

  if (loading) {
    return (
      <div className={`period-selector ${compact ? 'period-selector-compact' : ''} ${className}`}>
        <Loader2 size={14} className="animate-spin" />
        <span className="period-selector-label">Loading periods...</span>
      </div>
    );
  }

  if (periods.length === 0) {
    return null;
  }

  return (
    <div className={`period-selector ${compact ? 'period-selector-compact' : ''} ${className}`}>
      <Calendar size={14} className="period-selector-icon" />
      <span className="period-selector-label">{compact ? '' : 'Evaluation Period:'}</span>
      <div className="period-selector-select-wrapper">
        <select
          value={selectedPeriodId}
          onChange={(e) => setSelectedPeriodId(e.target.value)}
          className="period-selector-select"
          aria-label="Select evaluation period"
        >
          {periods.map((period) => (
            <option key={period.id} value={period.id}>
              {period.period_name}
              {period.school_year ? ` (${period.school_year})` : ''}
              {period.semester ? ` - ${period.semester}` : ''}
            </option>
          ))}
        </select>
        <ChevronDown size={12} className="period-selector-chevron" />
      </div>
      {selectedPeriod && (
        <span className={`period-selector-status ${selectedPeriod.is_open ? 'open' : 'locked'}`}>
          {selectedPeriod.is_open ? 'Open' : 'Locked'}
        </span>
      )}
      {showRefresh && (
        <button
          type="button"
          className="period-selector-refresh"
          onClick={() => refresh()}
          title="Refresh periods"
          aria-label="Refresh periods"
        >
          <RefreshCw size={12} />
        </button>
      )}
    </div>
  );
}
