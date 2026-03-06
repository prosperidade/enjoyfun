import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link2, Mail, Upload, Users } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../lib/api';

const PAGE_SIZE = 10;

export default function Guests() {
  const [guests, setGuests] = useState([]);
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [showModal, setShowModal] = useState(false);
  const [selectedEvent, setSelectedEvent] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ page: 1, total_pages: 1, total: 0, limit: PAGE_SIZE });
  const [uploadEvent, setUploadEvent] = useState('');
  const [uploadFile, setUploadFile] = useState(null);

  const copyInviteLink = async (token) => {
    const inviteUrl = `https://seusite.com/invite?token=${token}`;
    try {
      await navigator.clipboard.writeText(inviteUrl);
      toast.success('Link do convite copiado!');
    } catch {
      toast.error('Não foi possível copiar o link.');
    }
  };

  useEffect(() => {
    const timeout = setTimeout(() => {
      setPage(1);
      setSearch(searchInput.trim());
    }, 350);

    return () => clearTimeout(timeout);
  }, [searchInput]);

  const activeEventName = useMemo(() => {
    if (!selectedEvent) return 'Todos os eventos';
    return events.find((event) => String(event.id) === String(selectedEvent))?.name || 'Evento';
  }, [events, selectedEvent]);

  const loadEvents = async () => {
    try {
      const { data } = await api.get('/events');
      setEvents(data.data || []);
    } catch {
      toast.error('Erro ao carregar eventos.');
    }
  };

  const loadGuests = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/guests', {
        params: {
          event_id: selectedEvent || undefined,
          search: search || undefined,
          page,
          limit: PAGE_SIZE,
        },
      });

      setGuests(data.data?.items || []);
      setPagination(data.data?.pagination || { page: 1, total_pages: 1, total: 0, limit: PAGE_SIZE });
    } catch {
      toast.error('Erro ao carregar convidados.');
    } finally {
      setLoading(false);
    }
  }, [page, search, selectedEvent]);

  useEffect(() => {
    loadEvents();
  }, []);

  useEffect(() => {
    loadGuests();
  }, [loadGuests]);

  const handleUpload = async (e) => {
    e.preventDefault();

    if (!uploadEvent) {
      toast.error('Selecione um evento para importação.');
      return;
    }

    if (!uploadFile) {
      toast.error('Selecione um arquivo CSV.');
      return;
    }

    const formData = new FormData();
    formData.append('event_id', uploadEvent);
    formData.append('file', uploadFile);

    setUploading(true);
    try {
      const { data } = await api.post('/guests/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      toast.success(data.message || 'Importação concluída!');
      setShowModal(false);
      setUploadEvent('');
      setUploadFile(null);
      setPage(1);
      await loadGuests();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao importar convidados.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Users size={22} className="text-blue-400" /> Convidados
          </h1>
          <p className="text-sm text-gray-500">{pagination.total} convidado(s) • {activeEventName}</p>
        </div>

        <button className="btn-primary flex items-center gap-2" onClick={() => setShowModal(true)}>
          <Upload size={16} /> Importar CSV
        </button>
      </div>

      <div className="card p-4 grid grid-cols-1 lg:grid-cols-3 gap-3">
        <input
          className="input w-full lg:col-span-2"
          placeholder="Buscar por nome ou e-mail"
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
        />

        <select
          className="input w-full"
          value={selectedEvent}
          onChange={(e) => {
            setSelectedEvent(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Todos os eventos</option>
          {events.map((event) => (
            <option key={event.id} value={event.id}>{event.name}</option>
          ))}
        </select>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>E-mail</th>
              <th>Telefone</th>
              <th>Documento</th>
              <th>Status</th>
              <th>Evento</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={7} className="py-10 text-center"><div className="spinner w-6 h-6 mx-auto" /></td></tr>
            ) : guests.length === 0 ? (
              <tr><td colSpan={7} className="py-10 text-center text-sm text-gray-500">Nenhum convidado encontrado para esse filtro.</td></tr>
            ) : (
              guests.map((guest) => (
                <tr key={guest.id}>
                  <td className="text-white font-medium">{guest.name}</td>
                  <td>
                    <div className="flex items-center gap-1 text-gray-300">
                      <Mail size={14} className="text-gray-500" />
                      {guest.email}
                    </div>
                  </td>
                  <td className="text-gray-400">{guest.phone || '—'}</td>
                  <td className="text-gray-400">{guest.document || '—'}</td>
                  <td>
                    <span className={`text-xs font-semibold px-2 py-1 rounded-full ${guest.status === 'presente' ? 'bg-green-500/15 text-green-400 border border-green-500/30' : 'bg-amber-500/15 text-amber-400 border border-amber-500/30'}`}>
                      {guest.status}
                    </span>
                  </td>
                  <td className="text-gray-400">{guest.event_name || `#${guest.event_id}`}</td>
                  <td>
                    <button
                      type="button"
                      className="btn-secondary px-2 py-1 inline-flex items-center gap-1 text-xs"
                      onClick={() => copyInviteLink(guest.qr_code_token)}
                      title="Copiar link do convite"
                    >
                      <Link2 size={14} /> Copiar link
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between text-sm text-gray-400">
        <span>Página {pagination.page} de {pagination.total_pages}</span>
        <div className="flex gap-2">
          <button
            className="btn-secondary"
            disabled={pagination.page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Anterior
          </button>
          <button
            className="btn-secondary"
            disabled={pagination.page >= pagination.total_pages}
            onClick={() => setPage((current) => Math.min(pagination.total_pages, current + 1))}
          >
            Próxima
          </button>
        </div>
      </div>

      {showModal && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div className="p-6 border-b border-gray-800 flex justify-between items-center">
              <h2 className="text-lg font-bold text-white flex items-center gap-2">
                <Upload size={18} className="text-blue-400" /> Upload de convidados
              </h2>
              <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-white text-xl leading-none">✕</button>
            </div>

            <form onSubmit={handleUpload} className="p-6 space-y-4">
              <div>
                <label className="block text-xs font-semibold text-gray-400 mb-1">Evento *</label>
                <select className="input w-full" value={uploadEvent} onChange={(e) => setUploadEvent(e.target.value)}>
                  <option value="">Selecione um evento</option>
                  {events.map((event) => (
                    <option key={event.id} value={event.id}>{event.name}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-400 mb-1">Arquivo CSV *</label>
                <input
                  type="file"
                  className="input w-full"
                  accept=".csv,text/csv"
                  onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
                />
                <p className="text-xs text-gray-500 mt-1">Cabeçalho obrigatório: name,email. Opcionais: phone,document,status.</p>
              </div>

              <div className="flex gap-3 pt-2">
                <button type="button" className="btn-secondary flex-1" onClick={() => setShowModal(false)}>
                  Cancelar
                </button>
                <button type="submit" className="btn-primary flex-1" disabled={uploading}>
                  {uploading ? <span className="spinner w-5 h-5" /> : 'Importar'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
