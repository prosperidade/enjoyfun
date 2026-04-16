import { useEffect, useState } from "react";
import { useParams, Link, useNavigate } from "react-router-dom";
import api from "../lib/api";
import { CalendarDays, MapPin, Clock, ArrowLeft, Users, CheckCircle, Layers3, UserRound, Trash2, Pencil, Globe, Package, Map, ExternalLink } from "lucide-react";
import toast from "react-hot-toast";
import { useEventScope } from "../context/EventScopeContext";

export default function EventDetails() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { buildScopedPath } = useEventScope();
  const [event, setEvent] = useState(null);
  const [loading, setLoading] = useState(true);
  const [ticketTypes, setTicketTypes] = useState([]);
  const [batches, setBatches] = useState([]);
  const [commissaries, setCommissaries] = useState([]);

  useEffect(() => {
    api.get(`/events/${id}`)
      .then((r) => setEvent(r.data.data))
      .catch(() => toast.error("Erro ao carregar detalhes do evento."))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => {
    const loadCommercialConfig = async () => {
      const [typesRes, batchesRes, commissariesRes] = await Promise.allSettled([
        api.get("/tickets/types", { params: { event_id: id } }),
        api.get("/tickets/batches", { params: { event_id: id } }),
        api.get("/tickets/commissaries", { params: { event_id: id } }),
      ]);

      setTicketTypes(typesRes.status === "fulfilled" ? typesRes.value.data?.data || [] : []);
      setBatches(batchesRes.status === "fulfilled" ? batchesRes.value.data?.data || [] : []);
      setCommissaries(commissariesRes.status === "fulfilled" ? commissariesRes.value.data?.data || [] : []);

      if (typesRes.status === "rejected") {
        toast.error(typesRes.reason?.response?.data?.message || "Erro ao carregar tipos de ingresso.");
      }
      if (batchesRes.status === "rejected") {
        toast.error(batchesRes.reason?.response?.data?.message || "Erro ao carregar lotes comerciais.");
      }
      if (commissariesRes.status === "rejected") {
        toast.error(commissariesRes.reason?.response?.data?.message || "Erro ao carregar comissários.");
      }
    };

    loadCommercialConfig();
  }, [id]);

  if (loading) return <div className="flex items-center justify-center py-20"><div className="spinner w-10 h-10" /></div>;
  if (!event) return <div className="text-center py-20 text-slate-400">Evento não encontrado.</div>;

  const starts = new Date(event.starts_at).toLocaleString("pt-BR");
  const ends = event.ends_at ? new Date(event.ends_at).toLocaleString("pt-BR") : "Não informado";

  const EVENT_TYPE_LABELS = {
    festival: "Festival", show: "Show", corporate: "Corporativo",
    wedding: "Casamento", graduation: "Formatura", sports_stadium: "Esportivo",
    expo: "Feira", congress: "Congresso", theater: "Teatro",
    sports_gym: "Ginasio", rodeo: "Rodeio", custom: "Customizado",
  };

  const VENUE_TYPE_LABELS = {
    outdoor: "Ar livre", indoor: "Fechado", hybrid: "Hibrido", stadium: "Estadio", arena: "Arena",
  };

  const hasLocation = event.city || event.latitude || event.longitude;
  const hasModules = Array.isArray(event.modules_enabled) && event.modules_enabled.length > 0;
  const mapLinks = [
    { key: "banner_url", label: "Arte do Convite" },
    { key: "map_3d_url", label: "Mapa 3D" },
    { key: "map_image_url", label: "Mapa de Imagem" },
    { key: "map_seating_url", label: "Mapa de Assentos" },
    { key: "map_parking_url", label: "Mapa de Estacionamento" },
  ].filter((m) => event[m.key]);
  const hasMaps = mapLinks.length > 0;

  const getMapDownloadUrl = (ref) => {
    if (!ref) return null;
    if (ref.startsWith('file:')) {
      const fileId = ref.split(':')[1];
      return `/api/organizer-files/${fileId}/download`;
    }
    return ref;
  };

  const handleDeleteEvent = async () => {
    if (!event?.can_delete) return;
    if (!window.confirm("Excluir este evento? Essa ação só é permitida para eventos sem dados vinculados.")) {
      return;
    }

    try {
      await api.delete(`/events/${id}`);
      toast.success("Evento excluído com sucesso.");
      navigate("/events");
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao excluir evento.");
    }
  };

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="flex items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <Link to="/events" className="an-btn an-btn-secondary">
            <ArrowLeft size={16} /> Voltar para Eventos
          </Link>
          <Link to={`/events?edit=${id}`} className="an-btn an-btn-secondary">
            <Pencil size={16} /> Editar Evento
          </Link>
        </div>
        {event?.can_delete ? (
          <button type="button" className="an-btn an-btn-secondary" onClick={handleDeleteEvent}>
            <Trash2 size={16} /> Excluir Evento
          </button>
        ) : null}
      </div>

      <div className="an-card overflow-hidden p-0 border-cyan-500/20">
        <div className="h-48 bg-gradient-to-r from-slate-900 via-cyan-950/50 to-slate-900 relative">
          <div className="absolute bottom-4 left-6 flex items-center gap-3">
            <div className="bg-slate-900 p-3 rounded-lg border border-cyan-500/30 shadow-xl neon-glow-cyan">
              <CalendarDays size={32} className="text-cyan-400" />
            </div>
            <div>
              <h1 className="text-3xl font-bold text-slate-100 font-headline">{event.name}</h1>
              <div className="flex items-center gap-2 mt-2">
                <span className="badge-green inline-block">{String(event.status || "draft").toUpperCase()}</span>
                {event.event_type && EVENT_TYPE_LABELS[event.event_type] && (
                  <span className="text-xs font-medium px-2.5 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                    {EVENT_TYPE_LABELS[event.event_type]}
                  </span>
                )}
              </div>
            </div>
          </div>
        </div>

        <div className="p-6 grid sm:grid-cols-2 gap-6">
          <div className="space-y-4">
            <h3 className="text-lg font-semibold text-slate-200">Informações do Evento</h3>

            {event.venue_name && (
              <div className="flex items-start gap-3 text-slate-300">
                <MapPin className="text-cyan-400 shrink-0" size={20} />
                <div>
                  <p className="font-semibold">{event.venue_name}</p>
                  <p className="text-sm text-slate-500">{event.address}</p>
                </div>
              </div>
            )}

            <div className="flex items-center gap-3 text-slate-300">
              <Clock className="text-cyan-400 shrink-0" size={20} />
              <div>
                <p className="text-sm">Início: <span className="font-semibold text-slate-100">{starts}</span></p>
                <p className="text-sm">Fim: <span className="font-semibold text-slate-100">{ends}</span></p>
              </div>
            </div>

            {event.capacity && (
              <div className="flex items-center gap-3 text-slate-300">
                <Users className="text-cyan-400 shrink-0" size={20} />
                <p>Capacidade: <span className="font-semibold text-slate-100">{parseInt(event.capacity).toLocaleString()} pessoas</span></p>
              </div>
            )}
          </div>

          <div className="space-y-4">
            <h3 className="text-lg font-semibold text-slate-200">Descrição</h3>
            <p className="text-slate-400 text-sm leading-relaxed whitespace-pre-wrap">
              {event.description || "Nenhuma descrição fornecida."}
            </p>

            <div className="mt-6 pt-6 border-t border-slate-800/40">
              <div className="flex items-center gap-2 text-green-400 font-semibold mb-2">
                <CheckCircle size={18} /> Sistema Integrado
              </div>
              <div className="flex flex-wrap gap-2">
                <Link to={buildScopedPath("/bar", id)} className="badge-blue cursor-pointer hover:bg-blue-800">POS Ativo</Link>
                <Link to={buildScopedPath("/tickets", id)} className="badge-purple cursor-pointer hover:bg-purple-800">Bilheteria Linkada</Link>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="grid md:grid-cols-3 gap-4">
        <div className="an-card">
          <div className="flex items-center gap-2 text-slate-400 text-sm mb-2">
            <Layers3 size={16} className="text-cyan-400" />
            Lotes comerciais
          </div>
          <p className="text-2xl font-semibold text-slate-100">{batches.length}</p>
          <p className="text-xs text-slate-500 mt-1">Configurados no fluxo de criação do evento.</p>
        </div>

        <div className="an-card">
          <div className="flex items-center gap-2 text-slate-400 text-sm mb-2">
            <UserRound size={16} className="text-amber-400" />
            Comissários
          </div>
          <p className="text-2xl font-semibold text-slate-100">{commissaries.length}</p>
          <p className="text-xs text-slate-500 mt-1">Vinculados ao evento.</p>
        </div>

        <div className="an-card">
          <div className="flex items-center gap-2 text-slate-400 text-sm mb-2">
            <CalendarDays size={16} className="text-cyan-400" />
            Tipos de ingresso
          </div>
          <p className="text-2xl font-semibold text-slate-100">{ticketTypes.length}</p>
          <p className="text-xs text-slate-500 mt-1">Base comercial disponível para bilheteria.</p>
        </div>
      </div>

      {/* ── Localização ── */}
      {hasLocation && (
        <div className="an-card">
          <div className="flex items-center gap-2 mb-4">
            <Globe size={18} className="text-cyan-400" />
            <h3 className="section-title mb-0">Localização</h3>
          </div>
          <div className="grid sm:grid-cols-2 gap-4 text-sm">
            {event.city && (
              <div>
                <p className="text-slate-500 text-xs mb-1">Cidade</p>
                <p className="text-slate-100">{[event.city, event.state, event.country].filter(Boolean).join(", ")}</p>
              </div>
            )}
            {event.venue_type && (
              <div>
                <p className="text-slate-500 text-xs mb-1">Tipo de local</p>
                <p className="text-slate-100">{VENUE_TYPE_LABELS[event.venue_type] || event.venue_type}</p>
              </div>
            )}
            {event.zip_code && (
              <div>
                <p className="text-slate-500 text-xs mb-1">CEP</p>
                <p className="text-slate-100">{event.zip_code}</p>
              </div>
            )}
            {(event.latitude && event.longitude) && (
              <div>
                <p className="text-slate-500 text-xs mb-1">Coordenadas GPS</p>
                <p className="text-slate-100 font-mono text-xs">{event.latitude}, {event.longitude}</p>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── Modulos Ativos ── */}
      {hasModules && (
        <div className="an-card">
          <div className="flex items-center gap-2 mb-4">
            <Package size={18} className="text-cyan-400" />
            <h3 className="section-title mb-0">Modulos Ativos</h3>
            <span className="text-xs text-slate-500 ml-auto">{event.modules_enabled.length} modulos</span>
          </div>
          <div className="flex flex-wrap gap-2">
            {event.modules_enabled.map((mod) => (
              <span key={mod} className="text-xs font-medium px-2.5 py-1 rounded-full bg-slate-800/40 text-slate-300 border border-slate-700/50">
                {mod.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())}
              </span>
            ))}
          </div>
        </div>
      )}

      {/* ── Mapas ── */}
      {hasMaps && (
        <div className="an-card">
          <div className="flex items-center gap-2 mb-4">
            <Map size={18} className="text-emerald-400" />
            <h3 className="section-title mb-0">Mapas</h3>
          </div>
          <div className="grid sm:grid-cols-2 gap-3">
            {mapLinks.map((m) => (
              <a
                key={m.key}
                href={getMapDownloadUrl(event[m.key])}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-800/40 border border-slate-700/50 text-sm text-slate-300 hover:text-slate-100 hover:border-cyan-500/30 transition-colors"
              >
                <ExternalLink size={14} className="text-cyan-400 shrink-0" />
                {m.label}
              </a>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
