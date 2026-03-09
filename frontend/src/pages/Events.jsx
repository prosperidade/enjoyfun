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
} from "lucide-react";
import { Link } from "react-router-dom";
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

const EMPTY_EVENT_FORM = {
  name: "",
  description: "",
  venue_name: "",
  address: "",
  starts_at: "",
  ends_at: "",
  status: "draft",
  capacity: "",
};

const EMPTY_BATCH_FORM = {
  name: "",
  code: "",
  price: "",
  quantity_total: "",
  starts_at: "",
  ends_at: "",
};

const EMPTY_COMMISSARY_FORM = {
  name: "",
  email: "",
  phone: "",
  commission_mode: "percent",
  commission_value: "",
};

export default function Events() {
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(EMPTY_EVENT_FORM);
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

  const resetCreateState = () => {
    setForm(EMPTY_EVENT_FORM);
    setBatchForm(EMPTY_BATCH_FORM);
    setCommissaryForm(EMPTY_COMMISSARY_FORM);
    setDraftBatches([]);
    setDraftCommissaries([]);
  };

  const closeCreateForm = () => {
    setShowForm(false);
    resetCreateState();
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const addBatchDraft = () => {
    if (!batchForm.name.trim()) {
      toast.error("Informe o nome do lote.");
      return;
    }

    setDraftBatches((prev) => [
      ...prev,
      {
        id: crypto.randomUUID(),
        name: batchForm.name.trim(),
        code: batchForm.code.trim(),
        price: batchForm.price,
        quantity_total: batchForm.quantity_total,
        starts_at: batchForm.starts_at,
        ends_at: batchForm.ends_at,
      },
    ]);
    setBatchForm(EMPTY_BATCH_FORM);
  };

  const addCommissaryDraft = () => {
    if (!commissaryForm.name.trim()) {
      toast.error("Informe o nome do comissário.");
      return;
    }

    setDraftCommissaries((prev) => [
      ...prev,
      {
        id: crypto.randomUUID(),
        name: commissaryForm.name.trim(),
        email: commissaryForm.email.trim(),
        phone: commissaryForm.phone.trim(),
        commission_mode: commissaryForm.commission_mode,
        commission_value: commissaryForm.commission_value,
      },
    ]);
    setCommissaryForm(EMPTY_COMMISSARY_FORM);
  };

  const removeBatchDraft = (id) => {
    setDraftBatches((prev) => prev.filter((item) => item.id !== id));
  };

  const removeCommissaryDraft = (id) => {
    setDraftCommissaries((prev) => prev.filter((item) => item.id !== id));
  };

  const handleCreate = async (e) => {
    e.preventDefault();
    setSaving(true);

    try {
      const eventRes = await api.post("/events", form);
      const eventId = Number(eventRes.data?.data?.id || 0);
      const warnings = [];

      if (eventId > 0 && draftBatches.length > 0) {
        for (const batch of draftBatches) {
          try {
            await api.post("/tickets/batches", {
              event_id: eventId,
              name: batch.name,
              code: batch.code || null,
              price: batch.price === "" ? 0 : Number(batch.price),
              quantity_total: batch.quantity_total === "" ? null : Number(batch.quantity_total),
              starts_at: batch.starts_at || null,
              ends_at: batch.ends_at || null,
            });
          } catch (err) {
            warnings.push(err.response?.data?.message || `Falha ao salvar lote "${batch.name}".`);
            break;
          }
        }
      }

      if (eventId > 0 && draftCommissaries.length > 0) {
        for (const commissary of draftCommissaries) {
          try {
            await api.post("/tickets/commissaries", {
              event_id: eventId,
              name: commissary.name,
              email: commissary.email || null,
              phone: commissary.phone || null,
              commission_mode: commissary.commission_mode,
              commission_value: commissary.commission_value === "" ? 0 : Number(commissary.commission_value),
            });
          } catch (err) {
            warnings.push(err.response?.data?.message || `Falha ao salvar comissário "${commissary.name}".`);
            break;
          }
        }
      }

      if (warnings.length > 0) {
        toast.success("Evento criado.");
        toast.error(warnings[0]);
      } else {
        toast.success("Evento e configurações criados com sucesso.");
      }

      closeCreateForm();
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao criar evento.");
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
        <button onClick={() => setShowForm((prev) => !prev)} className="btn-primary">
          <Plus size={16} /> Novo Evento
        </button>
      </div>

      <div className="relative">
        <Search
          size={16}
          className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500"
        />
        <input
          className="input pl-10"
          placeholder="Buscar eventos..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {showForm && (
        <div className="card border-purple-800/40 space-y-6">
          <div>
            <h2 className="section-title">Novo Evento</h2>
            <p className="text-sm text-gray-500 mt-1">
              Configure tudo na mesma interface: informações do evento, lotes comerciais e comissários.
            </p>
          </div>

          <form onSubmit={handleCreate} className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="sm:col-span-2">
                <label className="input-label">Nome do Evento *</label>
                <input
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
                  className="input"
                  value={form.venue_name}
                  onChange={set("venue_name")}
                  placeholder="Nome do local"
                />
              </div>
              <div>
                <label className="input-label">Capacidade</label>
                <input
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
                  className="input"
                  type="datetime-local"
                  value={form.ends_at}
                  onChange={set("ends_at")}
                  required
                />
              </div>
              <div className="sm:col-span-2">
                <label className="input-label">Endereço</label>
                <input
                  className="input"
                  value={form.address}
                  onChange={set("address")}
                  placeholder="Rua, número, cidade"
                />
              </div>
              <div className="sm:col-span-2">
                <label className="input-label">Descrição</label>
                <textarea
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
                  className="select"
                  value={form.status}
                  onChange={set("status")}
                >
                  <option value="draft">Rascunho</option>
                  <option value="published">Publicado</option>
                </select>
              </div>
            </div>

            <div className="grid xl:grid-cols-2 gap-6">
              <div className="rounded-2xl border border-gray-800 bg-gray-950/40 p-4 space-y-4">
                <div>
                  <h3 className="section-title">Lotes Comerciais</h3>
                  <p className="text-xs text-gray-500 mt-1">
                    Os lotes serão criados junto com o evento.
                  </p>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <input
                    className="input col-span-2"
                    placeholder="Nome do lote"
                    value={batchForm.name}
                    onChange={(e) => setBatchForm((f) => ({ ...f, name: e.target.value }))}
                  />
                  <input
                    className="input"
                    placeholder="Código"
                    value={batchForm.code}
                    onChange={(e) => setBatchForm((f) => ({ ...f, code: e.target.value }))}
                  />
                  <input
                    type="number"
                    step="0.01"
                    className="input"
                    placeholder="Preço"
                    value={batchForm.price}
                    onChange={(e) => setBatchForm((f) => ({ ...f, price: e.target.value }))}
                  />
                  <input
                    type="number"
                    className="input"
                    placeholder="Qtd. total"
                    value={batchForm.quantity_total}
                    onChange={(e) => setBatchForm((f) => ({ ...f, quantity_total: e.target.value }))}
                  />
                  <input
                    type="datetime-local"
                    className="input"
                    value={batchForm.starts_at}
                    onChange={(e) => setBatchForm((f) => ({ ...f, starts_at: e.target.value }))}
                  />
                  <input
                    type="datetime-local"
                    className="input"
                    value={batchForm.ends_at}
                    onChange={(e) => setBatchForm((f) => ({ ...f, ends_at: e.target.value }))}
                  />
                  <button type="button" className="btn-secondary col-span-2" onClick={addBatchDraft}>
                    <Plus size={16} /> Adicionar Lote
                  </button>
                </div>

                <div className="space-y-2 max-h-60 overflow-auto">
                  {draftBatches.length === 0 ? (
                    <p className="text-sm text-gray-500">Nenhum lote preparado.</p>
                  ) : draftBatches.map((batch) => (
                    <div key={batch.id} className="rounded-xl border border-gray-800 p-3 flex items-start justify-between gap-3">
                      <div>
                        <p className="font-medium text-white">{batch.name}</p>
                        <p className="text-xs text-gray-500">
                          {batch.code || "Sem código"} • R$ {Number(batch.price || 0).toFixed(2)} • Qtd.: {batch.quantity_total || "Livre"}
                        </p>
                      </div>
                      <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeBatchDraft(batch.id)}>
                        <Trash2 size={16} />
                      </button>
                    </div>
                  ))}
                </div>
              </div>

              <div className="rounded-2xl border border-gray-800 bg-gray-950/40 p-4 space-y-4">
                <div>
                  <h3 className="section-title">Comissários</h3>
                  <p className="text-xs text-gray-500 mt-1">
                    Cadastre a operação comercial junto com o evento.
                  </p>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <input
                    className="input col-span-2"
                    placeholder="Nome"
                    value={commissaryForm.name}
                    onChange={(e) => setCommissaryForm((f) => ({ ...f, name: e.target.value }))}
                  />
                  <input
                    type="email"
                    className="input"
                    placeholder="E-mail"
                    value={commissaryForm.email}
                    onChange={(e) => setCommissaryForm((f) => ({ ...f, email: e.target.value }))}
                  />
                  <input
                    className="input"
                    placeholder="Telefone"
                    value={commissaryForm.phone}
                    onChange={(e) => setCommissaryForm((f) => ({ ...f, phone: e.target.value }))}
                  />
                  <select
                    className="select"
                    value={commissaryForm.commission_mode}
                    onChange={(e) => setCommissaryForm((f) => ({ ...f, commission_mode: e.target.value }))}
                  >
                    <option value="percent">Percentual (%)</option>
                    <option value="fixed">Valor Fixo (R$)</option>
                  </select>
                  <input
                    type="number"
                    step="0.01"
                    className="input"
                    placeholder="Valor da comissão"
                    value={commissaryForm.commission_value}
                    onChange={(e) => setCommissaryForm((f) => ({ ...f, commission_value: e.target.value }))}
                  />
                  <button type="button" className="btn-secondary col-span-2" onClick={addCommissaryDraft}>
                    <Plus size={16} /> Adicionar Comissário
                  </button>
                </div>

                <div className="space-y-2 max-h-60 overflow-auto">
                  {draftCommissaries.length === 0 ? (
                    <p className="text-sm text-gray-500">Nenhum comissário preparado.</p>
                  ) : draftCommissaries.map((commissary) => (
                    <div key={commissary.id} className="rounded-xl border border-gray-800 p-3 flex items-start justify-between gap-3">
                      <div>
                        <p className="font-medium text-white">{commissary.name}</p>
                        <p className="text-xs text-gray-500">
                          {commissary.commission_mode === "percent"
                            ? `${Number(commissary.commission_value || 0)}%`
                            : `R$ ${Number(commissary.commission_value || 0).toFixed(2)}`}
                        </p>
                      </div>
                      <button type="button" className="p-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-red-500/10" onClick={() => removeCommissaryDraft(commissary.id)}>
                        <Trash2 size={16} />
                      </button>
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
                {saving ? <span className="spinner w-4 h-4" /> : "Criar Evento com Configurações"}
              </button>
              <button
                type="button"
                onClick={closeCreateForm}
                className="btn-outline flex-1"
              >
                Cancelar
              </button>
            </div>
          </form>
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
            onClick={() => setShowForm(true)}
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
              {ev.capacity && (
                <div className="text-xs text-gray-500">
                  Capacidade: {parseInt(ev.capacity).toLocaleString()}
                </div>
              )}

              <div className="flex gap-2 pt-2 border-t border-gray-800 mt-auto">
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
