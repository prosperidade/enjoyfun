export default function InsightComposer({
  aiQuestion,
  loadingInsight,
  onAiQuestionChange,
  onKeyDown,
  onRequestInsight,
}) {
  return (
    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
      <input
        value={aiQuestion}
        onChange={(e) => onAiQuestionChange(e.target.value)}
        placeholder="Pergunte à IA (ex: qual horário de pico?)"
        className="flex-1 sm:w-72 bg-slate-950 border border-indigo-500/30 text-slate-100 rounded-xl px-4 py-3 text-sm outline-none focus:border-indigo-500 transition-colors placeholder:text-slate-600"
        onKeyDown={onKeyDown}
      />
      <button
        onClick={onRequestInsight}
        disabled={loadingInsight}
        className="bg-indigo-600 px-6 py-3 rounded-xl text-slate-100 text-sm font-bold hover:bg-indigo-500 transition-colors whitespace-nowrap shadow-lg shadow-indigo-900/20"
      >
        {loadingInsight ? "..." : "✨ Analisar"}
      </button>
    </div>
  );
}
