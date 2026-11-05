import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1',
  headers: { Accept: 'application/json' },
  timeout: 15000,
});

api.interceptors.request.use((config) => {
  const token = window.sessionStorage.getItem('farmlink_access_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  if (['post', 'put', 'patch', 'delete'].includes(config.method?.toLowerCase())
    && !config.headers['Idempotency-Key']) {
    config.headers['Idempotency-Key'] = window.crypto.randomUUID();
  }
  return config;
});

export default api;
