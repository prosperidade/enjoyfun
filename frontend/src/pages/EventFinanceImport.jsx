import { useState, useRef, useCallback } from "react";
import { useEffect } from "react";
import {
  Upload, CheckCircle, AlertCircle, ChevronRight,
  FileText, X, FolderOpen,
} from "lucide-react";
import { useEventScope } from "../context/EventScopeContext";
import api from "../lib/api";
import toast from "react-hot-toast";

const IMPORT_TYPES = [
  {
    value: "payables",
    label: "Contas a Pagar",
    description: "description, category_id, cost_center_id, amount, due_date, supplier_id, payment_method, notes",
  },
  {
    value: "suppliers",
    label: "Fornecedores",
    description: "legal_name, trade_name, document_number, pix_key, contact_email, contact_phone",
  },
  {
    value: "budget_lines",
    label: "Orçamento (Linhas)",
    description: "budget_id, category_id, cost_center_id, description, budgeted_amount",
  },
];

const STEPS = ["1. Configurar", "2. Selecionar arquivo", "3. Preview", "4. Confirmar"];

function parseCsvText(text) {
  const lines = text.trim().split(/\r?\n/).filter(Boolean);
  if (lines.length < 2) return { headers: [], rows: [] };

  // Detecta separador: tabulação, ponto-e-vírgula ou vírgula
  const firstLine = lines[0];
  const sep = firstLine.includes("\t") ? "\t" : firstLine.includes(";") ? ";" : ",";

  const parseLine = (line) => {
    const result = [];
    let inQuote = false;
    let current = "";
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (ch === '"') {
        if (inQuote && line[i + 1] === '"') { current += '"'; i++; }
        else inQuote = !inQuote;
      } else if (ch === sep && !inQuote) {
        result.push(current.trim());
        current = "";
      } else {
        current += ch;
      }
    }
    result.push(current.trim());
    return result;
  };

  const headers = parseLine(lines[0]).map((h) => h.toLowerCase().replace(/ /g, "_").replace(/[^a-z0-9_]/g, ""));
  const rows = lines.slice(1).map((line) => {
    const cols = parseLine(line);
    const row = {};
    headers.forEach((h, i) => { row[h] = cols[i] ?? ""; });
    return row;
  });

  return { headers, rows };
}

function FileDropZone({ onFile, file }) {
  const inputRef = useRef();
  const [dragging, setDragging] = useState(false);

  const handleDrop = useCallback((e) => {
    e.preventDefault();
    setDragging(false);
    const f = e.dataTransfer.files[0];
    if (f) onFile(f);
  }, [onFile]);

  const handleChange = (e) => {
    const f = e.target.files[0];
    if (f) onFile(f);
  };

  return (
    <div
      onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
      onDragLeave={() => setDragging(false)}
      onDrop={handleDrop}
      onClick={() => inputRef.current?.click()}
      className={`border-2 border-dashed rounded-xl p-10 text-center cursor-pointer transition-colors
        ${dragging ? "border-cyan-400 bg-cyan-400/5" : file ? "border-green-500/50 bg-green-900/5" : "border-white/10 hover:border-white/20"}`}
    >
      <input ref={inputRef} type="file" accept=".csv,.tsv,.txt" className="hidden" onChange={handleChange} />
      {file ? (
        <div className="space-y-2">
          <FileText size={32} className="text-green-400 mx-auto" />
          <p className="text-green-400 font-medium">{file.name}</p>
          <p className="text-xs text-gray-500">{(file.size / 1024).toFixed(1)} KB</p>
        </div>
      ) : (
        <div className="space-y-3">
          <Upload size={32} className="text-gray-500 mx-auto" />
          <div>
            <p className="text-gray-300 font-medium">Arraste o arquivo CSV aqui</p>
            <p className="text-xs text-gray-500 mt-1">ou clique para selecionar — CSV, TSV até 5 MB</p>
          </div>
          <button type="button" className="btn-outline text-sm mx-auto flex items-center gap-2">
            <FolderOpen size={14} /> Escolher arquivo
          </button>
        </div>
      )}
    </div>
  );
}

export default function EventFinanceImport() {
  const { eventId, setEventId } = useEventScope();
  const [step, setStep] = useState(0);
  const [events, setEvents] = useState([]);
  const [importType, setImportType] = useState("payables");
  const [file, setFile] = useState(null);
  const [parsedRows, setParsedRows] = useState([]);
  const [parseError, setParseError] = useState("");
  const [preview, setPreview] = useState(null);
  const [batchId, setBatchId] = useState(null);
  const [confirming, setConfirming] = useState(false);
  const [result, setResult] = useState(null);

  useEffect(() => {
    api.get("/events").then((r) => setEvents(r.data.data || [])).catch(() => {});
  }, []);

  const handleFile = (f) => {
    if (f.size > 5 * 1024 * 1024) { toast.error("Arquivo muito grande (máx. 5 MB)."); return; }
    setFile(f);
    setParseError("");
    setParsedRows([]);

    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const { rows } = parseCsvText(text);
      if (rows.length === 0) {
        setParseError("Nenhuma linha detectada. Certifique-se de que o CSV tem cabeçalho na primeira linha.");
        return;
      }
      setParsedRows(rows);
    };
    reader.readAsText(f, "utf-8");
  };

  const handlePreview = async () => {
    if (parsedRows.length === 0) {
      toast.error("Nenhuma linha parseada. Verifique o arquivo.");
      return;
    }
    try {
      const res = await api.post("/event-finance/imports/preview", {
        import_type: importType,
        source_filename: file?.name || `importacao_${importType}.csv`,
        event_id: eventId ? parseInt(eventId) : null,
        rows: parsedRows,
      });
      setPreview(res.data.data);
      setBatchId(res.data.data.batch_id);
      setStep(2);
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao gerar preview.");
    }
  };

  const handleConfirm = async () => {
    if (!batchId) return;
    setConfirming(true);
    try {
      const res = await api.post("/event-finance/imports/confirm", { batch_id: batchId });
      setResult(res.data.data);
      setStep(3);
      toast.success(`${res.data.data.applied} linha(s) importada(s)!`);
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao confirmar importação.");
    } finally {
      setConfirming(false);
    }
  };

  const reset = () => {
    setStep(0);
    setFile(null);
    setParsedRows([]);
    setParseError("");
    setPreview(null);
    setBatchId(null);
    setResult(null);
  };

  const selectedType = IMPORT_TYPES.find((t) => t.value === importType);

  return (
    <div className="space-y-6 max-w-3xl">
      <div>
        <h1 className="page-title flex items-center gap-2">
          <Upload size={22} className="text-cyan-400" /> Importação em Lote
        </h1>
        <p className="text-gray-500 text-sm">Importe fornecedores, contas a pagar ou orçamentos via arquivo CSV</p>
      </div>

      {/* Stepper */}
      <div className="flex items-center gap-1">
        {STEPS.map((s, i) => (
          <div key={i} className="flex items-center gap-1 flex-1 last:flex-none">
            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0
              ${i < step ? "bg-green-500 text-white" : i === step ? "bg-cyan-500 text-white" : "bg-white/10 text-gray-500"}`}>
              {i < step ? <CheckCircle size={14} /> : i + 1}
            </div>
            <span className={`text-xs hidden md:block truncate ${i === step ? "text-cyan-400" : "text-gray-500"}`}>{s}</span>
            {i < STEPS.length - 1 && <ChevronRight size={14} className="text-gray-700 flex-shrink-0" />}
          </div>
        ))}
      </div>

      {/* Step 0: Configurar */}
      {step === 0 && (
        <div className="card border-white/5 space-y-4">
          <h2 className="section-title">O que deseja importar?</h2>
          <div className="grid grid-cols-1 gap-3">
            {IMPORT_TYPES.map((t) => (
              <label
                key={t.value}
                className={`flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors
                  ${importType === t.value ? "border-cyan-500/60 bg-cyan-900/10" : "border-white/5 hover:border-white/10"}`}
              >
                <input
                  type="radio"
                  name="importType"
                  value={t.value}
                  checked={importType === t.value}
                  onChange={(e) => setImportType(e.target.value)}
                  className="mt-0.5 accent-cyan-500"
                />
                <div>
                  <p className="font-medium text-white">{t.label}</p>
                  <p className="text-xs text-gray-500 mt-0.5">Colunas: <code className="text-cyan-400">{t.description}</code></p>
                </div>
              </label>
            ))}
          </div>

          {(importType === "payables" || importType === "budget_lines") && (
            <div>
              <label className="input-label">Evento</label>
              <select className="select" value={eventId} onChange={(e) => setEventId(e.target.value)}>
                <option value="">Selecionar evento (opcional)...</option>
                {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
              </select>
            </div>
          )}

          <button onClick={() => setStep(1)} className="btn-primary w-full">
            Próximo: Selecionar Arquivo
          </button>
        </div>
      )}

      {/* Step 1: Upload do arquivo */}
      {step === 1 && (
        <div className="card border-white/5 space-y-4">
          <h2 className="section-title">Selecionar arquivo CSV</h2>
          <p className="text-sm text-gray-400">
            Importe um arquivo <strong>.csv</strong> ou <strong>.tsv</strong>. A primeira linha deve ser o cabeçalho.
            Separadores aceitos: vírgula, ponto-e-vírgula ou tabulação.
          </p>

          <div className="bg-black/20 border border-white/5 rounded-lg p-3">
            <p className="text-xs text-gray-500 mb-1">Cabeçalho esperado:</p>
            <code className="text-xs font-mono text-cyan-400">{selectedType?.description}</code>
          </div>

          <FileDropZone onFile={handleFile} file={file} />

          {file && parsedRows.length > 0 && (
            <div className="flex items-center gap-3 p-3 rounded-lg bg-green-900/10 border border-green-500/20 text-sm text-green-400">
              <CheckCircle size={16} />
              <span><strong>{parsedRows.length}</strong> linha(s) detectada(s) em <strong>{file.name}</strong></span>
              <button
                onClick={() => { setFile(null); setParsedRows([]); }}
                className="ml-auto text-gray-500 hover:text-white"
              >
                <X size={14} />
              </button>
            </div>
          )}

          {parseError && (
            <div className="flex items-start gap-2 p-3 rounded-lg bg-red-900/10 border border-red-500/20 text-sm text-red-400">
              <AlertCircle size={16} className="flex-shrink-0 mt-0.5" />
              <span>{parseError}</span>
            </div>
          )}

          <div className="flex gap-3">
            <button onClick={() => setStep(0)} className="btn-outline flex-1">Voltar</button>
            <button
              onClick={handlePreview}
              disabled={parsedRows.length === 0}
              className="btn-primary flex-1"
            >
              Gerar Preview ({parsedRows.length} linha{parsedRows.length !== 1 ? "s" : ""})
            </button>
          </div>
        </div>
      )}

      {/* Step 2: Preview */}
      {step === 2 && preview && (
        <div className="space-y-4">
          <div className="card border-white/5">
            <h2 className="section-title">Preview de importação</h2>
            <div className="flex gap-6 mt-3 text-sm">
              <span className="text-green-400">✓ {preview.valid} válida(s)</span>
              <span className={preview.invalid > 0 ? "text-red-400" : "text-gray-500"}>
                ✕ {preview.invalid} inválida(s)
              </span>
              <span className="text-gray-400">Total: {preview.total_rows}</span>
            </div>
          </div>

          {preview.errors?.length > 0 && (
            <div className="card border-red-500/30 bg-red-900/5">
              <p className="text-red-400 text-sm font-medium mb-2 flex items-center gap-1">
                <AlertCircle size={14} /> Linhas com erro
              </p>
              <ul className="text-xs text-gray-400 space-y-1 max-h-32 overflow-y-auto">
                {preview.errors.map((e, i) => (
                  <li key={i}>Linha {e.row}: {e.errors?.join(", ")}</li>
                ))}
              </ul>
            </div>
          )}

          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Linha</th>
                  <th>Status</th>
                  <th>Dados</th>
                  <th>Erro</th>
                </tr>
              </thead>
              <tbody>
                {preview.preview?.slice(0, 30).map((r) => (
                  <tr key={r.row_number} className={r.row_status === "invalid" ? "bg-red-900/10" : ""}>
                    <td className="font-mono text-xs">{r.row_number}</td>
                    <td>
                      {r.row_status === "valid"
                        ? <span className="badge-green">Válida</span>
                        : <span className="badge-red">Inválida</span>}
                    </td>
                    <td className="text-xs text-gray-400 max-w-[200px] truncate">
                      {Object.values(r.raw_payload || {}).slice(0, 4).join(" | ")}
                    </td>
                    <td className="text-xs text-red-400">
                      {r.error_messages?.join(", ") || "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {preview.total_rows > 30 && (
              <p className="text-xs text-gray-600 p-2 text-center">Exibindo 30 de {preview.total_rows} linhas.</p>
            )}
          </div>

          <div className="flex gap-3">
            <button onClick={() => setStep(1)} className="btn-outline flex-1">Voltar</button>
            <button
              onClick={handleConfirm}
              disabled={confirming || !preview.can_confirm}
              className="btn-primary flex-1"
            >
              {confirming ? "Importando..." : `Confirmar ${preview.valid} linha(s) válida(s)`}
            </button>
          </div>
        </div>
      )}

      {/* Step 3: Resultado */}
      {step === 3 && result && (
        <div className="card border-green-500/30 bg-green-900/5 text-center space-y-4 py-10">
          <CheckCircle size={48} className="text-green-400 mx-auto" />
          <h2 className="text-xl font-bold text-green-400">Importação concluída!</h2>
          <div className="flex justify-center gap-8 text-sm">
            <div>
              <p className="text-2xl font-bold text-white">{result.applied}</p>
              <p className="text-gray-400">Registro(s) criado(s)</p>
            </div>
            {result.skipped > 0 && (
              <div>
                <p className="text-2xl font-bold text-yellow-400">{result.skipped}</p>
                <p className="text-gray-400">Ignorado(s)</p>
              </div>
            )}
          </div>
          <button onClick={reset} className="btn-primary mx-auto">
            Nova Importação
          </button>
        </div>
      )}
    </div>
  );
}
