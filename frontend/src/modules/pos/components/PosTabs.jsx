import { Package, ShoppingCart } from "lucide-react";

export default function PosTabs({ tab, setTab }) {
  return (
    <div className="flex gap-2 bg-slate-950 p-1.5 rounded-xl border border-slate-800/40 w-full sm:w-auto">
      <button
        onClick={() => setTab("pos")}
        className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "pos" ? "bg-cyan-600 text-slate-100 shadow-lg shadow-purple-900/20" : "text-slate-400 hover:text-slate-100 hover:bg-slate-800/40"}`}
      >
        <ShoppingCart size={16} /> VENDA
      </button>
      <button
        onClick={() => setTab("stock")}
        className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "stock" ? "bg-cyan-600 text-slate-100 shadow-lg shadow-purple-900/20" : "text-slate-400 hover:text-slate-100 hover:bg-slate-800/40"}`}
      >
        <Package size={16} /> ESTOQUE
      </button>
      <button
        onClick={() => setTab("reports")}
        className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "reports" ? "bg-cyan-600 text-slate-100 shadow-lg shadow-purple-900/20" : "text-slate-400 hover:text-slate-100 hover:bg-slate-800/40"}`}
      >
        📊 BI & IA
      </button>
    </div>
  );
}
