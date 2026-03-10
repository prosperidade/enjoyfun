const GROUP_OPTIONS = [
  { value: "hour", label: "Hora" },
  { value: "day", label: "Dia" },
];

export default function AnalyticsFiltersBar({
  analytics,
  compareEventId,
  eventId,
  events,
  groupBy,
  onCompareEventChange,
  onEventChange,
  onGroupByChange,
}) {
  const blockedFilters = analytics?.filters?.blocked || [];
  const compareOptions = events.filter(
    (dashboardEvent) => String(dashboardEvent.id) !== String(eventId)
  );

  return (
    <div className="card space-y-4">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div className="grid flex-1 grid-cols-1 gap-3 md:grid-cols-3">
          <div>
            <label className="input-label">Evento</label>
            <select
              className="select"
              value={eventId}
              onChange={(event) => onEventChange(event.target.value)}
            >
              <option value="">Todos os eventos com base segura atual</option>
              {events.map((dashboardEvent) => (
                <option key={dashboardEvent.id} value={dashboardEvent.id}>
                  {dashboardEvent.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="input-label">Comparar com</label>
            <select
              className="select"
              value={compareEventId}
              disabled={!eventId}
              onChange={(event) => onCompareEventChange(event.target.value)}
            >
              <option value="">
                {eventId
                  ? "Sem comparacao nesta leitura"
                  : "Selecione o evento base primeiro"}
              </option>
              {compareOptions.map((dashboardEvent) => (
                <option key={dashboardEvent.id} value={dashboardEvent.id}>
                  {dashboardEvent.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="input-label">Agrupar curva por</label>
            <div className="flex gap-2 rounded-xl border border-gray-800 bg-gray-900/70 p-1.5">
              {GROUP_OPTIONS.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => onGroupByChange(option.value)}
                  className={`flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                    groupBy === option.value
                      ? "bg-brand text-white"
                      : "text-gray-400 hover:bg-gray-800 hover:text-white"
                  }`}
                >
                  {option.label}
                </button>
              ))}
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-dashed border-gray-700 bg-gray-900/40 px-4 py-3 text-sm text-gray-400 lg:max-w-sm">
          O comparativo basico entre dois eventos ja pode ser ligado aqui. Filtros avancados de periodo e recortes adicionais seguem reservados para as proximas PRs do analitico.
        </div>
      </div>

      {blockedFilters.length > 0 ? (
        <div className="rounded-xl border border-amber-700/40 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
          O backend reservou filtros ainda nao ativos nesta tela:{" "}
          {blockedFilters.join(", ")}.
        </div>
      ) : null}
    </div>
  );
}
