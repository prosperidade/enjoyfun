import { useEffect, useState } from "react";
import {
  AlertCircle,
  ArrowLeft,
  CalendarDays,
  Download,
  FileText,
  MicVocal,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
  Upload,
  Users,
  X,
} from "lucide-react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import api from "../lib/api";
import toast from "react-hot-toast";
import { useAuth } from "../context/AuthContext";
import EmbeddedAIChat from "../components/EmbeddedAIChat";
import { useEventScope } from "../context/EventScopeContext";
import {
  ALERT_SEVERITY_META,
  ALERT_STATUS_META,
  BOOKING_STATUS_META,
  TIMELINE_STATUS_META,
  formatCurrency,
  formatDateTime,
  formatDateTimeRelativeLabel,
  formatFileSize,
  formatMinutes,
  formatNumber,
  resolveMeta,
} from "../modules/artists/artistUi";

const TABS = [
  { id: "bookings", label: "Contratacoes" },
  { id: "logistics", label: "Logistica" },
  { id: "timeline", label: "Timeline" },
  { id: "alerts", label: "Alertas" },
  { id: "team", label: "Equipe" },
  { id: "files", label: "Arquivos" },
];

const ARRIVAL_MODE_OPTIONS = [
  { value: "", label: "Selecionar..." },
  { value: "flight", label: "Voo" },
  { value: "road", label: "Rodoviario" },
  { value: "van", label: "Van" },
  { value: "car", label: "Carro" },
  { value: "hotel", label: "Hospedagem" },
  { value: "other", label: "Outro" },
];

const LOGISTICS_ITEM_TYPE_OPTIONS = [
  { value: "flight", label: "Passagem" },
  { value: "transfer", label: "Transfer" },
  { value: "hotel", label: "Hospedagem" },
  { value: "team", label: "Equipe" },
  { value: "hospitality", label: "Hospitalidade" },
  { value: "rider", label: "Rider" },
  { value: "other", label: "Outro" },
];

function createEmptyCostItemRow(overrides = {}) {
  return {
    id: null,
    item_type: "flight",
    description: "",
    quantity: "1",
    unit_amount: "",
    total_amount: "",
    currency_code: "BRL",
    supplier_name: "",
    notes: "",
    status: "pending",
    ...overrides,
  };
}

function createEmptyTeamMemberRow(overrides = {}) {
  return {
    id: null,
    full_name: "",
    role_name: "",
    document_number: "",
    phone: "",
    notes: "",
    needs_hotel: false,
    needs_transfer: false,
    is_active: true,
    ...overrides,
  };
}

function EmptyState({ icon, title, description, action }) {
  const IconComponent = icon;

  return (
    <div className="card border-dashed border-white/10 py-12 text-center">
      <IconComponent size={36} className="mx-auto text-gray-700" />
      <h3 className="mt-4 text-lg font-semibold text-white">{title}</h3>
      <p className="mx-auto mt-2 max-w-2xl text-sm text-gray-500">{description}</p>
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

function StatCard({ label, value, helper, tone = "default" }) {
  const toneClass =
    tone === "danger"
      ? "border-red-500/20 bg-red-500/5"
      : tone === "warning"
        ? "border-yellow-500/20 bg-yellow-500/5"
        : "border-white/5";

  return (
    <div className={`card ${toneClass}`}>
      <p className="text-xs uppercase tracking-[0.18em] text-gray-500">{label}</p>
      <p className="mt-3 text-2xl font-semibold text-white">{value}</p>
      <p className="mt-2 text-sm text-gray-500">{helper}</p>
    </div>
  );
}

function DetailRow({ label, value }) {
  return (
    <div className="space-y-1 rounded-xl border border-white/5 bg-black/10 p-3">
      <p className="text-[11px] uppercase tracking-[0.18em] text-gray-600">{label}</p>
      <p className="text-sm text-gray-300">{value || "—"}</p>
    </div>
  );
}

function emptyToNull(value) {
  const normalized = String(value ?? "").trim();
  return normalized === "" ? null : normalized;
}

function toDateInputValue(value) {
  return value ? String(value).slice(0, 10) : "";
}

function toDateTimeInputValue(value) {
  if (!value) {
    return "";
  }

  return String(value).replace(" ", "T").slice(0, 16);
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
  const normalized = String(value ?? "").trim();
  if (normalized === "") {
    return null;
  }

  return Number.parseFloat(normalized);
}

function downloadBase64File(base64Content, filename, mimeType) {
  const binary = window.atob(base64Content);
  const bytes = new Uint8Array(binary.length);

  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }

  const blob = new Blob([bytes], { type: mimeType || "application/octet-stream" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

function ActionModal({ title, description, onClose, children, wide = false }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
      <div
        className={`card max-h-[90vh] w-full overflow-y-auto border-white/10 ${
          wide ? "max-w-6xl" : "max-w-3xl"
        }`}
      >
        <div className="mb-5 flex items-center justify-between gap-3">
          <div>
            <h2 className="section-title">{title}</h2>
            {description && <p className="mt-1 text-sm text-gray-500">{description}</p>}
          </div>
          <button type="button" onClick={onClose} className="text-gray-500 transition-colors hover:text-white">
            <X size={20} />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

export default function ArtistDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { hasRole } = useAuth();
  const { buildScopedPath, eventId: scopedEventId, setEventId: setScopedEventId } = useEventScope();
  const canImport = hasRole("admin") || hasRole("organizer") || hasRole("manager");
  const canManage = canImport;

  const requestedTab = searchParams.get("tab") || "bookings";
  const activeTab =
    requestedTab === "operations"
      ? "logistics"
      : requestedTab === "overview"
        ? "bookings"
        : requestedTab;
  const selectedEventId = searchParams.get("event_id") || scopedEventId || "";

  const [events, setEvents] = useState([]);
  const [artist, setArtist] = useState(null);
  const [logistics, setLogistics] = useState(null);
  const [logisticsItems, setLogisticsItems] = useState([]);
  const [timeline, setTimeline] = useState(null);
  const [teamMembers, setTeamMembers] = useState([]);
  const [files, setFiles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [relatedLoading, setRelatedLoading] = useState(false);
  const [error, setError] = useState("");
  const [relatedError, setRelatedError] = useState("");
  const [refreshToken, setRefreshToken] = useState(0);
  const [editor, setEditor] = useState(null);
  const [savingEditor, setSavingEditor] = useState(false);
  const [exportingFormat, setExportingFormat] = useState(null);

  const bookings = Array.isArray(artist?.bookings) ? artist.bookings : [];
  const currentBooking = bookings.find(
    (booking) => String(booking.event_id) === String(selectedEventId)
  ) || null;

  useEffect(() => {
    let cancelled = false;

    async function loadEvents() {
      try {
        const response = await api.get("/events");
        if (!cancelled) {
          setEvents(Array.isArray(response.data?.data) ? response.data.data : []);
        }
      } catch {
        if (!cancelled) {
          setEvents([]);
        }
      }
    }

    void loadEvents();
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function loadArtist() {
      setLoading(true);
      setError("");

      try {
        const response = await api.get(`/artists/${id}`);
        if (cancelled) {
          return;
        }

        setArtist(response.data?.data || null);
      } catch (loadError) {
        if (cancelled) {
          return;
        }

        setArtist(null);
        setError(loadError.response?.data?.message || "Erro ao carregar artista.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void loadArtist();
    return () => {
      cancelled = true;
    };
  }, [id, refreshToken]);

  useEffect(() => {
    if (loading || !artist || selectedEventId || (artist.bookings?.length ?? 0) === 0) {
      return;
    }

    const nextParams = new URLSearchParams(searchParams);
    const nextEventId = String(artist.bookings[0].event_id);
    nextParams.set("event_id", nextEventId);
    setScopedEventId(nextEventId, { updateUrl: false });
    setSearchParams(nextParams, { replace: true });
  }, [artist, loading, searchParams, selectedEventId, setScopedEventId, setSearchParams]);

  useEffect(() => {
    if (!selectedEventId || !currentBooking) {
      setLogistics(null);
      setLogisticsItems([]);
      setTimeline(null);
      setTeamMembers([]);
      setFiles([]);
      setRelatedError("");
      return;
    }

    let cancelled = false;

    async function loadRelated() {
      setRelatedLoading(true);
      setRelatedError("");

      try {
        const params = {
          event_id: Number(selectedEventId),
          event_artist_id: Number(currentBooking.id),
        };

        const [logisticsRes, logisticsItemsRes, timelinesRes, teamRes, filesRes] = await Promise.all([
          api.get("/artists/logistics", { params: { ...params, per_page: 20 } }),
          api.get("/artists/logistics-items", { params: { ...params, per_page: 200 } }),
          api.get("/artists/timelines", { params: { ...params, per_page: 20 } }),
          api.get("/artists/team", { params: { ...params, per_page: 100 } }),
          api.get("/artists/files", { params: { ...params, per_page: 100 } }),
        ]);

        const nextLogistics = Array.isArray(logisticsRes.data?.data)
          ? logisticsRes.data.data[0] || null
          : null;
        const nextTimelineSummary = Array.isArray(timelinesRes.data?.data)
          ? timelinesRes.data.data[0] || null
          : null;
        const nextLogisticsItems = Array.isArray(logisticsItemsRes.data?.data)
          ? logisticsItemsRes.data.data
          : [];
        const nextTeamMembers = Array.isArray(teamRes.data?.data) ? teamRes.data.data : [];
        const nextFiles = Array.isArray(filesRes.data?.data) ? filesRes.data.data : [];

        let nextTimeline = nextTimelineSummary;
        if (nextTimelineSummary?.id) {
          const timelineRes = await api.get(`/artists/timelines/${nextTimelineSummary.id}`);
          nextTimeline = timelineRes.data?.data || nextTimelineSummary;
        }

        if (cancelled) {
          return;
        }

        setLogistics(nextLogistics);
        setLogisticsItems(nextLogisticsItems);
        setTimeline(nextTimeline);
        setTeamMembers(nextTeamMembers);
        setFiles(nextFiles);
      } catch (loadError) {
        if (cancelled) {
          return;
        }

        setLogistics(null);
        setLogisticsItems([]);
        setTimeline(null);
        setTeamMembers([]);
        setFiles([]);
        setRelatedError(loadError.response?.data?.message || "Erro ao carregar contexto operacional.");
      } finally {
        if (!cancelled) {
          setRelatedLoading(false);
        }
      }
    }

    void loadRelated();
    return () => {
      cancelled = true;
    };
  }, [currentBooking, refreshToken, selectedEventId]);

  function updateRouteState(nextValues) {
    const nextParams = new URLSearchParams(searchParams);

    if (Object.prototype.hasOwnProperty.call(nextValues, "tab")) {
      if (nextValues.tab) {
        nextParams.set("tab", nextValues.tab);
      } else {
        nextParams.delete("tab");
      }
    }

    if (Object.prototype.hasOwnProperty.call(nextValues, "event_id")) {
      if (nextValues.event_id) {
        const nextEventId = String(nextValues.event_id);
        nextParams.set("event_id", nextEventId);
        setScopedEventId(nextEventId, { updateUrl: false });
      } else {
        nextParams.delete("event_id");
        setScopedEventId("", { updateUrl: false });
      }
    }

    setSearchParams(nextParams);
  }

  function setEditorField(field, value) {
    setEditor((current) =>
      current
        ? {
            ...current,
            form: {
              ...current.form,
              [field]: value,
            },
          }
        : current
    );
  }

  function setEditorArrayField(collection, index, field, value) {
    setEditor((current) => {
      if (!current) {
        return current;
      }

      const nextItems = Array.isArray(current.form?.[collection]) ? [...current.form[collection]] : [];
      const currentItem = nextItems[index];
      if (!currentItem) {
        return current;
      }

      nextItems[index] = {
        ...currentItem,
        [field]: value,
      };

      return {
        ...current,
        form: {
          ...current.form,
          [collection]: nextItems,
        },
      };
    });
  }

  function appendEditorArrayRow(collection, row) {
    setEditor((current) => {
      if (!current) {
        return current;
      }

      const nextItems = Array.isArray(current.form?.[collection]) ? [...current.form[collection], row] : [row];
      return {
        ...current,
        form: {
          ...current.form,
          [collection]: nextItems,
        },
      };
    });
  }

  function removeEditorArrayRow(collection, index) {
    setEditor((current) => {
      if (!current) {
        return current;
      }

      const nextItems = Array.isArray(current.form?.[collection]) ? [...current.form[collection]] : [];
      const removed = nextItems[index];
      if (!removed) {
        return current;
      }

      nextItems.splice(index, 1);
      const deletionKey = collection === "items" ? "deleted_item_ids" : "deleted_member_ids";
      const fallbackRow =
        collection === "items" ? createEmptyCostItemRow() : createEmptyTeamMemberRow();

      return {
        ...current,
        form: {
          ...current.form,
          [collection]: nextItems.length > 0 ? nextItems : [fallbackRow],
          [deletionKey]:
            removed.id != null
              ? [...(current.form?.[deletionKey] || []), removed.id]
              : current.form?.[deletionKey] || [],
        },
      };
    });
  }

  function openArtistEditor() {
    setEditor({
      type: "artist",
      title: "Editar artista",
      description: "Atualize o cadastro mestre deste artista.",
      submitLabel: "Salvar artista",
      form: {
        stage_name: artist?.stage_name || "",
        legal_name: artist?.legal_name || "",
        document_number: artist?.document_number || "",
        artist_type: artist?.artist_type || "",
        default_contact_name: artist?.default_contact_name || "",
        default_contact_phone: artist?.default_contact_phone || "",
        notes: artist?.notes || "",
        is_active: Boolean(artist?.is_active),
      },
    });
  }

  function openBookingEditor(booking = null) {
    const nextBooking = booking || null;
    setEditor({
      type: "booking",
      title: nextBooking ? "Editar contratacao" : "Nova contratacao",
      description: nextBooking
        ? "Ajuste palco, horarios e valor da contratacao."
        : "Crie uma nova contratacao deste artista em um evento.",
      submitLabel: nextBooking ? "Salvar contratacao" : "Criar contratacao",
      bookingId: nextBooking?.id || null,
      form: {
        event_id: nextBooking ? String(nextBooking.event_id) : selectedEventId || "",
        booking_status: nextBooking?.booking_status || "pending",
        performance_date: toDateInputValue(nextBooking?.performance_date),
        performance_start_at: toDateTimeInputValue(nextBooking?.performance_start_at),
        soundcheck_at: toDateTimeInputValue(nextBooking?.soundcheck_at),
        performance_duration_minutes:
          nextBooking?.performance_duration_minutes != null
            ? String(nextBooking.performance_duration_minutes)
            : "",
        stage_name: nextBooking?.stage_name || "",
        cache_amount: nextBooking?.cache_amount != null ? String(nextBooking.cache_amount) : "",
        notes: nextBooking?.notes || "",
      },
    });
  }

  function openOperationEditor() {
    if (!currentBooking) {
      toast.error("Selecione uma contratacao antes de configurar a operacao.");
      return;
    }

    setEditor({
      type: "operation",
      title: "Configurar operacao do artista",
      description:
        "Centralize contratacao, chegada, hospedagem, custos logísticos e equipe em um unico fluxo.",
      submitLabel: "Salvar operacao",
      form: {
        booking_status: currentBooking.booking_status || "pending",
        performance_date: toDateInputValue(currentBooking.performance_date),
        performance_start_at: toDateTimeInputValue(currentBooking.performance_start_at),
        soundcheck_at: toDateTimeInputValue(currentBooking.soundcheck_at),
        performance_duration_minutes:
          currentBooking.performance_duration_minutes != null
            ? String(currentBooking.performance_duration_minutes)
            : "",
        stage_name: currentBooking.stage_name || "",
        cache_amount: currentBooking.cache_amount != null ? String(currentBooking.cache_amount) : "",
        booking_notes: currentBooking.notes || "",
        arrival_origin: logistics?.arrival_origin || "",
        arrival_mode: logistics?.arrival_mode || "",
        arrival_reference: logistics?.arrival_reference || "",
        arrival_at: toDateTimeInputValue(logistics?.arrival_at || timeline?.landing_at),
        hotel_name: logistics?.hotel_name || "",
        hotel_address: logistics?.hotel_address || "",
        hotel_check_in_at: toDateTimeInputValue(logistics?.hotel_check_in_at),
        hotel_check_out_at: toDateTimeInputValue(logistics?.hotel_check_out_at),
        venue_arrival_at: toDateTimeInputValue(logistics?.venue_arrival_at || timeline?.venue_arrival_at),
        departure_destination: logistics?.departure_destination || "",
        departure_mode: logistics?.departure_mode || "",
        departure_reference: logistics?.departure_reference || "",
        departure_at: toDateTimeInputValue(logistics?.departure_at),
        hospitality_notes: logistics?.hospitality_notes || "",
        transport_notes: logistics?.transport_notes || "",
        items:
          logisticsItems.length > 0
            ? logisticsItems.map((item) => ({
                id: item.id,
                item_type: item.item_type || "other",
                description: item.description || "",
                quantity: item.quantity != null ? String(item.quantity) : "1",
                unit_amount: item.unit_amount != null ? String(item.unit_amount) : "",
                total_amount: item.total_amount != null ? String(item.total_amount) : "",
                currency_code: item.currency_code || "BRL",
                supplier_name: item.supplier_name || "",
                notes: item.notes || "",
                status: item.status || "pending",
              }))
            : [
                createEmptyCostItemRow({ item_type: "flight", description: "Passagens do artista" }),
                createEmptyCostItemRow({ item_type: "transfer", description: "Transfer in/out" }),
                createEmptyCostItemRow({ item_type: "team", description: "Custos da equipe" }),
              ],
        deleted_item_ids: [],
        team_members:
          teamMembers.length > 0
            ? teamMembers.map((member) => ({
                id: member.id,
                full_name: member.full_name || "",
                role_name: member.role_name || "",
                document_number: member.document_number || "",
                phone: member.phone || "",
                notes: member.notes || "",
                needs_hotel: Boolean(member.needs_hotel),
                needs_transfer: Boolean(member.needs_transfer),
                is_active: Boolean(member.is_active),
              }))
            : [createEmptyTeamMemberRow()],
        deleted_member_ids: [],
      },
    });
  }

  function openTimelineEditor() {
    if (!currentBooking) {
      toast.error("Selecione uma contratacao antes de editar a timeline.");
      return;
    }

    setEditor({
      type: "timeline",
      title: timeline ? "Editar timeline operacional" : "Criar timeline operacional",
      description:
        "Ajuste os horarios-base da operacao. O backend recalcula janelas e alertas a partir desses marcos.",
      submitLabel: timeline ? "Salvar timeline" : "Criar timeline",
      form: {
        landing_at: toDateTimeInputValue(timeline?.landing_at || logistics?.arrival_at),
        airport_out_at: toDateTimeInputValue(timeline?.airport_out_at),
        hotel_arrival_at: toDateTimeInputValue(timeline?.hotel_arrival_at || logistics?.hotel_check_in_at),
        venue_arrival_at: toDateTimeInputValue(timeline?.venue_arrival_at || logistics?.venue_arrival_at),
        soundcheck_at: toDateTimeInputValue(timeline?.soundcheck_at || currentBooking?.soundcheck_at),
        show_start_at: toDateTimeInputValue(timeline?.show_start_at || currentBooking?.performance_start_at),
        show_end_at: toDateTimeInputValue(timeline?.show_end_at),
        venue_exit_at: toDateTimeInputValue(timeline?.venue_exit_at),
        next_departure_deadline_at: toDateTimeInputValue(timeline?.next_departure_deadline_at || logistics?.departure_at),
      },
    });
  }

  async function handleRecalculateTimeline() {
    if (!currentBooking?.id || !selectedEventId) {
      toast.error("Selecione uma contratacao antes de recalcular a timeline.");
      return;
    }

    try {
      if (timeline?.id) {
        await api.post(`/artists/timelines/${timeline.id}/recalculate`);
      } else {
        await api.post("/artists/timelines", {
          event_id: Number(selectedEventId),
          event_artist_id: Number(currentBooking.id),
          landing_at: logistics?.arrival_at || null,
          hotel_arrival_at: logistics?.hotel_check_in_at || null,
          venue_arrival_at: logistics?.venue_arrival_at || null,
          soundcheck_at: currentBooking.soundcheck_at || null,
          show_start_at: currentBooking.performance_start_at || null,
          next_departure_deadline_at: logistics?.departure_at || null,
        });
      }

      toast.success("Timeline recalculada com sucesso.");
      setRefreshToken((current) => current + 1);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao recalcular timeline.");
    }
  }

  async function handleRecalculateAlerts() {
    if (!currentBooking?.id || !selectedEventId) {
      toast.error("Selecione uma contratacao antes de recalcular alertas.");
      return;
    }

    try {
      await api.post("/artists/alerts/recalculate", {
        event_id: Number(selectedEventId),
        event_artist_id: Number(currentBooking.id),
        timeline_id: timeline?.id || null,
      });
      toast.success("Alertas recalculados com sucesso.");
      setRefreshToken((current) => current + 1);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao recalcular alertas.");
    }
  }

  async function handleAlertAction(alert, action) {
    if (!alert?.id) {
      return;
    }

    const notePrompt =
      action === "acknowledge"
        ? "Observacao do reconhecimento (opcional):"
        : action === "resolve"
          ? "Resolucao aplicada (opcional):"
          : "Motivo do descarte (opcional):";
    const notes = window.prompt(notePrompt, alert.resolution_notes || "");
    if (notes === null) {
      return;
    }

    try {
      if (action === "acknowledge") {
        await api.post(`/artists/alerts/${alert.id}/acknowledge`, {
          resolution_notes: emptyToNull(notes),
        });
      } else if (action === "resolve") {
        await api.post(`/artists/alerts/${alert.id}/resolve`, {
          resolution_notes: emptyToNull(notes),
        });
      } else {
        await api.patch(`/artists/alerts/${alert.id}`, {
          status: "dismissed",
          resolution_notes: emptyToNull(notes),
        });
      }

      toast.success("Alerta atualizado com sucesso.");
      setRefreshToken((current) => current + 1);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao atualizar alerta.");
    }
  }

  async function handleExportOperation(format) {
    if (!artist?.id || !currentBooking?.id || !selectedEventId) {
      toast.error("Selecione uma contratacao antes de exportar a operacao.");
      return;
    }

    setExportingFormat(format);
    try {
      const response = await api.post("/artists/exports/operation", {
        artist_id: Number(artist.id),
        event_id: Number(selectedEventId),
        event_artist_id: Number(currentBooking.id),
        format,
      });

      const payload = response.data?.data || {};
      if (!payload.file_base64 || !payload.filename) {
        throw new Error("Arquivo de exportacao invalido.");
      }

      downloadBase64File(payload.file_base64, payload.filename, payload.mime_type);
      toast.success(`Operacao exportada em ${String(format).toUpperCase()}.`);
    } catch (error) {
      toast.error(error.response?.data?.message || error.message || "Erro ao exportar operacao.");
    } finally {
      setExportingFormat(null);
    }
  }

  function openTeamEditor(member = null) {
    setEditor({
      type: "team",
      title: member ? "Editar membro da equipe" : "Novo membro da equipe",
      description: "Equipe vinculada a contratacao operacional atual.",
      submitLabel: member ? "Salvar membro" : "Adicionar membro",
      memberId: member?.id || null,
      form: {
        full_name: member?.full_name || "",
        role_name: member?.role_name || "",
        document_number: member?.document_number || "",
        phone: member?.phone || "",
        notes: member?.notes || "",
        needs_hotel: Boolean(member?.needs_hotel),
        needs_transfer: Boolean(member?.needs_transfer),
        is_active: member ? Boolean(member.is_active) : true,
      },
    });
  }

  function openFileEditor() {
    setEditor({
      type: "file",
      title: "Registrar arquivo",
      description: "Cadastro de metadados operacionais do arquivo da contratacao.",
      submitLabel: "Registrar arquivo",
      form: {
        file_type: "",
        original_name: "",
        storage_path: "",
        mime_type: "",
        file_size_bytes: "",
        notes: "",
      },
    });
  }

  async function handleSaveEditor(event) {
    event.preventDefault();

    if (!editor) {
      return;
    }

    setSavingEditor(true);
    try {
      if (editor.type === "artist") {
        if (!emptyToNull(editor.form.stage_name)) {
          toast.error("Informe o nome artistico.");
          return;
        }

        await api.patch(`/artists/${artist.id}`, {
          stage_name: emptyToNull(editor.form.stage_name),
          legal_name: emptyToNull(editor.form.legal_name),
          document_number: emptyToNull(editor.form.document_number),
          artist_type: emptyToNull(editor.form.artist_type),
          default_contact_name: emptyToNull(editor.form.default_contact_name),
          default_contact_phone: emptyToNull(editor.form.default_contact_phone),
          notes: emptyToNull(editor.form.notes),
          is_active: Boolean(editor.form.is_active),
        });
        toast.success("Artista atualizado com sucesso.");
      }

      if (editor.type === "booking") {
        if (!editor.form.event_id) {
          toast.error("Selecione um evento.");
          return;
        }

        const payload = {
          event_id: Number(editor.form.event_id),
          artist_id: Number(artist.id),
          booking_status: editor.form.booking_status,
          performance_date: emptyToNull(editor.form.performance_date),
          performance_start_at: toSqlDateTime(editor.form.performance_start_at),
          soundcheck_at: toSqlDateTime(editor.form.soundcheck_at),
          performance_duration_minutes: toIntegerOrNull(editor.form.performance_duration_minutes),
          stage_name: emptyToNull(editor.form.stage_name),
          cache_amount: toDecimalOrNull(editor.form.cache_amount),
          notes: emptyToNull(editor.form.notes),
        };

        if (editor.bookingId) {
          await api.patch(`/artists/bookings/${editor.bookingId}`, payload);
          toast.success("Contratacao atualizada com sucesso.");
        } else {
          await api.post("/artists/bookings", payload);
          toast.success("Contratacao criada com sucesso.");
        }

        updateRouteState({
          event_id: editor.form.event_id,
          tab: "bookings",
        });
      }

      if (editor.type === "operation") {
        if (!currentBooking?.id || !selectedEventId) {
          toast.error("Selecione uma contratacao antes de configurar a operacao.");
          return;
        }

        const bookingPayload = {
          event_id: Number(selectedEventId),
          artist_id: Number(artist.id),
          booking_status: editor.form.booking_status,
          performance_date: emptyToNull(editor.form.performance_date),
          performance_start_at: toSqlDateTime(editor.form.performance_start_at),
          soundcheck_at: toSqlDateTime(editor.form.soundcheck_at),
          performance_duration_minutes: toIntegerOrNull(editor.form.performance_duration_minutes),
          stage_name: emptyToNull(editor.form.stage_name),
          cache_amount: toDecimalOrNull(editor.form.cache_amount),
          notes: emptyToNull(editor.form.booking_notes),
        };

        await api.patch(`/artists/bookings/${currentBooking.id}`, bookingPayload);

        const logisticsPayload = {
          event_id: Number(selectedEventId),
          event_artist_id: Number(currentBooking.id),
          arrival_origin: emptyToNull(editor.form.arrival_origin),
          arrival_mode: emptyToNull(editor.form.arrival_mode),
          arrival_reference: emptyToNull(editor.form.arrival_reference),
          arrival_at: toSqlDateTime(editor.form.arrival_at),
          hotel_name: emptyToNull(editor.form.hotel_name),
          hotel_address: emptyToNull(editor.form.hotel_address),
          hotel_check_in_at: toSqlDateTime(editor.form.hotel_check_in_at),
          hotel_check_out_at: toSqlDateTime(editor.form.hotel_check_out_at),
          venue_arrival_at: toSqlDateTime(editor.form.venue_arrival_at),
          departure_destination: emptyToNull(editor.form.departure_destination),
          departure_mode: emptyToNull(editor.form.departure_mode),
          departure_reference: emptyToNull(editor.form.departure_reference),
          departure_at: toSqlDateTime(editor.form.departure_at),
          hospitality_notes: emptyToNull(editor.form.hospitality_notes),
          transport_notes: emptyToNull(editor.form.transport_notes),
        };

        const logisticsResponse = logistics?.id
          ? await api.patch(`/artists/logistics/${logistics.id}`, logisticsPayload)
          : await api.post("/artists/logistics", logisticsPayload);
        const savedLogisticsId = logisticsResponse.data?.data?.id || logistics?.id || null;

        for (const itemId of editor.form.deleted_item_ids || []) {
          await api.delete(`/artists/logistics-items/${itemId}`);
        }

        for (const item of editor.form.items || []) {
          const itemType = emptyToNull(item.item_type);
          const description = emptyToNull(item.description);
          const quantity = toDecimalOrNull(item.quantity);

          if (!itemType && !description && item.id == null) {
            continue;
          }
          if (!itemType || !description) {
            toast.error("Cada custo logistico precisa de tipo e descricao.");
            return;
          }
          if (quantity == null || quantity <= 0) {
            toast.error("Cada custo logistico precisa de quantidade maior que zero.");
            return;
          }

          const itemPayload = {
            event_id: Number(selectedEventId),
            event_artist_id: Number(currentBooking.id),
            artist_logistics_id: savedLogisticsId,
            item_type: itemType,
            description,
            quantity,
            unit_amount: toDecimalOrNull(item.unit_amount),
            total_amount: toDecimalOrNull(item.total_amount),
            currency_code: emptyToNull(item.currency_code),
            supplier_name: emptyToNull(item.supplier_name),
            notes: emptyToNull(item.notes),
            status: emptyToNull(item.status) || "pending",
          };

          if (item.id) {
            await api.patch(`/artists/logistics-items/${item.id}`, itemPayload);
          } else {
            await api.post("/artists/logistics-items", itemPayload);
          }
        }

        for (const memberId of editor.form.deleted_member_ids || []) {
          await api.delete(`/artists/team/${memberId}`);
        }

        for (const member of editor.form.team_members || []) {
          const fullName = emptyToNull(member.full_name);
          if (!fullName && member.id == null) {
            continue;
          }
          if (!fullName) {
            toast.error("Cada membro da equipe precisa de nome.");
            return;
          }

          const memberPayload = {
            event_id: Number(selectedEventId),
            event_artist_id: Number(currentBooking.id),
            full_name: fullName,
            role_name: emptyToNull(member.role_name),
            document_number: emptyToNull(member.document_number),
            phone: emptyToNull(member.phone),
            notes: emptyToNull(member.notes),
            needs_hotel: Boolean(member.needs_hotel),
            needs_transfer: Boolean(member.needs_transfer),
            is_active: Boolean(member.is_active),
          };

          if (member.id) {
            await api.patch(`/artists/team/${member.id}`, memberPayload);
          } else {
            await api.post("/artists/team", memberPayload);
          }
        }

        toast.success("Operacao atualizada com sucesso.");
        updateRouteState({
          event_id: selectedEventId,
          tab: "logistics",
        });
      }

      if (editor.type === "timeline") {
        if (!currentBooking?.id || !selectedEventId) {
          toast.error("Selecione uma contratacao antes de editar a timeline.");
          return;
        }

        const payload = {
          event_id: Number(selectedEventId),
          event_artist_id: Number(currentBooking.id),
          landing_at: toSqlDateTime(editor.form.landing_at),
          airport_out_at: toSqlDateTime(editor.form.airport_out_at),
          hotel_arrival_at: toSqlDateTime(editor.form.hotel_arrival_at),
          venue_arrival_at: toSqlDateTime(editor.form.venue_arrival_at),
          soundcheck_at: toSqlDateTime(editor.form.soundcheck_at),
          show_start_at: toSqlDateTime(editor.form.show_start_at),
          show_end_at: toSqlDateTime(editor.form.show_end_at),
          venue_exit_at: toSqlDateTime(editor.form.venue_exit_at),
          next_departure_deadline_at: toSqlDateTime(editor.form.next_departure_deadline_at),
        };

        if (timeline?.id) {
          await api.patch(`/artists/timelines/${timeline.id}`, payload);
          toast.success("Timeline atualizada com sucesso.");
        } else {
          await api.post("/artists/timelines", payload);
          toast.success("Timeline criada com sucesso.");
        }

        updateRouteState({
          event_id: selectedEventId,
          tab: "timeline",
        });
      }

      if (editor.type === "team") {
        if (!currentBooking?.id) {
          toast.error("Selecione uma contratacao antes de editar a equipe.");
          return;
        }
        if (!emptyToNull(editor.form.full_name)) {
          toast.error("Informe o nome do membro.");
          return;
        }

        const payload = {
          event_id: Number(selectedEventId),
          event_artist_id: Number(currentBooking.id),
          full_name: emptyToNull(editor.form.full_name),
          role_name: emptyToNull(editor.form.role_name),
          document_number: emptyToNull(editor.form.document_number),
          phone: emptyToNull(editor.form.phone),
          notes: emptyToNull(editor.form.notes),
          needs_hotel: Boolean(editor.form.needs_hotel),
          needs_transfer: Boolean(editor.form.needs_transfer),
          is_active: Boolean(editor.form.is_active),
        };

        if (editor.memberId) {
          await api.patch(`/artists/team/${editor.memberId}`, payload);
          toast.success("Membro atualizado com sucesso.");
        } else {
          await api.post("/artists/team", payload);
          toast.success("Membro adicionado com sucesso.");
        }
      }

      if (editor.type === "file") {
        if (!currentBooking?.id) {
          toast.error("Selecione uma contratacao antes de registrar arquivos.");
          return;
        }
        if (
          !emptyToNull(editor.form.file_type) ||
          !emptyToNull(editor.form.original_name) ||
          !emptyToNull(editor.form.storage_path)
        ) {
          toast.error("Preencha tipo, nome original e storage_path.");
          return;
        }

        await api.post("/artists/files", {
          event_id: Number(selectedEventId),
          event_artist_id: Number(currentBooking.id),
          file_type: emptyToNull(editor.form.file_type),
          original_name: emptyToNull(editor.form.original_name),
          storage_path: emptyToNull(editor.form.storage_path),
          mime_type: emptyToNull(editor.form.mime_type),
          file_size_bytes: toIntegerOrNull(editor.form.file_size_bytes),
          notes: emptyToNull(editor.form.notes),
        });
        toast.success("Arquivo registrado com sucesso.");
      }

      setEditor(null);
      setRefreshToken((current) => current + 1);
    } catch (saveError) {
      toast.error(saveError.response?.data?.message || "Erro ao salvar alteracao.");
    } finally {
      setSavingEditor(false);
    }
  }

  async function handleCancelBooking(booking) {
    const reason = window.prompt("Motivo do cancelamento (opcional):", "");
    if (reason === null) {
      return;
    }

    try {
      await api.post(`/artists/bookings/${booking.id}/cancel`, {
        cancellation_reason: emptyToNull(reason),
      });
      toast.success("Contratacao cancelada com sucesso.");
      setRefreshToken((current) => current + 1);
    } catch (cancelError) {
      toast.error(cancelError.response?.data?.message || "Erro ao cancelar contratacao.");
    }
  }

  async function handleDeleteTeamMember(member) {
    if (!window.confirm(`Remover ${member.full_name} desta contratacao?`)) {
      return;
    }

    try {
      await api.delete(`/artists/team/${member.id}`);
      toast.success("Membro removido com sucesso.");
      setRefreshToken((current) => current + 1);
    } catch (deleteError) {
      toast.error(deleteError.response?.data?.message || "Erro ao remover membro.");
    }
  }

  async function handleDeleteFile(file) {
    if (!window.confirm(`Remover o arquivo "${file.original_name}"?`)) {
      return;
    }

    try {
      await api.delete(`/artists/files/${file.id}`);
      toast.success("Arquivo removido com sucesso.");
      setRefreshToken((current) => current + 1);
    } catch (deleteError) {
      toast.error(deleteError.response?.data?.message || "Erro ao remover arquivo.");
    }
  }

  if (loading) {
    return <div className="py-16 text-center text-gray-500">Carregando artista...</div>;
  }

  if (error) {
    return (
      <EmptyState
        icon={AlertCircle}
        title="Falha ao carregar artista"
        description={error}
        action={
          <button
            type="button"
            onClick={() => setRefreshToken((current) => current + 1)}
            className="btn-primary"
          >
            <RefreshCw size={16} />
            Tentar novamente
          </button>
        }
      />
    );
  }

  if (!artist) {
    return (
      <EmptyState
        icon={MicVocal}
        title="Artista nao encontrado"
        description="O cadastro solicitado nao esta disponivel no escopo atual."
      />
    );
  }

  const bookingMeta = resolveMeta(BOOKING_STATUS_META, currentBooking?.booking_status);
  const timelineMeta = resolveMeta(TIMELINE_STATUS_META, timeline?.timeline_status);
  const severityMeta = resolveMeta(ALERT_SEVERITY_META, timeline?.current_severity);
  const derivedTimeline = timeline?.derived_timeline || timeline || {};
  const timelineEvents = [
    { label: "Landing", value: derivedTimeline.landing_at },
    { label: "Hotel", value: derivedTimeline.hotel_arrival_at },
    { label: "Venue", value: derivedTimeline.venue_arrival_at },
    { label: "Soundcheck", value: derivedTimeline.soundcheck_at },
    { label: "Show start", value: derivedTimeline.show_start_at },
    { label: "Show end", value: derivedTimeline.show_end_at },
    { label: "Venue exit", value: derivedTimeline.venue_exit_at },
    { label: "Deadline", value: derivedTimeline.next_departure_deadline_at },
  ];
  const computedWindows = timeline?.computed_windows || {};
  const alerts = Array.isArray(timeline?.alerts) ? timeline.alerts : [];
  const transfers = Array.isArray(timeline?.transfers) ? timeline.transfers : [];
  const currentArrivalAt =
    logistics?.arrival_at || derivedTimeline.landing_at || logistics?.venue_arrival_at || null;
  const currentLogisticsCost = logistics?.total_logistics_cost ?? currentBooking?.total_logistics_cost ?? 0;

  function renderEditorModal() {
    if (!editor) {
      return null;
    }

    return (
      <ActionModal
        title={editor.title}
        description={editor.description}
        onClose={() => setEditor(null)}
        wide={editor.type === "operation"}
      >
        <form onSubmit={handleSaveEditor} className="space-y-5">
          {editor.type === "artist" && (
            <>
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2">
                  <span className="input-label">Nome artistico *</span>
                  <input
                    className="input"
                    value={editor.form.stage_name}
                    onChange={(event) => setEditorField("stage_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Nome juridico</span>
                  <input
                    className="input"
                    value={editor.form.legal_name}
                    onChange={(event) => setEditorField("legal_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Documento</span>
                  <input
                    className="input"
                    value={editor.form.document_number}
                    onChange={(event) => setEditorField("document_number", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Tipo</span>
                  <input
                    className="input"
                    value={editor.form.artist_type}
                    onChange={(event) => setEditorField("artist_type", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Contato padrao</span>
                  <input
                    className="input"
                    value={editor.form.default_contact_name}
                    onChange={(event) => setEditorField("default_contact_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Telefone padrao</span>
                  <input
                    className="input"
                    value={editor.form.default_contact_phone}
                    onChange={(event) => setEditorField("default_contact_phone", event.target.value)}
                  />
                </label>
              </div>
              <label className="block space-y-2">
                <span className="input-label">Observacoes</span>
                <textarea
                  rows={3}
                  className="input resize-none"
                  value={editor.form.notes}
                  onChange={(event) => setEditorField("notes", event.target.value)}
                />
              </label>
              <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
                <input
                  type="checkbox"
                  className="checkbox"
                  checked={editor.form.is_active}
                  onChange={(event) => setEditorField("is_active", event.target.checked)}
                />
                Cadastro ativo para novas contratacoes
              </label>
            </>
          )}

          {editor.type === "booking" && (
            <>
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2">
                  <span className="input-label">Evento *</span>
                  <select
                    className="select"
                    value={editor.form.event_id}
                    onChange={(event) => setEditorField("event_id", event.target.value)}
                    disabled={Boolean(editor.bookingId)}
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
                  <span className="input-label">Status</span>
                  <select
                    className="select"
                    value={editor.form.booking_status}
                    onChange={(event) => setEditorField("booking_status", event.target.value)}
                  >
                    {Object.entries(BOOKING_STATUS_META).map(([key, meta]) => (
                      <option key={key} value={key}>
                        {meta.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="space-y-2">
                  <span className="input-label">Data do show</span>
                  <input
                    type="date"
                    className="input"
                    value={editor.form.performance_date}
                    onChange={(event) => setEditorField("performance_date", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Inicio do show</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.performance_start_at}
                    onChange={(event) => setEditorField("performance_start_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Soundcheck</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.soundcheck_at}
                    onChange={(event) => setEditorField("soundcheck_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Duracao (min)</span>
                  <input
                    type="number"
                    min="0"
                    className="input"
                    value={editor.form.performance_duration_minutes}
                    onChange={(event) => setEditorField("performance_duration_minutes", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Palco</span>
                  <input
                    className="input"
                    value={editor.form.stage_name}
                    onChange={(event) => setEditorField("stage_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Cache</span>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    className="input"
                    value={editor.form.cache_amount}
                    onChange={(event) => setEditorField("cache_amount", event.target.value)}
                  />
                </label>
              </div>
              <label className="block space-y-2">
                <span className="input-label">Observacoes</span>
                <textarea
                  rows={3}
                  className="input resize-none"
                  value={editor.form.notes}
                  onChange={(event) => setEditorField("notes", event.target.value)}
                />
              </label>
            </>
          )}

          {editor.type === "operation" && (
            <div className="space-y-6">
              <div className="rounded-3xl border border-white/10 bg-black/10 p-5">
                <div className="mb-4 space-y-1">
                  <h3 className="text-sm font-semibold uppercase tracking-[0.18em] text-gray-400">
                    Contratacao
                  </h3>
                  <p className="text-sm text-gray-500">
                    Ajuste palco, horarios e valor da contratacao no mesmo fluxo operacional.
                  </p>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <label className="space-y-2">
                    <span className="input-label">Status</span>
                    <select
                      className="select"
                      value={editor.form.booking_status}
                      onChange={(event) => setEditorField("booking_status", event.target.value)}
                    >
                      {Object.entries(BOOKING_STATUS_META).map(([key, meta]) => (
                        <option key={key} value={key}>
                          {meta.label}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Palco</span>
                    <input
                      className="input"
                      value={editor.form.stage_name}
                      onChange={(event) => setEditorField("stage_name", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Data do show</span>
                    <input
                      type="date"
                      className="input"
                      value={editor.form.performance_date}
                      onChange={(event) => setEditorField("performance_date", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Valor da contratacao</span>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      className="input"
                      value={editor.form.cache_amount}
                      onChange={(event) => setEditorField("cache_amount", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Inicio do show</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.performance_start_at}
                      onChange={(event) => setEditorField("performance_start_at", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Soundcheck</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.soundcheck_at}
                      onChange={(event) => setEditorField("soundcheck_at", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Duracao (min)</span>
                    <input
                      type="number"
                      min="0"
                      className="input"
                      value={editor.form.performance_duration_minutes}
                      onChange={(event) => setEditorField("performance_duration_minutes", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2 xl:col-span-1 md:col-span-2">
                    <span className="input-label">Observacoes da contratacao</span>
                    <textarea
                      rows={3}
                      className="input resize-none"
                      value={editor.form.booking_notes}
                      onChange={(event) => setEditorField("booking_notes", event.target.value)}
                    />
                  </label>
                </div>
              </div>

              <div className="rounded-3xl border border-white/10 bg-black/10 p-5">
                <div className="mb-4 space-y-1">
                  <h3 className="text-sm font-semibold uppercase tracking-[0.18em] text-gray-400">
                    Logistica e chegada
                  </h3>
                  <p className="text-sm text-gray-500">
                    Registre origem, voo, horario de chegada, hotel e deslocamento do artista.
                  </p>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <label className="space-y-2">
                    <span className="input-label">Origem</span>
                    <input
                      className="input"
                      value={editor.form.arrival_origin}
                      onChange={(event) => setEditorField("arrival_origin", event.target.value)}
                      placeholder="Cidade / aeroporto de origem"
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Modo de chegada</span>
                    <select
                      className="select"
                      value={editor.form.arrival_mode}
                      onChange={(event) => setEditorField("arrival_mode", event.target.value)}
                    >
                      {ARRIVAL_MODE_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">CIA / voo / localizador</span>
                    <input
                      className="input"
                      value={editor.form.arrival_reference}
                      onChange={(event) => setEditorField("arrival_reference", event.target.value)}
                      placeholder="Ex: Azul AD4321 / localizador"
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Horario de chegada</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.arrival_at}
                      onChange={(event) => setEditorField("arrival_at", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Hotel</span>
                    <input
                      className="input"
                      value={editor.form.hotel_name}
                      onChange={(event) => setEditorField("hotel_name", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2 xl:col-span-2">
                    <span className="input-label">Endereco do hotel</span>
                    <input
                      className="input"
                      value={editor.form.hotel_address}
                      onChange={(event) => setEditorField("hotel_address", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Venue arrival</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.venue_arrival_at}
                      onChange={(event) => setEditorField("venue_arrival_at", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Check-in hotel</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.hotel_check_in_at}
                      onChange={(event) => setEditorField("hotel_check_in_at", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Check-out hotel</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.hotel_check_out_at}
                      onChange={(event) => setEditorField("hotel_check_out_at", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Destino de saida</span>
                    <input
                      className="input"
                      value={editor.form.departure_destination}
                      onChange={(event) => setEditorField("departure_destination", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Modo de saida</span>
                    <input
                      className="input"
                      value={editor.form.departure_mode}
                      onChange={(event) => setEditorField("departure_mode", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Referencia de saida</span>
                    <input
                      className="input"
                      value={editor.form.departure_reference}
                      onChange={(event) => setEditorField("departure_reference", event.target.value)}
                    />
                  </label>
                  <label className="space-y-2">
                    <span className="input-label">Horario de saida</span>
                    <input
                      type="datetime-local"
                      className="input"
                      value={editor.form.departure_at}
                      onChange={(event) => setEditorField("departure_at", event.target.value)}
                    />
                  </label>
                </div>

                <div className="mt-4 grid gap-4 md:grid-cols-2">
                  <label className="block space-y-2">
                    <span className="input-label">Observacoes de hospitalidade</span>
                    <textarea
                      rows={3}
                      className="input resize-none"
                      value={editor.form.hospitality_notes}
                      onChange={(event) => setEditorField("hospitality_notes", event.target.value)}
                    />
                  </label>
                  <label className="block space-y-2">
                    <span className="input-label">Observacoes de transporte</span>
                    <textarea
                      rows={3}
                      className="input resize-none"
                      value={editor.form.transport_notes}
                      onChange={(event) => setEditorField("transport_notes", event.target.value)}
                    />
                  </label>
                </div>
              </div>

              <div className="rounded-3xl border border-white/10 bg-black/10 p-5">
                <div className="mb-4 flex items-center justify-between gap-3">
                  <div className="space-y-1">
                    <h3 className="text-sm font-semibold uppercase tracking-[0.18em] text-gray-400">
                      Custos logísticos
                    </h3>
                    <p className="text-sm text-gray-500">
                      Lance passagens, transfer, hospedagem, equipe e demais custos do artista.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => appendEditorArrayRow("items", createEmptyCostItemRow())}
                    className="btn-outline"
                  >
                    <Plus size={14} />
                    Adicionar custo
                  </button>
                </div>

                <div className="space-y-3">
                  {(editor.form.items || []).map((item, index) => (
                    <div key={item.id || `item-${index}`} className="rounded-2xl border border-white/10 bg-black/20 p-4">
                      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                        <label className="space-y-2">
                          <span className="input-label">Tipo</span>
                          <select
                            className="select"
                            value={item.item_type}
                            onChange={(event) => setEditorArrayField("items", index, "item_type", event.target.value)}
                          >
                            {LOGISTICS_ITEM_TYPE_OPTIONS.map((option) => (
                              <option key={option.value} value={option.value}>
                                {option.label}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="space-y-2 xl:col-span-2">
                          <span className="input-label">Descricao</span>
                          <input
                            className="input"
                            value={item.description}
                            onChange={(event) => setEditorArrayField("items", index, "description", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Qtd</span>
                          <input
                            type="number"
                            min="0.01"
                            step="0.01"
                            className="input"
                            value={item.quantity}
                            onChange={(event) => setEditorArrayField("items", index, "quantity", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Valor unit.</span>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="input"
                            value={item.unit_amount}
                            onChange={(event) => setEditorArrayField("items", index, "unit_amount", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Total manual</span>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="input"
                            value={item.total_amount}
                            onChange={(event) => setEditorArrayField("items", index, "total_amount", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Fornecedor</span>
                          <input
                            className="input"
                            value={item.supplier_name}
                            onChange={(event) => setEditorArrayField("items", index, "supplier_name", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Moeda</span>
                          <input
                            className="input"
                            value={item.currency_code}
                            onChange={(event) => setEditorArrayField("items", index, "currency_code", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Status</span>
                          <input
                            className="input"
                            value={item.status}
                            onChange={(event) => setEditorArrayField("items", index, "status", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2 xl:col-span-2">
                          <span className="input-label">Observacoes</span>
                          <input
                            className="input"
                            value={item.notes}
                            onChange={(event) => setEditorArrayField("items", index, "notes", event.target.value)}
                          />
                        </label>
                      </div>
                      <div className="mt-3 flex justify-end">
                        <button
                          type="button"
                          onClick={() => removeEditorArrayRow("items", index)}
                          className="rounded-full border border-red-500/30 px-3 py-1 text-xs text-red-300 transition-colors hover:border-red-500/50 hover:text-red-200"
                        >
                          Remover custo
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="rounded-3xl border border-white/10 bg-black/10 p-5">
                <div className="mb-4 flex items-center justify-between gap-3">
                  <div className="space-y-1">
                    <h3 className="text-sm font-semibold uppercase tracking-[0.18em] text-gray-400">
                      Equipe do artista
                    </h3>
                    <p className="text-sm text-gray-500">
                      Cadastre nomes, funcoes e necessidades da equipe que acompanha o artista.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => appendEditorArrayRow("team_members", createEmptyTeamMemberRow())}
                    className="btn-outline"
                  >
                    <Plus size={14} />
                    Adicionar membro
                  </button>
                </div>

                <div className="space-y-3">
                  {(editor.form.team_members || []).map((member, index) => (
                    <div
                      key={member.id || `member-${index}`}
                      className="rounded-2xl border border-white/10 bg-black/20 p-4"
                    >
                      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <label className="space-y-2">
                          <span className="input-label">Nome</span>
                          <input
                            className="input"
                            value={member.full_name}
                            onChange={(event) => setEditorArrayField("team_members", index, "full_name", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Funcao</span>
                          <input
                            className="input"
                            value={member.role_name}
                            onChange={(event) => setEditorArrayField("team_members", index, "role_name", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Documento</span>
                          <input
                            className="input"
                            value={member.document_number}
                            onChange={(event) => setEditorArrayField("team_members", index, "document_number", event.target.value)}
                          />
                        </label>
                        <label className="space-y-2">
                          <span className="input-label">Telefone</span>
                          <input
                            className="input"
                            value={member.phone}
                            onChange={(event) => setEditorArrayField("team_members", index, "phone", event.target.value)}
                          />
                        </label>
                        <label className="block space-y-2 xl:col-span-2">
                          <span className="input-label">Observacoes</span>
                          <input
                            className="input"
                            value={member.notes}
                            onChange={(event) => setEditorArrayField("team_members", index, "notes", event.target.value)}
                          />
                        </label>
                        <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
                          <input
                            type="checkbox"
                            className="checkbox"
                            checked={member.needs_hotel}
                            onChange={(event) =>
                              setEditorArrayField("team_members", index, "needs_hotel", event.target.checked)
                            }
                          />
                          Precisa de hotel
                        </label>
                        <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
                          <input
                            type="checkbox"
                            className="checkbox"
                            checked={member.needs_transfer}
                            onChange={(event) =>
                              setEditorArrayField("team_members", index, "needs_transfer", event.target.checked)
                            }
                          />
                          Precisa de transfer
                        </label>
                      </div>

                      <div className="mt-3 flex justify-between gap-3">
                        <label className="flex items-center gap-3 text-sm text-gray-300">
                          <input
                            type="checkbox"
                            className="checkbox"
                            checked={member.is_active}
                            onChange={(event) =>
                              setEditorArrayField("team_members", index, "is_active", event.target.checked)
                            }
                          />
                          Membro ativo
                        </label>
                        <button
                          type="button"
                          onClick={() => removeEditorArrayRow("team_members", index)}
                          className="rounded-full border border-red-500/30 px-3 py-1 text-xs text-red-300 transition-colors hover:border-red-500/50 hover:text-red-200"
                        >
                          Remover membro
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {editor.type === "timeline" && (
            <>
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label className="space-y-2">
                  <span className="input-label">Landing</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.landing_at}
                    onChange={(event) => setEditorField("landing_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Saida do aeroporto</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.airport_out_at}
                    onChange={(event) => setEditorField("airport_out_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Chegada no hotel</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.hotel_arrival_at}
                    onChange={(event) => setEditorField("hotel_arrival_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Chegada no venue</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.venue_arrival_at}
                    onChange={(event) => setEditorField("venue_arrival_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Soundcheck</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.soundcheck_at}
                    onChange={(event) => setEditorField("soundcheck_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Inicio do show</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.show_start_at}
                    onChange={(event) => setEditorField("show_start_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Fim do show</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.show_end_at}
                    onChange={(event) => setEditorField("show_end_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Saida do venue</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.venue_exit_at}
                    onChange={(event) => setEditorField("venue_exit_at", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Deadline proxima saida</span>
                  <input
                    type="datetime-local"
                    className="input"
                    value={editor.form.next_departure_deadline_at}
                    onChange={(event) => setEditorField("next_departure_deadline_at", event.target.value)}
                  />
                </label>
              </div>
              <p className="text-sm text-gray-500">
                Ao salvar, o backend recalcula automaticamente a timeline operacional e os alertas desta contratacao.
              </p>
            </>
          )}

          {editor.type === "team" && (
            <>
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2">
                  <span className="input-label">Nome *</span>
                  <input
                    className="input"
                    value={editor.form.full_name}
                    onChange={(event) => setEditorField("full_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Funcao</span>
                  <input
                    className="input"
                    value={editor.form.role_name}
                    onChange={(event) => setEditorField("role_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Documento</span>
                  <input
                    className="input"
                    value={editor.form.document_number}
                    onChange={(event) => setEditorField("document_number", event.target.value)}
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Telefone</span>
                  <input
                    className="input"
                    value={editor.form.phone}
                    onChange={(event) => setEditorField("phone", event.target.value)}
                  />
                </label>
              </div>
              <label className="block space-y-2">
                <span className="input-label">Observacoes</span>
                <textarea
                  rows={3}
                  className="input resize-none"
                  value={editor.form.notes}
                  onChange={(event) => setEditorField("notes", event.target.value)}
                />
              </label>
              <div className="grid gap-3 md:grid-cols-3">
                <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
                  <input
                    type="checkbox"
                    className="checkbox"
                    checked={editor.form.needs_hotel}
                    onChange={(event) => setEditorField("needs_hotel", event.target.checked)}
                  />
                  Precisa de hotel
                </label>
                <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
                  <input
                    type="checkbox"
                    className="checkbox"
                    checked={editor.form.needs_transfer}
                    onChange={(event) => setEditorField("needs_transfer", event.target.checked)}
                  />
                  Precisa de transfer
                </label>
                <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 px-4 py-3 text-sm text-gray-300">
                  <input
                    type="checkbox"
                    className="checkbox"
                    checked={editor.form.is_active}
                    onChange={(event) => setEditorField("is_active", event.target.checked)}
                  />
                  Membro ativo
                </label>
              </div>
            </>
          )}

          {editor.type === "file" && (
            <>
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2">
                  <span className="input-label">Tipo *</span>
                  <select
                    className="select"
                    value={editor.form.file_type}
                    onChange={(event) => setEditorField("file_type", event.target.value)}
                  >
                    <option value="">Selecionar tipo...</option>
                    <option value="contract">Contrato</option>
                    <option value="rider">Rider</option>
                    <option value="rooming_list">Rooming list</option>
                    <option value="flight">Passagem</option>
                    <option value="voucher">Voucher</option>
                    <option value="document">Documento diverso</option>
                  </select>
                </label>
                <label className="space-y-2">
                  <span className="input-label">Nome original *</span>
                  <input
                    className="input"
                    value={editor.form.original_name}
                    onChange={(event) => setEditorField("original_name", event.target.value)}
                  />
                </label>
                <label className="space-y-2 md:col-span-2">
                  <span className="input-label">Storage path *</span>
                  <input
                    className="input"
                    value={editor.form.storage_path}
                    onChange={(event) => setEditorField("storage_path", event.target.value)}
                    placeholder="artists/event-88/contrato.pdf"
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">MIME</span>
                  <input
                    className="input"
                    value={editor.form.mime_type}
                    onChange={(event) => setEditorField("mime_type", event.target.value)}
                    placeholder="application/pdf"
                  />
                </label>
                <label className="space-y-2">
                  <span className="input-label">Tamanho (bytes)</span>
                  <input
                    type="number"
                    min="0"
                    className="input"
                    value={editor.form.file_size_bytes}
                    onChange={(event) => setEditorField("file_size_bytes", event.target.value)}
                  />
                </label>
              </div>
              <label className="block space-y-2">
                <span className="input-label">Observacoes</span>
                <textarea
                  rows={3}
                  className="input resize-none"
                  value={editor.form.notes}
                  onChange={(event) => setEditorField("notes", event.target.value)}
                />
              </label>
            </>
          )}

          <div className="flex gap-3">
            <button type="submit" disabled={savingEditor} className="btn-primary flex-1">
              {savingEditor ? "Salvando..." : editor.submitLabel}
            </button>
            <button type="button" onClick={() => setEditor(null)} className="btn-outline flex-1">
              Cancelar
            </button>
          </div>
        </form>
      </ActionModal>
    );
  }

  return (
    <div className="space-y-6">
      {renderEditorModal()}

      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div className="flex items-start gap-3">
          <button
            type="button"
            onClick={() => navigate(buildScopedPath("/artists", selectedEventId || scopedEventId))}
            className="btn-outline p-2"
          >
            <ArrowLeft size={16} />
          </button>

          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="page-title">
                <MicVocal size={22} className="text-brand" />
                {artist.stage_name}
              </h1>
              <span className={artist.is_active ? "badge-green" : "badge-gray"}>
                {artist.is_active ? "Ativo" : "Inativo"}
              </span>
            </div>
            <p className="text-sm text-gray-400">
              {artist.legal_name || "Sem nome juridico"} · {artist.artist_type || "Tipo nao definido"}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {bookings.length > 0 && (
            <select
              className="select min-w-[240px]"
              value={selectedEventId}
              onChange={(event) =>
                updateRouteState({
                  event_id: event.target.value,
                })
              }
            >
              {bookings.map((booking) => (
                <option key={booking.id} value={booking.event_id}>
                  {booking.event_name || `Evento ${booking.event_id}`}
                </option>
              ))}
            </select>
          )}

          <button
            type="button"
            onClick={() => setRefreshToken((current) => current + 1)}
            className="btn-outline"
          >
            <RefreshCw size={16} />
            Atualizar
          </button>

          {canImport && (
            <Link
              to={buildScopedPath("/artists/import", currentBooking?.event_id || selectedEventId || scopedEventId)}
              className="btn-primary"
            >
              <Upload size={16} />
              Importar
            </Link>
          )}
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
        <StatCard label="Artista" value={artist.stage_name} helper={artist.artist_type || "Cadastro mestre"} />
        <StatCard
          label="Evento"
          value={currentBooking?.event_name || "Sem evento"}
          helper="Contexto operacional atual"
        />
        <StatCard
          label="Show"
          value={formatDateTimeRelativeLabel(currentBooking?.performance_start_at)}
          helper={currentBooking?.stage_name || "Palco nao definido"}
        />
        <StatCard
          label="Chegada"
          value={formatDateTimeRelativeLabel(currentArrivalAt)}
          helper={logistics?.arrival_reference || "Sem chegada registrada"}
        />
        <StatCard
          label="Severidade atual"
          value={severityMeta.label}
          helper={timelineMeta.label}
          tone={
            timeline?.current_severity === "red"
              ? "danger"
              : timeline?.current_severity === "orange" || timeline?.current_severity === "yellow"
                ? "warning"
                : "default"
          }
        />
        <StatCard
          label="Custo logistico"
          value={formatCurrency(currentLogisticsCost)}
          helper={`${formatNumber(logisticsItems.length)} item(ns) logísticos`}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(340px,0.9fr)]">
        <div className="card border-white/5">
          <div className="flex items-center justify-between gap-3">
            <h2 className="section-title">Cadastro base</h2>
            {canManage && (
              <button type="button" onClick={openArtistEditor} className="btn-outline">
                <Pencil size={14} />
                Editar artista
              </button>
            )}
          </div>
          <div className="mt-4 grid gap-3 md:grid-cols-2">
            <DetailRow label="Nome artistico" value={artist.stage_name} />
            <DetailRow label="Nome juridico" value={artist.legal_name} />
            <DetailRow label="Documento" value={artist.document_number} />
            <DetailRow label="Tipo" value={artist.artist_type} />
            <DetailRow label="Contato padrao" value={artist.default_contact_name} />
            <DetailRow label="Telefone padrao" value={artist.default_contact_phone} />
            <DetailRow label="Criado em" value={formatDateTime(artist.created_at)} />
            <DetailRow label="Atualizado em" value={formatDateTime(artist.updated_at)} />
          </div>
          {artist.notes && <p className="mt-4 text-sm text-gray-400">{artist.notes}</p>}
        </div>

        <div className="card border-white/5">
          <div className="flex items-center justify-between gap-3">
            <h2 className="section-title">Operacao atual</h2>
            <div className="flex flex-wrap items-center gap-2">
              {currentBooking && (
                <>
                  <button
                    type="button"
                    onClick={() => handleExportOperation("csv")}
                    disabled={exportingFormat !== null}
                    className="btn-outline"
                  >
                    <Download size={14} />
                    {exportingFormat === "csv" ? "Gerando CSV..." : "Exportar CSV"}
                  </button>
                  <button
                    type="button"
                    onClick={() => handleExportOperation("docx")}
                    disabled={exportingFormat !== null}
                    className="btn-outline"
                  >
                    <Download size={14} />
                    {exportingFormat === "docx" ? "Gerando DOCX..." : "Exportar DOCX"}
                  </button>
                </>
              )}
              {canManage && (
                <button
                  type="button"
                  onClick={currentBooking ? openOperationEditor : () => openBookingEditor()}
                  className="btn-outline"
                >
                  <Pencil size={14} />
                  {currentBooking ? "Configurar operacao" : "Nova contratacao"}
                </button>
              )}
            </div>
          </div>
          {currentBooking ? (
            <div className="mt-4 space-y-4">
              <div className="flex flex-wrap items-center gap-3">
                <span className="badge-gray">{currentBooking.event_name}</span>
                <span className={bookingMeta.className}>{bookingMeta.label}</span>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <DetailRow label="Palco" value={currentBooking.stage_name || "Nao definido"} />
                <DetailRow label="Show" value={formatDateTimeRelativeLabel(currentBooking.performance_start_at)} />
                <DetailRow label="Soundcheck" value={formatDateTimeRelativeLabel(currentBooking.soundcheck_at)} />
                <DetailRow label="Chegada" value={formatDateTimeRelativeLabel(currentArrivalAt)} />
                <DetailRow label="Cache" value={formatCurrency(currentBooking.cache_amount)} />
                <DetailRow label="Total artistico" value={formatCurrency(currentBooking.total_artist_cost)} />
              </div>
            </div>
          ) : (
            <p className="mt-4 text-sm text-gray-500">
              Selecione uma contratacao para abrir o contexto operacional.
            </p>
          )}
        </div>
      </div>

      <div className="card border-white/5">
        <div className="flex items-center justify-between gap-3">
          <h2 className="section-title">Eventos deste artista</h2>
          <div className="flex items-center gap-3">
            <span className="text-sm text-gray-500">
              {formatNumber(bookings.length)} contratacao(oes)
            </span>
            {canManage && (
              <button type="button" onClick={() => openBookingEditor()} className="btn-outline">
                <Plus size={14} />
                Nova contratacao
              </button>
            )}
          </div>
        </div>

        {bookings.length === 0 ? (
          <p className="mt-4 text-sm text-gray-500">Nenhuma contratacao vinculada ainda.</p>
        ) : (
          <div className="mt-4 flex flex-wrap gap-2">
            {bookings.map((booking) => (
              <button
                key={booking.id}
                type="button"
                onClick={() =>
                  updateRouteState({
                    event_id: booking.event_id,
                    tab: "bookings",
                  })
                }
                className={`rounded-full border px-4 py-2 text-sm transition-colors ${
                  String(booking.event_id) === String(selectedEventId)
                    ? "border-brand/40 bg-brand/10 text-brand"
                    : "border-white/10 text-gray-400 hover:border-white/20 hover:text-white"
                }`}
              >
                {booking.event_name || `Evento ${booking.event_id}`}
              </button>
            ))}
          </div>
        )}
      </div>

      <div className="card border-white/5">
        <div className="flex flex-wrap gap-2">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => updateRouteState({ tab: tab.id })}
              className={`rounded-full px-4 py-2 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? "bg-brand/15 text-brand"
                  : "text-gray-400 hover:bg-white/5 hover:text-white"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {scopedEventId && artist && (
        <EmbeddedAIChat
          surface="artists"
          title={`Assistente de ${artist.stage_name}`}
          description="Logistica, timeline, alertas, custos e equipe deste artista"
          accentColor="emerald"
          context={{
            event_artist_id: currentBooking?.id || artist.id,
            focus_artist_name: artist.stage_name,
          }}
          suggestions={[
            `Como esta a logistica de ${artist.stage_name}?`,
            'Tem algum alerta critico?',
            'Qual o custo total deste artista?',
          ]}
        />
      )}

      {activeTab === "bookings" && (
        <div className="card border-white/5">
          <div className="flex items-center justify-between gap-3">
            <h2 className="section-title">Contratacoes do artista</h2>
            <div className="flex items-center gap-3">
              <span className="text-sm text-gray-500">
                {formatNumber(bookings.length)} registro(s)
              </span>
              {canManage && (
                <button type="button" onClick={() => openBookingEditor()} className="btn-outline">
                  <Plus size={14} />
                  Nova contratacao
                </button>
              )}
            </div>
          </div>

          {bookings.length === 0 ? (
            <p className="mt-4 text-sm text-gray-500">Nenhuma contratacao encontrada.</p>
          ) : (
            <div className="table-wrapper mt-4">
              <table className="table">
                <thead>
                  <tr>
                    <th>Evento</th>
                    <th>Status</th>
                    <th>Show</th>
                    <th>Palco</th>
                    <th className="text-right">Cache</th>
                    <th className="text-right">Logistica</th>
                    <th className="text-right">Acoes</th>
                  </tr>
                </thead>
                <tbody>
                  {bookings.map((booking) => {
                    const meta = resolveMeta(BOOKING_STATUS_META, booking.booking_status);
                    return (
                      <tr key={booking.id}>
                        <td className="font-medium text-white">{booking.event_name || `Evento ${booking.event_id}`}</td>
                        <td>
                          <span className={meta.className}>{meta.label}</span>
                        </td>
                        <td className="text-sm text-gray-400">
                          {formatDateTime(booking.performance_start_at)}
                        </td>
                        <td className="text-sm text-gray-400">
                          {booking.stage_name || "—"}
                        </td>
                        <td className="text-right tabular-nums text-white">
                          {formatCurrency(booking.cache_amount)}
                        </td>
                        <td className="text-right tabular-nums text-cyan-300">
                          {formatCurrency(booking.total_logistics_cost)}
                        </td>
                        <td className="text-right">
                          <div className="flex justify-end gap-2">
                            <button
                              type="button"
                              onClick={() => openBookingEditor(booking)}
                              className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                            >
                              Editar
                            </button>
                            <button
                              type="button"
                             onClick={() =>
                                updateRouteState({
                                  event_id: booking.event_id,
                                  tab: "logistics",
                                })
                              }
                              className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                            >
                              Logistica
                            </button>
                            {canManage && (
                              <button
                                type="button"
                                onClick={() => handleCancelBooking(booking)}
                                className="rounded-full border border-red-500/30 px-3 py-1 text-xs text-red-300 transition-colors hover:border-red-500/50 hover:text-red-200"
                              >
                                Cancelar
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {activeTab === "logistics" && (
        <>
          {!currentBooking ? (
            <EmptyState
              icon={CalendarDays}
              title="Selecione uma contratacao"
              description="A logistica depende de um contexto de evento. Escolha uma contratacao no seletor superior ou na aba de contratacoes."
            />
          ) : relatedLoading ? (
            <div className="py-12 text-center text-gray-500">Carregando logistica...</div>
          ) : (
            <div className="space-y-6">
              {relatedError && (
                <div className="card border-red-500/20 bg-red-500/5 text-sm text-red-300">
                  {relatedError}
                </div>
              )}

              <div className="card border-white/5">
                <div className="flex items-center justify-between gap-3">
                  <h2 className="section-title">Logistica consolidada</h2>
                  {canManage && (
                    <button type="button" onClick={openOperationEditor} className="btn-outline">
                      <Pencil size={14} />
                      Configurar operacao
                    </button>
                  )}
                </div>
                {logistics ? (
                  <div className="mt-4 space-y-4">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                      <DetailRow label="Origem" value={logistics.arrival_origin} />
                      <DetailRow label="Modo de chegada" value={logistics.arrival_mode} />
                      <DetailRow label="CIA / voo / ref." value={logistics.arrival_reference} />
                      <DetailRow label="Chegada" value={formatDateTime(logistics.arrival_at)} />
                      <DetailRow label="Hotel" value={logistics.hotel_name} />
                      <DetailRow label="Check-in" value={formatDateTime(logistics.hotel_check_in_at)} />
                      <DetailRow label="Check-out" value={formatDateTime(logistics.hotel_check_out_at)} />
                      <DetailRow label="Venue arrival" value={formatDateTime(logistics.venue_arrival_at)} />
                      <DetailRow label="Destino saida" value={logistics.departure_destination} />
                      <DetailRow label="Modo saida" value={logistics.departure_mode} />
                      <DetailRow label="Ref. saida" value={logistics.departure_reference} />
                      <DetailRow label="Horario de saida" value={formatDateTime(logistics.departure_at)} />
                    </div>

                    {(logistics.hospitality_notes || logistics.transport_notes) && (
                      <div className="rounded-xl border border-white/5 bg-black/10 p-4 text-sm text-gray-400">
                        {logistics.hospitality_notes && (
                          <p>
                            <span className="font-medium text-white">Hospitalidade:</span>{" "}
                            {logistics.hospitality_notes}
                          </p>
                        )}
                        {logistics.transport_notes && (
                          <p className="mt-2">
                            <span className="font-medium text-white">Transporte:</span>{" "}
                            {logistics.transport_notes}
                          </p>
                        )}
                      </div>
                    )}
                  </div>
                ) : (
                  <p className="mt-4 text-sm text-gray-500">
                    Nenhuma logistica consolidada cadastrada para esta contratacao.
                  </p>
                )}
              </div>

              <div className="card border-white/5">
                <div className="flex items-center justify-between gap-3">
                  <h2 className="section-title">Custos logísticos</h2>
                  <span className="text-sm text-gray-500">
                    {formatNumber(logisticsItems.length)} item(ns) • {formatCurrency(currentLogisticsCost)}
                  </span>
                </div>
                {logisticsItems.length === 0 ? (
                  <p className="mt-4 text-sm text-gray-500">
                    Nenhum custo logístico cadastrado para esta contratacao.
                  </p>
                ) : (
                  <div className="table-wrapper mt-4">
                    <table className="table">
                      <thead>
                        <tr>
                          <th>Tipo</th>
                          <th>Descricao</th>
                          <th>Fornecedor</th>
                          <th className="text-right">Qtd</th>
                          <th className="text-right">Unit.</th>
                          <th className="text-right">Total</th>
                        </tr>
                      </thead>
                      <tbody>
                        {logisticsItems.map((item) => (
                          <tr key={item.id}>
                            <td className="text-sm text-gray-300">{item.item_type || "—"}</td>
                            <td>
                              <div className="space-y-1">
                                <p className="font-medium text-white">{item.description}</p>
                                {item.notes && <p className="text-xs text-gray-500">{item.notes}</p>}
                              </div>
                            </td>
                            <td className="text-sm text-gray-400">{item.supplier_name || "—"}</td>
                            <td className="text-right tabular-nums text-white">{formatNumber(item.quantity)}</td>
                            <td className="text-right tabular-nums text-white">{formatCurrency(item.unit_amount)}</td>
                            <td className="text-right tabular-nums font-semibold text-cyan-300">
                              {formatCurrency(item.total_amount)}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}
        </>
      )}

      {activeTab === "timeline" && (
        <>
          {!currentBooking ? (
            <EmptyState
              icon={CalendarDays}
              title="Selecione uma contratacao"
              description="A timeline depende de um contexto de evento. Escolha uma contratacao no seletor superior ou na aba de contratacoes."
            />
          ) : relatedLoading ? (
            <div className="py-12 text-center text-gray-500">Carregando timeline...</div>
          ) : (
            <div className="space-y-6">
              {relatedError && (
                <div className="card border-red-500/20 bg-red-500/5 text-sm text-red-300">
                  {relatedError}
                </div>
              )}

              <div className="flex flex-wrap items-center justify-end gap-2">
                {canManage && (
                  <button type="button" onClick={openTimelineEditor} className="btn-outline">
                    <Pencil size={14} />
                    {timeline ? "Editar horarios-base" : "Criar timeline"}
                  </button>
                )}
                {canManage && (
                  <button type="button" onClick={handleRecalculateTimeline} className="btn-outline">
                    <RefreshCw size={14} />
                    Recalcular timeline
                  </button>
                )}
                {canManage && (
                  <button type="button" onClick={handleRecalculateAlerts} className="btn-outline">
                    <RefreshCw size={14} />
                    Recalcular alertas
                  </button>
                )}
              </div>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                  label="Timeline"
                  value={timelineMeta.label}
                  helper={currentBooking.event_name || `Evento ${currentBooking.event_id}`}
                  tone={
                    timeline?.timeline_status === "critical"
                      ? "danger"
                      : timeline?.timeline_status === "attention"
                        ? "warning"
                        : "default"
                  }
                />
                <StatCard
                  label="Severidade"
                  value={severityMeta.label}
                  helper="Maior severidade operacional ativa."
                  tone={
                    timeline?.current_severity === "red"
                      ? "danger"
                      : timeline?.current_severity === "orange" || timeline?.current_severity === "yellow"
                        ? "warning"
                        : "default"
                  }
                />
                <StatCard
                  label="Transfers"
                  value={formatNumber(transfers.length)}
                  helper="Rotas consideradas no calculo operacional."
                />
                <StatCard
                  label="Alertas abertos"
                  value={formatNumber(alerts.filter((item) => item.status !== "resolved").length)}
                  helper="Alertas em aberto ou acknowledged."
                  tone={alerts.some((item) => item.status !== "resolved") ? "warning" : "default"}
                />
              </div>

              <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.95fr)]">
                <div className="card border-white/5">
                  <h2 className="section-title">Checkpoint operacional</h2>
                  <div className="mt-4 grid gap-3 md:grid-cols-2">
                    {timelineEvents.map((eventItem) => (
                      <DetailRow
                        key={eventItem.label}
                        label={eventItem.label}
                        value={formatDateTime(eventItem.value)}
                      />
                    ))}
                  </div>
                </div>

                <div className="card border-white/5">
                  <h2 className="section-title">Janelas calculadas</h2>
                  <div className="mt-4 space-y-3">
                    {[
                      {
                        id: "arrival_soundcheck",
                        label: "Chegada → Soundcheck",
                        value: computedWindows.arrival_soundcheck,
                      },
                      {
                        id: "arrival_show",
                        label: "Chegada → Show",
                        value: computedWindows.arrival_show,
                      },
                      {
                        id: "departure_deadline",
                        label: "Saida → Deadline",
                        value: computedWindows.departure_deadline,
                      },
                    ].map((windowItem) => (
                      <div key={windowItem.id} className="rounded-xl border border-white/5 bg-black/10 p-4">
                        <div className="flex items-center justify-between gap-3">
                          <p className="font-medium text-white">{windowItem.label}</p>
                          <span className="text-sm text-gray-500">
                            ETA {formatMinutes(windowItem.value?.planned_eta_minutes)}
                          </span>
                        </div>
                        <div className="mt-3 grid gap-3 text-sm text-gray-400 md:grid-cols-2">
                          <p>Previsto: {formatDateTime(windowItem.value?.predicted_at)}</p>
                          <p>Alvo: {formatDateTime(windowItem.value?.target_at)}</p>
                          <p>Margem: {windowItem.value?.margin_minutes != null ? `${windowItem.value.margin_minutes} min` : "—"}</p>
                          <p>Origem: {windowItem.value?.source || "—"}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              <div className="card border-white/5">
                <h2 className="section-title">Transfers</h2>
                {transfers.length === 0 ? (
                  <p className="mt-4 text-sm text-gray-500">Nenhum transfer cadastrado.</p>
                ) : (
                  <div className="table-wrapper mt-4">
                    <table className="table">
                      <thead>
                        <tr>
                          <th>Rota</th>
                          <th>Trecho</th>
                          <th className="text-right">ETA planejado</th>
                        </tr>
                      </thead>
                      <tbody>
                        {transfers.map((transfer) => (
                          <tr key={transfer.id}>
                            <td>
                              <div className="space-y-1">
                                <p className="font-medium text-white">
                                  {transfer.route_code || "Rota sem codigo"}
                                </p>
                                <p className="text-xs uppercase tracking-[0.18em] text-gray-600">
                                  {transfer.route_phase || "other"}
                                </p>
                              </div>
                            </td>
                            <td className="text-sm text-gray-400">
                              {transfer.origin_label || "Origem"} → {transfer.destination_label || "Destino"}
                            </td>
                            <td className="text-right tabular-nums text-white">
                              {formatMinutes(transfer.planned_eta_minutes)}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}
        </>
      )}

      {activeTab === "alerts" && (
        <>
          {!currentBooking ? (
            <EmptyState
              icon={AlertCircle}
              title="Selecione uma contratacao"
              description="Os alertas operacionais dependem de um contexto de evento."
            />
          ) : relatedLoading ? (
            <div className="py-12 text-center text-gray-500">Carregando alertas...</div>
          ) : (
            <div className="space-y-6">
              {relatedError && (
                <div className="card border-red-500/20 bg-red-500/5 text-sm text-red-300">
                  {relatedError}
                </div>
              )}

              <div className="card border-white/5">
                <div className="flex items-center justify-between gap-3">
                  <h2 className="section-title">Alertas operacionais</h2>
                  {canManage && (
                    <button type="button" onClick={handleRecalculateAlerts} className="btn-outline">
                      <RefreshCw size={14} />
                      Recalcular alertas
                    </button>
                  )}
                </div>
                {alerts.length === 0 ? (
                  <p className="mt-4 text-sm text-gray-500">Nenhum alerta registrado nesta timeline.</p>
                ) : (
                  <div className="mt-4 space-y-3">
                    {alerts.map((alert) => {
                      const severity = resolveMeta(ALERT_SEVERITY_META, alert.severity);
                      const status = resolveMeta(ALERT_STATUS_META, alert.status);
                      return (
                        <div key={alert.id} className="rounded-2xl border border-white/5 bg-black/10 p-4">
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="space-y-2">
                              <div className="flex flex-wrap items-center gap-2">
                                <span className={severity.className}>{severity.label}</span>
                                <span className={status.className}>{status.label}</span>
                              </div>
                              <h3 className="font-semibold text-white">{alert.title}</h3>
                            </div>
                            <p className="text-xs text-gray-500">
                              {formatDateTime(alert.triggered_at)}
                            </p>
                          </div>
                          <p className="mt-3 text-sm text-gray-400">{alert.message}</p>
                          {alert.recommended_action && (
                            <p className="mt-3 text-sm text-cyan-300">
                              Acao sugerida: {alert.recommended_action}
                            </p>
                          )}
                          {canManage && (
                            <div className="mt-4 flex flex-wrap gap-2">
                              {alert.status !== "acknowledged" && alert.status !== "resolved" && alert.status !== "dismissed" && (
                                <button
                                  type="button"
                                  onClick={() => handleAlertAction(alert, "acknowledge")}
                                  className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                                >
                                  Reconhecer
                                </button>
                              )}
                              {alert.status !== "resolved" && (
                                <button
                                  type="button"
                                  onClick={() => handleAlertAction(alert, "resolve")}
                                  className="rounded-full border border-emerald-500/30 px-3 py-1 text-xs text-emerald-300 transition-colors hover:border-emerald-500/50 hover:text-emerald-200"
                                >
                                  Resolver
                                </button>
                              )}
                              {alert.status !== "dismissed" && (
                                <button
                                  type="button"
                                  onClick={() => handleAlertAction(alert, "dismiss")}
                                  className="rounded-full border border-red-500/30 px-3 py-1 text-xs text-red-300 transition-colors hover:border-red-500/50 hover:text-red-200"
                                >
                                  Descartar
                                </button>
                              )}
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>
          )}
        </>
      )}

      {activeTab === "team" && (
        <>
          {!currentBooking ? (
            <EmptyState
              icon={Users}
              title="Selecione uma contratacao"
              description="A equipe do artista e vinculada a uma contratacao especifica do evento."
            />
          ) : relatedLoading ? (
            <div className="py-12 text-center text-gray-500">Carregando equipe...</div>
          ) : (
            <div className="card border-white/5">
              <div className="flex items-center justify-between gap-3">
                <h2 className="section-title">Equipe vinculada</h2>
                <div className="flex items-center gap-3">
                  <span className="text-sm text-gray-500">{formatNumber(teamMembers.length)} pessoa(s)</span>
                  {canManage && (
                    <button type="button" onClick={() => openTeamEditor()} className="btn-outline">
                      <Plus size={14} />
                      Adicionar
                    </button>
                  )}
                </div>
              </div>

              {teamMembers.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">
                  Nenhum membro de equipe cadastrado para esta contratacao.
                </p>
              ) : (
                <div className="table-wrapper mt-4">
                  <table className="table">
                    <thead>
                      <tr>
                        <th>Nome</th>
                        <th>Funcao</th>
                        <th>Contato</th>
                        <th>Flags</th>
                        <th>Status</th>
                        <th className="text-right">Acoes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {teamMembers.map((member) => (
                        <tr key={member.id}>
                          <td>
                            <div className="space-y-1">
                              <p className="font-medium text-white">{member.full_name}</p>
                              <p className="text-xs text-gray-500">{member.document_number || "Sem documento"}</p>
                            </div>
                          </td>
                          <td className="text-sm text-gray-400">{member.role_name || "Sem funcao"}</td>
                          <td className="text-sm text-gray-400">{member.phone || "Sem telefone"}</td>
                          <td>
                            <div className="flex flex-wrap gap-2">
                              {member.needs_hotel && <span className="badge-gray">Hotel</span>}
                              {member.needs_transfer && <span className="badge-gray">Transfer</span>}
                              {!member.needs_hotel && !member.needs_transfer && (
                                <span className="text-xs text-gray-600">Sem flags</span>
                              )}
                            </div>
                          </td>
                          <td>
                            <span className={member.is_active ? "badge-green" : "badge-gray"}>
                              {member.is_active ? "Ativo" : "Inativo"}
                            </span>
                          </td>
                          <td className="text-right">
                            {canManage && (
                              <div className="flex justify-end gap-2">
                                <button
                                  type="button"
                                  onClick={() => openTeamEditor(member)}
                                  className="rounded-full border border-white/10 px-3 py-1 text-xs text-gray-300 transition-colors hover:border-white/20 hover:text-white"
                                >
                                  Editar
                                </button>
                                <button
                                  type="button"
                                  onClick={() => handleDeleteTeamMember(member)}
                                  className="rounded-full border border-red-500/30 px-3 py-1 text-xs text-red-300 transition-colors hover:border-red-500/50 hover:text-red-200"
                                >
                                  Remover
                                </button>
                              </div>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </>
      )}

      {activeTab === "files" && (
        <>
          {!currentBooking ? (
            <EmptyState
              icon={FileText}
              title="Selecione uma contratacao"
              description="Arquivos ficam vinculados a contratacao do evento."
            />
          ) : relatedLoading ? (
            <div className="py-12 text-center text-gray-500">Carregando arquivos...</div>
          ) : (
            <div className="card border-white/5">
              <div className="flex items-center justify-between gap-3">
                <h2 className="section-title">Arquivos da contratacao</h2>
                <div className="flex items-center gap-3">
                  <span className="text-sm text-gray-500">{formatNumber(files.length)} item(ns)</span>
                  {canManage && (
                    <button type="button" onClick={openFileEditor} className="btn-outline">
                      <Plus size={14} />
                      Registrar
                    </button>
                  )}
                </div>
              </div>

              {files.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">Nenhum arquivo registrado para esta contratacao.</p>
              ) : (
                <div className="table-wrapper mt-4">
                  <table className="table">
                    <thead>
                      <tr>
                        <th>Arquivo</th>
                        <th>Tipo</th>
                        <th>MIME</th>
                        <th>Tamanho</th>
                        <th>Criado em</th>
                        <th className="text-right">Acoes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {files.map((file) => (
                        <tr key={file.id}>
                          <td>
                            <div className="space-y-1">
                              <p className="font-medium text-white">{file.original_name}</p>
                              <p className="max-w-[320px] truncate text-xs text-gray-600">
                                {file.storage_path}
                              </p>
                            </div>
                          </td>
                          <td className="text-sm text-gray-400">{file.file_type || "—"}</td>
                          <td className="text-sm text-gray-400">{file.mime_type || "—"}</td>
                          <td className="text-sm text-gray-400">{formatFileSize(file.file_size_bytes)}</td>
                          <td className="text-sm text-gray-400">{formatDateTime(file.created_at)}</td>
                          <td className="text-right">
                            {canManage && (
                              <button
                                type="button"
                                onClick={() => handleDeleteFile(file)}
                                className="rounded-full border border-red-500/30 px-3 py-1 text-xs text-red-300 transition-colors hover:border-red-500/50 hover:text-red-200"
                              >
                                Remover
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </>
      )}

    </div>
  );
}
