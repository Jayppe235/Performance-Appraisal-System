import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { apiUrl } from '../data/apiBase.js';

async function auth(action, values = {}) {
  const response = await fetch(apiUrl('/api/auth.php'), { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ action, ...values }) });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to complete this request.');
  return data;
}

export default function CredentialPage({ mode, session, onUserUpdate }) {
  const navigate = useNavigate(); const [params] = useSearchParams();
  const [values, setValues] = useState({ user_code: '', password: '', confirm: '' });
  const [message, setMessage] = useState(''); const [error, setError] = useState(''); const [busy, setBusy] = useState(false);
  const token = params.get('token') || '';
  useEffect(() => { if (mode === 'verify' && token) submit(null, true); }, [mode, token]);
  async function submit(event, automatic = false) {
    event?.preventDefault(); setBusy(true); setError('');
    try {
      let data;
      if (mode === 'change') { if (values.password !== values.confirm) throw new Error('Passwords do not match.'); data = await auth('change-password', { password: values.password }); window.sessionStorage.removeItem('pmas-password-change-authorized'); onUserUpdate?.(data.user); navigate('/', { replace: true }); return; }
      if (mode === 'forgot') data = await auth('request-reset', { user_code: values.user_code });
      if (mode === 'reset') { if (values.password !== values.confirm) throw new Error('Passwords do not match.'); data = await auth('reset-password', { token, password: values.password }); }
      if (mode === 'verify') data = await auth('verify-email', { token });
      if (mode === 'required') data = await auth('send-verification');
      setMessage(data.message);
      if (mode === 'verify' && session?.isLoggedIn) setTimeout(() => window.location.assign('/'), 900);
    } catch (e) { setError(e.message); } finally { setBusy(false); }
  }
  const passwordMode = mode === 'change' || mode === 'reset';
  const title = { change: 'Change your temporary password', forgot: 'Recover your account', reset: 'Choose a new password', required: 'Verify your email', verify: 'Verifying your email' }[mode];
  return <main className="login-page"><section className="login-shell"><section className="login-card"><h1>{title}</h1>
    {mode === 'change' && <p>Your temporary password must be replaced before continuing.</p>}
    {mode === 'required' && <p>Dashboard access is locked until you verify {session?.user?.email || 'your email address'}.</p>}
    {error && <div className="alert" role="alert">{error}</div>}{message && <div className="notice success" role="status">{message}</div>}
    {mode !== 'verify' && <form className="form" onSubmit={submit}>
      {mode === 'forgot' && <><p>An administrator will review your request and reset your password.</p><label>Username Code<input inputMode="numeric" required value={values.user_code} onChange={e=>setValues(v=>({...v,user_code:e.target.value.replace(/\D/g,'')}))}/></label></>}
      {passwordMode && <><label>New Password<input type="password" required minLength="8" autoComplete="new-password" value={values.password} onChange={e=>setValues(v=>({...v,password:e.target.value}))}/></label><label>Confirm Password<input type="password" required value={values.confirm} onChange={e=>setValues(v=>({...v,confirm:e.target.value}))}/></label></>}
      <button disabled={busy}>{busy ? 'Please wait…' : mode === 'required' ? 'Send verification email' : mode === 'forgot' ? 'Request Password Reset' : 'Continue'}</button>
    </form>}
    {(mode === 'forgot' || mode === 'reset') && <Link to="/login">Back to login</Link>}
  </section></section></main>;
}
