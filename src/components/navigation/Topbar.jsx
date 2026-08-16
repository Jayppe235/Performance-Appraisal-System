import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowRight, BadgeCheck, Bell, Camera, CheckCheck, ChevronDown, Clock, Dot, Eye, EyeOff, FileText, KeyRound, Loader2, LogOut, Mail, Megaphone, Moon, RotateCcw, Save, Search, ShieldCheck, Sun, Trash2, User, X } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { assetUrl } from '../../data/apiBase.js';
import { confirmLogout, confirmSaveChanges } from '../common/ConfirmationModal.jsx';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';

function notificationsUrl() {
  return '/api/notifications.php';
}

const adminSettingsKey = 'dipascaf-admin-settings-v1';

function readNotificationSettings() {
  if (typeof window === 'undefined') return { notificationsEnabled: true };
  try {
    const stored = window.localStorage.getItem(adminSettingsKey);
    if (!stored) return { notificationsEnabled: true };
    const settings = JSON.parse(stored);
    return {
      notificationsEnabled: settings.notificationsEnabled !== false,
      reminderLeadDays: settings.reminderLeadDays || 3,
      dailyReminderTime: settings.dailyReminderTime || '08:00',
    };
  } catch {
    return { notificationsEnabled: true };
  }
}

function formatRole(role) {
  return String(role || 'system').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function profileImageSrc(path, version = '') {
  const value = String(path || '').trim();
  if (value === '') return '';
  if (value.startsWith('data:') || value.startsWith('blob:')) return value;
  const suffix = version ? `${value.includes('?') ? '&' : '?'}v=${encodeURIComponent(version)}` : '';
  if (/^(https?:)?\/\//i.test(value) || value.startsWith('/')) return `${value}${suffix}`;
  return `${assetUrl(value)}${suffix}`;
}

function getTypeIcon(type) {
  if (type === 'approval') return ShieldCheck;
  if (type === 'revision') return RotateCcw;
  if (type === 'evaluation' || type === 'success') return CheckCheck;
  if (type === 'report') return FileText;
  if (type === 'info' || type === 'account_activity') return User;
  return Megaphone;
}

function getTypeLabel(type) {
  if (type === 'approval') return 'Approval';
  if (type === 'revision') return 'Revision';
  if (type === 'evaluation') return 'Evaluation';
  if (type === 'report') return 'Report';
  if (type === 'success') return 'Success';
  if (type === 'warning') return 'Warning';
  if (type === 'error') return 'Error';
  if (type === 'info' || type === 'account_activity') return 'Account';
  return 'System';
}

function getTypeClass(type) {
  if (type === 'approval') return 'type-approval';
  if (type === 'revision' || type === 'warning') return 'type-warning';
  if (type === 'evaluation' || type === 'success') return 'type-evaluation';
  if (type === 'report') return 'type-report';
  if (type === 'error') return 'type-error';
  if (type === 'info' || type === 'account_activity') return 'type-account';
  return 'type-system';
}

const notificationFilters = [
  { key: 'all', label: 'All' },
  { key: 'unread', label: 'Unread' },
  { key: 'evaluations', label: 'Evaluations' },
  { key: 'approvals', label: 'Approvals' },
  { key: 'reports', label: 'Reports' },
  { key: 'system', label: 'System' },
];

export default function Topbar({ role, onOpenMenu, onUserUpdate, darkMode = false, onToggleDark, onResetDark, onLogout }) {
  const { selectedPeriodId } = useEvaluationPeriod();
  const { section = role.nav[0].key } = useParams();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const [searchFocused, setSearchFocused] = useState(false);
  const recentSearchKey = `dipascaf-recent-searches-${role.key}`;
  const [recentSearches, setRecentSearches] = useState(() => {
    try { return JSON.parse(window.localStorage.getItem(recentSearchKey) || '[]'); } catch { return []; }
  });
  const [notifications, setNotifications] = useState([]);
  const [notificationOpen, setNotificationOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [accountClosing, setAccountClosing] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [profileName, setProfileName] = useState(role.user.name || '');
  const [profileEmail, setProfileEmail] = useState(String(role.user.email || '').toLowerCase().endsWith('@pmas.local') ? '' : (role.user.email || ''));
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPasswords, setShowPasswords] = useState(false);
  const [passwordEditorOpen, setPasswordEditorOpen] = useState(false);
  const passwordSectionRef = useRef(null);
  const [profileImage, setProfileImage] = useState(null);
  const [profilePreview, setProfilePreview] = useState('');
  const [profileImageVersion, setProfileImageVersion] = useState('');
  const [removeProfileImage, setRemoveProfileImage] = useState(false);
  const [profileMessage, setProfileMessage] = useState(null);
  const [profileSaving, setProfileSaving] = useState(false);
  const [hasNewNotification, setHasNewNotification] = useState(false);

  useEffect(() => {
    if (!profileOpen || !passwordEditorOpen) return undefined;

    let revealTimer;
    const frame = window.requestAnimationFrame(() => {
      revealTimer = window.setTimeout(() => {
        passwordSectionRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }, 60);
    });

    return () => {
      window.cancelAnimationFrame(frame);
      window.clearTimeout(revealTimer);
    };
  }, [profileOpen, passwordEditorOpen]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [markingRead, setMarkingRead] = useState(false);
  const [notificationFilter, setNotificationFilter] = useState('all');
  const [notificationLoading, setNotificationLoading] = useState(false);
  const [notificationError, setNotificationError] = useState('');
  const [notificationSettings, setNotificationSettings] = useState(readNotificationSettings);
  const notificationPanelRef = useRef(null);
  const accountMenuRef = useRef(null);
  const accountCloseTimerRef = useRef(null);
  const profileFileInputRef = useRef(null);
  const active = useMemo(() => role.nav.find((item) => item.key === section) || role.nav[0], [role, section]);
  const matches = role.nav.filter((item) => item.label.toLowerCase().includes(query.toLowerCase()));
  const suggestedSearches = role.key === 'admin' ? ['Evaluation Assignments', 'Evaluation Monitoring', 'Reports', 'People Management'] : role.nav.slice(0, 4).map(item => item.label);
  const searchSuggestions = query.trim() ? matches : [
    ...recentSearches.map(label => role.nav.find(item => item.label === label)).filter(Boolean),
    ...suggestedSearches.map(label => role.nav.find(item => item.label === label)).filter(Boolean),
  ].filter((item,index,items)=>items.findIndex(candidate=>candidate.key===item.key)===index).slice(0,6);
  const latestNotificationId = Number(notifications[0]?.id || 0);
  const avatarSrc = profileImageSrc(role.user.profileImage, profileImageVersion);
  const avatarLabel = role.user.name || role.portal || 'User';
  const roleLabel = formatRole(role.user.databaseRole || role.key);
  const isVpaaAccount = role.key === 'vpaa' || String(role.user.roleKey || role.user.databaseRole || '').toLowerCase() === 'vpaa';
  const profileDepartment = isVpaaAccount ? 'VPAA' : (role.user.department || 'Unassigned');
  const [periodProfilePrograms, setPeriodProfilePrograms] = useState([]);
  const profileProgram = periodProfilePrograms.length
    ? periodProfilePrograms.map((program) => program.code).join(', ')
    : (role.user.program || 'Unassigned');
  const passwordScore = [
    newPassword.length >= 8,
    /[A-Z]/.test(newPassword),
    /[a-z]/.test(newPassword),
    /\d/.test(newPassword),
    /[^A-Za-z0-9]/.test(newPassword),
  ].filter(Boolean).length;
  const passwordStrength = newPassword
    ? passwordScore >= 5 ? 'Strong' : passwordScore >= 3 ? 'Good' : 'Weak'
    : 'Not set';
  const passwordInputType = showPasswords ? 'text' : 'password';

  useEffect(() => {
    if (role.key !== 'program_head' || !selectedPeriodId) {
      setPeriodProfilePrograms([]);
      return;
    }
    let active = true;
    apiFetch(`/api/dashboard.php?role=program_head&period_id=${encodeURIComponent(selectedPeriodId)}`)
      .then((payload) => {
        if (active && payload.ok) setPeriodProfilePrograms(payload.data?.programs || []);
      })
      .catch(() => {
        if (active) setPeriodProfilePrograms([]);
      });
    return () => { active = false; };
  }, [role.key, selectedPeriodId]);

  useEffect(() => {
    function syncNotificationSettings() {
      setNotificationSettings(readNotificationSettings());
    }

    window.addEventListener('dipascaf-settings-updated', syncNotificationSettings);
    window.addEventListener('storage', syncNotificationSettings);
    return () => {
      window.removeEventListener('dipascaf-settings-updated', syncNotificationSettings);
      window.removeEventListener('storage', syncNotificationSettings);
    };
  }, []);

  const loadNotifications = useCallback(async (options = {}) => {
    if (!notificationSettings.notificationsEnabled) return;
    const filter = options.filter || notificationFilter;
    const showLoading = options.showLoading !== false;

    if (showLoading) setNotificationLoading(true);
    try {
      const payload = await apiFetch(`${notificationsUrl()}?action=list&limit=25&filter=${encodeURIComponent(filter)}`);
      if (!payload.ok) {
        throw new Error(payload.message || 'Unable to load notifications.');
      }

      const incoming = payload.notifications || [];
      const latestId = Number(payload.latest_id || incoming[0]?.id || 0);
      const newUnread = Number(payload.unread_count || 0);
      const seenId = Number(window.localStorage.getItem('dipascaf-last-notification-id') || 0);
      setNotifications(incoming);
      setUnreadCount(newUnread);
      setHasNewNotification(latestId > seenId || newUnread > 0);
      setNotificationError('');
    } catch (error) {
      setNotificationError(error.message || 'Unable to load notifications.');
    } finally {
      if (showLoading) setNotificationLoading(false);
    }
  }, [notificationFilter, notificationSettings.notificationsEnabled]);

  useEffect(() => {
    if (!notificationSettings.notificationsEnabled) {
      setNotifications([]);
      setUnreadCount(0);
      setHasNewNotification(false);
      setNotificationOpen(false);
      return undefined;
    }

    let stopped = false;
    const refresh = async () => {
      if (!stopped) {
        await loadNotifications({ showLoading: false });
      }
    };

    refresh();
    const interval = window.setInterval(refresh, 12000);
    return () => {
      stopped = true;
      window.clearInterval(interval);
    };
  }, [loadNotifications, notificationSettings.notificationsEnabled]);

  // Close notification panel on outside click
  useEffect(() => {
    if (!notificationOpen) return;

    function handleClickOutside(event) {
      if (notificationPanelRef.current && !notificationPanelRef.current.contains(event.target)) {
        setNotificationOpen(false);
      }
    }

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [notificationOpen]);

  useEffect(() => {
    if (!accountOpen) return;

    function handleClickOutside(event) {
      if (accountMenuRef.current && !accountMenuRef.current.contains(event.target)) {
        closeAccountMenu();
      }
    }

    function handleAccountKeyDown(event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeAccountMenu();
      }
    }

    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleAccountKeyDown);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleAccountKeyDown);
    };
  }, [accountOpen]);

  useEffect(() => () => window.clearTimeout(accountCloseTimerRef.current), []);

  function closeAccountMenu(immediate = false) {
    window.clearTimeout(accountCloseTimerRef.current);
    if (immediate) {
      setAccountOpen(false);
      setAccountClosing(false);
      return;
    }
    if (!accountOpen || accountClosing) return;
    setAccountClosing(true);
    accountCloseTimerRef.current = window.setTimeout(() => {
      setAccountOpen(false);
      setAccountClosing(false);
    }, 230);
  }

  function toggleAccountMenu() {
    if (accountOpen) {
      closeAccountMenu();
      return;
    }
    window.clearTimeout(accountCloseTimerRef.current);
    setAccountClosing(false);
    setAccountOpen(true);
  }

  async function toggleNotifications() {
    const willOpen = !notificationOpen;
    setNotificationOpen(willOpen);

    if (willOpen) {
      window.localStorage.setItem('dipascaf-last-notification-id', String(latestNotificationId));
      setHasNewNotification(unreadCount > 0);
      await loadNotifications({ showLoading: true });
    }
  }

  async function handleMarkAsRead(event, notificationId) {
    event.stopPropagation();
    if (markingRead) return;
    setMarkingRead(true);
    try {
      const payload = await apiFetch(`${notificationsUrl()}?action=mark_read`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: notificationId }),
      });
      setNotifications((prev) => prev.map((n) => n.id === notificationId ? { ...n, is_read: true } : n));
      setUnreadCount(Number(payload.unread_count ?? Math.max(0, unreadCount - 1)));
      if (notificationFilter === 'unread') {
        await loadNotifications({ showLoading: false });
      }
    } catch {
      // Non-critical
    } finally {
      setMarkingRead(false);
    }
  }

  async function handleDeleteNotification(event, notificationId) {
    event.stopPropagation();
    try {
      const payload = await apiFetch(`${notificationsUrl()}?action=delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: notificationId }),
      });
      setNotifications((prev) => prev.filter((n) => n.id !== notificationId));
      setUnreadCount(Number(payload.unread_count ?? unreadCount));
    } catch (error) {
      setNotificationError(error.message || 'Unable to delete notification.');
    }
  }

  async function handleNotificationAction(event, item) {
    event.stopPropagation();
    const actionUrl = String(item.action_url || item.link || '');
    if (!actionUrl.startsWith('/')) return;
    if (!item.is_read) {
      try {
        await apiFetch(`${notificationsUrl()}?action=mark_read`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: item.id }),
        });
      } catch {
        // Navigation remains available if marking the notification fails.
      }
    }
    setNotificationOpen(false);
    navigate(actionUrl);
  }

  async function handleMarkAllRead() {
    try {
      const payload = await apiFetch(`${notificationsUrl()}?action=mark_all_read`, { method: 'POST' });
      setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true })));
      setUnreadCount(Number(payload.unread_count ?? 0));
      setHasNewNotification(false);
      window.localStorage.setItem('dipascaf-last-notification-id', String(latestNotificationId));
      if (notificationFilter === 'unread') {
        await loadNotifications({ showLoading: false });
      }
    } catch (error) {
      setNotificationError(error.message || 'Unable to mark notifications as read.');
    }
  }

  function submitSearch(event) {
    event.preventDefault();
    if (matches[0]) selectSearchResult(matches[0]);
  }

  function selectSearchResult(item) {
    const next=[item.label,...recentSearches.filter(label=>label!==item.label)].slice(0,5);
    setRecentSearches(next);
    window.localStorage.setItem(recentSearchKey,JSON.stringify(next));
    setQuery(''); setSearchFocused(false); navigate(`${role.basePath}/${item.key}`);
  }

  function openProfile() {
    closeAccountMenu(true);
    resetProfileChanges();
    setProfileImage(null);
    setProfileOpen(true);
  }

  function closeProfile() {
    if (profileSaving) return;
    setProfileOpen(false);
  }

  async function handleLogoutClick() {
    closeAccountMenu(true);
    const confirmed = await confirmLogout();
    if (!confirmed) return;
    await onLogout?.();
  }

  function handleThemeToggle() {
    onToggleDark?.();
    closeAccountMenu(true);
  }

  function handleThemeReset() {
    onResetDark?.();
    closeAccountMenu(true);
  }

  function handleProfileFile(event) {
    const file = event.target.files?.[0] || null;
    setProfileImage(file);
    setRemoveProfileImage(false);
    setProfilePreview(file ? URL.createObjectURL(file) : profileImageSrc(role.user.profileImage, profileImageVersion));
  }

  function resetProfileChanges() {
    setProfileName(role.user.name || '');
    setProfileEmail(String(role.user.email || '').toLowerCase().endsWith('@pmas.local') ? '' : (role.user.email || ''));
    setCurrentPassword('');
    setNewPassword('');
    setConfirmPassword('');
    setShowPasswords(false);
    setPasswordEditorOpen(false);
    setProfileImage(null);
    setRemoveProfileImage(false);
    setProfilePreview(profileImageSrc(role.user.profileImage, profileImageVersion));
    setProfileMessage(null);
    if (profileFileInputRef.current) {
      profileFileInputRef.current.value = '';
    }
  }

  function removePhoto() {
    setProfileImage(null);
    setRemoveProfileImage(true);
    setProfilePreview('');
    if (profileFileInputRef.current) {
      profileFileInputRef.current.value = '';
    }
  }

  function validateProfile() {
    if (!profileName.trim()) return 'Full name is required.';
    if (profileEmail.trim() && !/^[^\s@]+@gmail\.com$/i.test(profileEmail.trim())) return 'Enter a valid Gmail address ending in @gmail.com, or leave it blank.';
    if (newPassword || confirmPassword || currentPassword) {
      if (!currentPassword) return 'Current password is required to change your password.';
      if (!newPassword) return 'Enter a new password.';
      if (newPassword.length < 8) return 'New password must be at least 8 characters.';
      if (!/[A-Z]/.test(newPassword) || !/[a-z]/.test(newPassword) || !/\d/.test(newPassword)) return 'New password must include uppercase and lowercase letters and a number.';
      if (newPassword === currentPassword) return 'Your new password must be different from your temporary password.';
      if (newPassword !== confirmPassword) return 'Confirm new password must match the new password.';
    }
    return '';
  }

  async function saveProfile(event) {
    event.preventDefault();
    const validationMessage = validateProfile();
    if (validationMessage) {
      setProfileMessage({ type: 'error', text: validationMessage });
      return;
    }
    const confirmed = await confirmSaveChanges();
    if (!confirmed) return;

    setProfileSaving(true);
    setProfileMessage(null);

    try {
      const formData = new FormData();
      formData.append('full_name', profileName.trim());
      formData.append('email', profileEmail.trim().toLowerCase());
      if (currentPassword) formData.append('current_password', currentPassword);
      if (newPassword) formData.append('new_password', newPassword);
      if (confirmPassword) formData.append('confirm_password', confirmPassword);
      if (removeProfileImage) formData.append('remove_profile_image', '1');
      if (profileImage) formData.append('profile_image', profileImage);

      const payload = await apiFetch('/api/profile.php', {
        method: 'POST',
        body: formData,
      });

      if (payload.user) {
        const nextImageVersion = Date.now();
        setProfileImageVersion(nextImageVersion);
        onUserUpdate?.(payload.user);
        setProfilePreview(profileImageSrc(payload.user.profileImage, nextImageVersion));
      }
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      setProfileImage(null);
      setRemoveProfileImage(false);
      setProfileMessage({ type: 'success', text: payload.message || 'Profile updated.' });
    } catch (error) {
      setProfileMessage({ type: 'error', text: error.message || 'Unable to update profile.' });
    } finally {
      setProfileSaving(false);
    }
  }

  const profileModal = profileOpen ? createPortal(
    <div className="people-modal-backdrop profile-modal-backdrop is-centered" onClick={(event) => event.target === event.currentTarget && closeProfile()}>
      <section className="people-modal-panel profile-modal-panel" role="dialog" aria-modal="true" aria-label="User profile">
        <div className="box-title">
          <div>
            <h2>{avatarLabel}</h2>
            <span>{role.user.userCode ? `Username ${role.user.userCode}` : roleLabel}</span>
          </div>
          <button type="button" className="modal-icon-close" onClick={closeProfile} aria-label="Close profile"><X size={18} /></button>
        </div>
        <form className="admin-form profile-form" onSubmit={saveProfile}>
          <div className="profile-editor-head">
            <div className="profile-editor-avatar">
              {profilePreview ? <img src={profilePreview} alt={`${profileName || role.user.name} profile preview`} /> : <span>{(profileName || role.user.name || 'U').charAt(0)}</span>}
            </div>
            <div className="profile-editor-summary"><strong>Profile Photo</strong><small>Use a clear JPG, PNG, WEBP, or GIF image.</small></div>
            <div className="profile-buttons-row">
              <label className="profile-upload-button">
                <Camera size={16} />
                <span>Change Photo</span>
                <input ref={profileFileInputRef} type="file" accept="image/jpeg,image/png,image/webp,image/gif" onChange={handleProfileFile} />
              </label>
              <button className="profile-remove-photo" type="button" onClick={removePhoto} disabled={!profilePreview && !role.user.profileImage}>
                <Trash2 size={16} />
                Remove
              </button>
            </div>
          </div>
          {profileMessage && <div className={`notice ${profileMessage.type === 'error' ? 'error' : 'success'}`}>{profileMessage.text}</div>}
          <div className="profile-section-heading"><span><User size={17} /></span><div><strong>Account Information</strong><small>Manage your personal and institutional details.</small></div></div>
          <div className="profile-field-grid">
            <label>
              Full Name
              <input value={profileName} onChange={(event) => setProfileName(event.target.value)} required />
            </label>
            <label>
              <span><Mail size={14} /> Gmail (Optional)</span>
              <input type="email" value={profileEmail} onChange={(event) => setProfileEmail(event.target.value)} placeholder="name@gmail.com" autoComplete="email" />
            </label>
            <label>
              Role
              <input value={roleLabel} readOnly aria-readonly="true" />
            </label>
            <label>
              Department
              <input value={profileDepartment} readOnly aria-readonly="true" />
            </label>
            <label>
              {periodProfilePrograms.length > 1 ? 'Programs for Selected Period' : 'Program'}
              <input value={profileProgram} readOnly aria-readonly="true" />
            </label>
          </div>
          <div ref={passwordSectionRef} className={`profile-password-section ${passwordEditorOpen ? 'is-open' : ''}`}>
            <button type="button" className="profile-password-section-toggle" onClick={() => setPasswordEditorOpen((current) => !current)} aria-expanded={passwordEditorOpen}>
              <span><i><KeyRound size={17} /></i><span><strong>Password & Security</strong><small>{passwordEditorOpen ? 'Enter your current password to save a new one.' : 'Optional — update your account password securely.'}</small></span></span><ChevronDown size={19} />
            </button>
            {passwordEditorOpen && <div className="profile-password-editor">
              <div className="profile-section-title">
                <strong>Reset Password</strong>
                <label className="profile-password-toggle">
                  <input type="checkbox" checked={showPasswords} onChange={(event) => setShowPasswords(event.target.checked)} />
                  {showPasswords ? <EyeOff size={15} /> : <Eye size={15} />}
                  <span>{showPasswords ? 'Hide passwords' : 'Show passwords'}</span>
                </label>
              </div>
              <div className="profile-password-grid"><label>Current Password<input type={passwordInputType} value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} placeholder="Enter current password" autoComplete="current-password" /></label><label>New Password<input type={passwordInputType} value={newPassword} onChange={(event) => setNewPassword(event.target.value)} placeholder="Create a new password" minLength={newPassword ? 8 : undefined} autoComplete="new-password" /></label><label>Confirm New Password<input type={passwordInputType} value={confirmPassword} onChange={(event) => setConfirmPassword(event.target.value)} placeholder="Re-enter new password" autoComplete="new-password" /></label></div>
              <div className={`profile-password-strength strength-${passwordStrength.toLowerCase().replace(' ', '-')}`}><span><i style={{ width: `${newPassword ? Math.max(18, passwordScore * 20) : 0}%` }} /></span><small>Password strength: {passwordStrength}</small></div>
            </div>}
          </div>
          <div className="profile-form-actions">
            <button type="button" className="ghost-button" onClick={closeProfile}>Cancel</button>
            <button type="button" className="ghost-button" onClick={resetProfileChanges}><RotateCcw size={16} />Reset Changes</button>
            <button type="submit" disabled={profileSaving}>
              {profileSaving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
              Save Changes
            </button>
          </div>
        </form>
      </section>
    </div>,
    document.body
  ) : null;

  return (
    <header className="admin-header">
      <button className="menu-toggle" type="button" aria-label="Open menu" onClick={onOpenMenu}>
        <span />
        <span />
        <span />
      </button>
      <div className="admin-header-info">
        <h1>{active.label}</h1>
        <p className="admin-header-note">{role.note}</p>
      </div>
      <form className="admin-search" onSubmit={submitSearch} onBlur={(event)=>{if(!event.currentTarget.contains(event.relatedTarget))setSearchFocused(false)}}>
        <label htmlFor="top-search">Search dashboard section</label>
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            id="top-search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onFocus={() => setSearchFocused(true)}
            autoComplete="off"
            placeholder="Search sections, reports, evaluations..."
            className="pl-10"
          />
          {searchFocused && <div className="top-search-suggestions" role="listbox" aria-label={query.trim()?'Search results':'Recent and suggested searches'}>
            <span>{query.trim()?'Matching sections':recentSearches.length?'Recent and suggested':'Suggested searches'}</span>
            {searchSuggestions.length ? searchSuggestions.map(item=><button key={item.key} type="button" role="option" onMouseDown={event=>event.preventDefault()} onClick={()=>selectSearchResult(item)}><Search size={14}/><span>{item.label}</span><ArrowRight size={14}/></button>) : <p>No dashboard sections match “{query}”.</p>}
          </div>}
        </div>
      </form>
      <div className="admin-actions" aria-label="Dashboard actions">
        <div className="dean-header-context role-context" title={avatarLabel}>{avatarLabel}</div>
        <div className="notification-center" ref={notificationPanelRef}>
          <button className={`notification-button ${hasNewNotification ? 'has-new' : ''}`} type="button" onClick={toggleNotifications} aria-label="Open notifications" aria-expanded={notificationOpen}>
            <Bell className="notification-bell-icon" aria-hidden="true" />
            {unreadCount > 0 && <span className="notification-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>}
          </button>
          {notificationOpen && (
            <section className="notification-panel" aria-label="Recent notifications">
              <div className="notification-panel-head">
                <div className="notification-panel-head-info">
                  <strong>Notifications</strong>
                  <span>{unreadCount > 0 ? `${unreadCount} unread` : 'All caught up!'}</span>
                </div>
                {unreadCount > 0 && (
                  <button className="notification-mark-all-btn" type="button" onClick={handleMarkAllRead}>
                    <CheckCheck size={14} />
                    Mark all read
                  </button>
                )}
              </div>
              <div className="notification-tabs" role="tablist" aria-label="Notification filters">
                {notificationFilters.map((filter) => (
                  <button
                    key={filter.key}
                    type="button"
                    role="tab"
                    aria-selected={notificationFilter === filter.key}
                    className={notificationFilter === filter.key ? 'active' : ''}
                    onClick={() => {
                      setNotificationFilter(filter.key);
                      loadNotifications({ filter: filter.key, showLoading: true });
                    }}
                  >
                    {filter.label}
                  </button>
                ))}
              </div>
              <div className="notification-list">
                {notificationLoading && (
                  <div className="notification-loading">
                    <Loader2 size={18} className="animate-spin" />
                    <span>Loading notifications...</span>
                  </div>
                )}
                {notificationError && !notificationLoading && (
                  <div className="notification-error">
                    <span>{notificationError}</span>
                    <button type="button" onClick={() => loadNotifications({ showLoading: true })}>Retry</button>
                  </div>
                )}
                {!notificationLoading && !notificationError && notifications.length === 0 && (
                  <div className="notification-empty">
                    <Bell size={28} />
                    <p>No notifications yet</p>
                    <span>New updates for this filter will appear here</span>
                  </div>
                )}
                {!notificationLoading && !notificationError && notifications.map((item) => {
                  const TypeIcon = getTypeIcon(item.type);
                  return (
                    <article className={`notification-item ${!item.is_read ? 'unread' : ''}`} key={item.id}>
                      <div className={`notification-item-icon ${getTypeClass(item.type)}`}>
                        <TypeIcon size={16} />
                      </div>
                      <div className="notification-item-body">
                        <div className="notification-item-head">
                          <span className={`notification-type-badge ${getTypeClass(item.type)}`}>
                            {getTypeLabel(item.type)}
                          </span>
                          {!item.is_read && <Dot size={16} className="notification-unread-dot" />}
                        </div>
                        <strong className="notification-item-title">{item.title}</strong>
                        <p className="notification-item-desc">{item.description}</p>
                        <div className="notification-item-meta">
                          <Clock size={12} />
                          <span>{item.relative_time || item.created_at}</span>
                        </div>
                        {(item.action_url || item.link) && (
                          <button className="notification-action-btn" type="button" onClick={(event) => handleNotificationAction(event, item)}>
                            <span>{item.module === 'password_reset' ? 'Review Reset Request' : 'Open Details'}</span>
                            <ArrowRight size={14} />
                          </button>
                        )}
                      </div>
                      <div className="notification-item-actions">
                        {!item.is_read && (
                          <button
                            className="notification-read-btn"
                            type="button"
                            onClick={(e) => handleMarkAsRead(e, item.id)}
                            title="Mark as read"
                            aria-label="Mark as read"
                          >
                            <CheckCheck size={14} />
                          </button>
                        )}
                      </div>
                    </article>
                  );
                })}
              </div>
            </section>
          )}
        </div>
        <div className="account-menu" ref={accountMenuRef}>
          <button className="profile-button account-menu-trigger" type="button" onClick={toggleAccountMenu} aria-label="Account menu" aria-expanded={accountOpen && !accountClosing} aria-haspopup="menu">
            <span className="admin-avatar">
              {avatarSrc ? <img src={avatarSrc} alt={`${avatarLabel} profile`} /> : avatarLabel.charAt(0)}
            </span>
          </button>
          {accountOpen && (
            <section className={`account-dropdown ${accountClosing ? 'is-closing' : 'is-opening'}`} aria-label="Account options" role="menu">
              <div className="account-dropdown-head">
                <span className="account-dropdown-avatar">
                  {avatarSrc ? <img src={avatarSrc} alt={`${avatarLabel} profile`} /> : avatarLabel.charAt(0)}
                </span>
                <div>
                  <strong>{avatarLabel}</strong>
                  <small>{roleLabel}</small>
                </div>
              </div>
              <button type="button" onClick={openProfile} role="menuitem">
                <User size={16} />
                <span>Profile Settings</span>
              </button>
              <button type="button" className="account-theme-item" onClick={handleThemeToggle} role="menuitem" aria-pressed={darkMode}>
                {darkMode ? <Sun size={16} /> : <Moon size={16} />}
                <span>{darkMode ? 'Switch to Light Mode' : 'Switch to Dark Mode'}</span>
                <small className="account-theme-badge"><CheckCheck size={12} /> {darkMode ? 'Dark active' : 'Light active'}</small>
              </button>
              <button type="button" className={!darkMode ? 'account-theme-item is-current' : 'account-theme-item'} onClick={handleThemeReset} role="menuitem" aria-current={!darkMode ? 'true' : undefined}>
                <RotateCcw size={16} />
                <span>Reset to Light Mode</span>
                {!darkMode && <small className="account-theme-badge"><CheckCheck size={12} /> Active</small>}
              </button>
              <button type="button" className="danger" onClick={handleLogoutClick} role="menuitem">
                <LogOut size={16} />
                <span>Logout</span>
              </button>
            </section>
          )}
        </div>
      </div>
      {profileModal}
    </header>
  );
}
