import { useEffect, useState } from "react";
import { Save, X } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

export default function WorkforceMemberSettingsModal({ isOpen, onClose, participant, onSaved }) {
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({
    max_shifts_event: 1,
    shift_hours: 8,
    meals_per_day: 4,
    payment_amount: 0
  });

  useEffect(() => {
    if (!isOpen || !participant?.participant_id) return;
    setLoading(true);
    api
      .get(`/workforce/member-settings/${participant.participant_id}`)
      .then((res) => {
        const d = res.data?.data || {};
        setForm({
          max_shifts_event: d.max_shifts_event ?? 1,
          shift_hours: d.shift_hours ?? 8,
          meals_per_day: d.meals_per_day ?? 4,
          payment_amount: d.payment_amount ?? 0
        });
      })
      .catch((err) => {
        toast.error(err.response?.data?.message || "Erro ao carregar configuração do membro.");
      })
      .finally(() => setLoading(false));
  }, [isOpen, participant?.participant_id]);

  if (!isOpen || !participant) return null;

  const save = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await api.put(`/workforce/member-settings/${participant.participant_id}`, form);
      toast.success("Configuração do membro salva.");
      onSaved?.();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar configuração.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
        <div className="p-4 border-b border-slate-800/40 flex items-center justify-between">
          <div>
            <h3 className="text-slate-100 font-bold">Configuração Operacional</h3>
            <p className="text-xs text-slate-500 mt-1">{participant.name}</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-slate-800/50">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={save} className="p-5 grid grid-cols-2 gap-4">
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
              <Save size={16} /> Salvar
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
