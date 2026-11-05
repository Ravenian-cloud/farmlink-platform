import { useCallback, useEffect, useState } from 'react';
import api from '../../services/api';
import MarketInsights from './MarketInsights';
import PremiumAccount from './PremiumAccount';
import SponsoredAd from './SponsoredAd';

const dashboardCopy = {
  farmer: {
    title: 'Farmer dashboard',
    description: 'Manage your produce, review buyer offers, and track orders.',
    productCategory: 'produce',
    productTypeLabel: 'Crop',
    productTypeField: 'crop',
  },
  buyer: {
    title: 'Buyer dashboard',
    description: 'Discover produce and inputs, negotiate prices, and follow orders.',
  },
  supplier: {
    title: 'Supplier dashboard',
    description: 'Manage agricultural inputs, respond to enquiries, and track orders.',
    productCategory: 'input',
    productTypeLabel: 'Input type',
    productTypeField: 'input_type',
  },
};

function messageFrom(error, fallback) {
  return error.response?.data?.error?.message
    || error.response?.data?.message
    || fallback;
}

function formatPrice(amount, currency = 'KES') {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(amount) / 100);
}

function ProductForm({ content, onCreated }) {
  const [values, setValues] = useState({
    name: '',
    type: '',
    brand: '',
    description: '',
    quantity_available: '',
    unit: 'kg',
    price_minor: '',
    county: '',
  });
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.post('/products', {
        category: content.productCategory,
        name: values.name.trim(),
        [content.productTypeField]: values.type.trim(),
        brand: values.brand.trim() || undefined,
        description: values.description.trim(),
        quantity_available: Number(values.quantity_available),
        unit: values.unit.trim(),
        price_minor: Number(values.price_minor),
        currency: 'KES',
        county: values.county.trim() || undefined,
      });
      setValues({
        name: '',
        type: '',
        brand: '',
        description: '',
        quantity_available: '',
        unit: 'kg',
        price_minor: '',
        county: '',
      });
      onCreated();
    } catch (requestError) {
      setError(messageFrom(requestError, 'Your listing could not be created.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={submit}>
      <h3>Create a listing</h3>
      <label>
        Name
        <input required maxLength="180" value={values.name} onChange={(event) => setValues({ ...values, name: event.target.value })} />
      </label>
      <label>
        {content.productTypeLabel}
        <input required maxLength="100" value={values.type} onChange={(event) => setValues({ ...values, type: event.target.value })} />
      </label>
      {content.productCategory === 'input' && (
        <label>
          Brand (optional)
          <input maxLength="120" value={values.brand} onChange={(event) => setValues({ ...values, brand: event.target.value })} />
        </label>
      )}
      <label>
        Description
        <textarea maxLength="10000" value={values.description} onChange={(event) => setValues({ ...values, description: event.target.value })} />
      </label>
      <label>
        Quantity
        <input type="number" min="0.001" step="0.001" required value={values.quantity_available} onChange={(event) => setValues({ ...values, quantity_available: event.target.value })} />
      </label>
      <label>
        Unit (e.g. kg, bag)
        <input required maxLength="24" value={values.unit} onChange={(event) => setValues({ ...values, unit: event.target.value })} />
      </label>
      <label>
        Price per unit (KES minor units)
        <input type="number" min="1" step="1" required value={values.price_minor} onChange={(event) => setValues({ ...values, price_minor: event.target.value })} />
      </label>
      <label>
        County (optional)
        <input maxLength="100" value={values.county} onChange={(event) => setValues({ ...values, county: event.target.value })} />
      </label>
      <button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Publish listing'}</button>
      {error && <p role="alert">{error}</p>}
    </form>
  );
}

function BidCard({ bid, user, refresh, setPageError }) {
  const [quantity, setQuantity] = useState(bid.current_offer?.quantity || '');
  const [price, setPrice] = useState(bid.current_offer?.unit_price_minor || '');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const isSeller = bid.seller_id === user.id;
  const currentOffer = bid.current_offer || bid.revisions?.[bid.revisions.length - 1];
  const lastUpdatedByMe = currentOffer?.created_by === user.id;
  const canRespond = bid.status === 'pending' && !lastUpdatedByMe;

  async function runAction(action, payload) {
    setBusy(true);
    setPageError('');
    try {
      await api.post(`/bids/${bid.id}/${action}`, payload || {});
      await refresh();
    } catch (error) {
      setPageError(messageFrom(error, 'The offer could not be updated.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <article>
      <h3>{bid.product?.name || 'Marketplace listing'}</h3>
      <p>Status: {bid.status}</p>
      {currentOffer && (
        <p>
          Latest offer: {currentOffer.quantity} at {formatPrice(currentOffer.unit_price_minor)} per unit
          {currentOffer.message ? ` — ${currentOffer.message}` : ''}
        </p>
      )}
      <p>Role: {isSeller ? 'Seller' : 'Buyer'} · Offer expires {bid.expires_at ? new Date(bid.expires_at).toLocaleString() : 'not specified'}</p>
      {bid.status === 'pending' && (
        <details>
          <summary>Offer history</summary>
          <ol>
            {(bid.revisions || []).map((revision) => (
              <li key={revision.id}>
                {revision.quantity} × {formatPrice(revision.unit_price_minor)} — {revision.message || 'No message'}
              </li>
            ))}
          </ol>
        </details>
      )}
      {canRespond && (
        <>
          {isSeller && <button type="button" disabled={busy} onClick={() => runAction('accept')}>Accept offer</button>}
          {isSeller && <button type="button" disabled={busy} onClick={() => runAction('reject')}>Reject offer</button>}
          <form onSubmit={(event) => { event.preventDefault(); runAction('counter', { quantity: Number(quantity), unit_price_minor: Number(price), message: message.trim() || undefined }); }}>
            <h4>Counter-offer</h4>
            <label>
              Quantity
              <input type="number" min="0.001" step="0.001" required value={quantity} onChange={(event) => setQuantity(event.target.value)} />
            </label>
            <label>
              Unit price (KES minor units)
              <input type="number" min="1" step="1" required value={price} onChange={(event) => setPrice(event.target.value)} />
            </label>
            <label>
              Message (optional)
              <input maxLength="1000" value={message} onChange={(event) => setMessage(event.target.value)} />
            </label>
            <button type="submit" disabled={busy}>Send counter-offer</button>
          </form>
        </>
      )}
    </article>
  );
}

function TransactionCard({ transaction, user, refresh, setPageError }) {
  const [phone, setPhone] = useState('');
  const [busy, setBusy] = useState(false);
  const isBuyer = transaction.buyer_id === user.id;

  async function initiatePayment(event) {
    event.preventDefault();
    setBusy(true);
    setPageError('');
    try {
      await api.post(`/transactions/${transaction.id}/payments`, { phone });
      await refresh();
    } catch (error) {
      setPageError(messageFrom(error, 'Payment could not be initiated.'));
    } finally {
      setBusy(false);
    }
  }

  async function confirmDelivery() {
    if (!window.confirm('Confirm delivery only after you have received the goods. FarmLink escrow is simulated and does not hold funds.')) return;
    setBusy(true);
    setPageError('');
    try {
      await api.post(`/transactions/${transaction.id}/delivery-confirmation`, {});
      await refresh();
    } catch (error) {
      setPageError(messageFrom(error, 'Delivery could not be confirmed.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <article>
      <h3>{transaction.product?.name || 'Marketplace order'}</h3>
      <p>{transaction.quantity} {transaction.unit} · {formatPrice(transaction.total_minor, transaction.currency)}</p>
      <p>
        Order: {transaction.status} · Payment: {transaction.payment_status} · Escrow: {transaction.escrow_status} (simulated)
      </p>
      <p>{isBuyer ? 'Seller' : 'Buyer'}: {isBuyer ? transaction.seller?.name : transaction.buyer?.name}</p>
      {isBuyer && transaction.status === 'awaiting_payment' && transaction.payment_status !== 'succeeded' && (
        <form onSubmit={initiatePayment}>
          <label>
            M-Pesa phone
            <input type="tel" required maxLength="32" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="+2547..." />
          </label>
          <button type="submit" disabled={busy}>{busy ? 'Starting…' : 'Request M-Pesa payment'}</button>
        </form>
      )}
      {isBuyer && transaction.payment_status === 'succeeded' && transaction.status !== 'completed' && (
        <>
          <p>Payment is recorded. The application records simulated escrow state; it does not hold or release funds.</p>
          <button type="button" disabled={busy} onClick={confirmDelivery}>Confirm goods received</button>
        </>
      )}
    </article>
  );
}

export default function Dashboard({ user: providedUser }) {
  const [user, setUser] = useState(providedUser || null);
  const [pageError, setPageError] = useState('');
  const [loading, setLoading] = useState(!providedUser);
  const [dataLoading, setDataLoading] = useState(false);
  const [products, setProducts] = useState([]);
  const [bids, setBids] = useState([]);
  const [transactions, setTransactions] = useState([]);
  const [section, setSection] = useState('overview');

  useEffect(() => {
    if (providedUser) {
      setUser(providedUser);
      setLoading(false);
      return undefined;
    }

    let active = true;
    api.get('/me')
      .then(({ data }) => {
        if (active) setUser(data.data);
      })
      .catch(() => {
        if (active) setPageError('Sign in to view your FarmLink dashboard.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [providedUser]);

  const refresh = useCallback(async () => {
    if (!user) return;
    setDataLoading(true);
    setPageError('');
    const requests = [
      api.get('/bids'),
      api.get('/transactions'),
    ];
    if (user.role === 'farmer' || user.role === 'supplier') requests.push(api.get('/my/products'));

    try {
      const results = await Promise.all(requests);
      setBids(results[0].data.data || []);
      setTransactions(results[1].data.data || []);
      setProducts(results[2]?.data.data || []);
    } catch (error) {
      setPageError(messageFrom(error, 'Your dashboard data could not be loaded.'));
    } finally {
      setDataLoading(false);
    }
  }, [user]);

  useEffect(() => {
    if (user) refresh();
  }, [user, refresh]);

  if (loading) return <p role="status">Loading your dashboard…</p>;
  if (!user) return <p role="alert">{pageError || 'Sign in to view your FarmLink dashboard.'}</p>;

  const content = dashboardCopy[user.role];
  if (!content) return <p role="alert">Your account role is not supported.</p>;
  const visibleBids = bids.filter((bid) => bid.buyer_id === user.id || bid.seller_id === user.id);
  const pendingBids = visibleBids.filter((bid) => bid.status === 'pending');

  return (
    <main>
      <h1>{content.title}</h1>
      <p>{content.description}</p>
      {user.kyc_status !== 'verified' && (
        <p role="status">
          Account verification: {user.kyc_status || 'pending'}. Marketplace actions that require verification will remain unavailable until your account is approved.
        </p>
      )}
      {pageError && <p role="alert">{pageError}</p>}
      <nav aria-label="Dashboard sections">
        <button type="button" onClick={() => setSection('overview')}>Overview</button>
        {(user.role === 'farmer' || user.role === 'supplier') && (
          <button type="button" onClick={() => setSection('products')}>My listings ({products.length})</button>
        )}
        <button type="button" onClick={() => setSection('bids')}>Offers ({pendingBids.length} open)</button>
        <button type="button" onClick={() => setSection('orders')}>Orders ({transactions.length})</button>
        <button type="button" onClick={refresh} disabled={dataLoading}>{dataLoading ? 'Refreshing…' : 'Refresh'}</button>
      </nav>

      {section === 'overview' && (
        <>
          <SponsoredAd />
          <PremiumAccount />
          <section aria-label="Dashboard summary">
            <h2>At a glance</h2>
            <ul>
              {(user.role === 'farmer' || user.role === 'supplier') && <li>Your active listings: {products.filter((product) => product.status === 'active').length}</li>}
              <li>Open offers: {pendingBids.length}</li>
              <li>Orders: {transactions.length}</li>
            </ul>
          </section>
          <MarketInsights />
        </>
      )}

      {section === 'products' && (user.role === 'farmer' || user.role === 'supplier') && (
        <section>
          <h2>My listings</h2>
          <ProductForm content={content} onCreated={refresh} />
          {products.length === 0
            ? <p>You have not created any listings yet.</p>
            : products.map((product) => (
              <article key={product.id}>
                <h3>{product.name}</h3>
                <p>{product.status} · {product.quantity_available} {product.unit}</p>
                <p>{formatPrice(product.price_minor, product.currency)} per {product.unit} · {product.county || 'County not specified'}</p>
                <p>{product.description}</p>
              </article>
            ))}
        </section>
      )}

      {section === 'bids' && (
        <section>
          <h2>Offers and negotiation</h2>
          {visibleBids.length === 0 && <p>You have no offers yet. Browse the marketplace to get started.</p>}
          {visibleBids.map((bid) => (
            <BidCard key={bid.id} bid={bid} user={user} refresh={refresh} setPageError={setPageError} />
          ))}
        </section>
      )}

      {section === 'orders' && (
        <section>
          <h2>Orders</h2>
          {transactions.length === 0 && <p>You do not have any orders yet.</p>}
          {transactions.map((transaction) => (
            <TransactionCard key={transaction.id} transaction={transaction} user={user} refresh={refresh} setPageError={setPageError} />
          ))}
          <p>Payment and escrow state displayed here is application-recorded; escrow custody is simulated.</p>
        </section>
      )}
    </main>
  );
}
