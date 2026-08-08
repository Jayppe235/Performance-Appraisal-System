import { useParams, useNavigate } from 'react-router-dom';
import { useEffect, useState, useMemo, useRef } from 'react';
import { createPortal } from 'react-dom';
import { ArrowLeft, BarChart3, Phone, Plus, Edit, Archive, Search, X, Check, AlertCircle, BookOpen, Users, UserPlus, Filter, ArrowUpDown, Building2, Loader2 } from 'lucide-react';
import apiFetch from '../data/api.js';
import { assetUrl } from '../data/apiBase.js';
import PeerAssignmentsPanel from '../components/evaluations/PeerAssignmentsPanel.jsx';
import { useDashboardContext } from '../layouts/DashboardLayout.jsx';
import { confirmDeleteData, confirmSaveChanges } from '../components/common/ConfirmationModal.jsx';
import AnimatedCounter from '../components/common/AnimatedCounter.jsx';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';

function programCountForDepartment(department, allPrograms) {
  if (!department) return 0;
  return programsForDepartment(department, allPrograms).length;
}

function programsForDepartment(department, allPrograms) {
  if (!department) return [];
  const departmentId = Number(department.id || 0);
  const code = String(department.code || '').toUpperCase();
  const name = String(department.name || '').toLowerCase();
  const inferredCode =
    code ||
    (name === 'cite' || name === 'cit' || name.includes('information technology') || name.includes('computer') ? 'CITE' : '') ||
    (name === 'coed' || name.includes('education') ? 'COED' : '') ||
    (name === 'cba' || name.includes('business') ? 'CBA' : '') ||
    (name === 'cas' || name.includes('arts') || name.includes('sciences') ? 'CAS' : '');

  return allPrograms.filter((p) => {
    const deptCode = String(p.department_code || '').toUpperCase();
    const programDepartmentId = Number(p.department_id || 0);

    if (departmentId && programDepartmentId) {
      return programDepartmentId === departmentId;
    }

    return Boolean(inferredCode && deptCode === inferredCode);
  });
}

function normalizeDepartmentName(rawName) {
  const name = String(rawName || '').trim();
  const lower = name.toLowerCase();

  if (lower === 'cite' || lower === 'cit' || lower === 'computer studies' || lower === 'information technology' || lower === 'information technology education' || lower === 'computer science' || lower === 'computer engineering') {
    return 'College of Information Technology Engineering';
  }

  return name;
}

function departmentMatches(user, department) {
  const userDepartment = normalizeDepartmentName(user?.department);
  const departmentName = normalizeDepartmentName(department?.name);
  const departmentCode = String(department?.code || '').toLowerCase();
  const lowerUserDepartment = userDepartment.toLowerCase();

  if (!userDepartment || !department) return false;
  if (userDepartment === departmentName || lowerUserDepartment === departmentCode) return true;
  if (departmentCode === 'cas' && (lowerUserDepartment.includes('arts') || lowerUserDepartment.includes('sciences'))) return true;
  if ((departmentCode === 'cit' || departmentCode === 'cite') && (lowerUserDepartment.includes('information technology') || lowerUserDepartment.includes('computer') || lowerUserDepartment === 'cite' || lowerUserDepartment === 'cit')) return true;
  if (departmentCode === 'coed' && lowerUserDepartment.includes('education')) return true;
  if (departmentCode === 'cba' && lowerUserDepartment.includes('business')) return true;

  return false;
}

function normalizeDepartment(department) {
  const code = String(department?.code || '').toUpperCase();
  const name = String(department?.name || '').toLowerCase();

  if (code === 'CIT' || code === 'CITE' || name.includes('information technology') || name.includes('computer')) {
    return {
      ...department,
      code: 'CITE',
      name: 'College of Information Technology Engineering',
    };
  }

  return department;
}

function mapApiUserToDepartmentUser(apiUser) {
  const role = String(apiUser?.role || '').toLowerCase();

  return {
    id: Number(apiUser.id),
    userCode: String(apiUser.user_code || ''),
    facultyId: Number(apiUser.faculty_id || 0),
    fullName: apiUser.full_name || '',
    role: role === 'dean' ? 'Dean' : role === 'program_head' ? 'Program Head' : role === 'vpaa' ? 'VPAA' : role === 'admin_hr' ? 'Admin/HR' : 'Faculty',
    department: normalizeDepartmentName(apiUser.department || ''),
    program: apiUser.program || '',
    email: String(apiUser.email || '').toLowerCase().endsWith('@pmas.local') ? '' : (apiUser.email || ''),
    phone: apiUser.phone || '',
    status: apiUser.is_active == 1 ? 'Active' : 'Inactive',
    avatar: assetUrl(apiUser.profile_image || ''),
    subjectAssignments: apiUser.subject_assignments || [],
  };
}

const initialState = {
  code: '',
  name: '',
  program_head_user_id: '',
  is_active: 1,
};

export default function DepartmentProfilePage() {
  const { departmentId } = useParams();
  const navigate = useNavigate();
  const { role } = useDashboardContext();
  const { selectedPeriodId } = useEvaluationPeriod();
  const isAdmin = role?.key === 'admin';
  const [departments, setDepartments] = useState([]);
  const [allPrograms, setAllPrograms] = useState([]);
  const [users, setUsers] = useState([]);
  const [peopleLoading, setPeopleLoading] = useState(true);
  const [loading, setLoading] = useState(true);
  const [usersError, setUsersError] = useState('');

  /* ── Manage Programs state ───────────────────────────────────────── */
  const [deptPrograms, setDeptPrograms] = useState([]);
  const [availableHeads, setAvailableHeads] = useState([]);
  const [programsLoading, setProgramsLoading] = useState(false);
  const [showProgramModal, setShowProgramModal] = useState(false);
  const [editProgramId, setEditProgramId] = useState(null);
  const [formData, setFormData] = useState({ ...initialState });
  const [formSubmitting, setFormSubmitting] = useState(false);
  const [formError, setFormError] = useState('');
  const [programSearch, setProgramSearch] = useState('');
  const [programSort, setProgramSort] = useState('name');
  const [programSortDir, setProgramSortDir] = useState('asc');
  const [programFilter, setProgramFilter] = useState('all');
  const [peopleSearch, setPeopleSearch] = useState('');
  const [peopleProgramFilter, setPeopleProgramFilter] = useState('');
  const [peopleStatusFilter, setPeopleStatusFilter] = useState('');
  const [peopleSort, setPeopleSort] = useState('name');
  const [activePeopleCategory, setActivePeopleCategory] = useState('dean');
  const [archiveLoading, setArchiveLoading] = useState(false);
  const [ratingsLoadingId, setRatingsLoadingId] = useState(null);
  const [subjects, setSubjects] = useState([]);
  const [subjectForm, setSubjectForm] = useState({ code: '', name: '' });
  const [subjectSaving, setSubjectSaving] = useState(false);
  const [showSubjectForm, setShowSubjectForm] = useState(false);
  const [academicManagementView, setAcademicManagementView] = useState('programs');
  const subjectCodeRef = useRef(null);
  const peopleTabRefs = useRef({});

  const loadSubjects = async () => {
    if (!departmentId) return;
    const payload = await apiFetch(`/api/subject-areas.php?department_id=${departmentId}&include_inactive=1`);
    setSubjects(Array.isArray(payload.subjects) ? payload.subjects : []);
  };

  useEffect(() => {
    loadSubjects().catch((error) => setUsersError(error.message || 'Unable to load subject areas.'));
  }, [departmentId]);

  async function createSubject(event) {
    event.preventDefault();
    if (!subjectForm.code.trim() || !subjectForm.name.trim() || subjectSaving) return;
    setSubjectSaving(true);
    try {
      await apiFetch('/api/subject-areas.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ department_id: Number(departmentId), subject_code: subjectForm.code.trim().toUpperCase(), subject_name: subjectForm.name.trim(), is_active: true }),
      });
      setSubjectForm({ code: '', name: '' });
      setShowSubjectForm(false);
      await loadSubjects();
    } catch (error) { setUsersError(error.message); } finally { setSubjectSaving(false); }
  }

  async function updateSubject(subject, changes) {
    setSubjectSaving(true);
    try {
      await apiFetch('/api/subject-areas.php', {
        method: 'PUT', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: subject.id,
          department_id: subject.department_id,
          subject_code: changes.subject_code ?? subject.subject_code,
          subject_name: changes.subject_name ?? subject.subject_name,
          coordinator_faculty_id: Object.prototype.hasOwnProperty.call(changes, 'coordinator_faculty_id') ? changes.coordinator_faculty_id : subject.coordinator_faculty_id,
          is_active: changes.is_active ?? Boolean(Number(subject.is_active)),
        }),
      });
      await loadSubjects();
    } catch (error) { setUsersError(error.message); } finally { setSubjectSaving(false); }
  }

  function editDepartmentAccount(user) {
    if (!user?.id) return;
    const returnTo = `/admin/department/${departmentId}`;
    navigate(`/admin/people?view=users&action=edit-account&user_id=${user.id}&return_to=${encodeURIComponent(returnTo)}`);
  }

  // ── Load departments & programs on mount ─────────────────────────────
  useEffect(() => {
    let alive = true;

    async function loadReferenceData() {
      try {
        const [deptPayload, progPayload] = await Promise.all([
          apiFetch('/api/departments.php'),
          apiFetch('/api/programs.php'),
        ]);
        if (alive) {
          if (deptPayload.ok && Array.isArray(deptPayload.data)) {
            setDepartments(deptPayload.data.map((department) => ({
              ...department,
              logo: assetUrl(department.logo || 'assets/images/ndmc-seal.png'),
            })));
          }
          if (progPayload.ok && Array.isArray(progPayload.data)) setAllPrograms(progPayload.data);
        }
      } catch (_) {
        // Reference data unavailable — empty lists are fine
      } finally {
        if (alive) setLoading(false);
      }
    }

    loadReferenceData();
    return () => { alive = false; };
  }, []);

  /* ── Fetch programs for the current department ─────────────────── */
  useEffect(() => {
    if (!departmentId) return;
    let alive = true;
    async function fetchDeptPrograms() {
      setProgramsLoading(true);
      try {
        const payload = await apiFetch(`/api/programs.php?department_id=${departmentId}&include_inactive=1`);
        if (alive) {
          if (payload.ok && Array.isArray(payload.data)) {
            setDeptPrograms(payload.data);
          }
          if (Array.isArray(payload.available_heads)) {
            setAvailableHeads(payload.available_heads);
          }
        }
      } catch (_) {
        // Programs unavailable
      } finally {
        if (alive) setProgramsLoading(false);
      }
    }
    fetchDeptPrograms();
    return () => { alive = false; };
  }, [departmentId]);

  /* ── Derived sorted/filtered programs ────────────────────────────── */
  const filteredPrograms = useMemo(() => {
    let list = [...deptPrograms];

    // Filter
    if (programFilter === 'active') list = list.filter(p => p.is_active === 1);
    else if (programFilter === 'inactive') list = list.filter(p => p.is_active === 0);

    // Search
    if (programSearch.trim()) {
      const q = programSearch.toLowerCase();
      list = list.filter(p =>
        (p.name || '').toLowerCase().includes(q) ||
        (p.code || '').toLowerCase().includes(q) ||
        (p.program_head || '').toLowerCase().includes(q)
      );
    }

    // Sort
    list.sort((a, b) => {
      let cmp = 0;
      if (programSort === 'name') cmp = (a.name || '').localeCompare(b.name || '');
      else if (programSort === 'code') cmp = (a.code || '').localeCompare(b.code || '');
      else if (programSort === 'head') cmp = (a.program_head || '').localeCompare(b.program_head || '');
      else if (programSort === 'faculty') cmp = (a.faculty_count || 0) - (b.faculty_count || 0);
      else if (programSort === 'status') cmp = (b.is_active || 0) - (a.is_active || 0);
      return programSortDir === 'asc' ? cmp : -cmp;
    });

    return list;
  }, [deptPrograms, programSearch, programSort, programSortDir, programFilter]);

  const programStats = useMemo(() => ({
    active: deptPrograms.filter((program) => Number(program.is_active) === 1).length,
    archived: deptPrograms.filter((program) => Number(program.is_active) === 0).length,
    unassigned: deptPrograms.filter((program) => !program.program_head).length,
    faculty: deptPrograms.reduce((total, program) => total + Number(program.faculty_count || 0), 0),
  }), [deptPrograms]);

  /* ── Program CRUD handlers ──────────────────────────────────────── */
  function openAddModal() {
    setEditProgramId(null);
    setFormData({ ...initialState });
    setFormError('');
    setShowProgramModal(true);
  }

  function openEditModal(program) {
    setEditProgramId(program.id);
    setFormData({
      code: program.code || '',
      name: program.name || '',
      program_head_user_id: program.program_head_user_id ? String(program.program_head_user_id) : '',
      is_active: program.is_active,
    });
    setFormError('');
    setShowProgramModal(true);
  }

  function closeProgramModal() {
    setShowProgramModal(false);
    setEditProgramId(null);
    setFormError('');
  }

  function handleFormChange(e) {
    const { name, value, type } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: type === 'number' ? Number(value) : value,
    }));
  }

  async function handleFormSubmit(e) {
    e.preventDefault();
    if (!formData.code.trim() || !formData.name.trim()) {
      setFormError('Program Code and Program Name are required.');
      return;
    }
    const confirmed = await confirmSaveChanges();
    if (!confirmed) return;
    setFormSubmitting(true);
    setFormError('');

    try {
      const isEdit = editProgramId !== null;
      const method = isEdit ? 'PUT' : 'POST';
      const body = isEdit
        ? { id: editProgramId, code: formData.code, name: formData.name, program_head_user_id: formData.program_head_user_id || null, is_active: formData.is_active }
        : { department_id: Number(departmentId), code: formData.code, name: formData.name, program_head_user_id: formData.program_head_user_id || null };

      const payload = await apiFetch('/api/programs.php', {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });

      if (!payload.ok) {
        setFormError(payload.message || 'Operation failed.');
        return;
      }

      // Refresh the list
      const refreshPayload = await apiFetch(`/api/programs.php?department_id=${departmentId}&include_inactive=1`);
      if (refreshPayload.ok && Array.isArray(refreshPayload.data)) {
        setDeptPrograms(refreshPayload.data);
      }
      if (Array.isArray(refreshPayload.available_heads)) {
        setAvailableHeads(refreshPayload.available_heads);
      }

      closeProgramModal();
    } catch (err) {
      setFormError(err.message || 'An unexpected error occurred.');
    } finally {
      setFormSubmitting(false);
    }
  }

  async function handleArchiveProgram(programId) {
    const confirmed = await confirmDeleteData({
      message: 'This will deactivate the program and unassign its program head. Faculty assigned to this program will retain their records.',
      confirmText: 'Archive Program',
    });
    if (!confirmed) return;
    setArchiveLoading(true);
    try {
      const payload = await apiFetch('/api/programs.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: programId }),
      });

      if (!payload.ok) {
        setFormError(payload.message || 'Failed to archive program.');
        setArchiveLoading(false);
        return;
      }

      const refreshPayload = await apiFetch(`/api/programs.php?department_id=${departmentId}&include_inactive=1`);
      if (refreshPayload.ok && Array.isArray(refreshPayload.data)) {
        setDeptPrograms(refreshPayload.data);
      }
      if (Array.isArray(refreshPayload.available_heads)) {
        setAvailableHeads(refreshPayload.available_heads);
      }
    } catch (_) {
      setFormError('An unexpected error occurred while archiving the program.');
    } finally {
      setArchiveLoading(false);
    }
  }

  function toggleSort(col) {
    if (programSort === col) {
      setProgramSortDir(prev => (prev === 'asc' ? 'desc' : 'asc'));
    } else {
      setProgramSort(col);
      setProgramSortDir('asc');
    }
  }

  // ── Real-time: fetch users from the API every 8 seconds ────────────
  useEffect(() => {
    let alive = true;

    async function fetchUsers() {
      try {
        const payload = await apiFetch('/api/people.php');
        if (payload.ok && Array.isArray(payload.users)) {
          const mappedUsers = payload.users.map(mapApiUserToDepartmentUser);
          if (alive) {
            setUsers(mappedUsers);
            setUsersError('');
          }
          return;
        }
        throw new Error(payload.message || 'Unable to load users from the database.');
      } catch (error) {
        if (alive) {
          setUsers([]);
          setUsersError(error.message || 'Unable to load users from the database.');
        }
      } finally {
        if (alive) setPeopleLoading(false);
      }
    }

    fetchUsers();
    const interval = window.setInterval(fetchUsers, 8000);

    return () => window.clearInterval(interval);
  }, [setUsers]);

  const visibleUsers = users;

  const rawDepartment = departments.find((dept) => dept.id === parseInt(departmentId));
  const department = rawDepartment ? { ...normalizeDepartment(rawDepartment), programs: programCountForDepartment(rawDepartment, allPrograms) } : null;
  const departmentScopedHeads = useMemo(() => {
    if (!department) return [];

    return availableHeads.filter((head) => departmentMatches({
      department: head.department || '',
      program: head.program || '',
    }, department));
  }, [availableHeads, department]);
  const departmentUsers = visibleUsers.filter((user) => departmentMatches(user, department)) || [];
  const dean = departmentUsers.find((user) => user.role === 'Dean') || visibleUsers.find((user) => user.fullName === department?.dean);
  const programHeads = departmentUsers.filter((u) => u.role === 'Program Head');
  const faculty = departmentUsers.filter((u) => u.role === 'Faculty');
  const adminUsers = departmentUsers.filter((u) => ['Admin', 'Admin/HR'].includes(u.role));
  const deanStatus = dean?.status || 'Active';
  const peopleProgramOptions = useMemo(() => (
    [...new Set([...programHeads, ...faculty].map((user) => user.program).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  ), [faculty, programHeads]);
  const peopleStatusOptions = useMemo(() => (
    [...new Set([...programHeads, ...faculty].map((user) => user.status).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  ), [faculty, programHeads]);
  const filterDepartmentPeople = useMemo(() => {
    const query = peopleSearch.trim().toLowerCase();

    return (items) => {
      const filtered = items.filter((user) => {
        const haystack = [
          user.fullName,
          user.email,
          user.phone,
          user.program,
          user.role,
          user.status,
        ].filter(Boolean).join(' ').toLowerCase();

        return (!query || haystack.includes(query))
          && (!peopleProgramFilter || user.program === peopleProgramFilter)
          && (!peopleStatusFilter || user.status === peopleStatusFilter);
      });

      return [...filtered].sort((left, right) => {
        const leftValue = peopleSort === 'program' ? left.program || '' : peopleSort === 'status' ? left.status || '' : left.fullName || '';
        const rightValue = peopleSort === 'program' ? right.program || '' : peopleSort === 'status' ? right.status || '' : right.fullName || '';
        return leftValue.localeCompare(rightValue);
      });
    };
  }, [peopleProgramFilter, peopleSearch, peopleSort, peopleStatusFilter]);
  const visibleProgramHeads = filterDepartmentPeople(programHeads);
  const visibleFaculty = filterDepartmentPeople(faculty);
  const peopleFiltersActive = !!(peopleSearch || peopleProgramFilter || peopleStatusFilter || peopleSort !== 'name');
  const peopleTabs = [
    { key: 'dean', label: 'Department Dean', count: dean ? 1 : 0 },
    { key: 'programHeads', label: 'Program Heads', count: programHeads.length },
    { key: 'faculty', label: 'Faculty Members', count: faculty.length },
  ];

  function handlePeopleTabKeyDown(event, currentKey) {
    const currentIndex = peopleTabs.findIndex((tab) => tab.key === currentKey);
    let nextIndex = currentIndex;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % peopleTabs.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + peopleTabs.length) % peopleTabs.length;
    else if (event.key === 'Home') nextIndex = 0;
    else if (event.key === 'End') nextIndex = peopleTabs.length - 1;
    else return;
    event.preventDefault();
    const nextKey = peopleTabs[nextIndex].key;
    setActivePeopleCategory(nextKey);
    peopleTabRefs.current[nextKey]?.focus();
  }

  function viewOverallRatings(user) {
    setRatingsLoadingId(user?.id || null);
    // Look up the user's program in deptPrograms by matching name/code for reliable ID matching
    const userProgram = (user?.program || '').trim().toLowerCase();
    const matchedProgram = deptPrograms.find(p =>
      (p.code || '').toLowerCase() === userProgram ||
      (p.name || '').toLowerCase() === userProgram ||
      (p.name || '').toLowerCase().includes(userProgram)
    );

    navigate('/admin/ai-actions', {
      state: {
        focusUserId: user?.id,
        focusUserName: user?.fullName,
        focusUserEmail: user?.email,
        focusRole: user?.role,
        focusProgramId: matchedProgram?.id,
        focusProgramCode: matchedProgram?.code || user?.program,
        focusProgramName: matchedProgram?.name || user?.program,
        focusDepartmentId: department?.id,
        focusDepartmentCode: department?.code,
        focusDepartmentName: department?.name,
        focusProgram: user?.program,
        source: 'department-profile',
      },
    });
  }

  function addDepartmentAccount() {
    const role = activePeopleCategory === 'dean'
      ? 'Dean'
      : activePeopleCategory === 'programHeads'
        ? 'Program Head'
        : 'Faculty';
    const params = new URLSearchParams({
      view: 'users',
      action: 'add-account',
      department_id: String(department.id),
      role,
    });
    navigate(`/admin/people?${params.toString()}#account-management`);
  }

  if (!department) {
    return (
      <section className="admin-content page-enter">
        <div className="admin-box module-empty">
          <p>Department not found</p>
          <button onClick={() => navigate(-1)}>Go Back</button>
        </div>
      </section>
    );
  }

  return (
    <section className="admin-content admin-module department-profile-page page-enter">
      {/* Header */}
      <div className="dept-profile-header module-wide">
        <button className="back-button" onClick={() => navigate(-1)}>
          <ArrowLeft className="h-4 w-4" /> Back
        </button>
        <div className="dept-header-content">
          <div className="dept-header-logo">
            <img src={department.logo} alt={`${department.name} logo`} />
          </div>
          <div className="dept-header-info">
            <span className="eyebrow">{department.code}</span>
            <h1>{department.name}</h1>
            <p className="dept-description">
              {faculty.length} faculty members &bull; {department.programs || 0} program(s)
            </p>
          </div>
        </div>
      </div>

      {/* Department Overview Statistics - Realtime Updating */}
      {usersError && (
        <div className="notice error module-wide">
          {usersError}
        </div>
      )}

      <section className="admin-box module-stats module-wide dept-profile-page-section" style={{ '--section-index': 1 }}>
        <div className="stat-grid" data-realtime="true">
          <article>
            <span>Faculty Members</span>
            <AnimatedCounter value={faculty.length} duration={1500} />
          </article>
          <article>
            <span>Dean Accounts</span>
            <AnimatedCounter value={dean ? 1 : 0} duration={1200} />
          </article>
          <article>
            <span>Program Heads</span>
            <AnimatedCounter value={programHeads.length} duration={1200} />
          </article>
          <article>
            <span>Admin Heads</span>
            <AnimatedCounter value={adminUsers.length} duration={1200} />
          </article>
          <article>
            <span>Programs</span>
            <AnimatedCounter value={department.programs || 0} duration={1800} />
          </article>
          <article>
            <span>Completion Rate</span>
            <AnimatedCounter value={department.completion || 0} suffix="%" duration={2000} />
          </article>
          <article className="dean-summary">
            <span>Dean</span>
            <strong>{department.dean || 'Unassigned'}</strong>
          </article>
        </div>
      </section>

      <div className="module-wide dept-profile-page-section peer-monitor-section" style={{ '--section-index': 2 }}>
        <PeerAssignmentsPanel
          key={`department-peer-${department.id}-${selectedPeriodId || 'loading'}`}
          compact
          departmentId={department.id}
          periodId={selectedPeriodId}
          excludeDeans
          strictDepartmentScope
          title={`${department.code} Peer-to-Peer Monitoring`}
        />
      </div>

      {/* ── Manage Programs (Admin only) ───────────────────────────────── */}
      {isAdmin && (
        <section className="admin-box module-wide manage-programs-section dept-profile-page-section" style={{ '--section-index': 3 }}>
          <div className="box-title">
            <div>
              <h2>{academicManagementView === 'programs' ? 'Manage Programs' : 'Manage Subject Areas'}</h2>
              <span>{academicManagementView === 'programs' ? `${deptPrograms.length} program(s)` : `${subjects.length} subject area(s)`} under {department.code}</span>
            </div>
            <div className="programs-toolbar-actions">
              <div className="academic-management-tabs" role="tablist" aria-label="Academic assignment management views">
                <button type="button" role="tab" aria-selected={academicManagementView === 'programs'} className={academicManagementView === 'programs' ? 'active' : ''} onClick={() => setAcademicManagementView('programs')}>Programs</button>
                <button type="button" role="tab" aria-selected={academicManagementView === 'subjects'} className={academicManagementView === 'subjects' ? 'active' : ''} onClick={() => setAcademicManagementView('subjects')}>Subject Areas</button>
              </div>
              <button type="button" className="primary-button" onClick={() => {
                if (academicManagementView === 'programs') openAddModal();
                else {
                  setShowSubjectForm(true);
                  window.setTimeout(() => subjectCodeRef.current?.focus(), 50);
                }
              }}>
                <Plus className="h-4 w-4" /> {academicManagementView === 'programs' ? 'Add Program' : 'Add Subject Area'}
              </button>
            </div>
          </div>

          <div hidden={academicManagementView !== 'programs'}>
          <div className="programs-insight-strip" aria-label="Program summary">
            <article>
              <span>Total Programs</span>
              <strong>{deptPrograms.length}</strong>
            </article>
            <article>
              <span>Active</span>
              <strong>{programStats.active}</strong>
            </article>
            <article>
              <span>Unassigned Heads</span>
              <strong>{programStats.unassigned}</strong>
            </article>
            <article>
              <span>Total Faculty</span>
              <strong>{programStats.faculty}</strong>
            </article>
          </div>

          {/* Search & Filter Toolbar */}
          <div className="programs-control-panel">
            <div className="programs-search-box">
              <div>
                <Search className="h-4 w-4" />
                <input
                  type="search"
                  placeholder="Search programs by name, code, or head..."
                  value={programSearch}
                  onChange={e => setProgramSearch(e.target.value)}
                />
              </div>
            </div>
            <div className="programs-filter-row">
              <div className="programs-filter-group">
                <Filter className="h-4 w-4" />
                <select
                  value={programFilter}
                  onChange={e => setProgramFilter(e.target.value)}
                  className="programs-filter-select"
                >
                  <option value="all">All Programs</option>
                  <option value="active">Active</option>
                  <option value="inactive">Archived</option>
                </select>
              </div>
              <span className="programs-count-badge">
                {filteredPrograms.length} of {deptPrograms.length} shown
              </span>
            </div>
          </div>

          {/* Programs Table */}
          {programsLoading ? (
            <ProgramTableSkeleton />
          ) : filteredPrograms.length === 0 ? (
            <div className="module-empty">
              <BookOpen className="h-10 w-10" style={{ opacity: 0.3, marginBottom: 8 }} />
              <p>{programSearch || programFilter !== 'all' ? 'No programs match your search.' : 'No programs yet for this department.'}</p>
              <button type="button" className="ghost-button" onClick={openAddModal} style={{ marginTop: 8 }}>
                <Plus className="h-4 w-4" /> Add the first program
              </button>
            </div>
          ) : (
            <div className="programs-table-container">
              {/* Table Header */}
              <div className="programs-table-row programs-table-header">
                <button type="button" className={`programs-th ${programSort === 'code' ? 'is-sorted' : ''}`} aria-pressed={programSort === 'code'} aria-label={`Sort by code${programSort === 'code' ? `, currently ${programSortDir === 'asc' ? 'ascending' : 'descending'}` : ''}`} onClick={() => toggleSort('code')}>
                  Code {programSort === 'code' && <ArrowUpDown className="h-3 w-3" />}
                </button>
                <button type="button" className={`programs-th ${programSort === 'name' ? 'is-sorted' : ''}`} aria-pressed={programSort === 'name'} aria-label={`Sort by program name${programSort === 'name' ? `, currently ${programSortDir === 'asc' ? 'ascending' : 'descending'}` : ''}`} onClick={() => toggleSort('name')}>
                  Program Name {programSort === 'name' && <ArrowUpDown className="h-3 w-3" />}
                </button>
                <button type="button" className={`programs-th ${programSort === 'head' ? 'is-sorted' : ''}`} aria-pressed={programSort === 'head'} aria-label={`Sort by program head${programSort === 'head' ? `, currently ${programSortDir === 'asc' ? 'ascending' : 'descending'}` : ''}`} onClick={() => toggleSort('head')}>
                  Program Head {programSort === 'head' && <ArrowUpDown className="h-3 w-3" />}
                </button>
                <button type="button" className={`programs-th ${programSort === 'faculty' ? 'is-sorted' : ''}`} aria-pressed={programSort === 'faculty'} aria-label={`Sort by faculty count${programSort === 'faculty' ? `, currently ${programSortDir === 'asc' ? 'ascending' : 'descending'}` : ''}`} onClick={() => toggleSort('faculty')}>
                  Faculty {programSort === 'faculty' && <ArrowUpDown className="h-3 w-3" />}
                </button>
                <button type="button" className={`programs-th ${programSort === 'status' ? 'is-sorted' : ''}`} aria-pressed={programSort === 'status'} aria-label={`Sort by status${programSort === 'status' ? `, currently ${programSortDir === 'asc' ? 'ascending' : 'descending'}` : ''}`} onClick={() => toggleSort('status')}>
                  Status {programSort === 'status' && <ArrowUpDown className="h-3 w-3" />}
                </button>
                <span className="programs-th programs-th-actions">Actions</span>
              </div>

              {/* Table Rows */}
              {filteredPrograms.map((program, index) => (
                <div key={program.id} className={`programs-table-row ${program.is_active ? '' : 'archived-row'}`} style={{ '--program-row-index': index }}>
                  <span className="programs-cell-code" data-label="Code">{program.code || '—'}</span>
                  <span className="programs-cell-name" data-label="Program Name">
                    <strong>{program.name || 'Unnamed Program'}</strong>
                  </span>
                  <span className="programs-cell-head" data-label="Program Head">
                    {program.program_head ? (
                      <span className="program-head-label">{program.program_head}</span>
                    ) : (
                      <span className="programs-unassigned">Unassigned</span>
                    )}
                  </span>
                  <span className="programs-cell-faculty" data-label="Faculty" title={`${program.faculty_count ?? 0} faculty member${Number(program.faculty_count ?? 0) === 1 ? '' : 's'}`} aria-label={`${program.faculty_count ?? 0} faculty members`}>
                    <Users className="h-3.5 w-3.5" />
                    {program.faculty_count ?? 0}
                  </span>
                  <span className="programs-cell-status" data-label="Status">
                    <span className={`status-badge ${program.is_active ? 'active' : 'inactive'}`}>
                      {program.is_active ? 'Active' : 'Archived'}
                    </span>
                  </span>
                  <span className="programs-cell-actions" data-label="Actions">
                    <button
                      type="button"
                      className="program-action-btn edit"
                      title="Edit program"
                      aria-label={`Edit ${program.name || program.code || 'program'}`}
                      onClick={() => openEditModal(program)}
                    >
                      <Edit className="h-4 w-4" />
                    </button>
                    {program.is_active ? (
                      <button
                        type="button"
                        className="program-action-btn archive"
                        title="Archive program"
                        aria-label={`Archive ${program.name || program.code || 'program'}`}
                        onClick={() => handleArchiveProgram(program.id)}
                        disabled={archiveLoading}
                      >
                        <Archive className="h-4 w-4" />
                      </button>
                    ) : null}
                  </span>
                </div>
              ))}
            </div>
          )}
          </div>

          <div hidden={academicManagementView !== 'subjects'} className="subject-management-view">
            <div className="programs-insight-strip" aria-label="Subject area summary">
              <article><span>Total Subjects</span><strong>{subjects.length}</strong></article>
              <article><span>Active</span><strong>{subjects.filter((subject) => Number(subject.is_active) === 1).length}</strong></article>
              <article><span>With Coordinator</span><strong>{subjects.filter((subject) => subject.coordinator_faculty_id).length}</strong></article>
              <article><span>Assigned Faculty</span><strong>{subjects.reduce((total, subject) => total + Number(subject.faculty_count || 0), 0)}</strong></article>
            </div>
            <div className="programs-table-container">
              <div className="programs-table-row programs-table-header"><span>Code</span><span>Subject Area</span><span>Coordinator</span><span>Faculty</span><span>Status</span><span>Actions</span></div>
              {subjects.map((subject) => {
                const eligibleCoordinators = faculty.filter((member) => member.subjectAssignments.some((assignment) => Number(assignment.id) === Number(subject.id)));
                return <div className={`programs-table-row ${Number(subject.is_active) ? '' : 'archived-row'}`} key={subject.id}>
                  <span className="programs-cell-code" data-label="Code">{subject.subject_code}</span>
                  <span className="programs-cell-name" data-label="Subject Area"><strong>{subject.subject_name}</strong></span>
                  <span className="programs-cell-head" data-label="Coordinator"><select value={subject.coordinator_faculty_id || ''} disabled={subjectSaving} onChange={(event) => updateSubject(subject, { coordinator_faculty_id: Number(event.target.value) || null })}><option value="">Unassigned</option>{eligibleCoordinators.map((member) => <option key={member.facultyId} value={member.facultyId}>{member.fullName}</option>)}</select></span>
                  <span className="programs-cell-faculty" data-label="Faculty"><Users className="h-3.5 w-3.5" /> {subject.faculty_count}</span>
                  <span className="programs-cell-status" data-label="Status"><span className={`status-badge ${Number(subject.is_active) ? 'active' : 'inactive'}`}>{Number(subject.is_active) ? 'Active' : 'Inactive'}</span></span>
                  <span className="programs-cell-actions" data-label="Actions"><button type="button" className="program-action-btn edit" title="Edit subject area" onClick={() => { const name = window.prompt('Subject area name', subject.subject_name); if (name?.trim()) updateSubject(subject, { subject_name: name.trim() }); }}><Edit className="h-4 w-4" /></button><button type="button" className="program-action-btn archive" title={Number(subject.is_active) ? 'Deactivate subject area' : 'Activate subject area'} onClick={() => updateSubject(subject, { is_active: !Number(subject.is_active) })}><Archive className="h-4 w-4" /></button></span>
                </div>;
              })}
              {subjects.length === 0 && <div className="module-empty"><BookOpen className="h-10 w-10" /><p>No subject areas are configured for this department.</p><button type="button" className="ghost-button" onClick={() => setShowSubjectForm(true)}><Plus className="h-4 w-4" /> Add the first subject area</button></div>}
            </div>
          </div>

          {showSubjectForm && createPortal(
            <div className="people-modal-backdrop is-centered" onClick={(event) => {
              if (event.target !== event.currentTarget || subjectSaving) return;
              setShowSubjectForm(false);
              setSubjectForm({ code: '', name: '' });
            }}>
              <div className="people-modal-panel program-modal-panel subject-area-modal-panel" onClick={(event) => event.stopPropagation()}>
                <div className="box-title">
                  <div>
                    <h2>Add New Subject Area</h2>
                    <span>Create a subject assignment under {department.name}</span>
                  </div>
                  <button type="button" className="modal-icon-close" aria-label="Close Add Subject Area" disabled={subjectSaving} onClick={() => { setShowSubjectForm(false); setSubjectForm({ code: '', name: '' }); }}>
                    <X className="h-5 w-5" />
                  </button>
                </div>
                <form className="program-form" onSubmit={createSubject}>
                  <div className="program-form-grid">
                    <label>
                      Subject Code *
                      <input type="text" ref={subjectCodeRef} value={subjectForm.code} maxLength={30} onChange={(event) => setSubjectForm((current) => ({ ...current, code: event.target.value.toUpperCase() }))} placeholder="e.g., MATH" required />
                    </label>
                    <label>
                      Subject Name *
                      <input type="text" value={subjectForm.name} onChange={(event) => setSubjectForm((current) => ({ ...current, name: event.target.value }))} placeholder="e.g., Mathematics" required />
                    </label>
                    <label className="program-form-full">
                      Department
                      <input type="text" value={`${department.name} (${department.code})`} disabled />
                    </label>
                  </div>
                  <div className="program-form-actions">
                    <button type="button" className="ghost-button" disabled={subjectSaving} onClick={() => { setShowSubjectForm(false); setSubjectForm({ code: '', name: '' }); }}>Cancel</button>
                    <button type="submit" className="primary-button" disabled={subjectSaving}>{subjectSaving ? <><Loader2 className="h-4 w-4 animate-spin" /> Saving...</> : <><Check className="h-4 w-4" /> Save Subject Area</>}</button>
                  </div>
                </form>
              </div>
            </div>,
            document.body,
          )}

          {/* Add/Edit Program Modal */}
          {showProgramModal && (
            <div className="people-modal-backdrop is-centered" onClick={closeProgramModal}>
              <div className="people-modal-panel program-modal-panel" onClick={e => e.stopPropagation()}>
                <div className="box-title">
                  <div>
                    <h2>{editProgramId ? 'Edit Program' : 'Add New Program'}</h2>
                    <span>{editProgramId ? 'Update program information' : `Create a new program under ${department.name}`}</span>
                  </div>
                  <button type="button" className="modal-icon-close" onClick={closeProgramModal}>
                    <X className="h-5 w-5" />
                  </button>
                </div>

                <form className="program-form" onSubmit={handleFormSubmit}>
                  {formError && (
                    <div className="program-form-error">
                      <AlertCircle className="h-4 w-4" />
                      <span>{formError}</span>
                    </div>
                  )}

                  <div className="program-form-grid">
                    <label>
                      Program Code *
                      <input
                        type="text"
                        name="code"
                        value={formData.code}
                        onChange={handleFormChange}
                        placeholder="e.g., BSIT"
                        required
                        maxLength={30}
                      />
                    </label>
                    <label>
                      Program Name *
                      <input
                        type="text"
                        name="name"
                        value={formData.name}
                        onChange={handleFormChange}
                        placeholder="e.g., Bachelor of Science in Information Technology"
                        required
                      />
                    </label>
                    <label>
                      Department
                      <input
                        type="text"
                        value={department.name}
                        disabled
                        style={{ opacity: 0.7, cursor: 'not-allowed' }}
                      />
                    </label>
                    <label>
                      Program Head
                      <select
                        name="program_head_user_id"
                        value={formData.program_head_user_id}
                        onChange={handleFormChange}
                      >
                        <option value="">-- Select Program Head --</option>
                        {departmentScopedHeads.map(head => (
                          <option key={head.id} value={head.id}>
                            {head.full_name}{String(head.email || '').toLowerCase().endsWith('@pmas.local') ? '' : ` (${head.email})`}{head.program ? ` - ${head.program}` : ''}
                          </option>
                        ))}
                      </select>
                      {departmentScopedHeads.length === 0 && (
                        <small className="program-head-helper">
                          No active Program Head account is assigned to {department.code} yet. Add or update the account first.
                        </small>
                      )}
                    </label>
                    {editProgramId && (
                      <label className="program-form-full">
                        Status
                        <select
                          name="is_active"
                          value={formData.is_active}
                          onChange={handleFormChange}
                        >
                          <option value={1}>Active</option>
                          <option value={0}>Archived</option>
                        </select>
                      </label>
                    )}
                  </div>

                  <div className="program-form-actions">
                    <button type="button" className="ghost-button" onClick={closeProgramModal}>
                      Cancel
                    </button>
                    <button type="submit" className="primary-button" disabled={formSubmitting}>
                      {formSubmitting ? 'Saving...' : <><Check className="h-4 w-4" /> {editProgramId ? 'Update Program' : 'Create Program'}</>}
                    </button>
                  </div>
                </form>
              </div>
            </div>
          )}
        </section>
      )}

      <section className="admin-box module-wide department-people-directory dept-profile-page-section" style={{ '--section-index': 4 }}>
        <div className="box-title department-people-directory-head">
          <span className="department-people-heading-icon" aria-hidden="true"><Building2 size={20} /></span>
          <div>
            <h2>Department People</h2>
            <span>
              {activePeopleCategory === 'dean' && (dean ? 'Primary administrative head' : 'No department dean assigned')}
              {activePeopleCategory === 'programHeads' && `${visibleProgramHeads.length} of ${programHeads.length} program head(s)`}
              {activePeopleCategory === 'faculty' && `${visibleFaculty.length} of ${faculty.length} faculty member(s)`}
            </span>
          </div>
          <button type="button" className="department-people-add-account" onClick={addDepartmentAccount}>
            <UserPlus size={17} />
            Add {activePeopleCategory === 'dean' ? 'Dean' : activePeopleCategory === 'programHeads' ? 'Program Head' : 'Faculty'} Account
          </button>
        </div>

        <div className="department-people-tabs" role="tablist" aria-label="Department people categories">
          {peopleTabs.map((item) => (
            <button
              key={item.key}
              type="button"
              role="tab"
              id={`department-people-tab-${item.key}`}
              aria-controls={`department-people-panel-${item.key}`}
              aria-selected={activePeopleCategory === item.key}
              tabIndex={activePeopleCategory === item.key ? 0 : -1}
              ref={(node) => { peopleTabRefs.current[item.key] = node; }}
              className={`department-people-tab ${activePeopleCategory === item.key ? 'active' : ''}`}
              onClick={() => setActivePeopleCategory(item.key)}
              onKeyDown={(event) => handlePeopleTabKeyDown(event, item.key)}
            >
              <span>{item.label}</span>
              <strong>{item.count}</strong>
            </button>
          ))}
        </div>

        {activePeopleCategory !== 'dean' && (
          <div className="department-people-filter-row compact">
            <label className="department-people-search">
              <Search className="h-4 w-4" />
              <input
                type="search"
                value={peopleSearch}
                onChange={(event) => setPeopleSearch(event.target.value)}
                placeholder="Search name, email, program, or status..."
              />
            </label>
            <label>
              <Filter className="h-4 w-4" />
              <select value={peopleProgramFilter} onChange={(event) => setPeopleProgramFilter(event.target.value)}>
                <option value="">All programs</option>
                {peopleProgramOptions.map((program) => <option key={program}>{program}</option>)}
              </select>
            </label>
            <label>
              <Check className="h-4 w-4" />
              <select value={peopleStatusFilter} onChange={(event) => setPeopleStatusFilter(event.target.value)}>
                <option value="">All status</option>
                {peopleStatusOptions.map((status) => <option key={status}>{status}</option>)}
              </select>
            </label>
            <label>
              <ArrowUpDown className="h-4 w-4" />
              <select value={peopleSort} onChange={(event) => setPeopleSort(event.target.value)}>
                <option value="name">Name A-Z</option>
                <option value="program">Program A-Z</option>
                <option value="status">Status A-Z</option>
              </select>
            </label>
            <button
              type="button"
              className="department-people-reset"
              disabled={!peopleFiltersActive}
              onClick={() => {
                setPeopleSearch('');
                setPeopleProgramFilter('');
                setPeopleStatusFilter('');
                setPeopleSort('name');
              }}
            >
              Reset
            </button>
          </div>
        )}

        <div className="department-people-tab-panel" role="tabpanel" id={`department-people-panel-${activePeopleCategory}`} aria-labelledby={`department-people-tab-${activePeopleCategory}`} aria-live="polite">
          {peopleLoading && <PeopleSectionSkeleton category={activePeopleCategory} />}
          {!peopleLoading && <>
          {activePeopleCategory === 'dean' && (
            dean ? (
              <article className="dept-dean-showcase compact">
                <div className="dept-dean-cover">
                  <span>{department.code}</span>
                  <strong>1</strong>
                </div>
                <div className="dept-dean-body">
                  <h3>{department.name}</h3>
                  <small>Department Dean</small>
                  <div className="dept-dean-profile-row">
                    <span className="dept-dean-avatar">
                    {dean.avatar ? <img src={dean.avatar} alt={`${dean.fullName}, Department Dean`} loading="lazy" /> : dean.fullName.charAt(0)}
                    </span>
                    <div className="dept-dean-copy">
                      <strong title={dean.fullName}>{dean.fullName}</strong>
                      <small>Username Code: {dean.userCode || 'Not assigned'}</small>
                    </div>
                    <span className={`status-badge ${deanStatus.toLowerCase()}`} aria-label={`Account status: ${deanStatus}`}>{deanStatus}</span>
                    <div className="department-person-actions">
                      <button className="department-edit-account-button" type="button" onClick={() => editDepartmentAccount(dean)}><Edit className="h-4 w-4" /> Edit Account</button>
                      <button className="rating-view-button" type="button" onClick={() => viewOverallRatings(dean)} disabled={ratingsLoadingId === dean.id}>{ratingsLoadingId === dean.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <BarChart3 className="h-4 w-4" />}{ratingsLoadingId === dean.id ? 'Opening...' : 'Overall Ratings'}</button>
                    </div>
                  </div>
                </div>
              </article>
            ) : (
              <div className="module-empty department-people-empty">
                <p>No department dean is assigned yet.</p>
              </div>
            )
          )}

          {activePeopleCategory === 'programHeads' && (
            <div className="department-people-scroll">
              <div className="dept-profile-grid dept-profile-grid-program-heads">
                {visibleProgramHeads.map((user, index) => (
                  <article key={user.id} className="dept-profile-person-card" style={{ '--people-card-index': index }}>
                    <div className="person-avatar">
                      {user.avatar ? <img src={user.avatar} alt={`${user.fullName}, Program Head`} loading="lazy" /> : user.fullName.charAt(0)}
                    </div>
                    <div className="person-info">
                      <h3 title={user.fullName}>{user.fullName}</h3>
                      <p className="role-badge program-head">{user.program || 'Program'}</p>
                      <div className="person-details">
                        <div className="detail-item">
                          <span aria-hidden="true">#</span>
                          <span>Username Code: {user.userCode || 'Not assigned'}</span>
                        </div>
                        {user.phone && (
                          <div className="detail-item">
                            <Phone className="h-4 w-4" />
                            <span>{user.phone}</span>
                          </div>
                        )}
                      </div>
                    </div>
                    <span className={`status-badge department-card-status ${user.status.toLowerCase()}`} aria-label={`Account status: ${user.status}`}>{user.status}</span>
                    <div className="department-person-actions">
                      <button className="department-edit-account-button" type="button" onClick={() => editDepartmentAccount(user)}><Edit className="h-4 w-4" /> Edit Account</button>
                      <button className="rating-view-button compact" type="button" onClick={() => viewOverallRatings(user)} disabled={ratingsLoadingId === user.id}>{ratingsLoadingId === user.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <BarChart3 className="h-4 w-4" />}{ratingsLoadingId === user.id ? 'Opening...' : 'Overall Ratings'}</button>
                    </div>
                  </article>
                ))}
                {visibleProgramHeads.length === 0 && (
                  <div className="module-empty department-people-empty">
                    <p>{programHeads.length === 0 ? 'No program heads are assigned yet.' : 'No program heads match the current filters.'}</p>
                  </div>
                )}
              </div>
            </div>
          )}

          {activePeopleCategory === 'faculty' && (
            <div className="department-people-scroll">
              <div className="dept-profile-grid dept-profile-grid-faculty">
                {visibleFaculty.map((user, index) => (
                  <article key={user.id} className="dept-profile-person-card" style={{ '--people-card-index': index }}>
                    <div className="person-avatar">
                      {user.avatar ? <img src={user.avatar} alt={`${user.fullName}, Faculty Member`} loading="lazy" /> : user.fullName.charAt(0)}
                    </div>
                    <div className="person-info">
                      <h3 title={user.fullName}>{user.fullName}</h3>
                      <p className="role-badge program-head">{user.program || 'Unassigned Program'}</p>
                      <div className="person-details">
                        <div className="detail-item">
                          <span aria-hidden="true">#</span>
                          <span>Username Code: {user.userCode || 'Not assigned'}</span>
                        </div>
                        {user.phone && (
                          <div className="detail-item">
                            <Phone className="h-4 w-4" />
                            <span>{user.phone}</span>
                          </div>
                        )}
                      </div>
                    </div>
                    <span className={`status-badge department-card-status ${user.status.toLowerCase()}`} aria-label={`Account status: ${user.status}`}>{user.status}</span>
                    <div className="department-person-actions">
                      <button className="department-edit-account-button" type="button" onClick={() => editDepartmentAccount(user)}><Edit className="h-4 w-4" /> Edit Account</button>
                      <button className="rating-view-button compact" type="button" onClick={() => viewOverallRatings(user)} disabled={ratingsLoadingId === user.id}>{ratingsLoadingId === user.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <BarChart3 className="h-4 w-4" />}{ratingsLoadingId === user.id ? 'Opening...' : 'Overall Ratings'}</button>
                    </div>
                  </article>
                ))}
                {visibleFaculty.length === 0 && (
                  <div className="module-empty department-people-empty">
                    <p>{faculty.length === 0 ? 'No faculty members are assigned yet.' : 'No faculty members match the current filters.'}</p>
                  </div>
                )}
              </div>
            </div>
          )}
          </>}
        </div>
      </section>

    </section>
  );
}

function ProgramTableSkeleton() {
  return <div className="programs-table-skeleton" role="status" aria-live="polite" aria-label="Loading programs">{[0, 1, 2, 3].map((item) => <span key={item} />)}<span className="sr-only">Loading programs...</span></div>;
}

function PeopleSectionSkeleton({ category }) {
  const count = category === 'dean' ? 1 : 3;
  return <div className={`department-people-skeleton ${category === 'dean' ? 'is-dean' : ''}`} role="status" aria-label="Loading department people">{Array.from({ length: count }, (_, item) => <article key={item}><span /><div><i /><i /><i /></div></article>)}<span className="sr-only">Loading department people...</span></div>;
}
