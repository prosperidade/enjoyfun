import { useEffect, useState } from 'react';
import api from '../lib/api';
import { CalendarDays, Plus, MapPin, Clock, ChevronRight, Search } from 'lucide-react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';

const statusBadge = { draft: 'badge-gray', published: 'badge-green', ongoing: 'badge-blue', finished: 'badge-gray', cancelled: 'badge-red' };
const statusLabel = { draft: 'Rascunho', published: 'Publicado', ongoing: 'Em andamento', finished: 'Finalizado', cancelled: 'Cancelado' };

export default function Events() {
  const [events, setEvents]   = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch]   = useState('');
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving]   = useState(false);
  const [form, setForm] = useState({ name: '', description: '', venue_name: '', address: '', starts_at: '', ends_at: '', status: 'draft', capacity: '' });

  const load = () => {
    setLoading(true);
    api.get('/events', { params: { search, per_page: 50 } })
       .then(r => setEvents(r.data.data || []))
       .catch(() => toast.error('Erro ao carregar eventos.'))
       .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [search]);

  const set = k => e => setForm(f => ({ ...f, [k]: e.target.value }));

  const handleCreate = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.post('/events', form);
      toast.success('Evento criado!');
      setShowForm(false);
      setForm({ name: '', description: '', venue_name: '', address: '', starts_at: '', ends_at: '', status: 'draft', capacity: '' });
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao criar evento.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2"><CalendarDays size={22} className="text-purple-400" /> Eventos</h1>
          <p className="text-gray-500 text-sm mt-1">{events.length} evento(s) encontrado(s)</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary">
          <Plus size={16} /> Novo Evento
        </button>
      </div>

      {/* Search */}
      <div className="relative">
        <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500" />
        <input className="input pl-10" placeholder="Buscar eventos..." value={search} onChange={e => setSearch(e.target.value)} />
      </div>

      {/* Create form */}
      {showForm && (
        <div className="card border-purple-800/40">
          <h2 className="section-title">Criar Novo Evento</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="sm:col-span-2">
              <label className="input-label">Nome do Evento *</label>
              <input className="input" value={form.name} onChange={set('name')} required placeholder="Ex: Festival de Verão 2025" />
            </div>
            <div>
              <label className="input-label">Local</label>
              <input className="input" value={form.venue_name} onChange={set('venue_name')} placeholder="Nome do local" />
            </div>
            <div>
              <label className="input-label">Capacidade</label>
              <input className="input" type="number" value={form.capacity} onChange={set('capacity')} placeholder="Ex: 5000" />
            </div>
            <div>
              <label className="input-label">Início *</label>
              <input className="input" type="datetime-local" value={form.starts_at} onChange={set('starts_at')} required />
            </div>
            <div>
              <label className="input-label">Término *</label>
              <input className="input" type="datetime-local" value={form.ends_at} onChange={set('ends_at')} required />
            </div>
            <div className="sm:col-span-2">
              <label className="input-label">Endereço</label>
              <input className="input" value={form.address} onChange={set('address')} placeholder="Rua, número, cidade" />
            </div>
            <div className="sm:col-span-2">
              <label className="input-label">Descrição</label>
              <textarea className="input resize-none" rows={3} value={form.description} onChange={set('description')} placeholder="Descrição do evento..." />
            </div>
            <div>
              <label className="input-label">Status</label>
              <select className="select" value={form.status} onChange={set('status')}>
                <option value="draft">Rascunho</option>
                <option value="published">Publicado</option>
              </select>
            </div>
            <div className="flex items-end gap-3">
              <button type="submit" disabled={saving} className="btn-primary flex-1">
                {saving ? <span className="spinner w-4 h-4" /> : 'Criar Evento'}
              </button>
              <button type="button" onClick={() => setShowForm(false)} className="btn-outline flex-1">Cancelar</button>
            </div>
          </form>
        </div>
      )}

      {/* Events grid */}
      {loading ? (
        <div className="flex items-center justify-center py-20"><div className="spinner w-10 h-10" /></div>
      ) : events.length === 0 ? (
        <div className="empty-state">
          <CalendarDays size={48} className="text-gray-700" />
          <p className="text-lg">Nenhum evento encontrado</p>
          <button onClick={() => setShowForm(true)} className="btn-primary mt-2"><Plus size={16} /> Criar Primeiro Evento</button>
        </div>
      ) : (
        <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
          {events.map(ev => (
            <div key={ev.id} className="card-hover flex flex-col gap-3">
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <h3 className="font-semibold text-white truncate">{ev.name}</h3>
                  <p className="text-xs text-gray-500 mt-0.5">por {ev.organizer_name}</p>
                </div>
                <span className={statusBadge[ev.status] || 'badge-gray'}>{statusLabel[ev.status] || ev.status}</span>
              </div>

              {ev.venue_name && (
                <div className="flex items-center gap-1.5 text-xs text-gray-400">
                  <MapPin size={12} /> {ev.venue_name}
                </div>
              )}
              <div className="flex items-center gap-1.5 text-xs text-gray-400">
                <Clock size={12} />
                {new Date(ev.starts_at).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })}
              </div>
              {ev.capacity && (
                <div className="text-xs text-gray-500">Capacidade: {parseInt(ev.capacity).toLocaleString()}</div>
              )}

              <div className="flex gap-2 pt-2 border-t border-gray-800 mt-auto">
                <Link to={`/events/${ev.id}`} className="btn-outline btn-sm flex-1">
                  Ver Detalhes <ChevronRight size={14} />
                </Link>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
