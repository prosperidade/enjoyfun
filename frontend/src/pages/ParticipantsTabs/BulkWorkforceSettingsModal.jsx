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
    <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
        <div className="p-4 border-b border-slate-800/40 flex items-center justify-between">
          <div>
            <h3 className="text-slate-100 font-bold">Configuração em Massa</h3>
            <p className="text-xs text-slate-500 mt-1">{participants.length} membros selecionados</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-slate-800/50">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={applyBulk} className="p-5 grid grid-cols-2 gap-4">
          <label className="text-xs text-slate-400 uppercase tracking-wider">
            Turnos no Evento
            <input
              type="number"
              min="0"
              className="input mt-1 w-full"
              value={form.max_shifts_event}
              onChange={(e) => setForm((p) => ({ ...p, max_shifts_event: Number(e.target.value) }))}
            />
          </label>
          <label className="text-xs text-slate-400 uppercase tracking-wider">
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
          <label className="text-xs text-slate-400 uppercase tracking-wider">
            Refeições por Dia
            <input
              type="number"
              min="0"
              className="input mt-1 w-full"
              value={form.meals_per_day}
              onChange={(e) => setForm((p) => ({ ...p, meals_per_day: Number(e.target.value) }))}
            />
          </label>
          <label className="text-xs text-slate-400 uppercase tracking-wider">
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
            <button type="button" onClick={onClose} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-semibold transition-colors">Cancelar</button>
            <button type="submit" disabled={loading} className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex items-center gap-2">
              <Save size={16} /> Aplicar em Massa
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
