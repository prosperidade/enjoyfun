import { useEffect, useState, useCallback } from "react";
import api from "../lib/api";
import {
  Ticket,
  Plus,
  XCircle,
  Clock,
  Eye,
  Send
} from "lucide-react";
import toast from "react-hot-toast";
import { QRCodeCanvas } from "qrcode.react";
import * as otplib from 'otplib';

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
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("");
  const [ticketTypes, setTicketTypes] = useState([]);
  const [batches, setBatches] = useState([]);
  const [commissaries, setCommissaries] = useState([]);
  const [ticketTypeId, setTicketTypeId] = useState("");
  const [batchFilter, setBatchFilter] = useState("");
  const [commissaryFilter, setCommissaryFilter] = useState("");
  const [batchForSale, setBatchForSale] = useState("");
  const [commissaryForSale, setCommissaryForSale] = useState("");
  const [selectedTicket, setSelectedTicket] = useState(null);
  const [currentTime, setCurrentTime] = useState(new Date());
  
  // Estados do Motor Dinâmico (Anti-Print)
  const [dynamicToken, setDynamicToken] = useState("");
  const [timeLeft, setTimeLeft] = useState(30);

  // 1. Relógio em tempo real
  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  // 2. Motor de Rotação do QR Code (Só ativa quando o modal abre)
  useEffect(() => {
    if (!selectedTicket || !selectedTicket.totp_secret) {
      setDynamicToken(selectedTicket?.qr_token || "");
      return;
    }

    const generateLoop = () => {
      try {
        const code = totp.generate(selectedTicket.totp_secret);
        // Formato da URL Assinada: TOKEN_FIXO.CODIGO_DINAMICO
        setDynamicToken(`${selectedTicket.qr_token}.${code}`);
        setTimeLeft(30);
      } catch (err) {
        console.error("Erro no motor TOTP:", err);
      }
    };

    generateLoop();
    const rotator = setInterval(generateLoop, 30000);
    const countdown = setInterval(() => {
      setTimeLeft((prev) => (prev <= 1 ? 30 : prev - 1));
    }, 1000);

    return () => {
      clearInterval(rotator);
      clearInterval(countdown);
    };
  }, [selectedTicket]);

  // 3. Carregar Eventos
  useEffect(() => {
    api.get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => console.error("Erro ao carregar eventos"));
  }, []);

  const loadCommercialConfig = useCallback(async () => {
    if (!eventId) {
      setTicketTypes([]);
      setBatches([]);
      setCommissaries([]);
      setTicketTypeId("");
      setBatchForSale("");
      setCommissaryForSale("");
      return;
    }

    const [typesRes, batchesRes, commissariesRes] = await Promise.allSettled([
      api.get("/tickets/types", { params: { event_id: eventId } }),
      api.get("/tickets/batches", { params: { event_id: eventId } }),
      api.get("/tickets/commissaries", { params: { event_id: eventId } }),
    ]);

    if (typesRes.status === "fulfilled") {
      const types = typesRes.value.data?.data || [];
      setTicketTypes(types);
      setTicketTypeId((prev) => prev || (types[0]?.id ? String(types[0].id) : ""));
    } else {
      setTicketTypes([]);
      toast.error(typesRes.reason?.response?.data?.message || "Erro ao carregar tipos de ingresso.");
    }

    setBatches(
      batchesRes.status === "fulfilled"
        ? batchesRes.value.data?.data || []
        : []
    );
    setCommissaries(
      commissariesRes.status === "fulfilled"
        ? commissariesRes.value.data?.data || []
        : []
    );
  }, [eventId]);

  useEffect(() => {
    if (!eventId && events.length > 0) {
      setEventId(String(events[0].id));
    }
  }, [events, eventId]);

  useEffect(() => {
    loadCommercialConfig();
  }, [loadCommercialConfig]);

  // 4. Busca de Ingressos (Com Cache Offline)
  const fetchTickets = useCallback(() => {
    setLoading(true);
    const params = {};
    if (eventId) params.event_id = eventId;
    if (batchFilter) params.ticket_batch_id = batchFilter;
    if (commissaryFilter) params.commissary_id = commissaryFilter;

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
  }, [eventId, batchFilter, commissaryFilter]);

  useEffect(() => {
    fetchTickets();
  }, [fetchTickets]);

  // 5. Venda Rápida
  const handleQuickSale = async () => {
    const targetEventId = eventId || (events[0] ? events[0].id : null);
    if (!targetEventId) return toast.error("Selecione um evento!");
    if (!ticketTypeId) return toast.error("Selecione um tipo de ingresso.");

    const loadId = toast.loading("Emitindo ingresso...");
    try {
      const payload = {
        event_id: Number(targetEventId),
        ticket_type_id: Number(ticketTypeId),
        ticket_batch_id: batchForSale ? Number(batchForSale) : null,
        commissary_id: commissaryForSale ? Number(commissaryForSale) : null,
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

  // 6. Transferência Nominal
  const handleTransfer = async (ticketId) => {
    const email = prompt("E-mail do novo titular (precisa ter conta):");
    if (!email) return;
    const name = prompt("Nome completo do novo titular:");
    if (!name) return;

    const loadId = toast.loading("Transferindo...");
    try {
      await api.post(`/tickets/${ticketId}/transfer`, { 
        new_owner_email: email, 
        new_holder_name: name 
      });
      toast.success("Transferência concluída!", { id: loadId });
      fetchTickets();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro na transferência.", { id: loadId });
    }
  };

  return (
    <div className="space-y-6">
      {/* HEADER CORRIGIDO: Botões alinhados e responsivos */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Ticket size={22} className="text-brand" /> Ingressos Comerciais
          </h1>
          <p className="text-gray-500 text-sm mt-1">{tickets.length} ingressos comerciais ativos</p>
        </div>
        <div className="flex flex-wrap gap-3 w-full sm:w-auto">
          <button onClick={handleQuickSale} className="btn-primary flex-1 sm:flex-none justify-center">
            <Plus size={18} /> Venda Rápida
          </button>
        </div>
      </div>

      {/* FILTROS */}
      <div className="card p-4 space-y-3">
        <p className="text-xs text-gray-500 uppercase tracking-wide">Operação Comercial de Ingressos</p>
        <div className="grid md:grid-cols-3 gap-3">
          <select className="select" value={eventId} onChange={(e) => setEventId(e.target.value)}>
            <option value="">Selecione o evento</option>
            {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
          </select>
          <select className="select" value={batchFilter} onChange={(e) => setBatchFilter(e.target.value)} disabled={!eventId}>
            <option value="">Filtro por lote (todos)</option>
            {batches.map((batch) => <option key={batch.id} value={batch.id}>{batch.name}</option>)}
          </select>
          <select className="select" value={commissaryFilter} onChange={(e) => setCommissaryFilter(e.target.value)} disabled={!eventId}>
            <option value="">Filtro por comissário (todos)</option>
            {commissaries.map((commissary) => <option key={commissary.id} value={commissary.id}>{commissary.name}</option>)}
          </select>
        </div>

        <div className="grid md:grid-cols-3 gap-3">
          <select className="select" value={ticketTypeId} onChange={(e) => setTicketTypeId(e.target.value)} disabled={!eventId}>
            <option value="">Tipo para venda rápida</option>
            {ticketTypes.map((tt) => <option key={tt.id} value={tt.id}>{tt.name}</option>)}
          </select>
          <select className="select" value={batchForSale} onChange={(e) => setBatchForSale(e.target.value)} disabled={!eventId}>
            <option value="">Lote na venda (opcional)</option>
            {batches.map((batch) => <option key={batch.id} value={batch.id}>{batch.name}</option>)}
          </select>
          <select className="select" value={commissaryForSale} onChange={(e) => setCommissaryForSale(e.target.value)} disabled={!eventId}>
            <option value="">Comissário na venda (opcional)</option>
            {commissaries.map((commissary) => <option key={commissary.id} value={commissary.id}>{commissary.name}</option>)}
          </select>
        </div>
      </div>

      {/* TABELA DE REGISTROS */}
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
              {tickets.map((t) => (
                <tr key={t.id} className="hover:bg-white/5 transition-colors">
                  <td>
                    <div className="font-medium text-white">{t.holder_name || "Cliente"}</div>
                    <div className="text-[10px] text-gray-500 font-mono">{t.order_reference}</div>
                  </td>
                  <td>{t.event_name}</td>
                  <td><span className="badge-purple">{t.type_name || "Geral"}</span></td>
                  <td className="text-gray-300">{t.batch_name || 'Sem lote'}</td>
                  <td className="text-gray-300">{t.commissary_name || 'Sem comissário'}</td>
                  <td><span className={statusBadge[t.status]}>{statusLabel[t.status]}</span></td>
                  <td className="text-right">
                    <div className="flex justify-end gap-2">
                      {t.status === 'paid' && (
                        <button onClick={() => handleTransfer(t.id)} title="Transferir Titularidade" className="p-2 hover:bg-white/10 rounded-lg text-gray-400 transition-all"><Send size={18} /></button>
                      )}
                      <button onClick={() => setSelectedTicket(t)} className="p-2 hover:text-brand hover:bg-brand-soft rounded-lg text-gray-400 transition-all"><Eye size={20} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* MODAL DO INGRESSO DINÂMICO (VIVO) - CORRIGIDO O BOTÃO FECHAR */}
      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/90 backdrop-blur-md">
          <div className="card max-w-sm w-full border-brand/50 text-center space-y-6 p-8 relative overflow-hidden shadow-2xl">
            <div className="absolute top-0 left-0 w-full h-1 bg-brand-gradient"></div>
            <div className="flex justify-between items-center">
              <div className="flex items-center gap-2 text-brand">
                <Clock size={16} className="animate-pulse" />
                <span className="text-xs font-mono">{currentTime.toLocaleTimeString("pt-BR")}</span>
              </div>
              <button onClick={() => setSelectedTicket(null)} className="text-gray-500 hover:text-white"><XCircle size={24} /></button>
            </div>

            <div className="space-y-1">
              <h3 className="text-xl font-bold text-white uppercase tracking-tight">Ingresso Oficial</h3>
              <p className="text-[10px] text-brand animate-pulse font-bold">ATUALIZA EM {timeLeft}s</p>
            </div>

            <div className="bg-white p-3 rounded-xl inline-block mx-auto shadow-inner border-4 border-brand">
                <QRCodeCanvas value={dynamicToken} size={200} level={"H"} includeMargin={true} />
            </div>

            <div className="space-y-1">
              <p className="text-white font-bold text-2xl truncate px-2">{selectedTicket.holder_name || "Cliente"}</p>
              <p className="text-brand font-semibold uppercase">{selectedTicket.type_name || "Ingresso Geral"}</p>
              <div className="bg-red-500/10 border border-red-500/20 text-red-400 text-[9px] py-1 px-2 rounded mt-2 font-bold uppercase">
                  Prints e Fotos são inválidos nesta portaria
              </div>
            </div>

            {/* BOTÃO CORRIGIDO: "FECHAR" com espaçamento correto */}
            <div className="pt-4 mt-4 border-t border-gray-800">
              <button onClick={() => setSelectedTicket(null)} className="btn-primary w-full py-3 text-lg font-bold tracking-widest">
                FECHAR
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
