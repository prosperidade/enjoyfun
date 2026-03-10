import { Link } from "react-router-dom";
import { Beer, Shirt, UtensilsCrossed } from "lucide-react";

const links = [
  {
    to: "/bar",
    label: "BAR",
    subtitle: "PDV Offline",
    borderClassName: "hover:border-purple-500 hover:bg-purple-900/20",
    iconWrapperClassName: "bg-purple-600/20 text-purple-400",
    icon: Beer,
  },
  {
    to: "/food",
    label: "FOOD",
    subtitle: "PDV Offline",
    borderClassName: "hover:border-orange-500 hover:bg-orange-900/20",
    iconWrapperClassName: "bg-orange-600/20 text-orange-400",
    icon: UtensilsCrossed,
  },
  {
    to: "/shop",
    label: "SHOP",
    subtitle: "PDV Offline",
    borderClassName: "hover:border-blue-500 hover:bg-blue-900/20",
    iconWrapperClassName: "bg-blue-600/20 text-blue-400",
    icon: Shirt,
  },
];

export default function QuickLinksPanel() {
  return (
    <div className="card border-gray-800/80 bg-gray-950/40">
      <h3 className="section-title mb-3">Acessos Rápidos de PDV</h3>
      <p className="mb-5 text-sm text-gray-400">
        Navegação auxiliar para abrir os módulos operacionais sem competir com os indicadores
        centrais do dashboard.
      </p>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {links.map((item) => {
          const Icon = item.icon;
          return (
            <Link
              key={item.to}
              to={item.to}
              className={`group flex flex-col items-center justify-center rounded-xl border border-gray-700/50 bg-gray-900/50 p-5 transition-all ${item.borderClassName}`}
            >
              <div
                className={`mb-3 flex h-12 w-12 items-center justify-center rounded-full transition-transform group-hover:scale-110 ${item.iconWrapperClassName}`}
              >
                <Icon size={24} />
              </div>
              <span className="font-bold tracking-wide text-white">{item.label}</span>
              <span className="mt-1 text-[10px] uppercase text-gray-500">{item.subtitle}</span>
            </Link>
          );
        })}
      </div>
    </div>
  );
}
