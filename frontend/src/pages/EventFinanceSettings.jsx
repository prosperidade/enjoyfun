import { useState, useEffect } from "react";
import { Plus, Edit2, CheckCircle2, XCircle, Search, Settings as SettingsIcon } from "lucide-react";
import api from "../lib/api";
import toast from "react-hot-toast";

export default function EventFinanceSettings() {
  const [activeTab, setActiveTab] = useState("categories"); // 'categories' or 'cost-centers'
  const [events, setEvents] = useState([]);
  const [selectedEventId, setSelectedEventId] = useState("");

  const [categories, setCategories] = useState([]);
  const [costCenters, setCostCenters] = useState([]);
  const [loading, setLoading] = useState(false);

  // Modal State
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [modalMode, setModalMode] = useState("create"); // 'create' or 'edit'
  const [formData, setFormData] = useState({ id: "", name: "", code: "", description: "", is_active: true });

  useEffect(() => {
    api.get("/events").then((r) => {
      setEvents(r.data.data || []);
      if (r.data.data?.length > 0) setSelectedEventId(r.data.data[0].id.toString());
    }).catch(() => {});
  }, []);

  const loadData = async () => {
    setLoading(true);
    try {
      if (activeTab === "categories") {
        const res = await api.get("/event-finance/categories?active=false"); // Load all to manage is_active
        setCategories(res.data.data || []);
      } else if (activeTab === "cost-centers" && selectedEventId) {
        const res = await api.get(`/event-finance/cost-centers?event_id=${selectedEventId}&active=false`);
        setCostCenters(res.data.data || []);
      }
    } catch (err) {
      toast.error("Erro ao carregar dados.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, [activeTab, selectedEventId]);

  const handleOpenModal = (mode, item = null) => {
    setModalMode(mode);
    setFormData(item ? { ...item } : { id: "", name: "", code: "", description: "", is_active: true });
    setIsModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    if (activeTab === "cost-centers" && !selectedEventId) {
      toast.error("Selecione um evento primeiro.");
      return;
    }

    try {
      const endpoint = activeTab === "categories" ? "/event-finance/categories" : "/event-finance/cost-centers";
      const payload = { ...formData };
      if (activeTab === "cost-centers") payload.event_id = parseInt(selectedEventId);

      if (modalMode === "create") {
        await api.post(endpoint, payload);
        toast.success("Salvo com sucesso!");
      } else {
        await api.put(`${endpoint}/${formData.id}`, payload);
        toast.success("Atualizado com sucesso!");
      }

      setIsModalOpen(false);
      loadData();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar.");
    }
  };

  return (
    <div className="space-y-6 max-w-5xl">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <SettingsIcon size={24} className="text-purple-400" /> Configurações Financeiras
          </h1>
          <p className="text-gray-400 text-sm">Gerencie Categorias globais e Centros de Custo por evento.</p>
        </div>
      </div>

      <div className="flex gap-4 border-b border-white/10 pb-2">
        <button
          onClick={() => setActiveTab("categories")}
          className={`px-4 py-2 font-medium text-sm rounded-lg transition-colors ${
            activeTab === "categories" ? "bg-purple-600/20 text-purple-400" : "text-gray-400 hover:text-white"
          }`}
        >
          Categorias de Custo (Globais)
        </button>
        <button
          onClick={() => setActiveTab("cost-centers")}
          className={`px-4 py-2 font-medium text-sm rounded-lg transition-colors ${
            activeTab === "cost-centers" ? "bg-purple-600/20 text-purple-400" : "text-gray-400 hover:text-white"
          }`}
        >
          Centros de Custo (Por Evento)
        </button>
      </div>

      {activeTab === "cost-centers" && (
        <div className="flex max-w-sm flex-col">
          <label className="input-label">Selecione o Evento</label>
          <select className="select" value={selectedEventId} onChange={(e) => setSelectedEventId(e.target.value)}>
            {events.map((ev) => (
              <option key={ev.id} value={ev.id}>{ev.name}</option>
            ))}
          </select>
        </div>
      )}

      <div className="card space-y-4">
        <div className="flex justify-between items-center">
          <h2 className="section-title">
            {activeTab === "categories" ? "Categorias de Custo" : "Centros de Custo"}
          </h2>
          <button onClick={() => handleOpenModal("create")} className="btn-primary text-sm flex items-center gap-1">
            <Plus size={16} /> Novo Registro
          </button>
        </div>

        <div className="table-wrapper">
          <table className="table">
            <thead>
              <tr>
                <th className="w-16">ID</th>
                <th>Nome</th>
                <th>Código / Ref</th>
                <th>Status</th>
                <th className="text-right">Ação</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="5" className="text-center py-6 text-gray-500">Carregando...</td></tr>
              ) : (activeTab === "categories" ? categories : costCenters).length === 0 ? (
                <tr><td colSpan="5" className="text-center py-6 text-gray-500">Nenhum registro encontrado.</td></tr>
              ) : (
                (activeTab === "categories" ? categories : costCenters).map((item) => (
                  <tr key={item.id}>
                    <td className="font-mono text-xs text-purple-400">#{item.id}</td>
                    <td className="font-medium">{item.name}</td>
                    <td className="text-gray-400">{item.code || "—"}</td>
                    <td>
                      {item.is_active ? (
                        <span className="badge-green flex items-center gap-1 w-max">
                          <CheckCircle2 size={12} /> Ativo
                        </span>
                      ) : (
                        <span className="badge-red flex items-center gap-1 w-max">
                          <XCircle size={12} /> Inativo
                        </span>
                      )}
                    </td>
                    <td className="text-right">
                      <button onClick={() => handleOpenModal("edit", item)} className="p-2 text-gray-400 hover:text-purple-400 rounded-lg hover:bg-white/5">
                        <Edit2 size={16} />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
          <p className="text-xs text-gray-500 mt-2 p-3 bg-white/5 rounded-lg">
            <strong>Dica Importante:</strong> O número do ID (coluna à esquerda) é o Identificador Exato que você deve usar nas planilhas de importação CSV como `category_id` ou `cost_center_id`.
          </p>
        </div>
      </div>

      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
          <div className="bg-gray-950 border border-gray-800 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl">
            <div className="p-5 border-b border-gray-800 flex justify-between items-center">
              <h3 className="font-bold text-lg text-white">
                {modalMode === "create" ? "Novo Registro" : "Editar Registro"}
              </h3>
              <button onClick={() => setIsModalOpen(false)} className="text-gray-500 hover:text-white">
                <XCircle size={20} />
              </button>
            </div>
            <form onSubmit={handleSave} className="p-5 space-y-4">
              <div>
                <label className="input-label">Nome (Obrigatório)</label>
                <input
                  type="text"
                  required
                  className="input"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Ex: Alimentação Equipe"
                />
              </div>
              <div>
                <label className="input-label">Código (Opcional)</label>
                <input
                  type="text"
                  className="input font-mono text-sm"
                  value={formData.code || ""}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                  placeholder="Ex: CAT-01"
                />
              </div>
              <div>
                <label className="input-label">Identificador Numérico (Gerado pelo sistema)</label>
                <input
                  type="text"
                  disabled
                  className="input text-gray-500 bg-black/20"
                  value={formData.id ? `#${formData.id}` : "—"}
                  placeholder=""
                />
              </div>
              {modalMode === "edit" && (
                <label className="flex items-center gap-3 p-3 border border-white/10 rounded-lg bg-black/20 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={formData.is_active}
                    onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                    className="accent-purple-500 w-4 h-4"
                  />
                  <span className="text-sm font-medium">Registro Ativo</span>
                </label>
              )}
              <div className="flex gap-3 pt-4">
                <button type="button" onClick={() => setIsModalOpen(false)} className="btn-outline flex-1">
                  Cancelar
                </button>
                <button type="submit" className="btn-primary flex-1">
                  Salvar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
