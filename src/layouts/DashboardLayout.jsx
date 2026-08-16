import { useEffect, useRef, useState } from 'react';
import { Outlet, useNavigate, useOutletContext } from 'react-router-dom';
import { roles } from '../data/navigation.js';
import { useLocalStorage } from '../hooks/useLocalStorage.js';
import { EvaluationPeriodProvider, useEvaluationPeriod } from '../contexts/EvaluationPeriodContext.jsx';
import apiFetch from '../data/api.js';

import Sidebar from '../components/navigation/Sidebar.jsx';
import Topbar from '../components/navigation/Topbar.jsx';
import FloatingChat from '../components/chat/FloatingChat.jsx';
import ToastContainer from '../components/common/Toast.jsx';

function roleFromPath(pathname) {
  if (pathname.startsWith('/vpaa')) return roles.vpaa;
  if (pathname.startsWith('/dean')) return roles.dean;
  if (pathname.startsWith('/program-head')) return roles.programHead;
  if (pathname.startsWith('/faculty')) return roles.faculty;
  return roles.admin;
}

function getThemePreference() {
  try {
    const storedPreference = window.localStorage.getItem('dipascaf-theme-preference');
    if (storedPreference === 'dark') return 'dark';
    if (storedPreference === 'light' || storedPreference === 'system') return 'light';
    const legacyDarkMode = window.localStorage.getItem('dipascaf-dark-mode');
    if (legacyDarkMode !== null) return JSON.parse(legacyDarkMode) ? 'dark' : 'light';
  } catch {}
  return 'light';
}

function resolveDarkMode(preference) {
  return preference === 'dark';
}

export function useDashboardContext() {
  return useOutletContext();
}

function DashboardLayoutContent({ session, onLogout, onUserUpdate }) {
  const navigate = useNavigate();
  const baseRole = roles[session.roleKey] || roles.admin;
  const role = {
    ...baseRole,
    user: {
      ...baseRole.user,
      name: session.user?.name || baseRole.user.name,
      email: String(session.user?.email || '').toLowerCase().endsWith('@pmas.local')
        ? ''
        : (session.user?.email || baseRole.user.email),
      department: session.user?.department || baseRole.user.department,
      program: session.user?.program || baseRole.user.program || '',
      databaseRole: session.user?.databaseRole || '',
      roleKey: session.user?.roleKey || session.roleKey,
      profileImage: session.user?.profileImage || '',
      mustChangePassword: Boolean(session.user?.mustChangePassword),
    },
  };
  const [sidebarOpen, setSidebarOpen] = useLocalStorage('dipascaf-sidebar-open', false);
  const [sidebarCollapsed, setSidebarCollapsed] = useLocalStorage('dipascaf-sidebar-collapsed', false);
  const initialThemePreference = getThemePreference();
  const [, setThemePreference] = useState(initialThemePreference);
  const [darkMode, setDarkMode] = useState(() => resolveDarkMode(initialThemePreference));
  const { selectedPeriodId } = useEvaluationPeriod();
  const onUserUpdateRef = useRef(onUserUpdate);

  useEffect(() => { onUserUpdateRef.current = onUserUpdate; }, [onUserUpdate]);

  useEffect(() => {
    if (!selectedPeriodId || session.roleKey === 'admin') return;
    let active = true;
    apiFetch(`/api/auth.php?action=me&period_id=${encodeURIComponent(selectedPeriodId)}`)
      .then((payload) => {
        if (!active || !payload?.ok || !payload.user) return;
        const next = payload.user;
        const currentPeriodId = String(session.user?.periodId || '');
        if (next.roleKey !== session.roleKey || String(next.periodId || '') !== currentPeriodId
          || next.department !== session.user?.department || next.program !== session.user?.program) {
          onUserUpdateRef.current(next);
        }
      })
      .catch(() => {});
    return () => { active = false; };
  }, [selectedPeriodId, session.roleKey, session.user?.department, session.user?.periodId, session.user?.program]);

  useEffect(() => {
    function syncTheme() {
      const nextPreference = getThemePreference();
      setThemePreference(nextPreference);
      setDarkMode(resolveDarkMode(nextPreference));
    }

    window.addEventListener('dipascaf-settings-updated', syncTheme);
    window.addEventListener('storage', syncTheme);
    return () => {
      window.removeEventListener('dipascaf-settings-updated', syncTheme);
      window.removeEventListener('storage', syncTheme);
    };
  }, []);

  useEffect(() => {
    const targets = [document.documentElement, document.body];
    targets.forEach((target) => {
      target.classList.toggle('dark-mode', darkMode);
      target.classList.toggle('dark', darkMode);
      target.classList.toggle('light-mode', !darkMode);
    });
  }, [darkMode]);

  useEffect(() => {
    let timeoutId;

    function readTimeoutMinutes(event) {
      const eventMinutes = Number(event?.detail?.sessionTimeoutMinutes);
      if (Number.isFinite(eventMinutes)) return Math.min(240, Math.max(5, eventMinutes));
      try {
        const settings = JSON.parse(window.localStorage.getItem('dipascaf-admin-settings-v1') || '{}');
        const storedMinutes = Number(settings.sessionTimeoutMinutes);
        if (Number.isFinite(storedMinutes)) return Math.min(240, Math.max(5, storedMinutes));
      } catch {}
      return 30;
    }

    function scheduleLogout(event) {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => {
        handleLogout();
      }, readTimeoutMinutes(event) * 60 * 1000);
    }

    const activityEvents = ['pointerdown', 'keydown', 'scroll', 'touchstart'];
    activityEvents.forEach((eventName) => window.addEventListener(eventName, scheduleLogout, { passive: true }));
    window.addEventListener('dipascaf-settings-updated', scheduleLogout);
    scheduleLogout();

    return () => {
      window.clearTimeout(timeoutId);
      activityEvents.forEach((eventName) => window.removeEventListener(eventName, scheduleLogout));
      window.removeEventListener('dipascaf-settings-updated', scheduleLogout);
    };
  }, [session.user?.email]);

  function handleToggleDark() {
    setDarkMode((prev) => {
      const next = !prev;
      const nextPreference = next ? 'dark' : 'light';
      setThemePreference(nextPreference);
      window.localStorage.setItem('dipascaf-theme-preference', nextPreference);
      window.localStorage.setItem('dipascaf-dark-mode', JSON.stringify(next));
      window.dispatchEvent(new CustomEvent('dipascaf-theme-changed', { detail: { themePreference: nextPreference } }));
      return next;
    });
  }

  function handleResetDark() {
    setThemePreference('light');
    window.localStorage.setItem('dipascaf-theme-preference', 'light');
    window.localStorage.setItem('dipascaf-dark-mode', 'false');
    window.dispatchEvent(new CustomEvent('dipascaf-theme-changed', { detail: { themePreference: 'light' } }));
    setDarkMode(false);
  }

  const bodyClass = [
    'admin-body',
    role.key === 'admin' ? 'admin-dashboard-body' : '',
    role.key === 'vpaa' || role.key === 'dean' || role.key === 'programHead' ? 'dean-body' : '',
    role.key === 'programHead' ? 'program-head-body' : '',
    role.key === 'faculty' ? 'role-dashboard-body role-sidebar-body faculty-body' : '',
    sidebarOpen ? 'sidebar-open' : '',
    sidebarCollapsed ? 'sidebar-collapsed' : '',
    darkMode ? '' : 'light-mode',
    darkMode ? 'dark-mode dark' : '',
  ].join(' ');

  async function handleLogout() {
    setSidebarOpen(false);
    document.documentElement.classList.remove('modal-open');
    try {
      await onLogout();
    } finally {
      window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
      navigate('/login', { replace: true });
    }
  }

  return (
    <div className={bodyClass}>
      <button className="sidebar-overlay" type="button" aria-label="Close menu" onClick={() => setSidebarOpen(false)} />
      <Sidebar
        role={role}
        sidebarCollapsed={sidebarCollapsed}
        onClose={() => setSidebarOpen(false)}
        onToggleCollapse={() => setSidebarCollapsed((value) => !value)}
      />
      <main className="admin-main">
        <Topbar
          role={role}
          onOpenMenu={() => setSidebarOpen((value) => !value)}
          onUserUpdate={onUserUpdate}
          darkMode={darkMode}
          onToggleDark={handleToggleDark}
          onResetDark={handleResetDark}
          onLogout={handleLogout}
        />
        <Outlet context={{ role, darkMode }} />
      </main>
      <FloatingChat role={role} />
      <ToastContainer />
    </div>
  );
}

export default function DashboardLayout(props) {
  return <EvaluationPeriodProvider><DashboardLayoutContent {...props} /></EvaluationPeriodProvider>;
}
