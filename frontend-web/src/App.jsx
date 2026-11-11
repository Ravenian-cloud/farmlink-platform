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
    <div className="app-shell">
      <header className="site-header">
        <a className="brand" href="#marketplace" onClick={(event) => { event.preventDefault(); setPage('marketplace'); }} aria-label="FarmLink home">
          <span className="brand-mark" aria-hidden="true">F</span>
          <span>FarmLink<small>Markets that grow together</small></span>
        </a>
        <nav aria-label="Main navigation">
          <button className={page === 'marketplace' ? 'active' : ''} type="button" onClick={() => setPage('marketplace')}>Marketplace</button>
          <button className={page === 'dashboard' ? 'active' : ''} type="button" onClick={() => setPage('dashboard')}>Dashboard</button>
          <button className={page === 'community' ? 'active' : ''} type="button" onClick={() => setPage('community')}>Community</button>
          <button className={page === 'impact' ? 'active' : ''} type="button" onClick={() => setPage('impact')}>Impact</button>
          <button className={page === 'feedback' ? 'active' : ''} type="button" onClick={() => setPage('feedback')}>Feedback</button>
          {user && <button className={page === 'community-admin' ? 'active' : ''} type="button" onClick={() => setPage('community-admin')}>Community admin</button>}
          {user?.role === 'supplier' && (
            <button className={page === 'ads' ? 'active' : ''} type="button" onClick={() => setPage('ads')}>Advertising</button>
          )}
          <button className={`account-link ${page === 'account' ? 'active' : ''}`} type="button" onClick={() => setPage('account')}>
            {user ? user.name : 'Sign in'}
          </button>
        </nav>
      </header>
      {renderPage()}
      <footer className="site-footer"><span>FarmLink</span><span>Connecting growers, buyers, and suppliers.</span></footer>
    </div>
  );
}
