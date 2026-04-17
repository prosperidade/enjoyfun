import { useEffect, useState, useCallback, useRef } from "react";
import api from "../lib/api";
import EventTemplateSelector from "../components/EventTemplateSelector";
import EventModulesSelector, { MODULE_PRESETS } from "../components/EventModulesSelector";
import { StagesSection, SectorsSection, ParkingConfigSection, PdvPointsSection, LocationSection, ExhibitorsSection, InvitationsSection, CeremonySection, SubEventsSection, MapsSection, CertificatesSection } from "../components/EventModuleSections";
import MapBuilder from "../components/MapBuilder";
import SeatingChart from "../components/SeatingChart";
import AgendaBuilder from "../components/AgendaBuilder";
import {
  CalendarDays,
  Plus,
  MapPin,
  Clock,
  ChevronRight,
  Search,
  Trash2,
  Pencil,
  Users,
  X,
} from "lucide-react";
import { Link, useSearchParams } from "react-router-dom";
import toast from "react-hot-toast";

const EVENT_TYPE_LABELS = {
  festival: "Festival", show: "Show", corporate: "Corporativo",
  wedding: "Casamento", graduation: "Formatura", sports_stadium: "Esportivo",
  expo: "Feira", congress: "Congresso", theater: "Teatro",
  sports_gym: "Ginasio", rodeo: "Rodeio", custom: "Customizado",
};

const statusLabel = {
  draft: "Rascunho",
  published: "Publicado",
  ongoing: "Em andamento",
  finished: "Finalizado",
  cancelled: "Cancelado",
};

function getBrowserTimeZone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "";
  } catch {
    return "";
  }
}

function createEmptyEventForm() {
  return {
    name: "",
    description: "",
    venue_name: "",
    address: "",
    starts_at: "",
    ends_at: "",
    status: "draft",
    capacity: "",
    event_timezone: getBrowserTimeZone(),
    event_type: "",
    modules_enabled: [],
    latitude: "",
    longitude: "",
    city: "",
    state: "",
    country: "BR",
    zip_code: "",
    venue_type: "outdoor",
    age_rating: "",
    map_3d_url: "",
    map_image_url: "",
    map_seating_url: "",
    map_parking_url: "",
    map_url: "",
    banner_url: "",
    tour_video_url: "",
    tour_video_360_url: "",
  };
}

const EMPTY_BATCH_FORM = {
  id: null,
  name: "",
  code: "",
  price: "",
  quantity_total: "",
  starts_at: "",
  ends_at: "",
  ticket_type_id: "",
  is_active: true,
};

const EMPTY_TICKET_TYPE_FORM = {
  id: null,
  client_key: null,
  name: "",
  price: "",
  sector: "",
};

const EMPTY_COMMISSARY_FORM = {
  id: null,
  name: "",
  email: "",
  phone: "",
  commission_mode: "percent",
  commission_value: "",
  status: "active",
};

function toDatetimeLocal(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value).slice(0, 16);
  }
  const offsetMs = date.getTimezoneOffset() * 60 * 1000;
  return new Date(date.getTime() - offsetMs).toISOString().slice(0, 16);
}

function mapEventToForm(event) {
  return {
    name: event?.name || "",
    description: event?.description || "",
    venue_name: event?.venue_name || "",
    address: event?.address || "",
    starts_at: toDatetimeLocal(event?.starts_at),
    ends_at: toDatetimeLocal(event?.ends_at),
    status: event?.status || "draft",
    capacity: event?.capacity ?? "",
    event_timezone: event?.event_timezone || getBrowserTimeZone(),
    event_type: event?.event_type || "",
    modules_enabled: Array.isArray(event?.modules_enabled) ? event.modules_enabled : [],
    latitude: event?.latitude ?? "",
    longitude: event?.longitude ?? "",
    city: event?.city || "",
    state: event?.state || "",
    country: event?.country || "BR",
    zip_code: event?.zip_code || "",
    venue_type: event?.venue_type || "outdoor",
    age_rating: event?.age_rating || "",
    map_3d_url: event?.map_3d_url || "",
    map_image_url: event?.map_image_url || "",
    map_seating_url: event?.map_seating_url || "",
    map_parking_url: event?.map_parking_url || "",
    map_url: event?.map_url || "",
    banner_url: event?.banner_url || "",
    tour_video_url: event?.tour_video_url || "",
    tour_video_360_url: event?.tour_video_360_url || "",
  };
}

function mapBatchToDraft(batch) {
  return {
    id: batch.id,
    name: batch.name || "",
    code: batch.code || "",
    price: batch.price ?? "",
    quantity_total: batch.quantity_total ?? "",
    starts_at: toDatetimeLocal(batch.starts_at),
    ends_at: toDatetimeLocal(batch.ends_at),
    ticket_type_id: batch.ticket_type_id ? String(batch.ticket_type_id) : "",
    is_active: batch.is_active !== false,
    ticket_type_name: batch.ticket_type_name || "",
  };
}

function mapTicketTypeToDraft(ticketType) {
  return {
    id: ticketType.id,
    client_key: null,
    name: ticketType.name || "",
    price: ticketType.price ?? "",
    sector: ticketType.sector || "",
  };
}

function mapCommissaryToDraft(commissary) {
  return {
    id: commissary.id,
    name: commissary.name || "",
    email: commissary.email || "",
    phone: commissary.phone || "",
    commission_mode: commissary.commission_mode || "percent",
    commission_value: commissary.commission_value ?? "",
    status: commissary.status || "active",
  };
}

function serializeBatch(batch) {
  const ticketTypeValue = batch.ticket_type_id ? String(batch.ticket_type_id) : "";
  const ticketTypeId = ticketTypeValue !== "" && /^\d+$/.test(ticketTypeValue) ? Number(ticketTypeValue) : null;

  return {
    id: Number.isInteger(batch.id) ? batch.id : null,
    name: batch.name.trim(),
    code: batch.code.trim() || null,
    price: batch.price === "" ? 0 : Number(batch.price),
    quantity_total: batch.quantity_total === "" ? null : Number(batch.quantity_total),
    starts_at: batch.starts_at || null,
    ends_at: batch.ends_at || null,
    ticket_type_id: ticketTypeId,
    ticket_type_client_key: ticketTypeId === null && ticketTypeValue !== "" ? ticketTypeValue : null,
    is_active: batch.is_active !== false,
  };
}

function serializeTicketType(ticketType) {
  const ticketTypeId = Number.isInteger(ticketType.id) ? ticketType.id : null;
  const clientKey = ticketType.client_key || (!ticketTypeId ? String(ticketType.id || "") : "");

  return {
    id: ticketTypeId,
    client_key: clientKey || null,
    name: ticketType.name.trim(),
    price: ticketType.price === "" ? 0 : Number(ticketType.price),
    sector: ticketType.sector || null,
  };
}

function serializeCommissary(commissary) {
  return {
    id: Number.isInteger(commissary.id) ? commissary.id : null,
    name: commissary.name.trim(),
    email: commissary.email.trim() || null,
    phone: commissary.phone.trim() || null,
    commission_mode: commissary.commission_mode,
    commission_value: commissary.commission_value === "" ? 0 : Number(commissary.commission_value),
    status: commissary.status || "active",
  };
}

function mergePendingEdit(items, pendingItem, matchKey) {
  if (!pendingItem || !Number.isInteger(pendingItem.id)) {
    return items;
  }

  return items.map((item) => (
    item[matchKey] === pendingItem[matchKey] ? pendingItem : item
  ));
}

export default function Events() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loadingForm, setLoadingForm] = useState(false);
  const [editingEventId, setEditingEventId] = useState(null);
  const [ticketTypeForm, setTicketTypeForm] = useState(EMPTY_TICKET_TYPE_FORM);
  const [ticketTypes, setTicketTypes] = useState([]);
  const [availableSectors, setAvailableSectors] = useState([]);
  const [form, setForm] = useState(() => createEmptyEventForm());
  const [batchForm, setBatchForm] = useState(EMPTY_BATCH_FORM);
  const [commissaryForm, setCommissaryForm] = useState(EMPTY_COMMISSARY_FORM);
  const [draftBatches, setDraftBatches] = useState([]);
  const [draftCommissaries, setDraftCommissaries] = useState([]);

  const load = useCallback(() => {
    setLoading(true);
    api
      .get("/events", { params: { search, per_page: 50 } })
      .then((r) => setEvents(r.data.data || []))
      .catch(() => toast.error("Erro ao carregar eventos."))
      .finally(() => setLoading(false));
  }, [search]);

  useEffect(() => {
    load();
  }, [load]);

  const updateEditQuery = useCallback((eventId) => {
    const next = new URLSearchParams(searchParams);
    if (eventId) {
      next.set("edit", String(eventId));
    } else {
      next.delete("edit");
    }
    setSearchParams(next, { replace: true });
  }, [searchParams, setSearchParams]);

  const resetEventFormState = useCallback(() => {
    setEditingEventId(null);
    setTicketTypeForm(EMPTY_TICKET_TYPE_FORM);
    setTicketTypes([]);
    setAvailableSectors([]);
    setForm(createEmptyEventForm());
    setBatchForm(EMPTY_BATCH_FORM);
    setCommissaryForm(EMPTY_COMMISSARY_FORM);
    setDraftBatches([]);
    setDraftCommissaries([]);
  }, []);

  const closingRef = useRef(false);

  const closeEventForm = useCallback(() => {
    closingRef.current = true;
    setShowForm(false);
    resetEventFormState();
    updateEditQuery(null);
    setTimeout(() => { closingRef.current = false; }, 100);
  }, [resetEventFormState, updateEditQuery]);

  const openCreateForm = () => {
    resetEventFormState();
    setShowForm(true);
    updateEditQuery(null);
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const getTicketTypeLabel = useCallback((value) => {
    if (!value) return "";
    const match = ticketTypes.find((item) => String(item.id ?? item.client_key) === String(value));
    return match?.name || "";
  }, [ticketTypes]);

  const startEditEvent = useCallback(async (eventId) => {
    if (!eventId) return;

    setLoadingForm(true);
    setShowForm(true);
    try {
      const [eventRes, typesRes, batchesRes, commissariesRes] = await Promise.all([
        api.get(`/events/${eventId}`),
        api.get("/tickets/types", { params: { event_id: eventId } }),
        api.get("/tickets/batches", { params: { event_id: eventId } }),
        api.get("/tickets/commissaries", { params: { event_id: eventId } }),
      ]);

      setEditingEventId(Number(eventId));
      setForm(mapEventToForm(eventRes.data?.data));
      const rawTypes = typesRes.data?.data;
      // Handle new format { ticket_types, available_sectors } or legacy flat array
      const typesArray = Array.isArray(rawTypes) ? rawTypes : (rawTypes?.ticket_types || []);
      const sectorsArray = Array.isArray(rawTypes) ? [] : (rawTypes?.available_sectors || []);
      setTicketTypes(typesArray.map(mapTicketTypeToDraft));
      setAvailableSectors(sectorsArray);
      setTicketTypeForm(EMPTY_TICKET_TYPE_FORM);
      setDraftBatches((batchesRes.data?.data || []).map(mapBatchToDraft));
      setDraftCommissaries((commissariesRes.data?.data || []).map(mapCommissaryToDraft));
      setBatchForm(EMPTY_BATCH_FORM);
      setCommissaryForm(EMPTY_COMMISSARY_FORM);
      updateEditQuery(eventId);
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao carregar configuração comercial do evento.");
      closeEventForm();
    } finally {
      setLoadingForm(false);
    }
  }, [closeEventForm, updateEditQuery]);

  useEffect(() => {
    if (closingRef.current) return;
    const editId = searchParams.get("edit");
    if (!editId) return;
    if (editingEventId === Number(editId) && showForm) return;
    startEditEvent(editId);
  }, [editingEventId, searchParams, showForm, startEditEvent]);

  const upsertBatchDraft = () => {
    const isEditingExistingBatch = Number.isInteger(batchForm.id);

    if (!batchForm.name.trim()) {
      toast.error("Informe o nome do lote.");
      return;
    }

    if (batchForm.quantity_total !== "" && Number(batchForm.quantity_total) < 0) {
      toast.error("Quantidade total inválida.");
      return;
    }

    if (batchForm.price !== "" && Number(batchForm.price) < 0) {
      toast.error("Preço inválido.");
      return;
    }

    const nextItem = {
      ...batchForm,
      id: batchForm.id ?? `draft-${crypto.randomUUID()}`,
      name: batchForm.name.trim(),
      code: batchForm.code.trim(),
      ticket_type_name: getTicketTypeLabel(batchForm.ticket_type_id),
    };

    setDraftBatches((prev) => {
      const exists = prev.some((item) => item.id === nextItem.id);
      return exists
        ? prev.map((item) => (item.id === nextItem.id ? nextItem : item))
        : [...prev, nextItem];
    });
    setBatchForm(EMPTY_BATCH_FORM);

    if (editingEventId) {
      toast.success(
        isEditingExistingBatch
          ? "Lote atualizado no formulário. Clique em 'Salvar Evento e Configurações' para gravar no banco."
          : "Lote adicionado ao formulário. Clique em 'Salvar Evento e Configurações' para gravar no banco."
      );
    }
  };

  const editBatchDraft = (item) => {
    setBatchForm({
      id: item.id,
      name: item.name || "",
      code: item.code || "",
      price: item.price ?? "",
      quantity_total: item.quantity_total ?? "",
      starts_at: item.starts_at || "",
      ends_at: item.ends_at || "",
      ticket_type_id: item.ticket_type_id ? String(item.ticket_type_id) : "",
      is_active: item.is_active !== false,
    });
  };

  const removeBatchDraft = (id) => {
    setDraftBatches((prev) => prev.filter((item) => item.id !== id));
    setBatchForm((current) => (current.id === id ? EMPTY_BATCH_FORM : current));
  };

  const upsertTicketTypeDraft = () => {
    const isEditingExistingType = Number.isInteger(ticketTypeForm.id);

    if (!ticketTypeForm.name.trim()) {
      toast.error("Informe o nome do tipo de ingresso.");
      return;
    }

    if (ticketTypeForm.price !== "" && Number(ticketTypeForm.price) < 0) {
      toast.error("Preço inválido para o tipo de ingresso.");
      return;
    }

    const draftKey = ticketTypeForm.client_key || ticketTypeForm.id || `draft-type-${crypto.randomUUID()}`;
    const nextItem = {
      ...ticketTypeForm,
      id: Number.isInteger(ticketTypeForm.id) ? ticketTypeForm.id : draftKey,
      client_key: Number.isInteger(ticketTypeForm.id) ? null : draftKey,
      name: ticketTypeForm.name.trim(),
    };

    setTicketTypes((prev) => {
      const exists = prev.some((item) => String(item.id ?? item.client_key) === String(nextItem.id ?? nextItem.client_key));
      return exists
        ? prev.map((item) => (
            String(item.id ?? item.client_key) === String(nextItem.id ?? nextItem.client_key)
              ? nextItem
              : item
          ))
        : [...prev, nextItem];
    });

    setDraftBatches((prev) => prev.map((batch) => {
      if (String(batch.ticket_type_id || "") !== String(nextItem.id ?? nextItem.client_key)) {
        return batch;
      }
      return { ...batch, ticket_type_name: nextItem.name };
    }));

    setTicketTypeForm(EMPTY_TICKET_TYPE_FORM);

    if (editingEventId) {
      toast.success(
        isEditingExistingType
          ? "Tipo atualizado no formulário. Clique em 'Salvar Evento e Configurações' para gravar no banco."
          : "Tipo adicionado ao formulário. Clique em 'Salvar Evento e Configurações' para gravar no banco."
      );
    }
  };

  const editTicketTypeDraft = (item) => {
    setTicketTypeForm({
      id: Number.isInteger(item.id) ? item.id : null,
      client_key: item.client_key || (!Number.isInteger(item.id) ? String(item.id) : null),
      name: item.name || "",
      price: item.price ?? "",
    });
  };

  const removeTicketTypeDraft = (item) => {
    const ticketTypeKey = String(item.id ?? item.client_key);
    const linkedBatch = draftBatches.find((batch) => String(batch.ticket_type_id || "") === ticketTypeKey);
    if (linkedBatch) {
      toast.error(`O tipo está vinculado ao lote "${linkedBatch.name}". Remova o vínculo primeiro.`);
      return;
    }

    setTicketTypes((prev) => prev.filter((current) => String(current.id ?? current.client_key) !== ticketTypeKey));
    setTicketTypeForm((current) => {
      const currentKey = String(current.id ?? current.client_key ?? "");
      return currentKey === ticketTypeKey ? EMPTY_TICKET_TYPE_FORM : current;
    });
  };

  const upsertCommissaryDraft = () => {
    const isEditingExistingCommissary = Number.isInteger(commissaryForm.id);

    if (!commissaryForm.name.trim()) {
      toast.error("Informe o nome do comissário.");
      return;
    }

    if (commissaryForm.commission_value !== "" && Number(commissaryForm.commission_value) < 0) {
      toast.error("Valor de comissão inválido.");
      return;
    }

    if (
      commissaryForm.commission_mode === "percent" &&
      commissaryForm.commission_value !== "" &&
      Number(commissaryForm.commission_value) > 100
    ) {
      toast.error("Comissão percentual não pode ser maior que 100.");
      return;
    }

    const nextItem = {
      ...commissaryForm,
      id: commissaryForm.id ?? `draft-${crypto.randomUUID()}`,
      name: commissaryForm.name.trim(),
      email: commissaryForm.email.trim(),
      phone: commissaryForm.phone.trim(),
    };

    setDraftCommissaries((prev) => {
      const exists = prev.some((item) => item.id === nextItem.id);
      return exists
        ? prev.map((item) => (item.id === nextItem.id ? nextItem : item))
        : [...prev, nextItem];
    });
    setCommissaryForm(EMPTY_COMMISSARY_FORM);

    if (editingEventId) {
      toast.success(
        isEditingExistingCommissary
          ? "Comissário atualizado no formulário. Clique em 'Salvar Evento e Configurações' para gravar no banco."
          : "Comissário adicionado ao formulário. Clique em 'Salvar Evento e Configurações' para gravar no banco."
      );
    }
  };

  const editCommissaryDraft = (item) => {
    setCommissaryForm({
      id: item.id,
      name: item.name || "",
      email: item.email || "",
      phone: item.phone || "",
      commission_mode: item.commission_mode || "percent",
      commission_value: item.commission_value ?? "",
      status: item.status || "active",
    });
  };

  const removeCommissaryDraft = (id) => {
    setDraftCommissaries((prev) => prev.filter((item) => item.id !== id));
    setCommissaryForm((current) => (current.id === id ? EMPTY_COMMISSARY_FORM : current));
  };

  const handleSaveEvent = async (e) => {
    e.preventDefault();
    setSaving(true);

    if (!form.event_timezone.trim()) {
      toast.error("Informe a timezone operacional do evento em formato IANA.");
      setSaving(false);
      return;
    }

    const ticketTypesForSave = mergePendingEdit(
      ticketTypes,
      Number.isInteger(ticketTypeForm.id)
        ? {
            ...ticketTypeForm,
            name: ticketTypeForm.name.trim(),
          }
        : null,
      "id"
    );

    const batchesForSave = mergePendingEdit(
      draftBatches,
      Number.isInteger(batchForm.id)
        ? {
            ...batchForm,
            name: batchForm.name.trim(),
            code: batchForm.code.trim(),
            ticket_type_name: getTicketTypeLabel(batchForm.ticket_type_id),
          }
        : null,
      "id"
    );

    const commissariesForSave = mergePendingEdit(
      draftCommissaries,
      Number.isInteger(commissaryForm.id)
        ? {
            ...commissaryForm,
            name: commissaryForm.name.trim(),
            email: commissaryForm.email.trim(),
            phone: commissaryForm.phone.trim(),
          }
        : null,
      "id"
    );

  const payload = {
      ...form,
      capacity: form.capacity === "" ? 0 : Number(form.capacity),
      event_type: form.event_type || null,
      modules_enabled: form.modules_enabled || [],
      latitude: form.latitude !== "" ? Number(form.latitude) : null,
      longitude: form.longitude !== "" ? Number(form.longitude) : null,
      city: form.city || null,
      state: form.state || null,
      country: form.country || "BR",
      zip_code: form.zip_code || null,
      venue_type: form.venue_type || "outdoor",
      age_rating: form.age_rating || null,
      map_3d_url: form.map_3d_url || null,
      map_image_url: form.map_image_url || null,
      map_seating_url: form.map_seating_url || null,
      map_parking_url: form.map_parking_url || null,
      banner_url: form.banner_url || null,
      tour_video_url: form.tour_video_url || null,
      tour_video_360_url: form.tour_video_360_url || null,
      commercial_config: {
        ticket_types: ticketTypesForSave.map(serializeTicketType),
        batches: batchesForSave.map(serializeBatch),
        commissaries: commissariesForSave.map(serializeCommissary),
      },
    };

    try {
      if (editingEventId) {
        await api.put(`/events/${editingEventId}`, payload);
        toast.success("Evento e configuração comercial atualizados com sucesso.");
      } else {
        await api.post("/events", payload);
        toast.success("Evento e configuração comercial criados com sucesso.");
      }

      closeEventForm();
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar evento.");
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteEvent = async (eventId) => {
    if (!window.confirm("Excluir este evento? Essa ação só é permitida para eventos sem dados vinculados.")) {
      return;
    }

    try {
      await api.delete(`/events/${eventId}`);
      toast.success("Evento excluído com sucesso.");
      if (editingEventId === eventId) {
        closeEventForm();
      }
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao excluir evento.");
    }
  };

  return (
    <div className="space-y-10">
      {/* ── Header Stitch ── */}
      <header className="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 glass-card rounded-xl flex items-center justify-center text-cyan-400 shadow-lg">
            <CalendarDays size={24} />
          </div>
          <div>
            <h1 className="text-3xl font-bold tracking-tight text-slate-100 font-headline">Eventos</h1>
            <p className="text-slate-400 text-sm">Gerencie e monitore suas experiências em tempo real.</p>
          </div>
        </div>
        <div className="flex items-center gap-4 flex-1 max-w-xl">
          <div className="relative flex-1">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              name="events_search"
              className="w-full bg-slate-800/50 border-none rounded-xl pl-10 pr-4 py-3 text-slate-200 focus:ring-2 focus:ring-cyan-500/50 transition-all placeholder:text-slate-500"
              placeholder="Buscar eventos..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <button
            onClick={openCreateForm}
            className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-bold px-6 py-3 rounded-xl hover:scale-[1.02] transition-all shadow-[0_0_20px_rgba(0,240,255,0.2)] whitespace-nowrap"
          >
            <Plus size={16} className="inline -mt-0.5 mr-1" /> Novo Evento
          </button>
        </div>
      </header>

      {showForm && (
        <div className="an-card border-cyan-500/20 space-y-6">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="text-xl font-semibold text-slate-100">{editingEventId ? "Editar Evento" : "Novo Evento"}</h2>
              <p className="text-sm text-slate-500 mt-1">
                Configure o evento e toda a operação comercial na mesma interface.
              </p>
            </div>
            <button type="button" className="an-btn an-btn-secondary text-xs" onClick={closeEventForm}>
              <X size={14} /> Fechar
            </button>
          </div>

          {loadingForm ? (
            <div className="flex items-center justify-center py-20">
              <div className="spinner w-10 h-10" />
            </div>
          ) : (
            <form onSubmit={handleSaveEvent} className="space-y-6">
              {/* Template Selector — only for new events */}
              {!editingEventId && (
                <EventTemplateSelector
                  selected={form.event_type}
                  onSelect={(key) => setForm((f) => ({
                    ...f,
                    event_type: key,
                    modules_enabled: MODULE_PRESETS[key] || [],
                  }))}
                  disabled={saving}
                />
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <label className="an-label">Nome do Evento *</label>
                  <input
                    name="event_name"
                    className="an-input"
                    value={form.name}
                    onChange={set("name")}
                    required
                    placeholder="Ex: Festival de Verão 2025"
                  />
                </div>
                <div>
                  <label className="an-label">Local</label>
                  <input
                    name="event_venue_name"
                    className="an-input"
                    value={form.venue_name}
                    onChange={set("venue_name")}
                    placeholder="Nome do local"
                  />
                </div>
                <div>
                  <label className="an-label">Capacidade</label>
                  <input
                    name="event_capacity"
                    className="an-input"
                    type="number"
                    value={form.capacity}
                    onChange={set("capacity")}
                    placeholder="Ex: 5000"
                  />
                </div>
                <div>
                  <label className="an-label">Início *</label>
                  <input
                    name="event_starts_at"
                    className="an-input"
                    type="datetime-local"
                    value={form.starts_at}
                    onChange={set("starts_at")}
                    required
                  />
                </div>
                <div>
                  <label className="an-label">Término *</label>
                  <input
                    name="event_ends_at"
                    className="an-input"
                    type="datetime-local"
                    value={form.ends_at}
                    onChange={set("ends_at")}
                    required
                  />
                </div>
                <div className="sm:col-span-2">
                  <label className="an-label">Timezone Operacional *</label>
                  <input
                    name="event_timezone"
                    className="an-input"
                    value={form.event_timezone}
                    onChange={set("event_timezone")}
                    placeholder="Ex: America/Sao_Paulo"
                    required
                  />
                  <p className="mt-1 text-xs text-slate-500">
                    Usada para resolver calendário operacional e converter payloads com offset no Meals.
                  </p>
                </div>
                <div className="sm:col-span-2">
                  <label className="an-label">Endereço</label>
                  <input
                    name="event_address"
                    className="an-input"
                    value={form.address}
                    onChange={set("address")}
                    placeholder="Rua, número, cidade"
                  />
                </div>
                <div className="sm:col-span-2">
                  <label className="an-label">Descrição</label>
                  <textarea
                    name="event_description"
                    className="input resize-none"
                    rows={3}
                    value={form.description}
                    onChange={set("description")}
                    placeholder="Descrição do evento..."
                  />
                </div>
                <div>
                  <label className="an-label">Status</label>
                  <select
                    name="event_status"
                    className="an-select"
                    value={form.status}
                    onChange={set("status")}
                  >
                    <option value="draft">Rascunho</option>
                    <option value="published">Publicado</option>
                    <option value="ongoing">Em andamento</option>
                    <option value="finished">Finalizado</option>
                    <option value="cancelled">Cancelado</option>
                  </select>
                </div>
              </div>

              {!['wedding', 'graduation'].includes(form.event_type) && (<div className="rounded-2xl border border-slate-700/50 bg-slate-800/30 p-4 space-y-4">
                <div>
                  <h3 className="text-lg font-semibold text-slate-200">Tipos de Ingresso</h3>
                  <p className="text-xs text-slate-500 mt-1">
                    A emissão comercial depende de pelo menos um tipo de ingresso cadastrado no evento.
                  </p>
                </div>

                <div className="grid sm:grid-cols-3 gap-3">
                  <input
                    name="ticket_type_name"
                    className="input sm:col-span-2"
                    placeholder="Nome do tipo de ingresso"
                    value={ticketTypeForm.name}
                    onChange={(e) => setTicketTypeForm((current) => ({ ...current, name: e.target.value }))}
                  />
                  <input
                    name="ticket_type_price"
                    type="number"
                    step="0.01"
                    className="an-input"
                    placeholder="Preço base"
                    value={ticketTypeForm.price}
                    onChange={(e) => setTicketTypeForm((current) => ({ ...current, price: e.target.value }))}
                  />
                  {availableSectors.length > 0 ? (
                    <select
                      name="ticket_type_sector"
                      className="select sm:col-span-3"
                      value={ticketTypeForm.sector}
                      onChange={(e) => setTicketTypeForm((current) => ({ ...current, sector: e.target.value }))}
                    >
                      <option value="">(Sem setor)</option>
                      {availableSectors.map((s) => (
                        <option key={s.id} value={s.name}>{s.name}</option>
                      ))}
                    </select>
                  ) : null}
                  <div className="sm:col-span-3 flex gap-3">
                    <button type="button" className="an-btn an-btn-secondary flex-1" onClick={upsertTicketTypeDraft}>
                      <Plus size={16} /> {ticketTypeForm.id || ticketTypeForm.client_key ? "Atualizar Tipo" : "Adicionar Tipo"}
                    </button>
                    {ticketTypeForm.id || ticketTypeForm.client_key ? (
                      <button type="button" className="an-btn an-btn-secondary flex-1" onClick={() => setTicketTypeForm(EMPTY_TICKET_TYPE_FORM)}>
                        Cancelar edição
                      </button>
                    ) : null}
                  </div>
                </div>

                <div className="space-y-2 max-h-60 overflow-auto">
                  {ticketTypes.length === 0 ? (
                    <p className="text-sm text-slate-500">Nenhum tipo de ingresso configurado.</p>
                  ) : ticketTypes.map((ticketType) => (
                    <div key={String(ticketType.id ?? ticketType.client_key)} className="rounded-xl border border-slate-700/50 bg-slate-800/40 p-3 flex items-start justify-between gap-3">
                      <div>
                        <p className="font-medium text-white">{ticketType.name}</p>
                        <p className="text-xs text-slate-500">
                          Preço base: R$ {Number(ticketType.price || 0).toFixed(2)}
                          {ticketType.sector ? ` • Setor: ${ticketType.sector}` : ""}
                        </p>
                      </div>
                      <div className="flex gap-2">
                        <button type="button" className="p-2 rounded-lg text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10" onClick={() => editTicketTypeDraft(ticketType)}>
                          <Pencil size={16} />
                        </button>
                        <button type="button" className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeTicketTypeDraft(ticketType)}>
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>)}

              {!['wedding', 'graduation'].includes(form.event_type) && (<div className="grid xl:grid-cols-2 gap-6">
                <div className="rounded-2xl border border-slate-700/50 bg-slate-800/30 p-4 space-y-4">
                  <div>
                    <h3 className="text-lg font-semibold text-slate-200">Lotes Comerciais</h3>
                    <p className="text-xs text-slate-500 mt-1">
                      Crie, edite e remova lotes vinculados ao evento.
                    </p>
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <input
                      name="batch_name"
                      className="input col-span-2"
                      placeholder="Nome do lote"
                      value={batchForm.name}
                      onChange={(e) => setBatchForm((f) => ({ ...f, name: e.target.value }))}
                    />
                    <input
                      name="batch_code"
                      className="an-input"
                      placeholder="Código"
                      value={batchForm.code}
                      onChange={(e) => setBatchForm((f) => ({ ...f, code: e.target.value }))}
                    />
                    <input
                      name="batch_price"
                      type="number"
                      step="0.01"
                      className="an-input"
                      placeholder="Preço"
                      value={batchForm.price}
                      onChange={(e) => setBatchForm((f) => ({ ...f, price: e.target.value }))}
                    />
                    <input
                      name="batch_quantity_total"
                      type="number"
                      className="an-input"
                      placeholder="Qtd. total"
                      value={batchForm.quantity_total}
                      onChange={(e) => setBatchForm((f) => ({ ...f, quantity_total: e.target.value }))}
                    />
                    <input
                      name="batch_starts_at"
                      type="datetime-local"
                      className="an-input"
                      value={batchForm.starts_at}
                      onChange={(e) => setBatchForm((f) => ({ ...f, starts_at: e.target.value }))}
                    />
                    <input
                      name="batch_ends_at"
                      type="datetime-local"
                      className="an-input"
                      value={batchForm.ends_at}
                      onChange={(e) => setBatchForm((f) => ({ ...f, ends_at: e.target.value }))}
                    />
                    {ticketTypes.length > 0 ? (
                      <select
                        name="batch_ticket_type_id"
                        className="select col-span-2"
                        value={batchForm.ticket_type_id}
                        onChange={(e) => setBatchForm((f) => ({ ...f, ticket_type_id: e.target.value }))}
                      >
                        <option value="">Tipo de ingresso vinculado (opcional)</option>
                        {ticketTypes.map((type) => (
                          <option key={type.id} value={type.id}>
                            {type.name}
                          </option>
                        ))}
                      </select>
                    ) : (
                      <p className="text-xs text-slate-500 col-span-2">
                        O vínculo com tipo de ingresso pode ser definido quando o evento já possui tipos cadastrados.
                      </p>
                    )}
                    <label className="col-span-2 flex items-center gap-2 text-sm text-slate-300">
                      <input
                        name="batch_is_active"
                        type="checkbox"
                        checked={batchForm.is_active}
                        onChange={(e) => setBatchForm((f) => ({ ...f, is_active: e.target.checked }))}
                      />
                      Lote ativo
                    </label>
                    <div className="col-span-2 flex gap-3">
                      <button type="button" className="an-btn an-btn-secondary flex-1" onClick={upsertBatchDraft}>
                        <Plus size={16} /> {batchForm.id ? "Atualizar Lote" : "Adicionar Lote"}
                      </button>
                      {batchForm.id ? (
                        <button type="button" className="an-btn an-btn-secondary flex-1" onClick={() => setBatchForm(EMPTY_BATCH_FORM)}>
                          Cancelar edição
                        </button>
                      ) : null}
                    </div>
                  </div>

                  <div className="space-y-2 max-h-72 overflow-auto">
                    {draftBatches.length === 0 ? (
                      <p className="text-sm text-slate-500">Nenhum lote configurado.</p>
                    ) : draftBatches.map((batch) => (
                      <div key={batch.id} className="rounded-xl border border-slate-700/50 bg-slate-800/40 p-3 flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium text-white">{batch.name}</p>
                          <p className="text-xs text-slate-500">
                            {batch.code || "Sem código"} • R$ {Number(batch.price || 0).toFixed(2)} • Qtd.: {batch.quantity_total || "Livre"}
                          </p>
                          <p className="text-[11px] text-slate-500 mt-1">
                            {batch.ticket_type_name || (batch.ticket_type_id ? `Tipo #${batch.ticket_type_id}` : "Sem vínculo com tipo")} • {batch.is_active === false ? "Inativo" : "Ativo"}
                          </p>
                        </div>
                        <div className="flex gap-2">
                          <button type="button" className="p-2 rounded-lg text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10" onClick={() => editBatchDraft(batch)}>
                            <Pencil size={16} />
                          </button>
                          <button type="button" className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeBatchDraft(batch.id)}>
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="rounded-2xl border border-slate-700/50 bg-slate-800/30 p-4 space-y-4">
                  <div>
                    <h3 className="text-lg font-semibold text-slate-200">Comissários</h3>
                    <p className="text-xs text-slate-500 mt-1">
                      Cadastre, altere e remova comissários do evento.
                    </p>
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <input
                      name="commissary_name"
                      className="input col-span-2"
                      placeholder="Nome"
                      value={commissaryForm.name}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, name: e.target.value }))}
                    />
                    <input
                      name="commissary_email"
                      type="email"
                      className="an-input"
                      placeholder="E-mail"
                      value={commissaryForm.email}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, email: e.target.value }))}
                    />
                    <input
                      name="commissary_phone"
                      className="an-input"
                      placeholder="Telefone"
                      value={commissaryForm.phone}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, phone: e.target.value }))}
                    />
                    <select
                      name="commissary_commission_mode"
                      className="an-select"
                      value={commissaryForm.commission_mode}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, commission_mode: e.target.value }))}
                    >
                      <option value="percent">Percentual (%)</option>
                      <option value="fixed">Valor Fixo (R$)</option>
                    </select>
                    <input
                      name="commissary_commission_value"
                      type="number"
                      step="0.01"
                      className="an-input"
                      placeholder="Valor da comissão"
                      value={commissaryForm.commission_value}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, commission_value: e.target.value }))}
                    />
                    <select
                      name="commissary_status"
                      className="select col-span-2"
                      value={commissaryForm.status}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, status: e.target.value }))}
                    >
                      <option value="active">Ativo</option>
                      <option value="inactive">Inativo</option>
                    </select>
                    <div className="col-span-2 flex gap-3">
                      <button type="button" className="an-btn an-btn-secondary flex-1" onClick={upsertCommissaryDraft}>
                        <Plus size={16} /> {commissaryForm.id ? "Atualizar Comissário" : "Adicionar Comissário"}
                      </button>
                      {commissaryForm.id ? (
                        <button type="button" className="an-btn an-btn-secondary flex-1" onClick={() => setCommissaryForm(EMPTY_COMMISSARY_FORM)}>
                          Cancelar edição
                        </button>
                      ) : null}
                    </div>
                  </div>

                  <div className="space-y-2 max-h-72 overflow-auto">
                    {draftCommissaries.length === 0 ? (
                      <p className="text-sm text-slate-500">Nenhum comissário configurado.</p>
                    ) : draftCommissaries.map((commissary) => (
                      <div key={commissary.id} className="rounded-xl border border-slate-700/50 bg-slate-800/40 p-3 flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium text-white">{commissary.name}</p>
                          <p className="text-xs text-slate-500">
                            {commissary.commission_mode === "percent"
                              ? `${Number(commissary.commission_value || 0)}%`
                              : `R$ ${Number(commissary.commission_value || 0).toFixed(2)}`}
                          </p>
                          <p className="text-[11px] text-slate-500 mt-1">
                            {commissary.email || "Sem e-mail"} • {commissary.status === "inactive" ? "Inativo" : "Ativo"}
                          </p>
                        </div>
                        <div className="flex gap-2">
                          <button type="button" className="p-2 rounded-lg text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10" onClick={() => editCommissaryDraft(commissary)}>
                            <Pencil size={16} />
                          </button>
                          <button type="button" className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeCommissaryDraft(commissary.id)}>
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>)}

              {/* ── Modulos do Evento ── */}
              <EventModulesSelector
                modules={form.modules_enabled}
                onToggle={(key) =>
                  setForm((f) => ({
                    ...f,
                    modules_enabled: f.modules_enabled.includes(key)
                      ? f.modules_enabled.filter((m) => m !== key)
                      : [...f.modules_enabled, key],
                  }))
                }
                disabled={saving}
              />

              {/* ── Secoes de configuracao por modulo ativo ── */}
              {form.modules_enabled.includes("location") && (
                <LocationSection form={form} setForm={setForm} />
              )}
              {form.modules_enabled.includes("stages") && (
                <StagesSection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("sectors") && (
                <SectorsSection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("parking_config") && (
                <ParkingConfigSection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("pdv_points") && (
                <PdvPointsSection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("seating") && (
                <SeatingChart eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("sessions") && (
                <AgendaBuilder eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("exhibitors") && (
                <ExhibitorsSection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("invitations") && (
                <InvitationsSection eventId={editingEventId} form={form} setForm={setForm} />
              )}
              {form.modules_enabled.includes("ceremony") && (
                <CeremonySection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("sub_events") && (
                <SubEventsSection eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("maps") && (
                <MapsSection eventId={editingEventId} form={form} setForm={setForm} />
              )}
              {form.modules_enabled.includes("maps") && (
                <MapBuilder eventId={editingEventId} />
              )}
              {form.modules_enabled.includes("certificates") && (
                <CertificatesSection eventId={editingEventId} />
              )}

              <div className="flex items-end gap-3">
                <button
                  type="submit"
                  disabled={saving}
                  className="an-btn an-btn-primary flex-1"
                >
                  {saving ? <span className="spinner w-4 h-4" /> : editingEventId ? "Salvar Evento e Configurações" : "Criar Evento com Configurações"}
                </button>
                <button
                  type="button"
                  onClick={closeEventForm}
                  className="an-btn an-btn-secondary flex-1"
                >
                  Cancelar
                </button>
              </div>
            </form>
          )}
        </div>
      )}

      {loading ? (
        <div className="flex items-center justify-center py-20">
          <div className="spinner w-10 h-10" />
        </div>
      ) : events.length === 0 ? (
        <div className="empty-state">
          <CalendarDays size={48} className="text-slate-700" />
          <p className="text-lg">Nenhum evento encontrado</p>
          <button
            onClick={openCreateForm}
            className="an-btn an-btn-primary mt-2"
          >
            <Plus size={16} /> Criar Primeiro Evento
          </button>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {events.map((ev) => (
            <div key={ev.id} className="bg-[#111827] border border-slate-800/60 rounded-2xl overflow-hidden flex flex-col group hover:border-cyan-500/40 transition-all duration-300">
              {/* Banner */}
              <div className="h-32 relative bg-gradient-to-br from-slate-800 to-slate-900">
                {ev.banner_url && (
                  <img src={ev.banner_url} alt={ev.name} className="w-full h-full object-cover" />
                )}
                {/* Banner vazio — sem ícone fantasma */}
                <div className="absolute top-4 left-4 flex gap-2">
                  {ev.event_type && EVENT_TYPE_LABELS[ev.event_type] && (
                    <span className="px-2 py-1 bg-indigo-500/20 text-indigo-300 backdrop-blur-md text-[10px] font-bold uppercase tracking-wider rounded border border-indigo-500/30">
                      {EVENT_TYPE_LABELS[ev.event_type]}
                    </span>
                  )}
                  <span className={`px-2 py-1 backdrop-blur-md text-[10px] font-bold uppercase tracking-wider rounded border flex items-center gap-1 ${
                    ev.status === 'ongoing' ? 'bg-green-500/20 text-green-400 border-green-500/30' :
                    ev.status === 'published' ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30' :
                    ev.status === 'cancelled' ? 'bg-red-500/20 text-red-400 border-red-500/30' :
                    'bg-slate-500/20 text-slate-400 border-slate-500/30'
                  }`}>
                    {ev.status === 'ongoing' && <span className="w-1.5 h-1.5 bg-green-400 rounded-full animate-pulse" />}
                    {statusLabel[ev.status] || ev.status}
                  </span>
                </div>
              </div>

              {/* Content */}
              <div className="p-6 flex flex-col flex-1">
                <div className="mb-4">
                  <h3 className="text-xl font-bold text-slate-100 group-hover:text-cyan-400 transition-colors truncate">
                    {ev.name}
                  </h3>
                  <p className="text-slate-500 text-sm">por {ev.organizer_name || "Enjoy Fun"}</p>
                </div>
                <div className="space-y-3 mb-6">
                  {ev.venue_name && (
                    <div className="flex items-center gap-3 text-slate-400 text-sm">
                      <MapPin size={16} className="text-cyan-400/60 shrink-0" />
                      <span>{ev.venue_name}</span>
                    </div>
                  )}
                  <div className="flex items-center gap-3 text-slate-400 text-sm">
                    <Clock size={16} className="text-cyan-400/60 shrink-0" />
                    <span>{new Date(ev.starts_at).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" })}</span>
                  </div>
                  {(ev.capacity || (ev.modules_enabled && ev.modules_enabled.length > 0)) && (
                    <div className="flex items-center gap-3 text-slate-400 text-sm">
                      <Users size={16} className="text-cyan-400/60 shrink-0" />
                      <span>
                        {ev.capacity ? `${parseInt(ev.capacity, 10).toLocaleString()}` : ""}
                        {ev.capacity && ev.modules_enabled?.length > 0 ? " / " : ""}
                        {ev.modules_enabled?.length > 0 ? `${ev.modules_enabled.length} módulos` : ""}
                      </span>
                    </div>
                  )}
                </div>
                <div className="mt-auto pt-6 border-t border-slate-800/50 flex gap-2">
                  <button
                    type="button"
                    className="flex-1 py-2 bg-slate-800/50 text-slate-100 text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors"
                    onClick={() => startEditEvent(ev.id)}
                  >
                    Editar
                  </button>
                  {ev.can_delete && (
                    <button
                      type="button"
                      className="p-2 text-red-400/70 hover:text-red-400 hover:bg-red-400/10 rounded-lg transition-all"
                      onClick={() => handleDeleteEvent(ev.id)}
                    >
                      <Trash2 size={18} />
                    </button>
                  )}
                  <Link
                    to={`/events/${ev.id}`}
                    className="p-2 bg-cyan-500/10 text-cyan-400 hover:bg-cyan-500/20 rounded-lg transition-all"
                  >
                    <ChevronRight size={18} />
                  </Link>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
