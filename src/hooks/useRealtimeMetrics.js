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
export default function useRealtimeMetrics(role = 'admin', options = {}, intervalMs = 30000) {
  const [data, setData] = useState({ metrics: [], actionCenter: null, overview: null, programs: [], timestamp: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const intervalRef = useRef(null);
  const pendingRef = useRef(false);
  const mountedRef = useRef(true);
  const requestIdRef = useRef(0);
  const isQuickTunnel = typeof window !== 'undefined'
    && window.location.hostname.endsWith('.trycloudflare.com');

  const buildQuery = useCallback(() => {
    const params = new URLSearchParams({ role });
    if (options.department) params.set('department', options.department);
    if (options.program) params.set('program', options.program);
    if (options.userId) params.set('user_id', options.userId);
    if (options.periodId) params.set('period_id', options.periodId);
    if (options.comparisonPeriodId) params.set('comparison_period_id', options.comparisonPeriodId);
    return params;
  }, [role, options.department, options.program, options.userId, options.periodId, options.comparisonPeriodId]);

  const buildUrl = useCallback(() => {
    const params = buildQuery();
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
      overview: payload.data.overview || null,
      programs: payload.data.programs || [],
      timestamp: payload.timestamp || Math.floor(Date.now() / 1000),
    });
    setError(null);
    setLoading(false);
  }, []);

  const fetchMetrics = useCallback(async () => {
    if (pendingRef.current || document.hidden) return;
    pendingRef.current = true;
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
    } finally {
      pendingRef.current = false;
    }
  }, [applyPayload, buildUrl]);

  useEffect(() => {
    mountedRef.current = true;
    setLoading(true);

    // Immediate first fetch
    fetchMetrics();

    const startPolling = () => {
      if (!intervalRef.current) {
        // Account-less Cloudflare Quick Tunnels do not support SSE. A slower
        // polling interval keeps public dashboards current without repeatedly
        // opening streams or overloading MariaDB when several users are online.
        const pollingInterval = isQuickTunnel ? Math.max(intervalMs, 15000) : intervalMs;
        intervalRef.current = window.setInterval(fetchMetrics, pollingInterval);
      }
    };

    const stopPolling = () => {
      if (intervalRef.current) {
        window.clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };

    // Dashboard aggregation is database-intensive. Controlled polling avoids
    // the SSE endpoint recalculating the full payload every two seconds.
    startPolling();

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
      window.removeEventListener('focus', fetchMetrics);
      window.removeEventListener(LIVE_DATA_CHANGED_EVENT, fetchMetrics);
      window.removeEventListener('storage', refreshOnStorage);
      document.removeEventListener('visibilitychange', refreshWhenVisible);
    };
  }, [fetchMetrics, intervalMs, isQuickTunnel]);

  return {
    metrics: data.metrics,
    actionCenter: data.actionCenter,
    overview: data.overview,
    programs: data.programs,
    loading,
    error,
    timestamp: data.timestamp,
    refresh: fetchMetrics,
  };
}
