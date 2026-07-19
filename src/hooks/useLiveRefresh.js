import { useCallback, useEffect, useRef, useState } from 'react';

export const LIVE_DATA_CHANGED_EVENT = 'appraisia-live-data-changed';
export const LIVE_DATA_STORAGE_KEY = 'appraisia-live-data-version';

export function notifyLiveDataChanged(detail = {}) {
  const payload = {
    ...detail,
    at: Date.now(),
  };

  if (typeof window === 'undefined') return;

  window.dispatchEvent(new CustomEvent(LIVE_DATA_CHANGED_EVENT, { detail: payload }));
  try {
    window.localStorage.setItem(LIVE_DATA_STORAGE_KEY, JSON.stringify(payload));
  } catch (_) {
    // Storage can be unavailable in private browsing; local event above still works.
  }
}

export default function useLiveRefresh(refreshFn, deps = [], options = {}) {
  const {
    intervalMs = 7000,
    enabled = true,
    immediate = true,
    eventNames = [LIVE_DATA_CHANGED_EVENT],
  } = options;

  const refreshRef = useRef(refreshFn);
  const mountedRef = useRef(false);
  const requestIdRef = useRef(0);
  const [refreshing, setRefreshing] = useState(false);
  const [lastUpdated, setLastUpdated] = useState(null);

  useEffect(() => {
    refreshRef.current = refreshFn;
  }, [refreshFn]);

  const refresh = useCallback(async (background = true) => {
    if (!enabled || typeof refreshRef.current !== 'function') return;
    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;
    if (background) setRefreshing(true);
    try {
      await refreshRef.current(background);
      if (mountedRef.current && requestId === requestIdRef.current) {
        setLastUpdated(Date.now());
      }
    } finally {
      if (mountedRef.current && requestId === requestIdRef.current) {
        setRefreshing(false);
      }
    }
  }, [enabled]);

  useEffect(() => {
    if (!enabled) return undefined;
    mountedRef.current = true;

    if (immediate) {
      refresh(false);
    }

    const tick = () => {
      if (!document.hidden) refresh(true);
    };
    const intervalId = intervalMs > 0 ? window.setInterval(tick, intervalMs) : null;
    const handleFocus = () => refresh(true);
    const handleVisibility = () => {
      if (!document.hidden) refresh(true);
    };
    const handleStorage = (event) => {
      if (event.key === LIVE_DATA_STORAGE_KEY) refresh(true);
    };
    const handleLiveEvent = () => refresh(true);

    window.addEventListener('focus', handleFocus);
    window.addEventListener('storage', handleStorage);
    document.addEventListener('visibilitychange', handleVisibility);
    eventNames.forEach((eventName) => window.addEventListener(eventName, handleLiveEvent));

    return () => {
      mountedRef.current = false;
      if (intervalId) window.clearInterval(intervalId);
      window.removeEventListener('focus', handleFocus);
      window.removeEventListener('storage', handleStorage);
      document.removeEventListener('visibilitychange', handleVisibility);
      eventNames.forEach((eventName) => window.removeEventListener(eventName, handleLiveEvent));
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, intervalMs, immediate, refresh, ...deps]);

  return { refresh, refreshing, lastUpdated };
}
