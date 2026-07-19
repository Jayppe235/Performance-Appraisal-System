import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import apiFetch from '../data/api.js';

const EvaluationPeriodContext = createContext(null);

function isSmokePeriod(period) {
  const name = String(period?.period_name || '');
  return /Smoke Self Eval Period/i.test(name) || /\bSMK\d+\b/i.test(name);
}

export function useEvaluationPeriod() {
  const ctx = useContext(EvaluationPeriodContext);
  if (!ctx) {
    return {
      selectedPeriodId: '',
      setSelectedPeriodId: () => {},
      periods: [],
      selectedPeriod: null,
      loading: false,
      error: '',
      refresh: () => {},
    };
  }
  return ctx;
}

export function EvaluationPeriodProvider({ children }) {
  const [periods, setPeriods] = useState([]);
  const [selectedPeriodId, setSelectedPeriodId] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const fetchPeriods = useCallback(async (options = {}) => {
    setLoading(true);
    setError('');
    try {
      const payload = await apiFetch('/api/evaluation-period.php?action=periods');
      if (payload.ok && Array.isArray(payload.data)) {
        const list = payload.data.filter((period) => !isSmokePeriod(period));
        setPeriods(list);
        // Auto-select current period on demand, otherwise keep the user's selected period.
        setSelectedPeriodId((current) => {
          const requestedId = options.selectPeriodId ? String(options.selectPeriodId) : '';
          if (requestedId && list.some((period) => String(period.id) === requestedId)) {
            return requestedId;
          }

          const currentId = payload.current?.id ? String(payload.current.id) : '';
          if (options.selectCurrent && currentId) return currentId;
          if (current && list.some((period) => String(period.id) === current)) return current;
          return currentId || (list[0]?.id ? String(list[0].id) : '');
        });
      } else {
        setPeriods([]);
        setError(payload.message || 'Failed to load evaluation periods.');
      }
    } catch (err) {
      setError(err.message);
      setPeriods([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchPeriods();
  }, [fetchPeriods]);

  const selectedPeriod = periods.find((p) => String(p.id) === selectedPeriodId) || null;

  return (
    <EvaluationPeriodContext.Provider
      value={{
        selectedPeriodId,
        setSelectedPeriodId,
        periods,
        selectedPeriod,
        loading,
        error,
        refresh: fetchPeriods,
      }}
    >
      {children}
    </EvaluationPeriodContext.Provider>
  );
}
