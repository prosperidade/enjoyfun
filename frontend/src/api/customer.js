/**
 * EnjoyFun 2.0 — Customer API Service
 * Funções de consumo de dados do Portal do Cliente Final (Cashless / OTP)
 */
import api from '../lib/api';

/**
 * Returns hybrid balance split: { global_balance, event_balance, total_balance }
 * @param {number} organizerId  - current event's organizer (pass MOCK_ORGANIZER_ID from app)
 */
export async function getCustomerBalanceApi(organizerId = 0) {
  const { data } = await api.get('/customer/balance', { params: { organizer_id: organizerId } });
  return data.data;
}

/**
 * Retorna o extrato de transações do cliente logado.
 * @returns {Promise<Array>}
 */
export async function getCustomerTransactionsApi() {
  const { data } = await api.get('/customer/transactions');
  return data.data;
}

export async function getMyTicketsApi() {
  const { data } = await api.get('/customer/tickets');
  return data.data ?? [];
}
