import { useState, useEffect } from "react";
import { X, Save, Plus, Calendar } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

// Sugestões de cargos se o banco estiver vazio
const DEFAULT_ROLES_SUGGESTIONS = [
    "Gerente de Bar", "Operador de Caixa", "Equipe de Limpeza", 
    "Segurança Alpha", "Hostess / Recepção", "Produtor Executivo", 
    "Auxiliar de Serviços", "Promoter"
];

export default function AddWorkforceAssignmentModal({
  isOpen,
  onClose,
  eventId,
  onAdded,
  presetRoleId = "",
  presetSector = "",
  lockRole = false
}) {
  const [loading, setLoading] = useState(false);
  const [staffList, setStaffList] = useState([]);
  const [roles, setRoles] = useState([]);
  const [shifts, setShifts] = useState([]);
  const [eventDays, setEventDays] = useState([]);
  const [isCreatingRole, setIsCreatingRole] = useState(false);
  const [newRoleName, setNewRoleName] = useState("");

  const [formData, setFormData] = useState({
    participant_id: "",
    role_id: "",
    sector: "",
    event_day_id: "",
    event_shift_id: ""
  });

  const loadData = async () => {
    if (!isOpen || !eventId) return;
    try {
      const [partRes, roleRes, dayRes, shiftRes] = await Promise.all([
        api.get(`/participants?event_id=${eventId}`),
        api.get("/workforce/roles"),
        api.get(`/event-days?event_id=${eventId}`),
        api.get(`/event-shifts?event_id=${eventId}`)
      ]);
      setStaffList(partRes.data.data || []);
      setRoles(roleRes.data.data || []);
      setEventDays(dayRes.data.data || []);
      setShifts(shiftRes.data.data || []);
      
      // Se tiver dias de evento, seleciona o primeiro por padrão
      if (dayRes.data.data?.length > 0 && !formData.event_day_id) {
          setFormData(prev => ({ ...prev, event_day_id: dayRes.data.data[0].id.toString() }));
      }
    } catch (err) {
      console.error(err);
    }
  };

  useEffect(() => {
    loadData();
  }, [isOpen, eventId]);

  useEffect(() => {
    if (!isOpen) return;
    setFormData((prev) => ({
      ...prev,
      role_id: presetRoleId ? String(presetRoleId) : prev.role_id,
      sector: presetSector || prev.sector
    }));
  }, [isOpen, presetRoleId, presetSector]);

  const filteredShifts = shifts.filter(s => {
      if (!formData.event_day_id) return true;
      return s.event_day_id.toString() === formData.event_day_id.toString();
  });

  useEffect(() => {
    if (!formData.event_day_id) {
      return;
    }

    const hasSelectedShift = filteredShifts.some(
      (shift) => shift.id.toString() === formData.event_shift_id.toString()
    );

    if (!hasSelectedShift) {
      setFormData((prev) => ({
        ...prev,
        event_shift_id: filteredShifts.length > 0 ? filteredShifts[0].id.toString() : ""
      }));
    }
  }, [formData.event_day_id, shifts]);

  if (!isOpen) return null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    let finalRoleId = formData.role_id;

    if (!formData.participant_id) return toast.error("Selecione um participante.");
    
    // Se não selecionou cargo e não está criando um novo, mas existem sugestões
    if (!finalRoleId && !isCreatingRole && !newRoleName) {
        return toast.error("Selecione um cargo ou crie um novo.");
    }

    setLoading(true);
    try {
      // 1. Criar novo cargo se estiver no modo de criação
      if (isCreatingRole && newRoleName) {
        const roleRes = await api.post("/workforce/roles", { name: newRoleName });
        finalRoleId = roleRes.data.data.id;
      }

      // 1.1 Se cargo veio das sugestões (string), cria no backend antes de alocar.
      if (!isCreatingRole && finalRoleId && Number.isNaN(Number(finalRoleId))) {
        const roleRes = await api.post("/workforce/roles", { name: finalRoleId });
        finalRoleId = roleRes.data.data.id;
      }

      // 2. Criar a atribuição (assignment)
      await api.post("/workforce/assignments", {
        event_id: eventId,
        participant_id: formData.participant_id,
        role_id: finalRoleId,
        sector: formData.sector,
        event_shift_id: formData.event_shift_id || null
      });

      toast.success("Staff alocado com sucesso!");
      onAdded();
      onClose();
      // Reset total para evitar duplicados
      setFormData({ participant_id: "", role_id: "", sector: "", event_day_id: "", event_shift_id: "" });
      setNewRoleName("");
      setIsCreatingRole(false);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao alocar staff");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
      
      <div className="bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-md relative z-10 shadow-2xl animate-fade-in flex flex-col max-h-[90vh] overflow-hidden">
        <div className="p-5 border-b border-gray-800 flex justify-between items-center bg-gray-900/50">
            <div>
                <h2 className="text-lg font-black text-white leading-none">Nova Alocação</h2>
                <p className="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-1">Vínculo Operacional de Staff</p>
            </div>
            <button onClick={onClose} className="text-gray-500 hover:text-white transition-colors p-2 rounded-xl hover:bg-gray-800">
                <X size={20} />
            </button>
        </div>

        <div className="p-6 overflow-y-auto space-y-5 custom-scrollbar">
          <form id="assignment-form" onSubmit={handleSubmit} className="space-y-5">
            
            {/* Escolha do Membro */}
            <div>
              <label className="text-[10px] font-black text-gray-500 mb-1.5 block uppercase tracking-[0.15em]">Participante Integramte *</label>
              <select 
                className="select w-full block bg-gray-800 border-gray-700 h-12 text-sm font-medium focus:border-brand/40" 
                required
                value={formData.participant_id}
                onChange={e => setFormData({...formData, participant_id: e.target.value})}
              >
                <option value="">Selecione um membro...</option>
                {staffList.map(s => (
                  <option key={s.participant_id} value={s.participant_id}>
                    {s.name} ({s.category_name})
                  </option>
                ))}
              </select>
            </div>

            {/* Cargo com Sugestões e Criação */}
             <div>
              <div className="flex justify-between items-center mb-1.5">
                <label className="text-[10px] font-black text-gray-500 uppercase tracking-[0.15em]">Cargo Atribuído *</label>
                {!lockRole && (
                  <button 
                    type="button" 
                    onClick={() => setIsCreatingRole(!isCreatingRole)}
                    className="text-[10px] text-brand hover:text-brand-light font-black uppercase tracking-widest flex items-center gap-1.5 bg-brand/5 px-2.5 py-1 rounded-lg border border-brand/20 transition-all active:scale-95"
                  >
                    <Plus size={10} /> {isCreatingRole ? "Visualizar Lista" : "Criar Novo Cargo"}
                  </button>
                )}
              </div>
              
              {isCreatingRole ? (
                <div className="space-y-2 translate-y-0 animate-slide-down">
                    <input 
                      type="text"
                      placeholder="Ex: Gerente Geral, Brigadista..."
                      className="input w-full bg-brand/5 border-brand/30 h-12 text-sm font-semibold shadow-inner placeholder:text-gray-600 focus:border-brand"
                      autoFocus
                      value={newRoleName}
                      onChange={e => setNewRoleName(e.target.value)}
                    />
                    <p className="text-[9px] text-gray-600 font-bold uppercase italic">O novo cargo será salvo permanentemente na sua base.</p>
                </div>
              ) : (
                <select 
                  className="select w-full block bg-gray-800 border-gray-700 h-12 text-sm font-medium focus:border-brand/40" 
                  required={!isCreatingRole}
                  disabled={lockRole}
                  value={formData.role_id}
                  onChange={e => setFormData({...formData, role_id: e.target.value})}
                >
                  <option value="">Selecione um cargo...</option>
                  
                  {/* Se o banco tiver cargos, mostra eles */}
                  {roles.length > 0 ? (
                      <optgroup label="Cargos no Banco">
                        {roles.map(r => (
                            <option key={r.id} value={r.id}>{r.name}</option>
                        ))}
                      </optgroup>
                  ) : (
                      /* Se o banco estiver vazio, mostra sugestões hardcoded */
                      <optgroup label="Sugestões Operacionais">
                        {DEFAULT_ROLES_SUGGESTIONS.map(roleName => (
                            <option key={roleName} value={roleName}>{roleName}</option>
                        ))}
                      </optgroup>
                  )}
                </select>
              )}
            </div>

            {/* Local de Trabalho / Setor */}
            <div>
              <label className="text-[10px] font-black text-gray-500 mb-1.5 block uppercase tracking-[0.15em]">Setor / Área de Atuação</label>
              <input 
                type="text" 
                placeholder="Ex: Bar Alpha, Palco Principal, Acessos..."
                className="input w-full bg-gray-800 border-gray-700 h-12 text-sm font-medium focus:border-brand/40" 
                value={formData.sector}
                onChange={e => setFormData({...formData, sector: e.target.value})}
              />
            </div>

            <div className="h-px bg-gray-800/50 my-2" />

            {/* Escala: Dia e Turno */}
             <div className="grid grid-cols-2 gap-4">
                <div>
                   <label className="text-[10px] font-black text-gray-500 mb-1.5 block uppercase tracking-[0.15em] flex items-center gap-1.5">
                     <Calendar size={12} className="text-brand" /> Dia do Evento
                   </label>
                   <select 
                    className="select w-full block bg-gray-800 border-gray-700 h-12 text-sm font-medium focus:border-brand/40" 
                    value={formData.event_day_id}
                    onChange={e => setFormData({...formData, event_day_id: e.target.value})}
                  >
                    <option value="">(Todos os Dias)</option>
                    {eventDays.map(d => (
                      <option key={d.id} value={d.id}>{d.date}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-[10px] font-black text-gray-500 mb-1.5 block uppercase tracking-[0.15em]">Turno / Escala</label>
                   <select 
                    className="select w-full block bg-gray-800 border-gray-700 h-12 text-sm font-medium focus:border-brand/40" 
                    value={formData.event_shift_id}
                    onChange={e => setFormData({...formData, event_shift_id: e.target.value})}
                    disabled={filteredShifts.length === 0}
                  >
                    <option value="">{filteredShifts.length === 0 ? "Sem turnos" : "Selecione o turno..."}</option>
                    {filteredShifts.map(s => (
                      <option key={s.id} value={s.id}>{s.name} ({s.starts_at} - {s.ends_at})</option>
                    ))}
                  </select>
               </div>
            </div>

          </form>
        </div>

        <div className="p-5 border-t border-gray-800 flex justify-end gap-3 bg-gray-950/30">
          <button type="button" onClick={onClose} className="px-5 py-2 rounded-xl font-bold text-gray-500 hover:text-white hover:bg-gray-800 transition-all text-xs uppercase tracking-widest">
            Cancelar
          </button>
          <button 
            type="submit" 
            form="assignment-form"
            disabled={loading}
            className="btn-primary h-12 px-8 text-xs font-black uppercase tracking-[0.2em] shadow-xl shadow-brand/20 active:scale-95 transition-all"
          >
             {loading ? <div className="spinner w-4 h-4" /> : <Save size={18} className="mr-2" />}
             Salvar Alocação
          </button>
        </div>
      </div>
    </div>
  );
}
