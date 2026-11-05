import { useState } from 'react';
import api from '../../services/api';

export default function PilotFeedbackForm() {
  const [category, setCategory] = useState('general');
  const [rating, setRating] = useState(5);
  const [negotiationRating, setNegotiationRating] = useState(4);
  const [recommendationRating, setRecommendationRating] = useState(4);
  const [summary, setSummary] = useState('');
  const [details, setDetails] = useState('');
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [idempotencyKey, setIdempotencyKey] = useState('');

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setStatus('');
    setError('');

    try {
      const requestKey = idempotencyKey || crypto.randomUUID();
      setIdempotencyKey(requestKey);
      await api.post('/pilot-feedback', {
        category,
        rating,
        ...(category === 'negotiation' ? { negotiation_rating: negotiationRating } : {}),
        ...(category === 'recommendation' ? { recommendation_accuracy_rating: recommendationRating } : {}),
        summary: summary.trim() || undefined,
        details: details.trim(),
      }, {
        headers: { 'Idempotency-Key': requestKey },
      });
      setStatus('Thank you. Your pilot feedback has been recorded.');
      setIdempotencyKey('');
      setSummary('');
      setDetails('');
    } catch (requestError) {
      setError(requestError.response?.data?.error?.message
        || requestError.response?.data?.message
        || 'Feedback could not be submitted. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main>
      <h1>Pilot feedback</h1>
      <p>Help us improve FarmLink. Do not include passwords, payment details, or sensitive personal information.</p>
      {status && <p role="status">{status}</p>}
      {error && <p role="alert">{error}</p>}
      <form onSubmit={submit}>
        <label>
          Feedback type
          <select value={category} onChange={(event) => { setCategory(event.target.value); setIdempotencyKey(''); }}>
            <option value="bug">Report a bug</option>
            <option value="negotiation">Negotiation experience</option>
            <option value="recommendation">Crop/input recommendation</option>
            <option value="general">General feedback</option>
          </select>
        </label>

        <label>
          Overall rating (1 to 5)
          <select value={rating} onChange={(event) => { setRating(Number(event.target.value)); setIdempotencyKey(''); }}>
            {[1, 2, 3, 4, 5].map((value) => <option key={value} value={value}>{value}</option>)}
          </select>
        </label>

        {category === 'negotiation' && (
          <label>
            Negotiation system rating (1 to 5)
            <select value={negotiationRating} onChange={(event) => { setNegotiationRating(Number(event.target.value)); setIdempotencyKey(''); }}>
              {[1, 2, 3, 4, 5].map((value) => <option key={value} value={value}>{value}</option>)}
            </select>
          </label>
        )}

        {category === 'recommendation' && (
          <label>
            Recommendation accuracy (1 to 5)
            <select value={recommendationRating} onChange={(event) => { setRecommendationRating(Number(event.target.value)); setIdempotencyKey(''); }}>
              {[1, 2, 3, 4, 5].map((value) => <option key={value} value={value}>{value}</option>)}
            </select>
          </label>
        )}

        <label>
          Short summary (optional)
          <input maxLength="160" value={summary} onChange={(event) => { setSummary(event.target.value); setIdempotencyKey(''); }} />
        </label>
        <label>
          Tell us more
          <textarea required minLength="10" maxLength="5000" value={details} onChange={(event) => { setDetails(event.target.value); setIdempotencyKey(''); }} />
        </label>
        <button type="submit" disabled={submitting}>
          {submitting ? 'Sending…' : 'Send feedback'}
        </button>
      </form>
    </main>
  );
}
