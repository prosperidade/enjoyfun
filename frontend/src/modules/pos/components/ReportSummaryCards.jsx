export default function ReportSummaryCards({
  loadingReports,
  reportData,
  reportError,
}) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div className="bg-purple-900/20 border border-purple-800/50 p-6 rounded-2xl">
        <h3 className="text-gray-400 text-xs font-bold uppercase">
          Faturamento do Setor
        </h3>
        <p className="text-3xl font-extrabold text-white mt-2">
          R$ {parseFloat(reportData?.total_revenue || 0).toFixed(2)}
        </p>
        <p className="text-xs text-purple-100/70 mt-3">
          {reportError
            ? "Mantendo o ultimo valor estavel."
            : loadingReports
              ? "Atualizando leitura..."
              : "Leitura operacional do filtro atual."}
        </p>
      </div>
      <div className="bg-indigo-900/20 border border-indigo-800/50 p-6 rounded-2xl">
        <h3 className="text-gray-400 text-xs font-bold uppercase">
          Itens Vendidos no Setor
        </h3>
        <p className="text-3xl font-extrabold text-white mt-2">
          {reportData?.total_items || 0} un.
        </p>
        <p className="text-xs text-indigo-100/70 mt-3">
          {reportError
            ? "Falha de atualizacao visivel ao operador."
            : loadingReports
              ? "Sincronizando contagem..."
              : "Volume consolidado do periodo."}
        </p>
      </div>
    </div>
  );
}
