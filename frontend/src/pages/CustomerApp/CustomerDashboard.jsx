import { useState, useEffect, useReducer } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { PlusCircle, QrCode, History, LogOut, Wallet, Ticket, MapPin, CalendarDays, Building2, X, Sun } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import toast from 'react-hot-toast';
import { getCustomerBalanceApi, getCustomerTransactionsApi, getMyTicketsApi } from '../../api/customer';
import { useCustomerEventContext } from '../../hooks/useCustomerEventContext';
import { logoutApi } from '../../api/auth';
import { clearSession, getRefreshToken, getStoredUser } from '../../lib/session';

const EMPTY_BALANCE = { global_balance: 0, event_balance: 0, total_balance: 0, event_name: '' };
const EMPTY_LIST = [];

function asyncResourceReducer(state, action) {
  switch (action.type) {
    case 'idle':
      return {
        data: action.payload,
        loading: action.loading ?? false,
      };
    case 'loading':
      return {
        data: action.payload ?? state.data,
        loading: true,
      };
    case 'success':
      return {
        data: action.payload,
        loading: false,
      };
    default:
      return state;
  }
}

export default function CustomerDashboard() {
  const { slug }   = useParams();
  const navigate   = useNavigate();
  const { eventContext, eventError, eventLoading } = useCustomerEventContext(slug);

  const user = getStoredUser() || {};

  const handleLogout = async () => {
    await logoutApi(getRefreshToken());
    clearSession();
    toast.success('Saiu com sucesso.');
    navigate(`/app/${slug}`, { replace: true });
  };

  const [balanceState, dispatchBalance] = useReducer(asyncResourceReducer, {
    data: EMPTY_BALANCE,
    loading: true,
  });
  const [ticketsState, dispatchTickets] = useReducer(asyncResourceReducer, {
    data: EMPTY_LIST,
    loading: true,
  });
  const [transactionsState, dispatchTransactions] = useReducer(asyncResourceReducer, {
    data: EMPTY_LIST,
    loading: true,
  });

  useEffect(() => {
    if (!eventContext?.id) {
      dispatchBalance({ type: 'idle', payload: EMPTY_BALANCE, loading: eventLoading });
      return;
    }

    dispatchBalance({ type: 'loading', payload: EMPTY_BALANCE });
    getCustomerBalanceApi({ eventId: Number(eventContext.id) })
      .then((res) => dispatchBalance({ type: 'success', payload: res || EMPTY_BALANCE }))
      .catch(() => dispatchBalance({ type: 'success', payload: EMPTY_BALANCE }));
  }, [eventContext?.id, eventLoading]);

  const [selectedTicket, setSelectedTicket] = useState(null);

  useEffect(() => {
    if (!eventContext?.id) {
      dispatchTickets({ type: 'idle', payload: EMPTY_LIST, loading: eventLoading });
      return;
    }

    dispatchTickets({ type: 'loading', payload: EMPTY_LIST });
    getMyTicketsApi({ eventId: Number(eventContext.id) })
      .then((data) => dispatchTickets({ type: 'success', payload: data || EMPTY_LIST }))
      .catch(() => dispatchTickets({ type: 'success', payload: EMPTY_LIST }));
  }, [eventContext?.id, eventLoading]);

  useEffect(() => {
    if (!eventContext?.id) {
      dispatchTransactions({ type: 'idle', payload: EMPTY_LIST, loading: eventLoading });
      return;
    }

    dispatchTransactions({ type: 'loading', payload: EMPTY_LIST });
    getCustomerTransactionsApi({ eventId: Number(eventContext.id) })
      .then((data) => dispatchTransactions({ type: 'success', payload: data || EMPTY_LIST }))
      .catch(() => dispatchTransactions({ type: 'success', payload: EMPTY_LIST }));
  }, [eventContext?.id, eventLoading]);

  const balanceData = balanceState.data;
  const balanceLoading = balanceState.loading;
  const tickets = ticketsState.data;
  const ticketsLoading = ticketsState.loading;
  const transactions = transactionsState.data;
  const transactionsLoading = transactionsState.loading;

  const formatCurrency = (value) =>
    Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

  const formatDateTime = (value) => {
    if (!value) return '';
    return new Date(value).toLocaleString('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const scrollToTransactions = () => {
    document.getElementById('customer-transactions')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const actions = [
    { label: 'Carregar Saldo', icon: PlusCircle, color: 'text-green-400', bg: 'bg-green-500/10 border-green-500/20',  onClick: () => navigate(`/app/${slug}/recharge`) },
    { label: 'Meu QR Code',   icon: QrCode,      color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', onClick: () => toast('Em breve: QR Code 📱') },
    { label: 'Extrato',       icon: History,     color: 'text-blue-400',   bg: 'bg-blue-500/10 border-blue-500/20',    onClick: scrollToTransactions },
  ];

  return (
    <>
    <div
      className="min-h-screen bg-gray-950 flex flex-col"
      style={{ background: 'radial-gradient(ellipse at top, rgba(124,58,237,0.12) 0%, #030712 60%)' }}
    >
      <div className="flex-1 flex flex-col max-w-md mx-auto w-full px-4 py-6 space-y-5">

        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs text-gray-500 uppercase tracking-widest">Evento</p>
            <h1 className="text-lg font-bold text-white capitalize">{eventContext?.name || slug?.replace(/-/g, ' ')}</h1>
          </div>
          <button
            onClick={handleLogout}
            className="flex items-center gap-1.5 text-xs text-gray-400 hover:text-red-400 transition-colors px-3 py-2 rounded-xl border border-gray-800 hover:border-red-800/50"
          >
            <LogOut size={14} /> Sair
          </button>
        </div>

        {/* Greeting */}
        <p className="text-gray-400 text-sm">
          Olá, <span className="text-white font-semibold">{user.name || 'Cliente'}</span> 👋
        </p>

        {eventError ? (
          <div className="bg-red-500/10 border border-red-500/20 rounded-2xl p-4">
            <p className="text-red-300 text-sm font-medium">{eventError}</p>
          </div>
        ) : null}

        {/* Saldo Card */}
        <div
          className="rounded-2xl p-6 text-white relative overflow-hidden"
          style={{
            background: 'linear-gradient(135deg, #7c3aed 0%, #2563eb 100%)',
            boxShadow: '0 20px 60px rgba(124,58,237,0.35)',
          }}
        >
          {/* Decorative circles */}
          <div className="absolute -top-8 -right-8 w-36 h-36 bg-white/5 rounded-full" />
          <div className="absolute -bottom-10 -left-6 w-28 h-28 bg-white/5 rounded-full" />

          <div className="relative">
            <div className="flex items-center gap-2 mb-2">
              <Wallet size={16} className="text-white/70" />
              <p className="text-xs text-white/70 font-medium uppercase tracking-wider">Saldo do Evento</p>
            </div>

            {balanceLoading ? (
              <div className="h-10 w-36 mt-1 bg-white/20 rounded-lg animate-pulse" />
            ) : (
              <p className="text-4xl font-extrabold tracking-tight">
                {formatCurrency(balanceData.total_balance)}
              </p>
            )}

            {!balanceLoading && (
              <div className="flex gap-3 mt-3">
                <div className="flex items-center gap-1.5 bg-white/10 rounded-xl px-2.5 py-1.5">
                  <Building2 size={12} className="text-white/60" />
                  <div>
                    <p className="text-[9px] text-white/50 uppercase tracking-wider">{balanceData.event_name || 'Este Evento'}</p>
                    <p className="text-xs font-bold text-white">
                      {formatCurrency(balanceData.event_balance)}
                    </p>
                  </div>
                </div>
              </div>
            )}

            <p className="text-xs text-white/50 mt-2 font-mono truncate">
              {user.email || user.phone || '\u2014'}
            </p>
          </div>
        </div>

        {/* Quick Actions */}
        <div>
          <p className="text-xs text-gray-500 uppercase tracking-widest mb-3">Ações rápidas</p>
          <div className="grid grid-cols-3 gap-3">
            {actions.map((action) => {
              const IconComponent = action.icon;

              return (
                <button
                  key={action.label}
                  onClick={action.onClick}
                  className={`flex flex-col items-center gap-2 py-4 px-2 rounded-2xl border ${action.bg} transition-all active:scale-95`}
                >
                  <div className={`w-10 h-10 rounded-xl flex items-center justify-center ${action.bg} border`}>
                    <IconComponent size={20} className={action.color} />
                  </div>
                  <span className="text-xs text-gray-300 font-medium text-center leading-tight">{action.label}</span>
                </button>
              );
            })}
          </div>
        </div>

        {/* Meus Ingressos */}
        <div>
          <p className="text-xs text-gray-500 uppercase tracking-widest mb-3">Meus Ingressos</p>

          {ticketsLoading ? (
            <div className="flex gap-3 overflow-hidden">
              {[1, 2].map(i => (
                <div key={i} className="min-w-[200px] h-28 bg-gray-800/50 rounded-2xl animate-pulse" />
              ))}
            </div>
          ) : tickets.length === 0 ? (
            <div className="bg-gray-900/50 border border-gray-800 border-dashed rounded-2xl p-6 flex flex-col items-center text-center gap-2">
              <Ticket size={24} className="text-gray-700" />
              <p className="text-gray-500 text-sm">Nenhum ingresso encontrado.</p>
              <p className="text-gray-600 text-xs">Compre ingressos para seus eventos favoritos e eles aparecerão aqui!</p>
            </div>
          ) : (
            <div className="flex gap-3 overflow-x-auto pb-2 -mx-4 px-4 snap-x snap-mandatory scrollbar-hide">
              {tickets.map(t => (
                <div
                  key={t.id}
                  onClick={() => t.status !== 'cancelled' && setSelectedTicket(t)}
                  className={`min-w-[220px] bg-gray-900/80 border rounded-2xl p-4 snap-start flex-shrink-0 space-y-2 transition-all active:scale-[0.97] ${
                    t.status === 'cancelled'
                      ? 'border-gray-800 opacity-60 cursor-not-allowed'
                      : 'border-gray-800 hover:border-purple-500/40 cursor-pointer'
                  }`}
                >
                  <div className="flex items-start justify-between gap-2">
                    <p className="text-white font-semibold text-sm leading-tight line-clamp-2">{t.event_name}</p>
                    <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 ${
                      t.status === 'used'      ? 'bg-gray-700 text-gray-400' :
                      t.status === 'cancelled' ? 'bg-red-500/15 text-red-400' :
                                                 'bg-green-500/15 text-green-400'
                    }`}>
                      {t.status === 'used' ? 'Usado' : t.status === 'cancelled' ? 'Cancelado' : 'Válido'}
                    </span>
                  </div>
                  {t.ticket_type && (
                    <p className="text-xs text-purple-400 font-medium">{t.ticket_type}</p>
                  )}
                  {t.event_date && (
                    <div className="flex items-center gap-1 text-gray-500 text-xs">
                      <CalendarDays size={11} />
                      {new Date(t.event_date).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })}
                    </div>
                  )}
                  {t.event_location && (
                    <div className="flex items-center gap-1 text-gray-500 text-xs">
                      <MapPin size={11} />
                      <span className="truncate">{t.event_location}</span>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>

        <div id="customer-transactions" className="flex-1 bg-gray-900/50 border border-gray-800 rounded-2xl p-5">
          <p className="text-xs text-gray-500 uppercase tracking-widest mb-4">Últimas transações</p>
          {transactionsLoading ? (
            <div className="space-y-3">
              {[1, 2, 3].map((item) => (
                <div key={item} className="h-14 bg-gray-800/60 rounded-2xl animate-pulse" />
              ))}
            </div>
          ) : transactions.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <History size={28} className="text-gray-700 mb-2" />
              <p className="text-gray-600 text-sm">Nenhuma transação neste evento</p>
            </div>
          ) : (
            <div className="space-y-3">
              {transactions.map((tx) => {
                const isCredit = tx.type === 'credit';
                const amountClass = isCredit ? 'text-emerald-400' : 'text-red-400';
                const badgeClass = isCredit
                  ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'
                  : 'bg-red-500/10 text-red-300 border-red-500/20';

                return (
                  <div
                    key={tx.id}
                    className="rounded-2xl border border-gray-800 bg-gray-950/60 px-4 py-3 flex items-center justify-between gap-3"
                  >
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <span className={`text-[10px] font-bold uppercase tracking-wider border rounded-full px-2 py-0.5 ${badgeClass}`}>
                          {isCredit ? 'Crédito' : 'Débito'}
                        </span>
                        <span className="text-[11px] text-gray-500">{formatDateTime(tx.created_at)}</span>
                      </div>
                      <p className="text-sm text-white font-medium truncate">
                        {tx.description || (isCredit ? 'Recarga' : 'Consumo')}
                      </p>
                    </div>
                    <p className={`text-sm font-bold whitespace-nowrap ${amountClass}`}>
                      {isCredit ? '+' : '-'}
                      {formatCurrency(Math.abs(Number(tx.amount || 0)))}
                    </p>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Footer */}
        <p className="text-xs text-gray-700 text-center pb-2">
          © {new Date().getFullYear()} EnjoyFun · Plataforma de Eventos
        </p>
      </div>
    </div>

    {/* ─── Ticket Modal (slide-up) ─────────────────────────────── */}
    {selectedTicket && (
      <div
        className="fixed inset-0 z-50 flex items-end justify-center"
        style={{ background: 'rgba(0,0,0,0.75)', backdropFilter: 'blur(6px)' }}
        onClick={() => setSelectedTicket(null)}
      >
        {/* Panel — stop propagation so clicking inside doesn't close */}
        <div
          className="w-full max-w-md bg-gray-950 rounded-t-3xl pb-safe"
          style={{
            animation: 'slideUp 0.28s cubic-bezier(0.32,0.72,0,1)',
            borderTop: '1px solid rgba(124,58,237,0.3)',
            boxShadow: '0 -20px 60px rgba(124,58,237,0.2)',
          }}
          onClick={e => e.stopPropagation()}
        >
          {/* Handle bar */}
          <div className="flex justify-center pt-3 pb-1">
            <div className="w-10 h-1 bg-gray-700 rounded-full" />
          </div>

          <div className="px-6 pt-3 pb-8 flex flex-col items-center gap-5">

            {/* Event name + close */}
            <div className="w-full flex items-start justify-between gap-3">
              <div>
                <p className="text-xs text-purple-400 font-semibold uppercase tracking-wider mb-0.5">
                  {selectedTicket.ticket_type || 'Ingresso'}
                </p>
                <h2 className="text-white font-bold text-lg leading-tight">
                  {selectedTicket.event_name}
                </h2>
              </div>
              <button
                onClick={() => setSelectedTicket(null)}
                className="w-8 h-8 flex-shrink-0 rounded-xl bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white transition-colors mt-0.5"
              >
                <X size={16} />
              </button>
            </div>

            {/* QR Code — white bg for scanner readability */}
            <div className="bg-white rounded-3xl p-5 flex flex-col items-center gap-3 w-full shadow-2xl">
              <QRCodeSVG
                value={selectedTicket.qr_token || selectedTicket.order_reference || 'invalid'}
                size={220}
                bgColor="#ffffff"
                fgColor="#1e1b4b"
                level="M"
                marginSize={2}
              />
              <p className="text-gray-400 text-xs font-mono">{selectedTicket.order_reference}</p>
            </div>

            {/* Holder info */}
            <div className="w-full bg-gray-900/80 border border-gray-800 rounded-2xl px-4 py-3 space-y-2">
              <div className="flex items-center justify-between text-sm">
                <span className="text-gray-500">Titular</span>
                <span className="text-white font-medium">{selectedTicket.holder_name || user.name || 'Cliente'}</span>
              </div>
              {selectedTicket.event_date && (
                <div className="flex items-center justify-between text-sm">
                  <span className="text-gray-500">Data</span>
                  <span className="text-white font-medium">
                    {new Date(selectedTicket.event_date).toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' })}
                  </span>
                </div>
              )}
              {selectedTicket.event_location && (
                <div className="flex items-center justify-between text-sm">
                  <span className="text-gray-500">Local</span>
                  <span className="text-white font-medium text-right max-w-[55%] leading-tight">{selectedTicket.event_location}</span>
                </div>
              )}
              <div className="flex items-center justify-between text-sm">
                <span className="text-gray-500">Status</span>
                <span className={`text-xs font-bold px-2.5 py-1 rounded-full ${
                  selectedTicket.status === 'used' ? 'bg-gray-700 text-gray-300' : 'bg-green-500/15 text-green-400'
                }`}>
                  {selectedTicket.status === 'used' ? 'Utilizado' : 'Válido'}
                </span>
              </div>
            </div>

            {/* Brightness tip */}
            <div className="flex items-center gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-2.5 w-full">
              <Sun size={14} className="text-amber-400 flex-shrink-0" />
              <p className="text-amber-300/80 text-xs leading-tight">
                Mantenha o <strong>brilho do celular alto</strong> para facilitar a leitura pelo scanner.
              </p>
            </div>
          </div>
        </div>
      </div>
    )}

    {/* Slide-up animation keyframe (injected inline) */}
    <style>{`
      @keyframes slideUp {
        from { transform: translateY(100%); opacity: 0.6; }
        to   { transform: translateY(0);    opacity: 1;   }
      }
    `}</style>
    </>
  );
}
