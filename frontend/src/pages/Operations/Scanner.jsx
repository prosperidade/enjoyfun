import { useEffect, useRef, useState } from "react";
import { Html5Qrcode } from "html5-qrcode";
import {
  Camera,
  CheckCircle2,
  XCircle,
  MapPin,
  Keyboard,
  AlertTriangle,
  Loader2,
  ArrowLeft,
  WifiOff,
} from "lucide-react";
import toast from "react-hot-toast";
import { useLocation, useNavigate, useSearchParams } from "react-router-dom";
import api from "../../lib/api";

const SCANNER_EVENTS_CACHE_KEY = "enjoyfun_scanner_events_v1";
const SCANNER_SECTORS_CACHE_PREFIX = "enjoyfun_scanner_sectors_v1";

function normalizeScannerModeValue(value = "") {
  return String(value || "")
    .toLowerCase()
    .trim()
    .replace(/\s+/g, "_")
    .replace(/^_+|_+$/g, "");
}

function formatSectorLabel(value = "") {
  const normalized = String(value || "").trim();
  if (!normalized) return "";

  return normalized
    .split("_")
    .filter(Boolean)
    .map((chunk) => chunk.charAt(0).toUpperCase() + chunk.slice(1))
    .join(" ");
}

function readJsonCache(key) {
  if (typeof window === "undefined" || !window.localStorage) {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(key);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function writeJsonCache(key, payload) {
  if (typeof window === "undefined" || !window.localStorage) {
    return;
  }

  try {
    window.localStorage.setItem(
      key,
      JSON.stringify({
        ...payload,
        saved_at: new Date().toISOString(),
      })
    );
  } catch {
    // Cache local é best-effort para o scanner.
  }
}

function selectDefaultEventId(events = [], requestedEventId = "", currentEventId = "") {
  const ids = new Set(events.map((event) => String(event.id)));

  if (currentEventId && ids.has(String(currentEventId))) {
    return String(currentEventId);
  }
  if (requestedEventId && ids.has(String(requestedEventId))) {
    return String(requestedEventId);
  }

  const now = new Date();
  const ongoing = events.find((event) => {
    if (!event?.starts_at || !event?.ends_at) return false;
    return new Date(event.starts_at) <= now && new Date(event.ends_at) >= now;
  });

  return ongoing ? String(ongoing.id) : (events[0] ? String(events[0].id) : "");
}

function deriveOperationalModes(assignments = []) {
  const grouped = new Map();

  assignments.forEach((item) => {
    const sectorId = normalizeScannerModeValue(item?.sector || "");
    const costBucket = normalizeScannerModeValue(item?.cost_bucket || "operational");
    if (!sectorId || costBucket === "managerial") {
      return;
    }

    const current = grouped.get(sectorId) || {
      id: sectorId,
      label: formatSectorLabel(sectorId),
      assignments: 0,
      members: new Set(),
    };

    current.assignments += 1;
    if (item?.participant_id) {
      current.members.add(String(item.participant_id));
    }
    grouped.set(sectorId, current);
  });

  return Array.from(grouped.values())
    .map((entry) => ({
      id: entry.id,
      label: entry.label,
      assignments: entry.assignments,
      members: entry.members.size,
    }))
    .sort((left, right) => left.label.localeCompare(right.label, "pt-BR"));
}

function buildScannerReturnPath(mode, fallback) {
  if (fallback) {
    return fallback;
  }

  return mode === "portaria" ? "/tickets" : "/";
}

export default function Scanner() {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();

  const requestedMode = normalizeScannerModeValue(searchParams.get("mode") || "");
  const requestedEventId = String(searchParams.get("event_id") || "");

  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState(requestedEventId);
  const [sectorModes, setSectorModes] = useState([]);
  const [eventsFromCache, setEventsFromCache] = useState(false);
  const [sectorsFromCache, setSectorsFromCache] = useState(false);
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [scanResult, setScanResult] = useState(null);
  const [isScanning, setIsScanning] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [cameraError, setCameraError] = useState("");
  const [operationMode, setOperationMode] = useState(requestedMode === "portaria" ? "portaria" : "");
  const [manualCode, setManualCode] = useState("");

  const scannerRef = useRef(null);
  const manualInputRef = useRef(null);
  const invalidRequestedModeNotifiedRef = useRef(false);
  const qrCodeRegionId = "qr-reader";

  const selectedEvent =
    events.find((event) => String(event.id) === String(eventId)) || null;
  const modeLocked =
    requestedMode === "portaria" ||
    (requestedMode !== "" && sectorModes.some((item) => item.id === requestedMode));
  const returnTo = buildScannerReturnPath(requestedMode, location.state?.returnTo);

  const startScanner = async () => {
    setScanResult(null);
    setCameraError("");
    setIsScanning(true);

    try {
      if (!scannerRef.current) {
        scannerRef.current = new Html5Qrcode(qrCodeRegionId);
      }

      await scannerRef.current.start(
        { facingMode: "environment" },
        {
          fps: 10,
          qrbox: { width: 250, height: 250 },
          aspectRatio: 1.0,
        },
        async (decodedText) => {
          scannerRef.current.pause();
          handleScan(decodedText);
        },
        () => {}
      );
    } catch (err) {
      console.error(err);
      setCameraError("Não foi possível acessar a câmera. Verifique as permissões.");
      setIsScanning(false);
    }
  };

  const stopScanner = () => {
    if (scannerRef.current && scannerRef.current.isScanning) {
      scannerRef.current.stop().catch(console.error);
    }
    setIsScanning(false);
  };

  useEffect(() => stopScanner, []);

  useEffect(() => {
    let cancelled = false;

    const loadEvents = async () => {
      setCatalogLoading(true);
      try {
        const res = await api.get("/events");
        if (cancelled) return;

        const list = res.data?.data || [];
        setEvents(list);
        setEventsFromCache(false);
        writeJsonCache(SCANNER_EVENTS_CACHE_KEY, { data: list });

        const nextEventId = selectDefaultEventId(list, requestedEventId, "");
        if (nextEventId) {
          setEventId(nextEventId);
        }
      } catch {
        if (cancelled) return;

        const cached = readJsonCache(SCANNER_EVENTS_CACHE_KEY);
        const cachedEvents = cached?.data || [];
        if (cachedEvents.length > 0) {
          setEvents(cachedEvents);
          setEventsFromCache(true);
          const nextEventId = selectDefaultEventId(cachedEvents, requestedEventId, "");
          if (nextEventId) {
            setEventId(nextEventId);
          }
          toast("Modo offline: eventos do scanner carregados do cache.");
        } else {
          toast.error("Erro ao carregar eventos do scanner.");
        }
      } finally {
        if (!cancelled) {
          setCatalogLoading(false);
        }
      }
    };

    loadEvents();

    return () => {
      cancelled = true;
    };
  }, [requestedEventId]);

  useEffect(() => {
    if (!eventId) {
      setSectorModes([]);
      setSectorsFromCache(false);
      if (!modeLocked) {
        setOperationMode(requestedMode === "portaria" ? "portaria" : "");
      }
      return;
    }

    let cancelled = false;
    const cacheKey = `${SCANNER_SECTORS_CACHE_PREFIX}_${eventId}`;

    const loadSectorModes = async () => {
      setCatalogLoading(true);
      try {
        const res = await api.get("/workforce/assignments", {
          params: { event_id: eventId },
        });
        if (cancelled) return;

        const nextModes = deriveOperationalModes(res.data?.data || []);
        setSectorModes(nextModes);
        setSectorsFromCache(false);
        writeJsonCache(cacheKey, { data: nextModes });
      } catch {
        if (cancelled) return;

        const cached = readJsonCache(cacheKey);
        const cachedModes = cached?.data || [];
        if (cachedModes.length > 0) {
          setSectorModes(cachedModes);
          setSectorsFromCache(true);
          toast("Modo offline: setores do scanner carregados do cache.");
        } else {
          setSectorModes([]);
          toast.error("Erro ao carregar setores operacionais do scanner.");
        }
      } finally {
        if (!cancelled) {
          setCatalogLoading(false);
        }
      }
    };

    loadSectorModes();

    return () => {
      cancelled = true;
    };
  }, [eventId]);

  useEffect(() => {
    if (requestedMode === "portaria") {
      setOperationMode("portaria");
      return;
    }

    if (modeLocked) {
      setOperationMode(requestedMode);
      return;
    }

    if (
      requestedMode &&
      eventId &&
      !catalogLoading &&
      !invalidRequestedModeNotifiedRef.current
    ) {
      invalidRequestedModeNotifiedRef.current = true;
      toast.error("O setor solicitado não está disponível para o evento selecionado.");
    }

    if (
      operationMode &&
      operationMode !== "portaria" &&
      !sectorModes.some((item) => item.id === operationMode)
    ) {
      setOperationMode("");
    }
  }, [catalogLoading, eventId, modeLocked, operationMode, requestedMode, sectorModes]);

  useEffect(() => {
    if (!operationMode || scanResult || isProcessing) return;

    const keepManualFocus = () => {
      const activeTag = document.activeElement?.tagName?.toLowerCase();
      if (activeTag === "button" || activeTag === "select") return;

      if (manualInputRef.current && document.activeElement !== manualInputRef.current) {
        manualInputRef.current.focus();
      }
    };

    keepManualFocus();
    const focusInterval = setInterval(keepManualFocus, 500);
    return () => clearInterval(focusInterval);
  }, [isProcessing, operationMode, scanResult]);

  const handleScan = async (qrData) => {
    setIsProcessing(true);
    try {
      let data;

      if (operationMode === "portaria") {
        try {
          const ticketResponse = await api.post("/tickets/validate", {
            dynamic_token: qrData,
          });

          data = {
            message: ticketResponse.data?.message || "Acesso liberado!",
            data: {
              ...ticketResponse.data?.data,
              info: "Ingresso validado",
            },
          };
        } catch (ticketErr) {
          if (ticketErr.response?.status !== 404) {
            throw ticketErr;
          }

          const fallbackResponse = await api.post("/scanner/process", {
            token: qrData,
            mode: operationMode,
          });
          data = fallbackResponse.data;
        }
      } else {
        const response = await api.post("/scanner/process", {
          token: qrData,
          mode: operationMode,
        });
        data = response.data;
      }

      setScanResult({
        type: "success",
        message: data.message || "Leitura Aprovada!",
        details: data.data,
      });
      toast.success("Leitura aprovada!");
    } catch (err) {
      const msg = err.response?.data?.message || "Erro na validação do QR Code.";
      const lowerMsg = msg.toLowerCase();

      let type = "error";
      if (
        lowerMsg.includes("já utilizado") ||
        lowerMsg.includes("já validado") ||
        lowerMsg.includes("already used")
      ) {
        type = "warning";
      } else if (lowerMsg.includes("limite") || lowerMsg.includes("cota atingida")) {
        type = "warning";
      } else if (lowerMsg.includes("bloqueado") || lowerMsg.includes("inapto")) {
        type = "error";
      }

      setScanResult({
        type,
        message: msg,
      });
      toast.error(type === "warning" ? "Atenção na leitura" : "Erro na leitura");
    } finally {
      setIsProcessing(false);
    }
  };

  const handleManualSubmit = (e) => {
    e.preventDefault();
    if (manualCode.trim()) {
      handleScan(manualCode.trim());
      setManualCode("");
    }
  };

  const resetScanner = () => {
    setScanResult(null);
    if (scannerRef.current && isScanning) {
      scannerRef.current.resume();
    } else {
      startScanner();
    }
  };

  const handleSelectMode = (mode) => {
    setScanResult(null);
    setManualCode("");
    setOperationMode(mode);
  };

  const handleChangeSector = () => {
    if (modeLocked) {
      return;
    }
    stopScanner();
    setOperationMode("");
    setScanResult(null);
    setManualCode("");
  };

  const handleExitScanner = () => {
    stopScanner();
    navigate(returnTo);
  };

  const handleEventChange = (nextEventId) => {
    if (modeLocked) {
      return;
    }

    stopScanner();
    setEventId(nextEventId);
    setOperationMode("");
    setScanResult(null);
    setManualCode("");
    setCameraError("");
    invalidRequestedModeNotifiedRef.current = false;
  };

  return (
    <div className="max-w-md mx-auto space-y-6 pb-20">
      <div className="space-y-4">
        <button
          type="button"
          onClick={handleExitScanner}
          className="inline-flex items-center gap-2 rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-700"
        >
          <ArrowLeft size={16} /> Voltar
        </button>

        <div className="text-center">
          <h1 className="text-2xl font-bold text-white flex items-center justify-center gap-2">
            <Camera className="text-brand" /> Scanner Operacional
          </h1>
          <p className="text-sm text-gray-400 mt-1">
            Portaria fixa para guest e setores dinâmicos vindos do Workforce.
          </p>
        </div>
      </div>

      {(eventsFromCache || sectorsFromCache) && (
        <div className="card border border-amber-900/60 bg-amber-950/30 p-4 text-sm text-amber-100">
          <div className="flex items-center gap-2 font-semibold">
            <WifiOff size={16} /> Operação com cache local
          </div>
          <p className="mt-1 text-xs text-amber-100/90">
            O scanner carregou {eventsFromCache ? "eventos" : "setores"} do dispositivo para manter a operação sem internet.
          </p>
        </div>
      )}

      {!operationMode ? (
        <div className="card p-6 space-y-4">
          <h2 className="text-lg font-semibold text-white text-center">Evento e setor do scanner</h2>

          <div className="space-y-2">
            <label className="text-xs font-semibold uppercase tracking-wide text-gray-400">
              Evento
            </label>
            <select
              className="input"
              value={eventId}
              onChange={(e) => handleEventChange(e.target.value)}
              disabled={catalogLoading || modeLocked}
            >
              <option value="">Selecione o evento...</option>
              {events.map((event) => (
                <option key={event.id} value={event.id}>
                  {event.name}
                </option>
              ))}
            </select>
          </div>

          <div className="grid gap-3">
            <button
              type="button"
              onClick={() => handleSelectMode("portaria")}
              className="btn-secondary justify-start"
            >
              <MapPin size={18} /> Portaria (guest e ingressos)
            </button>

            {sectorModes.map((mode) => (
              <button
                key={mode.id}
                type="button"
                onClick={() => handleSelectMode(mode.id)}
                className="btn-secondary justify-between"
                disabled={!eventId}
              >
                <span className="inline-flex items-center gap-2">
                  <MapPin size={18} /> {mode.label}
                </span>
                <span className="text-xs text-gray-400">
                  {mode.members} pessoa(s)
                </span>
              </button>
            ))}
          </div>

          {catalogLoading ? (
            <p className="text-sm text-gray-500 text-center">Carregando catálogo operacional...</p>
          ) : eventId && sectorModes.length === 0 ? (
            <p className="text-sm text-gray-500 text-center">
              Nenhum setor operacional com assignments visíveis foi encontrado neste evento.
            </p>
          ) : null}
        </div>
      ) : (
        <>
          <div className="card p-4 flex items-center justify-between gap-4">
            <div className="space-y-1">
              <div className="flex items-center gap-3">
                <MapPin className="text-brand" size={20} />
                <span className="font-semibold text-white">
                  Setor: {operationMode === "portaria" ? "Portaria" : formatSectorLabel(operationMode)}
                </span>
              </div>
              {selectedEvent ? (
                <p className="text-xs text-gray-400">
                  Evento: {selectedEvent.name}
                </p>
              ) : null}
            </div>
            {!modeLocked ? (
              <button
                onClick={handleChangeSector}
                className="bg-gray-800 hover:bg-gray-700 text-white text-sm py-1.5 px-4 rounded-lg transition-colors font-medium"
              >
                Trocar Setor
              </button>
            ) : null}
          </div>

          <div className="relative rounded-2xl overflow-hidden bg-black border-2 border-gray-800 shadow-2xl aspect-square flex items-center justify-center">
            {!isScanning && !scanResult && (
              <button onClick={startScanner} className="btn-primary flex items-center gap-2">
                <Camera size={20} /> Ligar Câmera
              </button>
            )}

            <div id={qrCodeRegionId} className={`w-full h-full ${scanResult ? "hidden" : "block"}`} />

            {cameraError && (
              <div className="absolute inset-0 bg-gray-900 flex items-center justify-center p-6 text-center text-red-400">
                {cameraError}
              </div>
            )}

            {isProcessing && (
              <div className="absolute inset-0 bg-black/90 flex flex-col items-center justify-center p-6 text-center z-10 animate-fade-in backdrop-blur-sm">
                <Loader2 size={64} className="text-brand animate-spin mb-4" />
                <h2 className="text-2xl font-bold text-white tracking-widest uppercase">Processando</h2>
              </div>
            )}

            {scanResult && !isProcessing && (
              <div
                className={`absolute inset-0 flex flex-col items-center justify-center p-6 text-center animate-in fade-in zoom-in duration-200 z-10 ${
                  scanResult.type === "success"
                    ? "bg-green-600"
                    : scanResult.type === "warning"
                      ? "bg-amber-500"
                      : "bg-red-600"
                }`}
              >
                {scanResult.type === "success" && <CheckCircle2 size={80} className="text-white mb-4 stroke-[2.5]" />}
                {scanResult.type === "warning" && <AlertTriangle size={80} className="text-white mb-4 stroke-[2.5]" />}
                {scanResult.type === "error" && <XCircle size={80} className="text-white mb-4 stroke-[2.5]" />}

                <h2 className="text-4xl font-black text-white leading-tight mb-2 tracking-wider uppercase drop-shadow-md">
                  {scanResult.type === "success" ? "APROVADO" : scanResult.type === "warning" ? "ATENÇÃO" : "NEGADO"}
                </h2>
                <p className="text-white/95 font-semibold text-xl px-2 drop-shadow">
                  {scanResult.message}
                </p>

                {scanResult.details?.holder_name && (
                  <div className="mt-6 bg-black/20 rounded-xl p-4 w-full border border-white/20 backdrop-blur-sm self-stretch mx-4">
                    <p className="text-white font-black text-2xl truncate">{scanResult.details.holder_name}</p>
                    {scanResult.details?.info && (
                      <p className="text-white/90 font-medium mt-1 uppercase tracking-wider text-sm">
                        {scanResult.details.info}
                      </p>
                    )}
                  </div>
                )}

                <button
                  onClick={resetScanner}
                  className="mt-8 px-10 py-4 bg-white text-black font-black rounded-full shadow-[0_8px_30px_rgb(0,0,0,0.3)] hover:bg-gray-100 active:scale-95 transition-all w-full max-w-[280px] text-lg uppercase tracking-wider"
                >
                  Ler Próximo
                </button>
              </div>
            )}
          </div>

          <form onSubmit={handleManualSubmit} className="card p-4 space-y-3">
            <h3 className="text-sm font-semibold text-gray-400 flex items-center gap-2">
              <Keyboard size={16} /> Digitar Código Manualmente
            </h3>
            <div className="flex gap-2">
              <input
                ref={manualInputRef}
                type="text"
                placeholder="Ex: EF-IMP-1234ABCD"
                className="input flex-1"
                value={manualCode}
                onChange={(e) => setManualCode(e.target.value)}
                disabled={!!scanResult}
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
              />
              <button
                type="submit"
                className="btn-primary whitespace-nowrap"
                disabled={!manualCode.trim() || !!scanResult}
              >
                Validar
              </button>
            </div>
          </form>
        </>
      )}
    </div>
  );
}
