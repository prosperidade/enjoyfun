import { useCallback, useEffect, useMemo, useState } from "react";
import toast from "react-hot-toast";
import { Search, ImageIcon, Film, FileText, Trash2, Copy, ExternalLink, X, Filter } from "lucide-react";
import api from "../lib/api";
import { useEventScope } from "../context/EventScopeContext";

const TYPE_FILTERS = [
  { value: "all",   label: "Tudo" },
  { value: "image", label: "Imagens" },
  { value: "video", label: "Videos" },
  { value: "other", label: "Outros" },
];

function resolveKind(file) {
  const mime = (file.mime_type || "").toLowerCase();
  if (mime.startsWith("image/")) return "image";
  if (mime.startsWith("video/")) return "video";
  return "other";
}

function buildPublicUrl(fileId) {
  const base = (api.defaults.baseURL || "").replace(/\/$/, "");
  return `${base}/api/organizer-files/${fileId}/public`;
}

function formatSize(bytes) {
  if (!bytes || bytes < 1024) return (bytes || 0) + " B";
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
  return (bytes / (1024 * 1024)).toFixed(1) + " MB";
}

function MediaCard({ file, onOpen, onDelete, onCopyLink }) {
  const kind = resolveKind(file);
  const isMedia = kind === "image" || kind === "video";
  return (
    <div className="group relative rounded-xl overflow-hidden border border-slate-700/50 bg-slate-800/40 hover:border-cyan-500/40 transition-colors">
      <button
        type="button"
        onClick={() => onOpen(file)}
        className="w-full aspect-square bg-slate-900/70 flex items-center justify-center overflow-hidden"
      >
        {kind === "image" ? (
          <img
            src={buildPublicUrl(file.id)}
            alt={file.original_name}
            className="w-full h-full object-cover"
            loading="lazy"
          />
        ) : kind === "video" ? (
          <div className="flex flex-col items-center gap-2 text-cyan-400">
            <Film className="w-10 h-10" />
            <span className="text-[10px] uppercase tracking-widest">VIDEO</span>
          </div>
        ) : (
          <div className="flex flex-col items-center gap-2 text-slate-400">
            <FileText className="w-10 h-10" />
            <span className="text-[10px] uppercase tracking-widest">ARQUIVO</span>
          </div>
        )}
      </button>

      <div className="p-2 space-y-1">
        <p className="text-xs text-slate-200 truncate" title={file.original_name}>
          {file.original_name}
        </p>
        <div className="flex items-center justify-between text-[10px] text-slate-500">
          <span>{(file.category || "general").toUpperCase()}</span>
          <span>{formatSize(file.file_size_bytes)}</span>
        </div>
      </div>

      <div className="absolute top-1 right-1 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
        {isMedia && (
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); onCopyLink(file); }}
            title="Copiar link publico"
            className="p-1.5 bg-slate-900/90 hover:bg-cyan-500/20 rounded-md text-slate-300 hover:text-cyan-400"
          >
            <Copy className="w-3.5 h-3.5" />
          </button>
        )}
        <a
          href={buildPublicUrl(file.id)}
          target="_blank"
          rel="noreferrer"
          onClick={(e) => e.stopPropagation()}
          title="Abrir em nova aba"
          className="p-1.5 bg-slate-900/90 hover:bg-cyan-500/20 rounded-md text-slate-300 hover:text-cyan-400"
        >
          <ExternalLink className="w-3.5 h-3.5" />
        </a>
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); onDelete(file); }}
          title="Deletar"
          className="p-1.5 bg-slate-900/90 hover:bg-red-500/20 rounded-md text-slate-300 hover:text-red-400"
        >
          <Trash2 className="w-3.5 h-3.5" />
        </button>
      </div>
    </div>
  );
}

function Lightbox({ file, onClose }) {
  if (!file) return null;
  const kind = resolveKind(file);
  const url = buildPublicUrl(file.id);
  return (
    <div
      className="fixed inset-0 z-50 bg-black/95 flex items-center justify-center p-6"
      onClick={onClose}
    >
      <button
        type="button"
        onClick={onClose}
        className="absolute top-4 right-4 p-2 rounded-full bg-slate-800/80 text-slate-100 hover:bg-slate-700"
      >
        <X className="w-6 h-6" />
      </button>
      <div className="max-w-5xl max-h-full w-full flex flex-col items-center gap-3" onClick={(e) => e.stopPropagation()}>
        {kind === "image" ? (
          <img src={url} alt={file.original_name} className="max-h-[85vh] max-w-full object-contain" />
        ) : kind === "video" ? (
          <video src={url} controls autoPlay className="max-h-[85vh] max-w-full" />
        ) : (
          <iframe src={url} title={file.original_name} className="w-full h-[80vh] bg-white rounded-lg" />
        )}
        <div className="text-center text-xs text-slate-400">
          {file.original_name} · {(file.category || "general").toUpperCase()} · {formatSize(file.file_size_bytes)}
        </div>
      </div>
    </div>
  );
}

export default function MediaWorkspace() {
  const { selectedEventId, events } = useEventScope();
  const [files, setFiles] = useState([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("all");
  const [categoryFilter, setCategoryFilter] = useState("all");
  const [lightboxFile, setLightboxFile] = useState(null);

  const eventName = useMemo(
    () => events.find((e) => String(e.id) === String(selectedEventId))?.name || null,
    [events, selectedEventId]
  );

  const load = useCallback(() => {
    setLoading(true);
    const params = { per_page: 100 };
    if (selectedEventId) params.event_id = selectedEventId;
    api.get("/organizer-files", { params })
      .then((r) => {
        const data = r.data?.data || r.data?.rows || [];
        setFiles(Array.isArray(data) ? data : []);
      })
      .catch(() => toast.error("Erro ao carregar arquivos"))
      .finally(() => setLoading(false));
  }, [selectedEventId]);

  useEffect(() => { load(); }, [load]);

  const categories = useMemo(() => {
    const set = new Set(files.map((f) => (f.category || "general")));
    return ["all", ...Array.from(set).sort()];
  }, [files]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return files.filter((f) => {
      const kind = resolveKind(f);
      if (typeFilter !== "all" && kind !== typeFilter) return false;
      if (categoryFilter !== "all" && (f.category || "general") !== categoryFilter) return false;
      if (q && !(f.original_name || "").toLowerCase().includes(q)) return false;
      return true;
    });
  }, [files, search, typeFilter, categoryFilter]);

  const counts = useMemo(() => ({
    all:   files.length,
    image: files.filter((f) => resolveKind(f) === "image").length,
    video: files.filter((f) => resolveKind(f) === "video").length,
    other: files.filter((f) => resolveKind(f) === "other").length,
  }), [files]);

  const handleCopyLink = async (file) => {
    try {
      await navigator.clipboard.writeText(buildPublicUrl(file.id));
      toast.success("Link copiado");
    } catch {
      toast.error("Nao consegui copiar");
    }
  };

  const handleDelete = async (file) => {
    if (!window.confirm(`Deletar ${file.original_name}?`)) return;
    try {
      await api.delete(`/organizer-files/${file.id}`);
      setFiles((prev) => prev.filter((f) => f.id !== file.id));
      toast.success("Removido");
    } catch {
      toast.error("Erro ao deletar");
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <div className="max-w-7xl mx-auto px-4 py-6 space-y-5">
        {/* Header */}
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <h1 className="text-2xl font-semibold text-slate-100 flex items-center gap-2">
              <ImageIcon className="w-6 h-6 text-cyan-400" /> Midia do Evento
            </h1>
            <p className="text-sm text-slate-400 mt-1">
              {eventName ? `Evento: ${eventName}` : "Todos os eventos"} · Grid visual de fotos, videos e docs.
            </p>
          </div>
        </div>

        {/* Toolbar */}
        <div className="flex items-center gap-3 flex-wrap bg-slate-900/60 border border-slate-700/50 rounded-xl p-3">
          {/* Search */}
          <div className="relative flex-1 min-w-[200px]">
            <Search className="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
            <input
              className="w-full bg-slate-800/60 border border-slate-700/50 rounded-lg pl-8 pr-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-cyan-500"
              placeholder="Buscar pelo nome..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>

          {/* Type pills */}
          <div className="flex gap-1">
            {TYPE_FILTERS.map((t) => (
              <button
                key={t.value}
                type="button"
                onClick={() => setTypeFilter(t.value)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                  typeFilter === t.value
                    ? "bg-cyan-500/20 text-cyan-300 border border-cyan-500/40"
                    : "bg-slate-800/60 text-slate-400 border border-slate-700/50 hover:text-slate-200"
                }`}
              >
                {t.label} ({counts[t.value] ?? 0})
              </button>
            ))}
          </div>

          {/* Category select */}
          <div className="flex items-center gap-1.5 text-xs text-slate-400">
            <Filter className="w-3.5 h-3.5" />
            <select
              className="bg-slate-800/60 border border-slate-700/50 rounded-lg px-2 py-1.5 text-xs text-slate-200 focus:outline-none focus:ring-1 focus:ring-cyan-500"
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
            >
              {categories.map((c) => (
                <option key={c} value={c}>{c === "all" ? "Todas categorias" : c.toUpperCase()}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Grid */}
        {loading ? (
          <div className="text-center py-12 text-sm text-slate-400">Carregando...</div>
        ) : filtered.length === 0 ? (
          <div className="text-center py-12 text-sm text-slate-500">
            {files.length === 0
              ? "Nenhum arquivo uploadado ainda para este evento."
              : "Nenhum resultado para os filtros aplicados."}
          </div>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            {filtered.map((file) => (
              <MediaCard
                key={file.id}
                file={file}
                onOpen={setLightboxFile}
                onDelete={handleDelete}
                onCopyLink={handleCopyLink}
              />
            ))}
          </div>
        )}
      </div>

      <Lightbox file={lightboxFile} onClose={() => setLightboxFile(null)} />
    </div>
  );
}
