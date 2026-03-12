import axios from 'axios';
import { clearSession, getAccessToken, getRefreshToken, persistSession } from './session';

// Reads VITE_API_URL from frontend/.env
// Falls back to /api (Vite proxy) when running `npm run dev` without .env
const BASE_URL = import.meta.env.VITE_API_URL || '/api';

const api = axios.create({
  baseURL: BASE_URL,
  headers: { 'Content-Type': 'application/json' },
});

let refreshRequest = null;

function redirectToLogin() {
  const path = window.location.pathname || '';
  if (path.startsWith('/app/')) {
    const segments = path.split('/').filter(Boolean);
    const customerEntry = segments[1] ? `/app/${segments[1]}` : '/';
    window.location.assign(customerEntry);
    return;
  }

  window.location.assign('/login');
}

// Attach JWT automatically
api.interceptors.request.use((config) => {
  const token = getAccessToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Auto-refresh on 401
api.interceptors.response.use(
  (res) => res,
  async (error) => {
    const original = error.config;
    const isRefreshRequest = original?.url?.includes('/auth/refresh');

    if (error.response?.status === 401 && !original?._retry && !isRefreshRequest) {
      original._retry = true;

      const refresh = getRefreshToken();
      if (!refresh) {
        clearSession();
        redirectToLogin();
        return Promise.reject(error);
      }

      refreshRequest ||= axios
        .post(`${BASE_URL}/auth/refresh`, { refresh_token: refresh })
        .then(({ data }) => {
          const result = data?.data;
          if (!result?.access_token) {
            throw new Error('Refresh inválido.');
          }
          persistSession(result);
          return result.access_token;
        })
        .finally(() => {
          refreshRequest = null;
        });

      try {
        const nextAccessToken = await refreshRequest;
        original.headers.Authorization = `Bearer ${nextAccessToken}`;
        return api(original);
      } catch (refreshError) {
        clearSession();
        redirectToLogin();
        return Promise.reject(refreshError);
      }
    }
    return Promise.reject(error);
  }
);

export default api;
