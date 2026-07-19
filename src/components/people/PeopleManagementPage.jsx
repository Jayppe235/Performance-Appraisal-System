import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useLocation, useNavigate } from 'react-router-dom';
import { DayPicker } from 'react-day-picker';
import { format } from 'date-fns';
import 'react-day-picker/style.css';
import { Archive, BadgeCheck, Building2, CalendarDays, Camera, Check, ChevronDown, ChevronLeft, ChevronRight, Copy, Download, Edit3, Eye, EyeOff, GraduationCap, Image as ImageIcon, Info, Layers3, Loader2, Lock, Mail, Plus, RefreshCw, RotateCcw, Save, Search, Shield, ShieldCheck, Sparkles, Trash2, Upload, User, UserPlus, Users, X } from 'lucide-react';
import { addToast } from '../common/Toast.jsx';
import { confirmDeleteData, confirmProceed, confirmSaveChanges } from '../common/ConfirmationModal.jsx';
import apiFetch from '../../data/api.js';
import { apiUrl, assetUrl } from '../../data/apiBase.js';

const API_BASE = '/api/people.php';
const DEFAULT_DEPARTMENT_LOGO = assetUrl('assets/images/ndmc-seal.png');

const emptyDepartment = {
  id: null,
  code: '',
  name: '',
  dean: '',
  logo: DEFAULT_DEPARTMENT_LOGO,
  description: '',
  department_type: 'Academic Department',
  status: 'Active',
};

const emptyUser = {
  id: null,
  userCode: '',
  birthDate: '',
  fullName: '',
  role: '',
  department: '',
  program: '',
  email: '',
  emailVerified: false,
  password: '',
  status: 'Active',
  avatar: '',
};

const emptyInlineDepartment = { name: '', code: '', description: '', status: 'Active' };
const emptyInlineProgram = { name: '', code: '', description: '', status: 'Active' };

const roleDescriptions = {
  Admin: 'Manages users, evaluation periods, monitoring, and reports.',
  VPAA: 'Evaluates Deans and monitors department-level performance.',
  Dean: 'Manages and evaluates users within the assigned department.',
  'Program Head': 'Manages and evaluates faculty within the assigned program.',
  Faculty: 'Participates in self-assessment, peer evaluation, and assigned evaluations.',
};

const roleOptions = ['VPAA', 'Dean', 'Program Head', 'Faculty'];
const accountRoleOptions = ['Admin', ...roleOptions];
// 'Admin' is intentionally excluded — only one admin may exist.
const statusOptions = ['Active', 'Inactive'];

function normalizeDepartmentName(rawName) {
  const name = String(rawName || '').trim();
  const lower = name.toLowerCase();

  if (lower === 'cite' || lower === 'cit' || lower === 'computer studies' || lower === 'information technology' || lower === 'information technology education' || lower === 'computer science' || lower === 'computer engineering' || lower.includes('information technology') || lower.includes('computer')) {
    return 'College of Information Technology and Engineering';
  }

  return name;
}

function departmentAliases(department) {
  const code = String(department?.code || department?.department_code || '').trim();
  const name = String(department?.name || department?.department_name || department || '').trim();
  const aliases = [code, name, normalizeDepartmentName(code), normalizeDepartmentName(name)];
  const lowerName = name.toLowerCase();
  const upperCode = code.toUpperCase();

  if (upperCode === 'CITE' || upperCode === 'CIT' || lowerName.includes('information technology') || lowerName.includes('computer')) {
    aliases.push('CITE', 'CIT', 'Computer Studies', 'College of Information Technology Engineering', 'College of Information Technology and Engineering');
  }

  return [...new Set(aliases.map((value) => String(value || '').trim()).filter(Boolean))];
}

function departmentsMatch(left, right, departments = []) {
  const leftAliases = new Set(departmentAliases(left).map((value) => value.toLowerCase()));
  const rightAliases = new Set(departmentAliases(right).map((value) => value.toLowerCase()));

  departments.forEach((department) => {
    const aliases = departmentAliases(department).map((value) => value.toLowerCase());
    if (aliases.some((alias) => leftAliases.has(alias))) aliases.forEach((alias) => leftAliases.add(alias));
    if (aliases.some((alias) => rightAliases.has(alias))) aliases.forEach((alias) => rightAliases.add(alias));
  });

  return [...leftAliases].some((alias) => rightAliases.has(alias));
}

function normalizeProgramKey(value) {
  return String(value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function programsMatch(left, right) {
  const leftKey = normalizeProgramKey(left);
  const rightKey = normalizeProgramKey(right);
  return Boolean(leftKey && rightKey && leftKey === rightKey);
}

function userSearchText(user, departments = []) {
  const matchingDepartment = departments.find((department) => departmentsMatch(user.department, department, departments));
  return [
    user.fullName,
    user.userCode,
    user.email,
    user.department,
    user.role,
    user.program,
    ...(matchingDepartment ? departmentAliases(matchingDepartment) : departmentAliases(user.department)),
  ].join(' ').toLowerCase();
}

function programsForDepartment(department, allPrograms) {
  if (!department) return [];
  const departmentId = Number(department.id || 0);
  const code = String(department.code || '').toUpperCase();
  const name = String(department.name || department || '').toLowerCase();
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

function roleToApiRole(role) {
  switch (role) {
    case 'Admin': return 'admin_hr';
    case 'VPAA': return 'vpaa';
    case 'Dean': return 'dean';
    case 'Program Head': return 'program_head';
    case 'Faculty': return 'teacher';
    default: return '';
  }
}

function apiRoleToRole(apiRole) {
  switch (apiRole) {
    case 'admin_hr': return 'Admin';
    case 'vpaa': return 'VPAA';
    case 'dean': return 'Dean';
    case 'program_head': return 'Program Head';
    case 'teacher': return 'Faculty';
    default: return '';
  }
}

function apiUserToFormUser(apiUser) {
  return {
    id: Number(apiUser.id),
    userCode: String(apiUser.user_code || ''),
    birthDate: apiUser.birth_date || '',
    emailVerified: Boolean(apiUser.email_verified_at),
    mustChangePassword: apiUser.must_change_password == 1,
    fullName: apiUser.full_name || '',
    email: apiUser.email || '',
    password: '',
    role: apiRoleToRole(apiUser.role),
    department: normalizeDepartmentName(apiUser.department || ''),
    program: apiUser.program || '',
    status: apiUser.is_active == 1 ? 'Active' : 'Inactive',
    avatar: assetUrl(apiUser.profile_image || ''),
    createdAt: apiUser.created_at || '',
    lastLoginAt: apiUser.last_login_at || '',
    pendingPasswordResetRequestId: Number(apiUser.pending_password_reset_request_id || 0),
  };
}

function normalizeApiDepartment(department) {
  return {
    ...department,
    logo: assetUrl(department.logo || 'assets/images/ndmc-seal.png'),
  };
}

export default function PeopleManagementPage({ archiveOnly = false }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [departments, setDepartments] = useState([]);
  const [departmentsLoading, setDepartmentsLoading] = useState(true);
  const [departmentsError, setDepartmentsError] = useState('');
  const [allPrograms, setAllPrograms] = useState([]);
  const [programsLoading, setProgramsLoading] = useState(true);
  const [programsError, setProgramsError] = useState('');
  const [users, setUsers] = useState([]);
  const [nextUserCode, setNextUserCode] = useState('2025001');
  const [usersLoading, setUsersLoading] = useState(true);
  const [usersError, setUsersError] = useState('');
  const [archivedDepartments, setArchivedDepartments] = useState([]);
  const [archivedUsers, setArchivedUsers] = useState([]);
  const [filters, setFilters] = useState({ search: '', department: '', program: '', role: '', status: '' });
  const [modal, setModal] = useState(null);
  const [departmentForm, setDepartmentForm] = useState(emptyDepartment);
  const [userForm, setUserForm] = useState(emptyUser);
  const [formError, setFormError] = useState('');
  const [formMessage, setFormMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const [userAvatarFile, setUserAvatarFile] = useState(null);
  const [departmentLogoFile, setDepartmentLogoFile] = useState(null);
  const [departmentTouched, setDepartmentTouched] = useState({});
  const [showPassword, setShowPassword] = useState(false);
  const [passwordExpanded, setPasswordExpanded] = useState(false);
  const [confirmPassword, setConfirmPassword] = useState('');
  const originalUserFormRef = useRef(emptyUser);
  const [touched, setTouched] = useState({});
  const [inlineModal, setInlineModal] = useState(null);
  const [inlineDepartment, setInlineDepartment] = useState(emptyInlineDepartment);
  const [inlineProgram, setInlineProgram] = useState(emptyInlineProgram);
  const [inlineSaving, setInlineSaving] = useState(false);
  const departmentManagementRef = useRef(null);
  const accountManagementRef = useRef(null);
  const [accountDetails, setAccountDetails] = useState(null);
  const [accountSort, setAccountSort] = useState({ key: 'fullName', direction: 'asc' });
  const [accountPage, setAccountPage] = useState(1);
  const [accountPageSize, setAccountPageSize] = useState(10);
  const selectedDepartment = departments.find((department) => department.name === userForm.department);
  const selectedProgramOptions = programsForDepartment(selectedDepartment || userForm.department, allPrograms);
  const roleRequiresDepartment = ['Dean', 'Program Head', 'Faculty'].includes(userForm.role);
  const roleUsesProgram = ['Dean', 'Program Head', 'Faculty'].includes(userForm.role);
  const roleRequiresProgram = ['Faculty', 'Program Head'].includes(userForm.role);
  const isEditing = !!userForm.id;
  const normalizedEmail = userForm.email.trim().toLowerCase();
  const passwordChecks = { length: userForm.password.length >= 8, upper: /[A-Z]/.test(userForm.password), lower: /[a-z]/.test(userForm.password), number: /\d/.test(userForm.password) };
  const passwordScore = Object.values(passwordChecks).filter(Boolean).length;
  const passwordStrength = passwordScore <= 2 ? 'Weak' : passwordScore === 3 ? 'Fair' : 'Strong';
  const summaryReady = Boolean(userForm.role && (!roleRequiresDepartment || userForm.department) && (!roleRequiresProgram || userForm.program));
  const viewParams = useMemo(() => new URLSearchParams(location.search), [location.search]);
  const peopleView = viewParams.get('view') || '';
  const peopleNeeds = viewParams.get('needs') || '';
  const peopleFilter = viewParams.get('filter') || '';
  const showDepartmentDirectory = !peopleView || peopleView === 'departments';
  const showProgramDirectory = peopleView === 'programs';
  const showUserDirectory = !peopleView || peopleView === 'users';
  const managingAccounts = peopleView === 'users';
  const requestedResetId = Number(viewParams.get('reset_request') || 0);

  const openAccountManagement = useCallback(() => {
    navigate('/admin/people?view=users#account-management');
    window.setTimeout(() => accountManagementRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
  }, [navigate]);

  const openDepartmentManagement = useCallback(() => {
    navigate('/admin/people?view=departments#department-management');
    window.setTimeout(() => {
      departmentManagementRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 80);
  }, [navigate]);

  useEffect(() => {
    if (peopleView !== 'departments') return;
    const frame = window.requestAnimationFrame(() => {
      departmentManagementRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    return () => window.cancelAnimationFrame(frame);
  }, [peopleView]);

  useEffect(() => {
    if (!requestedResetId || usersLoading) return;
    const requestedUser = users.find((user) => user.pendingPasswordResetRequestId === requestedResetId);
    if (requestedUser) openUserForm(requestedUser);
  }, [requestedResetId, usersLoading, users]);

  useEffect(() => {
    if (!managingAccounts) return;
    const frame = window.requestAnimationFrame(() => accountManagementRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    return () => window.cancelAnimationFrame(frame);
  }, [managingAccounts]);

  // ── Fetch users from API on mount ─────────────────────────────────────
  const fetchUsers = useCallback(async () => {
    setUsersLoading(true);
    setUsersError('');
    try {
      const payload = await apiFetch(API_BASE);
      const mappedUsers = Array.isArray(payload.users) ? payload.users.map(apiUserToFormUser) : [];
      setUsers(mappedUsers);
      if (payload.next_user_code) setNextUserCode(String(payload.next_user_code));
    } catch (error) {
      setUsersError(error.message);
    } finally {
      setUsersLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchUsers();
  }, [fetchUsers]);

  const fetchArchivedUsers = useCallback(async () => {
    try {
      const payload = await apiFetch(`${API_BASE}?active_only=0`);
      const mappedUsers = Array.isArray(payload.users)
        ? payload.users.map(apiUserToFormUser).filter((user) => user.status === 'Inactive')
        : [];
      setArchivedUsers(mappedUsers);
    } catch (error) {
      setUsersError(error.message);
    }
  }, []);

  const fetchDepartments = useCallback(async () => {
    setDepartmentsLoading(true);
    setDepartmentsError('');
    try {
      const payload = await apiFetch('/api/departments.php');
      setDepartments(payload.ok && Array.isArray(payload.data) ? payload.data.map(normalizeApiDepartment) : []);
    } catch (error) {
      setDepartmentsError(error.message || 'Failed to load departments from database.');
      setDepartments([]);
    } finally {
      setDepartmentsLoading(false);
    }
  }, []);

  const fetchArchivedDepartments = useCallback(async () => {
    try {
      const payload = await apiFetch('/api/departments.php?include_inactive=1');
      const archived = payload.ok && Array.isArray(payload.data)
        ? payload.data.map(normalizeApiDepartment).filter((department) => Number(department.is_active) === 0)
        : [];
      setArchivedDepartments(archived);
    } catch (error) {
      setDepartmentsError(error.message || 'Failed to load archived departments from database.');
    }
  }, []);

  const fetchPrograms = useCallback(async () => {
    setProgramsLoading(true);
    setProgramsError('');
    try {
      const payload = await apiFetch('/api/programs.php');
      setAllPrograms(payload.ok && Array.isArray(payload.data) ? payload.data : []);
    } catch (error) {
      setProgramsError(error.message || 'Failed to load programs from database.');
      setAllPrograms([]);
    } finally {
      setProgramsLoading(false);
    }
  }, []);

  const refreshDirectories = useCallback(async () => {
    await Promise.all([fetchUsers(), fetchArchivedUsers(), fetchDepartments(), fetchArchivedDepartments(), fetchPrograms()]);
  }, [fetchArchivedDepartments, fetchArchivedUsers, fetchDepartments, fetchPrograms, fetchUsers]);

  useEffect(() => {
    const nextRole = viewParams.get('role') || '';
    const nextStatus = viewParams.get('status') || '';
    const nextSearch = viewParams.get('search') || '';
    setFilters((current) => ({
      ...current,
      role: accountRoleOptions.includes(nextRole) ? nextRole : '',
      status: statusOptions.includes(nextStatus) ? nextStatus : '',
      search: nextSearch,
    }));
  }, [viewParams]);

  useEffect(() => {
    fetchArchivedUsers();
    fetchDepartments();
    fetchArchivedDepartments();
    fetchPrograms();
  }, [fetchArchivedDepartments, fetchArchivedUsers, fetchDepartments, fetchPrograms]);

  const accountUsers = useMemo(() => {
    const source = managingAccounts ? [...users, ...archivedUsers] : users;
    return [...new Map(source.map((user) => [user.id, user])).values()];
  }, [archivedUsers, managingAccounts, users]);

  const filteredUsers = useMemo(() => accountUsers.filter((user) => {
    const searchText = userSearchText(user, departments);
    return (!filters.search || searchText.includes(filters.search.toLowerCase()))
      && (!filters.department || departmentsMatch(user.department, filters.department, departments))
      && (!filters.program || programsMatch(user.program, filters.program))
      && (!filters.role || user.role === filters.role)
      && (!filters.status || user.status === filters.status);
  }), [accountUsers, departments, filters]);

  const sortedUsers = useMemo(() => [...filteredUsers].sort((left, right) => {
    const leftValue = String(left[accountSort.key] || '').toLowerCase();
    const rightValue = String(right[accountSort.key] || '').toLowerCase();
    const order = leftValue.localeCompare(rightValue);
    return accountSort.direction === 'asc' ? order : -order;
  }), [accountSort, filteredUsers]);
  const accountPageCount = Math.max(1, Math.ceil(sortedUsers.length / accountPageSize));
  const pagedUsers = managingAccounts ? sortedUsers.slice((accountPage - 1) * accountPageSize, accountPage * accountPageSize) : sortedUsers;
  const accountMetrics = useMemo(() => ({ total: accountUsers.length, active: accountUsers.filter((user) => user.status === 'Active').length, inactive: accountUsers.filter((user) => user.status === 'Inactive').length, admins: accountUsers.filter((user) => user.role === 'Admin').length }), [accountUsers]);
  const accountPrograms = useMemo(() => allPrograms.filter((program) => !filters.department || departmentsMatch(program.department_name || program.department_code, filters.department, departments)), [allPrograms, departments, filters.department]);

  const filteredDepartments = useMemo(() => departments.filter((department) => {
    const searchText = `${department.name} ${department.code} ${department.dean}`.toLowerCase();
    if (peopleNeeds === 'dean' && String(department.dean || '').trim() !== '') return false;
    return (!filters.search || searchText.includes(filters.search.toLowerCase()))
      && (!filters.department || department.name === filters.department);
  }), [departments, filters, peopleNeeds]);

  const filteredPrograms = useMemo(() => allPrograms.filter((program) => {
    const programName = program.name || program.program_name || '';
    const programCode = program.code || program.program_code || '';
    const departmentCode = program.department_code || '';
    const programHead = program.program_head_name || program.program_head || '';
    const searchText = `${programName} ${programCode} ${departmentCode} ${programHead}`.toLowerCase();
    if (peopleNeeds === 'head' && String(programHead || '').trim() !== '') return false;
    return !filters.search || searchText.includes(filters.search.toLowerCase());
  }), [allPrograms, filters.search, peopleNeeds]);

  function updateFilter(name, value) {
    setFilters((current) => ({ ...current, [name]: value }));
    setAccountPage(1);
  }

  function toggleAccountSort(key) {
    setAccountSort((current) => ({ key, direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc' }));
    setAccountPage(1);
  }

  function openDepartmentForm(department = emptyDepartment) {
    setDepartmentForm({ ...emptyDepartment, ...department, status: Number(department.is_active ?? 1) === 1 ? 'Active' : 'Inactive' });
    setDepartmentLogoFile(null);
    setDepartmentTouched({});
    setFormError('');
    setFormMessage('');
    setModal('department');
  }

  const departmentDirty = Object.keys(departmentTouched).length > 0 || Boolean(departmentLogoFile);
  const touchDepartment = (name) => setDepartmentTouched((current) => ({ ...current, [name]: true }));

  async function closeDepartmentForm() {
    if (saving) return;
    if (departmentDirty) {
      const discard = await confirmProceed({ title: 'Discard unsaved changes?', message: 'You have entered department information that has not been saved.', cancelText: 'Continue Editing', confirmText: 'Discard Changes' });
      if (!discard) return;
    }
    setModal(null);
  }

  function removeDepartmentLogo() {
    if (departmentForm.logo?.startsWith('blob:')) URL.revokeObjectURL(departmentForm.logo);
    setDepartmentLogoFile(null);
    setDepartmentForm((current) => ({ ...current, logo: DEFAULT_DEPARTMENT_LOGO }));
    touchDepartment('logo');
  }

  function openUserForm(user = emptyUser) {
    const formDepartment = departments.find((department) => departmentsMatch(user.department, department, departments));
    const formProgram = programsForDepartment(formDepartment || user.department, allPrograms)
      .find((program) => programsMatch(program.code, user.program));
    const nextForm = { ...emptyUser, ...user, userCode: user.id ? user.userCode : nextUserCode, program: formProgram?.code || user.program || '', password: '' };
    setUserForm(nextForm);
    originalUserFormRef.current = nextForm;
    setUserAvatarFile(null);
    setTouched({});
    setShowPassword(false);
    setPasswordExpanded(false);
    setConfirmPassword('');
    setFormError('');
    setFormMessage('');
    setModal('user');
  }

  async function changeNextUserCode() {
    const value = window.prompt('Enter the next automatic numeric username code.', nextUserCode);
    if (value === null || value === nextUserCode) return;
    if (!/^[1-9]\d*$/.test(value)) { addToast({ type: 'error', text: 'Enter a positive numeric username code.' }); return; }
    if (!window.confirm(`Set ${value} as the next automatic username code?`)) return;
    try {
      const result = await apiFetch(API_BASE, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'set_next_user_code', next_user_code: value }) });
      setNextUserCode(String(result.next_user_code));
      addToast({ type: 'success', text: `Next username code set to ${result.next_user_code}.` });
    } catch (error) { addToast({ type: 'error', text: error.message }); }
  }

  async function resetUserChanges() {
    const confirmed = await confirmProceed({ title: 'Reset unsaved changes?', message: 'Your unsaved account changes will be restored to their original values.', cancelText: 'Keep Editing', confirmText: 'Reset Changes' });
    if (!confirmed) return;
    setUserForm({ ...originalUserFormRef.current });
    setUserAvatarFile(null);
    setTouched({});
    setConfirmPassword('');
    setPasswordExpanded(false);
    setFormError('');
  }

  async function completePasswordReset() {
    const requestId = userForm.pendingPasswordResetRequestId;
    if (!requestId || saving) return;
    const confirmed = await confirmProceed({ title: 'Reset this account password?', message: `The temporary password will be ${userForm.birthDate || 'the recorded birth date'}, and the user must replace it after signing in.`, cancelText: 'Cancel', confirmText: 'Reset Password' });
    if (!confirmed) return;
    setSaving(true); setFormError('');
    try {
      const result = await apiFetch(API_BASE, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'complete_password_reset', request_id: requestId }) });
      setUserForm((current) => ({ ...current, pendingPasswordResetRequestId: 0, mustChangePassword: true }));
      await refreshDirectories();
      addToast({ type: 'success', text: result.message });
    } catch (error) { setFormError(error.message || 'Unable to reset this password.'); }
    finally { setSaving(false); }
  }

  const userFormDirty = Object.keys(touched).length > 0 || Boolean(userAvatarFile);

  async function closeUserForm() {
    if (saving) return;
    if (userFormDirty) {
      const discard = await confirmProceed({
        title: 'Discard unsaved changes?',
        message: 'You have entered account information that has not been saved.',
        cancelText: 'Continue Editing',
        confirmText: 'Discard Changes',
      });
      if (!discard) return;
    }
    setInlineModal(null);
    setModal(null);
  }

  function touch(name) {
    setTouched((current) => ({ ...current, [name]: true }));
  }

  function generatePassword() {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const numbers = '23456789';
    const symbols = '!@#$%';
    const all = upper + lower + numbers + symbols;
    const pick = (chars) => chars[Math.floor(Math.random() * chars.length)];
    const value = [pick(upper), pick(lower), pick(numbers), pick(symbols), ...Array.from({ length: 10 }, () => pick(all))]
      .sort(() => Math.random() - 0.5).join('');
    setUserForm((current) => ({ ...current, password: value }));
    window.setTimeout(() => setConfirmPassword(value), 0);
    setShowPassword(true);
    touch('password');
  }

  async function copyPassword() {
    if (!userForm.password) return;
    await navigator.clipboard.writeText(userForm.password);
    addToast({ type: 'success', text: 'Secure password copied.' });
  }

  async function removeUserPhoto() {
    const confirmed = await confirmProceed({ title: 'Remove profile photo?', message: 'The account will return to the generated initials avatar.', cancelText: 'Cancel', confirmText: 'Remove Photo' });
    if (!confirmed) return;
    if (userForm.avatar?.startsWith('blob:')) URL.revokeObjectURL(userForm.avatar);
    setUserAvatarFile(null);
    setUserForm((current) => ({ ...current, avatar: '' }));
    touch('avatar');
  }

  async function createInlineDepartment(event) {
    event.preventDefault();
    if (inlineSaving) return;
    setInlineSaving(true);
    try {
      const result = await apiFetch('/api/departments.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: inlineDepartment.code.trim().toUpperCase(), name: inlineDepartment.name.trim(), is_active: inlineDepartment.status === 'Active' }),
      });
      if (!result.ok) throw new Error(result.message || 'Failed to add department.');
      await fetchDepartments();
      setUserForm((current) => ({ ...current, department: inlineDepartment.name.trim(), program: '' }));
      touch('department');
      setInlineModal(null);
      setInlineDepartment(emptyInlineDepartment);
      addToast({ type: 'success', text: 'Department added successfully.' });
    } catch (error) { setFormError(error.message); } finally { setInlineSaving(false); }
  }

  async function createInlineProgram(event) {
    event.preventDefault();
    if (inlineSaving || !selectedDepartment) return;
    setInlineSaving(true);
    try {
      const result = await apiFetch('/api/programs.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ department_id: selectedDepartment.id, code: inlineProgram.code.trim().toUpperCase(), name: inlineProgram.name.trim(), is_active: inlineProgram.status === 'Active' }),
      });
      if (!result.ok) throw new Error(result.message || 'Failed to add program.');
      await fetchPrograms();
      setUserForm((current) => ({ ...current, program: inlineProgram.code.trim().toUpperCase() }));
      touch('program');
      setInlineModal(null);
      setInlineProgram(emptyInlineProgram);
      addToast({ type: 'success', text: 'Program added successfully.' });
    } catch (error) { setFormError(error.message); } finally { setInlineSaving(false); }
  }

  function getRoleAssignmentMessage(nextUser) {
    if (
      nextUser.role === 'VPAA' &&
      users.some((user) => user.id !== nextUser.id && user.role === 'VPAA')
    ) {
      return 'Only one VPAA account is allowed in the system.';
    }

    if (
      nextUser.role === 'Dean' &&
      nextUser.department &&
      users.some((user) => user.id !== nextUser.id && user.role === 'Dean' && user.department === nextUser.department)
    ) {
      return 'There is already a Dean assigned to this department.';
    }

    if (
      nextUser.role === 'Program Head' &&
      nextUser.program &&
      users.some((user) =>
        user.id !== nextUser.id &&
        user.role === 'Program Head' &&
        programsMatch(user.program, nextUser.program)
      )
    ) {
      return 'There is already a Program Head assigned to this program/course.';
    }

    return '';
  }

  function warnRoleAssignment(nextUser) {
    const message = getRoleAssignmentMessage(nextUser);
    if (!message) {
      return;
    }

    setFormError(message);
  }

  function updateUserRole(role) {
    const leavingProgramHeadForDean = userForm.role === 'Program Head' && role === 'Dean';
    const nextUser = {
      ...userForm,
      role,
      program: ['Dean', 'Faculty', 'Program Head'].includes(role) && !leavingProgramHeadForDean ? userForm.program : '',
    };

    setUserForm(nextUser);
    if (leavingProgramHeadForDean) touch('program');
    warnRoleAssignment(nextUser);
  }

  function updateUserDepartment(departmentName) {
    const department = departments.find((item) => item.name === departmentName);
    const availablePrograms = programsForDepartment(department || departmentName, allPrograms);
    const matchingProgram = availablePrograms.find((program) => programsMatch(program.code, userForm.program));
    const nextProgram = matchingProgram?.code || '';
    const nextUser = {
      ...userForm,
      department: departmentName,
      program: nextProgram,
    };

    setUserForm(nextUser);
    warnRoleAssignment(nextUser);
  }

  function updateUserProgram(program) {
    const nextUser = { ...userForm, program };
    setUserForm(nextUser);
    warnRoleAssignment(nextUser);
  }

  async function saveDepartment(event) {
    event.preventDefault();
    if (saving) return;
    setFormError('');
    setDepartmentTouched({ code: true, name: true });
    const cleanCode = departmentForm.code.trim().toUpperCase();
    const cleanName = departmentForm.name.trim().replace(/\s+/g, ' ');
    if (!/^[A-Z0-9-]{2,10}$/.test(cleanCode)) { setFormError('Department code must contain 2 to 10 letters, numbers, or hyphens.'); return; }
    if (!cleanName) { setFormError('Department name is required.'); return; }
    const duplicate = departments.find((item) => item.id !== departmentForm.id && (item.code.toLowerCase() === cleanCode.toLowerCase() || item.name.toLowerCase() === cleanName.toLowerCase()));
    if (duplicate) { setFormError(duplicate.code.toLowerCase() === cleanCode.toLowerCase() ? 'This department code already exists.' : 'This department name already exists.'); return; }
    const confirmed = await confirmSaveChanges();
    if (!confirmed) return;
    setSaving(true);

    try {
      const deanUser = users.find((user) => user.role === 'Dean' && user.fullName === departmentForm.dean);
      const payload = {
        id: departmentForm.id,
        code: cleanCode,
        name: cleanName,
        dean_user_id: deanUser?.id || null,
        description: departmentForm.description.trim(),
        department_type: departmentForm.department_type,
        is_active: departmentForm.status === 'Active',
      };

      let result;
      if (departmentLogoFile) {
        const formData = new FormData();
        Object.entries(payload).forEach(([key, value]) => { if (value !== null && value !== undefined) formData.append(key, value === true ? '1' : value === false ? '0' : String(value)); });
        formData.append('department_logo', departmentLogoFile);
        if (departmentForm.id) {
          formData.append('id', departmentForm.id);
          formData.append('_method', 'PUT');
        }

        const response = await fetch(apiUrl('/api/departments.php'), {
          method: 'POST',
          credentials: 'include',
          body: formData,
        });
        if (response.status === 401 || response.status === 403 || (response.headers.get('content-type') || '').includes('text/html')) {
          try { localStorage.removeItem('dipascaf-session'); } catch (_) {}
          window.location.href = '/login';
          return;
        }
        const text = await response.text();
        result = text.trim()
          ? JSON.parse(text)
          : { ok: false, message: 'Departments API returned an empty response.' };
        setDepartmentLogoFile(null);
      } else {
        result = await apiFetch('/api/departments.php', {
          method: departmentForm.id ? 'PUT' : 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
      }
      if (!result.ok) {
        throw new Error(result.message || 'Failed to save department.');
      }

      setModal(null);
      await refreshDirectories();
      addToast({ type: 'success', text: deanUser ? `${cleanName} has been saved and assigned to ${deanUser.fullName}.` : 'Department saved successfully. No dean assigned yet.' });
    } catch (error) {
      setFormError(error.message || 'Failed to save department.');
    } finally {
      setSaving(false);
    }
  }

  async function saveUser(event) {
    event.preventDefault();
    if (saving) return;
    setFormError('');
    setFormMessage('');

    try {
      const departmentProgramCodes = selectedProgramOptions.map((program) => normalizeProgramKey(program.code));
      const selectedProgramKey = normalizeProgramKey(userForm.program);
      setTouched({ fullName: true, email: true, userCode: true, birthDate: true, password: true, role: true, department: true, program: true });

      if (!userForm.fullName.trim()) {
        setFormError('Full name is required.');
        return;
      }
      if (!/^\S+@\S+\.\S+$/.test(normalizedEmail)) {
        setFormError('Enter a valid email address.');
        return;
      }
      if (users.some((user) => user.id !== userForm.id && user.email.trim().toLowerCase() === normalizedEmail)) {
        setFormError('An account with this email already exists.');
        return;
      }
      if (!/^[1-9]\d*$/.test(userForm.userCode)) { setFormError('Username code must contain positive numeric digits only.'); return; }
      if (users.some((user) => user.id !== userForm.id && user.userCode === userForm.userCode)) { setFormError('This username code is already assigned to another account. Please enter a different code.'); return; }
      if (!isEditing && !/^\d{4}-\d{2}-\d{2}$/.test(userForm.birthDate)) { setFormError('A valid birth date is required.'); return; }

      // Local validation (matches schema rules)
      if (isEditing && userForm.password && passwordScore < 4) {
        setFormError('Password must contain at least 8 characters, uppercase and lowercase letters, and a number.');
        return;
      }
      if (userForm.password && userForm.password !== confirmPassword) {
        setFormError('New passwords do not match.');
        return;
      }

      if (!userForm.role) {
        setFormError('Select a role for this account.');
        return;
      }

      const roleAssignmentMessage = getRoleAssignmentMessage(userForm);
      if (roleAssignmentMessage) {
        setFormError(roleAssignmentMessage);
        return;
      }

      if (roleRequiresDepartment && !userForm.department) {
        setFormError('Select a department assignment for this account.');
        return;
      }

      if (roleRequiresProgram && !userForm.program) {
        setFormError('Select a program/course for this account.');
        return;
      }

      if (roleRequiresProgram && !departmentProgramCodes.includes(selectedProgramKey)) {
        setFormError('The selected program/course does not belong to this department.');
        return;
      }

      if (userForm.role === 'Dean' && userForm.program && !departmentProgramCodes.includes(selectedProgramKey)) {
        setFormError('The selected program/course does not belong to this department.');
        return;
      }

      const confirmed = await confirmSaveChanges();
      if (!confirmed) return;
      setSaving(true);

      let response;
      if (userAvatarFile) {
        // Use FormData when there's a profile image
        const formData = new FormData();
        formData.append('full_name', userForm.fullName.trim().replace(/\s+/g, ' '));
        formData.append('email', normalizedEmail);
        formData.append('user_code', userForm.userCode);
        formData.append('birth_date', userForm.birthDate);
        formData.append('role', roleToApiRole(userForm.role));
        formData.append('phone', '');
        formData.append('department', userForm.department || '');
        formData.append('program', userForm.program || '');
        formData.append('is_active', userForm.status === 'Active' ? '1' : '0');
        formData.append('profile_image', userAvatarFile);

        if (isEditing) {
          formData.append('id', userForm.id);
          formData.append('_method', 'PUT');
          if (userForm.password) formData.append('password', userForm.password);
        }

        response = await fetch(apiUrl(API_BASE), {
          method: 'POST',
          credentials: 'include',
          body: formData,
        });
        // Check for unauth redirect from PHP API
        if (response.status === 401 || response.status === 403 || (response.headers.get('content-type') || '').includes('text/html')) {
          try { localStorage.removeItem('dipascaf-session'); } catch (_) {}
          window.location.href = '/login';
          return;
        }
        const text = await response.text();
        response = text.trim()
          ? JSON.parse(text)
          : { ok: false, message: 'People API returned an empty response.' };
        setUserAvatarFile(null);
      } else {
        const payload = {
          full_name: userForm.fullName.trim().replace(/\s+/g, ' '),
          email: normalizedEmail,
          user_code: userForm.userCode,
          birth_date: userForm.birthDate,
          role: roleToApiRole(userForm.role),
          phone: '',
          department: userForm.department || null,
          program: userForm.program || null,
          is_active: userForm.status === 'Active',
        };

        if (isEditing) {
          // UPDATE
          payload.id = userForm.id;
          if (userForm.password) {
            payload.password = userForm.password;
          }
          response = await apiFetch(API_BASE, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
        } else {
          // CREATE
          response = await apiFetch(API_BASE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
        }
      }

      const result = response;

      if (!result.ok) {
        setFormError(result.message || 'Failed to save account.');
        setSaving(false);
        return;
      }

      const assignment = userForm.program ? ` as ${userForm.role} of ${userForm.program}` : userForm.department ? ` as ${userForm.role} of ${userForm.department}` : '';
      addToast({ type: 'success', text: isEditing ? 'Account updated successfully.' : `Account created successfully. ${userForm.fullName.trim()} has been assigned${assignment}.` });
      setModal(null);
      await refreshDirectories();
    } catch (error) {
      setFormError('Network error: ' + error.message);
    } finally {
      setSaving(false);
    }
  }

  async function archiveUser(id) {
    const target = users.find((user) => user.id === id);
    const confirmed = await confirmDeleteData({
      title: 'Deactivate this account?',
      message: `${target?.fullName || 'This user'} will no longer be able to sign in until the account is reactivated.`,
      cancelText: 'Cancel',
      confirmText: 'Deactivate Account',
    });
    if (!confirmed) return;

    try {
      const result = await apiFetch(`${API_BASE}?id=${id}`, {
        method: 'DELETE',
      });
      if (!result.ok) throw new Error(result.message);
      await refreshDirectories();
      addToast({ type: 'success', text: 'Account deactivated successfully.' });
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to archive user: ' + (error.message || 'Unknown error') });
    }
  }

  async function archiveDepartment(id) {
    const target = departments.find((department) => department.id === id);
    if (!target) return;
    const confirmed = await confirmDeleteData({
      message: `${target.name} will be archived and removed from active lists. This action cannot be undone from the active view.`,
      confirmText: 'Archive',
    });
    if (!confirmed) return;

    try {
      const result = await apiFetch('/api/departments.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      if (!result.ok) throw new Error(result.message || 'Failed to archive department.');
      await refreshDirectories();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to archive department: ' + (error.message || 'Unknown error') });
    }
  }

  async function restoreDepartment(id) {
    const target = archivedDepartments.find((department) => department.id === id);
    const confirmed = await confirmProceed({
      message: `${target?.name || 'This department'} will reappear in the active department directory.`,
      confirmText: 'Restore',
    });
    if (!confirmed) return;

    try {
      const result = await apiFetch('/api/departments.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, action: 'restore' }),
      });
      if (!result.ok) throw new Error(result.message || 'Failed to restore department.');
      await refreshDirectories();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to restore department: ' + (error.message || 'Unknown error') });
    }
  }

  async function restoreUser(id) {
    const target = archivedUsers.find((user) => user.id === id);
    const confirmed = await confirmProceed({
      message: `${target?.fullName || 'This user'} will reappear in the active user list.`,
      confirmText: 'Restore',
    });
    if (!confirmed) return;

    try {
      const result = await apiFetch(`${API_BASE}?id=${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'restore' }),
      });
      if (!result.ok) throw new Error(result.message || 'Failed to restore user.');

      await refreshDirectories();
      addToast({ type: 'success', text: 'Account activated successfully.' });
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to restore user: ' + (error.message || 'Unknown error') });
    }
  }

function handleImageUpload(event, type) {
  const file = event.target.files?.[0];
  if (!file) return;
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    setFormError('Choose a JPG, PNG, or WEBP image.');
    event.target.value = '';
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    setFormError('Profile pictures must be 5 MB or smaller.');
    event.target.value = '';
    return;
  }
  setFormError('');
  const url = URL.createObjectURL(file);
  if (type === 'department') {
    setDepartmentForm((current) => ({ ...current, logo: url }));
    setDepartmentLogoFile(file);
    touchDepartment('logo');
  }
  if (type === 'user') {
    setUserForm((current) => ({ ...current, avatar: url }));
    setUserAvatarFile(file);
    touch('avatar');
  }
}

  return (
    <section className="people-management-page module-wide page-enter">
      <div className="people-management-hero">
        <div>
          <p className="eyebrow">Admin/HR</p>
          <h2>{archiveOnly ? 'Archive Page' : 'People Management Page'}</h2>
          <p>{archiveOnly ? 'Review archived departments, users, accounts, and other records without permanently deleting system history.' : 'Manage department directory records, user accounts, department assignment, and role-based access from one dedicated workspace.'}</p>
        </div>
        {!archiveOnly && <div className="people-management-actions">
          <button type="button" onClick={openDepartmentManagement}><Building2 className="h-4 w-4" /> Manage Departments</button>
          <button type="button" onClick={openAccountManagement}><Users className="h-4 w-4" /> Manage Accounts</button>
          <button type="button" onClick={() => openUserForm()}><UserPlus className="h-4 w-4" /> Add Account/User</button>
        </div>}
      </div>

      {usersError && (
        <div className="notice warning" role="alert" style={{ marginBottom: '1rem', padding: '0.75rem 1rem', background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: '0.5rem', color: '#92400e' }}>
          Could not load users from database: {usersError}
        </div>
      )}
      {departmentsError && (
        <div className="notice warning" role="alert" style={{ marginBottom: '1rem', padding: '0.75rem 1rem', background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: '0.5rem', color: '#92400e' }}>
          Could not load departments from database: {departmentsError}
        </div>
      )}
      {programsError && (
        <div className="notice warning" role="alert" style={{ marginBottom: '1rem', padding: '0.75rem 1rem', background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: '0.5rem', color: '#92400e' }}>
          Could not load programs from database: {programsError}
        </div>
      )}

      {archiveOnly ? (
        <>
          <section className="people-section">
            <div className="box-title"><h2>Archived Departments</h2><span>{archivedDepartments.length} archived record(s)</span></div>
            <div className="people-department-grid">
              {archivedDepartments.map((department) => (
                <article className="people-department-card card-pop" key={department.id}>
                  <div className="people-department-card-cover">
                    <span>{department.code}</span>
                  </div>
                  <div className="people-department-card-body">
                    <div className="people-department-logo"><img src={department.logo} alt={`${department.name} logo`} /></div>
                    <div className="people-department-copy">
                      <h3>{department.name}</h3>
                      <p><Archive className="h-4 w-4" /> Archived in database</p>
                      <p>Dean: {department.dean || 'Unassigned'}</p>
                      <p>Programs: {department.programs || 0}</p>
                    </div>
                  </div>
                  <div className="people-card-actions" style={{ padding: '0 14px 14px' }}>
                    <button type="button" className="compact-link" onClick={() => restoreDepartment(department.id)}><RotateCcw className="h-4 w-4" /> Restore</button>
                  </div>
                </article>
              ))}
              {archivedDepartments.length === 0 && <div className="dipascaf-empty">No archived departments yet.</div>}
            </div>
          </section>
          <section className="people-section">
            <div className="box-title"><h2>Archived Users</h2><span>{archivedUsers.length} archived record(s)</span></div>
            <div className="people-user-grid">
              {archivedUsers.map((user) => (
                <article className="people-user-card card-pop" key={user.id}>
                  <div className="people-card-cover">
                    <span className={`people-status ${user.status.toLowerCase()}`}>{user.status}</span>
                  </div>
                  <div className="people-user-info">
                    <div className="people-user-avatar">{user.avatar ? <img src={user.avatar} alt={`${user.fullName} profile`} /> : user.fullName.charAt(0)}</div>
                    <div className="people-user-copy">
                      <h3>{user.fullName}</h3>
                      <p>{user.role}</p>
                      <span>{user.department || 'Unassigned'} {user.program ? `- ${user.program}` : ''}</span>
                      <small>Inactive in database</small>
                    </div>
                  </div>
                  <div className="people-card-actions">
                    <button type="button" className="compact-link" onClick={() => restoreUser(user.id)}><RotateCcw className="h-4 w-4" /> Restore</button>
                  </div>
                </article>
              ))}
              {archivedUsers.length === 0 && <div className="dipascaf-empty">No archived users yet.</div>}
            </div>
          </section>
        </>
      ) : (
      <>
      <section className="people-filter-panel" aria-label="People management filters">
        <label className="people-search-field">
          <span>{managingAccounts ? 'Search user accounts' : 'Search by name, department, role'}</span>
          <div>
            <Search className="h-4 w-4" />
            <input value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder={managingAccounts ? 'Search by name, email, role, department, or program...' : 'Search real-time results...'} />
            {filters.search && <button type="button" className="people-search-clear" onClick={() => updateFilter('search', '')} aria-label="Clear search"><X size={14} /></button>}
          </div>
        </label>
        <label>Department<select value={filters.department} onChange={(event) => { updateFilter('department', event.target.value); updateFilter('program', ''); }}><option value="">All departments</option>{departments.map((department) => <option key={department.id}>{department.name}</option>)}</select></label>
        {managingAccounts && <label>Program<select value={filters.program} onChange={(event) => updateFilter('program', event.target.value)}><option value="">All programs</option>{accountPrograms.map((program) => <option key={program.id} value={program.code}>{program.code} — {program.name}</option>)}</select></label>}
        <label>Role<select value={filters.role} onChange={(event) => updateFilter('role', event.target.value)}><option value="">All roles</option>{(managingAccounts ? accountRoleOptions : roleOptions).map((role) => <option key={role} value={role}>{role === 'Admin' ? 'HRDM Director/Admin' : role === 'Faculty' ? 'Faculty Member' : role}</option>)}</select></label>
        <label>Status<select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}><option value="">All status</option>{statusOptions.map((status) => <option key={status}>{status}</option>)}</select></label>
      </section>

      {peopleFilter === 'low-progress' && (
        <div className="notice warning" role="status" style={{ padding: '0.75rem 1rem', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: '0.75rem', color: '#92400e', fontWeight: 700 }}>
          Showing faculty accounts. Low-progress values depend on faculty progress records from the database.
        </div>
      )}

      {showDepartmentDirectory && <section className="people-section people-department-management" id="department-management" ref={departmentManagementRef} tabIndex="-1">
        <div className="box-title"><h2>Department Directory</h2><div className="people-directory-title-actions"><span>{filteredDepartments.length} department result(s)</span><button type="button" className="compact-link" onClick={() => openDepartmentForm()}><Plus size={15} /> Add Department</button></div></div>
        <div className="people-department-grid">
          {departmentsLoading && <div className="dipascaf-empty">Loading departments from database...</div>}
          {!departmentsLoading && filteredDepartments.map((department) => (
            <article className="people-department-card card-pop" key={department.id}>
              <div className="people-department-card-cover">
                <span>{department.code}</span>
              </div>
              <div className="people-department-card-body">
                <div className="people-department-logo"><img src={department.logo} alt={`${department.name} logo`} /></div>
                <div className="people-department-copy">
                  <h3>{department.name}</h3>
                  <p><Users className="h-4 w-4" /> {Number(department.user_count ?? department.faculty_count ?? users.filter((user) => user.department === department.name).length) || 0} total faculty/users</p>
                  <p><Building2 className="h-4 w-4" /> Dean: {department.dean || 'Unassigned'}</p>
                  <p>Programs: {department.programs || 0}</p>
                </div>
              </div>
              <div className="people-card-actions" style={{ padding: '0 14px 14px' }}>
                <button type="button" className="compact-link" onClick={() => navigate(`/admin/department/${department.id}`)}><Eye className="h-4 w-4" /> View</button>
                <button type="button" className="compact-link" onClick={() => openDepartmentForm(department)}><Edit3 className="h-4 w-4" /> Edit</button>
                <button type="button" className="archive-action" onClick={() => archiveDepartment(department.id)}><Archive className="h-4 w-4" /> Archive</button>
              </div>
            </article>
          ))}
          {!departmentsLoading && filteredDepartments.length === 0 && <div className="dipascaf-empty">No department records match the selected filters.</div>}
        </div>
      </section>}

      {showProgramDirectory && <section className="people-section">
        <div className="box-title"><h2>Program Directory</h2><span>{filteredPrograms.length} program result(s)</span></div>
        <div className="people-program-list">
          {programsLoading && <div className="dipascaf-empty">Loading programs from database...</div>}
          {!programsLoading && filteredPrograms.length > 0 && (
            <div className="people-program-list-head" aria-hidden="true">
              <span>Program</span>
              <span>Department</span>
              <span>Program Head</span>
              <span>Status</span>
            </div>
          )}
          {!programsLoading && filteredPrograms.map((program) => {
            const programName = program.name || program.program_name || '';
            const programCode = program.code || program.program_code || '';
            const departmentCode = program.department_code || 'Unassigned';
            const programHead = program.program_head_name || program.program_head || '';
            return (
              <article className="people-program-row card-pop" key={program.id || programCode || programName}>
                <div>
                  <h3>{programName || programCode}</h3>
                  <small>{programCode || 'No code'}</small>
                </div>
                <span>{departmentCode}</span>
                <span>{programHead || 'Unassigned'}</span>
                <span className={`people-status ${programHead ? 'active' : 'inactive'}`}>{programHead ? 'Assigned' : 'Needs Head'}</span>
              </article>
            );
          })}
          {!programsLoading && filteredPrograms.length === 0 && <div className="dipascaf-empty">No program records match the selected filters.</div>}
        </div>
      </section>}

      {showUserDirectory && <section className={`people-section ${managingAccounts ? 'people-account-management' : ''}`} id="account-management" ref={accountManagementRef} tabIndex="-1">
        {managingAccounts && <><div className="people-account-breadcrumb"><button type="button" onClick={() => navigate('/admin/people')}><ChevronLeft size={16} /> Back to People Management</button><span>People Management <b>/</b> Manage Accounts</span></div><div className="people-account-heading"><div className="people-account-heading-copy"><span className="people-account-heading-icon"><Users size={22} /></span><div><p className="eyebrow">Account Administration</p><h2>Manage User Accounts</h2><p>View, search, filter, update, and manage institutional user accounts and organizational assignments.</p></div></div><button type="button" onClick={() => openUserForm()}><UserPlus size={16} /> Add Account/User</button></div><div className="people-account-metrics"><article className="total"><span className="people-account-metric-icon"><Users size={18} /></span><div><span>Total Accounts</span><strong>{accountMetrics.total}</strong><small>All institutional users</small></div></article><article className="active"><span className="people-account-metric-icon"><Check size={18} /></span><div><span>Active</span><strong>{accountMetrics.active}</strong><small>Can access APPRAISIA</small></div></article><article className="inactive"><span className="people-account-metric-icon"><Archive size={18} /></span><div><span>Inactive</span><strong>{accountMetrics.inactive}</strong><small>Sign-in access disabled</small></div></article><article className="admin"><span className="people-account-metric-icon"><Shield size={18} /></span><div><span>Administrators</span><strong>{accountMetrics.admins}</strong><small>HRDM system managers</small></div></article></div><div className="people-account-table-title"><div><h3>Account Directory</h3><p>{filteredUsers.length} account{filteredUsers.length === 1 ? '' : 's'} match the current view</p></div><button type="button" onClick={refreshDirectories} disabled={usersLoading}><RefreshCw size={15} className={usersLoading ? 'animate-spin' : ''} /> Refresh</button></div></>}
        {!managingAccounts && <div className="box-title"><h2>User/People Management</h2><span>{filteredUsers.length} user result(s)</span></div>}
        {usersLoading && <div className="dipascaf-empty" style={{ padding: '2rem' }}>Loading users from database...</div>}
        {!usersLoading && (
        <div className="people-user-list">
          {filteredUsers.length > 0 && (
            <div className="people-user-list-head" aria-hidden="true">
              {managingAccounts ? <button type="button" onClick={() => toggleAccountSort('fullName')}>User {accountSort.key === 'fullName' ? (accountSort.direction === 'asc' ? '↑' : '↓') : ''}</button> : <span>User</span>}
              {managingAccounts ? <button type="button" onClick={() => toggleAccountSort('role')}>Role {accountSort.key === 'role' ? (accountSort.direction === 'asc' ? '↑' : '↓') : ''}</button> : <span>Role</span>}
              <span>Assignment</span>
              {managingAccounts ? <button type="button" onClick={() => toggleAccountSort('status')}>Status {accountSort.key === 'status' ? (accountSort.direction === 'asc' ? '↑' : '↓') : ''}</button> : <span>Status</span>}
              <span>Actions</span>
            </div>
          )}
          {pagedUsers.map((user) => (
            <article className="people-user-row card-pop" key={user.id}>
              <div className="people-user-identity">
                <div className="people-user-avatar">{user.avatar ? <img src={user.avatar} alt={`${user.fullName} profile`} /> : user.fullName.charAt(0)}</div>
                <div className="people-user-copy">
                  <h3>{user.fullName}</h3>
                  <small>Username Code: {user.userCode} · {user.email}</small>
                </div>
              </div>
              <span className="people-user-role">{user.role}</span>
              <span className="people-user-assignment">{user.department || 'Unassigned'} {user.program ? `- ${user.program}` : ''}</span>
              <span className={`people-status ${user.status.toLowerCase()}`}>{user.status}</span>
              <div className="people-card-actions">
                {managingAccounts && <button type="button" className="compact-link" onClick={() => setAccountDetails(user)}><Eye className="h-4 w-4" /> View</button>}
                <button type="button" className="compact-link" onClick={() => openUserForm(user)}><Edit3 className="h-4 w-4" /> Edit</button>
                {user.status === 'Active' ? <button type="button" className="archive-action" onClick={() => archiveUser(user.id)}><Archive className="h-4 w-4" /> Deactivate</button> : <button type="button" className="compact-link" onClick={() => restoreUser(user.id)}><RotateCcw className="h-4 w-4" /> Activate</button>}
              </div>
            </article>
          ))}
          {filteredUsers.length === 0 && <div className="dipascaf-empty"><Users size={28} /><strong>{accountUsers.length ? 'No accounts match your search.' : 'No user accounts yet'}</strong><span>{accountUsers.length ? 'Adjust the search or filters to see more results.' : 'Create the first institutional account to begin assigning roles and organizational units.'}</span>{!accountUsers.length && <button type="button" onClick={() => openUserForm()}><UserPlus size={15} /> Add Account/User</button>}</div>}
          {managingAccounts && filteredUsers.length > 0 && <div className="people-account-pagination"><span>Showing {(accountPage - 1) * accountPageSize + 1}–{Math.min(accountPage * accountPageSize, filteredUsers.length)} of {filteredUsers.length} accounts</span><label>Rows<select value={accountPageSize} onChange={(event) => { setAccountPageSize(Number(event.target.value)); setAccountPage(1); }}><option>10</option><option>25</option><option>50</option></select></label><div><button type="button" disabled={accountPage <= 1} onClick={() => setAccountPage((page) => Math.max(1, page - 1))} aria-label="Previous page"><ChevronLeft size={16} /></button><span>{accountPage} / {accountPageCount}</span><button type="button" disabled={accountPage >= accountPageCount} onClick={() => setAccountPage((page) => Math.min(accountPageCount, page + 1))} aria-label="Next page"><ChevronRight size={16} /></button></div></div>}
        </div>
        )}
      </section>}

      {modal === 'department' && (
        <ManagementModal title={departmentForm.id ? 'Edit Department' : 'Add New Department'} subtitle="Create a department, assign its academic head, and organize its information within APPRAISIA." icon={Building2} onClose={closeDepartmentForm} className="people-department-modal">
          <form className="admin-form people-department-form" onSubmit={saveDepartment}>
            <div className="people-department-form-scroll">
              {formError && <div className="people-form-alert" role="alert">{formError}</div>}
              <fieldset className="people-form-section"><legend><Building2 size={17} /> Basic Department Information</legend>
                <div className="people-department-logo-card"><div className="people-department-logo-preview">{departmentForm.logo && departmentForm.logo !== DEFAULT_DEPARTMENT_LOGO ? <img src={departmentForm.logo} alt="Department logo preview" /> : <span>{departmentForm.code.slice(0, 3) || <ImageIcon size={25} />}</span>}</div><div><strong>Department Logo</strong><small>Upload JPG, PNG, or WEBP. Maximum 5 MB.</small><div className="people-avatar-actions"><label className="people-file-control"><Upload size={15} /> Upload Logo<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => handleImageUpload(event, 'department')} /></label>{departmentForm.logo !== DEFAULT_DEPARTMENT_LOGO && <button type="button" onClick={removeDepartmentLogo}><Trash2 size={15} /> Remove</button>}</div></div></div>
                <div className="people-form-grid">
                  <FormField label="Department Code" icon={Layers3} error={departmentTouched.code && !/^[A-Z0-9-]{2,10}$/.test(departmentForm.code.trim()) ? 'Use 2–10 letters, numbers, or hyphens.' : ''}><input value={departmentForm.code} maxLength={10} onChange={(e) => { touchDepartment('code'); setDepartmentForm((v) => ({ ...v, code: e.target.value.toUpperCase().replace(/\s/g, '') })); }} placeholder="CITE" /><small>Use a short unique code for this department.</small></FormField>
                  <FormField label="Department Name" icon={Building2} error={departmentTouched.name && !departmentForm.name.trim() ? 'Department name is required.' : ''}><input value={departmentForm.name} onChange={(e) => { touchDepartment('name'); setDepartmentForm((v) => ({ ...v, name: e.target.value })); }} placeholder="Enter full department name" /></FormField>
                  <FormField label="Department Type" icon={Layers3}><select value={departmentForm.department_type} onChange={(e) => { touchDepartment('department_type'); setDepartmentForm((v) => ({ ...v, department_type: e.target.value })); }}>{['Academic Department','Administrative Department','Support Unit','College','Other'].map((type) => <option key={type}>{type}</option>)}</select></FormField>
                  <FormField label="Status" icon={Shield}><select value={departmentForm.status} onChange={(e) => { touchDepartment('status'); setDepartmentForm((v) => ({ ...v, status: e.target.value })); }}><option>Active</option><option>Inactive</option></select><small>{departmentForm.status === 'Active' ? 'Available for user assignment and evaluation workflow.' : 'Saved but hidden from active assignment.'}</small></FormField>
                </div>
                <FormField label="Department Description" icon={ImageIcon}><textarea value={departmentForm.description} onChange={(e) => { touchDepartment('description'); setDepartmentForm((v) => ({ ...v, description: e.target.value })); }} placeholder="Enter a short description of the department." /></FormField>
              </fieldset>
              <fieldset className="people-form-section"><legend><User size={17} /> Academic Leadership</legend><FormField label="Assigned Dean" icon={User}><select value={departmentForm.dean} onChange={(e) => { touchDepartment('dean'); setDepartmentForm((v) => ({ ...v, dean: e.target.value })); }}><option value="">Unassigned</option>{users.filter((user) => user.role === 'Dean').map((user) => <option key={user.id} value={user.fullName}>{user.fullName} — {user.email}{user.department ? ` (Current: ${user.department})` : ' (Unassigned)'}</option>)}</select><small>Only active users with the Dean role can be assigned here.</small></FormField></fieldset>
              {departmentForm.code.trim() && departmentForm.name.trim() && <aside className="people-assignment-summary"><strong>Department Summary</strong><div><span>Code</span><b>{departmentForm.code}</b></div><div><span>Name</span><b>{departmentForm.name}</b></div><div><span>Assigned Dean</span><b>{departmentForm.dean || 'Unassigned'}</b></div><div><span>Status</span><b>{departmentForm.status}</b></div><div><span>Type</span><b>{departmentForm.department_type}</b></div><div><span>Programs</span><b>{departmentForm.id ? programsForDepartment(departmentForm, allPrograms).length : 0} Linked Programs</b></div></aside>}
            </div>
            <footer className="people-department-form-footer"><button type="button" onClick={closeDepartmentForm}><X size={16} /> Cancel</button><button className="people-save-department" type="submit" disabled={saving}>{saving ? <Loader2 size={17} className="animate-spin" /> : <Save size={17} />} {saving ? 'Saving Department...' : 'Save Department'}</button></footer>
          </form>
        </ManagementModal>
      )}

      {accountDetails && <ManagementModal title="Account Details" subtitle="Account, role, assignment, and system information." icon={User} onClose={() => setAccountDetails(null)} className="people-account-details-modal">
        <div className="people-account-details">
          <div className="people-account-details-person">
            <div className="people-user-avatar">{accountDetails.avatar ? <img src={accountDetails.avatar} alt={`${accountDetails.fullName} profile`} /> : accountDetails.fullName.split(/\s+/).slice(0,2).map((part) => part[0]).join('')}</div>
            <div className="people-account-person-copy"><span className="people-account-code">Username Code · {accountDetails.userCode}</span><h3>{accountDetails.fullName}</h3><p>{accountDetails.email}</p></div>
            <span className={`people-status ${accountDetails.status.toLowerCase()}`}>{accountDetails.status}</span>
          </div>
          <div className="people-account-detail-grid">
            <section className="people-account-role-card"><h4><Building2 size={15} /> Role and Assignment</h4><dl><div><dt>Role</dt><dd>{accountDetails.role === 'Admin' ? 'HRDM Director/Admin' : accountDetails.role === 'Faculty' ? 'Faculty Member' : accountDetails.role}</dd></div><div><dt>Department</dt><dd>{accountDetails.department || 'Institution-wide / Unassigned'}</dd></div><div><dt>Program</dt><dd>{accountDetails.program || 'Not assigned'}</dd></div></dl></section>
            <section className="people-account-system-card"><h4><ShieldCheck size={15} /> System Information</h4><dl><div><dt>Date Created</dt><dd>{accountDetails.createdAt ? new Date(accountDetails.createdAt).toLocaleString() : 'Not available'}</dd></div><div><dt>Last Login</dt><dd>{accountDetails.lastLoginAt ? new Date(accountDetails.lastLoginAt).toLocaleString() : 'No recorded login'}</dd></div><div><dt>Email</dt><dd>{accountDetails.emailVerified ? 'Verified' : 'Not verified'}</dd></div></dl></section>
          </div>
          <footer><button type="button" onClick={() => { setAccountDetails(null); openUserForm(accountDetails); }}><Edit3 size={16} /> Edit Account</button><button type="button" onClick={() => setAccountDetails(null)}>Close</button></footer>
        </div>
      </ManagementModal>}

      {modal === 'user' && (
        <ManagementModal
          title={userForm.id ? 'Edit User Account' : 'Add New User'}
          subtitle={isEditing ? 'Update account information, organizational assignment, status, and security.' : 'Create an account and assign the appropriate institutional role and organizational unit.'}
          icon={isEditing ? Edit3 : UserPlus}
          onClose={closeUserForm}
          className="people-account-modal"
        >
          <form className="admin-form people-user-form" onSubmit={saveUser}>
            {isEditing && <section className="people-edit-profile-header people-edit-profile-header-fixed"><div className="people-edit-avatar">{userForm.avatar ? <img src={userForm.avatar} alt={`${userForm.fullName} profile preview`} /> : <span>{userForm.fullName.trim().split(/\s+/).slice(0,2).map((part) => part[0]).join('').toUpperCase() || 'U'}</span>}</div><div className="people-edit-profile-copy"><h3>{userForm.fullName || 'User Account'}</h3><p>{userForm.email}</p><div><span className="people-role-badge"><BadgeCheck size={13} /> {userForm.role === 'Admin' ? 'HRDM Director/Admin' : userForm.role === 'Faculty' ? 'Faculty Member' : userForm.role}</span>{userForm.department && <span className="people-department-badge"><Building2 size={12} /> {userForm.department}</span>}<span className={`people-status ${userForm.status.toLowerCase()}`}>{userForm.status}</span></div></div><div className="people-edit-photo-actions"><label><Camera size={15} /> Change Photo<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => handleImageUpload(event, 'user')} /></label><button type="button" onClick={removeUserPhoto} disabled={!userForm.avatar}><Trash2 size={14} /> Remove Photo</button></div><button type="button" className="people-edit-close" onClick={closeUserForm} aria-label="Close Edit User Account" title="Close"><span className="people-edit-close-glyph" aria-hidden="true">×</span></button></section>}
            <div className="people-user-form-scroll">
              {formError && <div className="people-form-alert" role="alert">{formError}</div>}
              {isEditing && <section className="people-edit-profile-header"><div className="people-edit-avatar">{userForm.avatar ? <img src={userForm.avatar} alt={`${userForm.fullName} profile preview`} /> : <span>{userForm.fullName.trim().split(/\s+/).slice(0,2).map((part) => part[0]).join('').toUpperCase() || 'U'}</span>}</div><div className="people-edit-profile-copy"><h3>{userForm.fullName || 'User Account'}</h3><p>{userForm.email}</p><div><span className="people-role-badge"><BadgeCheck size={13} /> {userForm.role === 'Admin' ? 'HRDM Director/Admin' : userForm.role === 'Faculty' ? 'Faculty Member' : userForm.role}</span><span className={`people-status ${userForm.status.toLowerCase()}`}>{userForm.status}</span></div></div><div className="people-edit-photo-actions"><label><Camera size={15} /> Change Photo<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => handleImageUpload(event, 'user')} /></label><button type="button" onClick={removeUserPhoto} disabled={!userForm.avatar}><Trash2 size={14} /> Remove</button></div></section>}
              <fieldset className="people-form-section">
                <legend><User size={17} /> Account Information</legend>
                <div className="people-form-grid">
                  <FormField label="Username Code" icon={BadgeCheck} error={touched.userCode && !/^[1-9]\d*$/.test(userForm.userCode) ? 'Enter a positive numeric code.' : ''}>
                    <input inputMode="numeric" value={userForm.userCode} onBlur={() => touch('userCode')} onChange={(event) => setUserForm((current) => ({ ...current, userCode: event.target.value.replace(/\D/g, '') }))} placeholder={nextUserCode} />
                  </FormField>
                  <FormField label="Full Name" icon={User} error={touched.fullName && !userForm.fullName.trim() ? 'Full name is required.' : ''}>
                    <input id="new-user-name" value={userForm.fullName} onBlur={() => touch('fullName')} onChange={(event) => { touch('fullName'); setUserForm((current) => ({ ...current, fullName: event.target.value })); }} placeholder="Enter complete name" autoComplete="name" />
                  </FormField>
                  <FormField label="Email Address" icon={Mail} labelExtra={<span className={`people-email-verification ${userForm.emailVerified ? 'is-verified' : 'is-unverified'}`}>{userForm.emailVerified ? <><BadgeCheck size={13} /> Verified</> : <><Info size={13} /> Not Verified</>}</span>} error={touched.email && !/^\S+@\S+\.\S+$/.test(normalizedEmail) ? 'Enter a valid email address.' : ''}>
                    <input id="new-user-email" type="email" value={userForm.email} onBlur={() => touch('email')} onChange={(event) => { touch('email'); setUserForm((current) => ({ ...current, email: event.target.value, emailVerified: event.target.value.trim().toLowerCase() === originalUserFormRef.current.email.trim().toLowerCase() ? originalUserFormRef.current.emailVerified : false })); }} placeholder="name@ndmc.edu.ph" autoComplete="email" />
                  </FormField>
                  <FormField label="Birth Date" icon={User} error={touched.birthDate && !userForm.birthDate ? 'Birth date is required for new accounts.' : ''}>
                    <ModernDatePicker value={userForm.birthDate} onBlur={() => touch('birthDate')} onChange={(birthDate) => setUserForm((current) => ({ ...current, birthDate }))} />
                    {!isEditing && userForm.birthDate && <small>Temporary password: {userForm.birthDate}. The user must change it at first login.</small>}
                  </FormField>
                </div>
                {!isEditing && <div className="people-avatar-card">
                  <div className="people-upload-preview">{userForm.avatar ? <img src={userForm.avatar} alt="Profile preview" /> : <span>{userForm.fullName.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'U'}</span>}</div>
                  <div><strong>Profile Picture</strong><small>JPG, PNG, or WEBP. Maximum 5 MB.</small><div className="people-avatar-actions"><label className="people-file-control"><Upload size={15} /> Upload Photo<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => handleImageUpload(event, 'user')} /></label>{userForm.avatar && <button type="button" onClick={removeUserPhoto}><Trash2 size={15} /> Remove</button>}</div></div>
                </div>}
              </fieldset>

              <fieldset className="people-form-section">
                <legend><Building2 size={17} /> Role and Assignment</legend>
                <div className="people-form-grid">
                  <FormField label="Role" icon={Shield}>
                    <select value={userForm.role} onChange={(event) => { touch('role'); updateUserRole(event.target.value); }}><option value="">Select institutional role</option>{(userForm.role === 'Admin' ? accountRoleOptions : roleOptions).map((role) => <option key={role} value={role}>{role === 'Admin' ? 'HRDM Director/Admin' : role === 'Faculty' ? 'Faculty Member' : role}</option>)}</select>
                    {userForm.role && <small>{roleDescriptions[userForm.role]}</small>}
                  </FormField>
                  <FormField label="Account Status" icon={ShieldCheck}><div className="people-status-control">{statusOptions.map((status) => <button type="button" key={status} className={userForm.status === status ? 'active' : ''} onClick={() => { touch('status'); setUserForm((current) => ({ ...current, status })); }}><Check size={14} /> {status}</button>)}</div><small>{userForm.status === 'Active' ? 'User can sign in.' : 'Sign-in access is disabled.'}</small></FormField>
                </div>
              </fieldset>

              {roleRequiresDepartment && <fieldset className="people-form-section people-dynamic-section"><legend><Building2 size={17} /> Organizational Assignment</legend><div className="people-form-grid">
                <FormField label="Department Assignment" icon={Building2} error={touched.department && roleRequiresDepartment && !userForm.department ? 'Department assignment is required.' : ''}><select value={userForm.department} onChange={(event) => { touch('department'); updateUserDepartment(event.target.value); }}><option value="">Search or select department</option>{departments.map((department) => <option key={department.id} value={department.name}>{department.name} ({department.code})</option>)}</select><button type="button" className="people-inline-add" onClick={() => setInlineModal('department')}><Plus size={14} /> Add New Department</button></FormField>
                {roleUsesProgram && <FormField label="Program Assignment" icon={GraduationCap} error={touched.program && roleRequiresProgram && !userForm.program ? 'Program assignment is required for this role.' : ''}><select value={userForm.program} onChange={(event) => { touch('program'); updateUserProgram(event.target.value); }} disabled={!userForm.department || programsLoading}><option value="">{!userForm.department ? 'Select department first' : programsLoading ? 'Loading programs...' : 'No Program Assigned'}</option>{selectedProgramOptions.map((program) => <option key={program.id || program.code} value={program.code}>{program.code} — {program.name}</option>)}</select>{userForm.department && !programsLoading && selectedProgramOptions.length === 0 && <small className="people-assignment-empty">No active programs are available for the selected department.</small>}{userForm.role === 'Dean' && <small>Deans are assigned to a department. A program assignment is optional and does not make the user a Program Head.</small>}<button type="button" className="people-inline-add" disabled={!selectedDepartment} onClick={() => setInlineModal('program')}><Plus size={14} /> Add New Program</button></FormField>}
              </div></fieldset>}

              {isEditing && <fieldset className={`people-form-section people-security-section ${passwordExpanded ? 'is-expanded' : ''}`}><legend><ShieldCheck size={17} /> Security and Password</legend>{userForm.pendingPasswordResetRequestId > 0 && <div className="people-reset-request-banner"><span className="people-reset-request-icon"><RotateCcw size={20} /></span><div className="people-reset-request-copy"><strong>Password reset requested</strong><span>Reset this account to its recorded birth date. The user will be required to create a new password after signing in.</span></div><button type="button" onClick={completePasswordReset} disabled={saving}>{saving ? <Loader2 size={16} className="animate-spin" /> : <RotateCcw size={16} />} <span>{saving ? 'Resetting...' : 'Complete Password Reset'}</span></button></div>}<div className="people-security-head"><div><strong>Update account credentials securely.</strong><small>The current password remains unchanged unless a new one is saved.</small></div><button type="button" onClick={() => setPasswordExpanded((value) => !value)}><Lock size={15} /> {passwordExpanded ? 'Cancel Password Change' : 'Change Password'}</button></div>{passwordExpanded && <div className="people-security-fields"><div className="people-form-grid"><FormField label="New Password" icon={Lock}><input type={showPassword ? 'text':'password'} value={userForm.password} onChange={e=>setUserForm(v=>({...v,password:e.target.value}))}/></FormField><FormField label="Confirm Password" icon={Lock}><input type={showPassword ? 'text':'password'} value={confirmPassword} onChange={e=>setConfirmPassword(e.target.value)}/></FormField></div></div>}</fieldset>}

              {summaryReady && <aside className="people-assignment-summary"><strong>Assignment Summary</strong><div><span>Role</span><b>{userForm.role === 'Faculty' ? 'Faculty Member' : userForm.role}</b></div>{userForm.department && <div><span>Department</span><b>{userForm.department}</b></div>}{userForm.program && <div><span>Program</span><b>{userForm.program}</b></div>}<div><span>Account Status</span><b>{userForm.status}</b></div></aside>}
            </div>
            <footer className="people-user-form-footer">{isEditing && <button type="button" className="people-reset-account" onClick={resetUserChanges}><RotateCcw size={16} /> Reset Changes</button>}<div className="people-user-footer-primary"><button type="button" className="people-cancel-account" onClick={closeUserForm}><X size={16} /> Cancel</button><button type="submit" className="people-save-account" disabled={saving}>{saving ? <Loader2 size={17} className="animate-spin" /> : isEditing ? <Save size={17} /> : <UserPlus size={17} />} {saving ? (isEditing ? 'Saving Changes...' : 'Creating Account...') : (isEditing ? 'Save Changes' : 'Create Account')}</button></div></footer>
          </form>
        </ManagementModal>
      )}
      {inlineModal === 'department' && <InlineCreationModal title="Add New Department" icon={Building2} onClose={() => setInlineModal(null)}><form onSubmit={createInlineDepartment}><label>Department Name *<input required value={inlineDepartment.name} onChange={(e) => setInlineDepartment((v) => ({ ...v, name: e.target.value }))} placeholder="College of Information Technology and Engineering" /></label><label>Department Code / Abbreviation *<input required value={inlineDepartment.code} onChange={(e) => setInlineDepartment((v) => ({ ...v, code: e.target.value.toUpperCase() }))} placeholder="CITE" /></label><label>Description (optional)<textarea value={inlineDepartment.description} onChange={(e) => setInlineDepartment((v) => ({ ...v, description: e.target.value }))} placeholder="Academic department for computing and engineering programs." /></label><StatusSelect value={inlineDepartment.status} onChange={(status) => setInlineDepartment((v) => ({ ...v, status }))} /><div className="people-inline-modal-actions"><button type="button" onClick={() => setInlineModal(null)}>Cancel</button><button type="submit" disabled={inlineSaving}>{inlineSaving && <Loader2 size={15} className="animate-spin" />} Save Department</button></div></form></InlineCreationModal>}
      {inlineModal === 'program' && <InlineCreationModal title="Add New Program" icon={GraduationCap} onClose={() => setInlineModal(null)}><form onSubmit={createInlineProgram}><label>Program Name *<input required value={inlineProgram.name} onChange={(e) => setInlineProgram((v) => ({ ...v, name: e.target.value }))} placeholder="Bachelor of Science in Information Technology" /></label><label>Program Code *<input required value={inlineProgram.code} onChange={(e) => setInlineProgram((v) => ({ ...v, code: e.target.value.toUpperCase() }))} placeholder="BSIT" /></label><label>Parent Department<input value={selectedDepartment?.name || ''} disabled /></label><label>Description (optional)<textarea value={inlineProgram.description} onChange={(e) => setInlineProgram((v) => ({ ...v, description: e.target.value }))} /></label><StatusSelect value={inlineProgram.status} onChange={(status) => setInlineProgram((v) => ({ ...v, status }))} /><div className="people-inline-modal-actions"><button type="button" onClick={() => setInlineModal(null)}>Cancel</button><button type="submit" disabled={inlineSaving}>{inlineSaving && <Loader2 size={15} className="animate-spin" />} Save Program</button></div></form></InlineCreationModal>}
      </>
      )}
    </section>
  );
}

function FormField({ label, icon: Icon, labelExtra, error, children, className = '' }) {
  return <label className={`people-form-field ${error ? 'has-error' : ''} ${className}`}><span className="people-field-label"><span className="people-field-label-copy">{Icon && <Icon size={15} />} {label}</span>{labelExtra}</span>{children}{error && <small className="people-field-error">{error}</small>}</label>;
}

function parseBirthDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
  if (!match) return undefined;
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return date.getFullYear() === Number(match[1]) && date.getMonth() === Number(match[2]) - 1 && date.getDate() === Number(match[3]) ? date : undefined;
}

function ModernDatePicker({ value, onChange, onBlur }) {
  const [open, setOpen] = useState(false);
  const [yearPickerOpen, setYearPickerOpen] = useState(false);
  const rootRef = useRef(null);
  const selected = parseBirthDate(value);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const [displayMonth, setDisplayMonth] = useState(selected || new Date(1990, 0, 1));

  useEffect(() => {
    if (open && selected) setDisplayMonth(selected);
  }, [open, value]);

  useEffect(() => {
    if (!yearPickerOpen) return undefined;
    const frame = window.requestAnimationFrame(() => rootRef.current?.querySelector('.people-year-options .is-selected')?.scrollIntoView({ block: 'center' }));
    return () => window.cancelAnimationFrame(frame);
  }, [yearPickerOpen]);

  useEffect(() => {
    if (!open) return undefined;
    const closeFromOutside = (event) => {
      if (!rootRef.current?.contains(event.target)) {
        setOpen(false);
        setYearPickerOpen(false);
        onBlur?.();
      }
    };
    const closeFromKeyboard = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
        setYearPickerOpen(false);
        rootRef.current?.querySelector('.people-date-trigger')?.focus();
        onBlur?.();
      }
    };
    document.addEventListener('pointerdown', closeFromOutside);
    document.addEventListener('keydown', closeFromKeyboard);
    return () => {
      document.removeEventListener('pointerdown', closeFromOutside);
      document.removeEventListener('keydown', closeFromKeyboard);
    };
  }, [open, onBlur]);

  const selectDate = (date) => {
    if (!date) return;
    onChange(format(date, 'yyyy-MM-dd'));
    setOpen(false);
    setYearPickerOpen(false);
    onBlur?.();
  };

  return <div className={`people-date-picker ${open ? 'is-open' : ''}`} ref={rootRef}>
    <button type="button" className="people-date-trigger" aria-haspopup="dialog" aria-expanded={open} onClick={(event) => { event.preventDefault(); setYearPickerOpen(false); setOpen((current) => !current); }}>
      <CalendarDays size={19} aria-hidden="true" />
      <span className={selected ? '' : 'is-placeholder'}>{selected ? format(selected, 'MMMM d, yyyy') : 'Select birth date'}</span>
      <ChevronDown size={18} aria-hidden="true" />
    </button>
    {open && <div className="people-date-popover" role="dialog" aria-label="Choose birth date" onClick={(event) => event.preventDefault()}>
      <div className="people-date-popover-heading"><span><CalendarDays size={17} /> Birth date</span><small>{selected ? format(selected, 'EEEE, MMMM d, yyyy') : 'Choose a date below'}</small></div>
      <div className="people-date-jump" aria-label="Choose month and year">
        <select aria-label="Calendar month" value={displayMonth.getMonth()} onChange={(event) => setDisplayMonth(new Date(displayMonth.getFullYear(), Number(event.target.value), 1))}>
          {Array.from({ length: 12 }, (_, month) => <option key={month} value={month}>{format(new Date(2000, month, 1), 'MMMM')}</option>)}
        </select>
        <div className={`people-year-picker ${yearPickerOpen ? 'is-open' : ''}`}>
          <button type="button" className="people-year-trigger" aria-label={`Calendar year ${displayMonth.getFullYear()}`} aria-haspopup="listbox" aria-expanded={yearPickerOpen} onClick={() => setYearPickerOpen((current) => !current)}>{displayMonth.getFullYear()} <ChevronDown size={15} /></button>
          {yearPickerOpen && <div className="people-year-options" role="listbox" aria-label="Select birth year">
            {Array.from({ length: today.getFullYear() - 1939 }, (_, index) => today.getFullYear() - index).map((year) => <button type="button" role="option" aria-selected={year === displayMonth.getFullYear()} className={year === displayMonth.getFullYear() ? 'is-selected' : ''} key={year} onClick={() => { setDisplayMonth(new Date(year, displayMonth.getMonth(), 1)); setYearPickerOpen(false); }}>{year}</button>)}
          </div>}
        </div>
      </div>
      <DayPicker mode="single" selected={selected} month={displayMonth} onMonthChange={setDisplayMonth} onSelect={selectDate} disabled={{ after: today }} startMonth={new Date(1940, 0, 1)} endMonth={today} captionLayout="label" navLayout="after" showOutsideDays fixedWeeks />
      <div className="people-date-actions">
        <button type="button" onClick={() => { onChange(''); onBlur?.(); }}>Clear</button>
        <button type="button" onClick={() => selectDate(today)}>Today</button>
      </div>
    </div>}
  </div>;
}

function StatusSelect({ value, onChange }) {
  return <label>Status<select value={value} onChange={(event) => onChange(event.target.value)}><option>Active</option><option>Inactive</option></select></label>;
}

function InlineCreationModal({ title, icon: Icon, children, onClose }) {
  return createPortal(<div className="people-inline-modal-backdrop" onClick={(event) => event.target === event.currentTarget && onClose()}><section className="people-inline-modal" role="dialog" aria-modal="true" aria-label={title}><header><span><Icon size={19} /></span><h3>{title}</h3><button type="button" onClick={onClose} aria-label="Close"><X size={17} /></button></header>{children}</section></div>, document.body);
}

function ManagementModal({ title, subtitle = '', icon: Icon, children, onClose, className = '' }) {
  const panelRef = useRef(null);
  const [fitsViewport, setFitsViewport] = useState(true);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = previousOverflow; };
  }, []);

  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  useLayoutEffect(() => {
    function syncModalPosition() {
      const panel = panelRef.current;
      if (!panel) return;
      const availableHeight = window.innerHeight - 40;
      setFitsViewport(panel.scrollHeight <= availableHeight);
    }

    syncModalPosition();
    window.addEventListener('resize', syncModalPosition);
    return () => window.removeEventListener('resize', syncModalPosition);
  }, [children]);

  useEffect(() => {
    const firstField = panelRef.current?.querySelector('input:not([type="hidden"]), select, textarea, button');
    window.setTimeout(() => firstField?.focus({ preventScroll: true }), 80);
  }, []);

  return createPortal(
    <div className={`people-modal-backdrop ${fitsViewport ? 'is-centered' : 'is-scrollable'}`} onClick={(event) => event.target === event.currentTarget && onClose()}>
      <section className={`people-modal-panel ${className}`} ref={panelRef} role="dialog" aria-modal="true" aria-label={title}>
        <div className="box-title people-modal-header"><div className="people-modal-heading">{Icon && <span><Icon size={20} /></span>}<div><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</div></div><button type="button" className="modal-icon-close" onClick={onClose} aria-label={`Close ${title}`} title="Close"><X size={18} /></button></div>
        {children}
      </section>
    </div>,
    document.body
  );
}
