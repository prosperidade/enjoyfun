import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Ticket, CalendarDays, MapPin, ArrowLeft, Search, X, Sun } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { getMyTicketsApi } from '../../api/customer';
import { useCustomerEventContext } from '../../hooks/useCustomerEventContext';
import { getStoredUser } from '../../lib/session';

export default function CustomerTickets() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { eventContext } = useCustomerEventContext(slug);
  const user = getStoredUser() || {};
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [selectedTicket, setSelectedTicket] = useState(null);

  useEffect(() => {
    if (!eventContext?.id) return;
    setLoading(true);
    getMyTicketsApi({ eventId: Number(eventContext.id) })
      .then((data) => setTickets(data || []))
      .catch(() => setTickets([]))
      .finally(() => setLoading(false));
  }, [eventContext?.id]);

  const filtered = filter === 'all' ? tickets : tickets.filter(t => t.status === filter);

  const statusLabel = { valid: 'Valido', used: 'Usado', cancelled: 'Cancelado' };
  const statusStyle = {
    valid: 'bg-green-500/15 text-green-400',
    used: 'bg-gray-700 text-gray-400',
    cancelled: 'bg-red-500/15 text-red-400',
  };

  return (
    <>
    <div className="min-h-screen bg-gray-950 flex flex-col" style={{ background: 'radial-gradient(ellipse at top, rgba(124,58,237,0.12) 0%, #030712 60%)' }}>
      <div className="flex-1 flex flex-col max-w-md mx-auto w-full px-4 py-6 space-y-5">

        {/* Header */}
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(`/app/${slug}/home`)} className="p-2 rounded-xl border border-gray-800 hover:border-purple-500/40 text-gray-400 hover:text-white">
            <ArrowLeft size={16} />
          </button>
          <div>
            <h1 className="text-lg font-bold text-white">Meus Ingressos</h1>
            <p className="text-xs text-gray-500">{eventContext?.name || slug?.replace(/-/g, ' ')}</p>
          </div>
        </div>

        {/* Filters */}
        <div className="flex gap-2">
          {[['all', 'Todos'], ['valid', 'Validos'], ['used', 'Usados'], ['cancelled', 'Cancelados']].map(([key, label]) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              className={`text-xs px-3 py-1.5 rounded-full border transition-colors ${
                filter === key
                  ? 'bg-purple-600 border-purple-500 text-white'
                  : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'
              }`}
            >
              {label} {key === 'all' ? `(${tickets.length})` : `(${tickets.filter(t => t.status === key).length})`}
            </button>
          ))}
        </div>

        {/* Ticket list */}
        {loading ? (
          <div className="space-y-3">
            {[1, 2, 3].map(i => <div key={i} className="h-28 bg-gray-800/50 rounded-2xl animate-pulse" />)}
          </div>
        ) : filtered.length === 0 ? (
          <div className="bg-gray-900/50 border border-gray-800 border-dashed rounded-2xl p-8 flex flex-col items-center text-center gap-2">
            <Ticket size={28} className="text-gray-700" />
            <p className="text-gray-500 text-sm">Nenhum ingresso encontrado.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {filtered.map(t => (
              <div
                key={t.id}
                onClick={() => t.status !== 'cancelled' && setSelectedTicket(t)}
                className={`bg-gray-900/80 border rounded-2xl p-4 space-y-2 transition-all ${
                  t.status === 'cancelled'
                    ? 'border-gray-800 opacity-60 cursor-not-allowed'
                    : 'border-gray-800 hover:border-purple-500/40 cursor-pointer active:scale-[0.98]'
                }`}
              >
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-white font-semibold text-sm">{t.event_name}</p>
                    {t.ticket_type && <p className="text-xs text-purple-400 font-medium mt-0.5">{t.ticket_type}</p>}
                  </div>
                  <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 ${statusStyle[t.status] || statusStyle.valid}`}>
                    {statusLabel[t.status] || t.status}
                  </span>
                </div>
                <div className="flex items-center gap-4 text-xs text-gray-500">
                  {t.event_date && (
                    <div className="flex items-center gap-1">
                      <CalendarDays size={11} />
                      {new Date(t.event_date).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })}
                    </div>
                  )}
                  {t.event_location && (
                    <div className="flex items-center gap-1">
                      <MapPin size={11} />
                      <span className="truncate max-w-[150px]">{t.event_location}</span>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>

    {/* QR Modal */}
    {selectedTicket && (
      <div className="fixed inset-0 z-50 flex items-end justify-center" style={{ background: 'rgba(0,0,0,0.75)', backdropFilter: 'blur(6px)' }} onClick={() => setSelectedTicket(null)}>
        <div className="w-full max-w-md bg-gray-950 rounded-t-3xl" style={{ animation: 'slideUp 0.28s cubic-bezier(0.32,0.72,0,1)', borderTop: '1px solid rgba(124,58,237,0.3)' }} onClick={e => e.stopPropagation()}>
          <div className="flex justify-center pt-3 pb-1"><div className="w-10 h-1 bg-gray-700 rounded-full" /></div>
          <div className="px-6 pt-3 pb-8 flex flex-col items-center gap-5">
            <div className="w-full flex items-start justify-between gap-3">
              <div>
                <p className="text-xs text-purple-400 font-semibold uppercase tracking-wider mb-0.5">{selectedTicket.ticket_type || 'Ingresso'}</p>
                <h2 className="text-white font-bold text-lg leading-tight">{selectedTicket.event_name}</h2>
              </div>
              <button onClick={() => setSelectedTicket(null)} className="w-8 h-8 rounded-xl bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white"><X size={16} /></button>
            </div>
            <div className="bg-white rounded-3xl p-5 flex flex-col items-center gap-3 w-full shadow-2xl">
              <QRCodeSVG value={selectedTicket.qr_token || selectedTicket.order_reference || 'invalid'} size={220} bgColor="#ffffff" fgColor="#1e1b4b" level="M" marginSize={2} />
              <p className="text-gray-400 text-xs font-mono">{selectedTicket.order_reference}</p>
            </div>
            <div className="flex items-center gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-2.5 w-full">
              <Sun size={14} className="text-amber-400 flex-shrink-0" />
              <p className="text-amber-300/80 text-xs leading-tight">Mantenha o <strong>brilho alto</strong> para facilitar a leitura.</p>
            </div>
          </div>
        </div>
      </div>
    )}

    <style>{`@keyframes slideUp { from { transform: translateY(100%); opacity: 0.6; } to { transform: translateY(0); opacity: 1; } }`}</style>
    </>
  );
}
