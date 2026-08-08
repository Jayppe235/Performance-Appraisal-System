/**
 * Shared fetch wrapper for API calls.
 *
 * - Sends credentials: 'include' by default
 * - Automatically parses JSON responses
 * - Detects when the PHP API redirects to login (unauthorized) by checking
 *   whether the response is HTML instead of JSON — and redirects the browser
 *   to /login so the user sees the login page instead of silent failures.
 * - Also handles 401/403 HTTP status codes.
 *
 * GUARD: redirectToLogin() will only fire ONCE. If we've already redirected,
 * subsequent calls silently throw instead of reloading the page (prevents
 * infinite redirect loops when multiple API calls fail at once).
 *
 * Usage:
 *   import apiFetch from '../../data/api.js';
 *   const data = await apiFetch('/api/evaluation-assignments.php?action=periods');
 */
import { apiUrl } from './apiBase.js';
import { notifyLiveDataChanged } from '../hooks/useLiveRefresh.js';

let _redirectingToLogin = false;

function isHtmlResponse(response) {
  const contentType = response.headers.get('content-type') || '';
  return contentType.includes('text/html');
}

function redirectToLogin() {
  // GUARD #1: Only redirect ONCE. Prevents infinite page-reload loops when
  // multiple API calls fail simultaneously (dashboard-mount cascade).
  if (_redirectingToLogin) {
    throw new Error('Session expired. Redirecting to login.');
  }
  _redirectingToLogin = true;

  // GUARD #2: Already on the login page? No need to redirect again.
  if (window.location.pathname === '/login') {
    _redirectingToLogin = false;
    throw new Error('Session expired. Redirecting to login.');
  }

  // Clear any stale client-side session data
  try {
    localStorage.removeItem('dipascaf-react-session');
    localStorage.removeItem('dipascaf-session');
  } catch (_) {
    // localStorage may not be available
  }

  window.location.href = '/login';
}

export default async function apiFetch(url, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const response = await fetch(apiUrl(url), {
    credentials: 'include',
    cache: options.cache || 'no-store',
    headers: { Accept: 'application/json' },
    ...options,
    // Merge headers properly — don't let user override Accept unless they explicitly do
    headers: {
      Accept: 'application/json',
      ...(options.headers || {}),
    },
  });

  // 401 / 403 — definitely unauthorized
  if (response.status === 401 || response.status === 403) {
    redirectToLogin();
    // Throw so callers don't continue after redirect
    throw new Error('Session expired. Redirecting to login.');
  }

  // If the response is HTML (not JSON), the PHP backend returned a non-JSON
  // response. This could be a PHP warning/error, not necessarily session expiry.
  // Log and throw without redirecting — the 401/403 check above is sufficient
  // for detecting real session expiry.
  if (isHtmlResponse(response)) {
    const text = await response.text();
    console.warn('[apiFetch] Received HTML instead of JSON from', url, '- possible PHP error');
    throw new Error('Unexpected server response (HTML). Check server logs.');
  }

  const text = await response.text();
  if (response.status === 204 || text.trim() === '') {
    throw new Error(`Empty response from ${url}.`);
  }

  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    console.warn('[apiFetch] Invalid JSON from', url, text.slice(0, 300));
    throw new Error(`Invalid JSON response from ${url}.`);
  }

  // If the API returned ok: false — throw the error for the caller to handle.
  // NOTE: We do NOT check the error message text here. Previously the code
  // redirected to login if the message contained "login" / "unauthorized" /
  // "session", but this was dangerously broad (any API with a vaguely
  // login-sounding error message would nuke the session). The HTML and
  // 401/403 checks above are sufficient for detecting real session expiry.
  if (!response.ok || payload.ok === false) {
    const errorMessage = payload.message || payload.error || 'Request failed';
    const requestError = new Error(errorMessage);
    requestError.status = response.status;
    requestError.code = payload.code || '';
    requestError.payload = payload;
    throw requestError;
  }

  if (method !== 'GET' && method !== 'HEAD') {
    notifyLiveDataChanged({ source: 'api', method, url });
  }

  return payload;
}
