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
} from "lucide-react";
import toast from "react-hot-toast";
import { QRCodeCanvas } from "qrcode.react";

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

  // Relógio Anti-fraude
  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  // Carregar eventos para o filtro
  useEffect(() => {
    api
      .get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => console.error("Erro ao carregar eventos"));
  }, []);

  // Busca de ingressos
  const fetchTickets = useCallback(() => {
    setLoading(true);
    const params = { per_page: 50 };
    if (eventId) params.event_id = eventId;

    api
      .get("/tickets", { params })
      .then((r) => {
        const data = r.data.data || [];
        setTickets(data);
        localStorage.setItem("enjoyfun_tickets_cache", JSON.stringify(data));
      })
      .catch(() => {
        const cached = localStorage.getItem("enjoyfun_tickets_cache");
        if (cached) {
          setTickets(JSON.parse(cached));
          toast("Modo Offline: Usando cache local.");
        } else {
          toast.error("Erro ao carregar ingressos.");
        }
      })
      .finally(() => setLoading(false));
  }, [eventId]);

  useEffect(() => {
    fetchTickets();
  }, [fetchTickets]);

  // Venda Rápida
  const handleQuickSale = async () => {
    const targetEventId = eventId || (events[0] ? events[0].id : null);
    if (!targetEventId) return toast.error("Selecione um evento primeiro!");

    const loadId = toast.loading("Emitindo ingresso...");
    try {
      const payload = {
        event_id: targetEventId,
        ticket_type_id: 1,
        price: 150.0,
      };
      const { data } = await api.post("/tickets", payload);
      if (data.success) {
        toast.success("Ingresso gerado!", { id: loadId });
        setTickets((prev) => [data.data, ...prev]);
        setSelectedTicket(data.data);
      }
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro na conexão.", {
        id: loadId,
      });
    }
  };

  // Validação (Scan ou Manual)
  const handleScan = async (e) => {
    if (e) e.preventDefault();
    const token = qrInput.trim();
    if (!token) return;

    setScanning(true);
    setScanResult(null);

    try {
      // O backend agora aceita tanto o QR Token quanto a Referência EF-...
      const { data } = await api.post(`/tickets/${token}/validate`);
      setScanResult(data);

      if (data.success) {
        toast.success("✅ Acesso liberado!");
        setQrInput(""); // Limpa para o próximo scan
        fetchTickets(); // Atualiza a lista
      }
    } catch (err) {
      const msg = err.response?.data?.message || "Erro ao validar.";
      toast.error(msg);
      setScanResult({ success: false, message: msg });
    } finally {
      setScanning(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Ticket size={22} className="text-purple-400" /> Ingressos
          </h1>
          <p className="text-gray-500 text-sm mt-1">
            {tickets.length} ingresso(s) registrados
          </p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={handleQuickSale}
            className="btn-primary flex items-center gap-2"
          >
            <Plus size={16} /> Venda Rápida
          </button>
          <button
            onClick={() => setScanMode(!scanMode)}
            className={scanMode ? "btn-secondary" : "btn-outline"}
          >
            <QrCode size={16} /> {scanMode ? "Fechar Scanner" : "Scanner QR"}
          </button>
        </div>
      </div>

      {scanMode && (
        <div className="card border-purple-800/40 animate-in fade-in zoom-in duration-300">
          <h2 className="section-title">🔍 Validar Ingresso</h2>
          <form onSubmit={handleScan} className="flex gap-3">
            <input
              className="input flex-1"
              placeholder="Escaneie ou digite a referência (EF-...)"
              value={qrInput}
              onChange={(e) => setQrInput(e.target.value)}
              autoFocus
            />
            <button type="submit" disabled={scanning} className="btn-primary">
              {scanning ? <span className="spinner w-4 h-4" /> : "Validar"}
            </button>
          </form>
          {scanResult && (
            <div
              className={`mt-4 rounded-xl p-4 border flex items-start gap-3 ${scanResult.success ? "bg-green-900/20 border-green-800" : "bg-red-900/20 border-red-800"}`}
            >
              {scanResult.success ? (
                <CheckCircle size={20} className="text-green-400 mt-0.5" />
              ) : (
                <XCircle size={20} className="text-red-400 mt-0.5" />
              )}
              <p
                className={`font-semibold ${scanResult.success ? "text-green-400" : "text-red-400"}`}
              >
                {scanResult.message}
              </p>
            </div>
          )}
        </div>
      )}

      <div className="flex gap-3">
        <select
          className="select w-auto min-w-[200px]"
          value={eventId}
          onChange={(e) => setEventId(e.target.value)}
        >
          <option value="">Todos os eventos</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.name}
            </option>
          ))}
        </select>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-20">
          <div className="spinner w-10 h-10" />
        </div>
      ) : (
        <div className="table-wrapper">
          <table className="table">
            <thead>
              <tr>
                <th>Titular</th>
                <th>Evento</th>
                <th>Tipo</th>
                <th>Valor</th>
                <th>Status</th>
                <th className="text-right">Ações</th>
              </tr>
            </thead>
            <tbody>
              {tickets.map((t) => (
                <tr key={t.id} className="hover:bg-white/5 transition-colors">
                  <td>
                    <div className="font-medium text-white">
                      {t.holder_name || "Participante"}
                    </div>
                    <div className="text-xs text-gray-500 font-mono">
                      {t.order_reference}
                    </div>
                  </td>
                  <td>{t.event_name}</td>
                  <td>
                    <span className="badge-purple">
                      {t.type_name || "Geral"}
                    </span>
                  </td>
                  <td>R$ {parseFloat(t.price_paid || 0).toFixed(2)}</td>
                  <td>
                    <span className={statusBadge[t.status] || "badge-gray"}>
                      {statusLabel[t.status] || t.status}
                    </span>
                  </td>
                  <td className="text-right">
                    <button
                      onClick={() => setSelectedTicket(t)}
                      className="p-2 hover:bg-purple-500/20 rounded-lg text-purple-400 transition-all"
                    >
                      <Eye size={20} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/90 backdrop-blur-md animate-in fade-in">
          <div className="card max-w-sm w-full border-purple-500/50 text-center space-y-6 p-8 relative overflow-hidden shadow-2xl">
            <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-purple-500 to-transparent"></div>
            <div className="flex justify-between items-center">
              <div className="flex items-center gap-2 text-purple-400">
                <Clock size={16} className="animate-pulse" />
                <span className="text-xs font-mono">
                  {currentTime.toLocaleTimeString("pt-BR")}
                </span>
              </div>
              <button
                onClick={() => setSelectedTicket(null)}
                className="text-gray-500 hover:text-white transition-colors"
              >
                <XCircle size={24} />
              </button>
            </div>

            <div className="space-y-2">
              <h3 className="text-xl font-bold text-white uppercase tracking-tight">
                Ingresso Oficial
              </h3>
              <p className="text-xs text-gray-400 italic">
                Válido apenas com relógio em movimento
              </p>
            </div>

            <div className="bg-white p-3 rounded-xl inline-block mx-auto shadow-inner">
              {selectedTicket?.qr_token ? (
                <QRCodeCanvas
                  value={selectedTicket.qr_token}
                  size={200}
                  level={"H"}
                  includeMargin={true}
                />
              ) : (
                <div className="w-[200px] h-[200px] flex items-center justify-center text-gray-400 italic bg-gray-100 rounded-lg">
                  Gerando QR Code...
                </div>
              )}
            </div>

            <div className="space-y-1">
              <p className="text-white font-bold text-2xl truncate px-2">
                {selectedTicket.holder_name || "Participante"}
              </p>
              <p className="text-purple-400 font-semibold">
                {selectedTicket.type_name || "Geral"}
              </p>
              <div className="pt-2">
                <span className="bg-white/5 border border-white/10 px-3 py-1 rounded-full text-[10px] font-mono text-gray-400 uppercase">
                  Ref: {selectedTicket.order_reference}
                </span>
              </div>
            </div>

            <button
              onClick={() => setSelectedTicket(null)}
              className="btn-primary w-full py-4 text-lg font-bold"
            >
              FECHAR
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
