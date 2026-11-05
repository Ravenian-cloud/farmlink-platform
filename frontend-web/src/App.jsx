import { useEffect, useState } from 'react';
import api from './services/api';
import Dashboard from './pages/dashboards/Dashboard';
import Marketplace from './pages/marketplace/Marketplace';
import PilotFeedbackForm from './pages/pilot/PilotFeedbackForm';
import AdPortal from './pages/marketplace/AdPortal';
import CommunityHub from './pages/community/CommunityHub';
import CommunityAdminTools from './pages/community/CommunityAdminTools';
import ImpactReports from './pages/dashboards/ImpactReports';
import AuthPanel from './pages/AuthPanel';

export default function App() {
  const [page, setPage] = useState('marketplace');
  const [user, setUser] = useState(null);
  const [authLoading, setAuthLoading] = useState(true);

  useEffect(() => {
    api.get('/me')
      .then(({ data }) => setUser(data.data))
      .catch(() => setUser(null))
      .finally(() => setAuthLoading(false));
  }, []);

  function signOut() {
    window.sessionStorage.removeItem('farmlink_access_token');
    setUser(null);
    setPage('marketplace');
  }

  function renderPage() {
    if (page === 'account') {
      return authLoading
        ? <p role="status">Checking your account…</p>
        : <AuthPanel
          user={user}
          onAuthenticated={(authenticatedUser) => { setUser(authenticatedUser); setPage('dashboard'); }}
          onLogout={signOut}
        />;
    }
    if (page === 'dashboard') return <Dashboard user={user} />;
    if (page === 'feedback') return <PilotFeedbackForm />;
    if (page === 'community') return <CommunityHub />;
    if (page === 'community-admin') return <CommunityAdminTools />;
    if (page === 'impact') return <ImpactReports user={user} />;
    if (page === 'ads' && user?.role === 'supplier') return <AdPortal />;
    return <Marketplace user={user} />;
  }

  return (
    <>
      <header>
        <nav aria-label="Main navigation">
          <button type="button" onClick={() => setPage('marketplace')}>Marketplace</button>
          <button type="button" onClick={() => setPage('dashboard')}>Dashboard</button>
          <button type="button" onClick={() => setPage('feedback')}>Pilot feedback</button>
          <button type="button" onClick={() => setPage('community')}>Knowledge & forum</button>
          <button type="button" onClick={() => setPage('impact')}>SDG impact</button>
          {user && <button type="button" onClick={() => setPage('community-admin')}>Community admin</button>}
          {user?.role === 'supplier' && (
            <button type="button" onClick={() => setPage('ads')}>Advertising</button>
          )}
          <button type="button" onClick={() => setPage('account')}>Account</button>
        </nav>
      </header>
      {renderPage()}
    </>
  );
}
