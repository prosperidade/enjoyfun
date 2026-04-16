export default function PosTabs({ tab, setTab }) {
  return (
    <div className="flex items-end gap-12">
      <button
        onClick={() => setTab("pos")}
        className={`pb-4 border-b-2 font-headline font-bold text-xl tracking-tight transition-all ${
          tab === "pos"
            ? "border-cyan-400 text-cyan-400"
            : "border-transparent text-slate-500 hover:text-slate-300"
        }`}
      >
        POS
      </button>
      <button
        onClick={() => setTab("stock")}
        className={`pb-4 border-b-2 font-headline font-medium text-xl tracking-tight transition-all ${
          tab === "stock"
            ? "border-cyan-400 text-cyan-400"
            : "border-transparent text-slate-500 hover:text-slate-300"
        }`}
      >
        Estoque
      </button>
      <button
        onClick={() => setTab("reports")}
        className={`pb-4 border-b-2 font-headline font-medium text-xl tracking-tight transition-all ${
          tab === "reports"
            ? "border-cyan-400 text-cyan-400"
            : "border-transparent text-slate-500 hover:text-slate-300"
        }`}
      >
        BI & IA
      </button>
    </div>
  );
}
