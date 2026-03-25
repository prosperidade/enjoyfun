import { useState, useEffect } from "react";
import { Download, FileText, CheckCircle } from "lucide-react";
import api from "../lib/api";
import toast from "react-hot-toast";

const EXPORT_TYPES = [
  {
    value: "payables",
    label: "Contas a Pagar",
    description: "Todas as contas com status, fornecedor e datas",
    icon: FileText,
  },
  {
    value: "payments",
    label: "Pagamentos",
    description: "Histórico de baixas e estornos",
    icon: FileText,
  },
  {
    value: "by-artist",
    label: "Por Artista",
    description: "Custo total comprometido e pago por artista",
    icon: FileText,
  },
  {
    value: "closing",
    label: "Fechamento Completo",
    description: "Todas as contas com seus pagamentos associados",
    icon: FileText,
  },
];

function downloadCSV(rows, filename) {
  if (!rows || rows.length === 0) {
    toast.error("Nenhum dado para exportar.");
    return;
  }
  const headers = Object.keys(rows[0]);
  const csvLines = [
    headers.join(";"),
    ...rows.map((r) =>
      headers.map((h) => {
        const v = r[h] ?? "";
        return typeof v === "string" && (v.includes(";") || v.includes("\n"))
          ? `"${v.replace(/"/g, '""')}"`
          : v;
      }).join(";")
    ),
  ];
  const blob = new Blob(["\uFEFF" + csvLines.join("\n")], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
  toast.success(`Arquivo "${filename}" baixado!`);
}

export default function EventFinanceExport() {
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("");
  const [exporting, setExporting] = useState(null);
  const [filterStatus, setFilterStatus] = useState("");

  useEffect(() => {
    api.get("/events").then((r) => setEvents(r.data.data || [])).catch(() => {});
  }, []);

  const handleExport = async (type) => {
    if (!eventId) {
      toast.error("Selecione um evento antes de exportar.");
      return;
    }
    setExporting(type);
    try {
      const body = { event_id: parseInt(eventId) };
      if (type === "payables" && filterStatus) body.status = filterStatus;

      const res = await api.post(`/event-finance/exports/${type}`, body);
      const rows = res.data.data?.rows || [];
      const event = events.find((e) => String(e.id) === String(eventId));
      const eventName = event?.name?.replace(/[^a-z0-9]/gi, "_").toLowerCase() || "evento";
      downloadCSV(rows, `${type}_${eventName}_${new Date().toISOString().split("T")[0]}.csv`);
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao exportar.");
    } finally {
      setExporting(null);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title flex items-center gap-2">
          <Download size={22} className="text-cyan-400" /> Exportações
        </h1>
        <p className="text-gray-500 text-sm">Gere arquivos CSV para análise externa</p>
      </div>

      {/* Seletor de evento */}
      <div className="card border-white/5 flex items-center gap-4 flex-wrap">
        <div className="flex-1">
          <label className="input-label">Evento</label>
          <select className="select" value={eventId} onChange={(e) => setEventId(e.target.value)}>
            <option value="">Selecionar evento...</option>
            {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
          </select>
        </div>
        <div className="flex-1">
          <label className="input-label">Filtro de status (para contas)</label>
          <select className="select" value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
            <option value="">Todos os status</option>
            <option value="pending">Pendente</option>
            <option value="partial">Parcial</option>
            <option value="paid">Pago</option>
            <option value="overdue">Vencido</option>
            <option value="cancelled">Cancelado</option>
          </select>
        </div>
      </div>

      {/* Cards de exportação */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {EXPORT_TYPES.map((et) => {
          const Icon = et.icon;
          const isRunning = exporting === et.value;
          return (
            <div key={et.value} className="card border-white/5 flex items-start gap-4">
              <div className="p-2 bg-cyan-400/10 rounded-lg">
                <Icon size={20} className="text-cyan-400" />
              </div>
              <div className="flex-1">
                <p className="font-semibold text-white">{et.label}</p>
                <p className="text-sm text-gray-400 mt-0.5">{et.description}</p>
              </div>
              <button
                onClick={() => handleExport(et.value)}
                disabled={!eventId || isRunning}
                className={`btn-outline text-sm flex-shrink-0 ${isRunning ? "opacity-60" : ""}`}
              >
                {isRunning ? (
                  <span className="flex items-center gap-1"><Download size={14} className="animate-bounce" /> Gerando...</span>
                ) : (
                  <span className="flex items-center gap-1"><Download size={14} /> CSV</span>
                )}
              </button>
            </div>
          );
        })}
      </div>

      {!eventId && (
        <div className="text-center py-4 text-gray-600 text-sm">
          Selecione um evento para habilitar as exportações.
        </div>
      )}
    </div>
  );
}
