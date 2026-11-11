import { useState } from 'react';
import api from '../services/api';

function accessTokenFrom(response) {
  const body = response.data?.data || response.data;
  const token = body?.access_token || body?.token;
  if (typeof token !== 'string' || token.length === 0) {
    throw new Error('The authentication response did not contain an access token.');
  }
  return token;
}

function errorMessage(requestError) {
  const errors = requestError.response?.data?.errors;
  return (errors && Object.values(errors).flat()[0])
    || requestError.response?.data?.error?.message
    || requestError.response?.data?.message
    || 'Authentication failed. Check your details and try again.';
}

export default function AuthPanel({ user, onAuthenticated, onLogout }) {
  const [mode, setMode] = useState('login');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [role, setRole] = useState('farmer');
  const [email, setEmail] = useState('');
  const [county, setCounty] = useState('');
  const [farmName, setFarmName] = useState('');
  const [businessName, setBusinessName] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setMessage('');
    try {
      if (mode === 'register') {
        const profile = {
          county: county.trim() || undefined,
          ...(role === 'farmer' ? { farm_name: farmName.trim() || undefined } : {}),
          ...(role === 'supplier' ? { business_name: businessName.trim() || undefined } : {}),
        };
        const registration = await api.post('/auth/register', {
          name: name.trim(),
          phone: phone.trim(),
          email: email.trim() || undefined,
          password,
          role,
          profile,
        });
        setMode('login');
        setName('');
        setPhone('');
        setRole('farmer');
        setEmail('');
        setCounty('');
        setFarmName('');
        setBusinessName('');
        setPassword('');
        setShowPassword(false);
        setMessage('Your account was created. Sign in with your phone number or email and password.');
        return;
      } else {
        const loginValue = phone.trim();
        const response = await api.post('/auth/login', { login: loginValue, password });
        window.sessionStorage.setItem('farmlink_access_token', accessTokenFrom(response));
      }

      const { data } = await api.get('/me');
      onAuthenticated(data.data);
      setPassword('');
      setMessage('You are signed in.');
    } catch (requestError) {
      if (!requestError.response && requestError.message?.includes('access token')) {
        setError(requestError.message);
      } else {
        window.sessionStorage.removeItem('farmlink_access_token');
        setError(errorMessage(requestError));
      }
    } finally {
      setBusy(false);
    }
  }

  if (user) {
    return (
      <main className="account-page">
        <section className="account-card" aria-label="Account">
          <span className="auth-step">YOUR FARMLINK ACCOUNT</span>
          <h1>You’re signed in.</h1>
          <p>Signed in as <strong>{user.name}</strong> ({user.role}).</p>
          <p>Account verification: <strong>{user.kyc_status || 'pending'}</strong></p>
          <button type="button" onClick={onLogout}>Sign out</button>
        </section>
      </main>
    );
  }

  const registering = mode === 'register';

  return (
    <main className="auth-page" aria-labelledby="account-title">
      <section className="auth-intro">
        <span className="auth-eyebrow">GROW WITH YOUR COMMUNITY</span>
        <h1>{registering ? 'Make your next move.' : 'Welcome back.'}</h1>
        <p>{registering
          ? 'Join local farmers, buyers, and suppliers building better markets together.'
          : 'Sign in to manage listings, offers, and orders in one place.'}</p>
        <div className="auth-points"><span>Local marketplace</span><span>Direct negotiation</span><span>Built for growers</span></div>
      </section>

      <section className="auth-card">
        <span className="auth-step">{registering ? 'GET STARTED · FREE ACCOUNT' : 'YOUR FARMLINK ACCOUNT'}</span>
        <h2 id="account-title">{registering ? 'Create your account' : 'Sign in'}</h2>
        <p className="auth-subtitle">{registering ? 'A few details to get your account ready.' : 'Enter your phone number or email and password.'}</p>
        {error && <p className="auth-message error-message" role="alert">{error}</p>}
        {message && <p className="auth-message success-message" role="status">{message}</p>}

        <form onSubmit={submit}>
          {registering && <>
            <label>Full name
              <input autoComplete="name" required maxLength="160" value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. Amina Wanjiku" />
            </label>
            <label>I am joining as a
              <select value={role} onChange={(event) => setRole(event.target.value)}>
                <option value="farmer">Farmer</option>
                <option value="buyer">Buyer</option>
                <option value="supplier">Agricultural supplier</option>
              </select>
            </label>
            {role === 'farmer' && <label><span className="field-label-row"><span>Farm name</span><span className="optional-label">Optional</span></span>
              <input autoComplete="organization" maxLength="160" value={farmName} onChange={(event) => setFarmName(event.target.value)} placeholder="Your farm or cooperative" />
            </label>}
            {role === 'supplier' && <label><span className="field-label-row"><span>Business name</span><span className="optional-label">Optional</span></span>
              <input autoComplete="organization" maxLength="160" value={businessName} onChange={(event) => setBusinessName(event.target.value)} placeholder="Your business name" />
            </label>}
            <label><span className="field-label-row"><span>County</span><span className="optional-label">Optional</span></span>
              <input autoComplete="address-level1" maxLength="100" value={county} onChange={(event) => setCounty(event.target.value)} placeholder="e.g. Nakuru" />
            </label>
          </>}

          <label>{registering ? 'Phone number' : 'Phone number or email'}
            <input
              type={registering ? 'tel' : 'text'}
              autoComplete={registering ? 'tel' : 'username'}
              required
              maxLength={registering ? 32 : 254}
              value={phone}
              onChange={(event) => setPhone(event.target.value)}
              placeholder={registering ? '+254 7XX XXX XXX' : 'Phone number or email'}
            />
          </label>
          {registering && <label><span className="field-label-row"><span>Email address</span><span className="optional-label">Optional</span></span>
            <input type="email" autoComplete="email" maxLength="254" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="you@example.com" />
          </label>}
          <label>Password
            <input
              type={showPassword ? 'text' : 'password'}
              autoComplete={registering ? 'new-password' : 'current-password'}
              required minLength={registering ? 12 : undefined}
              value={password} onChange={(event) => setPassword(event.target.value)}
              aria-describedby={registering ? 'password-hint' : undefined}
            />
            {registering && <small id="password-hint" className="field-hint">Use at least 12 characters.</small>}
          </label>
          <label className="show-password"><input type="checkbox" checked={showPassword} onChange={(event) => setShowPassword(event.target.checked)} /> Show password</label>
          <button className="auth-submit" type="submit" disabled={busy}>
            {busy ? 'Please wait…' : registering ? 'Create account' : 'Sign in'}
          </button>
        </form>

        <p className="auth-switch">
          {registering ? 'Already have an account?' : 'New to FarmLink?'}{' '}
          <button className="text-button" type="button" disabled={busy} onClick={() => {
            setError('');
            setMessage('');
            setMode(registering ? 'login' : 'register');
          }}>{registering ? 'Sign in' : 'Create an account'}</button>
        </p>
      </section>
    </main>
  );
}
