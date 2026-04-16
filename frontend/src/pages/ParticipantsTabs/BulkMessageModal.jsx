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
        <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-scale-in">
                <div className="p-6 border-b border-slate-800/40 flex justify-between items-center">
                    <h2 className="text-xl font-bold text-slate-100 flex items-center gap-2">
                        {type === "whatsapp" ? <MessageCircle className="text-green-400" /> : <Mail className="text-cyan-400" />}
                        Disparo em Massa ({selectedParticipants.length})
                    </h2>
                    <button onClick={onClose} className="text-slate-400 hover:text-red-400 transition-colors">
                        <X size={24} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div className="bg-cyan-500/10 border border-cyan-500/20 p-3 rounded-xl text-xs text-cyan-300">
                        <p className="font-semibold mb-1">Dica de Placeholders:</p>
                        <ul className="list-disc list-inside">
                            <li>Use <code>{"{{name}}"}</code> para o nome do participante</li>
                            <li>Use <code>{"{{link}}"}</code> para o link do convite/QR Code</li>
                        </ul>
                    </div>

                    <div>
                        <label className="block text-xs text-slate-400 uppercase tracking-wider mb-1">Mensagem</label>
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
                        <button type="button" onClick={onClose} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-semibold transition-colors flex-1">
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={loading || !message}
                            className={`font-semibold rounded-xl px-4 py-2 flex-1 flex items-center justify-center gap-2 ${type === 'whatsapp' ? 'bg-gradient-to-r from-green-500 to-green-400 text-slate-950' : 'bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950'}`}
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
