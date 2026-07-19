import { useCallback, useEffect, useRef, useState } from 'react';
import { apiUrl } from '../data/apiBase.js';
import { LIVE_DATA_CHANGED_EVENT, LIVE_DATA_STORAGE_KEY } from './useLiveRefresh.js';

/**
 * Custom hook that fetches real-time dashboard metrics from the backend API.
 * Polls every 5 seconds by default.
 *
 * @param {string} role - User role: 'admin', 'dean', 'program_head', 'faculty'
 * @param {object} options - Optional filters: { department, program, userId, periodId }
 * @param {number} intervalMs - Polling interval in milliseconds (default 5000)
 * @returns {{ metrics: Array, actionCenter: Array|null, loading: boolean, error: string|null, timestamp: number|null, refresh: Function }}
 */
export default function useRealtimeMetrics(role = 'admin', options = {}, intervalMs = 5000) {
  const [data, setData] = useState({ metrics: [], actionCenter: null, timestamp: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const intervalRef = useRef(null);
  const eventSourceRef = useRef(null);
  const mountedRef = useRef(true);
  const requestIdRef = useRef(0);

  const buildQuery = useCallback(() => {
    const params = new URLSearchParams({ role });
    if (options.department) params.set('department', options.department);
    if (options.program) params.set('program', options.program);
    if (options.userId) params.set('user_id', options.userId);
    if (options.periodId) params.set('period_id', options.periodId);
    return params;
  }, [role, options.department, options.program, options.userId, options.periodId]);

  const buildUrl = useCallback(() => {
    const params = buildQuery();
    params.set('_', String(Date.now()));
    return `/api/dashboard.php?${params.toString()}`;
  }, [buildQuery]);

  const buildStreamUrl = useCallback(() => {
    const params = buildQuery();
    return apiUrl(`/api/dashboard-stream.php?${params.toString()}`);
  }, [buildQuery]);

  const applyPayload = useCallback((payload) => {
    if (!payload.ok) throw new Error(payload.error || 'API error');

    setData({
      metrics: payload.data.metrics || [],
      actionCenter: payload.data.actionCenter || null,
      timestamp: payload.timestamp || Math.floor(Date.now() / 1000),
    });
    setError(null);
    setLoading(false);
  }, []);

  const fetchMetrics = useCallback(async () => {
    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;

    try {
      const response = await fetch(apiUrl(buildUrl()), {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' },
      });

      if (!response.ok) {
        // Check for HTML redirect (session expired)
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('text/html')) {
          throw new Error('Session expired');
        }
        throw new Error(`HTTP ${response.status}`);
      }

      const text = await response.text();
      if (!text.trim()) {
        throw new Error('Dashboard API returned an empty response.');
      }

      let payload;
      try {
        payload = JSON.parse(text);
      } catch {
        throw new Error('Dashboard API returned invalid JSON.');
      }
      if (mountedRef.current && requestId === requestIdRef.current) {
        applyPayload(payload);
      }
    } catch (err) {
      if (mountedRef.current && requestId === requestIdRef.current) {
        // Keep last successful data; just update error state
        setError(err.message);
        setLoading(false);
      }
    }
  }, [applyPayload, buildUrl]);

  useEffect(() => {
    mountedRef.current = true;
    setLoading(true);

    // Immediate first fetch
    fetchMetrics();

    const startPolling = () => {
      if (!intervalRef.current) {
        intervalRef.current = window.setInterval(fetchMetrics, intervalMs);
      }
    };

    const stopPolling = () => {
      if (intervalRef.current) {
        window.clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };

    if ('EventSource' in window) {
      const source = new EventSource(buildStreamUrl(), { withCredentials: true });
      eventSourceRef.current = source;

      source.addEventListener('metrics', (event) => {
        try {
          if (!mountedRef.current) return;
          applyPayload(JSON.parse(event.data));
          startPolling();
        } catch (err) {
          if (mountedRef.current) {
            setError(err.message || 'Dashboard stream returned invalid data.');
            startPolling();
          }
        }
      });

      source.addEventListener('error', () => {
        if (mountedRef.current) {
          setError('Live stream reconnecting...');
          startPolling();
        }
      });
    } else {
      startPolling();
    }

    const refreshWhenVisible = () => {
      if (!document.hidden) {
        fetchMetrics();
      }
    };
    const refreshOnStorage = (event) => {
      if (event.key === LIVE_DATA_STORAGE_KEY) fetchMetrics();
    };
    window.addEventListener('focus', fetchMetrics);
    window.addEventListener(LIVE_DATA_CHANGED_EVENT, fetchMetrics);
    window.addEventListener('storage', refreshOnStorage);
    document.addEventListener('visibilitychange', refreshWhenVisible);

    return () => {
      mountedRef.current = false;
      stopPolling();
      if (eventSourceRef.current) {
        eventSourceRef.current.close();
        eventSourceRef.current = null;
      }
      window.removeEventListener('focus', fetchMetrics);
      window.removeEventListener(LIVE_DATA_CHANGED_EVENT, fetchMetrics);
      window.removeEventListener('storage', refreshOnStorage);
      document.removeEventListener('visibilitychange', refreshWhenVisible);
    };
  }, [applyPayload, buildStreamUrl, fetchMetrics, intervalMs]);

  return {
    metrics: data.metrics,
    actionCenter: data.actionCenter,
    loading,
    error,
    timestamp: data.timestamp,
    refresh: fetchMetrics,
  };
}
