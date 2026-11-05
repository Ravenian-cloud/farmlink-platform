import { useState } from 'react';
import api from '../../services/api';

const today = new Date().toISOString().slice(0, 10);
const currentMonth = today.slice(0, 7);

export default function ImpactReports({ user }) {
  const [partnerKey, setPartnerKey] = useState('');
  const [reports, setReports] = useState([]);
  const [meta, setMeta] = useState(null);
  const [observations, setObservations] = useState([]);
  const [county, setCounty] = useState(user?.profile?.county || '');
  const [period, setPeriod] = useState(currentMonth);
  const [form, setForm] = useState({
    baseline_monthly_income_minor: '',
    current_monthly_income_minor: '',
    baseline_post_harvest_loss_percent: '',
    current_post_harvest_loss_percent: '',
    consent: false,
  });
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);

  async function loadMyObservations() {
    try {
      const { data } = await api.get('/impact/observations');
      setObservations(data.data || []);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Your impact observations could not be loaded.');
    }
  }

  async function loadPartnerReport(event) {
    event.preventDefault();
    setLoading(true);
    setError('');
    setMessage('');
    try {
      const { data } = await api.get('/policy/impact/report', {
        headers: { 'X-Partner-Key': partnerKey },
      });
      setReports(data.data || []);
      setMeta(data.meta);
    } catch (requestError) {
      setError(requestError.response?.status === 401
        ? 'The partner key is missing or invalid.'
        : requestError.response?.data?.message || 'Impact reports could not be loaded.');
    } finally {
      setLoading(false);
    }
  }

  async function submitObservation(event) {
    event.preventDefault();
    setError('');
    setMessage('');
    try {
      await api.patch('/profile', { county });
      await api.post('/impact/observations', {
        reporting_period: `${period}-01`,
        ...form,
      });
      setMessage('Your voluntary impact observation was saved.');
      await loadMyObservations();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'The observation was not saved.');
    }
  }

  function downloadReport() {
    const report = { meta, data: reports };
    const url = URL.createObjectURL(new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `farmlink-impact-report-${today}.json`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  return (
    <main>
      <h1>SDG impact reporting</h1>
      <p>
        County-level, privacy-thresholded indicators aligned with SDGs 1, 2, and 9.
        Reported results are observational; targets are not proof of impact.
      </p>
      {error && <p role="alert">{error}</p>}
      {message && <p role="status">{message}</p>}

      {user?.role === 'farmer' && (
        <section>
          <h2>Submit a voluntary farm outcome observation</h2>
          <p>Enter your monthly income in KES minor units (100 units = KES 1) and your estimated post-harvest loss percentages.</p>
          <form onSubmit={submitObservation}>
            <label>County<input value={county} onChange={(event) => setCounty(event.target.value)} minLength="2" maxLength="100" required /></label>
            <label>Reporting month<input type="month" value={period} max={currentMonth} onChange={(event) => setPeriod(event.target.value)} required /></label>
            <label>Baseline monthly income (KES minor units)<input type="number" min="0" max="1000000000000" step="1" value={form.baseline_monthly_income_minor} onChange={(event) => setForm({ ...form, baseline_monthly_income_minor: event.target.value })} required /></label>
            <label>Current monthly income (KES minor units)<input type="number" min="0" max="1000000000000" step="1" value={form.current_monthly_income_minor} onChange={(event) => setForm({ ...form, current_monthly_income_minor: event.target.value })} required /></label>
            <label>Baseline post-harvest loss (%)<input type="number" min="0" max="100" step="0.01" value={form.baseline_post_harvest_loss_percent} onChange={(event) => setForm({ ...form, baseline_post_harvest_loss_percent: event.target.value })} required /></label>
            <label>Current post-harvest loss (%)<input type="number" min="0" max="100" step="0.01" value={form.current_post_harvest_loss_percent} onChange={(event) => setForm({ ...form, current_post_harvest_loss_percent: event.target.value })} required /></label>
            <label>
              <input type="checkbox" checked={form.consent} onChange={(event) => setForm({ ...form, consent: event.target.checked })} required />
              I consent to my report being used in aggregated county-level impact reporting.
            </label>
            <button type="submit">Save observation</button>
          </form>
          <button type="button" onClick={loadMyObservations}>View my saved reports</button>
          <ul>
            {observations.map((observation) => (
              <li key={observation.id}>
                {observation.reporting_period}: baseline income {observation.baseline_monthly_income_minor}, current income {observation.current_monthly_income_minor} (KES minor units).
              </li>
            ))}
          </ul>
        </section>
      )}

      <section>
        <h2>Partner impact dashboard</h2>
        <p>Use a county-scoped partner key issued by FarmLink. The key is kept only for this browser session.</p>
        <form onSubmit={loadPartnerReport}>
          <label>Partner API key<input type="password" autoComplete="off" value={partnerKey} onChange={(event) => setPartnerKey(event.target.value)} required /></label>
          <button type="submit" disabled={loading}>{loading ? 'Loading…' : 'Load impact reports'}</button>
        </form>
        {meta && (
          <>
            <p>{meta.privacy}</p>
            <p>{meta.limitations}</p>
            <button type="button" onClick={downloadReport} disabled={reports.length === 0}>Download JSON report</button>
          </>
        )}
        {reports.length === 0 && meta && <p>No county currently meets the minimum reporting cohort.</p>}
        {reports.map(({ region, metrics }) => (
          <article key={region.county}>
            <h3>{region.county}</h3>
            <p>Reporting farmers: {metrics.farmer_sample_size}</p>
            <p>
              Average monthly income: {metrics.average_baseline_monthly_income_minor} → {metrics.average_current_monthly_income_minor} KES minor units
              {metrics.observed_income_change_percent === null ? '.' : ` (${metrics.observed_income_change_percent}% observed change).`}
            </p>
            <p>
              Income target scenario: +{metrics.income_target_percent.minimum}% to +{metrics.income_target_percent.maximum}%;
              projected range {metrics.projected_target_monthly_income_minor.minimum}–{metrics.projected_target_monthly_income_minor.maximum} KES minor units.
            </p>
            <p>
              Average post-harvest loss: {metrics.average_baseline_post_harvest_loss_percent}% → {metrics.average_current_post_harvest_loss_percent}%
              {' '}({metrics.post_harvest_loss_reduction_percentage_points} percentage-point change).
            </p>
            <p>Completed produce trades in 12 months: {metrics.completed_produce_trades_12m ?? 'not reported (small cohort)'}</p>
            <small>{metrics.methodology}</small>
          </article>
        ))}
      </section>
    </main>
  );
}
