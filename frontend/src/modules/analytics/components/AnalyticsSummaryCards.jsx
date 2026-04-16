import {
  CircleDollarSign,
  Ticket,
  Wallet,
  TrendingUp,
} from "lucide-react";
import AnalyticsStateBox from "./AnalyticsStateBox";

const SUMMARY_ITEMS = [
  {
    key: "tickets_sold",
    label: "Tickets Vendidos",
    icon: Ticket,
    color: "bg-purple-600",
    format: (value) => Number(value || 0).toLocaleString("pt-BR"),
  },
  {
    key: "gross_revenue",
    label: "Receita Bruta",
    icon: CircleDollarSign,
    color: "bg-green-600",
    format: (value) =>
      `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`,
  },
  {
    key: "average_ticket",
    label: "Ticket Medio",
    icon: TrendingUp,
    color: "bg-cyan-600",
    format: (value) =>
      `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`,
  },
  {
    key: "remaining_balance",
    label: "Saldo Remanescente",
    icon: Wallet,
    color: "bg-emerald-700",
    format: (value) =>
      `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`,
  },
];

export default function AnalyticsSummaryCards({ loading, summary }) {
  if (!loading && !summary) {
    return (
      <AnalyticsStateBox
        title="Resumo indisponivel"
        description="A leitura analitica nao retornou o resumo principal para este recorte."
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
      {SUMMARY_ITEMS.map((item) => {
        const Icon = item.icon;

        return (
          <div key={item.key} className="stat-card relative overflow-hidden">
            <div
              className={`absolute right-0 top-0 h-20 w-20 -translate-y-6 translate-x-6 rounded-full opacity-10 blur-2xl ${item.color}`}
            />
            <div
              className={`mb-3 flex h-10 w-10 items-center justify-center rounded-xl ${item.color}`}
            >
              <Icon size={20} className="text-white" />
            </div>
            <div className="stat-value">
              {loading ? "—" : item.format(summary?.[item.key])}
            </div>
            <div className="stat-label">{item.label}</div>
            {item.key === "remaining_balance" && !loading ? (
              <div className="mt-1 text-[10px] text-slate-500">
                Base confiavel atual de cartoes ativos
              </div>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}
