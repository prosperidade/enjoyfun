import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import api from "../lib/api";
import toast from "react-hot-toast";
import {
  ChevronDown,
  ChevronUp,
  Plus,
  Trash2,
  Theater,
  LayoutGrid,
  Car,
  Store,
  MapPin,
  Calendar,
  CalendarRange,
  Building2,
  Mail,
  Heart,
  Map,
  Award,
  Upload,
  ExternalLink,
  X,
} from "lucide-react";

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function SectionShell({ icon: Icon, title, count, expanded, toggle, children }) {
  return (
    <div className="border border-gray-800 rounded-xl p-4 bg-gray-900">
      <button
        type="button"
        onClick={toggle}
        className="flex items-center gap-2 w-full text-left"
      >
        <Icon className="w-4 h-4 text-purple-400" />
        <span className="font-semibold text-white text-sm">{title}</span>
        {count != null && (
          <span className="ml-auto text-xs bg-gray-800 text-gray-300 px-2 py-0.5 rounded-full">
            {count} {count === 1 ? "item" : "itens"}
          </span>
        )}
        {expanded ? (
          <ChevronUp className="w-4 h-4 text-gray-400" />
        ) : (
          <ChevronDown className="w-4 h-4 text-gray-400" />
        )}
      </button>
      {expanded && <div className="mt-4 space-y-3">{children}</div>}
    </div>
  );
}

function NoEventNotice() {
  return (
    <p className="text-xs text-yellow-500">
      Salve o evento primeiro para gerenciar os itens.
    </p>
  );
}

const inputCls =
  "w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-purple-500";
const btnAdd =
  "flex items-center gap-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium px-3 py-2 rounded-lg disabled:opacity-40";
const btnDel =
  "text-red-400 hover:text-red-300 p-1 rounded-lg hover:bg-gray-800";

// ---------------------------------------------------------------------------
// 1. StagesSection
// ---------------------------------------------------------------------------

const STAGE_TYPES = ["main", "secondary", "alternative", "auditorium", "room"];

export function StagesSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ name: "", stage_type: "main", capacity: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-stages?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.name.trim()) return toast.error("Nome obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-stages", { ...form, event_id: eventId, capacity: form.capacity ? Number(form.capacity) : null });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ name: "", stage_type: "main", capacity: "" });
      toast.success("Palco adicionado");
    } catch { toast.error("Erro ao adicionar palco"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-stages/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Palco removido");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={Theater} title="Palcos / Stages" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-3 gap-2">
        <input className={inputCls} placeholder="Nome do palco" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <select className={inputCls} value={form.stage_type} onChange={(e) => setForm({ ...form, stage_type: e.target.value })}>
          {STAGE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <input className={inputCls} type="number" placeholder="Capacidade" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar palco
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.name} <span className="text-gray-500">({item.stage_type})</span>{item.capacity ? ` - ${item.capacity} lug.` : ""}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 2. SectorsSection
// ---------------------------------------------------------------------------

const SECTOR_TYPES = ["pista", "vip", "camarote", "backstage", "frontstage", "lounge", "premium", "arquibancada"];

export function SectorsSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ name: "", sector_type: "pista", capacity: "", price_modifier: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-sectors?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.name.trim()) return toast.error("Nome obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-sectors", {
        ...form, event_id: eventId,
        capacity: form.capacity ? Number(form.capacity) : null,
        price_modifier: form.price_modifier ? Number(form.price_modifier) : null,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ name: "", sector_type: "pista", capacity: "", price_modifier: "" });
      toast.success("Setor adicionado");
    } catch { toast.error("Erro ao adicionar setor"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-sectors/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Setor removido");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={LayoutGrid} title="Setores" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-4 gap-2">
        <input className={inputCls} placeholder="Nome do setor" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <select className={inputCls} value={form.sector_type} onChange={(e) => setForm({ ...form, sector_type: e.target.value })}>
          {SECTOR_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <input className={inputCls} type="number" placeholder="Capacidade" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
        <input className={inputCls} type="number" placeholder="R$ modificador" value={form.price_modifier} onChange={(e) => setForm({ ...form, price_modifier: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar setor
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.name} <span className="text-gray-500">({item.sector_type})</span>{item.capacity ? ` - ${item.capacity} lug.` : ""}{item.price_modifier ? ` +R$${item.price_modifier}` : ""}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 3. ParkingConfigSection
// ---------------------------------------------------------------------------

const VEHICLE_TYPES = ["car", "motorcycle", "van", "bus", "truck"];
const VEHICLE_LABELS = { car: "Carro", motorcycle: "Moto", van: "Van", bus: "Onibus", truck: "Caminhao" };

export function ParkingConfigSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ vehicle_type: "car", price: "", total_spots: "", vip_spots: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-parking-config?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!eventId) return;
    if (items.some((i) => i.vehicle_type === form.vehicle_type)) {
      return toast.error(`Tipo "${VEHICLE_LABELS[form.vehicle_type]}" ja cadastrado para este evento`);
    }
    setLoading(true);
    try {
      const res = await api.post("/event-parking-config", {
        event_id: eventId,
        vehicle_type: form.vehicle_type,
        price: form.price ? Number(form.price) : 0,
        total_spots: form.total_spots ? Number(form.total_spots) : null,
        vip_spots: form.vip_spots ? Number(form.vip_spots) : null,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ vehicle_type: "car", price: "", total_spots: "", vip_spots: "" });
      toast.success("Config de estacionamento adicionada");
    } catch { toast.error("Erro ao adicionar config"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-parking-config/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Config removida");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={Car} title="Estacionamento" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-4 gap-2">
        <select className={inputCls} value={form.vehicle_type} onChange={(e) => setForm({ ...form, vehicle_type: e.target.value })}>
          {VEHICLE_TYPES.map((t) => <option key={t} value={t}>{VEHICLE_LABELS[t]}</option>)}
        </select>
        <input className={inputCls} type="number" placeholder="Preco R$" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
        <input className={inputCls} type="number" placeholder="Vagas total" value={form.total_spots} onChange={(e) => setForm({ ...form, total_spots: e.target.value })} />
        <input className={inputCls} type="number" placeholder="Vagas VIP" value={form.vip_spots} onChange={(e) => setForm({ ...form, vip_spots: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar tipo de veiculo
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{VEHICLE_LABELS[item.vehicle_type] || item.vehicle_type} - R${item.price || 0}{item.total_spots ? ` | ${item.total_spots} vagas` : ""}{item.vip_spots ? ` (${item.vip_spots} VIP)` : ""}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 4. PdvPointsSection
// ---------------------------------------------------------------------------

const PDV_TYPES = ["bar", "food", "shop"];
const PDV_LABELS = { bar: "Bar", food: "Alimentacao", shop: "Loja" };

export function PdvPointsSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [stages, setStages] = useState([]);
  const [form, setForm] = useState({ name: "", pdv_type: "bar", stage_id: "", location_description: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-pdv-points?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
    api.get(`/event-stages?event_id=${eventId}`).then((r) => setStages(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.name.trim()) return toast.error("Nome obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-pdv-points", {
        event_id: eventId,
        name: form.name,
        pdv_type: form.pdv_type,
        stage_id: form.stage_id || null,
        location_description: form.location_description || null,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ name: "", pdv_type: "bar", stage_id: "", location_description: "" });
      toast.success("Ponto de venda adicionado");
    } catch { toast.error("Erro ao adicionar PDV"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-pdv-points/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("PDV removido");
    } catch { toast.error("Erro ao remover"); }
  };

  const stageName = (sid) => stages.find((s) => s.id === sid)?.name || "";

  return (
    <SectionShell icon={Store} title="Pontos de Venda (PDV)" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-2 gap-2">
        <input className={inputCls} placeholder="Nome do PDV" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <select className={inputCls} value={form.pdv_type} onChange={(e) => setForm({ ...form, pdv_type: e.target.value })}>
          {PDV_TYPES.map((t) => <option key={t} value={t}>{PDV_LABELS[t]}</option>)}
        </select>
        <select className={inputCls} value={form.stage_id} onChange={(e) => setForm({ ...form, stage_id: e.target.value })}>
          <option value="">{eventId ? "Sem palco vinculado" : "Salve o evento primeiro"}</option>
          {stages.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <input className={inputCls} placeholder="Descricao do local" value={form.location_description} onChange={(e) => setForm({ ...form, location_description: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar PDV
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.name} <span className="text-gray-500">({PDV_LABELS[item.pdv_type] || item.pdv_type})</span>{stageName(item.stage_id) ? ` @ ${stageName(item.stage_id)}` : ""}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 5. LocationSection (modifies parent form — no API calls)
// ---------------------------------------------------------------------------

const VENUE_TYPES = ["indoor", "outdoor", "hybrid"];

export function LocationSection({ form, setForm }) {
  const [expanded, setExpanded] = useState(true);

  const set = (field) => (e) => setForm((prev) => ({ ...prev, [field]: e.target.value }));

  return (
    <SectionShell icon={MapPin} title="Localizacao" count={null} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      <div className="grid grid-cols-3 gap-2">
        <input className={inputCls} placeholder="Cidade" value={form.city || ""} onChange={set("city")} />
        <input className={inputCls} placeholder="Estado" value={form.state || ""} onChange={set("state")} />
        <input className={inputCls} placeholder="Pais" value={form.country || "BR"} onChange={set("country")} />
      </div>
      <div className="grid grid-cols-3 gap-2">
        <input className={inputCls} placeholder="CEP" value={form.zip_code || ""} onChange={set("zip_code")} />
        <select className={inputCls} value={form.venue_type || ""} onChange={set("venue_type")}>
          <option value="">Tipo de venue</option>
          {VENUE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <input className={inputCls} placeholder="Cole a URL do Google Maps aqui"
          value={form.map_url || ""}
          onChange={(e) => {
            const url = e.target.value;
            setForm(prev => ({ ...prev, map_url: url }));
            const match = url.match(/@(-?\d+\.?\d*),(-?\d+\.?\d*)/);
            if (match) {
              setForm(prev => ({ ...prev, map_url: url, latitude: match[1], longitude: match[2] }));
            }
          }}
        />
      </div>
      <input type="hidden" value={form.latitude || ""} />
      <input type="hidden" value={form.longitude || ""} />
      <div className="grid grid-cols-1 gap-2">
        <input className={inputCls} placeholder="Classificacao etaria" value={form.age_rating || ""} onChange={set("age_rating")} />
      </div>
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 6. SeatingSection (Mapa de Mesas)
// ---------------------------------------------------------------------------

const TABLE_TYPES = ["round", "rectangular", "imperial", "cocktail"];
const TABLE_LABELS = { round: "Redonda", rectangular: "Retangular", imperial: "Imperial", cocktail: "Cocktail" };

export function SeatingSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ table_number: "", table_name: "", table_type: "round", capacity: "8", section: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-tables?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.table_number) return toast.error("Numero da mesa obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-tables", {
        event_id: eventId,
        table_number: Number(form.table_number),
        table_name: form.table_name || null,
        table_type: form.table_type,
        capacity: form.capacity ? Number(form.capacity) : 8,
        section: form.section || null,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ table_number: "", table_name: "", table_type: "round", capacity: "8", section: "" });
      toast.success("Mesa adicionada");
    } catch { toast.error("Erro ao adicionar mesa"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-tables/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Mesa removida");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={LayoutGrid} title="Mapa de Mesas" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-5 gap-2">
        <input className={inputCls} type="number" placeholder="Numero" value={form.table_number} onChange={(e) => setForm({ ...form, table_number: e.target.value })} />
        <input className={inputCls} placeholder="Nome da mesa" value={form.table_name} onChange={(e) => setForm({ ...form, table_name: e.target.value })} />
        <select className={inputCls} value={form.table_type} onChange={(e) => setForm({ ...form, table_type: e.target.value })}>
          {TABLE_TYPES.map((t) => <option key={t} value={t}>{TABLE_LABELS[t]}</option>)}
        </select>
        <input className={inputCls} type="number" placeholder="Capacidade" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
        <input className={inputCls} placeholder="Secao" value={form.section} onChange={(e) => setForm({ ...form, section: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar mesa
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>Mesa {item.table_number}{item.table_name ? ` — ${item.table_name}` : ""} <span className="text-gray-500">({TABLE_LABELS[item.table_type] || item.table_type})</span> - {item.capacity || 8} lug.</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 7. SessionsSection (Agenda / Sessoes)
// ---------------------------------------------------------------------------

const SESSION_TYPES = ["keynote", "panel", "workshop", "poster", "roundtable", "break"];
const SESSION_LABELS = { keynote: "Keynote", panel: "Painel", workshop: "Workshop", poster: "Poster", roundtable: "Mesa Redonda", break: "Intervalo" };

export function SessionsSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [stages, setStages] = useState([]);
  const [form, setForm] = useState({ title: "", session_type: "keynote", speaker_name: "", starts_at: "", ends_at: "", max_capacity: "", stage_id: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-sessions?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
    api.get(`/event-stages?event_id=${eventId}`).then((r) => setStages(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.title.trim()) return toast.error("Titulo obrigatorio");
    if (!form.starts_at || !form.ends_at) return toast.error("Horario de inicio e fim obrigatorios");
    if (!eventId) return;
    setLoading(true);
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
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ title: "", session_type: "keynote", speaker_name: "", starts_at: "", ends_at: "", max_capacity: "", stage_id: "" });
      toast.success("Sessao adicionada");
    } catch { toast.error("Erro ao adicionar sessao"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-sessions/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Sessao removida");
    } catch { toast.error("Erro ao remover"); }
  };

  const fmtTime = (dt) => dt ? new Date(dt).toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" }) : "";

  return (
    <SectionShell icon={Calendar} title="Agenda / Sessoes" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-3 gap-2">
        <input className={inputCls} placeholder="Titulo da sessao" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        <select className={inputCls} value={form.session_type} onChange={(e) => setForm({ ...form, session_type: e.target.value })}>
          {SESSION_TYPES.map((t) => <option key={t} value={t}>{SESSION_LABELS[t]}</option>)}
        </select>
        <input className={inputCls} placeholder="Nome do palestrante" value={form.speaker_name} onChange={(e) => setForm({ ...form, speaker_name: e.target.value })} />
      </div>
      <div className="grid grid-cols-4 gap-2">
        <input className={inputCls} type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
        <input className={inputCls} type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
        <input className={inputCls} type="number" placeholder="Capacidade max" value={form.max_capacity} onChange={(e) => setForm({ ...form, max_capacity: e.target.value })} />
        <select className={inputCls} value={form.stage_id} onChange={(e) => setForm({ ...form, stage_id: e.target.value })}>
          <option value="">{eventId ? "Sem palco vinculado" : "Salve o evento primeiro"}</option>
          {stages.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar sessao
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.title}{item.speaker_name ? ` — ${item.speaker_name}` : ""} <span className="text-gray-500">({SESSION_LABELS[item.session_type] || item.session_type})</span> {fmtTime(item.starts_at)}–{fmtTime(item.ends_at)}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 8. ExhibitorsSection (Expositores)
// ---------------------------------------------------------------------------

const STAND_TYPES = ["standard", "premium", "corner", "island"];
const STAND_LABELS = { standard: "Padrao", premium: "Premium", corner: "Esquina", island: "Ilha" };
const EXHIBITOR_STATUSES = ["pending", "confirmed", "paid", "mounted", "cancelled"];
const STATUS_LABELS = { pending: "Pendente", confirmed: "Confirmado", paid: "Pago", mounted: "Montado", cancelled: "Cancelado" };
const STATUS_COLORS = { pending: "bg-yellow-700", confirmed: "bg-blue-700", paid: "bg-green-700", mounted: "bg-purple-700", cancelled: "bg-red-700" };

export function ExhibitorsSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ company_name: "", cnpj: "", contact_name: "", contact_email: "", contact_phone: "", stand_number: "", stand_type: "standard", status: "pending" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-exhibitors?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.company_name.trim()) return toast.error("Nome da empresa obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-exhibitors", {
        event_id: eventId,
        company_name: form.company_name,
        cnpj: form.cnpj || null,
        contact_name: form.contact_name || null,
        contact_email: form.contact_email || null,
        contact_phone: form.contact_phone || null,
        stand_number: form.stand_number || null,
        stand_type: form.stand_type,
        status: form.status,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ company_name: "", cnpj: "", contact_name: "", contact_email: "", contact_phone: "", stand_number: "", stand_type: "standard", status: "pending" });
      toast.success("Expositor adicionado");
    } catch { toast.error("Erro ao adicionar expositor"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-exhibitors/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Expositor removido");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={Building2} title="Expositores" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-3 gap-2">
        <input className={inputCls} placeholder="Nome da empresa" value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} />
        <input className={inputCls} placeholder="CNPJ" value={form.cnpj} onChange={(e) => setForm({ ...form, cnpj: e.target.value })} />
        <input className={inputCls} placeholder="Contato (nome)" value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} />
      </div>
      <div className="grid grid-cols-3 gap-2">
        <input className={inputCls} type="email" placeholder="Email do contato" value={form.contact_email} onChange={(e) => setForm({ ...form, contact_email: e.target.value })} />
        <input className={inputCls} placeholder="Telefone" value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} />
        <input className={inputCls} placeholder="Numero do stand" value={form.stand_number} onChange={(e) => setForm({ ...form, stand_number: e.target.value })} />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <select className={inputCls} value={form.stand_type} onChange={(e) => setForm({ ...form, stand_type: e.target.value })}>
          {STAND_TYPES.map((t) => <option key={t} value={t}>{STAND_LABELS[t]}</option>)}
        </select>
        <select className={inputCls} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
          {EXHIBITOR_STATUSES.map((t) => <option key={t} value={t}>{STATUS_LABELS[t]}</option>)}
        </select>
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar expositor
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.company_name}{item.stand_number ? ` — Stand ${item.stand_number}` : ""} <span className={`text-xs px-1.5 py-0.5 rounded ${STATUS_COLORS[item.status] || "bg-gray-700"} text-white`}>{STATUS_LABELS[item.status] || item.status}</span></span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 9. InvitationsSection (Convites / RSVP)
// ---------------------------------------------------------------------------

export function InvitationsSection({ eventId }) {
  const [totalParticipants, setTotalParticipants] = useState(null);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/participants?event_id=${eventId}&per_page=1`).then((r) => {
      const meta = r.data?.meta || r.data?.pagination;
      setTotalParticipants(meta?.total ?? null);
    }).catch(() => {});
  }, [eventId]);

  return (
    <SectionShell icon={Mail} title="Convites / RSVP" count={totalParticipants} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="bg-gray-800 rounded-lg p-4 text-sm text-gray-300 space-y-2">
        <p>
          Gerencie os convites na aba <span className="text-purple-400 font-medium">Publico e Participantes</span>.
          Campos de RSVP, escolha de menu e mesa ficam disponiveis apos ativar este modulo.
        </p>
        {totalParticipants != null && (
          <p className="text-xs text-gray-400">
            Total de participantes cadastrados: <span className="text-white font-medium">{totalParticipants}</span>
          </p>
        )}
        <Link to="/participants" className="inline-block text-purple-400 hover:text-purple-300 text-sm underline mt-1">
          Ir para Publico e Participantes &rarr;
        </Link>
      </div>
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 10. CeremonySection (Cerimonial / Timeline de Momentos)
// ---------------------------------------------------------------------------

export function CeremonySection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ name: "", time: "", responsible: "", notes: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-ceremony-moments?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async () => {
    if (!form.name.trim()) return toast.error("Nome do momento obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-ceremony-moments", {
        event_id: eventId,
        name: form.name,
        moment_time: form.time || null,
        responsible: form.responsible || null,
        notes: form.notes || null,
        sort_order: items.length + 1,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({ name: "", time: "", responsible: "", notes: "" });
      toast.success("Momento adicionado");
    } catch { toast.error("Erro ao adicionar momento"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-ceremony-moments/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Momento removido");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={Heart} title="Cerimonial / Timeline de Momentos" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="grid grid-cols-4 gap-2">
        <input className={inputCls} placeholder="Ex: Entrada dos noivos" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <input className={inputCls} type="time" value={form.time} onChange={(e) => setForm({ ...form, time: e.target.value })} />
        <input className={inputCls} placeholder="Responsavel" value={form.responsible} onChange={(e) => setForm({ ...form, responsible: e.target.value })} />
        <input className={inputCls} placeholder="Observacoes" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={handleAdd}>
        <Plus className="w-3 h-3" /> Adicionar momento
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.moment_time && <span className="text-purple-400 mr-2">{item.moment_time}</span>}{item.name}{item.responsible ? ` — ${item.responsible}` : ""}{item.notes ? <span className="text-gray-500 ml-2">({item.notes})</span> : ""}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 11. SubEventsSection (Sub-Eventos)
// ---------------------------------------------------------------------------

const SUB_EVENT_SUGGESTIONS = [
  { label: "Colacao de Grau", sub_event_type: "colacao" },
  { label: "Pre-Festa", sub_event_type: "pre_festa" },
  { label: "Despedida de Solteiro(a)", sub_event_type: "despedida" },
  { label: "After Party", sub_event_type: "after_party" },
  { label: "Ensaio", sub_event_type: "ensaio" },
];

export function SubEventsSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({ name: "", sub_event_type: "", date: "", time: "", venue: "", description: "" });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api.get(`/event-sub-events?event_id=${eventId}`).then((r) => setItems(r.data?.data || []));
  }, [eventId]);

  const handleAdd = async (suggestion) => {
    const entry = suggestion
      ? { name: suggestion.label, sub_event_type: suggestion.sub_event_type, date: "", time: "", venue: "", description: "" }
      : form;
    if (!entry.name.trim()) return toast.error("Nome do sub-evento obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-sub-events", {
        event_id: eventId,
        name: entry.name,
        sub_event_type: entry.sub_event_type || null,
        event_date: entry.date || null,
        event_time: entry.time || null,
        venue: entry.venue || null,
        description: entry.description || null,
        sort_order: items.length + 1,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      if (!suggestion) setForm({ name: "", sub_event_type: "", date: "", time: "", venue: "", description: "" });
      toast.success("Sub-evento adicionado");
    } catch { toast.error("Erro ao adicionar sub-evento"); }
    setLoading(false);
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/event-sub-events/${id}`);
      setItems((prev) => prev.filter((i) => i.id !== id));
      toast.success("Sub-evento removido");
    } catch { toast.error("Erro ao remover"); }
  };

  return (
    <SectionShell icon={CalendarRange} title="Sub-Eventos" count={items.length} expanded={expanded} toggle={() => setExpanded(!expanded)}>
      {!eventId && <NoEventNotice />}
      <div className="flex flex-wrap gap-1.5 mb-2">
        {SUB_EVENT_SUGGESTIONS.map((s) => (
          <button key={s.label} type="button" disabled={loading || !eventId} onClick={() => handleAdd(s)} className="text-[10px] bg-gray-800 hover:bg-gray-700 text-gray-300 px-2 py-1 rounded-lg border border-gray-700 transition-colors disabled:opacity-40">
            + {s.label}
          </button>
        ))}
      </div>
      <div className="grid grid-cols-4 gap-2">
        <input className={inputCls} placeholder="Nome do sub-evento" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <input className={inputCls} type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
        <input className={inputCls} placeholder="Local / Venue" value={form.venue} onChange={(e) => setForm({ ...form, venue: e.target.value })} />
        <input className={inputCls} placeholder="Descricao" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
      </div>
      <button type="button" className={btnAdd} disabled={loading || !eventId} onClick={() => handleAdd(null)}>
        <Plus className="w-3 h-3" /> Adicionar sub-evento
      </button>
      {items.map((item) => (
        <div key={item.id} className="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200">
          <span>{item.name}{item.event_date ? <span className="text-gray-500 ml-2">{item.event_date}</span> : ""}{item.venue ? ` @ ${item.venue}` : ""}{item.description ? <span className="text-gray-500 ml-2">({item.description})</span> : ""}</span>
          <button type="button" className={btnDel} onClick={() => handleDelete(item.id)}><Trash2 className="w-4 h-4" /></button>
        </div>
      ))}
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 12. MapsSection (File uploads via /organizer-files)
// ---------------------------------------------------------------------------

const MAP_SLOTS = [
  { field: "map_3d_url", label: "Planta 3D do Local", description: "Palcos, bares, lojas, banheiros, entradas" },
  { field: "map_image_url", label: "Mapa Geral do Evento", description: "Visao geral do evento (PNG/PDF)" },
  { field: "map_seating_url", label: "Mapa de Assentos", description: "Poltronas ou mesas numeradas" },
  { field: "map_parking_url", label: "Mapa de Estacionamento", description: "Vagas por setor" },
];

export function MapsSection({ eventId, form, setForm }) {
  const [expanded, setExpanded] = useState(true);
  const [uploading, setUploading] = useState(null);
  const [selectedFiles, setSelectedFiles] = useState({});

  const handleUpload = async (file, field) => {
    setUploading(field);
    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("category", "event_map");
      if (eventId) formData.append("event_id", eventId);
      const res = await api.post("/organizer-files", formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      const fileData = res.data?.data || {};
      const fileId = fileData.id;
      const fileName = fileData.original_name || file.name;
      // Store as "file:{id}:{name}" — backend serves via /organizer-files/{id}/download
      const ref = fileId ? `file:${fileId}:${fileName}` : fileName;
      setForm((prev) => ({ ...prev, [field]: ref }));
      setSelectedFiles((prev) => ({ ...prev, [field]: null }));
      toast.success(`"${fileName}" enviado com sucesso!`);
    } catch {
      toast.error("Erro ao enviar arquivo");
    }
    setUploading(null);
  };

  const handleRemove = (field) => {
    setForm((prev) => ({ ...prev, [field]: "" }));
    setSelectedFiles((prev) => ({ ...prev, [field]: null }));
    toast.success("Mapa removido");
  };

  const activeCount = MAP_SLOTS.filter((s) => form[s.field]).length;

  return (
    <SectionShell
      icon={Map}
      title="Mapas do Evento"
      count={activeCount || null}
      expanded={expanded}
      toggle={() => setExpanded(!expanded)}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {MAP_SLOTS.map((slot) => {
          const currentUrl = form[slot.field] || "";
          const file = selectedFiles[slot.field];
          const isUploading = uploading === slot.field;

          return (
            <div
              key={slot.field}
              className="border border-gray-700 rounded-lg p-3 bg-gray-800/60 space-y-2"
            >
              <div>
                <p className="text-sm font-medium text-gray-200">
                  {slot.label}
                </p>
                <p className="text-[10px] text-gray-500">{slot.description}</p>
              </div>

              {currentUrl ? (
                <div className="flex items-center gap-2 bg-gray-900 rounded-lg px-3 py-2">
                  <span className="text-xs text-green-400 truncate flex-1" title={currentUrl}>
                    {currentUrl.startsWith("file:") ? currentUrl.split(":").slice(2).join(":") : currentUrl.split("/").pop() || currentUrl}
                  </span>
                  {currentUrl.startsWith("file:") && (
                    <button
                      type="button"
                      onClick={async () => {
                        const fileId = currentUrl.split(":")[1];
                        try {
                          const res = await api.get(`/organizer-files/${fileId}/download`, { responseType: "blob" });
                          const url = URL.createObjectURL(res.data);
                          window.open(url, "_blank");
                        } catch { toast.error("Erro ao abrir arquivo"); }
                      }}
                      className="text-gray-400 hover:text-purple-400"
                    >
                      <ExternalLink className="w-3.5 h-3.5" />
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => handleRemove(slot.field)}
                    className="text-red-400 hover:text-red-300"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <div className="flex items-center gap-2">
                    <input
                      type="file"
                      accept="image/*,.pdf,.glb,.gltf"
                      id={`map-upload-${slot.field}`}
                      className="sr-only"
                      onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (f) {
                          setSelectedFiles((prev) => ({
                            ...prev,
                            [slot.field]: f,
                          }));
                        }
                      }}
                    />
                    <label
                      htmlFor={`map-upload-${slot.field}`}
                      className="flex items-center gap-1.5 cursor-pointer bg-gray-700 hover:bg-gray-600 text-gray-300 text-xs px-3 py-2 rounded-lg transition-colors flex-1"
                    >
                      <Upload className="w-3.5 h-3.5" />
                      {file ? file.name : "Escolher arquivo"}
                    </label>
                    {file && (
                      <button
                        type="button"
                        disabled={isUploading}
                        onClick={() => handleUpload(file, slot.field)}
                        className="flex items-center gap-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium px-3 py-2 rounded-lg disabled:opacity-40"
                      >
                        {isUploading ? "Enviando..." : "Enviar"}
                      </button>
                    )}
                  </div>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </SectionShell>
  );
}

// ---------------------------------------------------------------------------
// 13. CertificatesSection (via /event-certificates API)
// ---------------------------------------------------------------------------

const CERTIFICATE_TYPES = [
  { value: "participation", label: "Participacao" },
  { value: "presentation", label: "Apresentacao" },
  { value: "workshop", label: "Workshop" },
  { value: "speaker", label: "Palestrante" },
  { value: "organizer", label: "Organizador" },
];

export function CertificatesSection({ eventId }) {
  const [items, setItems] = useState([]);
  const [form, setForm] = useState({
    participant_name: "",
    participant_email: "",
    certificate_type: "participation",
    hours: "",
  });
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    if (!eventId) return;
    api
      .get(`/event-certificates?event_id=${eventId}`)
      .then((r) => setItems(r.data?.data || []))
      .catch(() => {});
  }, [eventId]);

  const handleGenerate = async () => {
    if (!form.participant_name.trim())
      return toast.error("Nome do participante obrigatorio");
    if (!eventId) return;
    setLoading(true);
    try {
      const res = await api.post("/event-certificates", {
        event_id: eventId,
        participant_name: form.participant_name,
        participant_email: form.participant_email || null,
        certificate_type: form.certificate_type,
        hours: form.hours ? Number(form.hours) : null,
      });
      setItems((prev) => [...prev, res.data?.data || res.data]);
      setForm({
        participant_name: "",
        participant_email: "",
        certificate_type: "participation",
        hours: "",
      });
      toast.success("Certificado gerado");
    } catch {
      toast.error("Erro ao gerar certificado");
    }
    setLoading(false);
  };

  const typeLabel = (t) =>
    CERTIFICATE_TYPES.find((ct) => ct.value === t)?.label || t;

  const emittedCount = items.length;
  const pendingCount = items.filter(
    (i) => i.status === "pending"
  ).length;

  return (
    <SectionShell
      icon={Award}
      title="Certificados"
      count={emittedCount || null}
      expanded={expanded}
      toggle={() => setExpanded(!expanded)}
    >
      {!eventId ? (
        <p className="text-xs text-yellow-500">
          Salve o evento primeiro para gerenciar certificados.
        </p>
      ) : (
        <>
          {/* Stats */}
          <div className="flex gap-4 mb-2">
            <div className="bg-gray-800 rounded-lg px-4 py-2 text-center">
              <p className="text-lg font-bold text-white">{emittedCount}</p>
              <p className="text-[10px] text-gray-400">Emitidos</p>
            </div>
            <div className="bg-gray-800 rounded-lg px-4 py-2 text-center">
              <p className="text-lg font-bold text-yellow-400">
                {pendingCount}
              </p>
              <p className="text-[10px] text-gray-400">Pendentes</p>
            </div>
          </div>

          {/* Quick-generate form */}
          <div className="grid grid-cols-2 gap-2">
            <input
              className={inputCls}
              placeholder="Nome do participante"
              value={form.participant_name}
              onChange={(e) =>
                setForm({ ...form, participant_name: e.target.value })
              }
            />
            <input
              className={inputCls}
              type="email"
              placeholder="Email do participante"
              value={form.participant_email}
              onChange={(e) =>
                setForm({ ...form, participant_email: e.target.value })
              }
            />
            <select
              className={inputCls}
              value={form.certificate_type}
              onChange={(e) =>
                setForm({ ...form, certificate_type: e.target.value })
              }
            >
              {CERTIFICATE_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
            <input
              className={inputCls}
              type="number"
              placeholder="Horas"
              value={form.hours}
              onChange={(e) => setForm({ ...form, hours: e.target.value })}
            />
          </div>
          <button
            type="button"
            className={btnAdd}
            disabled={loading || !eventId}
            onClick={handleGenerate}
          >
            <Plus className="w-3 h-3" /> Gerar certificado
          </button>

          {/* List of issued certificates */}
          {items.map((item) => (
            <div
              key={item.id || item.validation_code}
              className="bg-gray-800 rounded-lg px-3 py-2 text-sm text-gray-200 space-y-1"
            >
              <div className="flex items-center justify-between">
                <span className="font-medium">{item.participant_name}</span>
                <span className="text-[10px] text-gray-500">
                  {item.issued_at
                    ? new Date(item.issued_at).toLocaleDateString("pt-BR")
                    : ""}
                </span>
              </div>
              <div className="flex items-center gap-3 text-xs text-gray-400">
                <span>{typeLabel(item.certificate_type)}</span>
                {item.hours && <span>{item.hours}h</span>}
                {item.validation_code && (
                  <span className="font-mono text-purple-400">
                    {item.validation_code}
                  </span>
                )}
              </div>
            </div>
          ))}
        </>
      )}
    </SectionShell>
  );
}
