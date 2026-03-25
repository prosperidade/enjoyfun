import { useEffect, useState } from "react";
import { useParams, Link, useNavigate } from "react-router-dom";
import api from "../lib/api";
import { CalendarDays, MapPin, Clock, ArrowLeft, Users, CheckCircle, Layers3, UserRound, Trash2, Pencil } from "lucide-react";
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
  if (!event) return <div className="text-center py-20 text-gray-400">Evento não encontrado.</div>;

  const starts = new Date(event.starts_at).toLocaleString("pt-BR");
  const ends = event.ends_at ? new Date(event.ends_at).toLocaleString("pt-BR") : "Não informado";

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
          <Link to="/events" className="btn-outline inline-flex">
            <ArrowLeft size={16} /> Voltar para Eventos
          </Link>
          <Link to={`/events?edit=${id}`} className="btn-outline inline-flex">
            <Pencil size={16} /> Editar Evento
          </Link>
        </div>
        {event?.can_delete ? (
          <button type="button" className="btn-outline inline-flex" onClick={handleDeleteEvent}>
            <Trash2 size={16} /> Excluir Evento
          </button>
        ) : null}
      </div>

      <div className="card overflow-hidden p-0 border-purple-800/40">
        <div className="h-48 bg-gradient-to-r from-purple-900 to-indigo-900 relative">
          <div className="absolute bottom-4 left-6 flex items-center gap-3">
            <div className="bg-gray-900 p-3 rounded-lg border border-purple-500 shadow-xl">
              <CalendarDays size={32} className="text-purple-400" />
            </div>
            <div>
              <h1 className="text-3xl font-bold text-white shadow-sm">{event.name}</h1>
              <span className="badge-green mt-2 inline-block">{String(event.status || "draft").toUpperCase()}</span>
            </div>
          </div>
        </div>

        <div className="p-6 grid sm:grid-cols-2 gap-6">
          <div className="space-y-4">
            <h3 className="section-title">Informações do Evento</h3>

            {event.venue_name && (
              <div className="flex items-start gap-3 text-gray-300">
                <MapPin className="text-purple-400 shrink-0" size={20} />
                <div>
                  <p className="font-semibold">{event.venue_name}</p>
                  <p className="text-sm text-gray-500">{event.address}</p>
                </div>
              </div>
            )}

            <div className="flex items-center gap-3 text-gray-300">
              <Clock className="text-purple-400 shrink-0" size={20} />
              <div>
                <p className="text-sm">Início: <span className="font-semibold text-white">{starts}</span></p>
                <p className="text-sm">Fim: <span className="font-semibold text-white">{ends}</span></p>
              </div>
            </div>

            {event.capacity && (
              <div className="flex items-center gap-3 text-gray-300">
                <Users className="text-purple-400 shrink-0" size={20} />
                <p>Capacidade: <span className="font-semibold text-white">{parseInt(event.capacity).toLocaleString()} pessoas</span></p>
              </div>
            )}
          </div>

          <div className="space-y-4">
            <h3 className="section-title">Descrição</h3>
            <p className="text-gray-400 text-sm leading-relaxed whitespace-pre-wrap">
              {event.description || "Nenhuma descrição fornecida."}
            </p>

            <div className="mt-6 pt-6 border-t border-gray-800">
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
        <div className="card">
          <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
            <Layers3 size={16} className="text-cyan-400" />
            Lotes comerciais
          </div>
          <p className="text-2xl font-semibold text-white">{batches.length}</p>
          <p className="text-xs text-gray-500 mt-1">Configurados no fluxo de criação do evento.</p>
        </div>

        <div className="card">
          <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
            <UserRound size={16} className="text-amber-400" />
            Comissários
          </div>
          <p className="text-2xl font-semibold text-white">{commissaries.length}</p>
          <p className="text-xs text-gray-500 mt-1">Vinculados ao evento.</p>
        </div>

        <div className="card">
          <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
            <CalendarDays size={16} className="text-purple-400" />
            Tipos de ingresso
          </div>
          <p className="text-2xl font-semibold text-white">{ticketTypes.length}</p>
          <p className="text-xs text-gray-500 mt-1">Base comercial disponível para bilheteria.</p>
        </div>
      </div>
    </div>
  );
}
