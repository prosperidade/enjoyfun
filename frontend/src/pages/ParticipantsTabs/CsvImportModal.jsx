import { useState, useEffect } from "react";
import { X, UploadCloud, AlertCircle } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

function parseCsvRow(rowText) {
  const columns = [];
  let current = "";
  let inQuotes = false;

  for (let i = 0; i < rowText.length; i += 1) {
    const char = rowText[i];
    const nextChar = rowText[i + 1];

    if (char === '"') {
      if (inQuotes && nextChar === '"') {
        current += '"';
        i += 1;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }

    if (char === "," && !inQuotes) {
      columns.push(current.trim());
      current = "";
      continue;
    }

    current += char;
  }

  columns.push(current.trim());
  return columns.map((column) => column.replace(/\r/g, ""));
}

export default function CsvImportModal({
  isOpen,
  onClose,
  eventId,
  onImported,
  mode = "guest",
  workforceRoleId = null,
  workforceSector = "",
  workforceRoleCostBucket = "operational",
  managerUserId = null,
  managerEventRoleId = null,
  managerEventRolePublicId = ""
}) {
  const [loading, setLoading] = useState(false);
  const [file, setFile] = useState(null);
  const [categories, setCategories] = useState([]);
  const [defaultCategoryId, setDefaultCategoryId] = useState("");
  const managerFirstMode =
    mode === "workforce" &&
    (Boolean(managerUserId) || Boolean(managerEventRoleId) || Boolean(managerEventRolePublicId));

  const filteredCategories = categories.filter((category) => {
    if (mode !== "workforce") {
      return true;
    }

    return String(category.type || "").toLowerCase() === "staff";
  });

  useEffect(() => {
    if (isOpen) {
      api.get("/participants/categories")
        .then(res => {
          const cats = res.data.data || [];
          setCategories(cats);
        })
        .catch(() => {});
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;

    if (filteredCategories.length > 0) {
      const hasCurrentOption = filteredCategories.some((category) => String(category.id) === String(defaultCategoryId));
      if (!hasCurrentOption) {
        setDefaultCategoryId(String(filteredCategories[0].id));
      }
      return;
    }

    setDefaultCategoryId("");
  }, [isOpen, mode, filteredCategories, defaultCategoryId]);
  
  if (!isOpen) return null;

  const handleFileChange = (e) => {
    if (e.target.files && e.target.files[0]) {
      setFile(e.target.files[0]);
    }
  };

  const handleImport = async () => {
    if (!file) return toast.error("Selecione um arquivo CSV.");
    
    setLoading(true);
    try {
      // Usando FileReader simples em vez de dependência externa PapaParse para garantir compatibilidade imediata
      const reader = new FileReader();
      reader.onload = async (e) => {
        const text = e.target.result;
        const rows = String(text || "").split(/\r?\n/);
        const participants = [];
        
        // Skip header
        for (let i = 1; i < rows.length; i++) {
          const rowText = rows[i].trim();
          if (!rowText) continue;

          const cols = parseCsvRow(rowText).map((column) => column.replace(/^"|"$/g, ""));

          if (cols.length >= 4) {
            const participant = {
              name: cols[0] || "",
              email: cols[1] || "",
              document: cols[2] || "",
              phone: cols[3] || "",
              category_id: cols[4] || defaultCategoryId
            };
            if (participant.name) participants.push(participant);
          }
        }

        try {
            const endpoint = mode === "workforce"
              ? (managerFirstMode ? "/workforce/import" : (workforceRoleId ? `/workforce/roles/${workforceRoleId}/import` : "/workforce/import"))
              : "/participants/import";
            const payload = mode === "workforce"
              ? {
                  event_id: eventId,
                  role_id: managerFirstMode ? undefined : (workforceRoleId || undefined),
                  sector: workforceSector || undefined,
                  forced_manager_user_id: managerUserId || undefined,
                  manager_event_role_id: managerEventRoleId || undefined,
                  manager_event_role_public_id: managerEventRolePublicId || undefined,
                  file_name: file.name,
                  participants
                }
              : { event_id: eventId, participants };

            const res = await api.post(endpoint, payload);
            const { data } = res;
            if (data.success) {
                if (mode === "workforce") {
                  toast.success(data.message || `${data.data.imported} importados para ${data.data.sector}.`);
                } else {
                  toast.success(data.message || `${data.data.imported} importados, ${data.data.skipped} ignorados.`);
                }
            }
            onClose();
            onImported?.(data.data || null);
        } catch (err) {
            toast.error(err.response?.data?.message || "Erro ao processar importação no servidor.");
        } finally {
            setLoading(false);
        }
      };
      
      reader.onerror = () => {
          toast.error("Erro ao ler o arquivo localmente.");
          setLoading(false);
      }

      reader.readAsText(file);

    } catch (error) {
       console.error(error);
       toast.error("Erro inesperado ao processar arquivo.");
       setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
      
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md max-h-[90vh] relative z-10 shadow-2xl animate-fade-in flex flex-col overflow-hidden">
        <div className="p-4 border-b border-gray-800 flex justify-between items-center">
          <h2 className="text-lg font-bold text-white">Importação em Lote (CSV)</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-white transition-colors p-1 rounded-lg hover:bg-gray-800">
            <X size={20} />
          </button>
        </div>

        <div className="p-6 space-y-6 overflow-y-auto">
            <div className="card bg-gray-800/50 border border-blue-900/30 p-4">
                <div className="flex gap-3 text-blue-400">
                    <AlertCircle size={20} className="flex-shrink-0" />
                    <div className="text-sm">
                        <strong className="block mb-1">Formato Exigido do CSV</strong>
                        {mode === "workforce" && (
                          <>
                            <p className="text-blue-300 mb-2">
                              {managerFirstMode
                                ? `Importação vinculada automaticamente ao gerente atual. O setor desta tabela será respeitado e a equipe já entra na árvore correta.`
                                : `Importação vinculada ao cargo selecionado.`}
                            </p>
                            {!managerFirstMode && String(workforceRoleCostBucket || "").toLowerCase() === "managerial" && (
                              <p className="text-amber-300">
                                Cargo gerencial detectado: os nomes do CSV serão alocados automaticamente no cargo operacional do setor, preservando o gerente apenas como liderança configurada.
                              </p>
                            )}
                          </>
                        )}
                        <p className="text-gray-400 leading-relaxed">
                            A primeira linha deve ser o cabeçalho. As colunas devem estar separadas por vírgula nesta ordem: <br/>
                            <code className="text-gray-300 bg-gray-900 px-1 py-0.5 rounded mt-2 inline-block shadow-inner">name, email, document, phone, category_id</code>
                        </p>
                    </div>
                </div>
            </div>

            <div className="space-y-2">
                <label className="text-xs font-medium text-gray-400 block">
                  {mode === "workforce" ? "Categoria Workforce" : "Categoria Padrão"} (utilizada se a coluna CSV estiver vazia)
                </label>
                <select 
                    className="select w-full"
                    value={defaultCategoryId}
                    onChange={(e) => setDefaultCategoryId(e.target.value)}
                >
                    <option value="">Selecione uma categoria...</option>
                    {filteredCategories.map(c => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
                {mode === "workforce" && filteredCategories.length === 0 && (
                    <p className="text-xs text-amber-400">
                        Nenhuma categoria do tipo Workforce/Staff foi encontrada para este organizador.
                    </p>
                )}
            </div>

            <div className="border-2 border-dashed border-gray-700 rounded-xl p-8 flex flex-col items-center justify-center text-center hover:bg-gray-800/30 transition-colors">
                <input 
                    type="file" 
                    id="csv-upload" 
                    accept=".csv" 
                    className="hidden" 
                    onChange={handleFileChange}
                />
                
                <label htmlFor="csv-upload" className="cursor-pointer flex flex-col items-center">
                    <div className="w-12 h-12 bg-gray-800 rounded-full flex items-center justify-center text-gray-400 mb-3 hover:text-brand hover:scale-110 transition-all">
                        <UploadCloud size={24} />
                    </div>
                    {file ? (
                        <div>
                            <span className="text-brand font-medium block">{file.name}</span>
                            <span className="text-xs text-gray-500">{(file.size / 1024).toFixed(1)} KB</span>
                        </div>
                    ) : (
                         <div>
                            <span className="text-white font-medium block mb-1">Clique para procurar CSV</span>
                            <span className="text-xs text-gray-500">Ou arraste e solte o arquivo aqui</span>
                        </div>
                    )}
                </label>
            </div>
        </div>

        <div className="p-4 border-t border-gray-800 flex justify-end gap-3 bg-gray-900/50 rounded-b-2xl flex-shrink-0">
          <button onClick={onClose} className="px-4 py-2 rounded-lg font-medium text-gray-400 hover:text-white hover:bg-gray-800 transition-colors">
            Cancelar
          </button>
          <button 
            onClick={handleImport}
            disabled={loading || !file}
            className="btn-primary"
          >
             {loading ? <div className="spinner" /> : <UploadCloud size={18} />}
             Confirmar Importação
          </button>
        </div>
      </div>
    </div>
  );
}
