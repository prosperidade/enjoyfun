import { useEffect, useMemo, useState } from "react";
import { Save, X } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

const DEFAULT_FORM = {
  max_shifts_event: 1,
  shift_hours: 8,
  meals_per_day: 4,
  payment_amount: 0,
  cost_bucket: "operational",
  leader_name: "",
  leader_cpf: "",
  leader_phone: ""
};

const normalizeSector = (value = "") =>
  String(value || "")
    .toLowerCase()
    .trim()
    .replace(/\s+/g, "_");

export default function WorkforceRoleSettingsModal({ isOpen, role, eventId, roleMembersCount = 0, onClose, onSaved }) {
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState(DEFAULT_FORM);
  const [sectorCostLoading, setSectorCostLoading] = useState(false);
  const [sectorCostSummary, setSectorCostSummary] = useState(null);
  const normalizedSector = normalizeSector(role?.sector || "");

  useEffect(() => {
    if (!isOpen || !role?.id) return;
    setLoading(true);
    api
      .get(`/workforce/role-settings/${role.id}`)
      .then((res) => {
        const d = res.data?.data || {};
        setForm({
          max_shifts_event: Number(d.max_shifts_event ?? 1),
          shift_hours: Number(d.shift_hours ?? 8),
          meals_per_day: Number(d.meals_per_day ?? 4),
          payment_amount: Number(d.payment_amount ?? 0),
          cost_bucket: d.cost_bucket === "managerial" ? "managerial" : "operational",
          leader_name: d.leader_name ?? "",
          leader_cpf: d.leader_cpf ?? "",
          leader_phone: d.leader_phone ?? ""
        });
      })
      .catch((err) => {
        toast.error(err.response?.data?.message || "Erro ao carregar configuração do cargo.");
      })
      .finally(() => setLoading(false));
  }, [isOpen, role?.id]);

  useEffect(() => {
    if (!isOpen || !role?.id || !eventId || !normalizedSector) {
      setSectorCostSummary(null);
      return;
    }

    setSectorCostLoading(true);
    api
      .get(`/organizer-finance/workforce-costs?event_id=${eventId}&sector=${encodeURIComponent(normalizedSector)}`)
      .then((res) => {
        const data = res.data?.data || {};
        const bySector = Array.isArray(data.by_sector) ? data.by_sector : [];
        const row =
          bySector.find((entry) => normalizeSector(entry.sector) === normalizedSector) ||
          bySector[0] ||
          null;
        if (!row) {
          setSectorCostSummary(null);
          return;
        }

        const paymentTotal = Number(row.estimated_payment_total || 0);
        const mealsTotal = Number(row.estimated_meals_total || 0);
        const members = Number(row.members || 0);
        const mealUnitCost = Number(data.summary?.meal_unit_cost || 0);
        const sectorTotal = paymentTotal + mealsTotal * mealUnitCost;

        setSectorCostSummary({
          sector: normalizeSector(row.sector) || normalizedSector,
          members,
          paymentTotal,
          mealsTotal,
          mealUnitCost,
          sectorTotal
        });
      })
      .catch(() => {
        setSectorCostSummary(null);
      })
      .finally(() => setSectorCostLoading(false));
  }, [isOpen, role?.id, eventId, normalizedSector]);

  const estimatedTotal = useMemo(() => {
    const shifts = Number(form.max_shifts_event || 0);
    const perShift = Number(form.payment_amount || 0);
    return shifts * perShift;
  }, [form.max_shifts_event, form.payment_amount]);

  const missingLeadSlot = useMemo(() => (Number(roleMembersCount || 0) > 0 ? 0 : 1), [roleMembersCount]);
  const projectedSectorTotal = useMemo(() => {
    const baseSectorTotal = Number(sectorCostSummary?.sectorTotal || 0);
    return baseSectorTotal + estimatedTotal * missingLeadSlot;
  }, [sectorCostSummary?.sectorTotal, estimatedTotal, missingLeadSlot]);
  const projectedSectorMembers = useMemo(() => {
    const baseMembers = Number(sectorCostSummary?.members || 0);
    return baseMembers + missingLeadSlot;
  }, [sectorCostSummary?.members, missingLeadSlot]);

  if (!isOpen || !role) return null;

  const save = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await api.put(`/workforce/role-settings/${role.id}`, form);
      toast.success("Configuração do cargo salva.");
      onSaved?.();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar configuração do cargo.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-xl max-h-[90vh] overflow-hidden flex flex-col">
        <div className="p-4 border-b border-gray-800 flex items-center justify-between flex-shrink-0">
          <div>
            <h3 className="text-white font-bold">Configuração por Cargo</h3>
            <p className="text-xs text-gray-500 mt-1">{role.name}</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={save} className="flex flex-col min-h-0">
          <div className="p-4 grid grid-cols-2 gap-3 overflow-y-auto min-h-0">
            <label className="text-xs text-gray-400 col-span-2">
              Nome do Gerente / Diretor
              <input
                type="text"
                className="input mt-1 w-full"
                value={form.leader_name}
                onChange={(e) => setForm((p) => ({ ...p, leader_name: e.target.value }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              CPF
              <input
                type="text"
                className="input mt-1 w-full"
                value={form.leader_cpf}
                onChange={(e) => setForm((p) => ({ ...p, leader_cpf: e.target.value }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              Celular
              <input
                type="text"
                className="input mt-1 w-full"
                value={form.leader_phone}
                onChange={(e) => setForm((p) => ({ ...p, leader_phone: e.target.value }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              Número de Turnos
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
                min="0"
                step="0.5"
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
                min="0"
                step="0.01"
                className="input mt-1 w-full"
                value={form.payment_amount}
                onChange={(e) => setForm((p) => ({ ...p, payment_amount: Number(e.target.value) }))}
              />
            </label>

            <label className="text-xs text-gray-400 col-span-2">
              Tipo de Custo
              <select
                className="input mt-1 w-full"
                value={form.cost_bucket}
                onChange={(e) => setForm((p) => ({ ...p, cost_bucket: e.target.value }))}
              >
                <option value="operational">Membro operacional</option>
                <option value="managerial">Cargo gerencial/diretivo</option>
              </select>
            </label>

            <div className="col-span-2 rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2">
              <p className="text-[11px] text-gray-400">
                Total estimado por membro neste cargo = Valor por Turno x Número de Turnos
              </p>
              <p className="text-sm font-semibold text-emerald-400 mt-1">
                R$ {Number(estimatedTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
              </p>
            </div>

            <div className="col-span-2 rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2">
              <p className="text-[11px] text-gray-400">
                Custo total do setor ({(normalizedSector || "geral").replace(/_/g, " ")}) incluindo este cargo
              </p>
              {sectorCostLoading ? (
                <p className="text-sm text-gray-500 mt-1">Carregando custo do setor...</p>
              ) : (
                <>
                  <p className="text-xs text-gray-500 mt-1">
                    Base atual: R$ {Number(sectorCostSummary?.sectorTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })} | Membros:{" "}
                    {Number(sectorCostSummary?.members || 0).toLocaleString("pt-BR")}
                  </p>
                  <p className="text-sm font-semibold text-cyan-400 mt-1">
                    Projeção com o cargo: R$ {Number(projectedSectorTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                  </p>
                  <p className="text-[11px] text-gray-500 mt-1">
                    Membros projetados no setor: {Number(projectedSectorMembers || 0).toLocaleString("pt-BR")}
                    {missingLeadSlot > 0 ? " (inclui 1 posição-base deste cargo)" : ""}
                  </p>
                </>
              )}
            </div>
          </div>

          <div className="flex justify-end gap-2 p-4 border-t border-gray-800 flex-shrink-0">
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
