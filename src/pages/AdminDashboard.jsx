import { Navigate, useLocation, useParams } from 'react-router-dom';
import { useEffect, useMemo, useState, useCallback } from 'react';
import { Bell, Clock, KeyRound, Monitor, Moon, RotateCcw, Archive, FileText, Save, Search, ShieldCheck } from 'lucide-react';
import { addToast } from '../components/common/Toast.jsx';
import { confirmDeleteData, confirmProceed, confirmSaveChanges } from '../components/common/ConfirmationModal.jsx';
import ErrorBoundary from '../components/common/ErrorBoundary.jsx';
import Hero from '../components/common/Hero.jsx';
import ReportGrid from '../components/common/ReportGrid.jsx';
import EvaluationAssignmentWorkbench from '../components/evaluations/EvaluationAssignmentWorkbench.jsx';
import PeopleManagementPage from '../components/people/PeopleManagementPage.jsx';
import DataTable from '../components/common/DataTable.jsx';
import AdminEvaluationMonitor from '../components/evaluations/AdminEvaluationMonitor.jsx';
import AdminDashboardOverview from '../components/dashboard/AdminDashboardOverview.jsx';
import PeriodSelector from '../components/evaluations/PeriodSelector.jsx';
import { useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';
import useRealtimeMetrics from '../hooks/useRealtimeMetrics.js';
import useLiveRefresh from '../hooks/useLiveRefresh.js';
import apiFetch from '../data/api.js';
import { assetUrl } from '../data/apiBase.js';

const adminSettingsKey = 'dipascaf-admin-settings-v1';

const defaultAdminSettings = {
  dashboardRefreshSeconds: 10,
  notificationsEnabled: true,
  reminderLeadDays: 3,
  dailyReminderTime: '08:00',
  themePreference: 'light',
  sessionTimeoutMinutes: 30,
  requireStrongPasswords: true,
};

function readAdminSettings() {
  if (typeof window === 'undefined') return defaultAdminSettings;
  try {
    const stored = window.localStorage.getItem(adminSettingsKey);
    if (!stored) return defaultAdminSettings;
    const parsed = JSON.parse(stored);
    return {
      ...defaultAdminSettings,
      ...parsed,
      themePreference: parsed.themePreference === 'dark' ? 'dark' : 'light',
    };
  } catch {
    return defaultAdminSettings;
  }
}

function applyAdminSettings(settings) {
  if (typeof window === 'undefined') return;
  window.localStorage.setItem(adminSettingsKey, JSON.stringify(settings));
  window.localStorage.setItem('dipascaf-theme-preference', settings.themePreference);
  window.localStorage.setItem('dipascaf-dark-mode', JSON.stringify(settings.themePreference === 'dark'));
  window.dispatchEvent(new CustomEvent('dipascaf-settings-updated', { detail: settings }));
}

function mapApiUser(apiUser) {
  return {
    id: Number(apiUser.id),
    fullName: apiUser.full_name || '',
    role: apiUser.role === 'admin_hr' ? 'Admin/HR' : apiUser.role === 'vpaa' ? 'VPAA' : apiUser.role === 'dean' ? 'Dean' : apiUser.role === 'program_head' ? 'Program Head' : 'Faculty',
    department: apiUser.department || '',
    program: apiUser.program || '',
    email: String(apiUser.email || '').toLowerCase().endsWith('@pmas.local') ? '' : (apiUser.email || ''),
    status: apiUser.is_active == 1 ? 'Active' : 'Inactive',
    avatar: assetUrl(apiUser.profile_image || ''),
    archivedAt: apiUser.archived_at || 'Inactive in database',
  };
}

function mapApiDepartment(department) {
  return {
    ...department,
    logo: assetUrl(department.logo || 'assets/images/ndmc-seal.png'),
  };
}

export default function AdminDashboard({ role, onUserUpdate }) {
  const { section = 'dashboard' } = useParams();
  const location = useLocation();
  const [activeSettingsTab, setActiveSettingsTab] = useState('profile');
  const [departments, setDepartments] = useState([]);
  const [users, setUsers] = useState([]);
  const [archivedDepartments, setArchivedDepartments] = useState([]);
  const [archivedUsers, setArchivedUsers] = useState([]);
  const { selectedPeriodId, periods } = useEvaluationPeriod();
  const [adminSettings, setAdminSettings] = useState(readAdminSettings);
  const [profileForm, setProfileForm] = useState({
    fullName: role.user.name || '',
  });
  const [securityForm, setSecurityForm] = useState({
    currentPassword: '',
    newPassword: '',
    confirmPassword: '',
  });
  const [settingsSaving, setSettingsSaving] = useState(false);
  const [settingsMessage, setSettingsMessage] = useState(null);
  const [dashboardFilters, setDashboardFilters] = useState({ department: '', program: '', comparisonPeriodId: '' });

  // ── Archived questionnaire categories state ─────────────────────
  const [archivedFormACategories, setArchivedFormACategories] = useState([]);
  const [archivedFormBCategories, setArchivedFormBCategories] = useState([]);
  const [archivedEvaluations, setArchivedEvaluations] = useState([]);
  const [archiveEvaluationSearch, setArchiveEvaluationSearch] = useState('');
  const [archiveEvaluationPage, setArchiveEvaluationPage] = useState(1);
  const archiveEvaluationPageSize = 10;
  const filteredArchivedEvaluations = useMemo(() => {
    const needle = archiveEvaluationSearch.trim().toLowerCase();
    if (!needle) return archivedEvaluations;
    return archivedEvaluations.filter((item) => [
      item.cycle_name,
      item.evaluatee_name,
      item.department,
      item.program,
      item.assignment_type,
      item.archive_scope,
      item.status,
      item.archived_at,
    ].some((value) => String(value || '').toLowerCase().includes(needle)));
  }, [archiveEvaluationSearch, archivedEvaluations]);
  const archiveEvaluationPages = Math.max(1, Math.ceil(filteredArchivedEvaluations.length / archiveEvaluationPageSize));
  const visibleArchivedEvaluations = filteredArchivedEvaluations.slice(
    (archiveEvaluationPage - 1) * archiveEvaluationPageSize,
    archiveEvaluationPage * archiveEvaluationPageSize,
  );

  useEffect(() => {
    setArchiveEvaluationPage(1);
  }, [archiveEvaluationSearch]);

  useEffect(() => {
    setArchiveEvaluationPage((current) => Math.min(current, archiveEvaluationPages));
  }, [archiveEvaluationPages]);

  useEffect(() => {
    setProfileForm({
      fullName: role.user.name || '',
    });
  }, [role.user.name]);

  function updateAdminSetting(name, value) {
    setAdminSettings((prev) => ({ ...prev, [name]: value }));
  }

  function updateSecurityForm(name, value) {
    setSecurityForm((prev) => ({ ...prev, [name]: value }));
  }

  function validateSettings() {
    if (!profileForm.fullName.trim()) return 'Full name is required.';
    const refreshSeconds = Number(adminSettings.dashboardRefreshSeconds);
    if (!Number.isFinite(refreshSeconds) || refreshSeconds < 5) return 'Dashboard refresh must be at least 5 seconds.';
    const sessionMinutes = Number(adminSettings.sessionTimeoutMinutes);
    if (!Number.isFinite(sessionMinutes) || sessionMinutes < 5) return 'Session timeout must be at least 5 minutes.';
    if (securityForm.currentPassword || securityForm.newPassword || securityForm.confirmPassword) {
      if (!securityForm.currentPassword) return 'Current password is required to change your password.';
      if (!securityForm.newPassword) return 'Enter a new password.';
      if (securityForm.newPassword.length < 8) return 'New password must be at least 8 characters.';
      if (adminSettings.requireStrongPasswords && !/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.test(securityForm.newPassword)) {
        return 'Strong passwords need uppercase, lowercase, and number characters.';
      }
      if (securityForm.newPassword !== securityForm.confirmPassword) return 'Confirm password must match the new password.';
    }
    return '';
  }

  async function saveAdminSettings(event) {
    event.preventDefault();
    const validationMessage = validateSettings();
    if (validationMessage) {
      setSettingsMessage({ type: 'error', text: validationMessage });
      return;
    }

    const confirmed = await confirmSaveChanges();
    if (!confirmed) return;

    setSettingsSaving(true);
    setSettingsMessage(null);

    const nextSettings = {
      ...adminSettings,
      dashboardRefreshSeconds: Math.max(5, Number(adminSettings.dashboardRefreshSeconds) || defaultAdminSettings.dashboardRefreshSeconds),
      reminderLeadDays: Math.max(1, Number(adminSettings.reminderLeadDays) || defaultAdminSettings.reminderLeadDays),
      sessionTimeoutMinutes: Math.max(5, Number(adminSettings.sessionTimeoutMinutes) || defaultAdminSettings.sessionTimeoutMinutes),
    };

    try {
      const shouldUpdateProfile = profileForm.fullName.trim() !== (role.user.name || '') || securityForm.currentPassword || securityForm.newPassword || securityForm.confirmPassword;
      if (shouldUpdateProfile) {
        const formData = new FormData();
        formData.append('full_name', profileForm.fullName.trim());
        if (securityForm.currentPassword) formData.append('current_password', securityForm.currentPassword);
        if (securityForm.newPassword) formData.append('new_password', securityForm.newPassword);
        if (securityForm.confirmPassword) formData.append('confirm_password', securityForm.confirmPassword);
        const profilePayload = await apiFetch('/api/profile.php', {
          method: 'POST',
          body: formData,
        });
        if (profilePayload.user) {
          onUserUpdate?.(profilePayload.user);
        }
      }

      applyAdminSettings(nextSettings);
      setAdminSettings(nextSettings);
      setSecurityForm({ currentPassword: '', newPassword: '', confirmPassword: '' });
      setSettingsMessage({ type: 'success', text: 'Settings saved and applied.' });
      addToast({ type: 'success', text: 'Settings saved successfully.' });
    } catch (error) {
      setSettingsMessage({ type: 'error', text: error.message || 'Unable to save settings.' });
      addToast({ type: 'error', text: error.message || 'Unable to save settings.' });
    } finally {
      setSettingsSaving(false);
    }
  }

  const loadData = useCallback(async () => {
    try {
      const [deptPayload, peoplePayload, archivedDeptPayload, allPeoplePayload] = await Promise.all([
        apiFetch('/api/departments.php'),
        apiFetch('/api/people.php'),
        apiFetch('/api/departments.php?include_inactive=1'),
        apiFetch('/api/people.php?active_only=0'),
      ]);

      if (deptPayload.ok && Array.isArray(deptPayload.data)) {
        setDepartments(deptPayload.data.map(mapApiDepartment));
      } else {
        setDepartments([]);
      }

      if (peoplePayload.ok && Array.isArray(peoplePayload.users)) {
        setUsers(peoplePayload.users.map(mapApiUser));
      } else {
        setUsers([]);
      }

      if (archivedDeptPayload.ok && Array.isArray(archivedDeptPayload.data)) {
        setArchivedDepartments(
          archivedDeptPayload.data
            .filter((department) => Number(department.is_active) === 0)
            .map((department) => ({
              ...mapApiDepartment(department),
              archivedAt: department.archived_at || 'Archived in database',
            }))
        );
      } else {
        setArchivedDepartments([]);
      }

      if (allPeoplePayload.ok && Array.isArray(allPeoplePayload.users)) {
        setArchivedUsers(allPeoplePayload.users.map(mapApiUser).filter((user) => user.status === 'Inactive'));
      } else {
        setArchivedUsers([]);
      }
    } catch (_) {
      setDepartments([]);
      setUsers([]);
      setArchivedDepartments([]);
      setArchivedUsers([]);
    }
  }, []);

  // ── Fetch departments & users from API on mount ────────────────────
  useEffect(() => {
    loadData();
  }, [loadData]);
  useLiveRefresh(loadData, [], { immediate: false, intervalMs: 0 });

  // ── Load archived questionnaire categories ─────────────────────
  const loadArchivedCategories = useCallback(async () => {
    try {
      const [formARes, formBRes] = await Promise.all([
        apiFetch('/api/form_a_admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'list_archived' }),
        }),
        apiFetch('/api/form_b_admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'list_archived' }),
        }),
      ]);
      if (formARes.ok && Array.isArray(formARes.categories)) {
        setArchivedFormACategories(formARes.categories);
      }
      if (formBRes.ok && Array.isArray(formBRes.categories)) {
        setArchivedFormBCategories(formBRes.categories);
      }
    } catch (_) {
      // API unavailable — empty state is fine
    }
  }, []);

  const loadArchivedEvaluations = useCallback(async () => {
    try {
      const payload = await apiFetch('/api/archived-evaluations.php');
      if (payload.ok && Array.isArray(payload.data)) {
        setArchivedEvaluations(payload.data);
      }
    } catch (_) {
      // API unavailable — empty state is fine
    }
  }, []);

  // Load archived categories when archive tab is active
  useEffect(() => {
    if (activeSettingsTab === 'archive') {
      loadArchivedCategories();
      loadArchivedEvaluations();
    }
  }, [activeSettingsTab, loadArchivedCategories, loadArchivedEvaluations]);

  useEffect(() => {
    const params = new URLSearchParams(location.search);
    if (section === 'settings' && params.get('tab') === 'archive') {
      setActiveSettingsTab('archive');
    }
  }, [location.search, section]);

  // ── Archive/restore category handlers ───────────────────────────
  async function archiveFormACategory(categoryId) {
    const confirmed = await confirmDeleteData({
      message: 'Questions under this Form A category will be hidden from new evaluations. This action cannot be undone.',
      confirmText: 'Archive',
    });
    if (!confirmed) return;
    try {
      const result = await apiFetch('/api/form_a_admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'archive_category', category_id: categoryId }),
      });
      if (!result.ok) throw new Error(result.message);
      addToast({ type: 'success', text: 'Form A category archived successfully.' });
      await loadArchivedCategories();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to archive Form A category: ' + (error.message || 'Unknown error') });
    }
  }

  async function restoreFormACategory(categoryId, categoryTitle) {
    const confirmed = await confirmProceed({
      message: `"${categoryTitle}" will be available for new evaluations.`,
      confirmText: 'Restore',
    });
    if (!confirmed) return;
    try {
      const result = await apiFetch('/api/form_a_admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'restore_category', category_id: categoryId }),
      });
      if (!result.ok) throw new Error(result.message);
      addToast({ type: 'success', text: 'Form A category restored successfully.' });
      await loadArchivedCategories();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to restore category: ' + (error.message || 'Unknown error') });
    }
  }

  async function archiveFormBCategory(categoryId) {
    const confirmed = await confirmDeleteData({
      message: 'Questions under this Form B category will be hidden from new evaluations. This action cannot be undone.',
      confirmText: 'Archive',
    });
    if (!confirmed) return;
    try {
      const result = await apiFetch('/api/form_b_admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'archive_category', category_id: categoryId }),
      });
      if (!result.ok) throw new Error(result.message);
      addToast({ type: 'success', text: 'Form B category archived successfully.' });
      await loadArchivedCategories();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to archive Form B category: ' + (error.message || 'Unknown error') });
    }
  }

  async function restoreFormBCategory(categoryId, categoryTitle) {
    const confirmed = await confirmProceed({
      message: `"${categoryTitle}" will be available for new evaluations.`,
      confirmText: 'Restore',
    });
    if (!confirmed) return;
    try {
      const result = await apiFetch('/api/form_b_admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'restore_category', category_id: categoryId }),
      });
      if (!result.ok) throw new Error(result.message);
      addToast({ type: 'success', text: 'Form B category restored successfully.' });
      await loadArchivedCategories();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to restore category: ' + (error.message || 'Unknown error') });
    }
  }

  const dashboardRefreshMs = Math.max(5, Number(adminSettings.dashboardRefreshSeconds) || 10) * 1000;

  // Real-time metrics from backend API - auto-refreshes using the saved dashboard setting
  const { overview, loading, error, timestamp } = useRealtimeMetrics(
    'admin',
    { periodId: selectedPeriodId, department: dashboardFilters.department, program: dashboardFilters.program, comparisonPeriodId: dashboardFilters.comparisonPeriodId },
    dashboardRefreshMs
  );

  async function restoreDepartment(id) {
    const target = archivedDepartments.find((department) => department.id === id);
    if (!target) return;
    const confirmed = await confirmProceed({
      message: `${target.name} will reappear in the active department directory.`,
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
      addToast({ type: 'success', text: 'Department restored successfully.' });
      await loadData();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to restore department: ' + (error.message || 'Unknown error') });
    }
  }

  async function restoreUser(id) {
    const target = archivedUsers.find((user) => user.id === id);
    if (!target) return;
    const confirmed = await confirmProceed({
      message: `${target.fullName} will reappear in the active user list.`,
      confirmText: 'Restore',
    });
    if (!confirmed) return;

    try {
      const result = await apiFetch(`/api/people.php?id=${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'restore' }),
      });
      if (!result.ok) throw new Error(result.message || 'Failed to restore user.');

      addToast({ type: 'success', text: 'User restored successfully.' });
      await loadData();
    } catch (error) {
      addToast({ type: 'error', text: 'Failed to restore user: ' + (error.message || 'Unknown error') });
    }
  }

  if (section === 'evaluations') {
    return <Navigate to="/admin/assignments" replace />;
  }

  return (
    <section className={`admin-content admin-module ${section === 'ai-actions' ? 'evaluation-monitor-page' : ''}`}>
      {section === 'dashboard' && (
        <>
          <Hero
            className="admin-dashboard-hero welcome-dashboard-hero"
            eyebrow="Admin/HR Dashboard"
            title={role.user.name ? `Welcome back, ${role.user.name}` : 'Welcome back'}
            actions={<PeriodSelector compact className="dashboard-period-selector" />}
          >
            Monitor faculty appraisal progress, user records, AI insights, evaluation assignments, reports, and system settings in one organized workspace.
          </Hero>
          <section className="admin-dashboard-unified module-wide page-enter" aria-labelledby="admin-dashboard-unified-title">
            <div className="action-center-head admin-overview-title">
              <div>
                <p className="eyebrow">Dashboard</p>
                <h2 id="admin-dashboard-unified-title">Dashboard Summary</h2>
                <p>{error ? `Live refresh paused: ${error}` : `Review evaluation progress and urgent staffing assignments${timestamp ? `, updated ${new Date(timestamp * 1000).toLocaleTimeString()}` : ''}.`}</p>
              </div>
            </div>
            <AdminDashboardOverview overview={overview} loading={loading} error={error} filters={dashboardFilters} onFiltersChange={setDashboardFilters} periods={periods} selectedPeriodId={selectedPeriodId} />
          </section>
        </>
      )}

      {(section === 'people' || section === 'department') && <PeopleManagementPage />}

      {section === 'assignments' && (
        <ErrorBoundary
          key={location.pathname + location.search}
          title="Evaluation Assignment could not be displayed."
          message="The assignment workbench hit an interface error while loading. Reload this section to continue."
        >
          <EvaluationAssignmentWorkbench initialTab="assignment" />
        </ErrorBoundary>
      )}

      {section === 'ai-actions' && (
        <>
          <AdminEvaluationMonitor />
        </>
      )}

      {section === 'reports' && <ReportGrid role={role} />}

      {section === 'settings' && (
        <section className="admin-box module-wide page-enter">
          <div className="box-title">
            <h2>Settings & Archive</h2>
            <span>Profile, system preferences, and archived records</span>
          </div>

          {/* Settings Tabs */}
          <div className="settings-tabs">
            <button
              className={`settings-tab ${activeSettingsTab === 'profile' ? 'active' : ''}`}
              onClick={() => setActiveSettingsTab('profile')}
            >
              Profile & System
            </button>
            <button
              className={`settings-tab ${activeSettingsTab === 'archive' ? 'active' : ''}`}
              onClick={() => setActiveSettingsTab('archive')}
            >
              Archive ({archivedDepartments.length + archivedUsers.length + archivedEvaluations.length})
            </button>
          </div>

          {/* Profile & System Settings Tab */}
          {activeSettingsTab === 'profile' && (
            <form className="admin-form settings-feature-form" onSubmit={saveAdminSettings}>
              <div className="settings-feature-grid">
                <section className="settings-control-card">
                  <div className="settings-card-head">
                    <span className="settings-card-icon"><ShieldCheck size={20} /></span>
                    <div>
                      <h3>Admin Profile</h3>
                      <p>Basic account identity used across the dashboard.</p>
                    </div>
                  </div>
                  <div className="settings-field-grid">
                    <label>Full Name
                      <input value={profileForm.fullName} onChange={(event) => setProfileForm((prev) => ({ ...prev, fullName: event.target.value }))} />
                    </label>
                  </div>
                </section>

                <section className="settings-control-card">
                  <div className="settings-card-head">
                    <span className="settings-card-icon"><Bell size={20} /></span>
                    <div>
                      <h3>Notification & Reminder Settings</h3>
                      <p>Controls live notification polling and evaluation reminder timing.</p>
                    </div>
                  </div>
                  <div className="settings-toggle-list">
                    <label className="settings-toggle-row">
                      <input
                        type="checkbox"
                        checked={adminSettings.notificationsEnabled}
                        onChange={(event) => updateAdminSetting('notificationsEnabled', event.target.checked)}
                      />
                      <span>
                        <strong>Enable notifications and reminders</strong>
                        <small>{adminSettings.notificationsEnabled ? 'Topbar notifications are active.' : 'Topbar notification polling is paused.'}</small>
                      </span>
                    </label>
                  </div>
                  <div className="settings-field-grid">
                    <label>Reminder Lead Days
                      <input
                        type="number"
                        min="1"
                        max="14"
                        value={adminSettings.reminderLeadDays}
                        onChange={(event) => updateAdminSetting('reminderLeadDays', event.target.value)}
                      />
                    </label>
                    <label>Daily Reminder Time
                      <input
                        type="time"
                        value={adminSettings.dailyReminderTime}
                        onChange={(event) => updateAdminSetting('dailyReminderTime', event.target.value)}
                      />
                    </label>
                  </div>
                </section>

                <section className="settings-control-card">
                  <div className="settings-card-head">
                    <span className="settings-card-icon"><Clock size={20} /></span>
                    <div>
                      <h3>Dashboard Refresh Settings</h3>
                      <p>Adjusts how often dashboard metrics reload while this browser is open.</p>
                    </div>
                  </div>
                  <label>Dashboard Refresh Seconds
                    <input
                      type="number"
                      min="5"
                      max="120"
                      value={adminSettings.dashboardRefreshSeconds}
                      onChange={(event) => updateAdminSetting('dashboardRefreshSeconds', event.target.value)}
                    />
                  </label>
                  <div className="settings-live-pill"><Monitor size={15} /> Active interval: {Math.max(5, Number(adminSettings.dashboardRefreshSeconds) || 10)} seconds</div>
                </section>

                <section className="settings-control-card">
                  <div className="settings-card-head">
                    <span className="settings-card-icon"><Moon size={20} /></span>
                    <div>
                      <h3>Theme Preference</h3>
                      <p>All users start in light mode and may switch to dark mode manually.</p>
                    </div>
                  </div>
                  <label>Theme Mode
                    <select
                      value={adminSettings.themePreference}
                      onChange={(event) => updateAdminSetting('themePreference', event.target.value)}
                    >
                      <option value="light">Light Mode</option>
                      <option value="dark">Dark Mode</option>
                    </select>
                  </label>
                </section>

                <section className="settings-control-card settings-security-card">
                  <div className="settings-card-head">
                    <span className="settings-card-icon"><KeyRound size={20} /></span>
                    <div>
                      <h3>Security & Password Settings</h3>
                      <p>Update your password and basic session safeguards.</p>
                    </div>
                  </div>
                  <div className="settings-field-grid settings-password-grid">
                    <label>Current Password
                      <input
                        type="password"
                        value={securityForm.currentPassword}
                        onChange={(event) => updateSecurityForm('currentPassword', event.target.value)}
                        autoComplete="current-password"
                      />
                    </label>
                    <label>New Password
                      <input
                        type="password"
                        value={securityForm.newPassword}
                        onChange={(event) => updateSecurityForm('newPassword', event.target.value)}
                        autoComplete="new-password"
                      />
                    </label>
                    <label>Confirm New Password
                      <input
                        type="password"
                        value={securityForm.confirmPassword}
                        onChange={(event) => updateSecurityForm('confirmPassword', event.target.value)}
                        autoComplete="new-password"
                      />
                    </label>
                    <label>Session Timeout Minutes
                      <input
                        type="number"
                        min="5"
                        max="240"
                        value={adminSettings.sessionTimeoutMinutes}
                        onChange={(event) => updateAdminSetting('sessionTimeoutMinutes', event.target.value)}
                      />
                    </label>
                  </div>
                  <label className="settings-toggle-row">
                    <input
                      type="checkbox"
                      checked={adminSettings.requireStrongPasswords}
                      onChange={(event) => updateAdminSetting('requireStrongPasswords', event.target.checked)}
                    />
                    <span>
                      <strong>Require strong passwords</strong>
                      <small>Uppercase, lowercase, and number checks are enforced before saving.</small>
                    </span>
                  </label>
                </section>
              </div>

              {settingsMessage && <div className={`settings-message ${settingsMessage.type}`}>{settingsMessage.text}</div>}
              <div className="settings-save-row">
                <button type="submit" className="primary-button" disabled={settingsSaving}>
                  <Save size={16} /> {settingsSaving ? 'Saving...' : 'Save Settings'}
                </button>
              </div>
            </form>
          )}

          {/* Archive Tab */}
          {activeSettingsTab === 'archive' && (
            <div className="settings-archive-content">
              {/* ── Archived Departments ── */}
              <div className="archive-section">
                <h3>Archived Departments ({archivedDepartments.length})</h3>
                {archivedDepartments.length > 0 ? (
                  <div className="archive-grid">
                    {archivedDepartments.map((dept) => (
                      <article className="archive-card" key={dept.id}>
                        <div className="archive-card-header">
                          <Archive className="h-4 w-4" />
                          <span className="archive-date">Archived: {dept.archivedAt}</span>
                        </div>
                        <h4>{dept.name}</h4>
                        <p className="archive-code">{dept.code}</p>
                        <p className="archive-info">Dean: {dept.dean || 'Unassigned'}</p>
                        <button
                          type="button"
                          className="restore-button"
                          onClick={() => restoreDepartment(dept.id)}
                        >
                          <RotateCcw className="h-4 w-4" /> Restore
                        </button>
                      </article>
                    ))}
                  </div>
                ) : (
                  <div className="empty-state">No archived departments</div>
                )}
              </div>

              {/* ── Archived Users (grouped by role) ── */}
              <div className="archive-section">
                <h3>Archived Users ({archivedUsers.length})</h3>
                {archivedUsers.length > 0 ? (
                  <div className="archive-by-role">
                    {['Admin/HR', 'VPAA', 'Dean', 'Program Head', 'Faculty'].map((role) => {
                      const usersInRole = archivedUsers.filter(u => u.role === role);
                      if (usersInRole.length === 0) return null;
                      return (
                        <div key={role} className="archive-role-group">
                          <h4 className="archive-role-heading">{role} ({usersInRole.length})</h4>
                          <div className="archive-table">
                            <table>
                              <thead>
                                <tr>
                                  <th>Name</th>
                                  <th>Department</th>
                                  <th>Archived</th>
                                  <th>Action</th>
                                </tr>
                              </thead>
                              <tbody>
                                {usersInRole.map((user) => (
                                  <tr key={user.id}>
                                    <td>{user.fullName}</td>
                                    <td>{user.department || '—'}</td>
                                    <td>{user.archivedAt}</td>
                                    <td>
                                      <button
                                        type="button"
                                        className="restore-button compact"
                                        onClick={() => restoreUser(user.id)}
                                      >
                                        <RotateCcw className="h-4 w-4" /> Restore
                                      </button>
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  <div className="empty-state">No archived users</div>
                )}
              </div>

              {/* ── Archived Evaluations ── */}
              <div className="archive-section archive-evaluations-section">
                <div className="archive-section-heading">
                  <div>
                    <h3>Archived Evaluations</h3>
                    <span>{archivedEvaluations.length} total record(s)</span>
                  </div>
                  <label className="archive-evaluation-search">
                    <Search size={16} />
                    <input
                      type="search"
                      value={archiveEvaluationSearch}
                      onChange={(event) => setArchiveEvaluationSearch(event.target.value)}
                      placeholder="Search period, faculty, department..."
                    />
                  </label>
                </div>
                {archivedEvaluations.length > 0 ? (
                  <div className="archive-evaluation-list">
                    <div className="archive-table archive-evaluation-table">
                      <table>
                        <thead>
                          <tr>
                            <th>Evaluation Period</th>
                            <th>Evaluatee</th>
                            <th>Department / Program</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Archived</th>
                          </tr>
                        </thead>
                        <tbody>
                          {visibleArchivedEvaluations.map((item) => (
                            <tr key={item.assignment_id}>
                              <td>{item.cycle_name || '—'}</td>
                              <td><strong>{item.evaluatee_name || '—'}</strong></td>
                              <td>
                                {item.department || '—'}
                                {item.program ? <small>{item.program}</small> : null}
                              </td>
                              <td>{item.assignment_type || item.archive_scope || '—'}</td>
                              <td><span className="archive-status-pill">{item.status || '—'}</span></td>
                              <td>{item.archived_at || '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {filteredArchivedEvaluations.length === 0 && (
                      <div className="empty-state">No archived evaluations match your search.</div>
                    )}
                    {filteredArchivedEvaluations.length > 0 && (
                      <div className="archive-pagination">
                        <span>
                          Showing {(archiveEvaluationPage - 1) * archiveEvaluationPageSize + 1}–
                          {Math.min(archiveEvaluationPage * archiveEvaluationPageSize, filteredArchivedEvaluations.length)}
                          {' '}of {filteredArchivedEvaluations.length}
                        </span>
                        <div>
                          <button
                            type="button"
                            onClick={() => setArchiveEvaluationPage((page) => Math.max(1, page - 1))}
                            disabled={archiveEvaluationPage === 1}
                          >
                            Previous
                          </button>
                          <strong>{archiveEvaluationPage} / {archiveEvaluationPages}</strong>
                          <button
                            type="button"
                            onClick={() => setArchiveEvaluationPage((page) => Math.min(archiveEvaluationPages, page + 1))}
                            disabled={archiveEvaluationPage === archiveEvaluationPages}
                          >
                            Next
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="empty-state">No archived evaluations</div>
                )}
              </div>

            </div>
          )}
        </section>
      )}
    </section>
  );
}
