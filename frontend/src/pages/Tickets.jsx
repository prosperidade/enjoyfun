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
import { useEventScope } from "../context/EventScopeContext";
import EmbeddedAIChat from "../components/EmbeddedAIChat";
import Pagination from "../components/Pagination";
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from "../lib/pagination";

const { totp } = otplib;
const PAGE_SIZE = 25;
const CACHE_TTL_MS = 7200000; // 2 hours

function setCacheItem(key, data) {
  localStorage.setItem(key, JSON.stringify({ data, savedAt: Date.now() }));
}

function getCacheItem(key) {
  const raw = localStorage.getItem(key);
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed.savedAt === "number" && Date.now() - parsed.savedAt < CACHE_TTL_MS) {
      return parsed.data;
    }
    // Also handle legacy entries without savedAt wrapper (plain arrays/objects)
    if (parsed && typeof parsed.savedAt === "undefined") {
      // Legacy format — treat as expired, remove
      localStorage.removeItem(key);
      return null;
    }
    // Expired
    localStorage.removeItem(key);
    return null;
  } catch {
    localStorage.removeItem(key);
    return null;
  }
}

const statusBadge = {
  pending: "bg-amber-500/10 text-amber-400",
  paid: "bg-green-500/10 text-green-400",
  used: "bg-cyan-500/10 text-cyan-400",
  cancelled: "bg-red-500/10 text-red-400",
  refunded: "bg-slate-500/15 text-slate-400",
  active: "bg-green-500/10 text-green-400",
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
  const { eventId: scopedEventId, setEventId, buildScopedPath } = useEventScope();
  const [searchParams] = useSearchParams();
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [transferModal, setTransferModal] = useState(null);
  const [events, setEvents] = useState([]);
  const [ticketTypes, setTicketTypes] = useState([]);
  const [availableSectors, setAvailableSectors] = useState([]);
  const [batches, setBatches] = useState([]);
  const [commissaries, setCommissaries] = useState([]);
  const [batchFilter, setBatchFilter] = useState("");
  const [commissaryFilter, setCommissaryFilter] = useState("");
  const [sectorFilter, setSectorFilter] = useState("");
  const [page, setPage] = useState(1);
  const [ticketMeta, setTicketMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE });
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
    if (requestedEventId && availableIds.has(String(requestedEventId))) {
      return String(requestedEventId);
    }
    if (scopedEventId && availableIds.has(String(scopedEventId))) {
      return String(scopedEventId);
    }
    return events[0] ? String(events[0].id) : "";
  }, [events, requestedEventId, scopedEventId]);

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

  const sectors = useMemo(() => {
    if (availableSectors.length > 0) {
      return availableSectors.map((s) => s.name);
    }
    return [...new Set(ticketTypes.filter((t) => t.sector).map((t) => t.sector))];
  }, [ticketTypes, availableSectors]);

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
      .then((r) => {
        const list = r.data.data || [];
        setEvents(list);
        setCacheItem("enjoyfun_tickets_events_cache", list);
      })
      .catch(() => {
        const cached = getCacheItem("enjoyfun_tickets_events_cache");
        if (cached) {
          try { setEvents(cached); } catch { /* ignore */ }
          toast("Modo Offline: eventos carregados do cache.");
        } else {
          toast.error("Sem internet e sem cache. Carregue os eventos quando estiver online.");
        }
      });
  }, []);

  const loadCommercialConfig = useCallback(async () => {
    if (!effectiveEventId) {
      setTicketTypes([]);
      setAvailableSectors([]);
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
      const raw = typesRes.value.data?.data;
      // Handle new format { ticket_types, available_sectors } or legacy flat array
      const data = Array.isArray(raw) ? raw : (raw?.ticket_types || []);
      const sectors = Array.isArray(raw) ? [] : (raw?.available_sectors || []);
      setTicketTypes(data);
      setAvailableSectors(sectors);
      setCacheItem(`tickets_types_${effectiveEventId}`, { ticket_types: data, available_sectors: sectors });
    } else {
      const cached = getCacheItem(`tickets_types_${effectiveEventId}`);
      if (cached) {
        try {
          // Handle both cached formats
          if (Array.isArray(cached)) {
            setTicketTypes(cached);
            setAvailableSectors([]);
          } else {
            setTicketTypes(cached.ticket_types || []);
            setAvailableSectors(cached.available_sectors || []);
          }
        } catch { setTicketTypes([]); setAvailableSectors([]); }
      } else {
        setTicketTypes([]);
        setAvailableSectors([]);
        toast.error("Erro ao carregar tipos de ingresso (offline).");
      }
    }

    if (batchesRes.status === "fulfilled") {
      const data = batchesRes.value.data?.data || [];
      setBatches(data);
      setCacheItem(`tickets_batches_${effectiveEventId}`, data);
    } else {
      const cached = getCacheItem(`tickets_batches_${effectiveEventId}`);
      if (cached) {
        try { setBatches(cached); } catch { setBatches([]); }
      } else {
        setBatches([]);
        toast.error("Erro ao carregar lotes comerciais (offline).");
      }
    }

    if (commissariesRes.status === "fulfilled") {
      const data = commissariesRes.value.data?.data || [];
      setCommissaries(data);
      setCacheItem(`tickets_commissaries_${effectiveEventId}`, data);
    } else {
      const cached = getCacheItem(`tickets_commissaries_${effectiveEventId}`);
      if (cached) {
        try { setCommissaries(cached); } catch { setCommissaries([]); }
      } else {
        setCommissaries([]);
        toast.error("Erro ao carregar comissários (offline).");
      }
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
    const params = { page, per_page: PAGE_SIZE };
    if (effectiveEventId) params.event_id = effectiveEventId;
    if (effectiveBatchFilter) params.ticket_batch_id = effectiveBatchFilter;
    if (effectiveCommissaryFilter) params.commissary_id = effectiveCommissaryFilter;
    if (sectorFilter) params.sector = sectorFilter;

    api.get("/tickets", { params })
      .then((r) => {
        const data = r.data.data || [];
        setTicketMeta(extractPaginationMeta(r.data?.meta, { ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page }));
        const commercialOnly = data.filter((ticket) => {
          const ref = String(ticket?.order_reference || "");
          return !ref.startsWith("EF-GUEST-") && !ref.startsWith("EF-IMP-");
        });
        setTickets(commercialOnly);
        setCacheItem("enjoyfun_tickets_cache", commercialOnly);
      })
      .catch(() => {
        const cached = getCacheItem("enjoyfun_tickets_cache");
        if (cached) {
          const commercialOnly = Array.isArray(cached)
            ? cached.filter((ticket) => {
                const ref = String(ticket?.order_reference || "");
                return !ref.startsWith("EF-GUEST-") && !ref.startsWith("EF-IMP-");
              })
            : [];
          setTickets(commercialOnly);
          setTicketMeta({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: 1 });
          toast("Modo Offline: Usando cache.");
        } else {
          toast.error("Erro ao carregar ingressos.");
        }
      })
      .finally(() => setLoading(false));
  }, [effectiveBatchFilter, effectiveCommissaryFilter, effectiveEventId, sectorFilter, page]);

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchTickets();
    }, 0);
    return () => clearTimeout(timer);
  }, [fetchTickets]);

  const handleEventChange = (nextEventId) => {
    setPage(1);
    setEventId(nextEventId);
    setBatchFilter("");
    setCommissaryFilter("");
    setSectorFilter("");
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

  const handleTransfer = (ticketId) => {
    setTransferModal({ ticketId, email: '', name: '' });
  };

  const submitTransfer = async () => {
    if (!transferModal) return;
    const { ticketId, email, name } = transferModal;
    if (!email || !name) {
      toast.error("Preencha todos os campos.");
      return;
    }

    const loadId = toast.loading("Transferindo...");
    try {
      await api.post(`/tickets/${ticketId}/transfer`, {
        new_owner_email: email,
        new_holder_name: name,
      });
      toast.success("Transferência concluída!", { id: loadId });
      setTransferModal(null);
      fetchTickets();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro na transferência.", { id: loadId });
    }
  };

  return (
    <div className="space-y-8">
      {/* ── Header ─────────────────────────────────────── */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
          <div className="flex items-center gap-3 mb-1">
            <Ticket size={28} className="text-cyan-400" />
            <h1 className="font-headline text-2xl md:text-3xl font-bold text-slate-100 tracking-tight">
              Ingressos Comerciais
            </h1>
          </div>
          <p className="text-slate-400 flex items-center gap-2 text-sm">
            <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
            {ticketMeta.total} ingressos comerciais ativos
          </p>
        </div>
        <div className="flex gap-4 w-full md:w-auto">
          <Link
            to={buildScopedPath("/scanner?mode=portaria", effectiveEventId)}
            state={{ returnTo: buildScopedPath("/tickets", effectiveEventId) }}
            className="flex-1 md:flex-none flex items-center justify-center gap-2 px-5 py-2.5 bg-slate-800/60 hover:bg-slate-700/60 text-slate-100 border border-slate-700/50 hover:border-cyan-500/30 rounded-xl transition-all"
          >
            <Camera size={18} /> Scanner
          </Link>
          <button
            onClick={handleQuickSale}
            className="flex-1 md:flex-none flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-400 hover:shadow-[0_0_25px_rgba(0,240,255,0.35)] active:scale-95 text-slate-950 font-bold rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.2)] transition-all"
          >
            <Plus size={18} /> Venda Rápida
          </button>
        </div>
      </div>

      {/* ── Filter Card ────────────────────────────────── */}
      <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 md:p-7">
        <div className="flex items-center gap-2 mb-5">
          <span className="w-1 h-5 bg-cyan-500 rounded-full" />
          <p className="text-[10px] uppercase tracking-widest text-cyan-400 font-bold">Operação Comercial de Ingressos</p>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
          <div className="space-y-1.5">
            <label className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Evento</label>
            <select className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 rounded-xl text-sm text-slate-200 px-3 py-2.5 transition-all" name="ticket_event_filter" value={effectiveEventId} onChange={(e) => handleEventChange(e.target.value)}>
              <option value="">Selecione o evento</option>
              {events.map((event) => (
                <option key={event.id} value={event.id}>{event.name}</option>
              ))}
            </select>
          </div>
          <div className="space-y-1.5">
            <label className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Lote</label>
            <select className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 rounded-xl text-sm text-slate-200 px-3 py-2.5 transition-all" name="ticket_batch_filter" value={effectiveBatchFilter} onChange={(e) => { setPage(1); setBatchFilter(e.target.value); }} disabled={!effectiveEventId}>
              <option value="">Todos os lotes</option>
              {batches.map((batch) => (
                <option key={batch.id} value={batch.id}>{batch.name}</option>
              ))}
            </select>
          </div>
          <div className="space-y-1.5">
            <label className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Comissário</label>
            <select className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 rounded-xl text-sm text-slate-200 px-3 py-2.5 transition-all" name="ticket_commissary_filter" value={effectiveCommissaryFilter} onChange={(e) => { setPage(1); setCommissaryFilter(e.target.value); }} disabled={!effectiveEventId}>
              <option value="">Todos os comissários</option>
              {commissaries.map((commissary) => (
                <option key={commissary.id} value={commissary.id}>{commissary.name}</option>
              ))}
            </select>
          </div>
          <div className="space-y-1.5">
            <label className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Setor</label>
            <select className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 rounded-xl text-sm text-slate-200 px-3 py-2.5 transition-all" name="ticket_sector_filter" value={sectorFilter} onChange={(e) => { setPage(1); setSectorFilter(e.target.value); }} disabled={!effectiveEventId || sectors.length === 0}>
              <option value="">Todos os setores</option>
              {sectors.map((sector) => (
                <option key={sector} value={sector}>{sector}</option>
              ))}
            </select>
          </div>
        </div>
      </div>

      <EmbeddedAIChat
        surface="tickets"
        title="Assistente de Ingressos"
        description={`${ticketMeta.total} ingressos comerciais`}
        accentColor="cyan"
        suggestions={[
          'Quantos ingressos foram vendidos hoje?',
          'Quais lotes ainda tem disponibilidade?',
          'Resumo de vendas por comissario',
        ]}
      />

      {/* ── Table ──────────────────────────────────────── */}
      {loading ? (
        <div className="flex justify-center py-20"><div className="spinner w-10 h-10" /></div>
      ) : (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-800/40">
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50">Titular</th>
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50">Evento</th>
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50">Tipo</th>
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50">Lote</th>
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50">Comissário</th>
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50">Status</th>
                  <th className="px-5 py-3.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800/50 text-right">Ações</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/30">
                {tickets.map((ticket) => (
                  <tr key={ticket.id} className="hover:bg-slate-800/30 transition-colors">
                    <td className="px-5 py-3.5">
                      <p className="font-medium text-slate-100">{ticket.holder_name || "Cliente"}</p>
                      <p className="text-[10px] text-slate-500 font-mono mt-0.5">{ticket.order_reference}</p>
                    </td>
                    <td className="px-5 py-3.5 text-slate-300">{ticket.event_name}</td>
                    <td className="px-5 py-3.5">
                      <span className="px-2 py-1 bg-purple-500/10 text-purple-400 text-[10px] font-bold rounded uppercase tracking-tight">{ticket.type_name || "Geral"}</span>
                    </td>
                    <td className="px-5 py-3.5 text-slate-400">{ticket.batch_name || "Sem lote"}</td>
                    <td className="px-5 py-3.5 text-slate-400">{ticket.commissary_name || "Sem comissário"}</td>
                    <td className="px-5 py-3.5">
                      <span className={`px-2 py-1 text-[10px] font-bold rounded uppercase ${statusBadge[ticket.status] || "bg-slate-500/15 text-slate-400"}`}>
                        {statusLabel[ticket.status] || ticket.status}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <div className="flex justify-end gap-1">
                        {ticket.status === "paid" ? (
                          <button onClick={() => handleTransfer(ticket.id)} title="Transferir Titularidade" className="p-2 hover:bg-cyan-500/10 rounded-lg text-slate-500 hover:text-cyan-400 transition-all">
                            <Send size={16} />
                          </button>
                        ) : null}
                        <button onClick={() => setSelectedTicket(ticket)} className="p-2 hover:bg-cyan-500/10 rounded-lg text-slate-500 hover:text-cyan-400 transition-all">
                          <Eye size={16} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {!loading && ticketMeta.total_pages > 1 ? (
        <Pagination
          page={ticketMeta.page}
          totalPages={ticketMeta.total_pages}
          onPrev={() => setPage((current) => Math.max(1, current - 1))}
          onNext={() => setPage((current) => Math.min(ticketMeta.total_pages, current + 1))}
        />
      ) : null}

      {/* ── QR Modal ────────────────────────────────────── */}
      {selectedTicket ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-xl">
          <div className="bg-slate-900/95 backdrop-blur-xl border border-cyan-500/30 max-w-md w-full text-center relative overflow-hidden shadow-[0_0_50px_rgba(0,240,255,0.1)] rounded-3xl">
            {/* Decorative gradient line */}
            <div className="h-1 bg-gradient-to-r from-cyan-500 to-cyan-400" />

            <div className="p-8 space-y-6">
              {/* Top bar: clock + close */}
              <div className="flex justify-between items-center">
                <div className="flex items-center gap-2 text-cyan-400">
                  <Clock size={14} className="animate-pulse" />
                  <span className="text-[11px] font-mono">{currentTime.toLocaleTimeString("pt-BR")}</span>
                </div>
                <button onClick={() => setSelectedTicket(null)} className="text-slate-500 hover:text-red-400 transition-colors">
                  <XCircle size={22} />
                </button>
              </div>

              {/* Anti-print warning */}
              <div className="inline-flex items-center gap-1.5 px-3 py-1 bg-red-500/10 text-red-400 rounded-full text-[10px] font-bold uppercase tracking-widest">
                Prints e fotos sao invalidos
              </div>

              {/* Title */}
              <div className="space-y-1">
                <h3 className="font-headline text-2xl font-bold text-slate-100 uppercase tracking-tight">Ingresso Oficial</h3>
                <p className="text-slate-400 text-sm">{selectedTicket.event_name} &bull; {selectedTicket.type_name || "Geral"}</p>
              </div>

              {/* QR Code */}
              <div className="relative inline-block">
                <div className="bg-white p-5 rounded-2xl border-4 border-cyan-500/50 shadow-[0_0_20px_rgba(0,240,255,0.15)]">
                  <QRCodeCanvas value={dynamicToken} size={200} level="H" includeMargin />
                </div>
                <div className="absolute -bottom-3 left-1/2 -translate-x-1/2 bg-slate-900 px-3 py-1 rounded-full border border-cyan-500/30">
                  <p className="text-[10px] font-mono text-cyan-400 flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 bg-cyan-400 rounded-full animate-ping" />
                    ATUALIZA EM {timeLeft}s
                  </p>
                </div>
              </div>

              {/* Holder info */}
              <div className="p-4 bg-slate-800/40 rounded-xl border border-slate-700/50 mt-6">
                <div className="flex justify-between items-center mb-1">
                  <span className="text-[10px] text-slate-500 uppercase tracking-widest">Titular</span>
                  <span className="text-[10px] text-slate-500 uppercase tracking-widest">Tipo</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-sm font-bold text-slate-200 truncate mr-3">{selectedTicket.holder_name || "Cliente"}</span>
                  <span className="text-sm font-medium text-cyan-400 uppercase shrink-0">{selectedTicket.type_name || "Geral"}</span>
                </div>
              </div>

              {/* Close button */}
              <button
                onClick={() => setSelectedTicket(null)}
                className="w-full py-3.5 bg-gradient-to-r from-cyan-500 to-cyan-400 hover:shadow-[0_0_25px_rgba(0,240,255,0.3)] text-slate-950 font-bold rounded-2xl text-lg tracking-widest transition-all active:scale-95"
              >
                FECHAR
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {/* ── Transfer Modal ─────────────────────────────── */}
      {transferModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl p-6 w-full max-w-md shadow-2xl space-y-5">
            <h2 className="font-headline text-lg font-bold text-slate-100 flex items-center gap-2">
              <Send size={18} className="text-cyan-400" />
              Transferir Ingresso
            </h2>
            <div className="space-y-1.5">
              <label className="text-[10px] font-bold text-slate-500 uppercase tracking-widest">E-mail do Destinatário</label>
              <input
                type="email"
                placeholder="maria@email.com"
                value={transferModal.email}
                onChange={(e) => setTransferModal({ ...transferModal, email: e.target.value })}
                className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 rounded-xl text-sm text-slate-200 placeholder-slate-600 px-3 py-2.5 transition-all"
              />
            </div>
            <div className="space-y-1.5">
              <label className="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Nome Completo</label>
              <input
                type="text"
                placeholder="Nome completo do novo titular"
                value={transferModal.name}
                onChange={(e) => setTransferModal({ ...transferModal, name: e.target.value })}
                className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 rounded-xl text-sm text-slate-200 placeholder-slate-600 px-3 py-2.5 transition-all"
              />
            </div>
            <div className="flex gap-3 justify-end pt-2">
              <button
                onClick={() => setTransferModal(null)}
                className="px-4 py-2 border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 hover:text-slate-100 rounded-xl text-sm font-medium transition-all"
              >
                Cancelar
              </button>
              <button
                onClick={submitTransfer}
                className="px-5 py-2 bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-bold rounded-xl text-sm shadow-[0_0_15px_rgba(0,240,255,0.2)] hover:shadow-[0_0_25px_rgba(0,240,255,0.35)] transition-all active:scale-95"
              >
                Transferir
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
