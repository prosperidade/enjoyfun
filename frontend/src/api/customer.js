/**
 * EnjoyFun 2.0 — Customer API Service
 * Funções de consumo de dados do Portal do Cliente Final (Cashless / OTP)
 */
import api from '../lib/api';
import publicApi from '../lib/publicApi';

/**
 * Resolve public event context from slug or event_id.
 */
export async function getCustomerEventContextApi({ eventId = null, slug = '' } = {}) {
  const params = {};
  if (Number(eventId) > 0) params.event_id = Number(eventId);
  if (String(slug || '').trim()) params.slug = String(slug).trim();
  const { data } = await publicApi.get('/customer/context', { params });
  return data.data;
}

/**
 * Returns event-scoped balance split: { global_balance, event_balance, total_balance }
 */
export async function getCustomerBalanceApi({ eventId = null, eventSlug = '' } = {}) {
  const params = {};
  if (Number(eventId) > 0) params.event_id = Number(eventId);
  if (String(eventSlug || '').trim()) params.event_slug = String(eventSlug).trim();
  const { data } = await api.get('/customer/balance', { params });
  return data.data;
}

/**
 * Retorna o extrato de transações do cliente logado.
 * @returns {Promise<Array>}
 */
export async function getCustomerTransactionsApi({ eventId = null, eventSlug = '' } = {}) {
  const params = {};
  if (Number(eventId) > 0) params.event_id = Number(eventId);
  if (String(eventSlug || '').trim()) params.event_slug = String(eventSlug).trim();
  const { data } = await api.get('/customer/transactions', { params });
  return data.data;
}

export async function getMyTicketsApi({ eventId = null, eventSlug = '' } = {}) {
  const params = {};
  if (Number(eventId) > 0) params.event_id = Number(eventId);
  if (String(eventSlug || '').trim()) params.event_slug = String(eventSlug).trim();
  const { data } = await api.get('/customer/tickets', { params });
  return data.data ?? [];
}
