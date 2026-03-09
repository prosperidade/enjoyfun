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
    <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
        <div className="p-6 border-b border-gray-800 flex justify-between items-center">
          <h2 className="text-lg font-bold text-white flex items-center gap-2">
            <Pencil size={18} className="text-brand" /> Editar Convidado
          </h2>
          <button onClick={onClose} className="text-gray-400 hover:text-white transition-colors">
            <X size={20} />
          </button>
        </div>

        <form onSubmit={handleSave} className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-semibold text-gray-400 mb-1">Nome</label>
            <input
              type="text"
              className="input w-full"
              value={form.holder_name}
              onChange={(e) => setForm((prev) => ({ ...prev, holder_name: e.target.value }))}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-400 mb-1">E-mail</label>
            <input
              type="email"
              className="input w-full"
              value={form.holder_email}
              onChange={(e) => setForm((prev) => ({ ...prev, holder_email: e.target.value }))}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-400 mb-1">Telefone</label>
            <input
              type="text"
              className="input w-full"
              value={form.holder_phone}
              onChange={(e) => setForm((prev) => ({ ...prev, holder_phone: e.target.value }))}
            />
          </div>

          <div className="flex gap-3 pt-2">
            <button type="button" className="btn-secondary flex-1" onClick={onClose}>
              Cancelar
            </button>
            <button type="submit" className="btn-primary flex-1 flex items-center justify-center gap-2" disabled={saving}>
              <Save size={16} /> {saving ? "Salvando..." : "Salvar"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
