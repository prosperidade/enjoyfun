import { useState, useEffect } from 'react';
import { Search, Plus, UserCheck, ShieldCheck, Mail, QrCode, FileDown, MessageCircle, Copy, Pencil, Trash2, CheckCircle, AlertCircle } from 'lucide-react';
import api from '../../lib/api';
import toast from 'react-hot-toast';
import AddParticipantModal from './AddParticipantModal';
import CsvImportModal from './CsvImportModal';
import EditParticipantModal from './EditParticipantModal';
import BulkMessageModal from './BulkMessageModal';

export default function GuestManagementTab({ eventId }) {
    const [guests, setGuests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    
    // Modals
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isBulkModalOpen, setIsBulkModalOpen] = useState(false);
    
    // Selection & Data
    const [selectedParticipant, setSelectedParticipant] = useState(null);
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkType, setBulkType] = useState('whatsapp');

    const fetchGuests = async () => {
        setLoading(true);
        try {
            const res = await api.get(`/participants?event_id=${eventId}`);
            const filtered = res.data.data?.filter(p => p.category_type !== 'staff') || [];
            setGuests(filtered);
            setSelectedIds([]); // Reset selection on reload
        } catch (error) {
            console.error(error);
            toast.error("Erro ao carregar convidados.");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (eventId) fetchGuests();
    }, [eventId]);

    const filteredData = guests.filter(g => 
        g.name.toLowerCase().includes(search.toLowerCase()) || 
        g.email?.toLowerCase().includes(search.toLowerCase())
    );

    const toggleSelectAll = () => {
        if (selectedIds.length === filteredData.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(filteredData.map(g => g.participant_id));
        }
    };

    const toggleSelect = (id) => {
        setSelectedIds(prev => prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]);
    };

    const handleCopyLink = async (token) => {
        const url = `${window.location.origin}/invite?token=${token}`;
        try {
            await navigator.clipboard.writeText(url);
            toast.success("Link copiado!");
        } catch {
            toast.error("Erro ao copiar.");
        }
    };

    const handleDelete = async (participant) => {
        if (!window.confirm(`Remover ${participant.name} deste evento?`)) return;
        try {
            await api.delete(`/participants/${participant.participant_id}`);
            toast.success("Participante removido.");
            fetchGuests();
        } catch (error) {
            toast.error("Erro ao excluir.");
        }
    };

    const openBulk = (type) => {
        if (selectedIds.length === 0) {
            toast.error("Selecione participantes primeiro.");
            return;
        }
        setBulkType(type);
        setIsBulkModalOpen(true);
    };

    return (
        <div className="space-y-6 animate-fade-in">
            {/* Bulk Actions Header */}
            {selectedIds.length > 0 && (
                <div className="bg-brand/10 border border-brand/20 p-4 rounded-2xl flex items-center justify-between animate-slide-up">
                    <div className="flex items-center gap-3">
                        <CheckCircle className="text-brand" size={20} />
                        <span className="text-brand font-semibold">{selectedIds.length} selecionados</span>
                    </div>
                    <div className="flex gap-2">
                        <button onClick={() => openBulk('whatsapp')} className="btn-primary bg-green-600 hover:bg-green-700 h-9 px-4 text-xs flex items-center gap-2">
                            <MessageCircle size={14} /> WhatsApp em Massa
                        </button>
                        <button onClick={() => openBulk('email')} className="btn-secondary h-9 px-4 text-xs flex items-center gap-2">
                            <Mail size={14} /> E-mail em Massa
                        </button>
                        <button onClick={() => setSelectedIds([])} className="p-2 text-gray-400 hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            {/* Action Bar */}
            <div className="flex flex-col sm:flex-row gap-4 items-center justify-between">
                <div className="relative w-full sm:w-96">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
                    <input 
                        type="text" 
                        placeholder="Buscar por nome ou e-mail..." 
                        className="input pl-10 w-full"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="flex gap-3 w-full sm:w-auto">
                     <button 
                        onClick={() => setIsImportModalOpen(true)}
                        className="btn-secondary flex items-center justify-center gap-2 flex-1 sm:flex-none whitespace-nowrap"
                    >
                        <FileDown size={18} /> <span className="hidden sm:inline">Importar CSV</span>
                    </button>
                    <button 
                        onClick={() => setIsAddModalOpen(true)}
                        className="btn-primary flex items-center justify-center gap-2 flex-1 sm:flex-none whitespace-nowrap"
                    >
                        <Plus size={18} /> Adicionar <span className="hidden sm:inline">Convidado</span>
                    </button>
                </div>
            </div>

            {/* List */}
            <div className="card overflow-hidden p-0 border border-gray-800">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-gray-300">
                        <thead className="bg-gray-900/80 text-gray-400 uppercase text-xs border-b border-gray-800">
                            <tr>
                                <th className="px-6 py-4 w-10">
                                    <input 
                                        type="checkbox" 
                                        className="checkbox"
                                        checked={selectedIds.length > 0 && selectedIds.length === filteredData.length}
                                        onChange={toggleSelectAll}
                                    />
                                </th>
                                <th className="px-6 py-4 font-semibold">Participante</th>
                                <th className="px-6 py-4 font-semibold">Categoria</th>
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
                                    <tr key={guest.participant_id} className={`hover:bg-gray-800/30 transition-colors ${selectedIds.includes(guest.participant_id) ? 'bg-brand/5' : ''}`}>
                                        <td className="px-6 py-4">
                                            <input 
                                                type="checkbox" 
                                                className="checkbox"
                                                checked={selectedIds.includes(guest.participant_id)}
                                                onChange={() => toggleSelect(guest.participant_id)}
                                            />
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="w-10 h-10 rounded-full bg-brand/20 text-brand flex items-center justify-center font-bold">
                                                    {guest.name.charAt(0).toUpperCase()}
                                                </div>
                                                <div>
                                                    <div className="font-semibold text-white">{guest.name}</div>
                                                    <div className="text-xs text-gray-500">{guest.phone || 'Sem Telefone'}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="bg-purple-900/40 text-purple-400 border border-purple-800/50 px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wide">
                                                {guest.category_name}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center justify-center gap-2">
                                                <button 
                                                    onClick={() => handleCopyLink(guest.qr_token)}
                                                    className="p-1 px-2 bg-gray-800 hover:bg-gray-700 rounded border border-gray-700 text-xs flex items-center gap-1 transition-colors"
                                                    title="Copiar Link direto"
                                                >
                                                    <Copy size={12} /> Link
                                                </button>
                                                <a 
                                                    href={`${window.location.origin}/invite?token=${guest.qr_token}`}
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
                                                    onClick={() => { setSelectedParticipant(guest); setIsEditModalOpen(true); }}
                                                    className="p-2 bg-gray-800 hover:bg-gray-700 text-blue-400 rounded-lg transition-colors border border-gray-700" 
                                                    title="Editar"
                                                >
                                                    <Pencil size={14} />
                                                </button>
                                                <button 
                                                    onClick={() => handleDelete(guest)}
                                                    className="p-2 bg-gray-800 hover:bg-red-900/40 text-red-500 rounded-lg transition-colors border border-gray-700" 
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

            <AddParticipantModal 
                isOpen={isAddModalOpen} 
                onClose={() => setIsAddModalOpen(false)} 
                eventId={eventId} 
                onAdded={fetchGuests}
            />
            
            <CsvImportModal
                isOpen={isImportModalOpen}
                onClose={() => setIsImportModalOpen(false)}
                eventId={eventId}
                mode="guest"
                onImported={fetchGuests}
            />

            <EditParticipantModal
                isOpen={isEditModalOpen}
                onClose={() => { setIsEditModalOpen(false); setSelectedParticipant(null); }}
                participant={selectedParticipant}
                onUpdated={fetchGuests}
            />

            <BulkMessageModal
                isOpen={isBulkModalOpen}
                onClose={() => setIsBulkModalOpen(false)}
                selectedParticipants={guests.filter(g => selectedIds.includes(g.participant_id))}
                type={bulkType}
            />
        </div>
    );
}
