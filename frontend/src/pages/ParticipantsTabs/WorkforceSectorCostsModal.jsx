import { useEffect, useMemo, useState } from "react";
import { X } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

const normalizeSector = (value = "") =>
  String(value || "")
    .toLowerCase()
    .trim()
    .replace(/\s+/g, "_");

export default function WorkforceSectorCostsModal({ isOpen, role, eventId, onClose }) {
  const [loading, setLoading] = useState(false);
  const [data, setData] = useState({
    mealUnitCost: 0,
    workersCount: 0,
    workersPaymentTotal: 0,
    workersMealsTotal: 0,
    workersTotalCost: 0,
    rolePaymentTotal: 0,
    roleMealsTotal: 0,
    roleTotalCost: 0,
    sectorGrandTotal: 0
  });

  const normalizedSector = normalizeSector(role?.sector || "");

  useEffect(() => {
    if (!isOpen || !role?.id || !eventId) return;

    setLoading(true);
    Promise.all([
      api.get(`/organizer-finance/workforce-costs?event_id=${eventId}&sector=${encodeURIComponent(normalizedSector)}`),
      api.get(`/workforce/role-settings/${role.id}`)
    ])
      .then(([costsRes, roleSettingsRes]) => {
        const costs = costsRes.data?.data || {};
        const roleSettings = roleSettingsRes.data?.data || {};
        const mealUnitCost = Number(costs.summary?.meal_unit_cost || 0);

        const members = Array.isArray(costs.operational_members) ? costs.operational_members : [];
        const membersInSector = members.filter((m) => normalizeSector(m.sector) === normalizedSector);

        const workersPaymentTotal = membersInSector.reduce(
          (acc, m) => acc + Number(m.estimated_payment_total || 0),
          0
        );
        const workersMealsTotal = membersInSector.reduce(
          (acc, m) => acc + Number(m.estimated_meals_total || 0),
          0
        );
        const workersTotalCost = workersPaymentTotal + workersMealsTotal * mealUnitCost;

        const rolePaymentAmount = Number(roleSettings.payment_amount || 0);
        const roleMaxShifts = Number(roleSettings.max_shifts_event || 0);
        const roleMealsPerDay = Number(roleSettings.meals_per_day || 0);

        const rolePaymentTotal = rolePaymentAmount * roleMaxShifts;
        const roleMealsTotal = roleMealsPerDay * roleMaxShifts;
        const roleTotalCost = rolePaymentTotal + roleMealsTotal * mealUnitCost;

        const sectorGrandTotal = workersTotalCost + roleTotalCost;

        setData({
          mealUnitCost,
          workersCount: membersInSector.length,
          workersPaymentTotal,
          workersMealsTotal,
          workersTotalCost,
          rolePaymentTotal,
          roleMealsTotal,
          roleTotalCost,
          sectorGrandTotal
        });
      })
      .catch((err) => {
        toast.error(err.response?.data?.message || "Erro ao carregar totais do setor.");
      })
      .finally(() => setLoading(false));
  }, [isOpen, role?.id, eventId, normalizedSector]);

  const labelSector = useMemo(
    () => (normalizedSector || "geral").replace(/_/g, " "),
    [normalizedSector]
  );

  if (!isOpen || !role) return null;

  return (
    <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-xl overflow-hidden">
        <div className="p-4 border-b border-gray-800 flex items-center justify-between">
          <div>
            <h3 className="text-white font-bold">Custos Totais do Setor</h3>
            <p className="text-xs text-gray-500 mt-1">
              Setor: {labelSector} | Cargo: {role.name}
            </p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
            <X size={18} />
          </button>
        </div>

        <div className="p-4 space-y-3">
          {loading ? (
            <div className="h-32 flex items-center justify-center">
              <div className="spinner w-7 h-7" />
            </div>
          ) : (
            <>
              <div className="rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-3">
                <p className="text-xs text-gray-400 uppercase tracking-wider">Trabalhadores (configuração em massa/individual)</p>
                <p className="text-sm text-gray-500 mt-1">
                  {Number(data.workersCount || 0).toLocaleString("pt-BR")} membros
                </p>
                <p className="text-sm text-gray-300 mt-2">
                  Pagamento: R$ {Number(data.workersPaymentTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm text-gray-300">
                  Refeições: {Number(data.workersMealsTotal || 0).toLocaleString("pt-BR")} x R$ {Number(data.mealUnitCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm font-semibold text-cyan-400 mt-2">
                  Subtotal Trabalhadores: R$ {Number(data.workersTotalCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
              </div>

              <div className="rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-3">
                <p className="text-xs text-gray-400 uppercase tracking-wider">Cargo</p>
                <p className="text-sm text-gray-300 mt-2">
                  Pagamento: R$ {Number(data.rolePaymentTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm text-gray-300">
                  Refeições: {Number(data.roleMealsTotal || 0).toLocaleString("pt-BR")} x R$ {Number(data.mealUnitCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm font-semibold text-amber-400 mt-2">
                  Subtotal Cargo: R$ {Number(data.roleTotalCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
              </div>

              <div className="rounded-xl border border-emerald-700/50 bg-emerald-900/10 px-3 py-3">
                <p className="text-xs text-emerald-400 uppercase tracking-wider">Total do Setor</p>
                <p className="text-xl font-bold text-emerald-300 mt-2">
                  R$ {Number(data.sectorGrandTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
              </div>
            </>
          )}

          <div className="flex justify-end pt-1">
            <button type="button" onClick={onClose} className="btn-secondary">
              Fechar
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
