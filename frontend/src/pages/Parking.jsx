import { ParkingSquare, Plus, Search } from 'lucide-react';
import { useState, useEffect } from 'react';
import api from '../lib/api';
import toast from 'react-hot-toast';

export default function Parking() {
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents]   = useState([]);
  const [eventId, setEventId] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ event_id: '', license_plate: '', vehicle_type: 'car', spot_code: '', fee: '0' });

  useEffect(() => { api.get('/events').then(r => setEvents(r.data.data || [])).catch(() => {}); }, []);
  useEffect(() => {
    setLoading(true);
    const params = eventId ? { event_id: eventId, per_page: 100 } : { per_page: 100 };
    api.get('/parking', { params }).then(r => setRecords(r.data.data || [])).catch(() => {}).finally(() => setLoading(false));
  }, [eventId]);

  const handleEntry = async (e) => {
    e.preventDefault();
    try {
      await api.post('/parking', form);
      toast.success('Entrada registrada!');
      setShowForm(false);
      setForm({ event_id: '', license_plate: '', vehicle_type: 'car', spot_code: '', fee: '0' });
      api.get('/parking', { params: { per_page: 100 } }).then(r => setRecords(r.data.data || []));
    } catch (err) { toast.error(err.response?.data?.message || 'Erro.'); }
  };

  const handleExit = async (id) => {
    try {
      await api.post(`/parking/${id}/exit`, { fee: 0 });
      toast.success('Saída registrada!');
      setRecords(r => r.map(rec => rec.id === id ? { ...rec, status: 'out', exit_at: new Date().toISOString() } : rec));
    } catch (err) { toast.error(err.response?.data?.message || 'Erro.'); }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title flex items-center gap-2"><ParkingSquare size={22} className="text-cyan-400" /> Estacionamento</h1>
          <p className="text-gray-500 text-sm">{records.filter(r => r.status === 'in').length} veículo(s) no local</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary"><Plus size={16} /> Registrar Entrada</button>
      </div>

      {showForm && (
        <div className="card border-cyan-800/40 max-w-lg">
          <h2 className="section-title">Registrar Entrada de Veículo</h2>
          <form onSubmit={handleEntry} className="grid grid-cols-2 gap-4">
            <div className="col-span-2">
              <label className="input-label">Evento *</label>
              <select className="select" value={form.event_id} onChange={e => setForm(f => ({ ...f, event_id: e.target.value }))} required>
                <option value="">Selecionar...</option>
                {events.map(ev => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
              </select>
            </div>
            <div>
              <label className="input-label">Placa *</label>
              <input className="input uppercase" placeholder="ABC-1234" value={form.license_plate} onChange={e => setForm(f => ({ ...f, license_plate: e.target.value.toUpperCase() }))} required />
            </div>
            <div>
              <label className="input-label">Tipo</label>
              <select className="select" value={form.vehicle_type} onChange={e => setForm(f => ({ ...f, vehicle_type: e.target.value }))}>
                <option value="car">Carro</option>
                <option value="motorcycle">Moto</option>
                <option value="truck">Caminhão</option>
                <option value="bus">Ônibus</option>
              </select>
            </div>
            <div>
              <label className="input-label">Vaga</label>
              <input className="input" placeholder="Ex: A-01" value={form.spot_code} onChange={e => setForm(f => ({ ...f, spot_code: e.target.value }))} />
            </div>
            <div>
              <label className="input-label">Taxa (R$)</label>
              <input className="input" type="number" step="0.01" min="0" value={form.fee} onChange={e => setForm(f => ({ ...f, fee: e.target.value }))} />
            </div>
            <div className="col-span-2 flex gap-3">
              <button type="submit" className="btn-primary flex-1">Registrar</button>
              <button type="button" onClick={() => setShowForm(false)} className="btn-outline flex-1">Cancelar</button>
            </div>
          </form>
        </div>
      )}

      <div className="flex gap-3">
        <select className="select w-auto" value={eventId} onChange={e => setEventId(e.target.value)}>
          <option value="">Todos os eventos</option>
          {events.map(ev => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
        </select>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead><tr><th>Placa</th><th>Tipo</th><th>Vaga</th><th>Entrada</th><th>Saída</th><th>Status</th><th>Ação</th></tr></thead>
          <tbody>
            {records.length === 0 ? (
              <tr><td colSpan={7} className="text-center text-gray-500 py-10">Nenhum registro</td></tr>
            ) : records.map(r => (
              <tr key={r.id}>
                <td className="font-mono font-bold text-white">{r.license_plate}</td>
                <td>{r.vehicle_type}</td>
                <td>{r.spot_code || '—'}</td>
                <td className="text-xs text-gray-400">{new Date(r.entry_at).toLocaleString('pt-BR')}</td>
                <td className="text-xs text-gray-400">{r.exit_at ? new Date(r.exit_at).toLocaleString('pt-BR') : '—'}</td>
                <td><span className={r.status === 'in' ? 'badge-green' : 'badge-gray'}>{r.status === 'in' ? 'No local' : 'Saiu'}</span></td>
                <td>{r.status === 'in' && <button onClick={() => handleExit(r.id)} className="btn-outline btn-sm">Registrar Saída</button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
