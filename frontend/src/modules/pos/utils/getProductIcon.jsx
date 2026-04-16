import {
  Beer,
  BottleWine,
  Coffee,
  CupSoda,
  Cylinder,
  GlassWater,
  Martini,
  Milk,
  Pizza,
  Shirt,
  UtensilsCrossed,
  Wine,
  Zap,
} from "lucide-react";

export function getProductIcon(name, sector) {
  const s = 28;

  if (!name) {
    return sector === "food" ? (
      <UtensilsCrossed size={s} className="text-orange-400" />
    ) : sector === "shop" ? (
      <Shirt size={s} className="text-blue-400" />
    ) : (
      <Beer size={s} className="text-cyan-400" />
    );
  }

  const n = String(name).toLowerCase();

  // ── BEBIDAS ALCOÓLICAS ──

  // Chopp / Chope → caneca de chopp
  if (n.includes("chopp") || n.includes("chope") || n.includes("draft")) {
    return <Beer size={s} className="text-amber-400" />;
  }

  // Cerveja (lata/long neck) → latinha cilíndrica
  if (n.includes("cerveja") || n.includes("beer") || n.includes("lager") || n.includes("pilsen") || n.includes("ipa") || n.includes("brahma") || n.includes("skol") || n.includes("heineken") || n.includes("budweiser") || n.includes("corona")) {
    return <Cylinder size={s} className="text-amber-500" />;
  }

  // Whisky / Old Par / Jack → garrafa
  if (n.includes("whisky") || n.includes("whiskey") || n.includes("old par") || n.includes("jack daniel") || n.includes("johnnie") || n.includes("chivas")) {
    return <BottleWine size={s} className="text-amber-600" />;
  }

  // Vodka → garrafa
  if (n.includes("vodka") || n.includes("absolut") || n.includes("smirnoff") || n.includes("grey goose")) {
    return <BottleWine size={s} className="text-blue-400" />;
  }

  // Tequila / Rum → garrafa
  if (n.includes("tequila") || n.includes("rum") || n.includes("bacardi") || n.includes("cachaça") || n.includes("cachaca") || n.includes("51")) {
    return <BottleWine size={s} className="text-amber-500" />;
  }

  // Combo / Dose / Kit → garrafa
  if (n.includes("combo") || n.includes("dose") || n.includes("kit") || n.includes("balde")) {
    return <BottleWine size={s} className="text-purple-400" />;
  }

  // Vinho → taça
  if (n.includes("vinho") || n.includes("wine") || n.includes("espumante") || n.includes("prosecco") || n.includes("champagne")) {
    return <Wine size={s} className="text-red-400" />;
  }

  // Drinks / Cocktails → copo martini
  if (n.includes("drink") || n.includes("cocktail") || n.includes("caipirinha") || n.includes("gin") || n.includes("mojito") || n.includes("martini") || n.includes("margarita") || n.includes("negroni") || n.includes("aperol")) {
    return <Martini size={s} className="text-cyan-400" />;
  }

  // ── BEBIDAS NÃO ALCOÓLICAS (LATA) ──

  // Xeque Mate / Mate → latinha
  if (n.includes("xeque mate") || n.includes("mate") || n.includes("xequemate")) {
    return <Cylinder size={s} className="text-green-500" />;
  }

  // Energético / Red Bull / Monster → latinha com raio
  if (n.includes("energetico") || n.includes("energético") || n.includes("red bull") || n.includes("monster") || n.includes("venom") || n.includes("burn")) {
    return <Zap size={s} className="text-yellow-400" />;
  }

  // Refrigerante / Coca / Guaraná → latinha
  if (n.includes("refri") || n.includes("coca") || n.includes("guarana") || n.includes("guaraná") || n.includes("fanta") || n.includes("sprite") || n.includes("pepsi") || n.includes("schweppes") || n.includes("tonica") || n.includes("tônica")) {
    return <Cylinder size={s} className="text-red-400" />;
  }

  // Suco / Juice → copo com canudo
  if (n.includes("suco") || n.includes("juice") || n.includes("limonada") || n.includes("laranjada")) {
    return <CupSoda size={s} className="text-orange-400" />;
  }

  // Gatorade / Isotônico → latinha
  if (n.includes("gatorade") || n.includes("isotônico") || n.includes("isotonico") || n.includes("powerade")) {
    return <Cylinder size={s} className="text-blue-400" />;
  }

  // Água → copo de água
  if (n.includes("agua") || n.includes("água") || n.includes("water")) {
    return <GlassWater size={s} className="text-blue-300" />;
  }

  // Café → xícara
  if (n.includes("café") || n.includes("cafe") || n.includes("coffee") || n.includes("capuccino") || n.includes("cappuccino") || n.includes("espresso") || n.includes("latte")) {
    return <Coffee size={s} className="text-amber-700" />;
  }

  // ── COMIDA ──

  if (n.includes("pizza")) {
    return <Pizza size={s} className="text-red-400" />;
  }
  if (n.includes("hamburguer") || n.includes("burger") || n.includes("lanche") || n.includes("hot dog") || n.includes("sanduiche") || n.includes("x-") || n.includes("cheeseburger")) {
    return <UtensilsCrossed size={s} className="text-orange-500" />;
  }
  if (n.includes("batata") || n.includes("fries") || n.includes("porção") || n.includes("porcao") || n.includes("petisco") || n.includes("onion") || n.includes("nugget")) {
    return <UtensilsCrossed size={s} className="text-yellow-500" />;
  }
  if (n.includes("açaí") || n.includes("acai") || n.includes("sorvete") || n.includes("ice cream") || n.includes("picolé") || n.includes("picole")) {
    return <Milk size={s} className="text-purple-400" />;
  }
  if (n.includes("churrasco") || n.includes("espeto") || n.includes("carne") || n.includes("costela") || n.includes("picanha")) {
    return <UtensilsCrossed size={s} className="text-red-500" />;
  }
  if (n.includes("pipoca") || n.includes("popcorn") || n.includes("algodao") || n.includes("algodão")) {
    return <UtensilsCrossed size={s} className="text-yellow-400" />;
  }

  // ── FALLBACK POR SETOR ──
  return sector === "food" ? (
    <UtensilsCrossed size={s} className="text-orange-400" />
  ) : sector === "shop" ? (
    <Shirt size={s} className="text-blue-400" />
  ) : (
    <Beer size={s} className="text-cyan-400" />
  );
}
