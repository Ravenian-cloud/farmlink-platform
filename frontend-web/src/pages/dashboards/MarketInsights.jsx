import { useEffect, useState } from 'react';
import api from '../../services/api';

function kes(amountMinor) {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'KES',
  }).format(amountMinor / 100);
}

export default function MarketInsights() {
  const [snapshots, setSnapshots] = useState([]);
  const [advancedSnapshots, setAdvancedSnapshots] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    Promise.all([api.get('/analytics/market'), api.get('/subscription')])
      .then(async ([{ data: market }, { data: account }]) => {
        if (!active) return;
        setSnapshots(market.data);
        if (account.data.status === 'active') {
          const { data: advanced } = await api.get('/analytics/market/advanced');
          if (active) setAdvancedSnapshots(advanced.data);
        }
      })
      .catch(() => {
        if (active) setError('Market insights are temporarily unavailable.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, []);

  return (
    <section aria-labelledby="market-insights-title">
      <h2 id="market-insights-title">Market snapshots</h2>
      <p>Historical completed-trade baselines; not a price guarantee or AI forecast.</p>
      {loading && <p role="status">Loading market snapshots…</p>}
      {error && <p role="alert">{error}</p>}
      {!loading && !error && snapshots.length === 0 && (
        <p>There is not enough recent regional trading data to show an insight yet.</p>
      )}
      {!loading && !error && snapshots.length > 0 && (
        <ul>
          {snapshots.map(({ dimensions, metrics, computed_at: computedAt }) => (
            <li key={`${dimensions.county}-${dimensions.crop}-${dimensions.unit}-${dimensions.currency}`}>
              <h3>{dimensions.crop} · {dimensions.county}</h3>
              <p>
                Median completed-trade price: {kes(metrics.price_median_minor)} per {dimensions.unit}
                {' '}({metrics.sample_size} recent trades)
              </p>
              <p>
                Recent orders: {metrics.completed_orders_30d} in 30 days.
                {metrics.demand_change_percent === null
                  ? ' No prior-period comparison is available.'
                  : ` Change from prior 30 days: ${metrics.demand_change_percent}%.`}
              </p>
              <small>Updated {new Date(computedAt).toLocaleString()}</small>
            </li>
          ))}
        </ul>
      )}
      {advancedSnapshots.length > 0 && (
        <section aria-label="Premium price spread analysis">
          <h3>Premium price spread analysis</h3>
          <ul>
            {advancedSnapshots.map(({ dimensions, price_interquartile_range_minor: spread, sample_size: sampleSize }) => (
              <li key={`${dimensions.county}-${dimensions.crop}-${dimensions.unit}`}>
                {dimensions.crop} · {dimensions.county}: middle-50% price spread {kes(spread)} per {dimensions.unit}
                {' '}from {sampleSize} completed trades.
              </li>
            ))}
          </ul>
        </section>
      )}
    </section>
  );
}
