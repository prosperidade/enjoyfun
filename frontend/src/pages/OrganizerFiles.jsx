import { useCallback, useEffect, useState, useRef } from "react";
import toast from "react-hot-toast";
import {
  FileSpreadsheet,
  Upload,
  Trash2,
  RefreshCw,
  Eye,
  Bot,
  Sparkles,
  CheckCircle,
  AlertCircle,
  Clock,
  XCircle,
  Search,
  Zap,
  Loader2,
} from "lucide-react";
import api from "../lib/api";
import { useEventScope } from "../context/EventScopeContext";
import EmbeddedAIChat from "../components/EmbeddedAIChat";
import Pagination from "../components/Pagination";
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from "../lib/pagination";

const PAGE_SIZE = 20;

const CATEGORIES = [
  { value: "general", label: "Geral" },
  { value: "financial", label: "Financeiro" },
  { value: "contracts", label: "Contratos" },
  { value: "logistics", label: "Logistica" },
  { value: "marketing", label: "Marketing" },
  { value: "operational", label: "Operacional" },
  { value: "reports", label: "Relatorios" },
  { value: "spreadsheets", label: "Planilhas" },
];

const PARSED_STATUS_META = {
  pending: { icon: Clock, color: "text-gray-400", label: "Pendente" },
  parsing: { icon: RefreshCw, color: "text-blue-400", label: "Processando" },
  parsed: { icon: CheckCircle, color: "text-emerald-400", label: "Processado" },
  failed: { icon: XCircle, color: "text-red-400", label: "Erro" },
  skipped: { icon: AlertCircle, color: "text-yellow-400", label: "Ignorado" },
};

const EMBEDDING_STATUS_META = {
  pending: { color: "text-gray-500", label: null },
  indexing: { color: "text-blue-400", label: "Indexando...", animate: true },
  indexed: { color: "text-emerald-400", label: "Indexado" },
  failed: { color: "text-red-400", label: "Erro no indice" },
};

const LARGE_FILE_THRESHOLD = 1 * 1024 * 1024; // 1MB — files above this get the Google option

// Polling interval for files in transitional states (parsing/indexing)
const STATUS_POLL_INTERVAL = 5000;

function formatBytes(bytes) {
  if (!bytes || bytes <= 0) return "0 B";
  const units = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

function formatDate(dateStr) {
  if (!dateStr) return "-";
  return new Date(dateStr).toLocaleString("pt-BR", {
    day: "2-digit", month: "2-digit", year: "2-digit",
    hour: "2-digit", minute: "2-digit",
  });
}

export default function OrganizerFiles() {
  const { eventId } = useEventScope();
  const [files, setFiles] = useState([]);
  const [page, setPage] = useState(1);
  const [filesMeta, setFilesMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE });
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [filterCategory, setFilterCategory] = useState("");
  const [selectedFile, setSelectedFile] = useState(null);
  const [parsedData, setParsedData] = useState(null);
  const [loadingParsed, setLoadingParsed] = useState(false);
  const [uploadCategory, setUploadCategory] = useState("general");
  const [uploadNotes, setUploadNotes] = useState("");
  const fileInputRef = useRef(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState(null);
  const [searchLoading, setSearchLoading] = useState(false);
  const searchTimerRef = useRef(null);

  const handleSearchChange = (value) => {
    setSearchQuery(value);
    if (searchTimerRef.current) clearTimeout(searchTimerRef.current);
    if (!value.trim()) {
      setSearchResults(null);
      return;
    }
    searchTimerRef.current = setTimeout(async () => {
      setSearchLoading(true);
      try {
        const res = await api.get(`/organizer-files/search?q=${encodeURIComponent(value.trim())}`);
        setSearchResults(res.data?.data?.files || []);
      } catch {
        toast.error("Erro na busca.");
        setSearchResults(null);
      } finally {
        setSearchLoading(false);
      }
    }, 400);
  };

  const clearSearch = () => {
    setSearchQuery('');
    setSearchResults(null);
    if (searchTimerRef.current) clearTimeout(searchTimerRef.current);
  };

  const fetchFiles = useCallback(async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (eventId) params.set("event_id", eventId);
      if (filterCategory) params.set("category", filterCategory);
      params.set("page", String(page));
      params.set("per_page", String(PAGE_SIZE));
      const response = await api.get(`/organizer-files?${params.toString()}`);
      setFiles(response.data?.data || []);
      setFilesMeta(extractPaginationMeta(response.data?.meta, { ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page }));
    } catch {
      setFilesMeta({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: 1 });
      toast.error("Erro ao carregar arquivos.");
    } finally {
      setLoading(false);
    }
  }, [eventId, filterCategory, page]);

  useEffect(() => {
    setPage(1);
  }, [eventId, filterCategory]);

  useEffect(() => { fetchFiles(); }, [fetchFiles]);

  // Auto-poll when files are in transitional states (parsing/indexing)
  useEffect(() => {
    const hasTransitional = files.some(
      (f) => f.parsed_status === 'parsing' || f.embedding_status === 'indexing'
    );
    if (!hasTransitional) return;
    const timer = setInterval(fetchFiles, STATUS_POLL_INTERVAL);
    return () => clearInterval(timer);
  }, [files, fetchFiles]);

  const handleUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append("file", file);
    formData.append("category", uploadCategory);
    if (eventId) formData.append("event_id", eventId);
    if (uploadNotes.trim()) formData.append("notes", uploadNotes.trim());

    setUploading(true);
    try {
      await api.post("/organizer-files", formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      toast.success("Arquivo enviado com sucesso!");
      setUploadNotes("");
      fetchFiles();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao enviar arquivo.");
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  };

  const handleDelete = async (fileId) => {
    if (!confirm("Remover este arquivo permanentemente?")) return;
    try {
      await api.delete(`/organizer-files/${fileId}`);
      toast.success("Arquivo removido.");
      setFiles((current) => current.filter((f) => f.id !== fileId));
      if (selectedFile?.id === fileId) {
        setSelectedFile(null);
        setParsedData(null);
      }
    } catch {
      toast.error("Erro ao remover arquivo.");
    }
  };

  const handleViewParsed = async (file) => {
    setSelectedFile(file);
    setParsedData(null);
    setLoadingParsed(true);
    try {
      const response = await api.get(`/organizer-files/${file.id}/parsed`);
      setParsedData(response.data?.data?.parsed_data || null);
    } catch {
      toast.error("Erro ao carregar dados processados.");
    } finally {
      setLoadingParsed(false);
    }
  };

  const handleReparse = async (fileId) => {
    try {
      await api.post(`/organizer-files/${fileId}/parse`);
      toast.success("Re-processamento concluido.");
      fetchFiles();
      if (selectedFile?.id === fileId) handleViewParsed(selectedFile);
    } catch {
      toast.error("Erro ao re-processar arquivo.");
    }
  };

  const handleAnalyzeGoogle = async (fileId) => {
    try {
      await api.post(`/organizer-files/${fileId}/analyze`);
      toast.success("Arquivo enviado para analise Google.");
      fetchFiles();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao enviar para analise Google.");
    }
  };

  const parsedCount = files.filter((f) => f.parsed_status === "parsed").length;
  const chatDescription = `${filesMeta.total} arquivo(s), ${parsedCount} processado(s)`;

  return (
    <div className="flex flex-col gap-6 lg:flex-row">
      {/* Main content */}
      <div className="flex-1 min-w-0 space-y-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold text-white">
              <FileSpreadsheet size={24} />
              Documentos e Planilhas
            </h1>
            <p className="mt-1 text-sm text-gray-400">
              Suba arquivos (CSV, Excel, PDF, JSON) para que os agentes de IA analisem, categorizem e organizem automaticamente.
            </p>
            <p className="mt-2 text-xs text-gray-500">{filesMeta.total} arquivo(s) neste recorte.</p>
          </div>

          <div className="flex items-center gap-3">
            <select
              value={filterCategory}
              onChange={(e) => setFilterCategory(e.target.value)}
              className="rounded-xl border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-gray-200"
            >
              <option value="">Todas as categorias</option>
              {CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>

            <button onClick={fetchFiles} disabled={loading} className="rounded-xl border border-gray-700 bg-gray-900 p-2 text-gray-400 hover:text-white">
              <RefreshCw size={16} className={loading ? "animate-spin" : ""} />
            </button>
          </div>
        </div>

        {/* Upload area */}
        <div className="rounded-2xl border border-dashed border-gray-700 bg-gray-950/50 p-6">
          <div className="flex flex-col gap-4 md:flex-row md:items-end">
            <div className="flex-1">
              <label className="mb-1 block text-xs text-gray-400">Categoria</label>
              <select
                value={uploadCategory}
                onChange={(e) => setUploadCategory(e.target.value)}
                className="w-full rounded-xl border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-gray-200"
              >
                {CATEGORIES.map((c) => (
                  <option key={c.value} value={c.value}>{c.label}</option>
                ))}
              </select>
            </div>
            <div className="flex-1">
              <label className="mb-1 block text-xs text-gray-400">Notas (opcional)</label>
              <input
                type="text"
                value={uploadNotes}
                onChange={(e) => setUploadNotes(e.target.value)}
                placeholder="Descricao do arquivo..."
                className="w-full rounded-xl border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-gray-200"
              />
            </div>
            <div>
              <input
                ref={fileInputRef}
                type="file"
                onChange={handleUpload}
                accept=".csv,.xls,.xlsx,.pdf,.json,.doc,.docx,.jpg,.jpeg,.png,.webp"
                className="hidden"
                id="file-upload"
              />
              <label
                htmlFor="file-upload"
                className={`flex cursor-pointer items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600 ${uploading ? "opacity-50 pointer-events-none" : ""}`}
              >
                <Upload size={16} />
                {uploading ? "Enviando..." : "Enviar arquivo"}
              </label>
            </div>
          </div>
        </div>

        {/* Search */}
        <div className="relative">
          <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => handleSearchChange(e.target.value)}
            placeholder="Buscar arquivos..."
            className="w-full rounded-xl border border-gray-700 bg-gray-900 py-2 pl-9 pr-9 text-sm text-gray-200 placeholder-gray-500 focus:border-gray-600 focus:outline-none"
          />
          {searchQuery && (
            <button
              onClick={clearSearch}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white"
            >
              <XCircle size={16} />
            </button>
          )}
        </div>

        {searchLoading && <p className="text-sm text-gray-500">Buscando...</p>}

        {/* Files list */}
        <div className="rounded-2xl border border-gray-800 bg-gray-950/70">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-gray-800 text-xs uppercase text-gray-500">
                <th className="px-4 py-3">Arquivo</th>
                <th className="px-4 py-3">Categoria</th>
                <th className="px-4 py-3">Tamanho</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Data</th>
                <th className="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody>
              {(searchResults !== null ? searchResults : files).length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                    {loading ? "Carregando..." : searchResults !== null ? "Nenhum resultado encontrado." : "Nenhum arquivo encontrado. Suba seu primeiro arquivo acima."}
                  </td>
                </tr>
              ) : (
                (searchResults !== null ? searchResults : files).map((file) => {
                  const statusMeta = PARSED_STATUS_META[file.parsed_status] || PARSED_STATUS_META.pending;
                  const StatusIcon = statusMeta.icon;
                  return (
                    <tr key={file.id} className="border-b border-gray-800/50 hover:bg-gray-900/30">
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="font-medium text-gray-200">{file.original_name}</p>
                          {file.parsed_status === "parsed" && file.category && (
                            <span
                              className="inline-flex items-center gap-1 rounded-full border border-emerald-700/60 bg-emerald-900/30 px-2 py-0.5 text-[10px] font-semibold text-emerald-300"
                              title="Este arquivo e injetado no contexto dos agentes de IA com base na sua categoria."
                            >
                              <Sparkles size={10} />
                              Usado pelos agentes
                            </span>
                          )}
                          {file.embedding_status === "indexing" && (
                            <span className="inline-flex items-center gap-1 rounded-full border border-blue-700/60 bg-blue-900/30 px-2 py-0.5 text-[10px] font-semibold text-blue-300">
                              <Loader2 size={10} className="animate-spin" />
                              Indexando...
                            </span>
                          )}
                          {file.embedding_status === "indexed" && (
                            <span
                              className="inline-flex items-center gap-1 rounded-full border border-cyan-700/60 bg-cyan-900/30 px-2 py-0.5 text-[10px] font-semibold text-cyan-300"
                              title="Embeddings gerados — busca semantica ativa."
                            >
                              <CheckCircle size={10} />
                              Indexado
                            </span>
                          )}
                          {file.google_file_uri && file.embedding_status !== 'indexed' && file.parsed_status !== 'parsed' && (
                            <span
                              className="inline-flex items-center gap-1 rounded-full border border-amber-700/60 bg-amber-900/30 px-2 py-0.5 text-[10px] font-semibold text-amber-300"
                              title="Arquivo enviado para Google, aguardando indexacao completa."
                            >
                              <Loader2 size={10} className="animate-spin" />
                              Preparando para IA...
                            </span>
                          )}
                          {file.google_file_uri && (file.embedding_status === 'indexed' || file.parsed_status === 'parsed') && (
                            <span
                              className="inline-flex items-center gap-1 rounded-full border border-violet-700/60 bg-violet-900/30 px-2 py-0.5 text-[10px] font-semibold text-violet-300"
                              title="Disponivel para analise profunda via Google Long Context."
                            >
                              <Zap size={10} />
                              Google
                            </span>
                          )}
                        </div>
                        {file.notes && <p className="mt-0.5 text-xs text-gray-500">{file.notes}</p>}
                      </td>
                      <td className="px-4 py-3">
                        <span className="rounded-full bg-gray-800 px-2 py-1 text-xs text-gray-300">
                          {CATEGORIES.find((c) => c.value === file.category)?.label || file.category}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-gray-400">{formatBytes(file.file_size_bytes)}</td>
                      <td className="px-4 py-3">
                        <span
                          className={`flex items-center gap-1 text-xs ${statusMeta.color}`}
                          title={file.parsed_status === 'failed' ? (file.parsed_error || 'Erro desconhecido') : undefined}
                        >
                          <StatusIcon size={12} />
                          {statusMeta.label}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500">{formatDate(file.created_at)}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          {file.parsed_status === "parsed" && (
                            <button onClick={() => handleViewParsed(file)} className="text-emerald-400 hover:text-emerald-300" title="Ver dados">
                              <Eye size={15} />
                            </button>
                          )}
                          {(file.file_size_bytes >= LARGE_FILE_THRESHOLD || ['pdf', 'document'].includes(file.file_type)) && !file.google_file_uri && (
                            <button
                              onClick={() => handleAnalyzeGoogle(file.id)}
                              className="text-violet-400 hover:text-violet-300"
                              title="Analise Experimental (Google Long Context)"
                            >
                              <Zap size={15} />
                            </button>
                          )}
                          <button onClick={() => handleReparse(file.id)} className="text-blue-400 hover:text-blue-300" title="Re-processar">
                            <RefreshCw size={15} />
                          </button>
                          <button onClick={() => handleDelete(file.id)} className="text-red-400 hover:text-red-300" title="Remover">
                            <Trash2 size={15} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        {!loading && searchResults === null && filesMeta.total_pages > 1 ? (
          <Pagination
            page={filesMeta.page}
            totalPages={filesMeta.total_pages}
            onPrev={() => setPage((current) => Math.max(1, current - 1))}
            onNext={() => setPage((current) => Math.min(filesMeta.total_pages, current + 1))}
          />
        ) : null}

        {/* Parsed data viewer */}
        {selectedFile && (
          <div className="rounded-2xl border border-emerald-900/40 bg-[linear-gradient(135deg,_rgba(6,78,59,0.15),_rgba(15,23,42,0.94))] p-6">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-emerald-200">
                <Bot size={18} />
                <p className="font-semibold">Dados processados: {selectedFile.original_name}</p>
              </div>
              <button onClick={() => { setSelectedFile(null); setParsedData(null); }} className="text-gray-400 hover:text-white">
                <XCircle size={18} />
              </button>
            </div>

            {loadingParsed ? (
              <p className="mt-4 text-sm text-gray-400">Carregando dados...</p>
            ) : parsedData ? (
              <div className="mt-4 space-y-3">
                <div className="flex gap-4 text-xs text-gray-400">
                  <span>Formato: {parsedData.format || "?"}</span>
                  <span>Linhas: {parsedData.rows_count || 0}</span>
                  {parsedData.headers && <span>Colunas: {parsedData.headers.length}</span>}
                  {parsedData.truncated && <span className="text-amber-400">Truncado (max 500 linhas)</span>}
                </div>

                {parsedData.headers && (
                  <div className="max-h-64 overflow-auto rounded-xl border border-gray-800 bg-gray-950">
                    <table className="w-full text-left text-xs">
                      <thead>
                        <tr className="border-b border-gray-800 bg-gray-900">
                          {parsedData.headers.map((h) => (
                            <th key={h} className="px-3 py-2 text-gray-400">{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {(parsedData.rows || []).slice(0, 20).map((row, i) => (
                          <tr key={i} className="border-b border-gray-800/30">
                            {parsedData.headers.map((h) => (
                              <td key={h} className="px-3 py-1.5 text-gray-300">{row[h] ?? ""}</td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                {parsedData.column_types && (
                  <div className="text-xs text-gray-500">
                    Tipos detectados: {Object.entries(parsedData.column_types).map(([col, type]) => `${col}(${type})`).join(", ")}
                  </div>
                )}
              </div>
            ) : (
              <p className="mt-4 text-sm text-gray-500">Nenhum dado processado disponivel. Tente re-processar o arquivo.</p>
            )}
          </div>
        )}
      </div>

      {/* AI Chat sidebar */}
      <aside className="w-full lg:w-96 flex-shrink-0">
        <div className="lg:sticky lg:top-4">
          <EmbeddedAIChat
            surface="documents"
            title="Assistente de Documentos"
            description={chatDescription}
            accentColor="amber"
            suggestions={[
              'Quais arquivos tenho neste evento?',
              'Busque nos documentos por "orcamento"',
              'Resuma os dados da planilha financeira',
            ]}
          />
        </div>
      </aside>
    </div>
  );
}
