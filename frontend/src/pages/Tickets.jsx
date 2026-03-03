import { useEffect, useState, useCallback } from "react";
import api from "../lib/api";
import {
  Ticket,
  QrCode,
  Plus,
  CheckCircle,
  XCircle,
  Clock,
  Eye,
  Send,
  Search
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
  const [scanMode, setScanMode] = useState(false);
  const [qrInput, setQrInput] = useState("");
  const [scanResult, setScanResult] = useState(null);
  const [scanning, setScanning] = useState(false);
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

  // 4. Busca de Ingressos (Com Cache Offline)
  const fetchTickets = useCallback(() => {
    setLoading(true);
    const params = { per_page: 50 };
    if (eventId) params.event_id = eventId;

    api.get("/tickets", { params })
      .then((r) => {
        const data = r.data.data || [];
        setTickets(data);
        localStorage.setItem("enjoyfun_tickets_cache", JSON.stringify(data));
      })
      .catch(() => {
        const cached = localStorage.getItem("enjoyfun_tickets_cache");
        if (cached) {
          setTickets(JSON.parse(cached));
          toast("Modo Offline: Usando cache.");
        } else {
          toast.error("Erro ao carregar ingressos.");
        }
      })
      .finally(() => setLoading(false));
  }, [eventId]);

  useEffect(() => {
    fetchTickets();
  }, [fetchTickets]);

  // 5. Venda Rápida
  const handleQuickSale = async () => {
    const targetEventId = eventId || (events[0] ? events[0].id : null);
    if (!targetEventId) return toast.error("Selecione um evento!");

    const loadId = toast.loading("Emitindo ingresso...");
    try {
      const payload = { event_id: targetEventId, ticket_type_id: 1, price: 150.0 };
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

  // 7. Validação por Scanner (Portaria)
  const handleScan = async (e) => {
    if (e) e.preventDefault();
    const token = qrInput.trim();
    if (!token) return;

    setScanning(true);
    setScanResult(null);

    try {
      // Bate na rota de validação dinâmica (Motor 3.0)
      const { data } = await api.post("/tickets/validate", { dynamic_token: token });
      setScanResult(data);
      if (data.success) {
        toast.success(data.message);
        setQrInput("");
        fetchTickets();
      }
    } catch (err) {
      const msg = err.response?.data?.message || "QR Code Expirado (PRINT)";
      setScanResult({ success: false, message: msg });
      toast.error(msg);
    } finally {
      setScanning(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* HEADER */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Ticket size={22} className="text-purple-400" /> Ingressos
          </h1>
          <p className="text-gray-500 text-sm mt-1">{tickets.length} ingressos ativos</p>
        </div>
        <div className="flex gap-2">
          <button onClick={handleQuickSale} className="btn-primary flex items-center gap-2">
            <Plus size={16} /> Venda Rápida
          </button>
          <button onClick={() => setScanMode(!scanMode)} className={scanMode ? "btn-secondary" : "btn-outline"}>
            <QrCode size={16} /> {scanMode ? "Fechar Scanner" : "Scanner QR"}
          </button>
        </div>
      </div>

      {/* ÁREA DO SCANNER (PORTARIA) */}
      {scanMode && (
        <div className="card border-purple-800/40 animate-in zoom-in duration-300">
          <h2 className="section-title">🔍 Validar Portaria (Anti-Print)</h2>
          <form onSubmit={handleScan} className="flex gap-3">
            <input className="input flex-1" placeholder="Bipe o código aqui..." value={qrInput} onChange={(e) => setQrInput(e.target.value)} autoFocus />
            <button type="submit" disabled={scanning} className="btn-primary">
              {scanning ? <span className="spinner w-4 h-4" /> : "Validar"}
            </button>
          </form>
          {scanResult && (
            <div className={`mt-4 rounded-xl p-4 border flex items-start gap-3 ${scanResult.success ? "bg-green-900/20 border-green-800" : "bg-red-900/20 border-red-800"}`}>
              {scanResult.success ? <CheckCircle size={20} className="text-green-400" /> : <XCircle size={20} className="text-red-400" />}
              <p className={`font-semibold ${scanResult.success ? "text-green-400" : "text-red-400"}`}>{scanResult.message}</p>
            </div>
          )}
        </div>
      )}

      {/* FILTROS */}
      <div className="flex gap-3">
        <select className="select w-auto min-w-[200px]" value={eventId} onChange={(e) => setEventId(e.target.value)}>
          <option value="">Todos os eventos</option>
          {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
        </select>
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
                <th>Status</th>
                <th className="text-right">Ações</th>
              </tr>
            </thead>
            <tbody>
              {tickets.map((t) => (
                <tr key={t.id} className="hover:bg-white/5 transition-colors">
                  <td>
                    <div className="font-medium text-white">{t.holder_name || "Participante"}</div>
                    <div className="text-[10px] text-gray-500 font-mono">{t.order_reference}</div>
                  </td>
                  <td>{t.event_name}</td>
                  <td><span className="badge-purple">{t.type_name || "Geral"}</span></td>
                  <td><span className={statusBadge[t.status]}>{statusLabel[t.status]}</span></td>
                  <td className="text-right">
                    <div className="flex justify-end gap-2">
                      {t.status === 'paid' && (
                        <button onClick={() => handleTransfer(t.id)} title="Transferir Titularidade" className="p-2 hover:bg-white/10 rounded-lg text-gray-400 transition-all"><Send size={18} /></button>
                      )}
                      <button onClick={() => setSelectedTicket(t)} className="p-2 hover:bg-purple-500/20 rounded-lg text-purple-400 transition-all"><Eye size={20} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* MODAL DO INGRESSO DINÂMICO (VIVO) */}
      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/90 backdrop-blur-md">
          <div className="card max-w-sm w-full border-purple-500/50 text-center space-y-6 p-8 relative overflow-hidden shadow-2xl">
            <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-purple-500 to-transparent"></div>
            <div className="flex justify-between items-center">
              <div className="flex items-center gap-2 text-purple-400">
                <Clock size={16} className="animate-pulse" />
                <span className="text-xs font-mono">{currentTime.toLocaleTimeString("pt-BR")}</span>
              </div>
              <button onClick={() => setSelectedTicket(null)} className="text-gray-500 hover:text-white"><XCircle size={24} /></button>
            </div>

            <div className="space-y-1">
              <h3 className="text-xl font-bold text-white uppercase tracking-tight">Ingresso Oficial</h3>
              <p className="text-[10px] text-purple-400 animate-pulse font-bold">ATUALIZA EM {timeLeft}s</p>
            </div>

            <div className="bg-white p-3 rounded-xl inline-block mx-auto shadow-inner border-4 border-purple-500">
                <QRCodeCanvas value={dynamicToken} size={200} level={"H"} includeMargin={true} />
            </div>

            <div className="space-y-1">
              <p className="text-white font-bold text-2xl truncate px-2">{selectedTicket.holder_name || "Participante"}</p>
              <p className="text-purple-400 font-semibold uppercase">{selectedTicket.type_name || "Ingresso Geral"}</p>
              <div className="bg-red-500/10 border border-red-500/20 text-red-400 text-[9px] py-1 px-2 rounded mt-2 font-bold uppercase">
                 Prints e Fotos são inválidos nesta portaria
              </div>
            </div>

            <button onClick={() => setSelectedTicket(null)} className="btn-primary w-full py-4 text-lg font-bold">VOLTAR</button>
          </div>
        </div>
      )}
    </div>
  );
}