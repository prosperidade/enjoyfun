import { useState } from "react";
import { Save, X } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

export default function BulkWorkforceSettingsModal({ isOpen, onClose, participants = [], onSaved }) {
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({
    max_shifts_event: 1,
    shift_hours: 8,
    meals_per_day: 4,
    payment_amount: 0
  });

  if (!isOpen) return null;

  const applyBulk = async (e) => {
    e.preventDefault();
    if (participants.length === 0) {
      toast.error("Nenhum membro selecionado.");
      return;
    }

    setLoading(true);
    try {
      const results = await Promise.allSettled(
        participants.map((p) =>
          api.put(`/workforce/member-settings/${p.participant_id}`, form)
        )
      );

      const success = results.filter((r) => r.status === "fulfilled").length;
      const failed = results.length - success;

      if (failed > 0) {
        toast.error(`Configuração aplicada em ${success} membros. ${failed} falharam.`);
      } else {
        toast.success(`Configuração aplicada em ${success} membros.`);
      }

      onSaved?.();
      onClose();
    } catch {
      toast.error("Erro ao aplicar configuração em massa.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden">
        <div className="p-4 border-b border-gray-800 flex items-center justify-between">
          <div>
            <h3 className="text-white font-bold">Configuração em Massa</h3>
            <p className="text-xs text-gray-500 mt-1">{participants.length} membros selecionados</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={applyBulk} className="p-5 grid grid-cols-2 gap-4">
          <label className="text-xs text-gray-400">
            Turnos no Evento
            <input
              type="number"
              min="0"
              className="input mt-1 w-full"
              value={form.max_shifts_event}
              onChange={(e) => setForm((p) => ({ ...p, max_shifts_event: Number(e.target.value) }))}
            />
          </label>
          <label className="text-xs text-gray-400">
            Horas por Turno
            <input
              type="number"
              step="0.5"
              min="0"
              className="input mt-1 w-full"
              value={form.shift_hours}
              onChange={(e) => setForm((p) => ({ ...p, shift_hours: Number(e.target.value) }))}
            />
          </label>
          <label className="text-xs text-gray-400">
            Refeições por Dia
            <input
              type="number"
              min="0"
              className="input mt-1 w-full"
              value={form.meals_per_day}
              onChange={(e) => setForm((p) => ({ ...p, meals_per_day: Number(e.target.value) }))}
            />
          </label>
          <label className="text-xs text-gray-400">
            Valor por Turno (R$)
            <input
              type="number"
              step="0.01"
              min="0"
              className="input mt-1 w-full"
              value={form.payment_amount}
              onChange={(e) => setForm((p) => ({ ...p, payment_amount: Number(e.target.value) }))}
            />
          </label>

          <div className="col-span-2 flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn-secondary">Cancelar</button>
            <button type="submit" disabled={loading} className="btn-primary flex items-center gap-2">
              <Save size={16} /> Aplicar em Massa
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
