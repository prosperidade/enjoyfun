import { Beer, Shirt, UtensilsCrossed } from "lucide-react";

export function getSectorInfo(sector) {
  if (sector === "food") {
    return {
      icon: <UtensilsCrossed className="text-orange-400" size={28} />,
      title: "ALIMENTAÇÃO",
      fallbackIcon: <UtensilsCrossed className="text-orange-400" size={32} />,
    };
  }

  if (sector === "shop") {
    return {
      icon: <Shirt className="text-blue-400" size={28} />,
      title: "LOJA",
      fallbackIcon: <Shirt className="text-blue-400" size={32} />,
    };
  }

  return {
    icon: <Beer className="text-cyan-400" size={28} />,
    title: "BAR",
    fallbackIcon: <Beer className="text-cyan-400" size={32} />,
  };
}
