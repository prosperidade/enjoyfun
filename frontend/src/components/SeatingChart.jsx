import { useState, useEffect, useRef, useCallback } from "react";
import api from "../lib/api";
import toast from "react-hot-toast";
import {
  ChevronDown,
  ChevronUp,
  Plus,
  Trash2,
  LayoutGrid,
  Users,
  GripVertical,
  ZoomIn,
  ZoomOut,
  Circle,
  Square,
} from "lucide-react";

const TABLE_TYPES = {
  round: { label: "Redonda", shape: "circle", defaultCap: 8 },
  rectangular: { label: "Retangular", shape: "rect", defaultCap: 10 },
  imperial: { label: "Imperial", shape: "rect", defaultCap: 20 },
  cocktail: { label: "Cocktail", shape: "circle", defaultCap: 4 },
};

const GRID_SIZE = 20;
const CANVAS_W = 800;
const CANVAS_H = 600;

function snap(v) {
  return Math.round(v / GRID_SIZE) * GRID_SIZE;
}

function SectionShell({ title, count, expanded, toggle, children }) {
  return (
    <div className="border border-gray-800 rounded-xl p-4 bg-gray-900">
      <button type="button" onClick={toggle} className="flex items-center gap-2 w-full text-left">
        <LayoutGrid className="w-4 h-4 text-purple-400" />
        <span className="font-semibold text-white text-sm">{title}</span>
        {count != null && (
          <span className="ml-auto text-xs bg-gray-800 text-gray-300 px-2 py-0.5 rounded-full">
            {count} {count === 1 ? "mesa" : "mesas"}
          </span>
        )}
        {expanded ? <ChevronUp className="w-4 h-4 text-gray-400" /> : <ChevronDown className="w-4 h-4 text-gray-400" />}
      </button>
      {expanded && <div className="mt-4">{children}</div>}
    </div>
  );
}

export default function SeatingChart({ eventId }) {
  const [tables, setTables] = useState([]);
  const [guests, setGuests] = useState([]);
  const [selectedTableId, setSelectedTableId] = useState(null);
  const [dragging, setDragging] = useState(null);
  const [expanded, setExpanded] = useState(true);
  const [zoom, setZoom] = useState(1);
  const [addForm, setAddForm] = useState({ table_number: "", table_name: "", table_type: "round", capacity: "8", section: "" });
  const [showForm, setShowForm] = useState(false);
  const canvasRef = useRef(null);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-tables?event_id=${eventId}`).then((r) => {
      const items = r.data?.data || [];
      setTables(items.map((t, i) => ({
        ...t,
        x: t.x ?? snap(80 + (i % 5) * 140),
        y: t.y ?? snap(80 + Math.floor(i / 5) * 140),
      })));
    });
    // Load guests assigned to tables
    api.get(`/event-participants?event_id=${eventId}`).then((r) => {
      setGuests(r.data?.data || r.data || []);
    }).catch(() => setGuests([]));
  }, [eventId]);

  const handleMouseDown = useCallback((e, tableId) => {
    e.stopPropagation();
    setSelectedTableId(tableId);
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect) return;
    const t = tables.find((x) => x.id === tableId);
    if (!t) return;
    setDragging({
      id: tableId,
      offsetX: (e.clientX - rect.left) / zoom - t.x,
      offsetY: (e.clientY - rect.top) / zoom - t.y,
    });
  }, [tables, zoom]);

  const handleMouseMove = useCallback((e) => {
    if (!dragging) return;
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect) return;
    const x = snap(Math.max(0, Math.min(CANVAS_W - 60, (e.clientX - rect.left) / zoom - dragging.offsetX)));
    const y = snap(Math.max(0, Math.min(CANVAS_H - 60, (e.clientY - rect.top) / zoom - dragging.offsetY)));
    setTables((prev) => prev.map((t) => t.id === dragging.id ? { ...t, x, y } : t));
  }, [dragging, zoom]);

  const handleMouseUp = useCallback(() => {
    setDragging(null);
  }, []);

  const handleAdd = async () => {
    if (!addForm.table_number) return toast.error("Numero da mesa obrigatorio");
    if (!eventId) return;
    try {
      const res = await api.post("/event-tables", {
        event_id: eventId,
        table_number: Number(addForm.table_number),
        table_name: addForm.table_name || null,
        table_type: addForm.table_type,
        capacity: addForm.capacity ? Number(addForm.capacity) : 8,
        section: addForm.section || null,
      });
      const newTable = res.data?.data || res.data;
      setTables((prev) => [...prev, {
        ...newTable,
        x: snap(CANVAS_W / 2 - 40),
        y: snap(CANVAS_H / 2 - 40),
      }]);
      setAddForm({ table_number: "", table_name: "", table_type: "round", capacity: "8", section: "" });
      setShowForm(false);
      toast.success("Mesa adicionada");
    } catch { toast.error("Erro ao adicionar mesa"); }
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-tables/${id}`);
      setTables((prev) => prev.filter((t) => t.id !== id));
      if (selectedTableId === id) setSelectedTableId(null);
      toast.success("Mesa removida");
    } catch { toast.error("Erro ao remover mesa"); }
  };

  const selectedTable = tables.find((t) => t.id === selectedTableId);
  const guestsAtTable = (tableId) => guests.filter((g) => g.table_id === tableId);

  const getTableSize = (type) => {
    const cfg = TABLE_TYPES[type] || TABLE_TYPES.round;
    return cfg.shape === "circle" ? 90 : 110;
  };

  return (
    <SectionShell title="Mapa de Mesas" count={tables.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <p className="text-xs text-yellow-500">Salve o evento primeiro.</p>}

      {/* Toolbar */}
      <div className="flex items-center gap-2 mb-3">
        <button
          type="button"
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium px-3 py-2 rounded-lg"
        >
          <Plus className="w-3 h-3" /> Nova mesa
        </button>
        <div className="ml-auto flex items-center gap-1">
          <button type="button" onClick={() => setZoom((z) => Math.max(0.5, z - 0.1))} className="p-1.5 bg-gray-800 rounded-lg hover:bg-gray-700">
            <ZoomOut className="w-3 h-3 text-gray-400" />
          </button>
          <span className="text-xs text-gray-500 w-10 text-center">{Math.round(zoom * 100)}%</span>
          <button type="button" onClick={() => setZoom((z) => Math.min(2, z + 0.1))} className="p-1.5 bg-gray-800 rounded-lg hover:bg-gray-700">
            <ZoomIn className="w-3 h-3 text-gray-400" />
          </button>
        </div>
        {selectedTableId && (
          <button type="button" onClick={() => handleDelete(selectedTableId)} className="flex items-center gap-1 bg-red-600/20 text-red-400 text-xs px-2 py-1.5 rounded-lg hover:bg-red-600/30">
            <Trash2 className="w-3 h-3" /> Remover mesa
          </button>
        )}
      </div>

      {/* Add form */}
      {showForm && (
        <div className="bg-gray-800 rounded-lg p-3 mb-3 space-y-2">
          <div className="grid grid-cols-5 gap-2">
            <input className="bg-gray-900 border border-gray-700 rounded px-2 py-1.5 text-sm text-white" type="number" placeholder="Numero" value={addForm.table_number} onChange={(e) => setAddForm({ ...addForm, table_number: e.target.value })} />
            <input className="bg-gray-900 border border-gray-700 rounded px-2 py-1.5 text-sm text-white" placeholder="Nome" value={addForm.table_name} onChange={(e) => setAddForm({ ...addForm, table_name: e.target.value })} />
            <select className="bg-gray-900 border border-gray-700 rounded px-2 py-1.5 text-sm text-white" value={addForm.table_type} onChange={(e) => setAddForm({ ...addForm, table_type: e.target.value })}>
              {Object.entries(TABLE_TYPES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
            </select>
            <input className="bg-gray-900 border border-gray-700 rounded px-2 py-1.5 text-sm text-white" type="number" placeholder="Lugares" value={addForm.capacity} onChange={(e) => setAddForm({ ...addForm, capacity: e.target.value })} />
            <input className="bg-gray-900 border border-gray-700 rounded px-2 py-1.5 text-sm text-white" placeholder="Secao" value={addForm.section} onChange={(e) => setAddForm({ ...addForm, section: e.target.value })} />
          </div>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setShowForm(false)} className="text-xs text-gray-500 hover:text-white px-3 py-1.5">Cancelar</button>
            <button type="button" onClick={handleAdd} className="bg-purple-600 text-white text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-purple-700">Salvar</button>
          </div>
        </div>
      )}

      {/* Canvas */}
      <div className="overflow-auto border border-gray-700 rounded-xl bg-gray-950 relative" style={{ maxHeight: 650 }}>
        <div
          ref={canvasRef}
          className="relative select-none"
          style={{
            width: CANVAS_W * zoom,
            height: CANVAS_H * zoom,
            backgroundImage: `radial-gradient(circle, rgba(139,92,246,0.1) 1px, transparent 1px)`,
            backgroundSize: `${GRID_SIZE * zoom}px ${GRID_SIZE * zoom}px`,
            cursor: dragging ? "grabbing" : "default",
          }}
          onMouseMove={handleMouseMove}
          onMouseUp={handleMouseUp}
          onMouseLeave={handleMouseUp}
          onClick={() => setSelectedTableId(null)}
        >
          {tables.map((t) => {
            const cfg = TABLE_TYPES[t.table_type] || TABLE_TYPES.round;
            const size = getTableSize(t.table_type);
            const isSelected = selectedTableId === t.id;
            const seated = guestsAtTable(t.id).length;
            const cap = t.capacity || cfg.defaultCap;
            const isFull = seated >= cap;
            const fillPct = cap > 0 ? Math.min(100, Math.round((seated / cap) * 100)) : 0;

            return (
              <div
                key={t.id}
                className={`absolute flex flex-col items-center justify-center transition-shadow ${
                  isSelected ? "ring-2 ring-purple-400 shadow-lg shadow-purple-500/30" : ""
                }`}
                style={{
                  left: t.x * zoom,
                  top: t.y * zoom,
                  width: size * zoom,
                  height: size * zoom,
                  borderRadius: cfg.shape === "circle" ? "50%" : 12 * zoom,
                  border: `2px solid ${isFull ? "#22c55e60" : isSelected ? "#a855f7" : "#374151"}`,
                  backgroundColor: isFull ? "rgba(34,197,94,0.08)" : "rgba(139,92,246,0.06)",
                  cursor: dragging?.id === t.id ? "grabbing" : "grab",
                  zIndex: isSelected ? 10 : 1,
                }}
                onMouseDown={(e) => handleMouseDown(e, t.id)}
              >
                <span className="text-white font-bold" style={{ fontSize: Math.max(10, 14 * zoom) }}>
                  {t.table_name || `Mesa ${t.table_number}`}
                </span>
                <span className="font-mono" style={{
                  fontSize: Math.max(9, 11 * zoom),
                  color: isFull ? "#22c55e" : "#9ca3af",
                }}>
                  {seated}/{cap}
                </span>
                {t.section && (
                  <span className="text-gray-600 uppercase" style={{ fontSize: Math.max(7, 8 * zoom) }}>
                    {t.section}
                  </span>
                )}
                {/* Occupation ring */}
                <svg
                  className="absolute inset-0 pointer-events-none"
                  viewBox="0 0 100 100"
                  style={{ width: "100%", height: "100%", transform: "rotate(-90deg)" }}
                >
                  <circle cx="50" cy="50" r="46" fill="none" stroke="transparent" strokeWidth="3" />
                  <circle
                    cx="50" cy="50" r="46" fill="none"
                    stroke={isFull ? "#22c55e" : "#a855f7"}
                    strokeWidth="3"
                    strokeDasharray={`${fillPct * 2.89} ${289}`}
                    strokeLinecap="round"
                    opacity="0.5"
                  />
                </svg>
              </div>
            );
          })}
        </div>
      </div>

      {/* Selected table details */}
      {selectedTable && (
        <div className="mt-3 bg-gray-800 rounded-lg p-3">
          <div className="flex items-center gap-3 mb-2">
            <Users className="w-4 h-4 text-purple-400" />
            <span className="text-sm text-white font-medium">
              {selectedTable.table_name || `Mesa ${selectedTable.table_number}`}
            </span>
            <span className="text-xs text-gray-500">
              {TABLE_TYPES[selectedTable.table_type]?.label || selectedTable.table_type} — {selectedTable.capacity || 8} lugares
            </span>
          </div>
          {guestsAtTable(selectedTable.id).length > 0 ? (
            <div className="flex flex-wrap gap-1.5">
              {guestsAtTable(selectedTable.id).map((g) => (
                <span key={g.id} className="bg-purple-900/40 text-purple-300 text-xs px-2 py-0.5 rounded-full">
                  {g.name || g.full_name || `Convidado #${g.id}`}
                </span>
              ))}
            </div>
          ) : (
            <p className="text-xs text-gray-500">Nenhum convidado atribuido a esta mesa.</p>
          )}
        </div>
      )}

      <p className="text-[11px] text-gray-600 mt-2">
        Arraste as mesas para organizar o layout. O anel mostra a ocupacao. Convidados sao atribuidos na aba Participantes.
      </p>
    </SectionShell>
  );
}
