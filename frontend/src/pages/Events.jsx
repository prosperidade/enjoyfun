import { useEffect, useState, useCallback } from "react";
import api from "../lib/api";
import {
  CalendarDays,
  Plus,
  MapPin,
  Clock,
  ChevronRight,
  Search,
  Trash2,
  Pencil,
  X,
} from "lucide-react";
import { Link, useSearchParams } from "react-router-dom";
import toast from "react-hot-toast";

const statusBadge = {
  draft: "badge-gray",
  published: "badge-green",
  ongoing: "badge-blue",
  finished: "badge-gray",
  cancelled: "badge-red",
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
    event_timezone: event?.event_timezone || "",
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
    setForm(createEmptyEventForm());
    setBatchForm(EMPTY_BATCH_FORM);
    setCommissaryForm(EMPTY_COMMISSARY_FORM);
    setDraftBatches([]);
    setDraftCommissaries([]);
  }, []);

  const closeEventForm = useCallback(() => {
    setShowForm(false);
    resetEventFormState();
    updateEditQuery(null);
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
      setTicketTypes((typesRes.data?.data || []).map(mapTicketTypeToDraft));
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
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <CalendarDays size={22} className="text-purple-400" /> Eventos
          </h1>
          <p className="text-gray-500 text-sm mt-1">
            {events.length} evento(s) encontrado(s)
          </p>
        </div>
        <button onClick={openCreateForm} className="btn-primary">
          <Plus size={16} /> Novo Evento
        </button>
      </div>

      <div className="relative">
        <Search
          size={16}
          className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500"
        />
        <input
          name="events_search"
          className="input pl-10"
          placeholder="Buscar eventos..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {showForm && (
        <div className="card border-purple-800/40 space-y-6">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="section-title">{editingEventId ? "Editar Evento" : "Novo Evento"}</h2>
              <p className="text-sm text-gray-500 mt-1">
                Configure o evento e toda a operação comercial na mesma interface.
              </p>
            </div>
            <button type="button" className="btn-outline btn-sm" onClick={closeEventForm}>
              <X size={14} /> Fechar
            </button>
          </div>

          {loadingForm ? (
            <div className="flex items-center justify-center py-20">
              <div className="spinner w-10 h-10" />
            </div>
          ) : (
            <form onSubmit={handleSaveEvent} className="space-y-6">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <label className="input-label">Nome do Evento *</label>
                  <input
                    name="event_name"
                    className="input"
                    value={form.name}
                    onChange={set("name")}
                    required
                    placeholder="Ex: Festival de Verão 2025"
                  />
                </div>
                <div>
                  <label className="input-label">Local</label>
                  <input
                    name="event_venue_name"
                    className="input"
                    value={form.venue_name}
                    onChange={set("venue_name")}
                    placeholder="Nome do local"
                  />
                </div>
                <div>
                  <label className="input-label">Capacidade</label>
                  <input
                    name="event_capacity"
                    className="input"
                    type="number"
                    value={form.capacity}
                    onChange={set("capacity")}
                    placeholder="Ex: 5000"
                  />
                </div>
                <div>
                  <label className="input-label">Início *</label>
                  <input
                    name="event_starts_at"
                    className="input"
                    type="datetime-local"
                    value={form.starts_at}
                    onChange={set("starts_at")}
                    required
                  />
                </div>
                <div>
                  <label className="input-label">Término *</label>
                  <input
                    name="event_ends_at"
                    className="input"
                    type="datetime-local"
                    value={form.ends_at}
                    onChange={set("ends_at")}
                    required
                  />
                </div>
                <div className="sm:col-span-2">
                  <label className="input-label">Timezone Operacional *</label>
                  <input
                    name="event_timezone"
                    className="input"
                    value={form.event_timezone}
                    onChange={set("event_timezone")}
                    placeholder="Ex: America/Sao_Paulo"
                    required
                  />
                  <p className="mt-1 text-xs text-gray-500">
                    Usada para resolver calendário operacional e converter payloads com offset no Meals.
                  </p>
                </div>
                <div className="sm:col-span-2">
                  <label className="input-label">Endereço</label>
                  <input
                    name="event_address"
                    className="input"
                    value={form.address}
                    onChange={set("address")}
                    placeholder="Rua, número, cidade"
                  />
                </div>
                <div className="sm:col-span-2">
                  <label className="input-label">Descrição</label>
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
                  <label className="input-label">Status</label>
                  <select
                    name="event_status"
                    className="select"
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

              <div className="rounded-2xl border border-gray-800 bg-gray-950/40 p-4 space-y-4">
                <div>
                  <h3 className="section-title">Tipos de Ingresso</h3>
                  <p className="text-xs text-gray-500 mt-1">
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
                    className="input"
                    placeholder="Preço base"
                    value={ticketTypeForm.price}
                    onChange={(e) => setTicketTypeForm((current) => ({ ...current, price: e.target.value }))}
                  />
                  <div className="sm:col-span-3 flex gap-3">
                    <button type="button" className="btn-secondary flex-1" onClick={upsertTicketTypeDraft}>
                      <Plus size={16} /> {ticketTypeForm.id || ticketTypeForm.client_key ? "Atualizar Tipo" : "Adicionar Tipo"}
                    </button>
                    {ticketTypeForm.id || ticketTypeForm.client_key ? (
                      <button type="button" className="btn-outline flex-1" onClick={() => setTicketTypeForm(EMPTY_TICKET_TYPE_FORM)}>
                        Cancelar edição
                      </button>
                    ) : null}
                  </div>
                </div>

                <div className="space-y-2 max-h-60 overflow-auto">
                  {ticketTypes.length === 0 ? (
                    <p className="text-sm text-gray-500">Nenhum tipo de ingresso configurado.</p>
                  ) : ticketTypes.map((ticketType) => (
                    <div key={String(ticketType.id ?? ticketType.client_key)} className="rounded-xl border border-gray-800 p-3 flex items-start justify-between gap-3">
                      <div>
                        <p className="font-medium text-white">{ticketType.name}</p>
                        <p className="text-xs text-gray-500">
                          Preço base: R$ {Number(ticketType.price || 0).toFixed(2)}
                        </p>
                      </div>
                      <div className="flex gap-2">
                        <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-purple-300 hover:bg-purple-500/10" onClick={() => editTicketTypeDraft(ticketType)}>
                          <Pencil size={16} />
                        </button>
                        <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeTicketTypeDraft(ticketType)}>
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="grid xl:grid-cols-2 gap-6">
                <div className="rounded-2xl border border-gray-800 bg-gray-950/40 p-4 space-y-4">
                  <div>
                    <h3 className="section-title">Lotes Comerciais</h3>
                    <p className="text-xs text-gray-500 mt-1">
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
                      className="input"
                      placeholder="Código"
                      value={batchForm.code}
                      onChange={(e) => setBatchForm((f) => ({ ...f, code: e.target.value }))}
                    />
                    <input
                      name="batch_price"
                      type="number"
                      step="0.01"
                      className="input"
                      placeholder="Preço"
                      value={batchForm.price}
                      onChange={(e) => setBatchForm((f) => ({ ...f, price: e.target.value }))}
                    />
                    <input
                      name="batch_quantity_total"
                      type="number"
                      className="input"
                      placeholder="Qtd. total"
                      value={batchForm.quantity_total}
                      onChange={(e) => setBatchForm((f) => ({ ...f, quantity_total: e.target.value }))}
                    />
                    <input
                      name="batch_starts_at"
                      type="datetime-local"
                      className="input"
                      value={batchForm.starts_at}
                      onChange={(e) => setBatchForm((f) => ({ ...f, starts_at: e.target.value }))}
                    />
                    <input
                      name="batch_ends_at"
                      type="datetime-local"
                      className="input"
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
                      <p className="text-xs text-gray-500 col-span-2">
                        O vínculo com tipo de ingresso pode ser definido quando o evento já possui tipos cadastrados.
                      </p>
                    )}
                    <label className="col-span-2 flex items-center gap-2 text-sm text-gray-300">
                      <input
                        name="batch_is_active"
                        type="checkbox"
                        checked={batchForm.is_active}
                        onChange={(e) => setBatchForm((f) => ({ ...f, is_active: e.target.checked }))}
                      />
                      Lote ativo
                    </label>
                    <div className="col-span-2 flex gap-3">
                      <button type="button" className="btn-secondary flex-1" onClick={upsertBatchDraft}>
                        <Plus size={16} /> {batchForm.id ? "Atualizar Lote" : "Adicionar Lote"}
                      </button>
                      {batchForm.id ? (
                        <button type="button" className="btn-outline flex-1" onClick={() => setBatchForm(EMPTY_BATCH_FORM)}>
                          Cancelar edição
                        </button>
                      ) : null}
                    </div>
                  </div>

                  <div className="space-y-2 max-h-72 overflow-auto">
                    {draftBatches.length === 0 ? (
                      <p className="text-sm text-gray-500">Nenhum lote configurado.</p>
                    ) : draftBatches.map((batch) => (
                      <div key={batch.id} className="rounded-xl border border-gray-800 p-3 flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium text-white">{batch.name}</p>
                          <p className="text-xs text-gray-500">
                            {batch.code || "Sem código"} • R$ {Number(batch.price || 0).toFixed(2)} • Qtd.: {batch.quantity_total || "Livre"}
                          </p>
                          <p className="text-[11px] text-gray-500 mt-1">
                            {batch.ticket_type_name || (batch.ticket_type_id ? `Tipo #${batch.ticket_type_id}` : "Sem vínculo com tipo")} • {batch.is_active === false ? "Inativo" : "Ativo"}
                          </p>
                        </div>
                        <div className="flex gap-2">
                          <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-purple-300 hover:bg-purple-500/10" onClick={() => editBatchDraft(batch)}>
                            <Pencil size={16} />
                          </button>
                          <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeBatchDraft(batch.id)}>
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="rounded-2xl border border-gray-800 bg-gray-950/40 p-4 space-y-4">
                  <div>
                    <h3 className="section-title">Comissários</h3>
                    <p className="text-xs text-gray-500 mt-1">
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
                      className="input"
                      placeholder="E-mail"
                      value={commissaryForm.email}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, email: e.target.value }))}
                    />
                    <input
                      name="commissary_phone"
                      className="input"
                      placeholder="Telefone"
                      value={commissaryForm.phone}
                      onChange={(e) => setCommissaryForm((f) => ({ ...f, phone: e.target.value }))}
                    />
                    <select
                      name="commissary_commission_mode"
                      className="select"
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
                      className="input"
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
                      <button type="button" className="btn-secondary flex-1" onClick={upsertCommissaryDraft}>
                        <Plus size={16} /> {commissaryForm.id ? "Atualizar Comissário" : "Adicionar Comissário"}
                      </button>
                      {commissaryForm.id ? (
                        <button type="button" className="btn-outline flex-1" onClick={() => setCommissaryForm(EMPTY_COMMISSARY_FORM)}>
                          Cancelar edição
                        </button>
                      ) : null}
                    </div>
                  </div>

                  <div className="space-y-2 max-h-72 overflow-auto">
                    {draftCommissaries.length === 0 ? (
                      <p className="text-sm text-gray-500">Nenhum comissário configurado.</p>
                    ) : draftCommissaries.map((commissary) => (
                      <div key={commissary.id} className="rounded-xl border border-gray-800 p-3 flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium text-white">{commissary.name}</p>
                          <p className="text-xs text-gray-500">
                            {commissary.commission_mode === "percent"
                              ? `${Number(commissary.commission_value || 0)}%`
                              : `R$ ${Number(commissary.commission_value || 0).toFixed(2)}`}
                          </p>
                          <p className="text-[11px] text-gray-500 mt-1">
                            {commissary.email || "Sem e-mail"} • {commissary.status === "inactive" ? "Inativo" : "Ativo"}
                          </p>
                        </div>
                        <div className="flex gap-2">
                          <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-purple-300 hover:bg-purple-500/10" onClick={() => editCommissaryDraft(commissary)}>
                            <Pencil size={16} />
                          </button>
                          <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeCommissaryDraft(commissary.id)}>
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              <div className="flex items-end gap-3">
                <button
                  type="submit"
                  disabled={saving}
                  className="btn-primary flex-1"
                >
                  {saving ? <span className="spinner w-4 h-4" /> : editingEventId ? "Salvar Evento e Configurações" : "Criar Evento com Configurações"}
                </button>
                <button
                  type="button"
                  onClick={closeEventForm}
                  className="btn-outline flex-1"
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
          <CalendarDays size={48} className="text-gray-700" />
          <p className="text-lg">Nenhum evento encontrado</p>
          <button
            onClick={openCreateForm}
            className="btn-primary mt-2"
          >
            <Plus size={16} /> Criar Primeiro Evento
          </button>
        </div>
      ) : (
        <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
          {events.map((ev) => (
            <div key={ev.id} className="card-hover flex flex-col gap-3">
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <h3 className="font-semibold text-white truncate">
                    {ev.name}
                  </h3>
                  <p className="text-xs text-gray-500 mt-0.5">
                    por {ev.organizer_name || "Enjoy Fun"}
                  </p>
                </div>
                <span className={statusBadge[ev.status] || "badge-gray"}>
                  {statusLabel[ev.status] || ev.status}
                </span>
              </div>

              {ev.venue_name && (
                <div className="flex items-center gap-1.5 text-xs text-gray-400">
                  <MapPin size={12} /> {ev.venue_name}
                </div>
              )}
              <div className="flex items-center gap-1.5 text-xs text-gray-400">
                <Clock size={12} />
                {new Date(ev.starts_at).toLocaleString("pt-BR", {
                  dateStyle: "short",
                  timeStyle: "short",
                })}
              </div>
              {ev.capacity ? (
                <div className="text-xs text-gray-500">
                  Capacidade: {parseInt(ev.capacity, 10).toLocaleString()}
                </div>
              ) : null}

              <div className="flex gap-2 pt-2 border-t border-gray-800 mt-auto">
                <button
                  type="button"
                  className="btn-outline btn-sm"
                  onClick={() => startEditEvent(ev.id)}
                  title="Editar evento"
                >
                  <Pencil size={14} />
                </button>
                {ev.can_delete ? (
                  <button
                    type="button"
                    className="btn-outline btn-sm"
                    onClick={() => handleDeleteEvent(ev.id)}
                    title="Excluir evento"
                  >
                    <Trash2 size={14} />
                  </button>
                ) : null}
                <Link
                  to={`/events/${ev.id}`}
                  className="btn-outline btn-sm flex-1"
                >
                  Ver Detalhes <ChevronRight size={14} />
                </Link>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
