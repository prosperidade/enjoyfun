import { apiClient } from './client';
import type { LoginResponse, LoginData } from '@/lib/types';
import { saveToken, saveRefreshToken, saveUser, clearAuth } from '@/lib/auth';

export async function login(email: string, password: string): Promise<LoginData> {
  const { data: body } = await apiClient.post<LoginResponse>('/auth/login', { email, password });
  const data = body?.data;
  if (!data?.access_token) {
    throw new Error('Resposta do servidor sem access_token');
  }
  await saveToken(data.access_token);
  if (data.refresh_token) await saveRefreshToken(data.refresh_token);
  if (data.user) await saveUser(data.user);
  return data;
}

export async function logout(): Promise<void> {
  try {
    await apiClient.post('/auth/logout').catch(() => undefined);
  } finally {
    await clearAuth();
  }
}

export async function refresh(): Promise<LoginData | null> {
  try {
    const { data: body } = await apiClient.post<LoginResponse>('/auth/refresh');
    const data = body?.data;
    if (data?.access_token) {
      await saveToken(data.access_token);
      if (data.refresh_token) await saveRefreshToken(data.refresh_token);
    }
    return data ?? null;
  } catch {
    return null;
  }
}
