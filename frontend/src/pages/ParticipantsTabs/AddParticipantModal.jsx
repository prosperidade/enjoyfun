import { useState, useEffect } from "react";
import { X, Save } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

export default function AddParticipantModal({ isOpen, onClose, eventId, onAdded }) {
  const [loading, setLoading] = useState(false);
  const [categories, setCategories] = useState([]);
  const [categoriesError, setCategoriesError] = useState("");
  
  const [formData, setFormData] = useState({
    name: "",
    email: "",
    document: "",
    phone: "",
    category_id: ""
  });

  useEffect(() => {
    if (isOpen) {
      setCategories([]);
      setCategoriesError("");
      setFormData((current) => ({ ...current, category_id: "" }));
      api.get("/participants/categories") 
        .then(res => {
          setCategories(res.data.data || []);
          setCategoriesError("");
        })
        .catch((error) => {
          setCategories([]);
          setCategoriesError(
            error.response?.data?.message || "Não foi possível carregar categorias válidas para este organizador."
          );
        });
    }
  }, [isOpen]);

  if (!isOpen) return null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (categories.length === 0) {
      return toast.error(categoriesError || "Nenhuma categoria válida disponível para este organizador.");
    }
    if (!formData.name || !formData.category_id) {
      return toast.error("Nome e Categoria são obrigatórios.");
    }

    setLoading(true);
    try {
      await api.post("/participants", {
        event_id: eventId,
        ...formData
      });
      toast.success("Participante adicionado com sucesso!");
      onAdded();
      onClose();
      setFormData({ name: "", email: "", document: "", phone: "", category_id: "" });
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao adicionar participante");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
      
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md relative z-10 shadow-2xl animate-fade-in overflow-hidden flex flex-col max-h-[90vh]">
        <div className="p-4 border-b border-gray-800 flex justify-between items-center bg-gray-900/50 sticky top-0 z-20">
          <h2 className="text-lg font-bold text-white">Adicionar Participante</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-white transition-colors p-1 rounded-lg hover:bg-gray-800">
            <X size={20} />
          </button>
        </div>

        <div className="p-6 overflow-y-auto">
          <form id="participant-form" onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="text-xs font-medium text-gray-400 mb-1 block">Nome Completo *</label>
              <input 
                type="text" 
                className="input w-full" 
                required
                value={formData.name}
                onChange={e => setFormData({...formData, name: e.target.value})}
              />
            </div>
            
            <div className="grid grid-cols-2 gap-4">
               <div>
                  <label className="text-xs font-medium text-gray-400 mb-1 block">E-mail</label>
                  <input 
                    type="email" 
                    className="input w-full" 
                    value={formData.email}
                    onChange={e => setFormData({...formData, email: e.target.value})}
                  />
               </div>
               <div>
                  <label className="text-xs font-medium text-gray-400 mb-1 block">Telefone / WhatsApp</label>
                  <input 
                    type="text" 
                    className="input w-full" 
                    value={formData.phone}
                    onChange={e => setFormData({...formData, phone: e.target.value})}
                  />
               </div>
            </div>

            <div>
              <label className="text-xs font-medium text-gray-400 mb-1 block">Documento (CPF/RG)</label>
              <input 
                type="text" 
                className="input w-full bg-gray-900" 
                value={formData.document}
                onChange={e => setFormData({...formData, document: e.target.value})}
              />
            </div>

            <div>
              <label className="text-xs font-medium text-gray-400 mb-1 block">Categoria Oficial *</label>
              {categoriesError ? (
                <p className="mb-2 rounded-lg border border-amber-700/60 bg-amber-950/40 px-3 py-2 text-xs text-amber-200">
                  {categoriesError}
                </p>
              ) : null}
              <select 
                className="select w-full block" 
                required
                disabled={categories.length === 0}
                value={formData.category_id}
                onChange={e => setFormData({...formData, category_id: e.target.value})}
              >
                <option value="">Selecione uma categoria...</option>
                {categories.map(c => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </div>
          </form>
        </div>

        <div className="p-4 border-t border-gray-800 flex justify-end gap-3 bg-gray-900/50 sticky bottom-0">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg font-medium text-gray-400 hover:text-white hover:bg-gray-800 transition-colors">
            Cancelar
          </button>
          <button 
            type="submit" 
            form="participant-form"
            disabled={loading || categories.length === 0}
            className="btn-primary"
          >
             {loading ? <div className="spinner" /> : <Save size={18} />}
             Salvar
          </button>
        </div>
      </div>
    </div>
  );
}
