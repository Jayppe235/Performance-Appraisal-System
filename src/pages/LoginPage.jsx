import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Bot, Eye, EyeOff, HelpCircle, Info, LoaderCircle, LockKeyhole, UserRound, ShieldCheck, Sparkles } from 'lucide-react';
import { roles } from '../data/navigation.js';
import ndmcSeal from '../../assets/images/ndmc-seal.png';
import { apiUrl } from '../data/apiBase.js';

const rememberedCodeKey = 'dipascaf-remembered-user-code';

function dashboardPathForRole(roleKey) {
  const selectedRole = roles[roleKey] || roles.admin;
  return `${selectedRole.basePath}/${selectedRole.nav[0].key}`;
}

export default function LoginPage({ onLogin, session, portalType = 'user', initialRecovery = false }) {
  const navigate = useNavigate();
  const [showPassword, setShowPassword] = useState(false);
  const [userCode, setUserCode] = useState(() => window.localStorage.getItem(rememberedCodeKey) || '');
  const [password, setPassword] = useState('');
  const [rememberCode, setRememberCode] = useState(() => Boolean(window.localStorage.getItem(rememberedCodeKey)));
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [fieldErrors, setFieldErrors] = useState({ userCode: '', password: '' });
  const [touched, setTouched] = useState({ userCode: false, password: false });
  const [submitting, setSubmitting] = useState(false);
  const [capsLockOn, setCapsLockOn] = useState(false);
  const [recoveryMode, setRecoveryMode] = useState(initialRecovery);
  const loginFormRef = useRef(null);
  const recoveryFormRef = useRef(null);
  const loginUserCodeRef = useRef(null);
  const recoveryUserCodeRef = useRef(null);
  const [formViewportHeight, setFormViewportHeight] = useState(null);

  useLayoutEffect(() => {
    function syncFormHeight() {
      const activeForm = recoveryMode ? recoveryFormRef.current : loginFormRef.current;
      if (activeForm) {
        const styles = window.getComputedStyle(activeForm);
        setFormViewportHeight(Math.ceil(activeForm.scrollHeight + parseFloat(styles.marginTop || 0) + parseFloat(styles.marginBottom || 0)));
      }
    }
    syncFormHeight();
    window.addEventListener('resize', syncFormHeight);
    return () => window.removeEventListener('resize', syncFormHeight);
  }, [recoveryMode, submitting, capsLockOn, fieldErrors, touched]);

  useEffect(() => {
    if (session?.isLoggedIn) {
      navigate(session.user?.mustChangePassword ? '/change-password' : dashboardPathForRole(session.roleKey), { replace: true });
    }
  }, [navigate, session]);

  useEffect(() => {
    const focusTimer = window.setTimeout(() => {
      (recoveryMode ? recoveryUserCodeRef.current : loginUserCodeRef.current)?.focus();
    }, 80);
    return () => window.clearTimeout(focusTimer);
  }, [recoveryMode]);

  function validateLogin(accountCode = userCode.trim(), accountPassword = password) {
    return {
      userCode: !accountCode
        ? 'Enter your username code.'
        : !/^[1-9]\d*$/.test(accountCode)
          ? 'Use numeric digits only; the code cannot begin with zero.'
          : '',
      password: accountPassword ? '' : 'Enter your password.',
    };
  }

  async function submit(event) {
    event.preventDefault();
    const accountCode = userCode.trim();
    let authenticated = false;
    const validation = validateLogin(accountCode, password);
    setTouched({ userCode: true, password: true });
    setFieldErrors(validation);
    if (validation.userCode || validation.password) {
      (validation.userCode ? loginUserCodeRef.current : loginFormRef.current?.querySelector('#password'))?.focus();
      return;
    }

    setSubmitting(true);
    setError('');
    setSuccess('');

    try {
      const response = await fetch(apiUrl('/api/auth.php'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: 'login',
          user_code: accountCode,
          password,
        }),
      });
      const contentType = response.headers.get('content-type') || '';
      let payload = { ok: false, message: 'The login service returned an unexpected response. Please try again.' };
      if (contentType.includes('application/json')) {
        const text = await response.text();
        payload = text.trim()
          ? JSON.parse(text)
          : { ok: false, message: 'The login API returned an empty response. Please make sure Apache and MySQL are running in XAMPP.' };
      }

      if (!response.ok || !payload.ok) {
        setError(payload.message || 'Invalid username code or password.');
        return;
      }

      const isAdmin = payload.user.databaseRole === 'admin_hr';
      if ((portalType === 'admin' && !isAdmin) || (portalType !== 'admin' && isAdmin)) {
        await fetch(apiUrl('/api/auth.php'), {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'logout' }),
        });
        setError('Invalid username code or password.');
        return;
      }

      const selectedRole = roles[payload.user.roleKey] || roles.admin;
      authenticated = true;

      if (rememberCode) {
        window.localStorage.setItem(rememberedCodeKey, accountCode);
      } else {
        window.localStorage.removeItem(rememberedCodeKey);
      }

      window.sessionStorage.removeItem('pmas-password-change-authorized');
      onLogin(payload.user);
      const requiresPasswordChange = Boolean(payload.user.mustChangePassword);
      setSuccess(requiresPasswordChange ? 'Login successful. Please replace your temporary password.' : `Login successful. Opening ${selectedRole.portal}.`);
      window.setTimeout(() => {
        navigate(requiresPasswordChange ? '/change-password' : `${selectedRole.basePath}/${selectedRole.nav[0].key}`, { replace: true });
      }, 850);
    } catch (exception) {
      console.error('Login fetch failed:', exception);
      setError('Unable to reach the APPRAISIA login service. Check your connection and try again.');
    } finally {
      if (!authenticated) {
        setSubmitting(false);
      }
    }
  }

  function showRecovery() {
    setError(''); setSuccess(''); setFieldErrors({ userCode: '', password: '' }); setRecoveryMode(true);
  }

  function showLogin() {
    setError(''); setSuccess(''); setFieldErrors({ userCode: '', password: '' }); setRecoveryMode(false);
    if (initialRecovery) navigate(portalType === 'admin' ? '/login/admin' : '/login', { replace: true });
  }

  async function submitRecovery(event) {
    event.preventDefault();
    const accountCode = userCode.trim();
    const userCodeError = !accountCode ? 'Enter your username code.' : !/^[1-9]\d*$/.test(accountCode) ? 'Use a valid numeric username code.' : '';
    setTouched((current) => ({ ...current, userCode: true }));
    setFieldErrors((current) => ({ ...current, userCode: userCodeError }));
    if (userCodeError) { recoveryUserCodeRef.current?.focus(); return; }
    setSubmitting(true); setError(''); setSuccess('');
    try {
      const response = await fetch(apiUrl('/api/auth.php'), { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ action: 'request-reset', user_code: accountCode }) });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to submit the recovery request.');
      setSuccess(payload.message);
    } catch (exception) { setError(exception.message || 'Unable to reach the recovery service.'); }
    finally { setSubmitting(false); }
  }

  return (
    <main className="login-page">
      <div className="login-bg" aria-hidden="true">
        <div className="circuit-grid" />
        <div className="circuit circuit-a"><span /><span /><span /></div>
        <div className="circuit circuit-b"><span /><span /><span /></div>
        <div className="circuit circuit-c"><span /><span /><span /></div>
        <div className="circuit-pulse pulse-a" />
        <div className="circuit-pulse pulse-b" />
        <div className="circuit-pulse pulse-c" />
      </div>
      <section className="login-shell">
        <section className="login-card" aria-labelledby="login-title">
          <div className="login-card-brand">
            <img src={ndmcSeal} alt="Notre Dame of Midsayap College seal" />
            <span>Notre Dame of Midsayap College</span>
          </div>
          <div className="login-security-chip"><ShieldCheck size={15} aria-hidden="true" /> {portalType === 'admin' ? 'Secure Administrator Portal' : 'Secure Faculty Portal'}</div>
          <h1 id="login-title">APPRAISIA</h1>
          <p className="login-subtitle">Digital Integrated Performance Appraisal System with Chatbot Assistance for Faculty</p>
          <div className="login-feature-row" aria-label="Portal features">
            <span><Sparkles size={14} /> AI-guided insights</span>
            <span><Bot size={14} /> Faculty chatbot</span>
          </div>
          <p className="login-welcome">{recoveryMode ? 'Enter your username code to ask an administrator to reset your password.' : portalType === 'admin' ? 'Administrator access. Enter your credentials to manage APPRAISIA.' : 'Welcome back. Enter your account details to open your role-based dashboard.'}</p>

          {error && <div className="alert login-form-alert" role="alert" aria-live="assertive">{error}</div>}
          {success && <div className="notice success login-success" role="status" aria-live="polite">{success}</div>}

          <div className="login-form-viewport" style={formViewportHeight ? { height: `${formViewportHeight}px` } : undefined}>
          <div className={`login-form-track ${recoveryMode ? 'show-recovery' : ''}`}>
          <form ref={loginFormRef} className="form login-form-panel" onSubmit={submit} aria-hidden={recoveryMode} inert={recoveryMode ? '' : undefined}>
            <label htmlFor="user-code">Username Code</label>
            <div className={`login-input-wrap ${fieldErrors.userCode && touched.userCode ? 'has-error' : ''}`}>
              <UserRound size={18} aria-hidden="true" />
              <input ref={loginUserCodeRef} id="user-code" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="username" placeholder="e.g. 2025001" value={userCode} onBlur={() => { setTouched((current) => ({ ...current, userCode: true })); setFieldErrors((current) => ({ ...current, userCode: validateLogin().userCode })); }} onChange={(event) => { const nextCode = event.target.value.replace(/\D/g, ''); setUserCode(nextCode); setError(''); setSuccess(''); if (touched.userCode) setFieldErrors((current) => ({ ...current, userCode: validateLogin(nextCode, password).userCode })); }} aria-invalid={Boolean(fieldErrors.userCode && touched.userCode)} aria-describedby={fieldErrors.userCode && touched.userCode ? 'user-code-error' : 'user-code-help'} disabled={submitting} />
            </div>
            <span id="user-code-help" className="login-field-help">Use the numeric code assigned to your account.</span>
            {fieldErrors.userCode && touched.userCode && <span id="user-code-error" className="login-field-error" role="status"><Info size={13} aria-hidden="true" />{fieldErrors.userCode}</span>}

            <label htmlFor="password">Password</label>
            <div className={`login-input-wrap ${fieldErrors.password && touched.password ? 'has-error' : ''}`}>
              <LockKeyhole size={18} aria-hidden="true" />
              <input
                id="password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                placeholder="Enter your password"
                value={password}
                onBlur={() => { setCapsLockOn(false); setTouched((current) => ({ ...current, password: true })); setFieldErrors((current) => ({ ...current, password: validateLogin(userCode.trim(), password).password })); }}
                onKeyUp={(event) => setCapsLockOn(event.getModifierState?.('CapsLock') || false)}
                onChange={(event) => { const nextPassword = event.target.value; setPassword(nextPassword); setError(''); setSuccess(''); if (touched.password) setFieldErrors((current) => ({ ...current, password: validateLogin(userCode.trim(), nextPassword).password })); }}
                aria-invalid={Boolean(fieldErrors.password && touched.password)}
                aria-describedby={[fieldErrors.password && touched.password ? 'password-error' : '', capsLockOn ? 'caps-lock-hint' : ''].filter(Boolean).join(' ') || undefined}
                disabled={submitting}
              />
              <button className="show-password-button" type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} disabled={submitting}>
                {showPassword ? <Eye size={18} /> : <EyeOff size={18} />}
              </button>
            </div>
            {fieldErrors.password && touched.password && <span id="password-error" className="login-field-error" role="status"><Info size={13} aria-hidden="true" />{fieldErrors.password}</span>}
            {capsLockOn && (
              <div id="caps-lock-hint" className="login-inline-hint" role="status" aria-live="polite">
                <Info size={14} /> Caps Lock is on.
              </div>
            )}

            <div className={`login-options ${portalType === 'admin' ? 'login-options-admin' : ''}`}>
              <label className="password-toggle" htmlFor="remember-code">
                <input type="checkbox" id="remember-code" checked={rememberCode} onChange={(event) => setRememberCode(event.target.checked)} disabled={submitting} />
                Remember username code
              </label>
              {portalType === 'admin' ? <span className="admin-recovery-note">Password recovery is handled by the system owner.</span> : <button className="login-text-action" type="button" onClick={showRecovery}>Forgot Password?</button>}
            </div>

            <button type="submit" disabled={submitting} aria-busy={submitting}>
              {submitting && <LoaderCircle className="login-spinner" size={18} aria-hidden="true" />}
              <span>{submitting ? 'Authenticating...' : 'Access Dashboard'}</span>
              {!submitting && <ArrowRight size={18} aria-hidden="true" />}
            </button>
          </form>
          <form ref={recoveryFormRef} className="form login-form-panel login-recovery-panel" onSubmit={submitRecovery} aria-hidden={!recoveryMode} inert={!recoveryMode ? '' : undefined}>
            <div className="login-recovery-heading"><button type="button" className="login-back-button" onClick={showLogin} aria-label="Back to login"><ArrowLeft size={17} /></button><div><strong>Request a password reset</strong><span>Enter your username code. An administrator will review your request.</span></div></div>
            <label htmlFor="recovery-user-code">Username Code</label>
            <div className={`login-input-wrap ${fieldErrors.userCode && touched.userCode ? 'has-error' : ''}`}><UserRound size={18} aria-hidden="true" /><input ref={recoveryUserCodeRef} id="recovery-user-code" inputMode="numeric" pattern="[0-9]*" autoComplete="username" placeholder="e.g. 2025001" value={userCode} onChange={(event) => { const nextCode = event.target.value.replace(/\D/g, ''); setUserCode(nextCode); setError(''); setSuccess(''); setFieldErrors((current) => ({ ...current, userCode: '' })); }} aria-invalid={Boolean(fieldErrors.userCode && touched.userCode)} aria-describedby={fieldErrors.userCode && touched.userCode ? 'recovery-user-code-error' : undefined} disabled={submitting} /></div>
            {fieldErrors.userCode && touched.userCode && <span id="recovery-user-code-error" className="login-field-error" role="status"><Info size={13} aria-hidden="true" />{fieldErrors.userCode}</span>}
            <button type="submit" disabled={submitting}>{submitting && <LoaderCircle className="login-spinner" size={18} />}<span>{submitting ? 'Submitting request...' : 'Request Password Reset'}</span>{!submitting && <ArrowRight size={18} />}</button>
            <button type="button" className="login-return-link" onClick={showLogin}><ArrowLeft size={15} /> Back to login</button>
          </form>
          </div>
          </div>
          <div className="login-support-strip">
            <HelpCircle size={15} aria-hidden="true" />
            <span>{portalType === 'admin' ? <>Not an administrator? <Link to="/login">User Login</Link></> : <>Need administrator access? <Link to="/login/admin">Admin Login</Link></>}</span>
          </div>
        </section>
      </section>
    </main>
  );
}
