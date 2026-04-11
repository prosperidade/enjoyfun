import axios, { AxiosError, InternalAxiosRequestConfig } from 'axios';
import { getToken, clearAuth } from '@/lib/auth';

const DEFAULT_BASE = 'http://localhost:8080/api';

export const apiClient = axios.create({
  baseURL: process.env.EXPO_PUBLIC_API_URL ?? DEFAULT_BASE,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Client': 'mobile',
  },
});

// Hook set by navigation root so 401s can force logout.
let onUnauthorized: (() => void) | null = null;
export function setUnauthorizedHandler(fn: (() => void) | null): void {
  onUnauthorized = fn;
}

apiClient.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const token = await getToken();
  if (token) {
    config.headers = config.headers ?? {};
    (config.headers as Record<string, string>).Authorization = `Bearer ${token}`;
  }
  return config;
});

apiClient.interceptors.response.use(
  (res) => res,
  async (error: AxiosError) => {
    if (error.response?.status === 401) {
      await clearAuth();
      if (onUnauthorized) onUnauthorized();
    }
    return Promise.reject(error);
  },
);
