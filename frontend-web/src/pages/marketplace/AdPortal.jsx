import { useEffect, useState } from 'react';
import api from '../../services/api';

export default function AdPortal() {
  const [campaigns, setCampaigns] = useState([]);
  const [name, setName] = useState('');
  const [targetRole, setTargetRole] = useState('farmer');
  const [destinationUrl, setDestinationUrl] = useState('');
  const [creative, setCreative] = useState(null);
  const [phone, setPhone] = useState('');
  const [pricing, setPricing] = useState(null);
  const [pendingCampaign, setPendingCampaign] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function loadCampaigns() {
    const { data } = await api.get('/ads/mine');
    setCampaigns(data.data);
  }

  useEffect(() => {
    Promise.all([loadCampaigns(), api.get('/ads/pricing')])
      .then(([, { data }]) => setPricing(data.data))
      .catch(() => setError('Could not load advertising campaign pricing or history.'));
  }, []);

  async function createCampaign(event) {
    event.preventDefault();
    setError('');
    setMessage('');
    const form = new FormData();
    form.append('name', name);
    form.append('target_role', targetRole);
    form.append('destination_url', destinationUrl);
    form.append('creative', creative);
    try {
      const { data } = await api.post('/ads', form);
      setPendingCampaign(data.data);
      setMessage('Advertisement uploaded. Complete payment to submit it for review.');
      await loadCampaigns();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Could not upload the advertisement.');
    }
  }

  async function purchaseCampaign(event) {
    event.preventDefault();
    setError('');
    try {
      const { data } = await api.post(`/ads/${pendingCampaign.id}/purchase`, { phone });
      setMessage(data.data.customer_message || 'Approve the M-Pesa prompt. The paid ad will be reviewed before publication.');
      await loadCampaigns();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Could not start advertisement payment.');
    }
  }

  return (
    <main>
      <h1>Advertising portal</h1>
      <p>Only HTTPS destinations and image creatives up to 2 MB are accepted. Paid ads are reviewed before they appear.</p>
      {pricing && (
        <p>
          Campaign fee: {pricing.enabled
            ? new Intl.NumberFormat(undefined, { style: 'currency', currency: 'KES' }).format(pricing.monthly_price_minor / 100)
            : 'Checkout is not enabled yet'} per {pricing.duration_days} days.
        </p>
      )}
      <form onSubmit={createCampaign}>
        <label htmlFor="ad-name">Campaign name</label>
        <input id="ad-name" value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} />
        <label htmlFor="ad-target">Audience</label>
        <select id="ad-target" value={targetRole} onChange={(event) => setTargetRole(event.target.value)}>
          <option value="farmer">Farmers</option>
          <option value="buyer">Buyers</option>
          <option value="supplier">Suppliers</option>
        </select>
        <label htmlFor="ad-url">HTTPS destination URL</label>
        <input id="ad-url" type="url" value={destinationUrl} onChange={(event) => setDestinationUrl(event.target.value)} required />
        <label htmlFor="ad-image">Creative (JPG, PNG or WebP)</label>
        <input id="ad-image" type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setCreative(event.target.files?.[0] || null)} required />
        <button type="submit" disabled={!pricing?.enabled}>Upload campaign</button>
      </form>
      {pendingCampaign && (
        <form onSubmit={purchaseCampaign}>
          <h2>Pay for {pendingCampaign.name}</h2>
          <label htmlFor="ad-phone">M-Pesa phone number</label>
          <input id="ad-phone" value={phone} onChange={(event) => setPhone(event.target.value)} required maxLength={32} />
          <button type="submit">Pay with M-Pesa</button>
        </form>
      )}
      {message && <p role="status">{message}</p>}
      {error && <p role="alert">{error}</p>}
      <h2>Your campaigns</h2>
      <ul>{campaigns.map((campaign) => (
        <li key={campaign.id}>
          {campaign.name}: {campaign.status}; payment {campaign.payment_status || 'not started'}; {campaign.impressions_count} impressions, {campaign.clicks_count} clicks.
          {campaign.status === 'draft' && !['initiated', 'pending'].includes(campaign.payment_status) && (
            <button type="button" onClick={() => setPendingCampaign(campaign)}>Complete payment</button>
          )}
        </li>
      ))}</ul>
    </main>
  );
}
