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
    <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden">
        <div className="p-4 border-b border-gray-800 flex items-center justify-between">
          <div>
            <h3 className="text-white font-bold">Configuração Operacional</h3>
            <p className="text-xs text-gray-500 mt-1">{participant.name}</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={save} className="p-5 grid grid-cols-2 gap-4">
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
            Valor de Pagamento (R$)
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
              <Save size={16} /> Salvar
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

