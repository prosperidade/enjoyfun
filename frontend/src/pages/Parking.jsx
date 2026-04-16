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
import { createOfflineQueueRecord, db } from "../lib/db";
import toast from "react-hot-toast";
import { useEventScope } from "../context/EventScopeContext";
import EmbeddedAIChat from "../components/EmbeddedAIChat";
import Pagination from "../components/Pagination";
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from "../lib/pagination";

const PAGE_SIZE = 50;
const VEHICLE_TYPE_LABELS = { car: 'CARRO', motorcycle: 'MOTO', truck: 'CAMINHAO', bus: 'ONIBUS', van: 'VAN' };

export default function Parking() {
  const { eventId, setEventId } = useEventScope();
  const parkingEventsCacheKey = "enjoyfun_parking_events_cache";
  const parkingRecordsCacheKey = eventId ? `enjoyfun_parking_records_${eventId}` : "";
  const [tab, setTab] = useState("parking");
  const [records, setRecords] = useState([]);
  const [page, setPage] = useState(1);
  const [recordsMeta, setRecordsMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE });
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
      .then((r) => {
        const list = r.data.data || [];
        setEvents(list);
        localStorage.setItem(parkingEventsCacheKey, JSON.stringify(list));
      })
      .catch(() => {
        const cached = localStorage.getItem(parkingEventsCacheKey);
        if (!cached) {
          return;
        }

        try {
          setEvents(JSON.parse(cached));
          toast("Modo Offline: eventos do parking carregados do cache.");
        } catch {
          // Ignora cache corrompido.
        }
      });
  }, []);

  useEffect(() => {
    setForm((current) => ({
      ...current,
      event_id: String(eventId || ""),
    }));
    setPage(1);
  }, [eventId]);

  const setRecordsWithCache = useCallback((nextValue) => {
    setRecords((current) => {
      const next = typeof nextValue === "function" ? nextValue(current) : nextValue;
      if (parkingRecordsCacheKey) {
        localStorage.setItem(parkingRecordsCacheKey, JSON.stringify(next));
      }
      return next;
    });
  }, [parkingRecordsCacheKey]);

  const fetchRecords = useCallback(async () => {
    setLoading(true);
    const params = {
      ...(eventId ? { event_id: eventId } : {}),
      page,
      per_page: PAGE_SIZE,
    };

    try {
      const response = await api.get("/parking", { params });
      setRecordsWithCache(response.data.data || []);
      setRecordsMeta(extractPaginationMeta(response.data?.meta, { ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page }));
    } catch {
      setRecordsMeta({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: 1 });
      if (parkingRecordsCacheKey) {
        const cached = localStorage.getItem(parkingRecordsCacheKey);
        if (cached) {
          try {
            setRecordsWithCache(JSON.parse(cached));
            toast("Modo Offline: estacionamento carregado do cache.");
          } catch {
            setRecordsWithCache([]);
          }
        } else {
          setRecordsWithCache([]);
        }
      } else {
        setRecordsWithCache([]);
      }
    } finally {
      setLoading(false);
    }
  }, [eventId, page, parkingRecordsCacheKey, setRecordsWithCache]);

  useEffect(() => {
    fetchRecords();
  }, [fetchRecords]);

  // Função de envio via Scanner (leitura de pistola/câmera) com fallback offline
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
      if (!err.response || err.code === 'ERR_NETWORK') {
        const offlineId = crypto.randomUUID();
        await db.offlineQueue.put(createOfflineQueueRecord({
          offline_id: offlineId,
          status: 'pending',
          payload_type: 'parking_entry',
          created_at: new Date().toISOString(),
          payload: { event_id: form.event_id, vehicle_type: form.vehicle_type, license_plate: scannedPlate },
        }));
        toast.success("Entrada salva localmente (Offline)!");
        setEntryScanInput("");
        setRecordsWithCache(prev => [{ id: offlineId, license_plate: scannedPlate, vehicle_type: form.vehicle_type, status: 'pending', created_at: new Date().toISOString() }, ...prev]);
        return;
      }
      toast.error(err.response?.data?.message || "Erro ao registrar entrada.");
      setEntryScanInput("");
    } finally {
      setScanningEntry(false);
    }
  }, [entryScanInput, scanningEntry, form.event_id, form.vehicle_type, fetchRecords, setRecordsWithCache]);

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
      if (!err.response || err.code === 'ERR_NETWORK') {
        const offlineId = crypto.randomUUID();
        await db.offlineQueue.put(createOfflineQueueRecord({
          offline_id: offlineId,
          status: 'pending',
          payload_type: 'parking_entry',
          created_at: new Date().toISOString(),
          payload: { event_id: form.event_id, vehicle_type: form.vehicle_type, license_plate: form.license_plate },
        }));
        toast.success("Entrada salva localmente (Offline)!");
        setShowForm(false);
        setForm({ event_id: String(eventId || ""), license_plate: "", vehicle_type: "car" });
        setRecordsWithCache(prev => [{ id: offlineId, license_plate: form.license_plate, vehicle_type: form.vehicle_type, status: 'pending', created_at: new Date().toISOString() }, ...prev]);
        return;
      }
      toast.error(err.response?.data?.message || "Erro ao registrar entrada.");
    }
  };

  const handleExit = async (id) => {
    try {
      await api.post(`/parking/${id}/exit`);
      toast.success("Saída registrada!");
      fetchRecords();
    } catch (err) {
      if (!err.response || err.code === 'ERR_NETWORK') {
        const offlineId = crypto.randomUUID();
        await db.offlineQueue.put(createOfflineQueueRecord({
          offline_id: offlineId,
          status: 'pending',
          payload_type: 'parking_exit',
          created_at: new Date().toISOString(),
          payload: { parking_id: id, event_id: eventId },
        }));
        toast.success("Saída salva localmente (Offline)!");
        setRecordsWithCache(prev => prev.map(r => r.id === id ? { ...r, status: 'exited', updated_at: new Date().toISOString() } : r));
        return;
      }
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
          type: VEHICLE_TYPE_LABELS[res.data.data?.vehicle_type] || (res.data.data?.vehicle_type ? res.data.data.vehicle_type.toUpperCase() : '—'),
          event: res.data.data?.event_name,
          current_status: res.data.data?.current_status,
        });

        setTicketInput("");
        toast.success(res.data.message);

        // Atualiza a lista de veículos na tela para refletir a entrada/saída
        fetchRecords();
      }
    } catch (err) {
      if (!err.response || err.code === 'ERR_NETWORK') {
        const normalizedToken = ticketInput.trim();
        const cachedRecord = records.find(
          (record) => String(record?.qr_token || "").trim() === normalizedToken,
        );

        if (!cachedRecord) {
          setValidationResult({ ok: false, message: "Voucher não encontrado no cache offline do estacionamento" });
          return toast.error("Voucher inválido ou não cacheado no parking.");
        }

        const parkingId = Number(cachedRecord?.id || 0);
        if (parkingId <= 0) {
          setValidationResult({ ok: false, message: "Registro ainda não sincronizado; valide novamente quando voltar a conexão" });
          return toast.error("Registro offline sem ID real ainda não pode ser reconciliado.");
        }

        const action = String(cachedRecord?.status || "").trim().toLowerCase() === "parked"
          ? "exit"
          : "entry";
        const offlineId = crypto.randomUUID();
        await db.offlineQueue.put(createOfflineQueueRecord({
          offline_id: offlineId,
          status: 'pending',
          payload_type: 'parking_validate',
          created_at: new Date().toISOString(),
          payload: {
            parking_id: parkingId,
            qr_token: normalizedToken,
            event_id: eventId,
            action,
          },
        }));

        setRecordsWithCache((current) => current.map((record) => {
          if (Number(record?.id || 0) !== parkingId) {
            return record;
          }

          return {
            ...record,
            status: action === "exit" ? "exited" : "parked",
            updated_at: new Date().toISOString(),
          };
        }));

        setValidationResult({
          ok: true,
          message: action === "exit" ? "Saída registrada via Offline" : "Entrada registrada via Offline",
          holder: cachedRecord.license_plate,
          type: VEHICLE_TYPE_LABELS[cachedRecord.vehicle_type] || (cachedRecord.vehicle_type ? cachedRecord.vehicle_type.toUpperCase() : '—'),
          event: "OFFLINE",
          current_status: action === "exit" ? "Saída Registrada" : "Acesso Liberado",
        });
        setTicketInput("");
        return toast.success(action === "exit" ? "Saída validada localmente!" : "Entrada validada localmente!");
      }
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
  const pendingCount = records.filter((r) => r.status === "pending").length;
  const totalRevenue = records
    .filter((r) => r.status === "exited" && Number(r.fee_paid) > 0)
    .reduce((sum, r) => sum + Number(r.fee_paid), 0);
  const selectedEvent = events.find((event) => String(event.id) === String(eventId)) || null;

  return (
    <div className="space-y-6">

      {/* MODAL QR — AETHER NEON */}
      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/90 backdrop-blur-xl animate-in fade-in">
          <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-800/40 rounded-2xl max-w-sm w-full text-center space-y-6 p-8 relative overflow-hidden shadow-2xl shadow-cyan-500/5">
            {/* Linha de Gradiente Superior */}
            <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-cyan-500 to-transparent"></div>

            <div className="flex justify-between items-center">
              <div className="flex items-center gap-2 text-cyan-400">
                <Clock size={16} className="animate-pulse" />
                <span className="text-xs font-mono">
                  {currentTime.toLocaleTimeString("pt-BR")}
                </span>
              </div>
              <button
                onClick={() => setSelectedTicket(null)}
                className="text-slate-500 hover:text-slate-200 transition-colors"
              >
                <XCircle size={24} />
              </button>
            </div>

            <div className="space-y-2">
              <h3 className="text-xl font-bold font-headline text-slate-100 uppercase tracking-tight">
                Ingresso Estacionamento
              </h3>
              <p className="text-xs text-slate-500 italic">
                Valido apenas com relogio em movimento
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
              <p className="text-cyan-400 font-bold text-3xl font-mono tracking-widest uppercase truncate px-2">
                {selectedTicket.license_plate}
              </p>
              <p className="text-cyan-300/70 font-semibold uppercase">
                {VEHICLE_TYPE_LABELS[selectedTicket.vehicle_type] || (selectedTicket.vehicle_type ? selectedTicket.vehicle_type.toUpperCase() : '—')}
              </p>
              <div className="pt-2">
                <span className="bg-slate-800/50 border border-slate-700/50 px-3 py-1 rounded-full text-[10px] font-mono text-slate-500 uppercase">
                  Ref: {selectedTicket.qr_token}
                </span>
              </div>
            </div>

            <button
              onClick={() => setSelectedTicket(null)}
              className="w-full py-4 text-lg font-bold bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl hover:from-cyan-400 hover:to-cyan-300 transition-all"
            >
              FECHAR
            </button>
          </div>
        </div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold font-headline text-slate-100 flex items-center gap-2">
            <ParkingSquare size={22} className="text-cyan-400" /> Portaria
          </h1>
          <p className="text-slate-500 text-sm mt-1">
            {parkedCount} veiculo(s) no local
            {totalRevenue > 0 && (
              <span className="ml-3 text-green-400 font-semibold">
                Receita: R$ {totalRevenue.toFixed(2)}
              </span>
            )}
          </p>
          {selectedEvent?.capacity > 0 && (
            <span className={`inline-flex items-center gap-1.5 mt-2 px-3 py-1 rounded-full text-xs font-semibold ${
              parkedCount >= selectedEvent.capacity
                ? "bg-red-500/15 text-red-400 border border-red-500/20"
                : parkedCount >= selectedEvent.capacity * 0.9
                  ? "bg-amber-500/15 text-amber-400 border border-amber-500/20"
                  : "bg-green-500/15 text-green-400 border border-green-500/20"
            }`}>
              <span className={`inline-block w-1.5 h-1.5 rounded-full ${
                parkedCount >= selectedEvent.capacity
                  ? "bg-red-400"
                  : parkedCount >= selectedEvent.capacity * 0.9
                    ? "bg-amber-400"
                    : "bg-green-400"
              }`} />
              {parkedCount >= selectedEvent.capacity
                ? "Estacionamento lotado!"
                : parkedCount >= selectedEvent.capacity * 0.9
                  ? `Quase lotado (${parkedCount} / ${selectedEvent.capacity} vagas ocupadas)`
                  : `${parkedCount} / ${selectedEvent.capacity} vagas ocupadas`}
            </span>
          )}
        </div>
        {tab === "parking" && (
          <button
            onClick={() => setShowForm(!showForm)}
            className="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl hover:from-cyan-400 hover:to-cyan-300 transition-all shadow-lg shadow-cyan-500/20"
          >
            <Plus size={16} /> Registrar Entrada
          </button>
        )}
      </div>

      {/* Abas — Aether Neon Tabs */}
      <div className="inline-flex bg-slate-800/50 rounded-xl p-1 gap-1">
        <button
          onClick={() => setTab("parking")}
          className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg transition-all ${
            tab === "parking"
              ? "bg-cyan-500 text-slate-950 font-semibold shadow-md"
              : "text-slate-400 hover:text-slate-200"
          }`}
        >
          <ParkingSquare size={14} /> Estacionamento
        </button>
        <button
          onClick={() => {
            setTab("tickets");
            setValidationResult(null);
          }}
          className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg transition-all ${
            tab === "tickets"
              ? "bg-cyan-500 text-slate-950 font-semibold shadow-md"
              : "text-slate-400 hover:text-slate-200"
          }`}
        >
          <QrCode size={14} /> Validar Ingresso
        </button>
      </div>

      {/* Aba Estacionamento */}
      {tab === "parking" && (
        <>
          {showForm && (
            <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 max-w-lg relative">
              <h2 className="text-lg font-semibold font-headline text-slate-100 mb-4">Registrar Entrada de Veiculo</h2>

              {/* Input Invisivel para capturar o Scanner */}
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
                  <label className="block text-xs font-medium text-slate-400 uppercase tracking-wider mb-1.5">Evento *</label>
                  <select
                    className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition-colors"
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
                  <label className="block text-xs font-medium text-slate-400 uppercase tracking-wider mb-1.5">Placa * (Ou bip aqui)</label>
                  <input
                    className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-xl px-3 py-2.5 font-mono uppercase text-lg focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition-colors placeholder:text-slate-600"
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
                  <label className="block text-xs font-medium text-slate-400 uppercase tracking-wider mb-1.5">Tipo</label>
                  <select
                    className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition-colors"
                    value={form.vehicle_type}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, vehicle_type: e.target.value }))
                    }
                  >
                    <option value="car">Carro</option>
                    <option value="motorcycle">Moto</option>
                    <option value="truck">Caminhao</option>
                    <option value="bus">Onibus</option>
                  </select>
                </div>
                <div className="col-span-2 flex gap-3">
                  <button onClick={handleEntry} className="flex-1 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl hover:from-cyan-400 hover:to-cyan-300 transition-all">
                    Registrar Manual
                  </button>
                  <button
                    onClick={() => setShowForm(false)}
                    className="flex-1 py-2.5 border border-slate-700/50 text-slate-400 hover:text-slate-200 hover:border-slate-600 rounded-xl transition-all"
                  >
                    Cancelar
                  </button>
                </div>
              </div>
            </div>
          )}

          <div className="flex gap-3">
            <select
              className="bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition-colors w-auto"
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

          <EmbeddedAIChat
            surface="parking"
            title="Assistente do Estacionamento"
            description={`${parkedCount} veiculos no local, ${pendingCount} pendentes de bip`}
            accentColor="cyan"
            suggestions={[
              'Existe gargalo de entrada agora?',
              'Quantos veiculos entraram hoje?',
              'O que devo ajustar na portaria?',
            ]}
          />

          <div className="bg-[#111827] border border-slate-800/40 rounded-2xl overflow-hidden">
            {loading ? (
              <p className="text-center text-slate-500 py-10">Carregando...</p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-slate-800/50">
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Placa</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Tipo</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Entrada</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Saida</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Taxa</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Status</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wider">Acao</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/40">
                  {records.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="text-center text-slate-500 py-10">
                        Nenhum registro
                      </td>
                    </tr>
                  ) : (
                    records.map((r) => (
                      <tr key={r.id} className="hover:bg-slate-800/20 transition-colors">
                        <td className="px-4 py-3 font-mono font-bold text-slate-100">
                          {r.license_plate}
                        </td>
                        <td className="px-4 py-3 text-slate-300">{VEHICLE_TYPE_LABELS[r.vehicle_type] || (r.vehicle_type ? r.vehicle_type.toUpperCase() : '—')}</td>
                        <td className="px-4 py-3 text-xs text-slate-500">
                          {r.entry_at ? new Date(r.entry_at).toLocaleString("pt-BR") : "Aguardando"}
                        </td>
                        <td className="px-4 py-3 text-xs text-slate-500">
                          {r.exit_at ? new Date(r.exit_at).toLocaleString("pt-BR") : "—"}
                        </td>
                        <td className="px-4 py-3 text-sm">
                          {r.status === "exited" && Number(r.fee_paid) > 0
                            ? <span className="text-green-400 font-semibold">R$ {Number(r.fee_paid).toFixed(2)}</span>
                            : <span className="text-slate-600">—</span>}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${
                            r.status === "pending"
                              ? "bg-amber-500/15 text-amber-400"
                              : r.status === "parked"
                                ? "bg-green-500/15 text-green-400"
                                : "bg-slate-700/50 text-slate-400"
                          }`}>
                           {r.status === "pending" ? "Aguardando Bip" : r.status === "parked" ? "No local" : "Saiu"}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            {r.status === "parked" && (
                              <button onClick={() => handleExit(r.id)} className="px-3 py-1.5 text-xs font-medium border border-amber-500/30 text-amber-400 hover:bg-amber-500/10 rounded-lg transition-colors">
                                Registrar Saida
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
          {!loading && recordsMeta.total_pages > 1 ? (
            <Pagination
              page={recordsMeta.page}
              totalPages={recordsMeta.total_pages}
              onPrev={() => setPage((current) => Math.max(1, current - 1))}
              onNext={() => setPage((current) => Math.min(recordsMeta.total_pages, current + 1))}
            />
          ) : null}
        </>
      )}

      {/* Aba Validar Ingresso */}
      {tab === "tickets" && (
        <div className="max-w-lg space-y-6">
          <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
            <h2 className="text-lg font-semibold font-headline text-slate-100 flex items-center gap-2 mb-4">
              <QrCode size={18} className="text-cyan-400" /> Validar Ingresso
            </h2>
            <div className="flex gap-2">
              <input
                className="flex-1 bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition-colors placeholder:text-slate-600"
                placeholder="Codigo ou referencia (EF-...)"
                value={ticketInput}
                onChange={(e) => setTicketInput(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && handleValidateTicket()}
                autoFocus
              />
              <button onClick={handleValidateTicket} disabled={validating || !ticketInput.trim()} className="px-5 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl hover:from-cyan-400 hover:to-cyan-300 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                {validating ? "..." : "Validar"}
              </button>
            </div>
          </div>

          {validationResult && (
            <div className={`bg-[#111827] border rounded-2xl p-5 ${
              !validationResult.ok
                ? "border-red-500/20 bg-red-500/5"
                : (validationResult.current_status || validationResult.status) === "parked"
                  ? "border-green-500/20 bg-green-500/5"
                  : "border-blue-500/20 bg-blue-500/5"
            }`}>
              <div className="flex items-start gap-4">
                {!validationResult.ok ? (
                  <XCircle size={40} className="text-red-400 flex-shrink-0" />
                ) : (validationResult.current_status || validationResult.status) === "parked" ? (
                  <div className="flex flex-col items-center justify-center bg-green-500/15 text-green-400 p-3 rounded-xl flex-shrink-0">
                    <CheckCircle size={32} />
                    <span className="text-xs font-bold mt-1 uppercase">Entrada</span>
                  </div>
                ) : (
                  <div className="flex flex-col items-center justify-center bg-blue-500/15 text-blue-400 p-3 rounded-xl flex-shrink-0">
                    <CheckCircle size={32} />
                    <span className="text-xs font-bold mt-1 uppercase">Saida</span>
                  </div>
                )}

                <div className="flex-1">
                  <p className={`font-bold font-headline text-xl mb-2 ${
                    !validationResult.ok
                      ? "text-red-400"
                      : (validationResult.current_status || validationResult.status) === "parked"
                        ? "text-green-400"
                        : "text-blue-400"
                  }`}>
                    {validationResult.message}
                  </p>

                  {validationResult.ok && (
                    <div className="grid grid-cols-2 gap-2 text-sm text-slate-300 bg-slate-800/30 p-3 rounded-xl border border-slate-700/30">
                      {validationResult.holder && (
                        <div>
                          <span className="text-slate-500 text-xs block uppercase">Placa</span>
                          <span className="font-mono font-bold text-cyan-400 text-lg">{validationResult.holder}</span>
                        </div>
                      )}
                      {validationResult.type && (
                        <div>
                          <span className="text-slate-500 text-xs block uppercase">Veiculo</span>
                          <span className="font-semibold text-slate-200">{validationResult.type}</span>
                        </div>
                      )}
                      {validationResult.event && (
                        <div className="col-span-2 mt-1 pt-2 border-t border-slate-700/30">
                          <span className="text-slate-500 text-xs block uppercase">Evento</span>
                          <span className="font-semibold text-slate-200">{validationResult.event}</span>
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
