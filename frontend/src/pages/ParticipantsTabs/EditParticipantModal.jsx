import { useState, useEffect } from "react";
import { X, Save } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

export default function EditParticipantModal({ isOpen, onClose, participant, onUpdated }) {
    const [formData, setFormData] = useState({
        name: "",
        email: "",
        phone: "",
        category_id: ""
    });
    const [categories, setCategories] = useState([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (isOpen && participant) {
            setFormData({
                name: participant.name || "",
                email: participant.email || "",
                phone: participant.phone || "",
                category_id: participant.category_id || ""
            });
            fetchCategories();
        }
    }, [isOpen, participant]);

    const fetchCategories = async () => {
        try {
            const res = await api.get("/participants/categories");
            setCategories(res.data.data || []);
        } catch (error) {
            console.error(error);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        try {
            await api.put(`/participants/${participant.participant_id}`, formData);
            toast.success("Participante atualizado!");
            onUpdated();
            onClose();
        } catch (error) {
            toast.error(error.response?.data?.message || "Erro ao atualizar participante.");
        } finally {
            setLoading(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl animate-scale-in">
                <div className="p-6 border-b border-gray-800 flex justify-between items-center">
                    <h2 className="text-xl font-bold text-white">Editar Participante</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-white transition-colors">
                        <X size={24} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-400 mb-1">Nome Completo</label>
                        <input
                            type="text"
                            required
                            className="input w-full"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-400 mb-1">E-mail</label>
                            <input
                                type="email"
                                className="input w-full"
                                value={formData.email}
                                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-400 mb-1">Telefone</label>
                            <input
                                type="text"
                                className="input w-full"
                                value={formData.phone}
                                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-400 mb-1">Categoria</label>
                        <select
                            required
                            className="select w-full"
                            value={formData.category_id}
                            onChange={(e) => setFormData({ ...formData, category_id: e.target.value })}
                        >
                            <option value="">Selecione uma categoria...</option>
                            {categories.map(cat => (
                                <option key={cat.id} value={cat.id}>{cat.name} ({cat.type})</option>
                            ))}
                        </select>
                    </div>

                    <div className="flex gap-3 pt-4">
                        <button
                            type="button"
                            onClick={onClose}
                            className="btn-secondary flex-1"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={loading}
                            className="btn-primary flex-1 flex items-center justify-center gap-2"
                        >
                            {loading ? <div className="spinner-sm" /> : <Save size={18} />}
                            Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
