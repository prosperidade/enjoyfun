import {
  CircleDollarSign,
  Ticket,
  Wallet,
  TrendingUp,
  Users,
  Zap,
} from "lucide-react";
import AnalyticsStateBox from "./AnalyticsStateBox";

const SUMMARY_ITEMS = [
  {
    key: "gross_revenue",
    label: "Receita Total",
    icon: CircleDollarSign,
    iconBg: "bg-cyan-400/10",
    iconColor: "text-cyan-400",
    barColor: "bg-cyan-400",
    format: (value) => `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 0 })}`,
    barPercent: 75,
  },
  {
    key: "average_ticket",
    label: "Ticket Médio",
    icon: Ticket,
    iconBg: "bg-purple-500/15",
    iconColor: "text-purple-400",
    barColor: "bg-purple-500",
    format: (value) => `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`,
    barPercent: 60,
  },
  {
    key: "tickets_sold",
    label: "Conversão / Vendas",
    icon: Zap,
    iconBg: "bg-yellow-400/10",
    iconColor: "text-yellow-400",
    barColor: "bg-yellow-400",
    format: (value) => Number(value || 0).toLocaleString("pt-BR"),
    barPercent: 42,
  },
  {
    key: "remaining_balance",
    label: "Saldo Remanescente",
    icon: Wallet,
    iconBg: "bg-green-400/10",
    iconColor: "text-green-400",
    barColor: "bg-green-400",
    format: (value) => `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`,
    barPercent: 85,
  },
];

export default function AnalyticsSummaryCards({ loading, summary }) {
  if (!loading && !summary) {
    return (
      <AnalyticsStateBox
        title="Resumo indisponível"
        description="A leitura analítica não retornou o resumo principal para este recorte."
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
      {SUMMARY_ITEMS.map((item) => {
        const Icon = item.icon;

        return (
          <div key={item.key} className="bg-[#111827] border border-slate-700/30 p-6 rounded-2xl group hover:border-cyan-500/30 transition-all">
            <div className="flex justify-between items-start mb-4">
              <div className={`p-2 ${item.iconBg} rounded-lg`}>
                <Icon size={20} className={item.iconColor} />
              </div>
            </div>
            <p className="text-slate-400 text-sm">{item.label}</p>
            <h3 className="text-2xl font-bold text-cyan-400 mt-1 font-headline">
              {loading ? "—" : item.format(summary?.[item.key])}
            </h3>
            <div className="w-full bg-slate-800 h-1 mt-4 rounded-full overflow-hidden">
              <div className={`${item.barColor} h-full rounded-full transition-all duration-700`} style={{ width: loading ? '0%' : `${item.barPercent}%` }} />
            </div>
          </div>
        );
      })}
    </div>
  );
}
