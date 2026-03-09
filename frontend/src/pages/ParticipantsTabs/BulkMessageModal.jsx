import { useState } from "react";
import { X, Send, MessageCircle, Mail } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

export default function BulkMessageModal({ isOpen, onClose, selectedParticipants, type = "whatsapp" }) {
    const [message, setMessage] = useState("");
    const [loading, setLoading] = useState(false);
    const resolveToken = (participant) => participant?.qr_token || participant?.qr_code_token || "";

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (selectedParticipants.length === 0) {
            toast.error("Nenhum participante selecionado.");
            return;
        }
        setLoading(true);

        try {
            if (type === "whatsapp") {
                const recipients = selectedParticipants.map(p => ({
                    phone: p.phone,
                    name: p.name,
                    link: `${window.location.origin}/invite?token=${resolveToken(p)}`
                }));
                const res = await api.post("/messaging/bulk-whatsapp", { recipients, message });
                toast.success(res.data.message || "Mensagens enviadas!");
            } else {
                // Email implementation (one by one or bulk if supported)
                for (const p of selectedParticipants) {
                    if (p.email) {
                        const token = resolveToken(p);
                        await api.post("/messaging/email", {
                            to: p.email,
                            subject: "Seu Convite - EnjoyFun",
                            message: message.replace("{{name}}", p.name).replace("{{link}}", `${window.location.origin}/invite?token=${token}`)
                        });
                    }
                }
                toast.success("E-mails enviados!");
            }
            onClose();
        } catch (error) {
            toast.error(error.response?.data?.message || "Erro ao enviar mensagens.");
        } finally {
            setLoading(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-scale-in">
                <div className="p-6 border-b border-gray-800 flex justify-between items-center bg-gray-900/50">
                    <h2 className="text-xl font-bold text-white flex items-center gap-2">
                        {type === "whatsapp" ? <MessageCircle className="text-green-500" /> : <Mail className="text-blue-500" />}
                        Disparo em Massa ({selectedParticipants.length})
                    </h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-white transition-colors">
                        <X size={24} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div className="bg-blue-900/20 border border-blue-800/30 p-3 rounded-lg text-xs text-blue-300">
                        <p className="font-semibold mb-1">Dica de Placeholders:</p>
                        <ul className="list-disc list-inside">
                            <li>Use <code>{"{{name}}"}</code> para o nome do participante</li>
                            <li>Use <code>{"{{link}}"}</code> para o link do convite/QR Code</li>
                        </ul>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-400 mb-1">Mensagem</label>
                        <textarea
                            required
                            rows={6}
                            className="input w-full resize-none"
                            placeholder="Olá {{name}}, aqui está seu convite: {{link}}"
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                        />
                    </div>

                    <div className="flex gap-3 pt-2">
                        <button type="button" onClick={onClose} className="btn-secondary flex-1">
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={loading || !message}
                            className={`btn-primary flex-1 flex items-center justify-center gap-2 ${type === 'whatsapp' ? 'bg-green-600 hover:bg-green-700' : ''}`}
                        >
                            {loading ? <div className="spinner-sm" /> : <Send size={18} />}
                            Enviar Agora
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
