import { Link } from "react-router-dom";
import { Zap, UtensilsCrossed, Store, ShoppingCart } from "lucide-react";
import { useEventScope } from "../context/EventScopeContext";

const sectors = [
  {
    to: "/bar",
    icon: Zap,
    title: "Bar",
    description: "Bebidas, drinks e chopp",
    color: "cyan",
  },
  {
    to: "/food",
    icon: UtensilsCrossed,
    title: "Alimentação",
    description: "Lanches, refeições e porções",
    color: "amber",
  },
  {
    to: "/shop",
    icon: Store,
    title: "Loja",
    description: "Produtos, merchandise e acessórios",
    color: "purple",
  },
];

const colorMap = {
  cyan:   { bg: "bg-cyan-500/10", text: "text-cyan-400", border: "hover:border-cyan-500/40", glow: "hover:shadow-[0_0_20px_rgba(0,240,255,0.15)]" },
  amber:  { bg: "bg-amber-500/10", text: "text-amber-400", border: "hover:border-amber-500/40", glow: "hover:shadow-[0_0_20px_rgba(245,158,11,0.15)]" },
  purple: { bg: "bg-purple-500/10", text: "text-purple-400", border: "hover:border-purple-500/40", glow: "hover:shadow-[0_0_20px_rgba(168,85,247,0.15)]" },
};

export default function PosHub() {
  const { buildScopedPath } = useEventScope();

  return (
    <div className="space-y-12">
      <header className="flex items-center gap-4">
        <div className="w-12 h-12 glass-card rounded-xl flex items-center justify-center text-cyan-400 shadow-lg">
          <ShoppingCart size={24} />
        </div>
        <div>
          <h1 className="text-3xl font-bold tracking-tight text-slate-100 font-headline">Vendas no Local</h1>
          <p className="text-slate-400 text-sm">Selecione o setor para operar o ponto de venda.</p>
        </div>
      </header>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
        {sectors.map((sector) => {
          const Icon = sector.icon;
          const c = colorMap[sector.color];
          return (
            <Link
              key={sector.to}
              to={buildScopedPath(sector.to)}
              className={`bg-[#111827] border border-slate-800/60 rounded-2xl p-8 flex flex-col items-center gap-6 group transition-all duration-300 ${c.border} ${c.glow}`}
            >
              <div className={`w-20 h-20 rounded-2xl ${c.bg} flex items-center justify-center group-hover:scale-110 transition-transform`}>
                <Icon size={36} className={c.text} />
              </div>
              <div className="text-center">
                <h2 className="text-2xl font-bold font-headline text-slate-100 group-hover:text-cyan-400 transition-colors">
                  {sector.title}
                </h2>
                <p className="text-slate-500 text-sm mt-2">{sector.description}</p>
              </div>
              <div className={`px-6 py-2 rounded-full border border-slate-700/50 text-sm font-medium text-slate-400 group-hover:${c.text} group-hover:border-current transition-colors`}>
                Abrir Terminal →
              </div>
            </Link>
          );
        })}
      </div>
    </div>
  );
}
