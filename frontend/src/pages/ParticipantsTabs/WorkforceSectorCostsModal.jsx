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
    plannedMembersTotal: 0,
    filledMembersTotal: 0,
    presentMembersTotal: null,
    workersCount: 0,
    leadershipPositionsTotal: 0,
    leadershipFilledTotal: 0,
    leadershipPlaceholderTotal: 0,
    workersPaymentTotal: 0,
    workersMealsTotal: 0,
    workersTotalCost: 0,
    leadershipPaymentTotal: 0,
    leadershipMealsTotal: 0,
    leadershipTotalCost: 0,
    sectorGrandTotal: 0
  });

  const normalizedSector = normalizeSector(role?.sector || "");

  useEffect(() => {
    if (!isOpen || !role?.id || !eventId) return;

    let cancelled = false;

    const loadSectorCosts = async () => {
      setLoading(true);
      try {
        const costsRes = await api.get(
          `/organizer-finance/workforce-costs?event_id=${eventId}&sector=${encodeURIComponent(normalizedSector)}`
        );
        if (cancelled) return;

        const costs = costsRes.data?.data || {};
        const mealUnitCost = Number(costs.summary?.meal_unit_cost || 0);
        const bySector = Array.isArray(costs.by_sector) ? costs.by_sector : [];
        const sectorRow =
          bySector.find((entry) => normalizeSector(entry.sector) === normalizedSector) ||
          bySector[0] ||
          {};

        const members = Array.isArray(costs.operational_members) ? costs.operational_members : [];
        const membersInSector = members.filter((m) => normalizeSector(m.sector) === normalizedSector);
        const managerialRows = Array.isArray(costs.by_role_managerial)
          ? costs.by_role_managerial.filter((row) => normalizeSector(row.sector) === normalizedSector)
          : [];

        const workersPaymentTotal = membersInSector.reduce(
          (acc, m) => acc + Number(m.estimated_payment_total || 0),
          0
        );
        const workersMealsTotal = membersInSector.reduce(
          (acc, m) => acc + Number(m.estimated_meals_total || 0),
          0
        );
        const workersTotalCost = workersPaymentTotal + workersMealsTotal * mealUnitCost;
        const leadershipPaymentTotal = managerialRows.reduce(
          (acc, row) => acc + Number(row.estimated_payment_total || 0),
          0
        );
        const leadershipMealsTotal = managerialRows.reduce(
          (acc, row) => acc + Number(row.estimated_meals_total || 0),
          0
        );
        const leadershipTotalCost = leadershipPaymentTotal + leadershipMealsTotal * mealUnitCost;
        const sectorGrandTotal =
          Number(sectorRow.estimated_payment_total || 0) +
          Number(sectorRow.estimated_meals_total || 0) * mealUnitCost;

        setData({
          mealUnitCost,
          plannedMembersTotal: Number(sectorRow.planned_members_total || sectorRow.members || 0),
          filledMembersTotal: Number(sectorRow.filled_members_total || 0),
          presentMembersTotal:
            sectorRow.present_members_total === null || sectorRow.present_members_total === undefined
              ? null
              : Number(sectorRow.present_members_total || 0),
          workersCount: Number(sectorRow.operational_members_total || membersInSector.length),
          leadershipPositionsTotal: Number(sectorRow.leadership_positions_total || 0),
          leadershipFilledTotal: Number(sectorRow.leadership_filled_total || 0),
          leadershipPlaceholderTotal: Number(sectorRow.leadership_placeholder_total || 0),
          workersPaymentTotal,
          workersMealsTotal,
          workersTotalCost,
          leadershipPaymentTotal,
          leadershipMealsTotal,
          leadershipTotalCost,
          sectorGrandTotal
        });
      } catch (err) {
        if (!cancelled) {
          toast.error(err.response?.data?.message || "Erro ao carregar totais do setor.");
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    loadSectorCosts();

    return () => {
      cancelled = true;
    };
  }, [isOpen, role?.id, eventId, normalizedSector]);

  const labelSector = useMemo(
    () => (normalizedSector || "geral").replace(/_/g, " "),
    [normalizedSector]
  );

  if (!isOpen || !role) return null;

  return (
    <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-xl overflow-hidden shadow-2xl">
        <div className="p-4 border-b border-slate-800/40 flex items-center justify-between">
          <div>
            <h3 className="text-slate-100 font-bold">Custos Totais do Setor</h3>
            <p className="text-xs text-slate-500 mt-1">
              Setor: {labelSector} | Cargo: {role.name}
            </p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-slate-800/50">
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
              <div className="rounded-xl border border-slate-800/40 bg-slate-950/60 px-3 py-3">
                <p className="text-xs text-slate-400 uppercase tracking-wider">Operação do setor</p>
                <p className="text-sm text-slate-500 mt-1">
                  {Number(data.workersCount || 0).toLocaleString("pt-BR")} pessoa(s) na equipe
                </p>
                <p className="text-sm text-slate-300 mt-2">
                  Pagamento: R$ {Number(data.workersPaymentTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm text-slate-300">
                  Refeições: {Number(data.workersMealsTotal || 0).toLocaleString("pt-BR")} x R$ {Number(data.mealUnitCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm font-semibold text-cyan-400 mt-2">
                  Subtotal da equipe: R$ {Number(data.workersTotalCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
              </div>

              <div className="rounded-xl border border-slate-800/40 bg-slate-950/60 px-3 py-3">
                <p className="text-xs text-slate-400 uppercase tracking-wider">Liderança do setor</p>
                <p className="text-sm text-slate-500 mt-1">
                  {Number(data.leadershipPositionsTotal || 0).toLocaleString("pt-BR")} cargo(s) de liderança •{" "}
                  {Number(data.leadershipFilledTotal || 0).toLocaleString("pt-BR")} com responsável
                </p>
                {Number(data.leadershipPlaceholderTotal || 0) > 0 && (
                  <p className="text-[11px] text-amber-400 mt-1">
                    {Number(data.leadershipPlaceholderTotal || 0).toLocaleString("pt-BR")} liderança(s) ainda sem nome e CPF
                  </p>
                )}
                <p className="text-sm text-slate-300 mt-2">
                  Pagamento: R$ {Number(data.leadershipPaymentTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm text-slate-300">
                  Refeições: {Number(data.leadershipMealsTotal || 0).toLocaleString("pt-BR")} x R$ {Number(data.mealUnitCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-sm font-semibold text-amber-400 mt-2">
                  Subtotal Liderança: R$ {Number(data.leadershipTotalCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
              </div>

              <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-3 py-3">
                <p className="text-xs text-emerald-400 uppercase tracking-wider">Total do Setor</p>
                <p className="text-sm text-emerald-200 mt-1">
                  {Number(data.plannedMembersTotal || 0).toLocaleString("pt-BR")} planejado(s) •{" "}
                  {Number(data.filledMembersTotal || 0).toLocaleString("pt-BR")} preenchido(s)
                  {data.presentMembersTotal !== null ? ` • ${Number(data.presentMembersTotal || 0).toLocaleString("pt-BR")} presente(s)` : ""}
                </p>
                <p className="text-xl font-bold text-emerald-300 mt-2">
                  R$ {Number(data.sectorGrandTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
              </div>
            </>
          )}

          <div className="flex justify-end pt-1">
            <button type="button" onClick={onClose} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-semibold transition-colors">
              Fechar
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
