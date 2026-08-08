import { useMemo } from 'react';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import { Calendar, ChevronDown, RefreshCw, Loader2, LockKeyhole } from 'lucide-react';

export default function PeriodSelector({ compact = false, className = '', showRefresh = true }) {
  const { selectedPeriodId, setSelectedPeriodId, periods, selectedPeriod, loading, refresh } = useEvaluationPeriod();
  const visiblePeriods = useMemo(() => {
    const uniquePeriods = new Map();

    periods.forEach((period) => {
      const name = String(period.period_name || '').trim().replace(/\s+/g, ' ');
      const schoolYear = String(period.school_year || '').trim().replace(/\s+/g, ' ');
      const key = `${name.toLocaleLowerCase()}|${schoolYear.toLocaleLowerCase()}`;
      const existing = uniquePeriods.get(key);

      // Keep the active selection visible; otherwise prefer the open/current record.
      if (
        !existing
        || String(period.id) === String(selectedPeriodId)
        || (!existing.is_open && period.is_open)
      ) {
        uniquePeriods.set(key, period);
      }
    });

    return [...uniquePeriods.values()].sort((left, right) => {
      const yearOf = (period) => {
        const source = String(period.school_year || period.year || period.period_name || '');
        return Number(source.match(/\b(20\d{2})\b/)?.[1] || 0);
      };
      return yearOf(right) - yearOf(left)
        || String(right.date_start || '').localeCompare(String(left.date_start || ''))
        || Number(right.id || 0) - Number(left.id || 0);
    });
  }, [periods, selectedPeriodId]);

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

  const selectedStatus = String(selectedPeriod?.status || (selectedPeriod?.is_open ? 'open' : 'draft')).toLowerCase();
  const selectedStatusLabel = selectedStatus === 'open'
    ? 'Open'
    : selectedStatus === 'locked'
      ? 'Locked'
      : selectedStatus === 'closed'
        ? 'Closed'
        : 'Draft';

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
          {visiblePeriods.map((period) => (
            <option key={period.id} value={period.id}>
              {period.period_name}
              {period.school_year ? ` (${period.school_year})` : ''}
            </option>
          ))}
        </select>
        <ChevronDown size={12} className="period-selector-chevron" />
      </div>
      {selectedPeriod && (
        <span className={`period-selector-status ${selectedStatus}`}>
          {selectedStatus === 'locked' && <LockKeyhole size={12} aria-hidden="true" />}
          {selectedStatusLabel}
        </span>
      )}
      {showRefresh && (
        <button
          type="button"
          className="period-selector-refresh"
          onClick={() => refresh({ selectCurrent: true })}
          title="Refresh periods"
          aria-label="Refresh periods"
        >
          <RefreshCw size={12} />
        </button>
      )}
    </div>
  );
}
