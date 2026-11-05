import { useEffect, useState } from 'react';
import api from '../../services/api';

export default function SponsoredAd() {
  const [ad, setAd] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    api.get('/ads')
      .then(({ data }) => {
        if (active && data.data) {
          setAd(data.data);
          api.post(`/ads/${data.data.id}/events/impression`)
            .catch(() => {
              if (active) setError('Ad impression tracking is temporarily unavailable.');
            });
        }
      })
      .catch(() => {
        if (active) setError('Sponsored placements are temporarily unavailable.');
      });

    return () => { active = false; };
  }, []);

  async function openAdvertiser(event) {
    event.preventDefault();
    try {
      await api.post(`/ads/${ad.id}/events/click`);
    } catch {
      setError('Click tracking failed; opening the advertiser link anyway.');
    }
    window.location.assign(ad.destination_url);
  }

  if (!ad) return error ? <p role="status">{error}</p> : null;

  return (
    <aside aria-label="Sponsored advertisement">
      <p><strong>{ad.label}</strong></p>
      <a href={ad.destination_url} onClick={openAdvertiser} rel="sponsored nofollow">
        <img src={ad.creative_url} alt={ad.name} loading="lazy" style={{ maxWidth: '100%', height: 'auto' }} />
      </a>
      {error && <p role="status">{error}</p>}
    </aside>
  );
}
