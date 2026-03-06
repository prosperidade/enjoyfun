import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link2, Mail, Pencil, Trash2, Upload, Users, X, CheckCircle2, AlertCircle } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../lib/api';

const PAGE_SIZE = 10;

export default function Guests() {
  const [guests, setGuests]           = useState([]);
  const [events, setEvents]           = useState([]);
  const [loading, setLoading]         = useState(true);
  const [uploading, setUploading]     = useState(false);
  const [deleting, setDeleting]       = useState(null); // id do convidado sendo deletado

  // Modals
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [selectedGuest, setSelectedGuest]     = useState(null); // convidado em edição

  // Filtros e paginação
  const [selectedEvent, setSelectedEvent] = useState('');
  const [searchInput, setSearchInput]     = useState('');
  const [search, setSearch]               = useState('');
  const [page, setPage]                   = useState(1);
  const [pagination, setPagination]       = useState({ page: 1, total_pages: 1, total: 0, limit: PAGE_SIZE });

  // Upload
  const [uploadEvent, setUploadEvent]     = useState('');
  const [uploadFile, setUploadFile]       = useState(null);
  const [importSummary, setImportSummary] = useState(null); // { imported, ignored, skipped }

  // Formulário de edição
  const [editForm, setEditForm] = useState({ holder_name: '', holder_email: '', holder_phone: '' });
  const [saving, setSaving]     = useState(false);

  // ── Debounce search ──────────────────────────────────────────────────────
  useEffect(() => {
    const timeout = setTimeout(() => {
      setPage(1);
      setSearch(searchInput.trim());
    }, 350);
    return () => clearTimeout(timeout);
  }, [searchInput]);

  const activeEventName = useMemo(() => {
    if (!selectedEvent) return 'Todos os eventos';
    return events.find((e) => String(e.id) === String(selectedEvent))?.name || 'Evento';
  }, [events, selectedEvent]);

  // ── Loaders ──────────────────────────────────────────────────────────────
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
          search:   search || undefined,
          page,
          limit:    PAGE_SIZE,
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

  useEffect(() => { loadEvents(); }, []);
  useEffect(() => { loadGuests(); }, [loadGuests]);

  // ── Upload / Importação ──────────────────────────────────────────────────
  const handleUpload = async (e) => {
    e.preventDefault();
    if (!uploadEvent) { toast.error('Selecione um evento para importação.'); return; }
    if (!uploadFile)  { toast.error('Selecione um arquivo CSV.'); return; }

    const formData = new FormData();
    formData.append('event_id', uploadEvent);
    formData.append('file', uploadFile);

    setUploading(true);
    setImportSummary(null);
    try {
      const { data } = await api.post('/guests/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      // Guarda o resumo para exibir no modal
      setImportSummary({
        imported: data.data?.imported ?? 0,
        ignored:  data.data?.ignored  ?? 0,
        skipped:  data.data?.skipped  ?? 0,
        message:  data.message || 'Importação concluída!',
      });

      setUploadFile(null);
      setUploadEvent('');
      setPage(1);
      await loadGuests();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao importar convidados.');
    } finally {
      setUploading(false);
    }
  };

  const closeUploadModal = () => {
    setShowUploadModal(false);
    setImportSummary(null);
    setUploadFile(null);
    setUploadEvent('');
  };

  // ── Copiar link ──────────────────────────────────────────────────────────
  const copyInviteLink = async (token) => {
    const url = `${window.location.origin}/invite?token=${token}`;
    try {
      await navigator.clipboard.writeText(url);
      toast.success('Link do convite copiado!');
    } catch {
      toast.error('Não foi possível copiar o link.');
    }
  };

  // ── Edição ───────────────────────────────────────────────────────────────
  const openEdit = (guest) => {
    setSelectedGuest(guest);
    setEditForm({
      holder_name:  guest.name  || '',
      holder_email: guest.email || '',
      holder_phone: guest.phone || '',
    });
  };

  const handleEdit = async (e) => {
    e.preventDefault();
    if (!selectedGuest) return;

    setSaving(true);
    try {
      await api.put(`/guests/${selectedGuest.id}`, editForm);
      toast.success('Convidado atualizado!');
      setSelectedGuest(null);
      await loadGuests();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao atualizar convidado.');
    } finally {
      setSaving(false);
    }
  };

  // ── Exclusão ─────────────────────────────────────────────────────────────
  const handleDelete = async (guest) => {
    if (!window.confirm(`Remover "${guest.name}" da lista de convidados? Esta ação não pode ser desfeita.`)) return;

    setDeleting(guest.id);
    try {
      await api.delete(`/guests/${guest.id}`);
      toast.success(`${guest.name} removido(a) com sucesso.`);
      await loadGuests();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao remover convidado.');
    } finally {
      setDeleting(null);
    }
  };

  // ── Render ───────────────────────────────────────────────────────────────
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Users size={22} className="text-blue-400" /> Convidados
          </h1>
          <p className="text-sm text-gray-500">{pagination.total} convidado(s) • {activeEventName}</p>
        </div>

        <button className="btn-primary flex items-center gap-2" onClick={() => setShowUploadModal(true)}>
          <Upload size={16} /> Importar CSV
        </button>
      </div>

      {/* Filtros */}
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
          onChange={(e) => { setSelectedEvent(e.target.value); setPage(1); }}
        >
          <option value="">Todos os eventos</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>{ev.name}</option>
          ))}
        </select>
      </div>

      {/* Tabela */}
      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>E-mail</th>
              <th>Telefone</th>
              <th>Status</th>
              <th>Evento</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={6} className="py-10 text-center"><div className="spinner w-6 h-6 mx-auto" /></td></tr>
            ) : guests.length === 0 ? (
              <tr><td colSpan={6} className="py-10 text-center text-sm text-gray-500">Nenhum convidado encontrado para esse filtro.</td></tr>
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
                  <td>
                    <span className={`text-xs font-semibold px-2 py-1 rounded-full ${
                      guest.status === 'used'
                        ? 'bg-green-500/15 text-green-400 border border-green-500/30'
                        : 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                    }`}>
                      {guest.status === 'used' ? 'Presente' : guest.status}
                    </span>
                  </td>
                  <td className="text-gray-400">{guest.event_name || `#${guest.event_id}`}</td>
                  <td>
                    <div className="flex items-center gap-1">
                      {/* Copiar link */}
                      <button
                        type="button"
                        className="btn-secondary px-2 py-1 inline-flex items-center gap-1 text-xs"
                        onClick={() => copyInviteLink(guest.qr_code_token)}
                        title="Copiar link do convite"
                      >
                        <Link2 size={13} />
                      </button>

                      {/* Editar */}
                      <button
                        type="button"
                        className="btn-secondary px-2 py-1 inline-flex items-center gap-1 text-xs text-blue-400 hover:text-blue-300"
                        onClick={() => openEdit(guest)}
                        title="Editar convidado"
                      >
                        <Pencil size={13} />
                      </button>

                      {/* Excluir */}
                      <button
                        type="button"
                        className="btn-secondary px-2 py-1 inline-flex items-center gap-1 text-xs text-red-400 hover:text-red-300 disabled:opacity-40"
                        onClick={() => handleDelete(guest)}
                        disabled={deleting === guest.id}
                        title="Remover convidado"
                      >
                        {deleting === guest.id
                          ? <span className="spinner w-3 h-3" />
                          : <Trash2 size={13} />
                        }
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Paginação */}
      <div className="flex items-center justify-between text-sm text-gray-400">
        <span>Página {pagination.page} de {pagination.total_pages}</span>
        <div className="flex gap-2">
          <button
            className="btn-secondary"
            disabled={pagination.page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Anterior
          </button>
          <button
            className="btn-secondary"
            disabled={pagination.page >= pagination.total_pages}
            onClick={() => setPage((p) => Math.min(pagination.total_pages, p + 1))}
          >
            Próxima
          </button>
        </div>
      </div>

      {/* ── Modal: Upload CSV ─────────────────────────────────────────────── */}
      {showUploadModal && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div className="p-6 border-b border-gray-800 flex justify-between items-center">
              <h2 className="text-lg font-bold text-white flex items-center gap-2">
                <Upload size={18} className="text-blue-400" /> Importar Convidados via CSV
              </h2>
              <button onClick={closeUploadModal} className="text-gray-400 hover:text-white">
                <X size={20} />
              </button>
            </div>

            {/* Resumo pós-importação */}
            {importSummary ? (
              <div className="p-6 space-y-4">
                <div className="rounded-xl border border-gray-700 overflow-hidden divide-y divide-gray-700">
                  <div className="flex items-center gap-3 p-4 bg-green-500/10">
                    <CheckCircle2 size={22} className="text-green-400 shrink-0" />
                    <div>
                      <p className="text-white font-semibold">
                        {importSummary.imported} convidado(s) inserido(s)
                      </p>
                      <p className="text-xs text-gray-400">Adicionados com sucesso à lista</p>
                    </div>
                  </div>

                  {importSummary.ignored > 0 && (
                    <div className="flex items-center gap-3 p-4 bg-amber-500/10">
                      <AlertCircle size={22} className="text-amber-400 shrink-0" />
                      <div>
                        <p className="text-white font-semibold">
                          {importSummary.ignored} ignorado(s) — já existiam
                        </p>
                        <p className="text-xs text-gray-400">E-mail já cadastrado neste evento</p>
                      </div>
                    </div>
                  )}

                  {importSummary.skipped > 0 && (
                    <div className="flex items-center gap-3 p-4 bg-red-500/10">
                      <AlertCircle size={22} className="text-red-400 shrink-0" />
                      <div>
                        <p className="text-white font-semibold">
                          {importSummary.skipped} linha(s) inválida(s)
                        </p>
                        <p className="text-xs text-gray-400">Nome/e-mail ausente ou e-mail mal formatado</p>
                      </div>
                    </div>
                  )}
                </div>

                <div className="flex gap-3">
                  <button className="btn-secondary flex-1" onClick={closeUploadModal}>
                    Fechar
                  </button>
                  <button
                    className="btn-primary flex-1"
                    onClick={() => setImportSummary(null)}
                  >
                    Nova Importação
                  </button>
                </div>
              </div>
            ) : (
              <form onSubmit={handleUpload} className="p-6 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1">Evento *</label>
                  <select
                    className="input w-full"
                    value={uploadEvent}
                    onChange={(e) => setUploadEvent(e.target.value)}
                  >
                    <option value="">Selecione um evento</option>
                    {events.map((ev) => (
                      <option key={ev.id} value={ev.id}>{ev.name}</option>
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
                  <p className="text-xs text-gray-500 mt-1">
                    Cabeçalho obrigatório: <code className="text-blue-400">name,email</code>.
                    {' '}Opcionais: <code className="text-gray-400">phone</code>.
                  </p>
                </div>

                <div className="flex gap-3 pt-2">
                  <button type="button" className="btn-secondary flex-1" onClick={closeUploadModal}>
                    Cancelar
                  </button>
                  <button type="submit" className="btn-primary flex-1" disabled={uploading}>
                    {uploading ? <span className="spinner w-5 h-5" /> : 'Importar'}
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      {/* ── Modal: Editar Convidado ───────────────────────────────────────── */}
      {selectedGuest && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div className="p-6 border-b border-gray-800 flex justify-between items-center">
              <h2 className="text-lg font-bold text-white flex items-center gap-2">
                <Pencil size={18} className="text-blue-400" /> Editar Convidado
              </h2>
              <button onClick={() => setSelectedGuest(null)} className="text-gray-400 hover:text-white">
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleEdit} className="p-6 space-y-4">
              <div>
                <label className="block text-xs font-semibold text-gray-400 mb-1">Nome *</label>
                <input
                  className="input w-full"
                  value={editForm.holder_name}
                  onChange={(e) => setEditForm((f) => ({ ...f, holder_name: e.target.value }))}
                  placeholder="Nome completo"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-400 mb-1">E-mail</label>
                <input
                  type="email"
                  className="input w-full"
                  value={editForm.holder_email}
                  onChange={(e) => setEditForm((f) => ({ ...f, holder_email: e.target.value }))}
                  placeholder="email@exemplo.com"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-400 mb-1">Telefone</label>
                <input
                  className="input w-full"
                  value={editForm.holder_phone}
                  onChange={(e) => setEditForm((f) => ({ ...f, holder_phone: e.target.value }))}
                  placeholder="(11) 99999-9999"
                />
              </div>

              <div className="flex gap-3 pt-2">
                <button type="button" className="btn-secondary flex-1" onClick={() => setSelectedGuest(null)}>
                  Cancelar
                </button>
                <button type="submit" className="btn-primary flex-1" disabled={saving}>
                  {saving ? <span className="spinner w-5 h-5" /> : 'Salvar Alterações'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}