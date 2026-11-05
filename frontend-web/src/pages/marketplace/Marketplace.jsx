import { useEffect, useState } from 'react';
import api from '../../services/api';

export default function Marketplace({ user }) {
  const [category, setCategory] = useState('produce');
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState(null);
  const [quantity, setQuantity] = useState('');
  const [price, setPrice] = useState('');
  const [message, setMessage] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    setProducts([]);
    api.get('/products', { params: { category, per_page: 24 } })
      .then(({ data }) => {
        if (active) setProducts(data.data || []);
      })
      .catch(() => {
        if (active) setError('Could not load agricultural inputs. Check your connection and try again.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, [category]);

  async function submitOffer(event) {
    event.preventDefault();
    if (!selected || !Number.isFinite(Number(quantity)) || Number(quantity) <= 0
      || !Number.isInteger(Number(price)) || Number(price) <= 0) {
      setError('Enter a valid quantity and an integer price in minor currency units.');
      return;
    }

    setSubmitting(true);
    setError('');
    try {
      await api.post(`/products/${selected.id}/bids`, {
        quantity: Number(quantity),
        unit_price_minor: Number(price),
        message: message.trim() || undefined,
      }, {
        headers: { 'Idempotency-Key': idempotencyKey },
      });
      setSelected(null);
      setIdempotencyKey('');
      setQuantity('');
      setPrice('');
      setMessage('');
    } catch (requestError) {
      setError(requestError.response?.data?.error?.message
        || requestError.response?.data?.message
        || 'Your offer was not submitted. Review the details and try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main>
      <h1>FarmLink marketplace</h1>
      <nav aria-label="Marketplace categories">
        <button type="button" aria-pressed={category === 'produce'} onClick={() => { setSelected(null); setCategory('produce'); }}>Produce</button>
        <button type="button" aria-pressed={category === 'input'} onClick={() => { setSelected(null); setCategory('input'); }}>Agricultural inputs</button>
      </nav>
      {error && <p role="alert">{error}</p>}
      {loading && <p role="status">Loading listings…</p>}
      {!loading && !error && products.length === 0 && (
        <p>No active {category === 'produce' ? 'produce' : 'input'} listings are available.</p>
      )}

      <section aria-label="Input listings">
        {products.map((product) => (
          <article key={product.id}>
            <h2>{product.name}</h2>
            <p>{category === 'produce' ? product.crop : product.input_type} {product.brand ? `· ${product.brand}` : ''}</p>
            <p>{product.price_minor} {product.currency} minor units / {product.unit}</p>
            <p>{product.quantity_available} {product.unit} available · {product.county || 'Location not specified'}</p>
            {user?.role === 'buyer' && (
              <button
                type="button"
                onClick={() => {
                  setSelected(product);
                  setIdempotencyKey(crypto.randomUUID());
                }}
              >
                Make an offer
              </button>
            )}
          </article>
        ))}
      </section>

      {selected && (
        <div role="dialog" aria-modal="true" aria-labelledby="offer-title">
          <form onSubmit={submitOffer}>
            <h2 id="offer-title">Offer for {selected.name}</h2>
            <label>
              Quantity ({selected.unit})
              <input
                type="number"
                min="0.001"
                max={selected.quantity_available}
                step="0.001"
                required
                value={quantity}
                onChange={(event) => setQuantity(event.target.value)}
              />
            </label>
            <label>
              Your unit price (minor units, {selected.currency})
              <input
                type="number"
                min="1"
                step="1"
                required
                value={price}
                onChange={(event) => setPrice(event.target.value)}
              />
            </label>
            <label>
              Message (optional)
              <textarea maxLength="1000" value={message} onChange={(event) => setMessage(event.target.value)} />
            </label>
            <button type="submit" disabled={submitting}>
              {submitting ? 'Submitting…' : 'Submit offer'}
            </button>
            <button
              type="button"
              disabled={submitting}
              onClick={() => {
                setSelected(null);
                setIdempotencyKey('');
              }}
            >
              Cancel
            </button>
          </form>
        </div>
      )}
    </main>
  );
}
