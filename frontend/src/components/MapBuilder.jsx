import { useState, useEffect, useRef, useCallback } from "react";
import api from "../lib/api";
import toast from "react-hot-toast";
import {
  ChevronDown,
  ChevronUp,
  Plus,
  Trash2,
  Map,
  Move,
  ZoomIn,
  ZoomOut,
  Mic2,
  Beer,
  UtensilsCrossed,
  ShoppingBag,
  Bath,
  DoorOpen,
  Car,
  GripVertical,
} from "lucide-react";

const ELEMENT_TYPES = {
  stage: { label: "Palco", icon: Mic2, color: "#a855f7" },
  bar: { label: "Bar", icon: Beer, color: "#3b82f6" },
  food: { label: "Alimentacao", icon: UtensilsCrossed, color: "#f59e0b" },
  shop: { label: "Loja", icon: ShoppingBag, color: "#10b981" },
  wc: { label: "Banheiro", icon: Bath, color: "#6b7280" },
  entrance: { label: "Entrada", icon: DoorOpen, color: "#ef4444" },
  parking: { label: "Estacionamento", icon: Car, color: "#64748b" },
};

const GRID_SIZE = 20;
const CANVAS_W = 800;
const CANVAS_H = 500;

function snap(v) {
  return Math.round(v / GRID_SIZE) * GRID_SIZE;
}

function SectionShell({ title, count, expanded, toggle, children }) {
  return (
    <div className="border border-gray-800 rounded-xl p-4 bg-gray-900">
      <button type="button" onClick={toggle} className="flex items-center gap-2 w-full text-left">
        <Map className="w-4 h-4 text-purple-400" />
        <span className="font-semibold text-white text-sm">{title}</span>
        {count != null && (
          <span className="ml-auto text-xs bg-gray-800 text-gray-300 px-2 py-0.5 rounded-full">
            {count} {count === 1 ? "item" : "itens"}
          </span>
        )}
        {expanded ? <ChevronUp className="w-4 h-4 text-gray-400" /> : <ChevronDown className="w-4 h-4 text-gray-400" />}
      </button>
      {expanded && <div className="mt-4">{children}</div>}
    </div>
  );
}

export default function MapBuilder({ eventId }) {
  const [elements, setElements] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [dragging, setDragging] = useState(null);
  const [expanded, setExpanded] = useState(true);
  const [zoom, setZoom] = useState(1);
  const canvasRef = useRef(null);

  // Load existing stages and PDV points to place on map
  useEffect(() => {
    if (!eventId) return;
    Promise.all([
      api.get(`/event-stages?event_id=${eventId}`).catch(() => ({ data: { data: [] } })),
      api.get(`/event-pdv-points?event_id=${eventId}`).catch(() => ({ data: { data: [] } })),
    ]).then(([stagesRes, pdvRes]) => {
      const s = stagesRes.data?.data || [];
      const p = pdvRes.data?.data || [];

      // Build visual elements from existing data
      const els = [];
      s.forEach((stage, i) => {
        els.push({
          id: `stage-${stage.id}`,
          sourceId: stage.id,
          source: "stage",
          type: "stage",
          label: stage.name,
          x: snap(60 + (i % 4) * 180),
          y: snap(60 + Math.floor(i / 4) * 120),
          w: 140,
          h: 80,
        });
      });
      p.forEach((pdv, i) => {
        const t = pdv.pdv_type || "bar";
        els.push({
          id: `pdv-${pdv.id}`,
          sourceId: pdv.id,
          source: "pdv",
          type: t,
          label: pdv.name,
          x: snap(60 + (i % 5) * 150),
          y: snap(280 + Math.floor(i / 5) * 100),
          w: 120,
          h: 60,
        });
      });
      setElements(els);
    });
  }, [eventId]);

  const handleMouseDown = useCallback((e, elId) => {
    e.stopPropagation();
    setSelectedId(elId);
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect) return;
    const el = elements.find((x) => x.id === elId);
    if (!el) return;
    setDragging({
      id: elId,
      offsetX: (e.clientX - rect.left) / zoom - el.x,
      offsetY: (e.clientY - rect.top) / zoom - el.y,
    });
  }, [elements, zoom]);

  const handleMouseMove = useCallback((e) => {
    if (!dragging) return;
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect) return;
    const x = snap(Math.max(0, Math.min(CANVAS_W - 40, (e.clientX - rect.left) / zoom - dragging.offsetX)));
    const y = snap(Math.max(0, Math.min(CANVAS_H - 40, (e.clientY - rect.top) / zoom - dragging.offsetY)));
    setElements((prev) => prev.map((el) => el.id === dragging.id ? { ...el, x, y } : el));
  }, [dragging, zoom]);

  const handleMouseUp = useCallback(() => {
    setDragging(null);
  }, []);

  const addElement = (type) => {
    const cfg = ELEMENT_TYPES[type];
    if (!cfg) return;
    const id = `new-${type}-${Date.now()}`;
    setElements((prev) => [
      ...prev,
      {
        id,
        source: "manual",
        type,
        label: cfg.label,
        x: snap(CANVAS_W / 2 - 60),
        y: snap(CANVAS_H / 2 - 30),
        w: type === "stage" ? 140 : 120,
        h: type === "stage" ? 80 : 60,
      },
    ]);
    setSelectedId(id);
    toast.success(`${cfg.label} adicionado ao mapa`);
  };

  const removeSelected = () => {
    if (!selectedId) return;
    setElements((prev) => prev.filter((el) => el.id !== selectedId));
    setSelectedId(null);
  };

  const updateLabel = (id, label) => {
    setElements((prev) => prev.map((el) => el.id === id ? { ...el, label } : el));
  };

  return (
    <SectionShell title="Mapa do Evento" count={elements.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && (
        <p className="text-xs text-yellow-500">Salve o evento primeiro para usar o mapa.</p>
      )}

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2 mb-3">
        {Object.entries(ELEMENT_TYPES).map(([key, cfg]) => {
          const Icon = cfg.icon;
          return (
            <button
              key={key}
              type="button"
              onClick={() => addElement(key)}
              className="flex items-center gap-1 bg-gray-800 hover:bg-gray-700 text-white text-xs px-2 py-1.5 rounded-lg border border-gray-700"
              title={`Adicionar ${cfg.label}`}
            >
              <Icon className="w-3 h-3" style={{ color: cfg.color }} />
              <span>{cfg.label}</span>
            </button>
          );
        })}
        <div className="ml-auto flex items-center gap-1">
          <button type="button" onClick={() => setZoom((z) => Math.max(0.5, z - 0.1))} className="p-1.5 bg-gray-800 rounded-lg hover:bg-gray-700">
            <ZoomOut className="w-3 h-3 text-gray-400" />
          </button>
          <span className="text-xs text-gray-500 w-10 text-center">{Math.round(zoom * 100)}%</span>
          <button type="button" onClick={() => setZoom((z) => Math.min(2, z + 0.1))} className="p-1.5 bg-gray-800 rounded-lg hover:bg-gray-700">
            <ZoomIn className="w-3 h-3 text-gray-400" />
          </button>
        </div>
        {selectedId && (
          <button type="button" onClick={removeSelected} className="flex items-center gap-1 bg-red-600/20 text-red-400 text-xs px-2 py-1.5 rounded-lg hover:bg-red-600/30">
            <Trash2 className="w-3 h-3" /> Remover
          </button>
        )}
      </div>

      {/* Canvas */}
      <div className="overflow-auto border border-gray-700 rounded-xl bg-gray-950 relative" style={{ maxHeight: 560 }}>
        <div
          ref={canvasRef}
          className="relative select-none"
          style={{
            width: CANVAS_W * zoom,
            height: CANVAS_H * zoom,
            backgroundImage: `radial-gradient(circle, rgba(139,92,246,0.15) 1px, transparent 1px)`,
            backgroundSize: `${GRID_SIZE * zoom}px ${GRID_SIZE * zoom}px`,
            cursor: dragging ? "grabbing" : "default",
          }}
          onMouseMove={handleMouseMove}
          onMouseUp={handleMouseUp}
          onMouseLeave={handleMouseUp}
          onClick={() => setSelectedId(null)}
        >
          {elements.map((el) => {
            const cfg = ELEMENT_TYPES[el.type] || ELEMENT_TYPES.stage;
            const Icon = cfg.icon;
            const isSelected = selectedId === el.id;
            return (
              <div
                key={el.id}
                className={`absolute flex flex-col items-center justify-center rounded-lg border-2 transition-shadow ${
                  isSelected ? "shadow-lg shadow-purple-500/30 ring-2 ring-purple-400" : "hover:shadow-md"
                }`}
                style={{
                  left: el.x * zoom,
                  top: el.y * zoom,
                  width: el.w * zoom,
                  height: el.h * zoom,
                  backgroundColor: `${cfg.color}15`,
                  borderColor: isSelected ? "#a855f7" : `${cfg.color}60`,
                  cursor: dragging?.id === el.id ? "grabbing" : "grab",
                  zIndex: isSelected ? 10 : 1,
                }}
                onMouseDown={(e) => handleMouseDown(e, el.id)}
              >
                <Icon className="mb-0.5" style={{ color: cfg.color, width: 16 * zoom, height: 16 * zoom }} />
                <span
                  className="text-center px-1 leading-tight text-white font-medium truncate w-full"
                  style={{ fontSize: Math.max(9, 11 * zoom) }}
                >
                  {el.label}
                </span>
                <GripVertical className="absolute top-0.5 right-0.5 text-gray-600" style={{ width: 10 * zoom, height: 10 * zoom }} />
              </div>
            );
          })}
        </div>
      </div>

      {/* Selected element editor */}
      {selectedId && (() => {
        const el = elements.find((x) => x.id === selectedId);
        if (!el) return null;
        const cfg = ELEMENT_TYPES[el.type] || ELEMENT_TYPES.stage;
        return (
          <div className="mt-3 bg-gray-800 rounded-lg p-3 flex items-center gap-3">
            <cfg.icon className="w-4 h-4 flex-shrink-0" style={{ color: cfg.color }} />
            <input
              className="flex-1 bg-gray-900 border border-gray-700 rounded px-2 py-1 text-sm text-white"
              value={el.label}
              onChange={(e) => updateLabel(el.id, e.target.value)}
              placeholder="Nome do elemento"
            />
            <span className="text-xs text-gray-500">
              x:{el.x} y:{el.y}
            </span>
            <span className="text-xs text-gray-500 capitalize">{cfg.label}</span>
          </div>
        );
      })()}

      <p className="text-[11px] text-gray-600 mt-2">
        Arraste os elementos para posicionar no mapa. Os palcos e pontos de venda cadastrados aparecem automaticamente.
      </p>
    </SectionShell>
  );
}
