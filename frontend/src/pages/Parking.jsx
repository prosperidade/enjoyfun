import {
  ParkingSquare,
  Plus,
  QrCode,
  CheckCircle,
  XCircle,
  Eye,
  X,
  Clock, // Adicionado para o relógio
} from "lucide-react";
import { useState, useEffect, useCallback, useRef } from "react"; // useRef adicionado para o scanner
import { QRCodeCanvas } from "qrcode.react";
import api from "../lib/api";
import toast from "react-hot-toast";
import { useEventScope } from "../context/EventScopeContext";

export default function Parking() {
  const { eventId, setEventId } = useEventScope();
  const [tab, setTab] = useState("parking");
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents] = useState([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedTicket, setSelectedTicket] = useState(null);
  const [currentTime, setCurrentTime] = useState(new Date()); // Relógio Anti-fraude

  const [form, setForm] = useState({
    event_id: "",
    license_plate: "",
    vehicle_type: "car",
  });

  const [ticketInput, setTicketInput] = useState("");
  const [validating, setValidating] = useState(false);
  const [validationResult, setValidationResult] = useState(null);

  // 🚀 ADIÇÃO CIRÚRGICA: Estados do Scanner de Entrada
  const [entryScanInput, setEntryScanInput] = useState("");
  const [scanningEntry, setScanningEntry] = useState(false);
  const entryScannerRef = useRef(null);

  // Atualização do Relógio Anti-fraude
  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    api
      .get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => {});
  }, []);

  useEffect(() => {
    setForm((current) => ({
      ...current,
      event_id: String(eventId || ""),
    }));
  }, [eventId]);

  const fetchRecords = useCallback(() => {
    setLoading(true);
    const params = eventId ? { event_id: eventId } : {};
    api
      .get("/parking", { params })
      .then((r) => setRecords(r.data.data || []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [eventId]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    const params = eventId ? { event_id: eventId } : {};
    api
      .get("/parking", { params })
      .then((r) => {
        if (active) setRecords(r.data.data || []);
      })
      .catch(() => {})
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [eventId]);

  // 🚀 ADIÇÃO CIRÚRGICA: Função de envio via Scanner (Automático)
  const handleScannerEntry = useCallback(async () => {
    const scannedPlate = entryScanInput.trim().toUpperCase();

    if (!scannedPlate || scanningEntry) return;

    if (!form.event_id) {
      toast.error("Selecione um evento antes de bipar o ingresso/placa.");
      setEntryScanInput("");
      return;
    }

    setScanningEntry(true);
    try {
      await api.post("/parking", {
        event_id: form.event_id,
        vehicle_type: form.vehicle_type,
        license_plate: scannedPlate,
      });
      toast.success("Entrada via scanner registrada!");
      setEntryScanInput("");
      fetchRecords();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao registrar entrada.");
      setEntryScanInput("");
    } finally {
      setScanningEntry(false);
    }
  }, [entryScanInput, scanningEntry, form.event_id, form.vehicle_type, fetchRecords]);

  // 🚀 ADIÇÃO CIRÚRGICA: Foco Inteligente (NÃO trava digitação manual)
  useEffect(() => {
    if (tab !== "parking" || !showForm) return;

    const focusScanner = () => {
      // Se o usuário clicou no campo manual de placa ou no select de evento, aborta o foco no scanner
      const activeTag = document.activeElement?.tagName?.toLowerCase();
      if (activeTag === 'input' || activeTag === 'select' || activeTag === 'textarea') return;
      
      if (entryScannerRef.current && document.activeElement !== entryScannerRef.current) {
        entryScannerRef.current.focus();
      }
    };

    focusScanner();
    const focusInterval = setInterval(focusScanner, 600);
    return () => clearInterval(focusInterval);
  }, [tab, showForm]);

  const handleEntry = async (e) => {
    e.preventDefault();
    try {
      await api.post("/parking", form);
      toast.success("Entrada registrada!");
      setShowForm(false);
      setForm({ event_id: String(eventId || ""), license_plate: "", vehicle_type: "car" });
      fetchRecords();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao registrar entrada.");
    }
  };

  const handleExit = async (id) => {
    try {
      await api.post(`/parking/${id}/exit`);
      toast.success("Saída registrada!");
      fetchRecords();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao registrar saída.");
    }
  };

  const handleValidateTicket = async () => {
    if (!ticketInput.trim()) return;
    
    setValidating(true);
    setValidationResult(null);
    
    try {
      // Rota corrigida para o motor independente de Estacionamento
      // Enviamos o qr_token no body conforme o novo ParkingController
      const res = await api.post("/parking/validate", { 
        qr_token: ticketInput.trim() 
      });

      if (res.data.success) {
        setValidationResult({
          ok: true,
          message: res.data.message,
          holder: res.data.data?.license_plate, // Exibe a Placa do veículo
          type: res.data.data?.vehicle_type === 'car' ? 'CARRO' : 'MOTO',
          event: res.data.data?.event_name,
          current_status: res.data.data?.current_status,
        });
        
        setTicketInput("");
        toast.success(res.data.message);
        
        // Atualiza a lista de veículos na tela para refletir a entrada/saída
        fetchRecords(); 
      }
    } catch (err) {
      const errorMsg = err.response?.data?.message || "Erro ao validar voucher.";
      
      setValidationResult({
        ok: false,
        message: errorMsg,
      });
      
      toast.error(errorMsg);
    } finally {
      setValidating(false);
    }
  };

  const parkedCount = records.filter((r) => r.status === "parked").length;

  return (
    <div className="space-y-6">
      
      {/* MODAL PADRONIZADO: IGUAL AO DE INGRESSOS (ROXO) */}
      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/90 backdrop-blur-md animate-in fade-in">
          <div className="card max-w-sm w-full border-purple-500/50 text-center space-y-6 p-8 relative overflow-hidden shadow-2xl">
            {/* Linha de Gradiente Superior */}
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
                Ingresso Estacionamento
              </h3>
              <p className="text-xs text-gray-400 italic">
                Válido apenas com relógio em movimento
              </p>
            </div>

            <div className="bg-white p-3 rounded-xl inline-block mx-auto shadow-inner">
              <QRCodeCanvas
                value={selectedTicket.qr_token}
                size={200}
                level={"H"}
                includeMargin={true}
              />
            </div>

            <div className="space-y-1">
              <p className="text-white font-bold text-3xl font-mono tracking-widest uppercase truncate px-2">
                {selectedTicket.license_plate}
              </p>
              <p className="text-purple-400 font-semibold uppercase">
                {selectedTicket.vehicle_type === 'car' ? 'Carro' : 'Moto'}
              </p>
              <div className="pt-2">
                <span className="bg-white/5 border border-white/10 px-3 py-1 rounded-full text-[10px] font-mono text-gray-400 uppercase">
                  Ref: {selectedTicket.qr_token}
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

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <ParkingSquare size={22} className="text-cyan-400" /> Portaria
          </h1>
          <p className="text-gray-500 text-sm">
            {parkedCount} veículo(s) no local
          </p>
        </div>
        {tab === "parking" && (
          <button
            onClick={() => setShowForm(!showForm)}
            className="btn-primary"
          >
            <Plus size={16} /> Registrar Entrada
          </button>
        )}
      </div>

      {/* Abas */}
      <div className="flex gap-2 border-b border-gray-700">
        <button
          onClick={() => setTab("parking")}
          className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            tab === "parking"
              ? "border-cyan-400 text-cyan-400"
              : "border-transparent text-gray-400 hover:text-white"
          }`}
        >
          <ParkingSquare size={14} className="inline mr-1" /> Estacionamento
        </button>
        <button
          onClick={() => {
            setTab("tickets");
            setValidationResult(null);
          }}
          className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            tab === "tickets"
              ? "border-cyan-400 text-cyan-400"
              : "border-transparent text-gray-400 hover:text-white"
          }`}
        >
          <QrCode size={14} className="inline mr-1" /> Validar Ingresso
        </button>
      </div>

      {/* Aba Estacionamento */}
      {tab === "parking" && (
        <>
          {showForm && (
            <div className="card border-cyan-800/40 max-w-lg relative">
              <h2 className="section-title">Registrar Entrada de Veículo</h2>

              {/* 🚀 ADIÇÃO CIRÚRGICA: Input Invisível para capturar o Scanner */}
              <input
                ref={entryScannerRef}
                value={entryScanInput}
                onChange={(e) => setEntryScanInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") {
                    e.preventDefault();
                    handleScannerEntry();
                  }
                }}
                className="absolute opacity-0 pointer-events-none"
                tabIndex={-1}
                aria-hidden="true"
              />

              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2">
                  <label className="input-label">Evento *</label>
                  <select
                    className="select"
                    value={form.event_id}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, event_id: e.target.value }))
                    }
                  >
                    <option value="">Selecionar...</option>
                    {events.map((ev) => (
                      <option key={ev.id} value={ev.id}>
                        {ev.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="input-label">Placa * (Ou bip aqui)</label>
                  <input
                    className="input uppercase"
                    placeholder="ABC1234"
                    value={form.license_plate}
                    onChange={(e) =>
                      setForm((f) => ({
                        ...f,
                        license_plate: e.target.value.toUpperCase(),
                      }))
                    }
                  />
                </div>
                <div>
                  <label className="input-label">Tipo</label>
                  <select
                    className="select"
                    value={form.vehicle_type}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, vehicle_type: e.target.value }))
                    }
                  >
                    <option value="car">Carro</option>
                    <option value="motorcycle">Moto</option>
                    <option value="truck">Caminhão</option>
                    <option value="bus">Ônibus</option>
                  </select>
                </div>
                <div className="col-span-2 flex gap-3">
                  <button onClick={handleEntry} className="btn-primary flex-1">
                    Registrar Manual
                  </button>
                  <button
                    onClick={() => setShowForm(false)}
                    className="btn-outline flex-1"
                  >
                    Cancelar
                  </button>
                </div>
              </div>
            </div>
          )}

          <div className="flex gap-3">
            <select
              className="select w-auto"
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

          <div className="table-wrapper">
            {loading ? (
              <p className="text-center text-gray-500 py-10">Carregando...</p>
            ) : (
              <table className="table">
                <thead>
                  <tr>
                    <th>Placa</th>
                    <th>Tipo</th>
                    <th>Entrada</th>
                    <th>Saída</th>
                    <th>Status</th>
                    <th>Ação</th>
                  </tr>
                </thead>
                <tbody>
                  {records.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="text-center text-gray-500 py-10">
                        Nenhum registro
                      </td>
                    </tr>
                  ) : (
                    records.map((r) => (
                      <tr key={r.id}>
                        <td className="font-mono font-bold text-white">
                          {r.license_plate}
                        </td>
                        <td>{r.vehicle_type}</td>
                        <td className="text-xs text-gray-400">
                          {new Date(r.entry_at).toLocaleString("pt-BR")}
                        </td>
                        <td className="text-xs text-gray-400">
                          {r.exit_at ? new Date(r.exit_at).toLocaleString("pt-BR") : "—"}
                        </td>
                        <td>
                          <span className={
                            r.status === "pending" ? "badge-yellow" : 
                            r.status === "parked" ? "badge-green" : "badge-gray"
                          }>
                           {r.status === "pending" ? "Aguardando Bip" : r.status === "parked" ? "No local" : "Saiu"}
                          </span>
                        </td>
                        <td>
                          <div className="flex items-center gap-2">
                            {r.status === "parked" && (
                              <button onClick={() => handleExit(r.id)} className="btn-outline btn-sm">
                                Registrar Saída
                              </button>
                            )}
                            {r.qr_token && (
                              <button
                                onClick={() => setSelectedTicket(r)}
                                className="p-2 text-cyan-400 hover:bg-cyan-400/10 rounded-lg transition-colors"
                              >
                                <Eye size={18} />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            )}
          </div>
        </>
      )}

      {/* Aba Validar Ingresso */}
      {tab === "tickets" && (
        <div className="max-w-lg space-y-6">
          <div className="card border-cyan-800/40">
            <h2 className="section-title flex items-center gap-2">
              <QrCode size={18} className="text-cyan-400" /> Validar Ingresso
            </h2>
            <div className="flex gap-2">
              <input
                className="input flex-1"
                placeholder="Código ou referência (EF-...)"
                value={ticketInput}
                onChange={(e) => setTicketInput(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && handleValidateTicket()}
                autoFocus
              />
              <button onClick={handleValidateTicket} disabled={validating || !ticketInput.trim()} className="btn-primary">
                {validating ? "..." : "Validar"}
              </button>
            </div>
          </div>

          {validationResult && (
            <div className={`card border ${
              !validationResult.ok
                ? "border-red-500/40 bg-red-900/10"
                : validationResult.current_status === "parked"
                  ? "border-green-500/40 bg-green-900/10" // Entrada = Verde
                  : "border-blue-500/40 bg-blue-900/10"   // Saída = Azul
            }`}>
              <div className="flex items-start gap-4">
                {!validationResult.ok ? (
                  <XCircle size={40} className="text-red-400 flex-shrink-0" />
                ) : validationResult.current_status === "parked" ? (
                  <div className="flex flex-col items-center justify-center bg-green-500/20 text-green-400 p-3 rounded-lg flex-shrink-0">
                    <CheckCircle size={32} />
                    <span className="text-xs font-bold mt-1 uppercase">Entrada</span>
                  </div>
                ) : (
                  <div className="flex flex-col items-center justify-center bg-blue-500/20 text-blue-400 p-3 rounded-lg flex-shrink-0">
                    <CheckCircle size={32} />
                    <span className="text-xs font-bold mt-1 uppercase">Saída</span>
                  </div>
                )}

                <div className="flex-1">
                  <p className={`font-bold text-xl mb-2 ${
                    !validationResult.ok
                      ? "text-red-400"
                      : validationResult.current_status === "parked"
                        ? "text-green-400"
                        : "text-blue-400"
                  }`}>
                    {validationResult.message}
                  </p>

                  {validationResult.ok && (
                    <div className="grid grid-cols-2 gap-2 text-sm text-gray-300 bg-black/20 p-3 rounded">
                      {validationResult.holder && (
                        <div>
                          <span className="text-gray-500 text-xs block uppercase">Placa</span>
                          <span className="font-mono font-bold text-white text-lg">{validationResult.holder}</span>
                        </div>
                      )}
                      {validationResult.type && (
                        <div>
                          <span className="text-gray-500 text-xs block uppercase">Veículo</span>
                          <span className="font-semibold">{validationResult.type}</span>
                        </div>
                      )}
                      {validationResult.event && (
                        <div className="col-span-2 mt-1 pt-2 border-t border-white/5">
                          <span className="text-gray-500 text-xs block uppercase">Evento</span>
                          <span className="font-semibold">{validationResult.event}</span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
