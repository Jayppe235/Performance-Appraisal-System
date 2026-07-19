import { useCallback, useEffect, useRef, useState } from 'react';
import { WifiOff } from 'lucide-react';
import { apiUrl } from '../../data/apiBase.js';

const RETRY_DELAY_MS = 5000;
const ONLINE_CHECK_INTERVAL_MS = 15000;
const REQUEST_TIMEOUT_MS = 5000;

export default function ConnectivityGate({ children }) {
  const [status, setStatus] = useState('checking');
  const timerRef = useRef(null);

  const checkConnection = useCallback(async () => {
    clearTimeout(timerRef.current);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
      const response = await fetch(apiUrl('/api/health.php'), {
        credentials: 'include',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      const payload = await response.json();
      if (!response.ok || payload?.ok !== true) throw new Error('Unavailable');
      setStatus('online');
      timerRef.current = setTimeout(checkConnection, ONLINE_CHECK_INTERVAL_MS);
    } catch {
      setStatus('offline');
      timerRef.current = setTimeout(checkConnection, RETRY_DELAY_MS);
    } finally {
      clearTimeout(timeout);
    }
  }, []);

  useEffect(() => {
    checkConnection();
    window.addEventListener('online', checkConnection);
    window.addEventListener('offline', checkConnection);
    return () => {
      clearTimeout(timerRef.current);
      window.removeEventListener('online', checkConnection);
      window.removeEventListener('offline', checkConnection);
    };
  }, [checkConnection]);

  if (status === 'online') return children;

  return (
    <main className="connectivity-gate" role="alert" aria-live="assertive">
      <section className="connectivity-card">
        <span className="connectivity-icon" aria-hidden="true"><WifiOff size={34} /></span>
        <h1>{status === 'checking' ? 'Connecting to PMAS' : 'Internet connection required'}</h1>
        <p>
          {status === 'checking'
            ? 'Checking the application server and database…'
            : 'PMAS cannot reach its server. Check your internet connection; this page will reconnect automatically.'}
        </p>
        {status === 'offline' && <button type="button" onClick={checkConnection}>Try again</button>}
      </section>
    </main>
  );
}
