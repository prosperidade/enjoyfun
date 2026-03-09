import { useEffect, useMemo, useState } from 'react';
import { Search, Mail, QrCode, MessageCircle, Copy, Trash2, CheckCircle, FileDown, X, AlertCircle, Pencil } from 'lucide-react';
import api from '../../lib/api';
import toast from 'react-hot-toast';
import BulkMessageModal from './BulkMessageModal';
import EditGuestModal from './EditGuestModal';

export default function GuestManagementTab({ eventId }) {
    const [guests, setGuests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkType, setBulkType] = useState('whatsapp');
    const [isBulkModalOpen, setIsBulkModalOpen] = useState(false);
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const [deletingId, setDeletingId] = useState(null);
    const [editingGuest, setEditingGuest] = useState(null);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [importFile, setImportFile] = useState(null);
    const [importing, setImporting] = useState(false);
    const [importSummary, setImportSummary] = useState(null);

    const fetchGuests = async () => {
        if (!eventId) return;
        setLoading(true);
        try {
            const res = await api.get('/guests', {
                params: {
                    event_id: eventId,
                    search: search.trim() || undefined,
                    page: 1,
                    limit: 500,
                },
            });
            const items = res.data?.data?.items || [];
            setGuests(items);
            setSelectedIds([]);
        } catch (error) {
            console.error(error);
            toast.error('Erro ao carregar convidados.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchGuests();
    }, [eventId]);

    useEffect(() => {
        const timeout = setTimeout(() => {
            fetchGuests();
        }, 300);
        return () => clearTimeout(timeout);
    }, [search]);

    const filteredData = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return guests;
        return guests.filter((g) =>
            (g.name || '').toLowerCase().includes(q) ||
            (g.email || '').toLowerCase().includes(q)
        );
    }, [guests, search]);

    const toggleSelectAll = () => {
        if (selectedIds.length === filteredData.length) {
            setSelectedIds([]);
            return;
        }
        setSelectedIds(filteredData.map((g) => g.id));
    };

    const toggleSelect = (id) => {
        setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const handleCopyLink = async (token) => {
        if (!token) {
            toast.error('Token do convite indisponível.');
            return;
        }
        const url = `${window.location.origin}/invite?token=${token}`;
        try {
            await navigator.clipboard.writeText(url);
            toast.success('Link copiado!');
        } catch {
            toast.error('Erro ao copiar.');
        }
    };

    const handleDelete = async (guest) => {
        if (!window.confirm(`Remover ${guest.name} deste evento?`)) return;
        setDeletingId(guest.id);
        try {
            await api.delete(`/guests/${guest.id}`);
            toast.success('Convidado removido.');
            await fetchGuests();
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao excluir convidado.');
        } finally {
            setDeletingId(null);
        }
    };

    const openEdit = (guest) => {
        setEditingGuest(guest);
        setIsEditModalOpen(true);
    };

    const closeEdit = () => {
        setIsEditModalOpen(false);
        setEditingGuest(null);
    };

    const openBulk = (type) => {
        if (selectedIds.length === 0) {
            toast.error('Selecione convidados primeiro.');
            return;
        }
        setBulkType(type);
        setIsBulkModalOpen(true);
    };

    const handleBulkDelete = async () => {
        if (selectedIds.length === 0) {
            toast.error('Selecione convidados para excluir.');
            return;
        }

        const confirmed = window.confirm(`Excluir ${selectedIds.length} convidado(s) selecionado(s)?`);
        if (!confirmed) return;

        setBulkDeleting(true);
        try {
            const res = await api.post('/guests/bulk-delete', { ids: selectedIds });
            const data = res.data?.data || {};
            const deleted = Number(data.deleted || 0);
            const notFound = Array.isArray(data.not_found) ? data.not_found.length : 0;
            const failed = Array.isArray(data.failed) ? data.failed.length : 0;

            if (data.status === 'success') {
                toast.success(`Exclusão em massa concluída (${deleted} removido(s)).`);
            } else if (data.status === 'partial') {
                toast(`Exclusão parcial: ${deleted} removido(s), ${notFound} não encontrado(s), ${failed} falha(s).`);
            } else {
                toast.error('Nenhum convidado foi removido.');
            }

            await fetchGuests();
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro na exclusão em massa de convidados.');
        } finally {
            setBulkDeleting(false);
        }
    };

    const selectedGuests = guests
        .filter((g) => selectedIds.includes(g.id))
        .map((g) => ({
            ...g,
            participant_id: g.id,
            qr_token: g.qr_code_token || g.qr_token || '',
        }));

    const closeImportModal = () => {
        setIsImportModalOpen(false);
        setImportFile(null);
        setImportSummary(null);
    };

    const handleImport = async (e) => {
        e.preventDefault();
        if (!eventId) {
            toast.error('Selecione um evento no topo do Participants Hub.');
            return;
        }
        if (!importFile) {
            toast.error('Selecione um arquivo CSV.');
            return;
        }

        const formData = new FormData();
        formData.append('event_id', String(eventId));
        formData.append('file', importFile);

        setImporting(true);
        try {
            const { data } = await api.post('/guests/import', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setImportSummary({
                imported: data?.data?.imported ?? 0,
                ignored: data?.data?.ignored ?? 0,
                skipped: data?.data?.skipped ?? 0,
                message: data?.message || 'Importação concluída.',
            });
            toast.success(data?.message || 'Importação concluída.');
            await fetchGuests();
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao importar convidados.');
        } finally {
            setImporting(false);
        }
    };

    return (
        <div className="space-y-6 animate-fade-in">
            {selectedIds.length > 0 && (
                <div className="bg-brand/10 border border-brand/20 p-4 rounded-2xl flex items-center justify-between animate-slide-up">
                    <div className="flex items-center gap-3">
                        <CheckCircle className="text-brand" size={20} />
                        <span className="text-brand font-semibold">{selectedIds.length} selecionados</span>
                    </div>
                    <div className="flex gap-2">
                        <button onClick={() => openBulk('whatsapp')} className="btn-primary bg-green-600 hover:bg-green-700 h-9 px-4 text-xs flex items-center gap-2">
                            <MessageCircle size={14} /> WhatsApp
                        </button>
                        <button onClick={() => openBulk('email')} className="btn-secondary h-9 px-4 text-xs flex items-center gap-2">
                            <Mail size={14} /> Email
                        </button>
                        <button
                            onClick={handleBulkDelete}
                            disabled={bulkDeleting}
                            className="btn-secondary h-9 px-4 text-xs flex items-center gap-2 border-red-700/60 text-red-400 hover:bg-red-900/30"
                        >
                            <Trash2 size={14} /> {bulkDeleting ? 'Deletando...' : 'Delete'}
                        </button>
                        <button onClick={() => setSelectedIds([])} className="p-2 text-gray-400 hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            <div className="flex flex-col sm:flex-row gap-4 items-center justify-between">
                <div className="relative w-full sm:w-96">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
                    <input
                        type="text"
                        placeholder="Buscar convidados por nome ou e-mail..."
                        className="input pl-10 w-full"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="flex gap-2 w-full sm:w-auto">
                    <button
                        onClick={() => setIsImportModalOpen(true)}
                        className="btn-secondary h-10 px-4 text-xs flex items-center gap-2 w-full sm:w-auto justify-center"
                    >
                        <FileDown size={14} /> Importar CSV
                    </button>
                </div>
            </div>

            <div className="card overflow-hidden p-0 border border-gray-800">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-gray-300">
                        <thead className="bg-gray-900/80 text-gray-400 uppercase text-xs border-b border-gray-800">
                            <tr>
                                <th className="px-6 py-4 w-10">
                                    <input
                                        type="checkbox"
                                        className="checkbox"
                                        checked={filteredData.length > 0 && selectedIds.length === filteredData.length}
                                        onChange={toggleSelectAll}
                                    />
                                </th>
                                <th className="px-6 py-4 font-semibold">Convidado</th>
                                <th className="px-6 py-4 font-semibold">Status</th>
                                <th className="px-6 py-4 font-semibold text-center whitespace-nowrap">QR Code / Link</th>
                                <th className="px-6 py-4 font-semibold text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-800">
                            {loading ? (
                                <tr>
                                    <td colSpan="5" className="px-6 py-12 text-center">
                                        <div className="spinner mx-auto" />
                                    </td>
                                </tr>
                            ) : filteredData.length > 0 ? (
                                filteredData.map((guest) => (
                                    <tr key={guest.id} className={`hover:bg-gray-800/30 transition-colors ${selectedIds.includes(guest.id) ? 'bg-brand/5' : ''}`}>
                                        <td className="px-6 py-4">
                                            <input
                                                type="checkbox"
                                                className="checkbox"
                                                checked={selectedIds.includes(guest.id)}
                                                onChange={() => toggleSelect(guest.id)}
                                            />
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-white">{guest.name}</div>
                                            <div className="text-xs text-gray-500">{guest.email || 'Sem e-mail'}</div>
                                            <div className="text-xs text-gray-500">{guest.event_name || `Evento #${guest.event_id}`}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="bg-purple-900/40 text-purple-400 border border-purple-800/50 px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wide">
                                                {guest.status || 'esperado'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center justify-center gap-2">
                                                <button
                                                    onClick={() => handleCopyLink(guest.qr_code_token)}
                                                    className="p-1 px-2 bg-gray-800 hover:bg-gray-700 rounded border border-gray-700 text-xs flex items-center gap-1 transition-colors"
                                                    title="Copiar Link direto"
                                                >
                                                    <Copy size={12} /> Link
                                                </button>
                                                <a
                                                    href={`${window.location.origin}/invite?token=${guest.qr_code_token}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="p-1 px-2 border border-brand/30 text-brand-light hover:bg-brand/10 rounded text-xs flex items-center gap-1 transition-colors"
                                                >
                                                    <QrCode size={12} /> QR
                                                </a>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-1">
                                                <button
                                                    onClick={() => openEdit(guest)}
                                                    className="p-2 bg-gray-800 hover:bg-blue-900/40 text-blue-400 rounded-lg transition-colors border border-gray-700"
                                                    title="Editar"
                                                >
                                                    <Pencil size={14} />
                                                </button>
                                                <button
                                                    onClick={() => handleDelete(guest)}
                                                    className="p-2 bg-gray-800 hover:bg-red-900/40 text-red-500 rounded-lg transition-colors border border-gray-700 disabled:opacity-40"
                                                    disabled={deletingId === guest.id}
                                                    title="Excluir"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="5" className="px-6 py-12 text-center text-gray-500">
                                        Nenhum convidado encontrado.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <BulkMessageModal
                isOpen={isBulkModalOpen}
                onClose={() => setIsBulkModalOpen(false)}
                selectedParticipants={selectedGuests}
                type={bulkType}
            />

            <EditGuestModal
                isOpen={isEditModalOpen}
                guest={editingGuest}
                onClose={closeEdit}
                onSaved={fetchGuests}
            />

            {isImportModalOpen && (
                <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
                        <div className="p-6 border-b border-gray-800 flex justify-between items-center">
                            <h2 className="text-lg font-bold text-white flex items-center gap-2">
                                <FileDown size={18} className="text-brand" /> Importar Convidados via CSV
                            </h2>
                            <button onClick={closeImportModal} className="text-gray-400 hover:text-white transition-colors">
                                <X size={20} />
                            </button>
                        </div>

                        {importSummary ? (
                            <div className="p-6 space-y-4">
                                <div className="rounded-xl border border-gray-700 overflow-hidden divide-y divide-gray-700">
                                    <div className="flex items-center gap-3 p-4 bg-green-500/10">
                                        <CheckCircle size={22} className="text-green-400 shrink-0" />
                                        <div>
                                            <p className="text-white font-semibold">{importSummary.imported} convidado(s) inserido(s)</p>
                                            <p className="text-xs text-gray-400">Adicionados com sucesso</p>
                                        </div>
                                    </div>
                                    {importSummary.ignored > 0 && (
                                        <div className="flex items-center gap-3 p-4 bg-amber-500/10">
                                            <AlertCircle size={22} className="text-amber-400 shrink-0" />
                                            <div>
                                                <p className="text-white font-semibold">{importSummary.ignored} ignorado(s)</p>
                                                <p className="text-xs text-gray-400">Já existiam no evento</p>
                                            </div>
                                        </div>
                                    )}
                                    {importSummary.skipped > 0 && (
                                        <div className="flex items-center gap-3 p-4 bg-red-500/10">
                                            <AlertCircle size={22} className="text-red-400 shrink-0" />
                                            <div>
                                                <p className="text-white font-semibold">{importSummary.skipped} inválido(s)</p>
                                                <p className="text-xs text-gray-400">Linhas com erro de validação</p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <div className="flex gap-3">
                                    <button className="btn-secondary flex-1" onClick={closeImportModal}>Fechar</button>
                                    <button className="btn-primary flex-1" onClick={() => { setImportSummary(null); setImportFile(null); }}>
                                        Nova Importação
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <form onSubmit={handleImport} className="p-6 space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-gray-400 mb-1">Arquivo CSV *</label>
                                    <input
                                        type="file"
                                        className="input w-full"
                                        accept=".csv,text/csv"
                                        onChange={(e) => setImportFile(e.target.files?.[0] || null)}
                                    />
                                    <p className="text-xs text-gray-500 mt-1">
                                        Cabeçalho obrigatório: <code className="text-blue-400">name,email</code>. Opcional: <code>phone</code>.
                                    </p>
                                </div>
                                <div className="flex gap-3 pt-2">
                                    <button type="button" className="btn-secondary flex-1" onClick={closeImportModal}>
                                        Cancelar
                                    </button>
                                    <button type="submit" className="btn-primary flex-1" disabled={importing}>
                                        {importing ? <span className="spinner w-5 h-5" /> : 'Importar'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
