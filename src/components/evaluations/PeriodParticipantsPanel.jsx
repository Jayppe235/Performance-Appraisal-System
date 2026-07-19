import { useCallback, useEffect, useMemo, useState } from 'react';
import { CheckCircle2, Loader2, Search, UserMinus, UserPlus, X } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import { addToast } from '../common/Toast.jsx';
import { confirmProceed } from '../common/ConfirmationModal.jsx';

const reasons = [
  ['resignation', 'Resignation'], ['retirement', 'Retirement'], ['leave', 'Leave'],
  ['transfer', 'Transfer'], ['role_change', 'Role Change'], ['other', 'Other'],
];

export default function PeriodParticipantsPanel() {
  const { selectedPeriodId, selectedPeriod } = useEvaluationPeriod();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const [departmentFilter, setDepartmentFilter] = useState('all');
  const [roleFilter, setRoleFilter] = useState('all');
  const [target, setTarget] = useState(null);
  const [reason, setReason] = useState('leave');
  const [notes, setNotes] = useState('');

  const load = useCallback(async () => {
    if (!selectedPeriodId) { setRows([]); return; }
    setLoading(true);
    try {
      const data = await apiFetch(`/api/evaluation-period-participation.php?evaluation_period_id=${encodeURIComponent(selectedPeriodId)}`);
      if (!data.ok) throw new Error(data.message || 'Unable to load period participants.');
      setRows(data.participants || []);
    } catch (error) {
      addToast({ type: 'error', text: error.message });
    } finally { setLoading(false); }
  }, [selectedPeriodId]);

  useEffect(() => { load(); }, [load]);

  const departments = useMemo(() => Array.from(new Set(rows.map((row) => String(row.department || '').trim()).filter(Boolean))).sort((a, b) => a.localeCompare(b)), [rows]);
  const roles = useMemo(() => Array.from(new Set(rows.map((row) => String(row.role || '').trim()).filter(Boolean))).sort((a, b) => a.localeCompare(b)), [rows]);

  const filtered = useMemo(() => rows.filter((row) => {
    const matchesFilter = filter === 'all' || row.participation_status === filter;
    const matchesDepartment = departmentFilter === 'all' || String(row.department || '') === departmentFilter;
    const matchesRole = roleFilter === 'all' || String(row.role || '') === roleFilter;
    const haystack = `${row.full_name} ${row.user_code} ${row.department} ${row.program} ${row.role}`.toLowerCase();
    return matchesFilter && matchesDepartment && matchesRole && haystack.includes(search.trim().toLowerCase());
  }), [rows, search, filter, departmentFilter, roleFilter]);

  const exclude = async (event) => {
    event.preventDefault();
    if (reason === 'other' && !notes.trim()) { addToast({ type: 'error', text: 'Add notes for the Other reason.' }); return; }
    setSaving(true);
    try {
      const data = await apiFetch('/api/evaluation-period-participation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'exclude', evaluation_period_id: Number(selectedPeriodId), user_id: Number(target.user_id), reason, notes: notes.trim() }) });
      if (!data.ok) throw new Error(data.message || 'Unable to exclude faculty member.');
      addToast({ type: 'success', text: data.message });
      setTarget(null); setNotes(''); setReason('leave'); await load();
    } catch (error) { addToast({ type: 'error', text: error.message }); }
    finally { setSaving(false); }
  };

  const include = async (row) => {
    const confirmed = await confirmProceed({ message: `Re-include ${row.full_name} in ${selectedPeriod?.period_name || 'this period'}? Safe non-submitted requirements will become active again.`, confirmText: 'Re-include Faculty' });
    if (!confirmed) return;
    setSaving(true);
    try {
      const data = await apiFetch('/api/evaluation-period-participation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'include', evaluation_period_id: Number(selectedPeriodId), user_id: Number(row.user_id) }) });
      if (!data.ok) throw new Error(data.message || 'Unable to re-include faculty member.');
      addToast({ type: 'success', text: data.message }); await load();
    } catch (error) { addToast({ type: 'error', text: error.message }); }
    finally { setSaving(false); }
  };

  return <section className="period-participants-panel">
    <header><div><p className="eyebrow">Period Participation</p><h2>Faculty Included in This Evaluation Period</h2><p>Exclude a faculty member from the selected period without disabling their account or removing historical records.</p></div><div className="period-participant-period"><span>Selected Period</span><strong>{selectedPeriod?.period_name || 'Select a period'}</strong></div></header>
    <div className="period-participant-tools"><label className="period-participant-search"><Search size={17}/><input value={search} onChange={(e)=>setSearch(e.target.value)} placeholder="Search faculty, code, department, or program"/></label><label className="period-participant-filter"><span>Department</span><select value={departmentFilter} onChange={(e)=>setDepartmentFilter(e.target.value)}><option value="all">All departments</option>{departments.map((department)=><option key={department} value={department}>{department}</option>)}</select></label><label className="period-participant-filter"><span>Role</span><select value={roleFilter} onChange={(e)=>setRoleFilter(e.target.value)}><option value="all">All roles</option>{roles.map((role)=><option key={role} value={role}>{role==='program_head'?'Program Head':role==='teacher'?'Faculty':role.replaceAll('_',' ')}</option>)}</select></label><label className="period-participant-filter"><span>Participation</span><select value={filter} onChange={(e)=>setFilter(e.target.value)}><option value="all">All participation</option><option value="included">Included</option><option value="excluded">Not included</option></select></label><button type="button" onClick={load} disabled={loading}>{loading?<Loader2 size={16} className="animate-spin"/>:<CheckCircle2 size={16}/>} Refresh</button></div>
    {!selectedPeriodId ? <div className="period-participant-empty">Select an evaluation period to manage participation.</div> : loading && !rows.length ? <div className="period-participant-empty"><Loader2 className="animate-spin"/> Loading participants...</div> : <div className="period-participant-table-wrap"><table><thead><tr><th>Faculty</th><th>Assignment</th><th>Account</th><th>Period Status</th><th>Activity</th><th>Action</th></tr></thead><tbody>{filtered.map((row)=><tr key={`${row.user_id}-${row.faculty_id}`} className={row.participation_status==='excluded'?'is-excluded':''}><td><strong>{row.full_name}</strong><small>Code {row.user_code}</small></td><td><span>{row.role==='program_head'?'Program Head':'Faculty'}</span><small>{[row.department,row.program].filter(Boolean).join(' • ')||'Unassigned'}</small></td><td><span className={`period-status account-${Number(row.is_active)===1?'active':'inactive'}`}>{Number(row.is_active)===1?'Active':'Inactive'}</span></td><td><span className={`period-status ${row.participation_status}`}>{row.participation_status==='excluded'?'Not Included in This Period':'Included'}</span>{row.exclusion_reason&&<small>{String(row.exclusion_reason).replace('_',' ')}{row.notes?` — ${row.notes}`:''}</small>}</td><td><span>{row.submitted_count} submitted</span><small>{row.open_count} open • {row.not_required_count} not required</small></td><td>{row.participation_status==='excluded'?<button type="button" className="include" disabled={saving} onClick={()=>include(row)}><UserPlus size={15}/> Re-include</button>:<button type="button" className="exclude" disabled={saving} onClick={()=>setTarget(row)}><UserMinus size={15}/> Exclude</button>}</td></tr>)}{!filtered.length&&<tr><td colSpan="6" className="period-participant-empty">No faculty match the current filters.</td></tr>}</tbody></table></div>}
    {target&&<div className="period-participant-modal" role="presentation" onMouseDown={(e)=>e.target===e.currentTarget&&!saving&&setTarget(null)}><form onSubmit={exclude} role="dialog" aria-modal="true" aria-label="Exclude faculty from period"><button type="button" className="close" onClick={()=>setTarget(null)} aria-label="Close"><X size={18}/></button><span className="icon"><UserMinus size={23}/></span><h3>Not Included in This Period</h3><p><strong>{target.full_name}</strong> will be removed from active assignments, monitoring, calculations, and reports for <strong>{selectedPeriod?.period_name}</strong>. Their account and historical records remain stored.</p><label>Reason<select value={reason} onChange={(e)=>setReason(e.target.value)}>{reasons.map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label><label>Notes {reason==='other'?'(required)':'(optional)'}<textarea value={notes} onChange={(e)=>setNotes(e.target.value)} maxLength="1000" rows="4" placeholder="Add administrative context for this period exclusion."/></label><footer><button type="button" onClick={()=>setTarget(null)} disabled={saving}>Cancel</button><button type="submit" disabled={saving}>{saving?<Loader2 size={16} className="animate-spin"/>:<UserMinus size={16}/>} Confirm Exclusion</button></footer></form></div>}
  </section>;
}
