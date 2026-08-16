import { useCallback, useEffect, useState } from 'react';
import { Activity, ChevronLeft, ChevronRight, RefreshCw, Search, ShieldCheck } from 'lucide-react';
import apiFetch from '../../data/api.js';

function formatTimestamp(value) {
  if (!value) return 'Unknown time';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

export default function AuditLogsPage({ embedded = false, onTotalChange }) {
  const [rows, setRows] = useState([]);
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ page: 1, pages: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadLogs = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ page: String(page), page_size: '25' });
      if (query) params.set('search', query);
      const payload = await apiFetch(`/api/audit-logs.php?${params}`);
      setRows(Array.isArray(payload.data) ? payload.data : []);
      setPagination(payload.pagination || { page: 1, pages: 1, total: 0 });
    } catch (loadError) {
      setError(loadError.message || 'Unable to load audit logs.');
    } finally {
      setLoading(false);
    }
  }, [page, query]);

  useEffect(() => { loadLogs(); }, [loadLogs]);
  useEffect(() => { onTotalChange?.(Number(pagination.total) || 0); }, [onTotalChange, pagination.total]);

  function submitSearch(event) {
    event.preventDefault();
    setPage(1);
    setQuery(search.trim());
  }

  return (
    <section className={`audit-page module-wide page-enter ${embedded ? 'audit-page-embedded' : ''}`}>
      <header className="audit-hero">
        <div className="audit-hero-icon"><ShieldCheck size={24} /></div>
        <div><p className="eyebrow">System Governance</p><h2>Audit Logs</h2><p>Review administrative actions, account events, and important system activity.</p></div>
        <div className="audit-total"><strong>{pagination.total}</strong><span>recorded events</span></div>
      </header>
      <div className="audit-toolbar">
        <form onSubmit={submitSearch}><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search user, email, or activity…" /><button type="submit">Search</button></form>
        <button type="button" onClick={loadLogs} disabled={loading}><RefreshCw size={16} className={loading ? 'animate-spin' : ''} /> Refresh</button>
      </div>
      <div className="audit-table-wrap">
        {error && <div className="audit-empty error">{error}</div>}
        {!error && loading && <div className="audit-empty">Loading audit activity…</div>}
        {!error && !loading && rows.length === 0 && <div className="audit-empty"><Activity size={22} /> No audit activity matches this view.</div>}
        {!error && !loading && rows.length > 0 && <table><thead><tr><th>Date & Time</th><th>Account</th><th>Role</th><th>Activity</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td><time>{formatTimestamp(row.createdAt)}</time></td><td><strong>{row.userName}</strong><small>{row.email || 'System-generated event'}</small></td><td><span className="audit-role">{String(row.role).replaceAll('_', ' ')}</span></td><td>{row.description}</td></tr>)}</tbody></table>}
      </div>
      <footer className="audit-pagination"><span>Page {pagination.page} of {pagination.pages}</span><div><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}><ChevronLeft size={16} /> Previous</button><button type="button" disabled={page >= pagination.pages || loading} onClick={() => setPage((value) => value + 1)}>Next <ChevronRight size={16} /></button></div></footer>
    </section>
  );
}
