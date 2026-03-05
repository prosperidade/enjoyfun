/**
 * EnjoyFun 2.0 — Auth API Service
 *
 * All functions return the full Axios response data.
 * Token persistence is handled by AuthContext (localStorage).
 *
 * Usage:
 *   import { loginApi, registerApi, refreshApi, logoutApi, meApi } from './auth';
 */

import api from '../lib/api';

// ── Login ──────────────────────────────────────────────────────────────────
/**
 * @param {string} email
 * @param {string} password
 * @returns {Promise<{user, access_token, refresh_token, expires_in}>}
 */
export async function loginApi(email, password) {
  const response = await api.post('/auth/login', { email, password });
  console.log('>>> RAW AXIOS RESPONSE.DATA:', response.data);
  return response.data?.data;  // { user, access_token, refresh_token, expires_in }
}

// ── Register ───────────────────────────────────────────────────────────────
/**
 * @param {{ name, email, password, phone, cpf }} payload
 * @returns {Promise<{user, access_token, refresh_token, expires_in}>}
 */
export async function registerApi({ name, email, password, phone = '', cpf = '' }) {
  const { data } = await api.post('/auth/register', { name, email, password, phone, cpf, account_type: 'organizer' });
  return data.data;
}

// ── Passwordless OTP ───────────────────────────────────────────────────────
/**
 * @param {string} identifier  - e-mail ou número WhatsApp
 * @param {number} organizer_id
 */
export async function requestCodeApi(identifier, organizer_id) {
  const { data } = await api.post('/auth/request-code', { identifier, organizer_id });
  return data;
}

/**
 * @param {string} identifier
 * @param {string} code        - 6-digit OTP
 * @param {number} organizer_id
 * @returns {Promise<{user, access_token, refresh_token, expires_in}>}
 */
export async function verifyCodeApi(identifier, code, organizer_id) {
  const { data } = await api.post('/auth/verify-code', { identifier, code, organizer_id });
  return data.data;
}

// ── Refresh token ──────────────────────────────────────────────────────────
/**
 * @param {string} refreshToken
 * @returns {Promise<{user, access_token, refresh_token, expires_in}>}
 */
export async function refreshApi(refreshToken) {
  const { data } = await api.post('/auth/refresh', { refresh_token: refreshToken });
  return data.data;
}

// ── Logout ─────────────────────────────────────────────────────────────────
/**
 * @param {string} refreshToken  – pass so the server can invalidate it
 */
export async function logoutApi(refreshToken) {
  await api.post('/auth/logout', { refresh_token: refreshToken }).catch(() => {});
}

// ── Current user ───────────────────────────────────────────────────────────
/**
 * @returns {Promise<User>}  – requires a valid Bearer token in localStorage
 */
export async function meApi() {
  const { data } = await api.get('/auth/me');
  return data.data;
}
