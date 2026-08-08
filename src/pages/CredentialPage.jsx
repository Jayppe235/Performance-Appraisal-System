import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Check, Eye, EyeOff, KeyRound, LoaderCircle, LockKeyhole, ShieldCheck, UserRound } from 'lucide-react';
import { apiUrl } from '../data/apiBase.js';
import { roles } from '../data/navigation.js';
import ndmcSeal from '/assets/images/ndmc-seal.png';

async function auth(action, values = {}) {
  const response = await fetch(apiUrl('/api/auth.php'), {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, ...values }),
  });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to complete this request.');
  return data;
}

export default function CredentialPage({ mode, session, onUserUpdate, onLogout }) {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [values, setValues] = useState({ user_code: '', password: '', confirm: '' });
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const token = params.get('token') || '';
  const passwordMode = mode === 'reset' || mode === 'change';

  const rules = useMemo(() => [
    { label: '8+ characters', met: values.password.length >= 8 },
    { label: 'Uppercase letter', met: /[A-Z]/.test(values.password) },
    { label: 'Lowercase letter', met: /[a-z]/.test(values.password) },
    { label: 'Number', met: /\d/.test(values.password) },
  ], [values.password]);
  const strength = rules.filter((rule) => rule.met).length;

  async function backToLogin() {
    setBusy(true);
    try {
      if (onLogout) await onLogout();
      else await auth('logout');
    } catch {
      // Continue to login even if the expired server session is already gone.
    } finally {
      navigate('/login', { replace: true });
      setBusy(false);
    }
  }

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      let data;
      if (mode === 'forgot') data = await auth('request-reset', { user_code: values.user_code });
      if (mode === 'reset') {
        if (values.password !== values.confirm) throw new Error('Passwords do not match.');
        data = await auth('reset-password', { token, password: values.password });
      }
      if (mode === 'change') {
        if (values.password !== values.confirm) throw new Error('Passwords do not match.');
        data = await auth('change-password', { password: values.password });
        onUserUpdate?.(data.user);
        const currentRole = roles[session?.roleKey];
        navigate(currentRole ? `${currentRole.basePath}/${currentRole.nav[0].key}` : '/login', { replace: true });
        return;
      }
      setMessage(data.message);
    } catch (exception) {
      setError(exception.message);
    } finally {
      setBusy(false);
    }
  }

  const title = {
    forgot: 'Recover your account',
    reset: 'Choose a new password',
    change: 'Secure your account',
  }[mode];

  return (
    <main className="login-page credential-page">
      <div className="login-bg" aria-hidden="true">
        <div className="circuit-grid" />
        <div className="circuit circuit-a"><span /><span /><span /></div>
        <div className="circuit circuit-b"><span /><span /><span /></div>
        <div className="circuit-pulse pulse-a" />
        <div className="circuit-pulse pulse-b" />
      </div>
      <section className="login-shell credential-shell">
        <section className="login-card credential-card" aria-labelledby="credential-title">
          <div className="login-card-brand credential-brand">
            <img src={ndmcSeal} alt="Notre Dame of Midsayap College seal" />
            <span>Notre Dame of Midsayap College</span>
          </div>
          <div className="login-security-chip"><ShieldCheck size={15} /> Secure credential update</div>
          <div className="credential-heading">
            <span className="credential-heading-icon"><KeyRound size={23} /></span>
            <div>
              <h1 id="credential-title">{title}</h1>
              <p>{mode === 'change' ? 'Replace your temporary password before continuing to your dashboard.' : mode === 'reset' ? 'Create a strong password for your account.' : 'Request help regaining access to your account.'}</p>
            </div>
          </div>

          {error && <div className="alert credential-alert" role="alert">{error}</div>}
          {message && <div className="notice success login-success" role="status">{message}</div>}

          <form className="form credential-form" onSubmit={submit}>
            {mode === 'forgot' && (
              <div className="credential-field">
                <label htmlFor="recovery-code">Username Code</label>
                <div className="login-input-wrap">
                  <UserRound size={19} />
                  <input id="recovery-code" inputMode="numeric" required placeholder="e.g. 2025001" value={values.user_code} onChange={(event) => setValues((current) => ({ ...current, user_code: event.target.value.replace(/\D/g, '') }))} />
                </div>
              </div>
            )}
            {passwordMode && (
              <>
                <div className="credential-field">
                  <label htmlFor="new-password">New Password</label>
                  <div className="login-input-wrap">
                    <LockKeyhole size={19} />
                    <input id="new-password" type={showPassword ? 'text' : 'password'} required minLength="8" autoComplete="new-password" placeholder="Create your new password" value={values.password} onChange={(event) => setValues((current) => ({ ...current, password: event.target.value }))} />
                    <button className="show-password-button" type="button" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? 'Hide new password' : 'Show new password'}>{showPassword ? <Eye size={18} /> : <EyeOff size={18} />}</button>
                  </div>
                </div>
                <div className="credential-strength" aria-label={`Password strength: ${strength} of 4`}>
                  <div>{[1, 2, 3, 4].map((level) => <span key={level} className={strength >= level ? 'active' : ''} />)}</div>
                  <small>{strength < 2 ? 'Weak' : strength < 4 ? 'Getting stronger' : 'Strong password'}</small>
                </div>
                <div className="credential-rules">
                  {rules.map((rule) => <span className={rule.met ? 'met' : ''} key={rule.label}><Check size={13} /> {rule.label}</span>)}
                </div>
                <div className="credential-field">
                  <label htmlFor="confirm-password">Confirm Password</label>
                  <div className="login-input-wrap">
                    <ShieldCheck size={19} />
                    <input id="confirm-password" type={showConfirm ? 'text' : 'password'} required autoComplete="new-password" placeholder="Re-enter your new password" value={values.confirm} onChange={(event) => setValues((current) => ({ ...current, confirm: event.target.value }))} />
                    <button className="show-password-button" type="button" onClick={() => setShowConfirm((visible) => !visible)} aria-label={showConfirm ? 'Hide confirmed password' : 'Show confirmed password'}>{showConfirm ? <Eye size={18} /> : <EyeOff size={18} />}</button>
                  </div>
                  {values.confirm && <span className={`credential-match ${values.password === values.confirm ? 'matched' : ''}`}>{values.password === values.confirm ? 'Passwords match' : 'Passwords do not match yet'}</span>}
                </div>
              </>
            )}
            <button type="submit" disabled={busy}>
              {busy ? <LoaderCircle className="login-spinner" size={18} /> : <ShieldCheck size={18} />}
              <span>{busy ? 'Securing account...' : mode === 'forgot' ? 'Request Password Reset' : 'Save new password'}</span>
              {!busy && <ArrowRight size={18} />}
            </button>
          </form>
          {(mode === 'forgot' || mode === 'reset') && <Link className="credential-back-link" to="/login">Back to login</Link>}
          {mode === 'change' && (
            <>
              <button className="credential-back-button" type="button" onClick={backToLogin} disabled={busy}>
                <ArrowLeft size={16} /> Back to Login
              </button>
              <p className="credential-footer-note"><ShieldCheck size={14} /> You will be redirected automatically after your password is updated.</p>
            </>
          )}
        </section>
      </section>
    </main>
  );
}
