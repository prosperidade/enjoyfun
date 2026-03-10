import { Activity, CreditCard, TrendingUp, Users, UtensilsCrossed } from "lucide-react";
import SectionHeader from "./SectionHeader";
import StatCard from "./StatCard";

function LoadingState() {
  return (
    <div className="flex h-28 items-center justify-center">
      <div className="spinner h-6 w-6" />
    </div>
  );
}

function EmptyState({ message }) {
  return <p className="text-sm text-gray-500">{message}</p>;
}

export default function WorkforceCostConnector({ loading, workforceCosts }) {
  return (
    <div className="space-y-6 border-t border-gray-800 pt-6">
      <SectionHeader
        icon={Users}
        title="Conector Financeiro de Equipe"
        badge="Bloco Auxiliar"
        iconClassName="text-emerald-400"
        badgeClassName="bg-emerald-400/20 text-emerald-400"
        description="Leitura auxiliar de custo estimado de equipe já existente no conector financeiro, sem alterar regra de negócio."
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
        <StatCard
          compact
          loading={loading}
          icon={Users}
          label="Posições Consideradas no Custo"
          value={workforceCosts?.summary?.members ?? 0}
          color="bg-emerald-600"
          subtitle="Base ativa calculada"
        />
        <StatCard
          compact
          loading={loading}
          icon={CreditCard}
          label="Pagamento Estimado da Equipe"
          value={`R$ ${Number(workforceCosts?.summary?.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
          color="bg-teal-600"
          subtitle="Montante estimado da equipe"
        />
        <StatCard
          compact
          loading={loading}
          icon={Activity}
          label="Horas Totais Estimadas"
          value={Number(workforceCosts?.summary?.estimated_hours_total || 0).toLocaleString("pt-BR")}
          color="bg-cyan-600"
          subtitle="Trabalho estimado em horas"
        />
        <StatCard
          compact
          loading={loading}
          icon={UtensilsCrossed}
          label="Custo Estimado de Refeições"
          value={`R$ ${Number(workforceCosts?.summary?.estimated_meals_cost_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
          color="bg-amber-600"
          subtitle={`Unitário: R$ ${Number(workforceCosts?.summary?.meal_unit_cost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
        />
        <StatCard
          compact
          loading={loading}
          icon={TrendingUp}
          label="Custo Total Estimado da Equipe"
          value={`R$ ${Number(workforceCosts?.summary?.estimated_total_cost || workforceCosts?.summary?.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
          color="bg-fuchsia-600"
          subtitle="Equipe + refeições estimadas"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="card">
          <h3 className="mb-4 text-sm font-semibold text-gray-200">Pagamento Estimado por Setor</h3>
          {loading ? (
            <LoadingState />
          ) : workforceCosts?.by_sector?.length ? (
            <div className="space-y-3">
              {workforceCosts.by_sector.map((row) => (
                <div
                  key={row.sector}
                  className="flex items-center justify-between rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2"
                >
                  <div>
                    <div className="text-xs uppercase text-gray-400">{row.sector}</div>
                    <div className="text-[11px] text-gray-500">{row.members} membros</div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-semibold text-emerald-400">
                      R$ {Number(row.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                    </div>
                    <div className="text-[11px] text-gray-500">
                      {Number(row.estimated_hours_total || 0).toLocaleString("pt-BR")} h
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState message="Sem dados de equipe para o filtro atual." />
          )}
        </div>

        <div className="card">
          <h3 className="mb-4 text-sm font-semibold text-gray-200">Pagamento Estimado por Cargo</h3>
          {loading ? (
            <LoadingState />
          ) : workforceCosts?.by_role?.length ? (
            <div className="max-h-[320px] space-y-3 overflow-y-auto pr-1">
              {workforceCosts.by_role.map((row, index) => (
                <div
                  key={`${row.sector}-${row.role_name}-${index}`}
                  className="flex items-center justify-between rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2"
                >
                  <div>
                    <div className="text-xs font-medium text-white">{row.role_name}</div>
                    <div className="text-[11px] uppercase text-gray-500">
                      {row.sector} • {row.members} membros
                    </div>
                  </div>
                  <div className="text-right text-sm font-semibold text-emerald-400">
                    R$ {Number(row.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState message="Sem dados de cargo para o filtro atual." />
          )}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="card">
          <h3 className="mb-4 text-sm font-semibold text-gray-200">Base Gerencial/Diretiva</h3>
          {loading ? (
            <LoadingState />
          ) : (workforceCosts?.by_role_managerial?.length || 0) > 0 ? (
            <div className="max-h-[320px] space-y-3 overflow-y-auto pr-1">
              {(workforceCosts?.by_role_managerial || []).map((row, index) => (
                <div
                  key={`${row.sector}-${row.role_name}-${index}`}
                  className="flex items-center justify-between rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2"
                >
                  <div>
                    <div className="text-xs font-medium text-white">{row.role_name}</div>
                    <div className="text-[11px] uppercase text-gray-500">
                      {row.sector} • {row.members} membros
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-semibold text-amber-400">
                      R$ {Number(row.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState message="Sem cargos gerenciais/diretivos no filtro atual." />
          )}
        </div>

        <div className="card">
          <h3 className="mb-4 text-sm font-semibold text-gray-200">Membros Operacionais (primeiros 20)</h3>
          {loading ? (
            <LoadingState />
          ) : (workforceCosts?.operational_members?.length || 0) > 0 ? (
            <div className="max-h-[320px] space-y-3 overflow-y-auto pr-1">
              {(workforceCosts?.operational_members || []).slice(0, 20).map((item) => (
                <div
                  key={item.participant_id}
                  className="flex items-center justify-between rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2"
                >
                  <div>
                    <div className="text-xs font-medium text-white">{item.participant_name}</div>
                    <div className="text-[11px] uppercase text-gray-500">
                      {item.sector} • {item.role_name}
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-semibold text-cyan-400">
                      R$ {Number(item.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState message="Sem membros operacionais no filtro atual." />
          )}
        </div>
      </div>
    </div>
  );
}
