import { useEffect, useState } from "react";
import { Pencil, Save, X } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

const INITIAL_FORM = {
  holder_name: "",
  holder_email: "",
  holder_phone: ""
};

export default function EditGuestModal({ isOpen, guest, onClose, onSaved }) {
  const [form, setForm] = useState(INITIAL_FORM);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!isOpen || !guest) return;
    setForm({
      holder_name: guest.name || "",
      holder_email: guest.email || "",
      holder_phone: guest.phone || ""
    });
  }, [isOpen, guest]);

  if (!isOpen || !guest) return null;

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.put(`/guests/${guest.id}`, form);
      toast.success("Convidado atualizado.");
      onSaved?.();
      onClose();
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao atualizar convidado.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
        <div className="p-6 border-b border-slate-800/40 flex justify-between items-center">
          <h2 className="text-lg font-bold text-slate-100 flex items-center gap-2">
            <Pencil size={18} className="text-cyan-400" /> Editar Convidado
          </h2>
          <button onClick={onClose} className="text-slate-400 hover:text-red-400 transition-colors">
            <X size={20} />
          </button>
        </div>

        <form onSubmit={handleSave} className="p-6 space-y-4">
          <div>
            <label className="block text-xs text-slate-400 uppercase tracking-wider mb-1">Nome</label>
            <input
              type="text"
              className="input w-full"
              value={form.holder_name}
              onChange={(e) => setForm((prev) => ({ ...prev, holder_name: e.target.value }))}
            />
          </div>

          <div>
            <label className="block text-xs text-slate-400 uppercase tracking-wider mb-1">E-mail</label>
            <input
              type="email"
              className="input w-full"
              value={form.holder_email}
              onChange={(e) => setForm((prev) => ({ ...prev, holder_email: e.target.value }))}
            />
          </div>

          <div>
            <label className="block text-xs text-slate-400 uppercase tracking-wider mb-1">Telefone</label>
            <input
              type="text"
              className="input w-full"
              value={form.holder_phone}
              onChange={(e) => setForm((prev) => ({ ...prev, holder_phone: e.target.value }))}
            />
          </div>

          <div className="flex gap-3 pt-2">
            <button type="button" className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-semibold transition-colors flex-1" onClick={onClose}>
              Cancelar
            </button>
            <button type="submit" className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex-1 flex items-center justify-center gap-2" disabled={saving}>
              <Save size={16} /> {saving ? "Salvando..." : "Salvar"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
