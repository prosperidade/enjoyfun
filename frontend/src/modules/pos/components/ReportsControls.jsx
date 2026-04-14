const TIME_FILTERS = ["1h", "5h", "24h", "total"];

export default function ReportsControls({
  aiQuestion,
  hasValidEventContext,
  lastReportUpdatedAt,
  loadingInsight,
  loadingReports,
  onAiQuestionChange,
  onInsightComposerKeyDown,
  onRequestInsight,
  onTimeFilterChange,
  reportError,
  reportStale,
  sectorTitle,
  timeFilter,
}) {
  const statusLabel = !hasValidEventContext
    ? "Selecione um evento para carregar os indicadores."
    : loadingReports && reportStale
      ? "Atualizando o ultimo snapshot valido..."
      : loadingReports
        ? "Carregando indicadores..."
        : reportError
          ? `Falha ao atualizar: ${reportError}`
          : lastReportUpdatedAt
            ? `Atualizado em ${new Date(lastReportUpdatedAt).toLocaleTimeString("pt-BR", {
                hour: "2-digit",
                minute: "2-digit",
              })}`
            : "Aguardando primeira leitura.";

  return (
    <>
      <div className="bg-gray-900/70 border border-gray-800 p-4 rounded-2xl">
        <p className="text-[11px] font-black tracking-[0.25em] uppercase text-indigo-400">
          Relatorio Setorial
        </p>
        <h2 className="text-xl font-black text-white mt-2">
          Indicadores operacionais do setor {sectorTitle}
        </h2>
        <p className="text-sm text-gray-400 mt-1">
          Cards, historico e mix abaixo refletem apenas o setor atual.
        </p>
        <div className="mt-4 flex flex-wrap items-center gap-3 text-xs">
          <span
            className={`inline-flex items-center rounded-full border px-3 py-1 font-semibold ${
              reportError
                ? "border-red-500/40 bg-red-500/10 text-red-200"
                : loadingReports
                  ? "border-amber-500/40 bg-amber-500/10 text-amber-100"
                  : "border-emerald-500/30 bg-emerald-500/10 text-emerald-100"
            }`}
          >
            {statusLabel}
          </span>
          {reportStale && !reportError ? (
            <span className="text-amber-200/80">
              Exibindo a ultima leitura estavel enquanto a nova resposta chega.
            </span>
          ) : null}
        </div>
      </div>

      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 bg-gray-900 border border-gray-800 p-4 rounded-xl">
        <div className="flex flex-wrap gap-2 bg-gray-800/50 p-1.5 rounded-xl border border-gray-700/50 w-full sm:w-auto overflow-x-auto">
          {TIME_FILTERS.map((filter) => (
            <button
              key={filter}
              onClick={() => onTimeFilterChange(filter)}
              disabled={loadingReports && timeFilter === filter}
              className={`flex-1 sm:flex-none px-4 py-2 text-xs font-bold rounded-lg transition-all ${timeFilter === filter ? "bg-indigo-600 text-white shadow-lg shadow-indigo-900/20" : "text-gray-400 hover:text-white hover:bg-gray-700"}`}
            >
              {filter.toUpperCase()}
            </button>
          ))}
        </div>

        {/* InsightComposer removido — EmbeddedAIChat no topo da pagina cobre essa funcao */}
      </div>
    </>
  );
}
