import { useState, useId, useRef } from "react";
import api from "../lib/api";
import toast from "react-hot-toast";
import { Upload, ExternalLink, X, Film, Image as ImageIcon, FileText, UploadCloud } from "lucide-react";

/**
 * MediaUpload — componente reutilizavel com drag-and-drop + preview.
 *
 * Salva o arquivo via /organizer-files e retorna "file:{id}:{name}"
 * no campo informado. Aceita URLs externas (https://...).
 *
 * Props:
 * - label: titulo do slot
 * - description: texto de ajuda
 * - value: valor atual
 * - onChange: (newValue) => void
 * - accept: formatos aceitos (default baseado em mediaType)
 * - category: categoria no organizer-files
 * - eventId: ID do evento
 * - mediaType: "image" | "video" | "video_360" | "document"
 * - compact: versao compacta (so linha)
 */
export default function MediaUpload({
  label,
  description,
  value = "",
  onChange,
  accept,
  category = "event_media",
  eventId,
  mediaType = "image",
  compact = false,
}) {
  const [uploading, setUploading] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const reactId = useId();
  const fileInputRef = useRef(null);

  const acceptAttr = accept || {
    image: "image/*",
    video: "video/*",
    video_360: "video/*",
    document: "image/*,.pdf,.glb,.gltf",
  }[mediaType] || "*/*";

  const Icon = {
    image: ImageIcon,
    video: Film,
    video_360: Film,
    document: FileText,
  }[mediaType] || Upload;

  const typeLabel = {
    image: "imagem",
    video: "video",
    video_360: "video 360",
    document: "documento",
  }[mediaType] || "arquivo";

  const handleUpload = async (file) => {
    if (!file) return;
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("category", category);
      if (eventId) formData.append("event_id", eventId);
      const res = await api.post("/organizer-files", formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      const fileData = res.data?.data || {};
      const fileId = fileData.id;
      const fileName = fileData.original_name || file.name;
      const ref = fileId ? `file:${fileId}:${fileName}` : fileName;
      onChange(ref);
      toast.success(`"${fileName}" enviado!`);
    } catch (err) {
      toast.error(err?.response?.data?.message || "Erro ao enviar arquivo");
    }
    setUploading(false);
  };

  const handleRemove = () => {
    onChange("");
  };

  // Drag-and-drop handlers
  const handleDragOver = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(true);
  };
  const handleDragLeave = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
  };
  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
    const file = e.dataTransfer.files?.[0];
    if (file) handleUpload(file);
  };

  const openPreview = async () => {
    if (!value) return;
    if (value.startsWith("file:")) {
      const fileId = value.split(":")[1];
      try {
        const res = await api.get(`/organizer-files/${fileId}/download`, { responseType: "blob" });
        const url = URL.createObjectURL(res.data);
        window.open(url, "_blank");
      } catch {
        toast.error("Erro ao abrir arquivo");
      }
    } else {
      window.open(value, "_blank");
    }
  };

  // Build preview URL from value
  const getPreviewSrc = () => {
    if (!value) return null;
    if (value.startsWith("file:")) {
      const fileId = value.split(":")[1];
      return `/api/organizer-files/${fileId}/download`;
    }
    return value;
  };

  const displayName = value.startsWith("file:")
    ? value.split(":").slice(2).join(":") || "Arquivo"
    : (value.split("/").pop() || value || "");

  const inputId = `media-upload-${label?.replace(/\s+/g, "-").toLowerCase() || reactId.replace(/:/g, "")}`;
  const previewSrc = getPreviewSrc();
  const isImage = mediaType === "image" || (value && /\.(jpg|jpeg|png|gif|webp|svg|avif)$/i.test(value));
  const isVideo = mediaType === "video" || mediaType === "video_360" || (value && /\.(mp4|webm|mov|avi|mkv)$/i.test(value));

  // Compact: uma unica linha
  if (compact) {
    return (
      <div className="space-y-1">
        {label && <label className="text-xs font-medium text-gray-400">{label}</label>}
        {value ? (
          <div className="flex items-center gap-2 bg-gray-900 border border-gray-700 rounded-lg px-3 py-2">
            <Icon className="w-3.5 h-3.5 text-green-400 flex-shrink-0" />
            <span className="text-xs text-green-400 truncate flex-1" title={value}>
              {displayName}
            </span>
            <button type="button" onClick={openPreview} className="text-gray-400 hover:text-purple-400" title="Visualizar">
              <ExternalLink className="w-3.5 h-3.5" />
            </button>
            <button type="button" onClick={handleRemove} className="text-red-400 hover:text-red-300" title="Remover">
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        ) : (
          <>
            <input
              type="file"
              accept={acceptAttr}
              id={inputId}
              ref={fileInputRef}
              className="sr-only"
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) handleUpload(f);
              }}
            />
            <label
              htmlFor={inputId}
              className="flex items-center gap-1.5 cursor-pointer bg-gray-700 hover:bg-gray-600 text-gray-300 text-xs px-3 py-2 rounded-lg transition-colors w-full justify-center"
            >
              {uploading ? "Enviando..." : <><Icon className="w-3.5 h-3.5" /> Enviar {typeLabel}</>}
            </label>
          </>
        )}
      </div>
    );
  }

  // Versao full — card com drag-and-drop
  return (
    <div className="border border-gray-700 rounded-lg p-3 bg-gray-800/60 space-y-2">
      <div>
        <p className="text-sm font-medium text-gray-200 flex items-center gap-1.5">
          <Icon className="w-3.5 h-3.5 text-purple-400" />
          {label}
        </p>
        {description && <p className="text-[10px] text-gray-500">{description}</p>}
      </div>

      {value ? (
        <div className="space-y-2">
          {/* Preview */}
          {isImage && previewSrc && (
            <div className="relative bg-gray-900 rounded-lg overflow-hidden" style={{ aspectRatio: "16/9" }}>
              <img src={previewSrc} alt={displayName} className="w-full h-full object-cover" onError={(e) => { e.target.style.display = "none"; }} />
            </div>
          )}
          {isVideo && previewSrc && (
            <div className="relative bg-gray-900 rounded-lg overflow-hidden" style={{ aspectRatio: "16/9" }}>
              <video src={previewSrc} className="w-full h-full object-cover" controls preload="metadata" />
            </div>
          )}
          <div className="flex items-center gap-2 bg-gray-900 rounded-lg px-3 py-2">
            <Icon className="w-3.5 h-3.5 text-green-400 flex-shrink-0" />
            <span className="text-xs text-green-400 truncate flex-1" title={value}>
              {displayName}
            </span>
            <button type="button" onClick={openPreview} className="text-gray-400 hover:text-purple-400" title="Abrir">
              <ExternalLink className="w-3.5 h-3.5" />
            </button>
            <button type="button" onClick={handleRemove} className="text-red-400 hover:text-red-300" title="Remover">
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      ) : (
        <>
          <input
            type="file"
            accept={acceptAttr}
            id={inputId}
            ref={fileInputRef}
            className="sr-only"
            onChange={(e) => {
              const f = e.target.files?.[0];
              if (f) handleUpload(f);
            }}
          />
          <div
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
            className={`
              cursor-pointer rounded-lg border-2 border-dashed p-4 text-center transition-all
              ${isDragging
                ? "border-purple-400 bg-purple-500/10"
                : "border-gray-600 hover:border-purple-500 hover:bg-gray-800"}
              ${uploading ? "opacity-50 pointer-events-none" : ""}
            `}
          >
            {uploading ? (
              <div className="flex items-center justify-center gap-2 text-purple-400 text-xs">
                <div className="w-3 h-3 border-2 border-purple-400 border-t-transparent rounded-full animate-spin" />
                Enviando...
              </div>
            ) : (
              <>
                <UploadCloud className={`w-6 h-6 mx-auto mb-2 ${isDragging ? "text-purple-400" : "text-gray-500"}`} />
                <p className="text-xs text-gray-300 font-medium">
                  {isDragging ? `Solte o ${typeLabel} aqui` : `Arraste ou clique pra enviar ${typeLabel}`}
                </p>
                <p className="text-[10px] text-gray-500 mt-1">
                  {mediaType === "image" && "PNG, JPG, WEBP, GIF (ate 200MB)"}
                  {mediaType === "video" && "MP4, WEBM, MOV (ate 200MB)"}
                  {mediaType === "video_360" && "MP4 360 (ate 200MB)"}
                  {mediaType === "document" && "PDF, imagens, GLB/GLTF (ate 200MB)"}
                </p>
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
}
