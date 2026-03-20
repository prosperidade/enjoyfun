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
    <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-6 bg-gray-900/40 p-4 rounded-2xl border border-gray-800/60">
      <div>
        <h1 className="text-2xl font-black text-white flex items-center gap-3 tracking-wide">
          {sectorInfo.icon} POS EnjoyFun{" "}
          <span className="text-gray-500 font-medium">| {sectorInfo.title}</span>
        </h1>
        {eventsError ? (
          <p className="mt-2 text-sm text-red-300">{eventsError}</p>
        ) : null}
      </div>

      <div className="flex flex-col sm:flex-row items-center gap-4">
        <select
          className="w-full sm:w-72 bg-gray-950 border border-gray-700 text-white rounded-xl px-4 py-3 text-sm font-medium focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all outline-none"
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
