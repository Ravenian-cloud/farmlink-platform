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

export default function AuthPanel({ user, onAuthenticated, onLogout }) {
  const [mode, setMode] = useState('login');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [role, setRole] = useState('farmer');
  const [password, setPassword] = useState('');
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
        const registration = await api.post('/auth/register', { name: name.trim(), phone: phone.trim(), password, role });
        const body = registration.data?.data || registration.data;
        const token = body?.access_token || body?.token;
        if (typeof token !== 'string' || token.length === 0) {
          setMode('login');
          setMessage('Account created. Sign in with your phone number and password.');
          return;
        }
        window.sessionStorage.setItem('farmlink_access_token', token);
      } else {
        const response = await api.post('/auth/login', { login: phone.trim(), password });
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
        setError(requestError.response?.data?.error?.message
          || requestError.response?.data?.message
          || 'Authentication failed. Check your details and try again.');
      }
    } finally {
      setBusy(false);
    }
  }

  if (user) {
    return (
      <section aria-label="Account">
        <p>Signed in as {user.name} ({user.role})</p>
        <button type="button" onClick={onLogout}>Sign out</button>
      </section>
    );
  }

  return (
    <section aria-labelledby="account-title">
      <h2 id="account-title">{mode === 'login' ? 'Sign in' : 'Create your FarmLink account'}</h2>
      {error && <p role="alert">{error}</p>}
      {message && <p role="status">{message}</p>}
      <form onSubmit={submit}>
        {mode === 'register' && (
          <>
            <label>
              Name
              <input autoComplete="name" required maxLength="160" value={name} onChange={(event) => setName(event.target.value)} />
            </label>
            <label>
              Account type
              <select value={role} onChange={(event) => setRole(event.target.value)}>
                <option value="farmer">Farmer</option>
                <option value="buyer">Buyer</option>
                <option value="supplier">Supplier</option>
              </select>
            </label>
          </>
        )}
        <label>
          Phone
          <input type="tel" autoComplete="tel" required maxLength="32" value={phone} onChange={(event) => setPhone(event.target.value)} />
        </label>
        <label>
          Password
          <input
            type="password"
            autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
            required
            minLength={mode === 'register' ? 12 : undefined}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>
        <button type="submit" disabled={busy}>{busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account'}</button>
      </form>
      <button
        type="button"
        onClick={() => {
          setError('');
          setMessage('');
          setMode(mode === 'login' ? 'register' : 'login');
        }}
      >
        {mode === 'login' ? 'Create an account' : 'I already have an account'}
      </button>
    </section>
  );
}
