import { useEffect, useState } from 'react';
import api from '../lib/api';
import { Ticket, QrCode, Search, Plus, CheckCircle, XCircle, Clock } from 'lucide-react';
import toast from 'react-hot-toast';

const statusBadge = { pending: 'badge-yellow', paid: 'badge-green', used: 'badge-blue', cancelled: 'badge-red', refunded: 'badge-gray' };
const statusLabel = { pending: 'Pendente', paid: 'Pago', used: 'Utilizado', cancelled: 'Cancelado', refunded: 'Reembolsado' };

export default function Tickets() {
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents]   = useState([]);
  const [eventId, setEventId] = useState('');
  const [scanMode, setScanMode] = useState(false);
  const [qrInput, setQrInput] = useState('');
  const [scanResult, setScanResult] = useState(null);
  const [scanning, setScanning] = useState(false);

  useEffect(() => {
    api.get('/events').then(r => setEvents(r.data.data || [])).catch(() => {});
  }, []);

  useEffect(() => {
    setLoading(true);
    const params = {};
    if (eventId) params.event_id = eventId;
    params.per_page = 50;
    api.get('/tickets', { params })
       .then(r => setTickets(r.data.data || []))
       .catch(() => toast.error('Erro ao carregar ingressos.'))
       .finally(() => setLoading(false));
  }, [eventId]);

  const handleScan = async (e) => {
    e.preventDefault();
    if (!qrInput.trim()) return;
    setScanning(true);
    setScanResult(null);
    try {
      const { data } = await api.post(`/tickets/${qrInput.trim()}/validate`, { gate: 'Principal' });
      setScanResult(data);
      if (data.data?.status === 'valid') toast.success('✅ Acesso liberado!');
      else toast.error('❌ Ingresso inválido.');
      setQrInput('');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao validar.');
    } finally {
      setScanning(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2"><Ticket size={22} className="text-purple-400" /> Ingressos</h1>
          <p className="text-gray-500 text-sm mt-1">{tickets.length} ingresso(s)</p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setScanMode(!scanMode)} className={scanMode ? 'btn-secondary' : 'btn-outline'}>
            <QrCode size={16} /> {scanMode ? 'Fechar Scanner' : 'Scanner QR'}
          </button>
        </div>
      </div>

      {/* QR Scanner */}
      {scanMode && (
        <div className="card border-purple-800/40">
          <h2 className="section-title">🔍 Validar Ingresso</h2>
          <form onSubmit={handleScan} className="flex gap-3">
            <input
              className="input flex-1"
              placeholder="Cole o token QR ou escaneie o código..."
              value={qrInput}
              onChange={e => setQrInput(e.target.value)}
              autoFocus
            />
            <button type="submit" disabled={scanning} className="btn-primary">
              {scanning ? <span className="spinner w-4 h-4" /> : 'Validar'}
            </button>
          </form>

          {scanResult && (
            <div className={`mt-4 rounded-xl p-4 border flex items-start gap-3 ${
              scanResult.data?.status === 'valid'
                ? 'bg-green-900/20 border-green-800'
                : 'bg-red-900/20 border-red-800'
            }`}>
              {scanResult.data?.status === 'valid'
                ? <CheckCircle size={20} className="text-green-400 flex-shrink-0 mt-0.5" />
                : <XCircle size={20} className="text-red-400 flex-shrink-0 mt-0.5" />}
              <div>
                <p className={`font-semibold ${scanResult.data?.status === 'valid' ? 'text-green-400' : 'text-red-400'}`}>
                  {scanResult.message}
                </p>
                {scanResult.data?.ticket && (
                  <p className="text-sm text-gray-400 mt-1">
                    {scanResult.data.ticket.holder_name} — {scanResult.data.ticket.ticket_type}
                  </p>
                )}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Filters */}
      <div className="flex gap-3">
        <select className="select w-auto min-w-[200px]" value={eventId} onChange={e => setEventId(e.target.value)}>
          <option value="">Todos os eventos</option>
          {events.map(ev => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
        </select>
      </div>

      {/* Table */}
      {loading ? (
        <div className="flex items-center justify-center py-20"><div className="spinner w-10 h-10" /></div>
      ) : tickets.length === 0 ? (
        <div className="empty-state"><Ticket size={48} className="text-gray-700" /><p>Nenhum ingresso encontrado</p></div>
      ) : (
        <div className="table-wrapper">
          <table className="table">
            <thead>
              <tr>
                <th>Titular</th>
                <th>Evento</th>
                <th>Tipo</th>
                <th>Valor</th>
                <th>Status</th>
                <th>Comprado em</th>
              </tr>
            </thead>
            <tbody>
              {tickets.map(t => (
                <tr key={t.id}>
                  <td>
                    <div className="font-medium text-white">{t.holder_name || '—'}</div>
                    <div className="text-xs text-gray-500">{t.holder_email}</div>
                  </td>
                  <td>{t.event_name}</td>
                  <td><span className="badge-purple">{t.ticket_type}</span></td>
                  <td>R$ {parseFloat(t.price_paid || 0).toFixed(2)}</td>
                  <td><span className={statusBadge[t.status] || 'badge-gray'}>{statusLabel[t.status] || t.status}</span></td>
                  <td className="text-gray-400 text-xs">
                    {t.purchased_at ? new Date(t.purchased_at).toLocaleString('pt-BR') : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
