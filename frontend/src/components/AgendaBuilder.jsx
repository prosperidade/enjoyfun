import { useState, useEffect, useMemo } from "react";
import api from "../lib/api";
import toast from "react-hot-toast";
import {
  ChevronDown,
  ChevronUp,
  Plus,
  Trash2,
  Calendar,
  Clock,
  User,
  X,
} from "lucide-react";

const SESSION_TYPES = ["keynote", "panel", "workshop", "poster", "roundtable", "break"];
const SESSION_LABELS = { keynote: "Keynote", panel: "Painel", workshop: "Workshop", poster: "Poster", roundtable: "Mesa Redonda", break: "Intervalo" };
const SESSION_COLORS = {
  keynote: { bg: "bg-purple-900/40", border: "border-purple-500/40", text: "text-purple-300" },
  panel: { bg: "bg-blue-900/40", border: "border-blue-500/40", text: "text-blue-300" },
  workshop: { bg: "bg-emerald-900/40", border: "border-emerald-500/40", text: "text-emerald-300" },
  poster: { bg: "bg-amber-900/40", border: "border-amber-500/40", text: "text-amber-300" },
  roundtable: { bg: "bg-cyan-900/40", border: "border-cyan-500/40", text: "text-cyan-300" },
  break: { bg: "bg-gray-800/60", border: "border-gray-600/40", text: "text-gray-400" },
};

const HOUR_HEIGHT = 80; // px per hour
const START_HOUR = 7;
const END_HOUR = 24;

function SectionShell({ title, count, expanded, toggle, children }) {
  return (
    <div className="border border-gray-800 rounded-xl p-4 bg-gray-900">
      <button type="button" onClick={toggle} className="flex items-center gap-2 w-full text-left">
        <Calendar className="w-4 h-4 text-purple-400" />
        <span className="font-semibold text-white text-sm">{title}</span>
        {count != null && (
          <span className="ml-auto text-xs bg-gray-800 text-gray-300 px-2 py-0.5 rounded-full">
            {count} {count === 1 ? "sessao" : "sessoes"}
          </span>
        )}
        {expanded ? <ChevronUp className="w-4 h-4 text-gray-400" /> : <ChevronDown className="w-4 h-4 text-gray-400" />}
      </button>
      {expanded && <div className="mt-4">{children}</div>}
    </div>
  );
}

const inputCls = "bg-gray-900 border border-gray-700 rounded px-2 py-1.5 text-sm text-white focus:outline-none focus:ring-1 focus:ring-purple-500";

function fmtTime(dt) {
  if (!dt) return "";
  const d = new Date(dt);
  if (isNaN(d.getTime())) return dt;
  return d.toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" });
}

function getMinutes(dt) {
  const d = new Date(dt);
  if (isNaN(d.getTime())) return 0;
  return d.getHours() * 60 + d.getMinutes();
}

export default function AgendaBuilder({ eventId }) {
  const [sessions, setSessions] = useState([]);
  const [stages, setStages] = useState([]);
  const [expanded, setExpanded] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    title: "", session_type: "keynote", speaker_name: "",
    starts_at: "", ends_at: "", max_capacity: "", stage_id: "",
  });

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-sessions?event_id=${eventId}`).then((r) => setSessions(r.data?.data || []));
    api.get(`/event-stages?event_id=${eventId}`).then((r) => setStages(r.data?.data || [])).catch(() => {});
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.title.trim()) return toast.error("Titulo obrigatorio");
    if (!form.starts_at || !form.ends_at) return toast.error("Horarios obrigatorios");
    if (!eventId) return;
    try {
      const res = await api.post("/event-sessions", {
        event_id: eventId,
        title: form.title,
        session_type: form.session_type,
        speaker_name: form.speaker_name || null,
        starts_at: form.starts_at,
        ends_at: form.ends_at,
        max_capacity: form.max_capacity ? Number(form.max_capacity) : null,
        stage_id: form.stage_id || null,
      });
      setSessions((prev) => [...prev, res.data?.data || res.data]);
      setForm({ title: "", session_type: "keynote", speaker_name: "", starts_at: "", ends_at: "", max_capacity: "", stage_id: "" });
      setShowForm(false);
      toast.success("Sessao adicionada");
    } catch { toast.error("Erro ao adicionar sessao"); }
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-sessions/${id}`);
      setSessions((prev) => prev.filter((s) => s.id !== id));
      toast.success("Sessao removida");
    } catch { toast.error("Erro ao remover"); }
  };

  // Group sessions by stage for multi-track view
  const tracks = useMemo(() => {
    const stageMap = new Map();
    stageMap.set("none", { name: "Sem palco", sessions: [] });
    stages.forEach((s) => stageMap.set(String(s.id), { name: s.name, sessions: [] }));

    sessions.forEach((s) => {
      const key = s.stage_id ? String(s.stage_id) : "none";
      if (!stageMap.has(key)) stageMap.set(key, { name: s.stage_name || `Palco ${key}`, sessions: [] });
      stageMap.get(key).sessions.push(s);
    });

    // Remove empty tracks except "none" when it has sessions
    const result = [];
    for (const [key, track] of stageMap) {
      if (track.sessions.length > 0 || (key !== "none" && stages.find((s) => String(s.id) === key))) {
        result.push({ key, ...track });
      }
    }
    return result.length > 0 ? result : [{ key: "none", name: "Agenda", sessions }];
  }, [sessions, stages]);

  const hours = [];
  for (let h = START_HOUR; h <= END_HOUR; h++) hours.push(h);
  const totalHeight = (END_HOUR - START_HOUR) * HOUR_HEIGHT;

  return (
    <SectionShell title="Agenda / Sessoes" count={sessions.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <p className="text-xs text-yellow-500">Salve o evento primeiro.</p>}

      {/* Toolbar */}
      <div className="flex items-center gap-2 mb-3 flex-wrap">
        <button
          type="button"
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium px-3 py-2 rounded-lg"
        >
          <Plus className="w-3 h-3" /> Nova sessao
        </button>
        {/* Legend */}
        <div className="flex items-center gap-2 ml-auto flex-wrap">
          {Object.entries(SESSION_LABELS).map(([k, v]) => {
            const c = SESSION_COLORS[k];
            return (
              <span key={k} className={`text-[10px] px-1.5 py-0.5 rounded ${c.bg} ${c.text} border ${c.border}`}>
                {v}
              </span>
            );
          })}
        </div>
      </div>

      {/* Add form */}
      {showForm && (
        <div className="bg-gray-800 rounded-lg p-3 mb-3 space-y-2">
          <div className="grid grid-cols-3 gap-2">
            <input className={inputCls} placeholder="Titulo da sessao" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
            <select className={inputCls} value={form.session_type} onChange={(e) => setForm({ ...form, session_type: e.target.value })}>
              {SESSION_TYPES.map((t) => <option key={t} value={t}>{SESSION_LABELS[t]}</option>)}
            </select>
            <input className={inputCls} placeholder="Palestrante" value={form.speaker_name} onChange={(e) => setForm({ ...form, speaker_name: e.target.value })} />
          </div>
          <div className="grid grid-cols-4 gap-2">
            <input className={inputCls} type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
            <input className={inputCls} type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
            <input className={inputCls} type="number" placeholder="Capacidade" value={form.max_capacity} onChange={(e) => setForm({ ...form, max_capacity: e.target.value })} />
            <select className={inputCls} value={form.stage_id} onChange={(e) => setForm({ ...form, stage_id: e.target.value })}>
              <option value="">Sem palco</option>
              {stages.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setShowForm(false)} className="text-xs text-gray-500 hover:text-white px-3 py-1.5">Cancelar</button>
            <button type="button" onClick={handleAdd} className="bg-purple-600 text-white text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-purple-700">Salvar</button>
          </div>
        </div>
      )}

      {/* Timeline view */}
      {sessions.length > 0 ? (
        <div className="overflow-auto border border-gray-700 rounded-xl bg-gray-950" style={{ maxHeight: 600 }}>
          <div className="flex" style={{ minWidth: tracks.length * 200 + 60 }}>
            {/* Time column */}
            <div className="flex-shrink-0 w-14 border-r border-gray-800 relative" style={{ height: totalHeight }}>
              {hours.map((h) => (
                <div
                  key={h}
                  className="absolute left-0 w-full flex items-start justify-end pr-2"
                  style={{ top: (h - START_HOUR) * HOUR_HEIGHT }}
                >
                  <span className="text-[10px] text-gray-500 font-mono leading-none mt-[-5px]">
                    {String(h).padStart(2, "0")}:00
                  </span>
                </div>
              ))}
            </div>

            {/* Track columns */}
            {tracks.map((track) => (
              <div key={track.key} className="flex-1 min-w-[180px] relative border-r border-gray-800/50" style={{ height: totalHeight }}>
                {/* Track header */}
                <div className="sticky top-0 z-10 bg-gray-900/90 backdrop-blur-sm border-b border-gray-800 px-2 py-1.5">
                  <span className="text-xs font-medium text-gray-300">{track.name}</span>
                </div>

                {/* Hour grid lines */}
                {hours.map((h) => (
                  <div
                    key={h}
                    className="absolute left-0 w-full border-t border-gray-800/30"
                    style={{ top: (h - START_HOUR) * HOUR_HEIGHT }}
                  />
                ))}

                {/* Session blocks */}
                {track.sessions.map((s) => {
                  const startMin = getMinutes(s.starts_at);
                  const endMin = getMinutes(s.ends_at);
                  if (!startMin && !endMin) return null;
                  const top = ((startMin / 60) - START_HOUR) * HOUR_HEIGHT;
                  const height = Math.max(24, ((endMin - startMin) / 60) * HOUR_HEIGHT);
                  const colors = SESSION_COLORS[s.session_type] || SESSION_COLORS.keynote;

                  return (
                    <div
                      key={s.id}
                      className={`absolute left-1 right-1 rounded-lg border ${colors.bg} ${colors.border} overflow-hidden group cursor-pointer`}
                      style={{ top: Math.max(0, top), height }}
                      title={`${s.title} — ${fmtTime(s.starts_at)}-${fmtTime(s.ends_at)}`}
                    >
                      <div className="p-1.5 h-full flex flex-col justify-between">
                        <div>
                          <div className={`text-xs font-semibold ${colors.text} leading-tight truncate`}>
                            {s.title}
                          </div>
                          {s.speaker_name && height > 40 && (
                            <div className="text-[10px] text-gray-400 truncate mt-0.5">
                              <User className="w-2.5 h-2.5 inline mr-0.5" />
                              {s.speaker_name}
                            </div>
                          )}
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-[9px] text-gray-500 font-mono">
                            {fmtTime(s.starts_at)}-{fmtTime(s.ends_at)}
                          </span>
                          <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); handleDelete(s.id); }}
                            className="opacity-0 group-hover:opacity-100 p-0.5 text-red-400 hover:bg-red-900/30 rounded transition-opacity"
                          >
                            <Trash2 className="w-3 h-3" />
                          </button>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        </div>
      ) : (
        <div className="text-center py-8 text-gray-500 text-sm border border-gray-800 rounded-xl bg-gray-950">
          Nenhuma sessao cadastrada. Clique em "Nova sessao" para comecar.
        </div>
      )}

      <p className="text-[11px] text-gray-600 mt-2">
        Sessoes sao exibidas por palco em formato de grade horaria. Cada cor indica um tipo de sessao.
      </p>
    </SectionShell>
  );
}
