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
                <div className="bg-cyan-500/10 border border-cyan-500/20 p-4 rounded-xl flex items-center justify-between animate-slide-up">
                    <div className="flex items-center gap-3">
                        <CheckCircle className="text-cyan-400" size={20} />
                        <span className="text-cyan-400 font-semibold">{selectedIds.length} selecionados</span>
                    </div>
                    <div className="flex gap-2">
                        <button onClick={() => openBulk('whatsapp')} className="bg-green-500/15 text-green-400 border border-green-500/30 hover:bg-green-500/25 h-9 px-4 text-xs flex items-center gap-2 rounded-xl font-semibold transition-colors">
                            <MessageCircle size={14} /> WhatsApp
                        </button>
                        <button onClick={() => openBulk('email')} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 h-9 px-4 text-xs flex items-center gap-2 rounded-xl font-semibold transition-colors">
                            <Mail size={14} /> Email
                        </button>
                        <button
                            onClick={handleBulkDelete}
                            disabled={bulkDeleting}
                            className="bg-red-500/15 text-red-400 border border-red-500/30 hover:bg-red-500/25 h-9 px-4 text-xs flex items-center gap-2 rounded-xl font-semibold transition-colors"
                        >
                            <Trash2 size={14} /> {bulkDeleting ? 'Deletando...' : 'Delete'}
                        </button>
                        <button onClick={() => setSelectedIds([])} className="p-2 text-slate-400 hover:text-slate-100 transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            <div className="flex flex-col sm:flex-row gap-4 items-center justify-between">
                <div className="relative w-full sm:w-96">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                    <input
                        type="text"
                        placeholder="Buscar convidados por nome ou e-mail..."
                        className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl pl-10 w-full px-3 py-2 text-sm text-slate-100 outline-none transition-colors"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="flex gap-2 w-full sm:w-auto">
                    <button
                        onClick={() => setIsImportModalOpen(true)}
                        className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl h-10 px-4 text-xs flex items-center gap-2 w-full sm:w-auto justify-center font-semibold transition-colors"
                    >
                        <FileDown size={14} /> Importar CSV
                    </button>
                </div>
            </div>

            <div className="bg-[#111827] border border-slate-800/40 rounded-2xl overflow-hidden p-0">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-slate-300">
                        <thead className="bg-slate-800/50 text-slate-400 uppercase text-xs tracking-wider border-b border-slate-800/40">
                            <tr>
                                <th className="px-6 py-4 w-10">
                                    <input
                                        type="checkbox"
                                        className="accent-cyan-500"
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
                        <tbody className="divide-y divide-slate-800/40">
                            {loading ? (
                                <tr>
                                    <td colSpan="5" className="px-6 py-12 text-center">
                                        <div className="spinner mx-auto" />
                                    </td>
                                </tr>
                            ) : filteredData.length > 0 ? (
                                filteredData.map((guest) => (
                                    <tr key={guest.id} className={`hover:bg-slate-800/30 transition-colors ${selectedIds.includes(guest.id) ? 'bg-cyan-500/5' : ''}`}>
                                        <td className="px-6 py-4">
                                            <input
                                                type="checkbox"
                                                className="accent-cyan-500"
                                                checked={selectedIds.includes(guest.id)}
                                                onChange={() => toggleSelect(guest.id)}
                                            />
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-slate-100">{guest.name}</div>
                                            <div className="text-xs text-slate-500">{guest.email || 'Sem e-mail'}</div>
                                            <div className="text-xs text-slate-500">{guest.event_name || `Evento #${guest.event_id}`}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="bg-cyan-500/15 text-cyan-400 border border-cyan-500/30 px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wide">
                                                {guest.status || 'esperado'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center justify-center gap-2">
                                                <button
                                                    onClick={() => handleCopyLink(guest.qr_code_token)}
                                                    className="p-1 px-2 bg-slate-800/50 hover:bg-slate-700/50 rounded-lg border border-slate-700/50 text-xs flex items-center gap-1 transition-colors text-slate-300"
                                                    title="Copiar Link direto"
                                                >
                                                    <Copy size={12} /> Link
                                                </button>
                                                <a
                                                    href={`${window.location.origin}/invite?token=${guest.qr_code_token}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="p-1 px-2 border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 rounded-lg text-xs flex items-center gap-1 transition-colors"
                                                >
                                                    <QrCode size={12} /> QR
                                                </a>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-1">
                                                <button
                                                    onClick={() => openEdit(guest)}
                                                    className="p-2 bg-slate-800/50 hover:bg-cyan-500/10 text-cyan-400 rounded-lg transition-colors border border-slate-700/50 hover:border-cyan-500/30"
                                                    title="Editar"
                                                >
                                                    <Pencil size={14} />
                                                </button>
                                                <button
                                                    onClick={() => handleDelete(guest)}
                                                    className="p-2 bg-slate-800/50 hover:bg-red-500/10 text-red-400 rounded-lg transition-colors border border-slate-700/50 hover:border-red-500/30 disabled:opacity-40"
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
                                    <td colSpan="5" className="px-6 py-12 text-center text-slate-500">
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
                <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
                        <div className="p-6 border-b border-slate-800/40 flex justify-between items-center">
                            <h2 className="text-lg font-bold text-slate-100 flex items-center gap-2">
                                <FileDown size={18} className="text-cyan-400" /> Importar Convidados via CSV
                            </h2>
                            <button onClick={closeImportModal} className="text-slate-400 hover:text-red-400 transition-colors">
                                <X size={20} />
                            </button>
                        </div>

                        {importSummary ? (
                            <div className="p-6 space-y-4">
                                <div className="rounded-xl border border-slate-700/50 overflow-hidden divide-y divide-slate-700/50">
                                    <div className="flex items-center gap-3 p-4 bg-green-500/10">
                                        <CheckCircle size={22} className="text-green-400 shrink-0" />
                                        <div>
                                            <p className="text-slate-100 font-semibold">{importSummary.imported} convidado(s) inserido(s)</p>
                                            <p className="text-xs text-slate-400">Adicionados com sucesso</p>
                                        </div>
                                    </div>
                                    {importSummary.ignored > 0 && (
                                        <div className="flex items-center gap-3 p-4 bg-amber-500/10">
                                            <AlertCircle size={22} className="text-amber-400 shrink-0" />
                                            <div>
                                                <p className="text-slate-100 font-semibold">{importSummary.ignored} ignorado(s)</p>
                                                <p className="text-xs text-slate-400">Já existiam no evento</p>
                                            </div>
                                        </div>
                                    )}
                                    {importSummary.skipped > 0 && (
                                        <div className="flex items-center gap-3 p-4 bg-red-500/10">
                                            <AlertCircle size={22} className="text-red-400 shrink-0" />
                                            <div>
                                                <p className="text-slate-100 font-semibold">{importSummary.skipped} inválido(s)</p>
                                                <p className="text-xs text-slate-400">Linhas com erro de validação</p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <div className="flex gap-3">
                                    <button className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-semibold transition-colors flex-1" onClick={closeImportModal}>Fechar</button>
                                    <button className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex-1" onClick={() => { setImportSummary(null); setImportFile(null); }}>
                                        Nova Importação
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <form onSubmit={handleImport} className="p-6 space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Arquivo CSV *</label>
                                    <input
                                        type="file"
                                        className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl w-full px-3 py-2 text-sm text-slate-100"
                                        accept=".csv,text/csv"
                                        onChange={(e) => setImportFile(e.target.files?.[0] || null)}
                                    />
                                    <p className="text-xs text-slate-500 mt-1">
                                        Cabeçalho obrigatório: <code className="text-blue-400">name,email</code>. Opcional: <code>phone</code>.
                                    </p>
                                </div>
                                <div className="flex gap-3 pt-2">
                                    <button type="button" className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-semibold transition-colors flex-1" onClick={closeImportModal}>
                                        Cancelar
                                    </button>
                                    <button type="submit" className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex-1" disabled={importing}>
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
