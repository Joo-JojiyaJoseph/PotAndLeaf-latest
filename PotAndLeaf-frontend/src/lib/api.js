import axios from 'axios';

// Token + active company live here so the axios interceptors and the auth
// context share one source of truth. Persisted so a refresh keeps you signed in.
let authToken = localStorage.getItem('pl_token') || null;
let companyId = localStorage.getItem('pl_company') || null;

export function setAuthToken(token) {
  authToken = token;
  token ? localStorage.setItem('pl_token', token) : localStorage.removeItem('pl_token');
}

export function setCompanyId(id) {
  companyId = id;
  id ? localStorage.setItem('pl_company', id) : localStorage.removeItem('pl_company');
}

export function getAuthToken() { return authToken; }
export function getCompanyId() { return companyId; }

/** Override X-Company-Id for a single request without changing the workspace company. */
export function withCompany(id, config = {}) {
  if (!id) return config;
  return {
    ...config,
    headers: { ...(config.headers || {}), 'X-Company-Id': String(id) },
  };
}

const api = axios.create({
  // Dev uses the Vite proxy (relative /api). A production build has no proxy,
  // so point it at the deployed backend via VITE_API_URL at build time, e.g.
  // VITE_API_URL=https://test-potandleaf-backend.webfolks.in/api
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
  if (authToken) config.headers.Authorization = `Bearer ${authToken}`;
  // Respect per-request company override (withCompany) — do not clobber it.
  if (companyId && !config.headers['X-Company-Id']) {
    config.headers['X-Company-Id'] = companyId;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      setAuthToken(null);
      if (window.location.pathname !== '/login') window.location.href = '/login';
    }
    return Promise.reject(error);
  },
);

export default api;
