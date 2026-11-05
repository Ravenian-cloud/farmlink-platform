import { useEffect, useState } from 'react';
import api from '../../services/api';

export default function PremiumAccount() {
  const [subscription, setSubscription] = useState(null);
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [checkingPayment, setCheckingPayment] = useState(false);

  useEffect(() => {
    let active = true;
    api.get('/subscription')
      .then(({ data }) => {
        if (active) {
          setSubscription(data.data);
          setCheckingPayment(['initiated', 'pending'].includes(data.data.latest_payment_status));
        }
      })
      .catch(() => { if (active) setError('Could not load subscription details.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!checkingPayment) return undefined;
    let active = true;
    let attempts = 0;
    const timer = window.setInterval(async () => {
      attempts += 1;
      try {
        const { data } = await api.get('/subscription');
        if (active) setSubscription(data.data);
        const paymentFinished = ['failed', 'cancelled'].includes(data.data.latest_payment_status);
        if (data.data.status === 'active' || paymentFinished || attempts >= 12) {
          window.clearInterval(timer);
          if (active) setCheckingPayment(false);
          if (active && paymentFinished) setError('Premium payment was not completed. You can try again.');
        }
      } catch {
        if (active) setError('Payment started, but subscription status could not be refreshed.');
        window.clearInterval(timer);
        if (active) setCheckingPayment(false);
      }
    }, 5000);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [checkingPayment]);

  async function purchase(event) {
    event.preventDefault();
    setError('');
    setMessage('');
    try {
      const { data } = await api.post('/subscription/purchase', { phone });
      setMessage(data.data.customer_message || 'Approve the M-Pesa prompt on your phone. Premium activates after payment confirmation.');
      setCheckingPayment(true);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Could not start Premium payment.');
    }
  }

  if (loading) return <p role="status">Loading Premium account…</p>;

  return (
    <section aria-labelledby="premium-account-title">
      <h2 id="premium-account-title">FarmLink Premium</h2>
      {subscription?.status === 'active'
        ? <p>Premium active until {new Date(subscription.ends_at).toLocaleDateString()}.</p>
        : <p>Get advanced market insights and increased visibility for your listings.</p>}
      {subscription?.price_minor > 0 && (
        <p>Price: {new Intl.NumberFormat(undefined, { style: 'currency', currency: 'KES' }).format(subscription.price_minor / 100)} per 30 days.</p>
      )}
      <p>30-day subscription. Renewal is manual; no automatic recurring charge.</p>
      {subscription?.status !== 'active'
        && !checkingPayment
        && !['initiated', 'pending'].includes(subscription?.latest_payment_status)
        && subscription?.price_minor >= 100
        && subscription.price_minor % 100 === 0 && (
        <form onSubmit={purchase}>
          <label htmlFor="premium-phone">M-Pesa phone number</label>
          <input id="premium-phone" value={phone} onChange={(event) => setPhone(event.target.value)} required maxLength={32} />
          <button type="submit">Continue with M-Pesa</button>
        </form>
      )}
      {subscription?.status !== 'active'
        && (!subscription?.price_minor || subscription.price_minor < 100 || subscription.price_minor % 100 !== 0)
        && (
        <p>Premium checkout is not enabled yet.</p>
      )}
      {message && <p role="status">{message}</p>}
      {checkingPayment && <p role="status">Waiting for M-Pesa payment confirmation…</p>}
      {!checkingPayment && ['initiated', 'pending'].includes(subscription?.latest_payment_status)
        && <p role="status">Payment confirmation is still pending. Do not retry; contact support if it does not resolve.</p>}
      {error && <p role="alert">{error}</p>}
    </section>
  );
}
