import { Package, ShoppingCart } from "lucide-react";

export default function PosTabs({ tab, setTab }) {
  return (
    <div className="flex gap-2 bg-gray-950 p-1.5 rounded-xl border border-gray-800 w-full sm:w-auto">
      <button
        onClick={() => setTab("pos")}
        className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "pos" ? "bg-purple-600 text-white shadow-lg shadow-purple-900/20" : "text-gray-400 hover:text-white hover:bg-gray-800"}`}
      >
        <ShoppingCart size={16} /> VENDA
      </button>
      <button
        onClick={() => setTab("stock")}
        className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "stock" ? "bg-purple-600 text-white shadow-lg shadow-purple-900/20" : "text-gray-400 hover:text-white hover:bg-gray-800"}`}
      >
        <Package size={16} /> ESTOQUE
      </button>
      <button
        onClick={() => setTab("reports")}
        className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "reports" ? "bg-purple-600 text-white shadow-lg shadow-purple-900/20" : "text-gray-400 hover:text-white hover:bg-gray-800"}`}
      >
        📊 BI & IA
      </button>
    </div>
  );
}
