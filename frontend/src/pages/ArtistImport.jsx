import { useEffect, useRef, useState } from "react";
import {
  AlertCircle,
  CheckCircle,
  ChevronRight,
  FileText,
  FolderOpen,
  MicVocal,
  ShieldAlert,
  Upload,
  X,
} from "lucide-react";
import { Link, useSearchParams } from "react-router-dom";
import api from "../lib/api";
import toast from "react-hot-toast";
import { useAuth } from "../context/AuthContext";
import { useEventScope } from "../context/EventScopeContext";

const IMPORT_TYPES = [
  {
    value: "bookings",
    label: "Bookings",
    description:
      "artist_id ou artist_stage_name, performance_start_at, performance_duration_minutes, soundcheck_at, cache_amount, booking_stage_name",
  },
  {
    value: "logistics",
    label: "Logistica",
    description:
      "event_artist_id ou artist_id, arrival_origin, arrival_mode, arrival_at, hotel_name, hotel_check_in_at, departure_destination, departure_at",
  },
  {
    value: "team",
    label: "Equipe",
    description:
      "event_artist_id ou artist_id, full_name, role_name, phone, needs_hotel, needs_transfer, notes",
  },
];

const STEPS = ["1. Configurar", "2. Selecionar arquivo", "3. Preview", "4. Confirmar"];

function parseCsvText(text) {
  const lines = text.trim().split(/\r?\n/).filter(Boolean);
  if (lines.length < 2) {
    return { headers: [], rows: [] };
  }

  const firstLine = lines[0];
  const separator = firstLine.includes("\t") ? "\t" : firstLine.includes(";") ? ";" : ",";

  function parseLine(line) {
    const columns = [];
    let current = "";
    let inQuotes = false;

    for (let index = 0; index < line.length; index += 1) {
      const char = line[index];
      if (char === '"') {
        if (inQuotes && line[index + 1] === '"') {
          current += '"';
          index += 1;
        } else {
          inQuotes = !inQuotes;
        }
      } else if (char === separator && !inQuotes) {
        columns.push(current.trim());
        current = "";
      } else {
        current += char;
      }
    }

    columns.push(current.trim());
    return columns;
  }

  const headers = parseLine(lines[0]).map((header) =>
    header.toLowerCase().replace(/ /g, "_").replace(/[^a-z0-9_]/g, "")
  );
  const rows = lines.slice(1).map((line) => {
    const columns = parseLine(line);
    const row = {};
    headers.forEach((header, index) => {
      row[header] = columns[index] ?? "";
    });
    return row;
  });

  return { headers, rows };
}

function FileDropZone({ onFile, file }) {
  const inputRef = useRef(null);
  const [dragging, setDragging] = useState(false);

  return (
    <div
      onDragOver={(event) => {
        event.preventDefault();
        setDragging(true);
      }}
      onDragLeave={() => setDragging(false)}
      onDrop={(event) => {
        event.preventDefault();
        setDragging(false);
        const droppedFile = event.dataTransfer.files[0];
        if (droppedFile) {
          onFile(droppedFile);
        }
      }}
      onClick={() => inputRef.current?.click()}
      className={`cursor-pointer rounded-2xl border-2 border-dashed p-10 text-center backdrop-blur-md transition-colors ${
        dragging
          ? "border-cyan-400 bg-cyan-500/5"
          : file
            ? "border-green-500/50 bg-green-900/5"
            : "border-slate-700/50 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)]"
      }`}
    >
      <input
        ref={inputRef}
        type="file"
        accept=".csv,.tsv,.txt"
        className="hidden"
        onChange={(event) => {
          const nextFile = event.target.files?.[0];
          if (nextFile) {
            onFile(nextFile);
          }
        }}
      />

      {file ? (
        <div className="space-y-2">
          <FileText size={32} className="mx-auto text-green-400" />
          <p className="font-medium text-green-400">{file.name}</p>
          <p className="text-xs text-slate-500">{(file.size / 1024).toFixed(1)} KB</p>
        </div>
      ) : (
        <div className="space-y-3">
          <Upload size={32} className="mx-auto text-slate-500" />
          <div>
            <p className="font-medium text-slate-300">Arraste o CSV da operacao aqui</p>
            <p className="mt-1 text-xs text-slate-500">
              ou clique para selecionar. Aceita CSV, TSV e TXT ate 5 MB.
            </p>
          </div>
          <button type="button" className="mx-auto flex items-center gap-2 border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 text-sm transition-colors">
            <FolderOpen size={14} />
            Escolher arquivo
          </button>
        </div>
      )}
    </div>
  );
}

export default function ArtistImport() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { hasRole } = useAuth();
  const { buildScopedPath, eventId: scopedEventId, setEventId } = useEventScope();
  const canImport = hasRole("admin") || hasRole("organizer") || hasRole("manager");

  const [step, setStep] = useState(0);
  const [events, setEvents] = useState([]);
  const eventId = searchParams.get("event_id") || scopedEventId || "";
  const [importType, setImportType] = useState("bookings");
  const [file, setFile] = useState(null);
  const [parsedRows, setParsedRows] = useState([]);
  const [parseError, setParseError] = useState("");
  const [preview, setPreview] = useState(null);
  const [batchId, setBatchId] = useState(null);
  const [confirming, setConfirming] = useState(false);
  const [result, setResult] = useState(null);

  useEffect(() => {
    let cancelled = false;

    async function loadEvents() {
      try {
        const response = await api.get("/events");
        if (cancelled) {
          return;
        }

        const nextEvents = Array.isArray(response.data?.data) ? response.data.data : [];
        setEvents(nextEvents);

        if (!eventId && nextEvents.length > 0) {
          const nextEventId = String(nextEvents[0].id);
          setEventId(nextEventId, { updateUrl: false });
          const nextParams = new URLSearchParams(searchParams);
          nextParams.set("event_id", nextEventId);
          setSearchParams(nextParams, { replace: true });
        }
      } catch {
        if (!cancelled) {
          setEvents([]);
        }
      }
    }

    void loadEvents();
    return () => {
      cancelled = true;
    };
  }, [eventId, searchParams, setEventId, setSearchParams]);

  function handleFile(nextFile) {
    if (nextFile.size > 5 * 1024 * 1024) {
      toast.error("Arquivo muito grande (max. 5 MB).");
      return;
    }

    setFile(nextFile);
    setParseError("");
    setParsedRows([]);

    const reader = new FileReader();
    reader.onload = (event) => {
      const text = String(event.target?.result || "");
      const { rows } = parseCsvText(text);
      if (rows.length === 0) {
        setParseError("Nenhuma linha detectada. Confirme se a primeira linha contem cabecalho.");
        return;
      }
      setParsedRows(rows);
    };
    reader.readAsText(nextFile, "utf-8");
  }

  async function handlePreview() {
    if (!eventId) {
      toast.error("Selecione um evento antes de gerar o preview.");
      return;
    }
    if (parsedRows.length === 0) {
      toast.error("Nenhuma linha parseada. Verifique o arquivo.");
      return;
    }

    try {
      const response = await api.post("/artists/imports/preview", {
        event_id: Number(eventId),
        import_type: importType,
        source_filename: file?.name || `artists_${importType}.csv`,
        rows: parsedRows,
      });
      setPreview(response.data?.data || null);
      setBatchId(response.data?.data?.batch_id || null);
      setStep(2);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao gerar preview.");
    }
  }

  async function handleConfirm() {
    if (!batchId) {
      return;
    }

    setConfirming(true);
    try {
      const response = await api.post("/artists/imports/confirm", { batch_id: batchId });
      setResult(response.data?.data || null);
      setStep(3);
      toast.success("Importacao aplicada com sucesso.");
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao confirmar importacao.");
    } finally {
      setConfirming(false);
    }
  }

  function resetFlow() {
    setStep(0);
    setFile(null);
    setParsedRows([]);
    setParseError("");
    setPreview(null);
    setBatchId(null);
    setResult(null);
  }

  if (!canImport) {
    return (
      <div className="bg-[#111827] border border-red-500/20 rounded-2xl bg-red-500/5 text-center py-16 p-6">
        <ShieldAlert size={40} className="mx-auto text-red-400" />
        <h1 className="mt-4 text-xl font-semibold text-slate-100">Acesso restrito</h1>
        <p className="mx-auto mt-2 max-w-xl text-sm text-red-200/80">
          Importacao de artistas exige perfil `admin`, `organizer` ou `manager`.
        </p>
        <div className="mt-6">
          <Link to={buildScopedPath("/artists", eventId || scopedEventId)} className="inline-flex items-center gap-2 border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 text-sm transition-colors">
            Voltar ao catalogo
          </Link>
        </div>
      </div>
    );
  }

  const selectedType = IMPORT_TYPES.find((item) => item.value === importType);

  return (
    <div className="space-y-6 max-w-4xl">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <h1 className="flex items-center gap-3 text-2xl font-bold font-headline text-slate-100">
            <MicVocal size={22} className="text-cyan-400" />
            Importacao de artistas
          </h1>
          <p className="text-sm text-slate-400">
            Preview + confirmacao para bookings, logistica e equipe do artista.
          </p>
        </div>

        <Link to={buildScopedPath("/artists", eventId || scopedEventId)} className="inline-flex items-center gap-2 border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 text-sm transition-colors">
          Voltar ao catalogo
        </Link>
      </div>

      <div className="flex items-center gap-1">
        {STEPS.map((label, index) => (
          <div key={label} className="flex flex-1 items-center gap-1 last:flex-none">
            <div
              className={`flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold ${
                index < step
                  ? "bg-green-500 text-slate-950"
                  : index === step
                    ? "bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950"
                    : "bg-slate-800/50 text-slate-500"
              }`}
            >
              {index < step ? <CheckCircle size={14} /> : index + 1}
            </div>
            <span className={`hidden truncate text-xs md:block ${index === step ? "text-cyan-400" : "text-slate-500"}`}>
              {label}
            </span>
            {index < STEPS.length - 1 && <ChevronRight size={14} className="flex-shrink-0 text-slate-700" />}
          </div>
        ))}
      </div>

      {step === 0 && (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-6 space-y-4">
          <h2 className="text-lg font-semibold text-slate-200">Configurar lote</h2>

          <div>
            <label className="text-xs text-slate-400 uppercase tracking-wider">Evento</label>
            <select
              className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-sm text-slate-200 outline-none transition-colors"
              value={eventId}
              onChange={(event) => {
                const nextEventId = event.target.value;
                setEventId(nextEventId);
                const nextParams = new URLSearchParams(searchParams);
                if (nextEventId) {
                  nextParams.set("event_id", nextEventId);
                } else {
                  nextParams.delete("event_id");
                }
                setSearchParams(nextParams);
              }}
            >
              <option value="">Selecionar evento...</option>
              {events.map((eventItem) => (
                <option key={eventItem.id} value={eventItem.id}>
                  {eventItem.name}
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-1 gap-3">
            {IMPORT_TYPES.map((type) => (
              <label
                key={type.value}
                className={`cursor-pointer rounded-xl border p-4 transition-colors ${
                  importType === type.value
                    ? "border-cyan-500/50 bg-cyan-500/10"
                    : "border-slate-800/40 hover:border-cyan-500/30"
                }`}
              >
                <div className="flex items-start gap-3">
                  <input
                    type="radio"
                    name="artist-import-type"
                    value={type.value}
                    checked={importType === type.value}
                    onChange={(event) => setImportType(event.target.value)}
                    className="mt-0.5 accent-cyan-400"
                  />
                  <div className="space-y-1">
                    <p className="font-medium text-slate-100">{type.label}</p>
                    <p className="text-xs text-slate-500">
                      Colunas base: <code className="text-cyan-300">{type.description}</code>
                    </p>
                  </div>
                </div>
              </label>
            ))}
          </div>

          <button type="button" onClick={() => setStep(1)} className="w-full bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2.5 transition-all hover:shadow-[0_0_20px_rgba(0,240,255,0.15)]">
            Proximo: selecionar arquivo
          </button>
        </div>
      )}

      {step === 1 && (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-6 space-y-4">
          <h2 className="text-lg font-semibold text-slate-200">Selecionar arquivo</h2>
          <p className="text-sm text-slate-400">
            Use CSV, TSV ou TXT com cabecalho na primeira linha. O lote sera validado antes de qualquer gravacao.
          </p>

          <div className="rounded-xl border border-slate-800/40 bg-[#111827] p-3">
            <p className="mb-1 text-xs text-slate-500">Cabecalho esperado</p>
            <code className="text-xs text-cyan-300">{selectedType?.description}</code>
          </div>

          <FileDropZone onFile={handleFile} file={file} />

          {file && parsedRows.length > 0 && (
            <div className="flex items-center gap-3 rounded-lg border border-green-500/20 bg-green-900/10 p-3 text-sm text-green-400">
              <CheckCircle size={16} />
              <span>
                <strong>{parsedRows.length}</strong> linha(s) detectada(s) em <strong>{file.name}</strong>
              </span>
              <button
                type="button"
                onClick={() => {
                  setFile(null);
                  setParsedRows([]);
                }}
                className="ml-auto text-slate-500 hover:text-slate-100"
              >
                <X size={14} />
              </button>
            </div>
          )}

          {parseError && (
            <div className="flex items-start gap-2 rounded-lg border border-red-500/20 bg-red-900/10 p-3 text-sm text-red-400">
              <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
              <span>{parseError}</span>
            </div>
          )}

          <div className="flex gap-3">
            <button type="button" onClick={() => setStep(0)} className="flex-1 border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2.5 transition-colors">
              Voltar
            </button>
            <button
              type="button"
              onClick={handlePreview}
              disabled={parsedRows.length === 0 || !eventId}
              className="flex-1 bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2.5 transition-all hover:shadow-[0_0_20px_rgba(0,240,255,0.15)] disabled:opacity-50"
            >
              Gerar preview ({parsedRows.length})
            </button>
          </div>
        </div>
      )}

      {step === 2 && preview && (
        <div className="space-y-4">
          <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-6">
            <h2 className="text-lg font-semibold text-slate-200">Preview de importacao</h2>
            <div className="mt-3 flex flex-wrap gap-6 text-sm">
              <span className="text-green-400">✓ {preview.valid} valida(s)</span>
              <span className={preview.invalid > 0 ? "text-red-400" : "text-slate-500"}>
                ✕ {preview.invalid} invalida(s)
              </span>
              <span className="text-slate-400">Total: {preview.total_rows}</span>
              <span className="text-slate-500">Batch: {preview.batch_id}</span>
            </div>
          </div>

          {preview.errors?.length > 0 && (
            <div className="bg-[#111827] border border-red-500/30 rounded-2xl p-6 bg-red-900/5">
              <p className="mb-2 flex items-center gap-1 text-sm font-medium text-red-400">
                <AlertCircle size={14} />
                Linhas com erro
              </p>
              <ul className="max-h-32 space-y-1 overflow-y-auto text-xs text-slate-400">
                {preview.errors.map((item, index) => (
                  <li key={`${item.row}-${index}`}>
                    Linha {item.row}: {item.errors?.join(", ")}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="overflow-x-auto rounded-2xl border border-slate-800/40 bg-[#111827]">
            <table className="w-full text-sm">
              <thead>
                <tr>
                  <th className="bg-slate-800/50 text-slate-400 uppercase text-xs tracking-wider px-4 py-3 text-left">Linha</th>
                  <th className="bg-slate-800/50 text-slate-400 uppercase text-xs tracking-wider px-4 py-3 text-left">Status</th>
                  <th className="bg-slate-800/50 text-slate-400 uppercase text-xs tracking-wider px-4 py-3 text-left">Dados</th>
                  <th className="bg-slate-800/50 text-slate-400 uppercase text-xs tracking-wider px-4 py-3 text-left">Erro</th>
                </tr>
              </thead>
              <tbody>
                {preview.preview?.slice(0, 30).map((row) => (
                  <tr key={row.row_number} className={row.row_status === "invalid" ? "bg-red-900/10" : ""}>
                    <td className="font-mono text-xs px-4 py-3 border-b border-slate-800/30">{row.row_number}</td>
                    <td>
                      {row.row_status === "valid" ? (
                        <span className="badge-green">Valida</span>
                      ) : (
                        <span className="badge-red">Invalida</span>
                      )}
                    </td>
                    <td className="max-w-[260px] truncate text-xs text-slate-400">
                      {Object.values(row.raw_payload || {}).slice(0, 4).join(" | ")}
                    </td>
                    <td className="text-xs text-red-400">
                      {row.error_messages?.join(", ") || "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {preview.total_rows > 30 && (
              <p className="p-2 text-center text-xs text-gray-600">
                Exibindo 30 de {preview.total_rows} linhas.
              </p>
            )}
          </div>

          <div className="flex gap-3">
            <button type="button" onClick={() => setStep(1)} className="btn-outline flex-1">
              Voltar
            </button>
            <button
              type="button"
              onClick={handleConfirm}
              disabled={confirming || !preview.can_confirm}
              className="btn-primary flex-1"
            >
              {confirming ? "Aplicando..." : `Confirmar ${preview.valid} linha(s)`}
            </button>
          </div>
        </div>
      )}

      {step === 3 && result && (
        <div className="card border-green-500/30 bg-green-900/5 py-10 text-center space-y-4">
          <CheckCircle size={48} className="mx-auto text-green-400" />
          <h2 className="text-xl font-bold text-green-400">Importacao concluida</h2>
          <div className="flex flex-wrap justify-center gap-8 text-sm">
            <div>
              <p className="text-2xl font-bold text-white">{result.applied}</p>
              <p className="text-slate-400">Registro(s) aplicados</p>
            </div>
            <div>
              <p className="text-2xl font-bold text-yellow-400">{result.skipped}</p>
              <p className="text-slate-400">Linha(s) ignoradas</p>
            </div>
          </div>
          <div className="flex flex-wrap justify-center gap-3">
            <button type="button" onClick={resetFlow} className="btn-primary">
              Novo lote
            </button>
            <Link
              to={buildScopedPath("/artists", eventId || scopedEventId)}
              className="btn-outline"
            >
              Ir para artistas
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
