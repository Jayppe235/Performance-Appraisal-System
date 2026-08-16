import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertTriangle,
  Archive,
  CheckCircle2,
  RefreshCw,
  Edit3,
  Eye,
  Lock,
  Unlock,
  Loader2,
  Search,
  ShieldCheck,
  Trash2,
  UserRoundCheck,
} from 'lucide-react';
import { addToast } from '../../components/common/Toast.jsx';
import { confirmDeleteData, confirmProceed, confirmSaveChanges } from '../../components/common/ConfirmationModal.jsx';
import apiFetch from '../../data/api.js';
import { assetUrl } from '../../data/apiBase.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import useLiveRefresh from '../../hooks/useLiveRefresh.js';

function statusTone(status) {
  if (status === 'completed') return 'success';
  if (status === 'overdue') return 'danger';
  return 'warning';
}

function StatusBadge({ status }) {
  const tone = statusTone(status);
  return <span className={`peer-status-badge ${tone}`}>{status === 'completed' ? 'Completed' : status === 'overdue' ? 'Overdue' : 'Pending'}</span>;
}

function roleDisplay(position = '') {
  const normalized = position.toLowerCase();
  if (normalized.includes('program head')) return 'Program Head';
  if (normalized.includes('dean')) return 'Dean';
  return position || 'Faculty';
}

function assignmentScope(row) {
  if (row.evaluatorRole === 'dean') return 'Dean-to-Dean';
  return row.department || row.evaluatorDepartment || 'Unassigned Department';
}

function assignedPersonMeta(row) {
  const position = roleDisplay(row.position);
  if (position === 'Program Head' || position === 'Dean') return position;
  return row.program ? `${position} / ${row.program}` : position;
}

function sameDepartment(left, right) {
  if (!left || !right) return false;
  if (left.departmentId && right.departmentId && Number(left.departmentId) === Number(right.departmentId)) return true;
  const leftDepartment = String(left.department || '').trim().toLowerCase();
  const rightDepartment = String(right.department || '').trim().toLowerCase();
  return leftDepartment !== '' && leftDepartment === rightDepartment;
}

function normalizeDepartmentText(value) {
  return String(value || '').trim().toLowerCase();
}

function departmentAliases(department) {
  if (!department) return [];
  return [
    department.code,
    department.name,
    department.label,
  ].map(normalizeDepartmentText).filter(Boolean);
}

function rowDepartments(row) {
  return [
    row.department,
    row.evaluatorDepartment,
  ].map(normalizeDepartmentText).filter(Boolean);
}

function rowMatchesDepartment(row, department) {
  if (!department) return true;
  const selectedDepartmentId = Number(department.id || 0);
  const rowDepartmentId = Number(row.departmentId || 0);
  if (selectedDepartmentId > 0 && rowDepartmentId > 0) {
    return selectedDepartmentId === rowDepartmentId;
  }

  const aliases = departmentAliases(department);
  const rowValues = rowDepartments(row);
  return aliases.some((alias) => rowValues.includes(alias));
}

function userHasNoDepartment(user) {
  const departmentId = Number(user?.departmentId || 0);
  const department = normalizeDepartmentText(user?.department);
  return departmentId <= 0 && (!department || department === 'general' || department.includes('unassigned'));
}

function roleLabel(role) {
  if (role === 'teacher' || role === 'faculty') return 'Faculty';
  if (role === 'program_head') return 'Program Head';
  if (role === 'dean') return 'Dean';
  return role || 'Role';
}

const emptyForm = {
  id: '',
  evaluation_period_id: '',
  department_id: '',
  evaluator_role: 'teacher',
  evaluator_id: '',
  evaluatee_id: '',
  status: 'pending',
};

export default function PeerAssignmentsPanel({
  admin = false,
  title = 'Peer-to-Peer Assignment Monitor',
  departmentId = null,
  compact = false,
  excludeDeans = false,
  strictDepartmentScope = false,
  showLifecycleControls = true,
  periodId: forcedPeriodId = null,
}) {
  const {
    selectedPeriodId: globalSelectedPeriodId,
    setSelectedPeriodId: setGlobalSelectedPeriodId,
  } = useEvaluationPeriod();
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState({ total: 0, completed: 0, pending: 0, overdue: 0, completionRate: 0 });
  const [invalids, setInvalids] = useState([]);
  const [setup, setSetup] = useState({ canManual: false, periods: [], departments: [], users: [] });
  const [peerLifecycle, setPeerLifecycle] = useState({ status: 'unlocked', isLocked: false, total: 0, completed: 0, pending: 0, canLock: false, canUnlock: false });
  const [form, setForm] = useState(emptyForm);
  const [filterDepartment, setFilterDepartment] = useState('');
  const [filterProgram, setFilterProgram] = useState('');
  const [filterRole, setFilterRole] = useState('');
  const [search, setSearch] = useState('');
  const [viewing, setViewing] = useState(null);
  const [loading, setLoading] = useState(true);
  const [departmentChanging, setDepartmentChanging] = useState(false);
  const [monitorPage, setMonitorPage] = useState(1);
  const [monitorPageSize, setMonitorPageSize] = useState(admin ? 5 : compact ? 6 : 10);
  const [busy, setBusy] = useState('');
  const requestSequenceRef = useRef(0);
  // The shared period selector is the source of truth. The local form mirrors
  // it for lifecycle actions but must never pin the monitor to an older year.
  const activePeriodId = forcedPeriodId !== null
    ? String(forcedPeriodId || '')
    : (globalSelectedPeriodId || form.evaluation_period_id || '');

  const loadRows = useCallback(async (background = false) => {
    // A parent with an explicit period must never fall back to the API's
    // default/latest period while context is still loading.
    if (forcedPeriodId !== null && !activePeriodId) {
      setRows([]);
      setSummary({ total: 0, completed: 0, pending: 0, overdue: 0, completionRate: 0 });
      if (!background) setLoading(false);
      return null;
    }
    const requestSequence = ++requestSequenceRef.current;
    if (!background) setLoading(true);
    try {
      const params = new URLSearchParams();
      if (activePeriodId) params.set('period_id', activePeriodId);
      if (departmentId) params.set('department_id', departmentId);
      if (admin) params.set('setup', '1');
      if (excludeDeans) params.set('exclude_deans', '1');
      if (strictDepartmentScope) params.set('strict_department', '1');
      const query = params.toString() ? `?${params.toString()}` : '';
      const payload = await apiFetch(`/api/peer-evaluation-assignments.php${query}`);
      if (requestSequence !== requestSequenceRef.current) return null;
      const responseRows = Array.isArray(payload.data) ? payload.data : [];
      const periodRows = activePeriodId
        ? responseRows.filter((row) => String(row.periodId) === String(activePeriodId))
        : responseRows;
      setRows(periodRows);
      const completedRows = periodRows.filter((row) => row.status === 'completed').length;
      setSummary({
        total: periodRows.length,
        completed: completedRows,
        pending: periodRows.filter((row) => row.status === 'pending').length,
        overdue: periodRows.filter((row) => row.status === 'overdue').length,
        completionRate: periodRows.length ? Math.round((completedRows / periodRows.length) * 100) : 0,
      });
      setInvalids(Array.isArray(payload.invalids) ? payload.invalids : []);
      setPeerLifecycle(payload.peerLifecycle || { status: 'unlocked', isLocked: false, total: 0, completed: 0, pending: 0, canLock: false, canUnlock: false });
      if (payload.setup) {
        setSetup({
          canManual: !!payload.setup.canManual,
          periods: Array.isArray(payload.setup.periods) ? payload.setup.periods : [],
          departments: Array.isArray(payload.setup.departments) ? payload.setup.departments : [],
          users: Array.isArray(payload.setup.users) ? payload.setup.users : [],
        });
        setForm((current) => ({
          ...current,
          evaluation_period_id: globalSelectedPeriodId || current.evaluation_period_id || String(payload.setup.periods?.[0]?.id || ''),
          department_id: current.department_id || String(payload.setup.departments?.[0]?.id || ''),
          evaluator_role: current.evaluator_role || 'teacher',
        }));
      }
    } catch (error) {
      if (requestSequence !== requestSequenceRef.current) return null;
      addToast({ type: 'error', text: error.message || 'Unable to load peer assignments.' });
    } finally {
      if (!background && requestSequence === requestSequenceRef.current) setLoading(false);
    }
  }, [activePeriodId, admin, departmentId, excludeDeans, forcedPeriodId, globalSelectedPeriodId, strictDepartmentScope]);

  const { refreshing: liveRefreshing } = useLiveRefresh(loadRows, [activePeriodId, admin, departmentId, excludeDeans, strictDepartmentScope], {
    intervalMs: 6000,
  });

  useEffect(() => {
    if (!globalSelectedPeriodId || String(form.evaluation_period_id) === String(globalSelectedPeriodId)) return;
    setForm((current) => ({ ...current, evaluation_period_id: String(globalSelectedPeriodId) }));
    setMonitorPage(1);
    setViewing(null);
  }, [form.evaluation_period_id, globalSelectedPeriodId]);

  const selectedDepartment = useMemo(
    () => {
      if (form.department_id === 'all') return { id: 'all', label: 'Apply to All Departments', special: 'all' };
      if (form.department_id === 'unassigned') return { id: 'unassigned', label: 'Not in a Department', special: 'unassigned' };
      return setup.departments.find((department) => String(department.id) === String(form.department_id));
    },
    [form.department_id, setup.departments],
  );

  const selectedEvaluator = useMemo(
    () => setup.users.find((user) => String(user.id) === String(form.evaluator_id)),
    [form.evaluator_id, setup.users],
  );

  const evaluatorOptions = useMemo(() => {
    const role = form.evaluator_role;
    return setup.users.filter((user) => {
      if (user.role !== role) return false;
      if (role === 'dean') return true;
      if (selectedDepartment?.special === 'all') return true;
      if (selectedDepartment?.special === 'unassigned') return userHasNoDepartment(user);
      return selectedDepartment && (Number(user.departmentId) === Number(selectedDepartment.id) || user.department === selectedDepartment.name || user.department === selectedDepartment.code);
    });
  }, [form.evaluator_role, selectedDepartment, setup.users]);

  const roleOptions = useMemo(() => {
    const roles = new Set(setup.users.map((user) => user.role).filter(Boolean));
    const ordered = ['teacher', 'program_head', 'dean'];
    return ordered.filter((role) => roles.has(role));
  }, [setup.users]);

  const peerOptions = useMemo(() => {
    if (!selectedEvaluator) return [];
    if (form.evaluator_role === 'dean') {
      return setup.users.filter((user) => user.role === 'dean' && Number(user.id) !== Number(selectedEvaluator.id) && !sameDepartment(user, selectedEvaluator));
    }
    if (form.evaluator_role === 'program_head') {
      return setup.users.filter((user) => user.role === 'program_head'
        && Number(user.id) !== Number(selectedEvaluator.id)
        && (selectedDepartment?.special === 'unassigned'
          ? userHasNoDepartment(user) && userHasNoDepartment(selectedEvaluator)
          : sameDepartment(user, selectedEvaluator)));
    }
    return setup.users.filter((user) => {
      const departmentMatches = selectedDepartment?.special === 'unassigned'
        ? userHasNoDepartment(user) && userHasNoDepartment(selectedEvaluator)
        : sameDepartment(user, selectedEvaluator);
      if (user.role !== 'teacher' || Number(user.id) === Number(selectedEvaluator.id) || !departmentMatches) {
        return false;
      }
      return true;
    });
  }, [form.evaluator_role, selectedDepartment, selectedEvaluator, setup.users]);

  const programOptions = useMemo(() => {
    const values = new Set();
    setup.users.forEach((user) => {
      if (user.program) values.add(user.program);
    });
    rows.forEach((row) => {
      if (row.program) values.add(row.program);
      if (row.evaluatorProgram) values.add(row.evaluatorProgram);
    });
    return [...values].sort();
  }, [rows, setup.users]);

  const activePeriod = useMemo(
    () => setup.periods.find((period) => String(period.id) === String(activePeriodId)) || null,
    [activePeriodId, setup.periods],
  );

  const selectedViewDepartment = useMemo(
    () => setup.departments.find((department) => String(department.id) === String(filterDepartment)) || null,
    [filterDepartment, setup.departments],
  );

  const filteredRows = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return rows.filter((row) => {
      const departmentMatches = !filterDepartment || (selectedViewDepartment && rowMatchesDepartment(row, selectedViewDepartment));
      if (!departmentMatches) return false;
      if (filterProgram && row.program !== filterProgram && row.evaluatorProgram !== filterProgram) return false;
      if (filterRole && row.evaluatorRole !== filterRole && row.evaluateeRole !== filterRole) return false;
      if (!needle) return true;
      return [
        row.periodName,
        row.department,
        row.evaluatorDepartment,
        setup.departments.find((department) => rowMatchesDepartment(row, department))?.code,
        setup.departments.find((department) => rowMatchesDepartment(row, department))?.name,
        setup.departments.find((department) => rowMatchesDepartment(row, department))?.label,
        row.evaluatorName,
        row.evaluatorRoleLabel,
        row.evaluatorProgram,
        row.evaluateeName,
        row.evaluateeRoleLabel,
        row.program,
        row.status,
      ].some((value) => String(value || '').toLowerCase().includes(needle));
    });
  }, [filterDepartment, filterProgram, filterRole, rows, search, selectedViewDepartment, setup.departments]);

  const deanRows = useMemo(() => filteredRows.filter((row) => row.evaluatorRole === 'dean'), [filteredRows]);
  const departmentRows = useMemo(() => filteredRows.filter((row) => row.evaluatorRole !== 'dean'), [filteredRows]);
  const monitorPages = Math.max(1, Math.ceil(filteredRows.length / monitorPageSize));
  const visibleMonitorRows = useMemo(
    () => filteredRows.slice((monitorPage - 1) * monitorPageSize, monitorPage * monitorPageSize),
    [filteredRows, monitorPage, monitorPageSize],
  );
  const visibleDeanRows = useMemo(
    () => visibleMonitorRows.filter((row) => row.evaluatorRole === 'dean'),
    [visibleMonitorRows],
  );
  const visibleDepartmentRows = useMemo(
    () => visibleMonitorRows.filter((row) => row.evaluatorRole !== 'dean'),
    [visibleMonitorRows],
  );

  const grouped = useMemo(() => {
    const byDepartment = new Map();
    visibleMonitorRows.forEach((row) => {
      const key = assignmentScope(row);
      byDepartment.set(key, [...(byDepartment.get(key) || []), row]);
    });
    return [...byDepartment.entries()];
  }, [visibleMonitorRows]);

  useEffect(() => {
    setMonitorPage(1);
  }, [activePeriodId, filterDepartment, filterProgram, filterRole, search]);

  useEffect(() => {
    setMonitorPage((current) => Math.min(current, monitorPages));
  }, [monitorPages]);

  async function runAction(action) {
    const labels = {
      generate: 'Generate peer assignments',
      regenerate: 'Regenerate peer assignments',
      validate: 'Validate peer assignments',
      lock: 'Lock peer assignments',
      unlock: 'Unlock peer assignments',
    };
    if (action === 'generate' || action === 'regenerate') {
      if (peerLifecycle.isLocked) {
        addToast({ type: 'error', text: 'Peer-to-peer evaluation is locked. Setup changes are blocked until all assigned peer evaluations are completed.' });
        return;
      }
      const confirmed = await confirmProceed({
        message: action === 'regenerate'
          ? form.evaluator_role === 'dean'
            ? 'Unlocked pending Dean-to-Dean assignments for the selected period will be refreshed across departments.'
            : 'Unlocked pending peer-to-peer assignments for the selected period and department will be refreshed.'
          : form.evaluator_role === 'dean'
            ? 'Dean-to-Dean assignments will be generated across eligible Deans from different departments.'
            : 'Peer-to-peer assignments will be generated for eligible Faculty and Program Heads in the selected period and department.',
        confirmText: action === 'regenerate' ? 'Regenerate' : 'Generate',
      });
      if (!confirmed) return;
    }
    if (action === 'validate') {
      const confirmed = await confirmProceed({
        message: 'Validate all peer assignments against the finalized participant roster before period activation?',
        confirmText: 'Validate Assignments',
      });
      if (!confirmed) return;
    }
    if (action === 'lock') {
      const confirmed = await confirmProceed({
        message: 'Users will be able to access their peer evaluation assignments, and setup changes will be blocked until all assigned peer evaluations are completed.',
        confirmText: 'Lock Assignments',
      });
      if (!confirmed) return;
    }
    if (action === 'unlock') {
      const confirmed = await confirmProceed({
        message: 'Users will no longer be able to access or continue peer evaluations for this period.',
        confirmText: 'Unlock Assignments',
      });
      if (!confirmed) return;
    }
    setBusy(action);
    try {
      const payload = await apiFetch('/api/peer-evaluation-assignments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action,
          include_program_heads: true,
          peer_group: form.evaluator_role === 'dean' ? 'dean' : 'department',
          evaluation_period_id: form.evaluation_period_id || undefined,
          department_id: !['all', 'unassigned'].includes(form.department_id) ? form.department_id || undefined : undefined,
          department_scope: ['all', 'unassigned'].includes(form.department_id) ? form.department_id : undefined,
        }),
      });
      addToast({ type: 'success', text: payload.message || `${labels[action]} completed.` });
      await loadRows();
    } catch (error) {
      addToast({ type: 'error', text: error.message || `${labels[action]} failed.` });
    } finally {
      setBusy('');
    }
  }

  function updateForm(field, value) {
    if (field === 'evaluation_period_id' && value) {
      setGlobalSelectedPeriodId(value);
    }
    setForm((current) => {
      const next = { ...current, [field]: value };
      if (field === 'evaluator_role') {
        next.evaluator_id = '';
        next.evaluatee_id = '';
        next.department_id = value === 'dean'
          ? ''
          : current.department_id || String(setup.departments?.[0]?.id || '');
      }
      if (field === 'department_id') {
        next.evaluator_id = '';
        next.evaluatee_id = '';
      }
      if (field === 'evaluator_id') {
        next.evaluatee_id = '';
      }
      return next;
    });
  }

  function changeDepartmentFilter(value) {
    if (String(value) === String(filterDepartment)) return;
    setDepartmentChanging(true);
    window.setTimeout(() => {
      setFilterDepartment(value);
      window.setTimeout(() => setDepartmentChanging(false), 40);
    }, 160);
  }

  function resetForm() {
    setForm({
      ...emptyForm,
      evaluation_period_id: String(setup.periods?.[0]?.id || ''),
      department_id: String(setup.departments?.[0]?.id || ''),
      evaluator_role: roleOptions[0] || 'teacher',
    });
    setViewing(null);
  }

  async function saveManualAssignment(event) {
    event.preventDefault();
    if (peerLifecycle.isLocked) {
      addToast({ type: 'error', text: 'Peer-to-peer evaluation is locked. Setup changes are blocked until all assigned peer evaluations are completed.' });
      return;
    }
    const confirmed = await confirmSaveChanges();
    if (!confirmed) return;
    setBusy('save');
    try {
      const payload = await apiFetch('/api/peer-evaluation-assignments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: form.id ? 'update' : 'save', ...form }),
      });
      addToast({ type: 'success', text: payload.message || 'Peer assignment saved.' });
      resetForm();
      await loadRows();
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to save peer assignment.' });
    } finally {
      setBusy('');
    }
  }

  async function mutateAssignment(row, action) {
    if (peerLifecycle.isLocked) {
      addToast({ type: 'error', text: 'Peer-to-peer evaluation is locked. Setup changes are blocked until all assigned peer evaluations are completed.' });
      return;
    }
    const verb = action === 'archive' ? 'archive' : 'remove';
    const confirmed = await confirmDeleteData({
      message: `This peer assignment will be ${verb}d. This action cannot be undone.`,
      confirmText: action === 'archive' ? 'Archive' : 'Delete',
    });
    if (!confirmed) return;
    setBusy(`${action}-${row.id}`);
    try {
      const payload = await apiFetch('/api/peer-evaluation-assignments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, id: row.id }),
      });
      addToast({ type: 'success', text: payload.message || `Peer assignment ${verb}d.` });
      if (String(form.id) === String(row.id)) resetForm();
      await loadRows();
    } catch (error) {
      addToast({ type: 'error', text: error.message || `Unable to ${verb} peer assignment.` });
    } finally {
      setBusy('');
    }
  }

  function editAssignment(row) {
    setForm({
      id: String(row.id),
      evaluation_period_id: String(row.periodId || ''),
      department_id: String(row.evaluatorRole === 'dean' ? setup.users.find((user) => Number(user.id) === Number(row.evaluatorId))?.departmentId || '' : row.departmentId || ''),
      evaluator_role: row.evaluatorRole || 'teacher',
      evaluator_id: String(row.evaluatorId || ''),
      evaluatee_id: String(row.evaluateeId || ''),
      status: row.status || 'pending',
    });
    setViewing(null);
    window.requestAnimationFrame(() => {
      document.getElementById('peer-assign-evaluator-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  function renderAssignmentTable(items, label, totalCount = items.length) {
    return (
      <div className="peer-admin-table-card">
        <div className="peer-group-title">
          <strong>{label}</strong>
          <span>{totalCount} assignment(s)</span>
        </div>
        {items.length === 0 ? (
          <div className="dipascaf-empty peer-department-empty">
            <strong>No peer-to-peer assignments found</strong>
            <span>
              {selectedViewDepartment
                ? `${selectedViewDepartment.label} has no assignments for the selected evaluation period.`
                : 'No department assignments are available for the selected evaluation period.'}
            </span>
            {selectedViewDepartment && (
              <button type="button" onClick={() => changeDepartmentFilter('')}>
                View All Departments
              </button>
            )}
          </div>
        ) : (
          <div className="peer-admin-table-wrap">
            <table className="peer-admin-table">
              <thead>
                <tr>
                  <th>Evaluation Period</th>
                  <th>Evaluator Department</th>
                  <th>Evaluator Name</th>
                  <th>Evaluator Role</th>
                  <th>Evaluator Program</th>
                  <th>Peer/Evaluatee Name</th>
                  <th>Peer Role</th>
                  <th>Peer Program/Course</th>
                  <th>Peer Department</th>
                  <th>Assignment Type</th>
                  <th>Assignment Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.map((row) => (
                  <tr
                    key={row.id}
                    className={!peerLifecycle.isLocked ? 'peer-assignment-selectable-row' : ''}
                    tabIndex={!peerLifecycle.isLocked ? 0 : undefined}
                    title={!peerLifecycle.isLocked ? 'Select this peer assignment to edit its evaluator' : 'Unlock peer assignments before editing'}
                    onClick={() => {
                      if (!peerLifecycle.isLocked) editAssignment(row);
                    }}
                    onKeyDown={(event) => {
                      if (!peerLifecycle.isLocked && (event.key === 'Enter' || event.key === ' ')) {
                        event.preventDefault();
                        editAssignment(row);
                      }
                    }}
                  >
                    <td>{row.periodName || 'No period'}</td>
                    <td>{row.evaluatorDepartment || (row.evaluatorRole === 'dean' ? 'Dean-to-Dean' : row.department) || 'Unassigned'}</td>
                    <td>{row.evaluatorName}</td>
                    <td>{row.evaluatorRoleLabel || roleLabel(row.evaluatorRole)}</td>
                    <td>{row.evaluatorProgram || 'Unassigned'}</td>
                    <td>{row.evaluateeName}</td>
                    <td>{row.evaluateeRoleLabel || roleLabel(row.evaluateeRole)}</td>
                    <td>{row.program || 'Unassigned'}</td>
                    <td>{row.department || 'Unassigned'}</td>
                    <td>{row.assignmentTypeLabel || 'Peer-to-Peer'}</td>
                    <td><StatusBadge status={row.status} /></td>
                    <td>
                      <div className="peer-row-actions" onClick={(event) => event.stopPropagation()}>
                        <button type="button" onClick={() => setViewing(row)} title="View assignment"><Eye size={14} /></button>
                        <button type="button" onClick={() => editAssignment(row)} disabled={peerLifecycle.isLocked} title="Edit assignment"><Edit3 size={14} /></button>
                        <button type="button" onClick={() => mutateAssignment(row, 'remove')} disabled={peerLifecycle.isLocked || busy === `remove-${row.id}`} title="Remove assignment">
                          {busy === `remove-${row.id}` ? <Loader2 size={14} className="animate-spin" /> : <Trash2 size={14} />}
                        </button>
                        <button type="button" onClick={() => mutateAssignment(row, 'archive')} disabled={peerLifecycle.isLocked || busy === `archive-${row.id}`} title="Archive assignment">
                          {busy === `archive-${row.id}` ? <Loader2 size={14} className="animate-spin" /> : <Archive size={14} />}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  function renderMonitorPagination() {
    if (filteredRows.length === 0) return null;

    const firstVisible = (monitorPage - 1) * monitorPageSize + 1;
    const lastVisible = Math.min(monitorPage * monitorPageSize, filteredRows.length);

    return (
      <nav className="peer-monitor-pagination" aria-label="Peer assignment pages">
        <span>Showing {firstVisible}–{lastVisible} of {filteredRows.length}</span>
        <div className="peer-pagination-controls">
          <label>
            <span>Rows per page</span>
            <select
              value={monitorPageSize}
              onChange={(event) => {
                setMonitorPageSize(Number(event.target.value));
                setMonitorPage(1);
              }}
              aria-label="Rows per page"
            >
              {[5, 6, 10, 20].map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
          </label>
          <button
            type="button"
            onClick={() => setMonitorPage((page) => Math.max(1, page - 1))}
            disabled={monitorPage === 1}
          >
            Previous
          </button>
          <strong>Page {monitorPage} of {monitorPages}</strong>
          <button
            type="button"
            onClick={() => setMonitorPage((page) => Math.min(monitorPages, page + 1))}
            disabled={monitorPage === monitorPages}
          >
            Next
          </button>
        </div>
      </nav>
    );
  }

  return (
    <section className={`admin-box peer-assignment-panel module-wide page-enter ${compact ? 'compact' : ''}`}>
      <div className="peer-assignment-head">
        <div>
          <p className="eyebrow">Confidential Peer Evaluation</p>
          <h2>{title}</h2>
          <p>Official peer-to-peer evaluator assignments for the selected period and assigned scope.</p>
        </div>
        <div className="peer-assignment-score">
          <strong>{summary.completionRate}%</strong>
          <span>Completion</span>
          {liveRefreshing && <small className="live-refresh-indicator compact">Syncing...</small>}
        </div>
      </div>

      <div className="peer-assignment-stats">
        <article><UserRoundCheck size={18} /><span>Total</span><strong>{summary.total}</strong></article>
        <article><CheckCircle2 size={18} /><span>Completed</span><strong>{summary.completed}</strong></article>
        <article><ShieldCheck size={18} /><span>Pending</span><strong>{summary.pending}</strong></article>
        <article><AlertTriangle size={18} /><span>Overdue</span><strong>{summary.overdue}</strong></article>
      </div>

      {!admin && (
        <div className="peer-filter-panel compact">
          <label>
            <span>Search peer assignments</span>
            <div className="peer-search-field">
              <Search size={16} />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search evaluator, assigned peer, role, program, status..."
              />
            </div>
          </label>
          <div className="peer-filter-summary compact">
            <span>
              Showing <strong>{filteredRows.length}</strong> of <strong>{rows.length}</strong> assignment(s)
            </span>
            {search && (
              <button type="button" onClick={() => setSearch('')}>
                Clear search
              </button>
            )}
          </div>
        </div>
      )}

      {admin && showLifecycleControls && (
        <div className="peer-admin-controls">
          <button type="button" onClick={() => runAction('lock')} disabled={!!busy || peerLifecycle.isLocked || !peerLifecycle.canLock}>
            {busy === 'lock' ? <Loader2 size={16} className="animate-spin" /> : <Lock size={16} />}
            Lock & Start
          </button>
          <button type="button" onClick={() => runAction('unlock')} disabled={!!busy || !peerLifecycle.isLocked || !peerLifecycle.canUnlock}>
            {busy === 'unlock' ? <Loader2 size={16} className="animate-spin" /> : <Unlock size={16} />}
            Unlock
          </button>
          <button type="button" onClick={() => runAction('generate')} disabled={!!busy || peerLifecycle.isLocked || !form.evaluation_period_id || (form.evaluator_role !== 'dean' && !form.department_id)}>
            {busy === 'generate' ? <Loader2 size={16} className="animate-spin" /> : <UserRoundCheck size={16} />}
            Generate
          </button>
          <button type="button" onClick={() => runAction('regenerate')} disabled={!!busy || peerLifecycle.isLocked || !form.evaluation_period_id || (form.evaluator_role !== 'dean' && !form.department_id)}>
            {busy === 'regenerate' ? <Loader2 size={16} className="animate-spin" /> : <RefreshCw size={16} />}
            Regenerate
          </button>
          <button type="button" onClick={() => runAction('validate')} disabled={!!busy || peerLifecycle.isLocked || !form.evaluation_period_id || rows.length === 0 || invalids.length > 0}>
            {busy === 'validate' ? <Loader2 size={16} className="animate-spin" /> : <CheckCircle2 size={16} />}
            Validate Assignments
          </button>
          <span className={invalids.length > 0 ? 'has-issues' : 'is-clean'}>
            {invalids.length > 0 ? `${invalids.length} issue(s) detected` : 'No duplicate or invalid assignments'}
          </span>
          <span className={peerLifecycle.isLocked ? 'is-clean' : 'has-issues'}>
            {peerLifecycle.isLocked
              ? `Locked: users can evaluate (${peerLifecycle.completed || 0}/${peerLifecycle.total || 0} completed)`
              : 'Unlocked: peer evaluation hidden from users'}
          </span>
        </div>
      )}

      {admin && setup.canManual && (
        <div className="peer-manual-admin">
          <div className="peer-setup-form">
            <div className="peer-form-heading">
              <strong>1. Choose the evaluation</strong>
              <span>{form.evaluator_role === 'dean' ? 'Select the period, then choose two Deans from different departments.' : 'Select the period, evaluator type, and department for this assignment.'}</span>
            </div>
            <label>
              <span>Evaluation Period</span>
              <select value={form.evaluation_period_id} onChange={(event) => updateForm('evaluation_period_id', event.target.value)} required>
                <option value="">Choose a period</option>
                {setup.periods.map((period) => (
                  <option key={period.id} value={period.id}>{period.period_name}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Peer Group</span>
              <select value={form.evaluator_role} onChange={(event) => updateForm('evaluator_role', event.target.value)} required>
                {roleOptions.length === 0 && <option value="teacher">Faculty</option>}
                {roleOptions.map((role) => (
                  <option key={role} value={role}>{role === 'dean' ? 'Dean-to-Dean' : `${roleLabel(role)}-to-${roleLabel(role)}`}</option>
                ))}
              </select>
            </label>
            {form.evaluator_role !== 'dean' ? (
              <label>
                <span>Department</span>
                <select value={form.department_id} onChange={(event) => updateForm('department_id', event.target.value)} required>
                  <option value="">Choose a department</option>
                  <option value="all">Apply to All Departments</option>
                  <option value="unassigned">Not in a Department</option>
                  {setup.departments.map((department) => (
                    <option key={department.id} value={department.id}>{department.label}</option>
                  ))}
                </select>
              </label>
            ) : (
              <div className="peer-dean-scope-note" role="note">
                <ShieldCheck size={18} />
                <span><strong>Institution-wide Dean selection</strong><small>The evaluator and evaluated Dean must belong to different departments.</small></span>
              </div>
            )}
          </div>

          {viewing && (
            <div className="peer-view-panel">
              <strong>{viewing.evaluatorName} evaluates {viewing.evaluateeName}</strong>
              <span>{viewing.periodName} - {viewing.evaluatorRoleLabel} to {viewing.evaluateeRoleLabel} - {viewing.status}</span>
            </div>
          )}

          <form id="peer-assign-evaluator-form" className={`peer-manual-form ${form.id ? 'is-editing' : ''}`} onSubmit={saveManualAssignment}>
            <div className="peer-form-heading">
              <strong>2. Assign the evaluator</strong>
              <span>Choose who will give the evaluation and who will receive it.</span>
            </div>
            <label>
              <span>{form.evaluator_role === 'dean' ? 'Which Dean will evaluate?' : 'Who will evaluate?'}</span>
              <select value={form.evaluator_id} onChange={(event) => updateForm('evaluator_id', event.target.value)} required>
                <option value="">Choose evaluator</option>
                {evaluatorOptions.map((user) => (
                  <option key={`${user.role}-${user.id}`} value={user.id}>
                    {user.name} {form.evaluator_role === 'dean' ? `— ${user.department || 'Unassigned Department'}` : user.program ? `- ${user.program}` : ''}{user.actingRoleLabel ? ` (${user.actingRoleLabel})` : ''}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>{form.evaluator_role === 'dean' ? 'Which other Dean will be evaluated?' : 'Who will be evaluated?'}</span>
              <select value={form.evaluatee_id} onChange={(event) => updateForm('evaluatee_id', event.target.value)} required>
                <option value="">{form.evaluator_role === 'dean' ? 'Choose a Dean from another department' : 'Choose peer/evaluatee'}</option>
                {peerOptions.map((user) => (
                  <option key={`${user.role}-${user.id}`} value={user.id}>
                    {user.name} {form.evaluator_role === 'dean' ? `— ${user.department || 'Unassigned Department'}` : user.program ? `- ${user.program}` : ''}{user.actingRoleLabel ? ` (${user.actingRoleLabel})` : ''}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>Assignment Status</span>
              <select value={form.status} onChange={(event) => updateForm('status', event.target.value)}>
                <option value="pending">Not Started</option>
                <option value="overdue">Past Due</option>
                <option value="completed">Finished</option>
              </select>
            </label>
            <div className="peer-setup-actions">
              <button type="submit" disabled={!!busy || peerLifecycle.isLocked || !form.evaluator_id || !form.evaluatee_id}>
                {busy === 'save' ? <Loader2 size={16} className="animate-spin" /> : <ShieldCheck size={16} />}
                {form.id ? 'Update' : 'Save'}
              </button>
              {form.id && (
                <button type="button" className="secondary" onClick={resetForm} disabled={!!busy}>
                  Cancel Edit
                </button>
              )}
            </div>
          </form>

          <div className="peer-filter-bar">
            <label>
              <span>Display Department</span>
              <select
                value={filterDepartment}
                onChange={(event) => changeDepartmentFilter(event.target.value)}
                disabled={departmentChanging}
              >
                <option value="">Show All Departments</option>
                {setup.departments.map((department) => (
                  <option key={department.id} value={department.id}>{department.label}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Select Role filter</span>
              <select value={filterRole} onChange={(event) => setFilterRole(event.target.value)}>
                <option value="">All Roles</option>
                {(roleOptions.length > 0 ? roleOptions : ['teacher', 'program_head', 'dean']).map((role) => (
                  <option key={role} value={role}>{roleLabel(role)}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Search and filter</span>
              <div className="peer-search-field">
                <Search size={16} />
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search evaluator, peer, role, status..." />
              </div>
            </label>
            <div className="peer-setup-actions">
              <button
                type="button"
                className="secondary"
                onClick={() => {
                  changeDepartmentFilter('');
                  setFilterProgram('');
                  setFilterRole('');
                  setSearch('');
                }}
              >
                <Eye size={16} />
                View All Assignments
              </button>
            </div>
          </div>

          <div className="peer-filter-summary">
            <span>
              Showing <strong>{filteredRows.length}</strong> of <strong>{rows.length}</strong> assignment(s)
              {activePeriod?.period_name ? <> for <strong>{activePeriod.period_name}</strong></> : null}
              {selectedViewDepartment ? <> in <strong>{selectedViewDepartment.label}</strong></> : null}
            </span>
            {selectedViewDepartment && (
              <small>This only filters the display. Assignments in other departments remain saved.</small>
            )}
            {(filterDepartment || filterRole || search) && (
              <button
                type="button"
                onClick={() => {
                  changeDepartmentFilter('');
                  setFilterProgram('');
                  setFilterRole('');
                  setSearch('');
                }}
              >
                Clear filters
              </button>
            )}
          </div>

          <div
            className={`peer-department-results ${departmentChanging ? 'is-changing' : 'is-ready'}`}
            aria-busy={departmentChanging}
          >
            {departmentChanging && <div className="peer-filter-loading">Updating department assignments...</div>}
            {filteredRows.length === 0
              ? renderAssignmentTable(
                [],
                selectedViewDepartment
                  ? `${selectedViewDepartment.code || selectedViewDepartment.name} Peer Assignments`
                  : 'All Department Peer Assignments',
                0,
              )
              : (
                <>
                  {visibleDeanRows.length > 0 && renderAssignmentTable(
                    visibleDeanRows,
                    'Dean-to-Dean Peer Assignments',
                    deanRows.length,
                  )}
                  {visibleDepartmentRows.length > 0 && renderAssignmentTable(
                    visibleDepartmentRows,
                    selectedViewDepartment
                      ? `${selectedViewDepartment.code || selectedViewDepartment.name} Peer Assignments`
                      : 'All Department Peer Assignments',
                    departmentRows.length,
                  )}
                </>
              )}
            {renderMonitorPagination()}
          </div>
        </div>
      )}

      {loading && <div className="dipascaf-empty">Loading confidential peer mappings...</div>}
      {!loading && !admin && filteredRows.length === 0 && <div className="dipascaf-empty">No peer to peer evaluator assigned yet.</div>}

      {!loading && !admin && filteredRows.length > 0 && (
        <div className={`peer-assignment-groups ${compact ? 'is-compact' : ''}`}>
          {grouped.map(([scope, items]) => (
            <div className="peer-assignment-group" key={scope}>
              <div className="peer-group-title">
                <strong>{scope}</strong>
                <span>{items.length} assignment(s)</span>
              </div>
              <div className="peer-assignment-list">
                {items.map((row) => (
                  <article className={`peer-assignment-card ${row.status}`} key={`${row.id}-${row.evaluateeId}-${row.status}`}>
                    <div className="peer-pair-main">
                      <div className="peer-person">
                        <div className="peer-avatar">{row.evaluatorAvatar ? <img src={assetUrl(row.evaluatorAvatar)} alt={`${row.evaluatorName} profile`} /> : row.evaluatorName.charAt(0)}</div>
                        <div>
                          <span>Evaluator</span>
                          <strong>{row.evaluatorName}</strong>
                          <small>{row.evaluatorPosition || row.evaluatorRole.replace('_', ' ')}</small>
                        </div>
                      </div>
                      <div className="peer-arrow" aria-hidden="true">-&gt;</div>
                      <div className="peer-person assigned">
                        <div className="peer-avatar">{row.avatar ? <img src={assetUrl(row.avatar)} alt={`${row.evaluateeName} profile`} /> : row.evaluateeName.charAt(0)}</div>
                        <div>
                          <span>Assigned Peer</span>
                          <strong>{row.evaluateeName}</strong>
                          <small>{assignedPersonMeta(row)}</small>
                        </div>
                      </div>
                    </div>
                    <div className="peer-card-tail">
                      <StatusBadge status={row.status} />
                      <small title={row.department}>{row.department}</small>
                      {row.score !== null && <small>Score {Number(row.score).toFixed(2)}/5</small>}
                      {row.locked && <small>Locked</small>}
                    </div>
                  </article>
                ))}
              </div>
            </div>
          ))}
          {renderMonitorPagination()}
        </div>
      )}
    </section>
  );
}
