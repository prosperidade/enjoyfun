export default function AnalyticsComparePlaceholder() {
  return (
    <div className="card border-dashed border-gray-700 bg-gray-900/30">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3 className="section-title mb-0">Comparativo entre Eventos</h3>
          <p className="mt-1 text-sm text-gray-400">
            Bloco reservado para a PR 4. O contrato ja existe no backend, mas a comparacao permanece desabilitada nesta etapa.
          </p>
        </div>
        <span className="badge badge-gray">Reservado</span>
      </div>
    </div>
  );
}
