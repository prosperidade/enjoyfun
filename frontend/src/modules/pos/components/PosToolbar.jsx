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
    <div className="flex items-center justify-between gap-6">
      <PosTabs tab={tab} setTab={setTab} />

      <div className="flex items-center gap-4">
        {eventsError && <p className="text-sm text-red-400">{eventsError}</p>}
        <div className="text-right hidden md:block">
          <p className="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Evento Ativo</p>
        </div>
        <select
          className="w-64 bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-lg px-3 py-1.5 text-sm font-medium focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 transition-all outline-none"
          value={eventId}
          onChange={(e) => setEventId(e.target.value)}
        >
          <option value="" disabled>Selecione um evento</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>{ev.name}</option>
          ))}
        </select>
      </div>
    </div>
  );
}
