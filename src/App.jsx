import { useEffect, useState } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import DashboardLayout from './layouts/DashboardLayout.jsx';
import AdminDashboard from './pages/AdminDashboard.jsx';
import DeanDashboard from './pages/DeanDashboard.jsx';
import ProgramHeadDashboard from './pages/ProgramHeadDashboard.jsx';
import FacultyDashboard from './pages/FacultyDashboard.jsx';
import VpaaDashboard from './pages/VpaaDashboard.jsx';
import DepartmentProfilePage from './pages/DepartmentProfilePage.jsx';
import LoginPage from './pages/LoginPage.jsx';
import LogoutPage from './pages/LogoutPage.jsx';
import CredentialPage from './pages/CredentialPage.jsx';
import ConfirmationModalProvider from './components/common/ConfirmationModal.jsx';
import { roles } from './data/navigation.js';
import { useLocalStorage } from './hooks/useLocalStorage.js';
import { apiUrl } from './data/apiBase.js';

const AUTH_TOKEN = 'dipascaf-react-authenticated-v2';
const EMPTY_SESSION = {
  isLoggedIn: false,
  roleKey: 'admin',
  authToken: null,
  user: null,
};

function isAuthenticated(session) {
  return session?.isLoggedIn === true && session.authToken === AUTH_TOKEN;
}

function ProtectedRoute({ session, children, requirePasswordChanged = false }) {
  if (!isAuthenticated(session)) return <Navigate to="/login" replace />;
  if (requirePasswordChanged && session.user?.mustChangePassword) {
    return <Navigate to="/change-password" replace />;
  }
  return children;
}

function pathRoleKey(pathname) {
  if (pathname.startsWith('/vpaa')) return 'vpaa';
  if (pathname.startsWith('/dean')) return 'dean';
  if (pathname.startsWith('/program-head')) return 'programHead';
  if (pathname.startsWith('/faculty')) return 'faculty';
  return 'admin';
}

function RoleRoute({ session, children }) {
  const location = useLocation();
  const currentRole = roles[session.roleKey] || roles.admin;

  if (pathRoleKey(location.pathname) !== currentRole.key) {
    return <Navigate to={`${currentRole.basePath}/${currentRole.nav[0].key}`} replace />;
  }

  return children;
}

export default function App() {
  const location = useLocation();
  const [session, setSession] = useLocalStorage('dipascaf-react-session', EMPTY_SESSION);
  const [sessionLoading, setSessionLoading] = useState(true);

  // On mount: check if a valid PHP session already exists (e.g. user was redirected
  // from a PHP dashboard after logging in via login.php).
  // If yes, auto-login into React. If not, proceed with existing session.
  //
  // NOTE: Uses direct fetch() instead of apiFetch() to avoid triggering
  // apiFetch's redirectToLogin() — which would cause an infinite reload loop
  // if the session check fails for any transient reason.
  useEffect(() => {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 8000);
    let mounted = true;

    (async () => {
      try {
        const res = await fetch(apiUrl('/api/auth.php?action=me'), {
          credentials: 'include',
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });
        // Only accept JSON responses
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('Not JSON');
        const text = await res.text();
        if (!text.trim()) throw new Error('Empty session response');
        const data = JSON.parse(text);
        if (mounted && data && data.ok && data.user) {
          // Valid PHP session found — bootstrap the React session from it
          setSession({
            isLoggedIn: true,
            roleKey: data.user.roleKey,
            authToken: AUTH_TOKEN,
            user: data.user,
          });
        }
      } catch {
        if (mounted) setSession(EMPTY_SESSION);
      } finally {
        window.clearTimeout(timeoutId);
        if (mounted) setSessionLoading(false);
      }
    })();

    return () => {
      mounted = false;
      window.clearTimeout(timeoutId);
      controller.abort();
    };
  }, []);

  // Show a brief loading screen while we check the PHP session on mount.
  // This prevents a flash from "not authenticated → redirect to login →
  // authenticated from PHP session → redirect to dashboard".
  if (sessionLoading) {
    return (
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100vh',
        fontFamily: 'system-ui, sans-serif',
        color: '#64748b',
        fontSize: '0.9rem',
      }}>
        Verifying session...
      </div>
    );
  }

  // Enforce the temporary-password gate across every application route,
  // including browser Back/Forward navigation and direct dashboard URLs.
  if (
    isAuthenticated(session)
    && session.user?.mustChangePassword
    && !['/change-password', '/logout'].includes(location.pathname)
  ) {
    return <Navigate to="/change-password" replace />;
  }

  function login(user) {
    setSession({
      isLoggedIn: true,
      roleKey: user.roleKey,
      authToken: AUTH_TOKEN,
      user,
    });
  }

  async function logout() {
    try {
      await fetch(apiUrl('/api/auth.php'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'logout' }),
        signal: AbortSignal.timeout(5000),
      });
    } catch (error) {
      console.error(error);
    }

    setSession(EMPTY_SESSION);
    window.sessionStorage.removeItem('pmas-password-change-authorized');
  }

  function updateUser(user) {
    setSession((current) => ({
      ...current,
      roleKey: user.roleKey || current.roleKey,
      user: {
        ...(current.user || {}),
        ...user,
      },
    }));
  }

  return (
    <>
      <Routes>
        <Route path="/login" element={<LoginPage onLogin={login} session={session} portalType="user" />} />
        <Route path="/login/admin" element={<LoginPage onLogin={login} session={session} portalType="admin" />} />
        <Route path="/logout" element={<LogoutPage onLogout={logout} />} />
        <Route path="/forgot-password" element={<LoginPage onLogin={login} session={session} portalType="user" initialRecovery />} />
        <Route path="/reset-password" element={<CredentialPage mode="reset" />} />
        <Route path="/change-password" element={
          <ProtectedRoute session={session}>
            {session.user?.mustChangePassword
              ? <CredentialPage mode="change" session={session} onUserUpdate={updateUser} onLogout={logout} />
              : <Navigate to={`${(roles[session.roleKey] || roles.admin).basePath}/${(roles[session.roleKey] || roles.admin).nav[0].key}`} replace />}
          </ProtectedRoute>
        } />
        <Route path="/" element={<Navigate to="/login" replace />} />
        <Route element={<ProtectedRoute session={session} requirePasswordChanged><RoleRoute session={session}><DashboardLayout session={session} onLogout={logout} onUserUpdate={updateUser} /></RoleRoute></ProtectedRoute>}>
          <Route path="/admin/:section" element={<AdminDashboard role={{...roles.admin, user: {...roles.admin.user, ...(session.user || {})}}} onUserUpdate={updateUser} />} />
          <Route path="/admin/department/:departmentId" element={<DepartmentProfilePage />} />
          <Route path="/vpaa/:section" element={<VpaaDashboard role={{...roles.vpaa, user: {...roles.vpaa.user, ...(session.user || {})}}} />} />
          <Route path="/dean/:section" element={<DeanDashboard role={{...roles.dean, user: {...roles.dean.user, ...(session.user || {})}}} />} />
          <Route path="/program-head/:section" element={<ProgramHeadDashboard role={{...roles.programHead, user: {...roles.programHead.user, ...(session.user || {})}}} />} />
          <Route path="/faculty/:section" element={<FacultyDashboard role={{...roles.faculty, user: {...roles.faculty.user, ...(session.user || {})}}} />} />
        </Route>
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
      <ConfirmationModalProvider />
    </>
  );
}
