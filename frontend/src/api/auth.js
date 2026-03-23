/**
 * EnjoyFun 2.0 — Auth API Service
 *
 * All functions return the full Axios response data.
 * Token persistence is handled by the frontend session manager.
 *
 * Usage:
 *   import { loginApi, registerApi, refreshApi, logoutApi, meApi } from './auth';
 */

import api from '../lib/api';

// ── Login ──────────────────────────────────────────────────────────────────
/**
 * @param {string} email
 * @param {string} password
 * @returns {Promise<{user, access_token, access_transport, refresh_token, refresh_transport, expires_in}>}
 */
export async function loginApi(email, password) {
  const response = await api.post('/auth/login', { email, password });
  return response.data?.data;
}

// ── Register ───────────────────────────────────────────────────────────────
/**
 * @param {{ name, email, password, phone, cpf }} payload
 * @returns {Promise<{user, access_token, access_transport, refresh_token, refresh_transport, expires_in}>}
 */
export async function registerApi({ name, email, password, phone = '', cpf = '' }) {
  const { data } = await api.post('/auth/register', { name, email, password, phone, cpf, account_type: 'organizer' });
  return data.data;
}

// ── Passwordless OTP ───────────────────────────────────────────────────────
/**
 * @param {string} identifier  - e-mail ou número WhatsApp
 * @param {object} scope
 */
export async function requestCodeApi(identifier, scope = {}) {
  const { data } = await api.post('/auth/request-code', { identifier, ...scope });
  return data;
}

/**
 * @param {string} identifier
 * @param {string} code        - 6-digit OTP
 * @param {object} scope
 * @returns {Promise<{user, access_token, access_transport, refresh_token, refresh_transport, expires_in}>}
 */
export async function verifyCodeApi(identifier, code, scope = {}) {
  const { data } = await api.post('/auth/verify-code', { identifier, code, ...scope });
  return data.data;
}

// ── Refresh token ──────────────────────────────────────────────────────────
/**
 * @param {string} refreshToken
 * @returns {Promise<{user, access_token, access_transport, refresh_token, refresh_transport, expires_in}>}
 */
export async function refreshApi(refreshToken = '') {
  const payload = refreshToken ? { refresh_token: refreshToken } : {};
  const { data } = await api.post('/auth/refresh', payload);
  return data.data;
}

// ── Logout ─────────────────────────────────────────────────────────────────
/**
 * @param {string} refreshToken  – pass so the server can invalidate it
 */
export async function logoutApi(refreshToken) {
  const payload = refreshToken ? { refresh_token: refreshToken } : {};
  await api.post('/auth/logout', payload).catch(() => {});
}

// ── Current user ───────────────────────────────────────────────────────────
/**
 * @returns {Promise<User>}  – requires a valid Bearer token or access cookie in the current session
 */
export async function meApi() {
  const { data } = await api.get('/auth/me');
  return data.data;
}
