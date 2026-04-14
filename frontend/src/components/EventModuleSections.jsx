import { useState, useEffect } from "react";
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
      <div className="grid grid-cols-4 gap-2">
        <input className={inputCls} placeholder="CEP" value={form.zip_code || ""} onChange={set("zip_code")} />
        <select className={inputCls} value={form.venue_type || ""} onChange={set("venue_type")}>
          <option value="">Tipo de venue</option>
          {VENUE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <input className={inputCls} type="number" step="any" placeholder="Latitude" value={form.latitude || ""} onChange={set("latitude")} />
        <input className={inputCls} type="number" step="any" placeholder="Longitude" value={form.longitude || ""} onChange={set("longitude")} />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <input className={inputCls} placeholder="Classificacao etaria" value={form.age_rating || ""} onChange={set("age_rating")} />
        <input className={inputCls} placeholder="URL Mapa 3D" value={form.map_3d_url || ""} onChange={set("map_3d_url")} />
        <input className={inputCls} placeholder="URL Imagem do mapa" value={form.map_image_url || ""} onChange={set("map_image_url")} />
        <input className={inputCls} placeholder="URL Mapa de assentos" value={form.map_seating_url || ""} onChange={set("map_seating_url")} />
        <input className={inputCls} placeholder="URL Mapa de estacionamento" value={form.map_parking_url || ""} onChange={set("map_parking_url")} />
      </div>
    </SectionShell>
  );
}
