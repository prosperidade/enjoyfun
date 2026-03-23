import axios from 'axios';
import { clearSession, getAccessToken, getSessionSnapshot, persistSession } from './session';

// Reads VITE_API_URL from frontend/.env
// Falls back to /api (Vite proxy) when running `npm run dev` without .env
const BASE_URL = import.meta.env.VITE_API_URL || '/api';

const api = axios.create({
  baseURL: BASE_URL,
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true,
});

let refreshRequest = null;
let cachedDeviceId = null;

function createDeviceId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `device-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function getDeviceId() {
  if (cachedDeviceId) {
    return cachedDeviceId;
  }

  if (typeof window === 'undefined') {
    cachedDeviceId = 'server-side';
    return cachedDeviceId;
  }

  const storageKey = 'enjoyfun_device_id';
  const existing = window.localStorage?.getItem(storageKey);
  if (existing) {
    cachedDeviceId = existing;
    return cachedDeviceId;
  }

  cachedDeviceId = createDeviceId();
  window.localStorage?.setItem(storageKey, cachedDeviceId);
  return cachedDeviceId;
}

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
  config.headers['X-Device-ID'] = getDeviceId();
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

      const { refreshToken, refreshTransport } = getSessionSnapshot();
      if (!refreshToken && refreshTransport !== 'cookie') {
        clearSession();
        redirectToLogin();
        return Promise.reject(error);
      }

      refreshRequest ||= axios
        .post(
          `${BASE_URL}/auth/refresh`,
          refreshToken ? { refresh_token: refreshToken } : {},
          {
            withCredentials: true,
            headers: {
              'Content-Type': 'application/json',
              'X-Device-ID': getDeviceId(),
            },
          }
        )
        .then(({ data }) => {
          const result = data?.data;
          if (!result || (!result.access_token && result.access_transport !== 'cookie')) {
            throw new Error('Refresh inválido.');
          }
          persistSession(result);
          return result.access_transport === 'cookie' ? '' : result.access_token;
        })
        .finally(() => {
          refreshRequest = null;
        });

      try {
        const nextAccessToken = await refreshRequest;
        if (nextAccessToken) {
          original.headers.Authorization = `Bearer ${nextAccessToken}`;
        } else if (original.headers?.Authorization) {
          delete original.headers.Authorization;
        }
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
