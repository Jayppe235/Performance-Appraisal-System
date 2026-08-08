import { createContext, useContext, useEffect, useRef, useState, useCallback } from 'react';
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
  const [selectedPeriodId, setSelectedPeriodId] = useState(() => {
    try {
      return window.sessionStorage.getItem('pmas-selected-evaluation-period-id') || '';
    } catch {
      return '';
    }
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const currentPeriodIdRef = useRef('');

  const fetchPeriods = useCallback(async (options = {}) => {
    if (!options.silent) setLoading(true);
    setError('');
    try {
      const payload = await apiFetch('/api/evaluation-period.php?action=periods');
      if (payload.ok && Array.isArray(payload.data)) {
        const list = payload.data.filter((period) => !isSmokePeriod(period));
        const currentId = payload.current?.id ? String(payload.current.id) : '';
        const accessibleCurrentId = currentId && list.some((period) => String(period.id) === currentId)
          ? currentId
          : '';
        const previousCurrentId = currentPeriodIdRef.current;
        const newlyOpenedPeriod = Boolean(previousCurrentId && accessibleCurrentId && previousCurrentId !== accessibleCurrentId);
        currentPeriodIdRef.current = accessibleCurrentId;
        setPeriods(list);
        // Follow a newly opened academic year across already active user sessions.
        // Otherwise preserve an explicit historical selection.
        setSelectedPeriodId((current) => {
          const requestedId = options.selectPeriodId ? String(options.selectPeriodId) : '';
          if (requestedId && list.some((period) => String(period.id) === requestedId)) {
            return requestedId;
          }

          if ((options.selectCurrent || newlyOpenedPeriod) && accessibleCurrentId) return accessibleCurrentId;
          if (current && list.some((period) => String(period.id) === current)) return current;
          return accessibleCurrentId || (list[0]?.id ? String(list[0].id) : '');
        });
      } else {
        setPeriods([]);
        setError(payload.message || 'Failed to load evaluation periods.');
      }
    } catch (err) {
      setError(err.message);
      setPeriods([]);
    } finally {
      if (!options.silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchPeriods();
  }, [fetchPeriods]);

  useEffect(() => {
    try {
      if (selectedPeriodId) window.sessionStorage.setItem('pmas-selected-evaluation-period-id', String(selectedPeriodId));
      else window.sessionStorage.removeItem('pmas-selected-evaluation-period-id');
    } catch {
      // Storage can be unavailable in privacy-restricted browser contexts.
    }
  }, [selectedPeriodId]);

  useEffect(() => {
    const refreshPeriodStatus = () => fetchPeriods({ silent: true });
    const intervalId = window.setInterval(refreshPeriodStatus, 15000);
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') refreshPeriodStatus();
    };

    window.addEventListener('focus', refreshPeriodStatus);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => {
      window.clearInterval(intervalId);
      window.removeEventListener('focus', refreshPeriodStatus);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
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
