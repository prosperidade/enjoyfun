import {
  Beer,
  Coffee,
  CupSoda,
  GlassWater,
  Pizza,
  Shirt,
  UtensilsCrossed,
  Zap,
} from "lucide-react";

export function getProductIcon(name, sector) {
  const defaultSize = 28;

  if (!name) {
    return sector === "food" ? (
      <UtensilsCrossed size={defaultSize} className="text-orange-400" />
    ) : sector === "shop" ? (
      <Shirt size={defaultSize} className="text-blue-400" />
    ) : (
      <Beer size={defaultSize} className="text-purple-400" />
    );
  }

  const normalizedName = String(name).toLowerCase();

  if (normalizedName.includes("vodka") || normalizedName.includes("combo")) {
    return <Beer size={defaultSize} className="text-indigo-400" />;
  }
  if (
    normalizedName.includes("whisky") ||
    normalizedName.includes("old par")
  ) {
    return <Coffee size={defaultSize} className="text-amber-600" />;
  }
  if (normalizedName.includes("pizza")) {
    return <Pizza size={defaultSize} className="text-red-400" />;
  }
  if (
    normalizedName.includes("xeque mate") ||
    normalizedName.includes("mate")
  ) {
    return <CupSoda size={defaultSize} className="text-green-500" />;
  }
  if (
    normalizedName.includes("agua") ||
    normalizedName.includes("água")
  ) {
    return <GlassWater size={defaultSize} className="text-blue-300" />;
  }
  if (
    normalizedName.includes("gatorade") ||
    normalizedName.includes("energetico") ||
    normalizedName.includes("energético")
  ) {
    return <Zap size={defaultSize} className="text-yellow-400" />;
  }
  if (
    normalizedName.includes("hamburguer") ||
    normalizedName.includes("burger")
  ) {
    return <UtensilsCrossed size={defaultSize} className="text-orange-500" />;
  }

  return sector === "food" ? (
    <UtensilsCrossed size={defaultSize} className="text-orange-400" />
  ) : sector === "shop" ? (
    <Shirt size={defaultSize} className="text-blue-400" />
  ) : (
    <Beer size={defaultSize} className="text-purple-400" />
  );
}
