import { useEffect, useState, useCallback, useMemo } from "react";
import api from "../lib/api";
import {
  Ticket,
  Plus,
  XCircle,
  Clock,
  Eye,
  Send,
  Camera,
} from "lucide-react";
import toast from "react-hot-toast";
import { QRCodeCanvas } from "qrcode.react";
import { Link, useSearchParams } from "react-router-dom";
import * as otplib from "otplib";

const { totp } = otplib;

const statusBadge = {
  pending: "badge-yellow",
  paid: "badge-green",
  used: "badge-blue",
  cancelled: "badge-red",
  refunded: "badge-gray",
  active: "badge-green",
};

const statusLabel = {
  pending: "Pendente",
  paid: "Pago",
  used: "Utilizado",
  cancelled: "Cancelado",
  refunded: "Reembolsado",
  active: "Ativo",
};

export default function Tickets() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("");
  const [ticketTypes, setTicketTypes] = useState([]);
  const [batches, setBatches] = useState([]);
  const [commissaries, setCommissaries] = useState([]);
  const [batchFilter, setBatchFilter] = useState("");
  const [commissaryFilter, setCommissaryFilter] = useState("");
  const [selectedTicket, setSelectedTicket] = useState(null);
  const [currentTime, setCurrentTime] = useState(new Date());
  const requestedEventId = searchParams.get("event_id");

  const selectedBatch = useMemo(
    () => batches.find((batch) => String(batch.id) === String(batchFilter)) || null,
    [batches, batchFilter]
  );

  const effectiveBatchFilter = useMemo(
    () => (selectedBatch ? String(selectedBatch.id) : ""),
    [selectedBatch]
  );

  const effectiveEventId = useMemo(() => {
    const availableIds = new Set(events.map((event) => String(event.id)));
    if (eventId && availableIds.has(String(eventId))) {
      return String(eventId);
    }
    if (requestedEventId && availableIds.has(String(requestedEventId))) {
      return String(requestedEventId);
    }
    return events[0] ? String(events[0].id) : "";
  }, [eventId, events, requestedEventId]);

  const resolvedTicketTypeId = useMemo(() => {
    if (selectedBatch?.ticket_type_id) {
      return String(selectedBatch.ticket_type_id);
    }

    if (ticketTypes.length === 1) {
      return String(ticketTypes[0].id);
    }

    return "";
  }, [selectedBatch, ticketTypes]);

  const effectiveCommissaryFilter = useMemo(() => {
    const selectedCommissary = commissaries.find(
      (commissary) => String(commissary.id) === String(commissaryFilter)
    );
    return selectedCommissary ? String(selectedCommissary.id) : "";
  }, [commissaries, commissaryFilter]);

  const dynamicToken = useMemo(() => {
    if (!selectedTicket) return "";
    if (!selectedTicket.totp_secret) return selectedTicket.qr_token || "";
    const secondSeed = currentTime.getSeconds();

    try {
      void secondSeed;
      return `${selectedTicket.qr_token}.${totp.generate(selectedTicket.totp_secret)}`;
    } catch (err) {
      console.error("Erro no motor TOTP:", err);
      return selectedTicket.qr_token || "";
    }
  }, [selectedTicket, currentTime]);

  const timeLeft = useMemo(() => {
    const seconds = currentTime.getSeconds();
    return 30 - (seconds % 30 || 0);
  }, [currentTime]);

  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    api.get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => toast.error("Erro ao carregar eventos."));
  }, []);

  const syncEventQuery = useCallback((nextEventId) => {
    const next = new URLSearchParams(searchParams);
    if (nextEventId) {
      next.set("event_id", String(nextEventId));
    } else {
      next.delete("event_id");
    }
    setSearchParams(next, { replace: true });
  }, [searchParams, setSearchParams]);

  const loadCommercialConfig = useCallback(async () => {
    if (!effectiveEventId) {
      setTicketTypes([]);
      setBatches([]);
      setCommissaries([]);
      return;
    }

    const [typesRes, batchesRes, commissariesRes] = await Promise.allSettled([
      api.get("/tickets/types", { params: { event_id: effectiveEventId } }),
      api.get("/tickets/batches", { params: { event_id: effectiveEventId } }),
      api.get("/tickets/commissaries", { params: { event_id: effectiveEventId } }),
    ]);

    if (typesRes.status === "fulfilled") {
      setTicketTypes(typesRes.value.data?.data || []);
    } else {
      setTicketTypes([]);
      toast.error(typesRes.reason?.response?.data?.message || "Erro ao carregar tipos de ingresso.");
    }

    if (batchesRes.status === "fulfilled") {
      setBatches(batchesRes.value.data?.data || []);
    } else {
      setBatches([]);
      toast.error(batchesRes.reason?.response?.data?.message || "Erro ao carregar lotes comerciais.");
    }

    if (commissariesRes.status === "fulfilled") {
      setCommissaries(commissariesRes.value.data?.data || []);
    } else {
      setCommissaries([]);
      toast.error(commissariesRes.reason?.response?.data?.message || "Erro ao carregar comissários.");
    }
  }, [effectiveEventId]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadCommercialConfig();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadCommercialConfig]);

  const fetchTickets = useCallback(() => {
    setLoading(true);
    const params = {};
    if (effectiveEventId) params.event_id = effectiveEventId;
    if (effectiveBatchFilter) params.ticket_batch_id = effectiveBatchFilter;
    if (effectiveCommissaryFilter) params.commissary_id = effectiveCommissaryFilter;

    api.get("/tickets", { params })
      .then((r) => {
        const data = r.data.data || [];
        const commercialOnly = data.filter((ticket) => {
          const ref = String(ticket?.order_reference || "");
          return !ref.startsWith("EF-GUEST-") && !ref.startsWith("EF-IMP-");
        });
        setTickets(commercialOnly);
        localStorage.setItem("enjoyfun_tickets_cache", JSON.stringify(commercialOnly));
      })
      .catch(() => {
        const cached = localStorage.getItem("enjoyfun_tickets_cache");
        if (cached) {
          const parsed = JSON.parse(cached);
          const commercialOnly = Array.isArray(parsed)
            ? parsed.filter((ticket) => {
                const ref = String(ticket?.order_reference || "");
                return !ref.startsWith("EF-GUEST-") && !ref.startsWith("EF-IMP-");
              })
            : [];
          setTickets(commercialOnly);
          toast("Modo Offline: Usando cache.");
        } else {
          toast.error("Erro ao carregar ingressos.");
        }
      })
      .finally(() => setLoading(false));
  }, [effectiveBatchFilter, effectiveCommissaryFilter, effectiveEventId]);

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchTickets();
    }, 0);
    return () => clearTimeout(timer);
  }, [fetchTickets]);

  const handleEventChange = (nextEventId) => {
    setEventId(nextEventId);
    setBatchFilter("");
    setCommissaryFilter("");
    syncEventQuery(nextEventId);
  };

  const handleQuickSale = async () => {
    if (!effectiveEventId) {
      return toast.error("Selecione um evento.");
    }

    if (ticketTypes.length === 0) {
      return toast.error("Este evento ainda não possui tipos de ingresso cadastrados. Edite o evento e cadastre ao menos um tipo.");
    }

    if (effectiveBatchFilter && !selectedBatch) {
      return toast.error("Selecione um lote válido.");
    }

    if (!resolvedTicketTypeId) {
      return toast.error("Selecione um lote nos filtros para definir o tipo do ingresso.");
    }

    const loadId = toast.loading("Emitindo ingresso...");
    try {
      const payload = {
        event_id: Number(effectiveEventId),
        ticket_type_id: Number(resolvedTicketTypeId),
        ticket_batch_id: effectiveBatchFilter ? Number(effectiveBatchFilter) : null,
        commissary_id: effectiveCommissaryFilter ? Number(effectiveCommissaryFilter) : null,
      };
      const { data } = await api.post("/tickets", payload);
      if (data.success) {
        toast.success("Ingresso gerado!", { id: loadId });
        fetchTickets();
      }
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro na emissão.", { id: loadId });
    }
  };

  const handleTransfer = async (ticketId) => {
    const email = prompt("E-mail do novo titular (precisa ter conta):");
    if (!email) return;
    const name = prompt("Nome completo do novo titular:");
    if (!name) return;

    const loadId = toast.loading("Transferindo...");
    try {
      await api.post(`/tickets/${ticketId}/transfer`, {
        new_owner_email: email,
        new_holder_name: name,
      });
      toast.success("Transferência concluída!", { id: loadId });
      fetchTickets();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro na transferência.", { id: loadId });
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Ticket size={22} className="text-brand" /> Ingressos Comerciais
          </h1>
          <p className="text-gray-500 text-sm mt-1">{tickets.length} ingressos comerciais ativos</p>
        </div>
        <div className="flex flex-wrap gap-3 w-full sm:w-auto">
          <Link
            to="/scanner?mode=portaria"
            state={{ returnTo: "/tickets" }}
            className="btn-outline flex-1 sm:flex-none justify-center"
          >
            <Camera size={18} /> Scanner
          </Link>
          <button onClick={handleQuickSale} className="btn-primary flex-1 sm:flex-none justify-center">
            <Plus size={18} /> Venda Rápida
          </button>
        </div>
      </div>

      <div className="card p-4 space-y-3">
        <p className="text-xs text-gray-500 uppercase tracking-wide">Operação Comercial de Ingressos</p>
        <div className="grid md:grid-cols-3 gap-3">
          <select className="select" name="ticket_event_filter" value={effectiveEventId} onChange={(e) => handleEventChange(e.target.value)}>
            <option value="">Selecione o evento</option>
            {events.map((event) => (
              <option key={event.id} value={event.id}>{event.name}</option>
            ))}
          </select>
          <select className="select" name="ticket_batch_filter" value={effectiveBatchFilter} onChange={(e) => setBatchFilter(e.target.value)} disabled={!effectiveEventId}>
            <option value="">Todos os lotes</option>
            {batches.map((batch) => (
              <option key={batch.id} value={batch.id}>{batch.name}</option>
            ))}
          </select>
          <select className="select" name="ticket_commissary_filter" value={effectiveCommissaryFilter} onChange={(e) => setCommissaryFilter(e.target.value)} disabled={!effectiveEventId}>
            <option value="">Todos os comissários</option>
            {commissaries.map((commissary) => (
              <option key={commissary.id} value={commissary.id}>{commissary.name}</option>
            ))}
          </select>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-20"><div className="spinner w-10 h-10" /></div>
      ) : (
        <div className="table-wrapper">
          <table className="table">
            <thead>
              <tr>
                <th>Titular</th>
                <th>Evento</th>
                <th>Tipo</th>
                <th>Lote</th>
                <th>Comissário</th>
                <th>Status</th>
                <th className="text-right">Ações</th>
              </tr>
            </thead>
            <tbody>
              {tickets.map((ticket) => (
                <tr key={ticket.id} className="hover:bg-white/5 transition-colors">
                  <td>
                    <div className="font-medium text-white">{ticket.holder_name || "Cliente"}</div>
                    <div className="text-[10px] text-gray-500 font-mono">{ticket.order_reference}</div>
                  </td>
                  <td>{ticket.event_name}</td>
                  <td><span className="badge-purple">{ticket.type_name || "Geral"}</span></td>
                  <td className="text-gray-300">{ticket.batch_name || "Sem lote"}</td>
                  <td className="text-gray-300">{ticket.commissary_name || "Sem comissário"}</td>
                  <td><span className={statusBadge[ticket.status]}>{statusLabel[ticket.status]}</span></td>
                  <td className="text-right">
                    <div className="flex justify-end gap-2">
                      {ticket.status === "paid" ? (
                        <button onClick={() => handleTransfer(ticket.id)} title="Transferir Titularidade" className="p-2 hover:bg-white/10 rounded-lg text-gray-400 transition-all">
                          <Send size={18} />
                        </button>
                      ) : null}
                      <button onClick={() => setSelectedTicket(ticket)} className="p-2 hover:text-brand hover:bg-brand-soft rounded-lg text-gray-400 transition-all">
                        <Eye size={20} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {selectedTicket ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/90 backdrop-blur-md">
          <div className="card max-w-sm w-full border-brand/50 text-center space-y-6 p-8 relative overflow-hidden shadow-2xl">
            <div className="absolute top-0 left-0 w-full h-1 bg-brand-gradient"></div>
            <div className="flex justify-between items-center">
              <div className="flex items-center gap-2 text-brand">
                <Clock size={16} className="animate-pulse" />
                <span className="text-xs font-mono">{currentTime.toLocaleTimeString("pt-BR")}</span>
              </div>
              <button onClick={() => setSelectedTicket(null)} className="text-gray-500 hover:text-white">
                <XCircle size={24} />
              </button>
            </div>

            <div className="space-y-1">
              <h3 className="text-xl font-bold text-white uppercase tracking-tight">Ingresso Oficial</h3>
              <p className="text-[10px] text-brand animate-pulse font-bold">ATUALIZA EM {timeLeft}s</p>
            </div>

            <div className="bg-white p-3 rounded-xl inline-block mx-auto shadow-inner border-4 border-brand">
              <QRCodeCanvas value={dynamicToken} size={200} level="H" includeMargin />
            </div>

            <div className="space-y-1">
              <p className="text-white font-bold text-2xl truncate px-2">{selectedTicket.holder_name || "Cliente"}</p>
              <p className="text-brand font-semibold uppercase">{selectedTicket.type_name || "Ingresso Geral"}</p>
              <div className="bg-red-500/10 border border-red-500/20 text-red-400 text-[9px] py-1 px-2 rounded mt-2 font-bold uppercase">
                Prints e fotos são inválidos nesta portaria
              </div>
            </div>

            <div className="pt-4 mt-4 border-t border-gray-800">
              <button onClick={() => setSelectedTicket(null)} className="btn-primary w-full py-3 text-lg font-bold tracking-widest">
                FECHAR
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
