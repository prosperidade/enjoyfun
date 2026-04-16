import PosTabs from "./PosTabs";

export default function PosToolbar({
  eventId,
  events,
  eventsError,
  sectorInfo,
  setEventId,
  setTab,
  tab,
}) {
  return (
    <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-6 bg-slate-900/60/40 p-4 rounded-2xl border border-slate-800/40/60">
      <div>
        <h1 className="text-2xl font-black text-slate-100 flex items-center gap-3 tracking-wide">
          {sectorInfo.icon} POS EnjoyFun{" "}
          <span className="text-slate-500 font-medium">| {sectorInfo.title}</span>
        </h1>
        {eventsError ? (
          <p className="mt-2 text-sm text-red-300">{eventsError}</p>
        ) : null}
      </div>

      <div className="flex flex-col sm:flex-row items-center gap-4">
        <select
          className="w-full sm:w-72 bg-slate-950 border border-slate-700/50 text-slate-100 rounded-xl px-4 py-3 text-sm font-medium focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all outline-none"
          value={eventId}
          onChange={(e) => setEventId(e.target.value)}
        >
          <option value="" disabled>
            Selecione um evento
          </option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.name}
            </option>
          ))}
        </select>

        <PosTabs tab={tab} setTab={setTab} />
      </div>
    </div>
  );
}
