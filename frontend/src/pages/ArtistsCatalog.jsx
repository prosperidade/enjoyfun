import { useEffect, useState } from "react";
import {
  AlertCircle,
  CalendarDays,
  ChevronRight,
  MicVocal,
  Plus,
  RefreshCw,
  Search,
  Upload,
  XCircle,
} from "lucide-react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import api from "../lib/api";
import { useAuth } from "../context/AuthContext";
import { useEventScope } from "../context/EventScopeContext";
import {
  ALERT_SEVERITY_META,
  BOOKING_STATUS_META,
  formatCurrency,
  formatDateTime,
  formatNumber,
  resolveMeta,
} from "../modules/artists/artistUi";
import toast from "react-hot-toast";

function emptyToNull(value) {
  const normalized = String(value ?? "").trim();
  return normalized === "" ? null : normalized;
}

function toSqlDateTime(value) {
  const normalized = String(value ?? "").trim();
  return normalized === "" ? null : `${normalized.replace("T", " ")}:00`;
}

function toIntegerOrNull(value) {
  const normalized = String(value ?? "").trim();
  if (normalized === "") {
    return null;
  }

  return Number.parseInt(normalized, 10);
}

function toDecimalOrNull(value) {
  const normalized = String(value ?? "").trim().replace(",", ".");
  if (normalized === "") {
    return null;
  }

  const amount = Number.parseFloat(normalized);
  return Number.isFinite(amount) ? amount : null;
}

function SummaryCard({ label, value, helper, tone = "default" }) {
  const toneClass =
    tone === "warning"
      ? "border-yellow-500/20 bg-yellow-500/5"
      : tone === "danger"
        ? "border-red-500/20 bg-red-500/5"
        : "border-white/5";

  return (
    <div className={`card ${toneClass}`}>
      <p className="text-xs uppercase tracking-[0.18em] text-gray-500">{label}</p>
      <p className="mt-3 text-3xl font-semibold text-white">{value}</p>
      <p className="mt-2 text-sm text-gray-500">{helper}</p>
    </div>
  );
}

function ModuleStatusCard({ moduleStatus, loading }) {
  if (loading) {
    return (
      <div className="card border-white/5">
        <p className="text-sm text-gray-500">Lendo status do backend de artistas...</p>
      </div>
    );
  }

  if (!moduleStatus) {
    return null;
  }

  const missingTables = Array.isArray(moduleStatus.required_tables)
    ? moduleStatus.required_tables.filter((item) => !item?.exists)
    : [];

  return (
    <div
      className={`card border ${
        moduleStatus.schema_ready
          ? "border-emerald-500/20 bg-emerald-500/5"
          : "border-red-500/20 bg-red-500/5"
      }`}
    >
      <div className="flex flex-wrap items-center gap-3">
        <span className={moduleStatus.schema_ready ? "badge-green" : "badge-red"}>
          {moduleStatus.schema_ready ? "Backend pronto" : "Estrutura pendente"}
        </span>
        <span className="text-sm text-gray-400">
          {moduleStatus.schema_ready
            ? "Cadastros, contratacoes e operacao de artistas disponiveis."
            : "O backend ainda nao liberou toda a estrutura do modulo."}
        </span>
      </div>
      {!moduleStatus.schema_ready && missingTables.length > 0 && (
        <p className="mt-3 text-sm text-red-300">
          Tabelas ausentes: {missingTables.map((item) => item.table || item.table_name || "desconhecida").join(", ")}
        </p>
      )}
    </div>
  );
}

function ArtistFormModal({ events, selectedEventId, onClose, onSaved }) {
  const [form, setForm] = useState(() => ({
    stage_name: "",
    legal_name: "",
    document_number: "",
    artist_type: "",
    default_contact_name: "",
    default_contact_phone: "",
    notes: "",
    is_active: true,
    register_contract: Boolean(selectedEventId),
    event_id: selectedEventId ? String(selectedEventId) : "",
    contract_status: selectedEventId ? "contracted" : "pending",
    contract_stage_name: "",
    performance_date: "",
    performance_start_at: "",
    soundcheck_at: "",
    performance_duration_minutes: "",
    cache_amount: "",
    contract_notes: "",
  }));
  const [saving, setSaving] = useState(false);
  const isRegisteringContract = Boolean(form.register_contract);
  const selectedEvent = events.find((item) => String(item.id) === String(form.event_id));

  function setField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();

    if (!emptyToNull(form.stage_name)) {
      toast.error("Informe o nome artistico.");
      return;
    }

    if (isRegisteringContract && !form.event_id) {
      toast.error("Selecione o evento da contratacao.");
      return;
    }

    setSaving(true);
    let createdArtist = null;

    try {
      const response = await api.post("/artists", {
        stage_name: emptyToNull(form.stage_name),
        legal_name: emptyToNull(form.legal_name),
        document_number: emptyToNull(form.document_number),
        artist_type: emptyToNull(form.artist_type),
        default_contact_name: emptyToNull(form.default_contact_name),
        default_contact_phone: emptyToNull(form.default_contact_phone),
        notes: emptyToNull(form.notes),
        is_active: Boolean(form.is_active),
      });
      createdArtist = response.data?.data || null;

      let createdContract = null;
      if (isRegisteringContract && createdArtist?.id) {
        const contractResponse = await api.post("/artists/bookings", {
          event_id: Number(form.event_id),
          artist_id: Number(createdArtist.id),
          booking_status: form.contract_status,
          performance_date: emptyToNull(form.performance_date),
          performance_start_at: toSqlDateTime(form.performance_start_at),
          soundcheck_at: toSqlDateTime(form.soundcheck_at),
          performance_duration_minutes: toIntegerOrNull(form.performance_duration_minutes),
          stage_name: emptyToNull(form.contract_stage_name),
          cache_amount: toDecimalOrNull(form.cache_amount),
          notes: emptyToNull(form.contract_notes),
        });

        createdContract = contractResponse.data?.data || null;
      }

      toast.success(
        isRegisteringContract
          ? "Artista e contratacao cadastrados com sucesso."
          : "Artista criado com sucesso."
      );
      onSaved({
        artist: createdArtist,
        contract: createdContract,
        eventId: form.event_id ? Number(form.event_id) : null,
        partial: false,
      });
    } catch (error) {
      if (createdArtist?.id && isRegisteringContract) {
        toast.error(
          error.response?.data?.message ||
            "O artista foi cadastrado, mas a contratacao inicial nao foi concluida."
        );
        onSaved({
          artist: createdArtist,
          contract: null,
          eventId: form.event_id ? Number(form.event_id) : null,
          partial: true,
        });
        return;
      }

      toast.error(error.response?.data?.message || "Erro ao criar artista.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
      <div className="card max-h-[90vh] w-full max-w-3xl overflow-y-auto border-white/10">
        <div className="mb-5 flex items-center justify-between gap-3">
          <div>
            <h2 className="section-title">
              {isRegisteringContract ? "Novo artista contratado" : "Novo artista"}
            </h2>
            <p className="mt-1 text-sm text-gray-500">
              {selectedEventId
                ? "Cadastre o artista e ja registre a contratacao do evento selecionado."
                : "Cadastre o artista no catalogo e, se precisar, ja vincule a contratacao inicial."}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-gray-500 transition-colors hover:text-white"
          >
            <XCircle size={20} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="grid gap-4 md:grid-cols-2">
            <label className="space-y-2">
              <span className="input-label">Nome artistico *</span>
              <input
                className="input"
                value={form.stage_name}
                onChange={(event) => setField("stage_name", event.target.value)}
                placeholder="Ex: Banda Horizonte"
              />
            </label>

            <label className="space-y-2">
              <span className="input-label">Nome juridico</span>
              <input
                className="input"
                value={form.legal_name}
                onChange={(event) => setField("legal_name", event.target.value)}
                placeholder="Razao social ou nome civil"
              />
            </label>

            <label className="space-y-2">
              <span className="input-label">Documento</span>
              <input
                className="input"
                value={form.document_number}
                onChange={(event) => setField("document_number", event.target.value)}
                placeholder="CPF / CNPJ"
              />
            </label>

            <label className="space-y-2">
              <span className="input-label">Tipo</span>
              <input
                className="input"
                value={form.artist_type}
                onChange={(event) => setField("artist_type", event.target.value)}
                placeholder="solo, banda, dj..."
              />
            </label>

            <label className="space-y-2">
              <span className="input-label">Contato padrao</span>
              <input
                className="input"
                value={form.default_contact_name}
                onChange={(event) => setField("default_contact_name", event.target.value)}
                placeholder="Pessoa responsavel"
              />
            </label>

            <label className="space-y-2">
              <span className="input-label">Telefone padrao</span>
              <input
                className="input"
                value={form.default_contact_phone}
                onChange={(event) => setField("default_contact_phone", event.target.value)}
                placeholder="(00) 00000-0000"
              />
            </label>
          </div>

          <label className="block space-y-2">
            <span className="input-label">Observacoes</span>
            <textarea
              rows={3}
              className="input resize-none"
              value={form.notes}
              onChange={(event) => setField("notes", event.target.value)}
              placeholder="Notas do cadastro base do artista"
            />
          </label>

          <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
            <input
              type="checkbox"
              className="checkbox"
              checked={form.is_active}
              onChange={(event) => setField("is_active", event.target.checked)}
            />
            Artista disponivel para novas contratacoes
          </label>

          <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
            <input
              type="checkbox"
              className="checkbox"
              checked={isRegisteringContract}
              onChange={(event) => setField("register_contract", event.target.checked)}
            />
            Registrar contratacao inicial agora
          </label>

          {isRegisteringContract && (
            <div className="space-y-4 rounded-3xl border border-white/10 bg-black/10 p-5">
              <div className="space-y-1">
                <h3 className="text-sm font-semibold uppercase tracking-[0.18em] text-gray-400">
                  Contratacao inicial
                </h3>
                <p className="text-sm text-gray-500">
                  {selectedEvent
                    ? `Vincule este artista ao evento ${selectedEvent.name} com valor, palco e horarios iniciais.`
                    : "Se quiser, o artista ja pode entrar contratado em um evento especifico."}
                </p>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2">
                  <span className="input-label">Evento *</span>
                  <select
                    className="select"
                    value={form.event_id}
                    onChange={(event) => setField("event_id", event.target.value)}
                  >
                    <option value="">Selecionar evento...</option>
                    {events.map((eventItem) => (
                      <option key={eventItem.id} value={eventItem.id}>
                        {eventItem.name}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="space-y-2">
                  <span className="input-label">Status da contratacao</span>
                  <select
                    className="select"
                    value={form.contract_status}
                    onChange={(event) => setField("contract_status", event.target.value)}
                  >
                    {Object.entries(BOOKING_STATUS_META).map(([key, value]) => (
                      <option key={key} value={key}>
                        {value.label}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="space-y-2">
                  <span className="input-label">Valor da contratacao</span>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    className="input"
                    value={form.cache_amount}
                    onChange={(event) => setField("cache_amount", event.target.value)}
                    placeholder="0,00"
                  />
                </label>

                <label className="space-y-2">
                  <span className="input-label">Palco</span>
                  <input
                    className="input"
                    value={form.contract_stage_name}
                    onChange={(event) => setField("contract_stage_name", event.target.value)}
                    placeholder="Palco principal, arena, lounge..."
                  />
                </label>

                <label className="space-y-2">
                  <span className="input-label">Data do show</span>
                  <input
                    type="date"
                    className="input"
                    value={form.performance_date}
                    onChange={(event) => setField("performance_date", event.target.value)}
                  />
                </label>

                <label className="space-y-2">
                  <span className="input-label">Inicio do show</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={form.performance_start_at}
                    onChange={(event) => setField("performance_start_at", event.target.value)}
                  />
                </label>

                <label className="space-y-2">
                  <span className="input-label">Soundcheck</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={form.soundcheck_at}
                    onChange={(event) => setField("soundcheck_at", event.target.value)}
                  />
                </label>

                <label className="space-y-2">
                  <span className="input-label">Duracao (min)</span>
                  <input
                    type="number"
                    min="0"
                    className="input"
                    value={form.performance_duration_minutes}
                    onChange={(event) => setField("performance_duration_minutes", event.target.value)}
                    placeholder="90"
                  />
                </label>
              </div>

              <label className="block space-y-2">
                <span className="input-label">Observacoes da contratacao</span>
                <textarea
                  rows={3}
                  className="input resize-none"
                  value={form.contract_notes}
                  onChange={(event) => setField("contract_notes", event.target.value)}
                  placeholder="Informacoes especificas desta contratacao"
                />
              </label>
            </div>
          )}

          <div className="flex gap-3">
            <button type="submit" disabled={saving} className="btn-primary flex-1">
              {saving
                ? "Salvando..."
                : isRegisteringContract
                  ? "Cadastrar artista e contratacao"
                  : "Cadastrar artista"}
            </button>
            <button type="button" onClick={onClose} className="btn-outline flex-1">
              Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function ArtistsCatalog() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { hasRole } = useAuth();
  const { buildScopedPath, eventId: scopedEventId, setEventId } = useEventScope();
  const canImport = hasRole("admin") || hasRole("organizer") || hasRole("manager");
  const canManage = canImport;

  const [events, setEvents] = useState([]);
  const [artists, setArtists] = useState([]);
  const [timelineByBookingId, setTimelineByBookingId] = useState({});
  const [moduleStatus, setModuleStatus] = useState(null);
  const [meta, setMeta] = useState(null);
  const eventId = searchParams.get("event_id") || scopedEventId || "";
  const [search, setSearch] = useState(searchParams.get("search") || "");
  const [activeFilter, setActiveFilter] = useState(searchParams.get("active") || "all");
  const [bookingStatusFilter, setBookingStatusFilter] = useState(
    searchParams.get("booking_status") || "all"
  );
  const [severityFilter, setSeverityFilter] = useState(searchParams.get("severity") || "all");
  const [loading, setLoading] = useState(false);
  const [statusLoading, setStatusLoading] = useState(true);
  const [operationalLoading, setOperationalLoading] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [reloadToken, setReloadToken] = useState(0);
  const [showCreateModal, setShowCreateModal] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function loadContext() {
      setStatusLoading(true);
      try {
        const [eventsRes, statusRes] = await Promise.all([
          api.get("/events"),
          api.get("/artists/module-status"),
        ]);

        if (cancelled) {
          return;
        }

        setEvents(Array.isArray(eventsRes.data?.data) ? eventsRes.data.data : []);
        setModuleStatus(statusRes.data?.data || null);
      } catch {
        if (cancelled) {
          return;
        }

        setModuleStatus(null);
      } finally {
        if (!cancelled) {
          setStatusLoading(false);
        }
      }
    }

    void loadContext();
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    const nextParams = new URLSearchParams(searchParams);

    if (eventId) {
      nextParams.set("event_id", eventId);
    } else {
      nextParams.delete("event_id");
    }

    if (search.trim()) {
      nextParams.set("search", search.trim());
    } else {
      nextParams.delete("search");
    }

    if (activeFilter !== "all") {
      nextParams.set("active", activeFilter);
    } else {
      nextParams.delete("active");
    }

    if (bookingStatusFilter !== "all") {
      nextParams.set("booking_status", bookingStatusFilter);
    } else {
      nextParams.delete("booking_status");
    }

    if (severityFilter !== "all") {
      nextParams.set("severity", severityFilter);
    } else {
      nextParams.delete("severity");
    }

    if (nextParams.toString() !== searchParams.toString()) {
      setSearchParams(nextParams, { replace: true });
    }
  }, [
    activeFilter,
    bookingStatusFilter,
    eventId,
    search,
    searchParams,
    setSearchParams,
    severityFilter,
  ]);

  useEffect(() => {
    if (moduleStatus && moduleStatus.schema_ready === false) {
      setArtists([]);
      setTimelineByBookingId({});
      setMeta(null);
      setLoadError("A estrutura do modulo ainda nao esta pronta no backend.");
      return;
    }

    let cancelled = false;

    async function loadArtists() {
      setLoading(true);
      setLoadError("");

      try {
        const params = { per_page: 200 };
        if (eventId) {
          params.event_id = Number(eventId);
        }
        if (search.trim()) {
          params.search = search.trim();
        }
        if (activeFilter !== "all") {
          params.is_active = activeFilter === "active";
        }

        const response = await api.get("/artists", { params });
        if (cancelled) {
          return;
        }

        setArtists(Array.isArray(response.data?.data) ? response.data.data : []);
        setMeta(response.data?.meta || null);
      } catch (error) {
        if (cancelled) {
          return;
        }

        setArtists([]);
        setMeta(null);
        setLoadError(error.response?.data?.message || "Erro ao carregar artistas.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void loadArtists();
    return () => {
      cancelled = true;
    };
  }, [activeFilter, eventId, moduleStatus, reloadToken, search]);

  useEffect(() => {
    if (!eventId || (moduleStatus && moduleStatus.schema_ready === false)) {
      setTimelineByBookingId({});
      return;
    }

    let cancelled = false;

    async function loadOperationalSummaries() {
      setOperationalLoading(true);
      try {
        const response = await api.get("/artists/timelines", {
          params: {
            event_id: Number(eventId),
            per_page: 500,
          },
        });

        if (cancelled) {
          return;
        }

        const rows = Array.isArray(response.data?.data) ? response.data.data : [];
        const nextMap = {};
        rows.forEach((row) => {
          if (row?.event_artist_id) {
            nextMap[row.event_artist_id] = row;
          }
        });
        setTimelineByBookingId(nextMap);
      } catch {
        if (!cancelled) {
          setTimelineByBookingId({});
        }
      } finally {
        if (!cancelled) {
          setOperationalLoading(false);
        }
      }
    }

    void loadOperationalSummaries();
    return () => {
      cancelled = true;
    };
  }, [eventId, moduleStatus, reloadToken]);

  const selectedEvent = events.find((item) => String(item.id) === String(eventId));
  const decoratedArtists = artists.map((artist) => {
    const timeline = artist.event_artist_id ? timelineByBookingId[artist.event_artist_id] || null : null;
    return {
      ...artist,
      current_severity: timeline?.current_severity || "green",
      operational_arrival:
        timeline?.landing_at ||
        timeline?.venue_arrival_at ||
        artist.soundcheck_at ||
        artist.performance_start_at ||
        null,
      timeline_status: timeline?.timeline_status || null,
    };
  });

  const visibleArtists = decoratedArtists.filter((artist) => {
    if (!eventId) {
      return true;
    }

    if (bookingStatusFilter !== "all" && artist.booking_status !== bookingStatusFilter) {
      return false;
    }

    if (severityFilter !== "all" && artist.current_severity !== severityFilter) {
      return false;
    }

    return true;
  });

  const summary = visibleArtists.reduce(
    (accumulator, artist) => {
      accumulator.active += artist.is_active ? 1 : 0;
      accumulator.bookings += Number(artist.bookings_count || 0);
      accumulator.cache += Number(artist.cache_amount || 0);
      accumulator.logistics += Number(artist.total_logistics_cost || 0);
      accumulator.total += Number(artist.total_artist_cost || 0);
      return accumulator;
    },
    {
      active: 0,
      bookings: 0,
      cache: 0,
      logistics: 0,
      total: 0,
    }
  );

  function buildDetailPath(artist, tab) {
    const nextParams = new URLSearchParams();
    if (eventId) {
      nextParams.set("event_id", eventId);
    }
    if (tab) {
      nextParams.set("tab", tab);
    }

    const query = nextParams.toString();
    return query ? `/artists/${artist.id}?${query}` : `/artists/${artist.id}`;
  }

  return (
    <div className="space-y-6">
      {showCreateModal && (
        <ArtistFormModal
          events={events}
          selectedEventId={eventId}
          onClose={() => setShowCreateModal(false)}
          onSaved={(result) => {
            setShowCreateModal(false);
            setReloadToken((current) => current + 1);
            if (result?.artist?.id) {
              const nextParams = new URLSearchParams();
              if (result.eventId) {
                nextParams.set("event_id", String(result.eventId));
                nextParams.set("tab", "bookings");
              }
              navigate(
                nextParams.toString()
                  ? `/artists/${result.artist.id}?${nextParams.toString()}`
                  : `/artists/${result.artist.id}`
              );
            }
          }}
        />
      )}

      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="space-y-2">
          <h1 className="page-title">
            <MicVocal size={22} className="text-brand" />
            Operacao de Artistas
          </h1>
          <p className="text-sm text-gray-400">
            {eventId && selectedEvent
              ? `Evento selecionado: ${selectedEvent.name}`
              : "Sem evento selecionado: exibindo o catalogo geral do organizador."}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => setReloadToken((current) => current + 1)}
            className="btn-outline"
          >
            <RefreshCw size={16} />
            Atualizar
          </button>

          {canManage && (
            <button type="button" onClick={() => setShowCreateModal(true)} className="btn-outline">
              <Plus size={16} />
              {eventId ? "Contratar artista" : "Novo artista"}
            </button>
          )}

          {canImport && (
            <Link
              to={buildScopedPath("/artists/import", eventId)}
              className="btn-primary"
            >
              <Upload size={16} />
              Importar lote
            </Link>
          )}
        </div>
      </div>

      <ModuleStatusCard moduleStatus={moduleStatus} loading={statusLoading} />

      <div className="card space-y-4 border-white/5">
        <div
          className={`grid gap-3 ${
            eventId
              ? "xl:grid-cols-[minmax(0,1.1fr)_220px_190px_190px_190px]"
              : "lg:grid-cols-[minmax(0,1.2fr)_220px_190px]"
          }`}
        >
          <label className="relative block">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" />
            <input
              className="input pl-9"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Buscar por nome artistico ou nome juridico..."
            />
          </label>

          <select
            className="select"
            value={eventId}
            onChange={(event) => setEventId(event.target.value)}
          >
            <option value="">Catalogo geral</option>
            {events.map((eventItem) => (
              <option key={eventItem.id} value={eventItem.id}>
                {eventItem.name}
              </option>
            ))}
          </select>

          <select
            className="select"
            value={activeFilter}
            onChange={(event) => setActiveFilter(event.target.value)}
          >
            <option value="all">Todos os status</option>
            <option value="active">Somente ativos</option>
            <option value="inactive">Somente inativos</option>
          </select>

          {eventId && (
            <>
              <select
                className="select"
                value={bookingStatusFilter}
                onChange={(event) => setBookingStatusFilter(event.target.value)}
              >
                <option value="all">Todas as contratacoes</option>
                {Object.entries(BOOKING_STATUS_META).map(([key, value]) => (
                  <option key={key} value={key}>
                    {value.label}
                  </option>
                ))}
              </select>

              <select
                className="select"
                value={severityFilter}
                onChange={(event) => setSeverityFilter(event.target.value)}
              >
                <option value="all">Todas as severidades</option>
                {Object.entries(ALERT_SEVERITY_META).map(([key, value]) => (
                  <option key={key} value={key}>
                    {value.label}
                  </option>
                ))}
              </select>
            </>
          )}
        </div>

        {(search ||
          eventId ||
          activeFilter !== "all" ||
          bookingStatusFilter !== "all" ||
          severityFilter !== "all") && (
          <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500">
            <span className="uppercase tracking-[0.18em]">Filtros ativos</span>
            {search && <span className="badge-gray">Busca: {search}</span>}
            {eventId && selectedEvent && <span className="badge-gray">Evento: {selectedEvent.name}</span>}
            {activeFilter !== "all" && (
              <span className="badge-gray">
                {activeFilter === "active" ? "Ativos" : "Inativos"}
              </span>
            )}
            {bookingStatusFilter !== "all" && (
              <span className="badge-gray">
                Contratacao: {resolveMeta(BOOKING_STATUS_META, bookingStatusFilter).label}
              </span>
            )}
            {severityFilter !== "all" && (
              <span className="badge-gray">
                Severidade: {resolveMeta(ALERT_SEVERITY_META, severityFilter).label}
              </span>
            )}
            <button
              type="button"
              onClick={() => {
                setSearch("");
                setEventId("");
                setActiveFilter("all");
                setBookingStatusFilter("all");
                setSeverityFilter("all");
              }}
              className="inline-flex items-center gap-1 rounded-full border border-white/10 px-3 py-1 text-gray-400 transition-colors hover:border-white/20 hover:text-white"
            >
              <XCircle size={12} />
              Limpar
            </button>
          </div>
        )}

        {eventId && (
          <p className="text-xs uppercase tracking-[0.18em] text-gray-600">
            {operationalLoading
              ? "Atualizando leitura operacional de timeline..."
              : "Chegada operacional e severidade consolidadas a partir das timelines do evento."}
          </p>
        )}
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <SummaryCard
          label="Artistas na tela"
          value={formatNumber(visibleArtists.length)}
          helper={
            eventId
              ? "Lineup filtrado por contratacao e risco operacional."
              : `Catalogo do organizador (${formatNumber(meta?.total ?? artists.length)} no backend).`
          }
        />
        <SummaryCard
          label="Ativos"
          value={formatNumber(summary.active)}
          helper={eventId ? "Disponiveis dentro do evento filtrado." : "Ativos dentro da listagem atual."}
        />
        <SummaryCard
          label={eventId ? "Cache total" : "Contratacoes totais"}
          value={eventId ? formatCurrency(summary.cache) : formatNumber(summary.bookings)}
          helper={
            eventId
              ? "Soma do valor previsto nas contratacoes exibidas."
              : "Historico agregado de contratacoes no catalogo."
          }
          tone={eventId ? "default" : "warning"}
        />
        <SummaryCard
          label={eventId ? "Custo artistico" : "Custo logistico"}
          value={eventId ? formatCurrency(summary.total) : formatCurrency(summary.logistics)}
          helper={
            eventId
              ? `Logistica na tela: ${formatCurrency(summary.logistics)}`
              : "Itens logisticos consolidados por contratacao."
          }
        />
      </div>

      {loadError && (
        <div className="card border-red-500/20 bg-red-500/5 text-sm text-red-300">
          {loadError}
        </div>
      )}

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Artista</th>
              {eventId ? (
                <>
                  <th>Palco / status</th>
                  <th>Chegada operacional</th>
                  <th>Show</th>
                  <th>Severidade</th>
                  <th className="text-right">Cache</th>
                  <th className="text-right">Total</th>
                </>
              ) : (
                <>
                  <th>Contato</th>
                  <th>Tipo</th>
                  <th className="text-right">Contratacoes</th>
                  <th>Status</th>
                </>
              )}
              <th />
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={eventId ? 8 : 6} className="py-12 text-center text-gray-500">
                  Carregando artistas...
                </td>
              </tr>
            ) : visibleArtists.length === 0 ? (
              <tr>
                <td colSpan={eventId ? 8 : 6} className="py-12 text-center text-gray-500">
                  <AlertCircle size={16} className="mr-2 inline-block text-gray-600" />
                  Nenhum artista encontrado para os filtros atuais.
                </td>
              </tr>
            ) : (
              visibleArtists.map((artist) => {
                const bookingMeta = resolveMeta(BOOKING_STATUS_META, artist.booking_status);
                const severityMeta = resolveMeta(ALERT_SEVERITY_META, artist.current_severity);
                const detailPath = eventId ? buildDetailPath(artist) : `/artists/${artist.id}`;

                return (
                  <tr
                    key={`${artist.id}-${artist.event_artist_id || "catalog"}`}
                    className="cursor-pointer hover:bg-white/5"
                    onClick={() => navigate(detailPath)}
                  >
                    <td>
                      <div className="space-y-1">
                        <p className="font-semibold text-white">{artist.stage_name}</p>
                        <p className="text-sm text-gray-500">
                          {artist.legal_name || "Sem nome juridico"}
                        </p>
                      </div>
                    </td>

                    {eventId ? (
                      <>
                        <td>
                          <div className="space-y-2">
                            <p className="text-sm text-gray-300">
                              {artist.booking_stage_name || "Palco nao definido"}
                            </p>
                            <span className={bookingMeta.className}>{bookingMeta.label}</span>
                          </div>
                        </td>
                        <td>
                          <div className="space-y-1 text-sm text-gray-400">
                            <p className="flex items-center gap-2">
                              <CalendarDays size={14} className="text-gray-600" />
                              {formatDateTime(artist.operational_arrival)}
                            </p>
                          </div>
                        </td>
                        <td>
                          <div className="space-y-1 text-sm text-gray-400">
                            <p>{formatDateTime(artist.performance_start_at)}</p>
                            <p className="text-xs text-gray-600">
                              Soundcheck: {formatDateTime(artist.soundcheck_at)}
                            </p>
                          </div>
                        </td>
                        <td>
                          <div className="space-y-2">
                            <span className={severityMeta.className}>{severityMeta.label}</span>
                            <p className="text-xs uppercase tracking-[0.18em] text-gray-600">
                              {artist.timeline_status || "sem-timeline"}
                            </p>
                          </div>
                        </td>
                        <td className="text-right tabular-nums text-white">
                          {formatCurrency(artist.cache_amount)}
                        </td>
                        <td className="text-right tabular-nums font-semibold text-emerald-300">
                          {formatCurrency(artist.total_artist_cost)}
                        </td>
                      </>
                    ) : (
                      <>
                        <td>
                          <div className="space-y-1 text-sm text-gray-400">
                            <p>{artist.default_contact_name || "Sem contato principal"}</p>
                            <p>{artist.default_contact_phone || "Sem telefone"}</p>
                          </div>
                        </td>
                        <td className="text-sm text-gray-300">
                          {artist.artist_type || "Nao definido"}
                        </td>
                        <td className="text-right tabular-nums text-white">
                          {formatNumber(artist.bookings_count)}
                        </td>
                        <td>
                          <span className={artist.is_active ? "badge-green" : "badge-gray"}>
                            {artist.is_active ? "Ativo" : "Inativo"}
                          </span>
                        </td>
                      </>
                    )}

                    <td className="text-right">
                      {eventId ? (
                        <div className="flex flex-wrap justify-end gap-2">
                          <button
                            type="button"
                            onClick={(event) => {
                              event.stopPropagation();
                              navigate(buildDetailPath(artist, "bookings"));
                            }}
                            className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                          >
                            Contratacao
                          </button>
                          <button
                            type="button"
                            onClick={(event) => {
                              event.stopPropagation();
                              navigate(buildDetailPath(artist, "timeline"));
                            }}
                            className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                          >
                            Timeline
                          </button>
                          <button
                            type="button"
                            onClick={(event) => {
                              event.stopPropagation();
                              navigate(buildDetailPath(artist, "alerts"));
                            }}
                            className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                          >
                            Alertas
                          </button>
                          <ChevronRight size={16} className="ml-1 mt-1 text-gray-600" />
                        </div>
                      ) : (
                        <ChevronRight size={16} className="ml-auto text-gray-600" />
                      )}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
