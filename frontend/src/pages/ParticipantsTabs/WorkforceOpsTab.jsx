import { useEffect, useMemo, useState } from "react";
import {
  ArrowLeft,
  Briefcase,
  Copy,
  FileDown,
  Mail,
  MessageCircle,
  Pencil,
  Plus,
  QrCode,
  Search,
  Settings2,
  Trash2,
  Users
} from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";
import AddWorkforceAssignmentModal from "./AddWorkforceAssignmentModal";
import CsvImportModal from "./CsvImportModal";
import BulkMessageModal from "./BulkMessageModal";
import EditParticipantModal from "./EditParticipantModal";
import WorkforceMemberSettingsModal from "./WorkforceMemberSettingsModal";
import BulkWorkforceSettingsModal from "./BulkWorkforceSettingsModal";
import WorkforceRoleSettingsModal from "./WorkforceRoleSettingsModal";
import WorkforceSectorCostsModal from "./WorkforceSectorCostsModal";

export default function WorkforceOpsTab({ eventId }) {
  const [managers, setManagers] = useState([]);
  const [assignments, setAssignments] = useState([]);
  const [participants, setParticipants] = useState([]);
  const [selectedManager, setSelectedManager] = useState(null);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedIds, setSelectedIds] = useState([]);
  const [loading, setLoading] = useState(true);

  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isBulkModalOpen, setIsBulkModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isBulkSettingsModalOpen, setIsBulkSettingsModalOpen] = useState(false);
  const [bulkType, setBulkType] = useState("whatsapp");
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [editingParticipant, setEditingParticipant] = useState(null);
  const [settingsParticipant, setSettingsParticipant] = useState(null);
  const [isSettingsModalOpen, setIsSettingsModalOpen] = useState(false);
  const [roleSettingsRole, setRoleSettingsRole] = useState(null);
  const [isRoleSettingsModalOpen, setIsRoleSettingsModalOpen] = useState(false);
  const [sectorCostsRole, setSectorCostsRole] = useState(null);
  const [isSectorCostsModalOpen, setIsSectorCostsModalOpen] = useState(false);

  const fetchManagersAndAssignments = async () => {
    setLoading(true);
    try {
      const [mgrRes, assignmentRes] = await Promise.all([
        api.get(`/workforce/managers?event_id=${eventId}`),
        api.get(`/workforce/assignments?event_id=${eventId}`)
      ]);
      const nextManagers = mgrRes.data.data || [];
      const nextAssignments = assignmentRes.data.data || [];
      setManagers(nextManagers);
      setAssignments(nextAssignments);
      return { managers: nextManagers, assignments: nextAssignments };
    } catch (error) {
      console.error(error);
      toast.error("Erro ao carregar gerentes e alocações.");
      return { managers: [], assignments: [] };
    } finally {
      setLoading(false);
    }
  };

  const fetchManagerMembers = async (managerUserId) => {
    setLoading(true);
    if (!managerUserId) {
      setParticipants([]);
      setSelectedIds([]);
      setLoading(false);
      return;
    }
    try {
      // Puxa estritamente os assignments alocados a esse gerente
      const res = await api.get(`/workforce/assignments?event_id=${eventId}&manager_user_id=${managerUserId}`);
      setParticipants(res.data.data || []);
      setSelectedIds([]);
    } catch (error) {
      console.error(error);
      toast.error("Erro ao carregar equipe do gerente.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!eventId) return;
    fetchManagersAndAssignments();
  }, [eventId]);

  useEffect(() => {
    if (!selectedManager) return;
    fetchManagerMembers(selectedManager.user_id);
  }, [selectedManager?.user_id]);

  const roleMemberCount = (roleId) =>
    assignments.filter((assignment) => Number(assignment.role_id) === Number(roleId)).length;

  const buildRoleContext = (row) => {
    if (!row) return null;
    return {
      id: Number(row.role_id || row.id || 0),
      name: row.role_name || row.name || "",
      sector: row.sector || ""
    };
  };

  const refreshSelectedManagerView = async (manager = selectedManager) => {
    const { managers: refreshedManagers } = await fetchManagersAndAssignments();
    if (!manager) {
      return;
    }

    const refreshedManager =
      refreshedManagers.find(
        (item) =>
          Number(item.user_id || 0) === Number(manager.user_id || 0) ||
          Number(item.participant_id || 0) === Number(manager.participant_id || 0)
      ) || manager;

    setSelectedManager(refreshedManager);
    if (refreshedManager?.user_id) {
      await fetchManagerMembers(refreshedManager.user_id);
      return;
    }

    setParticipants([]);
    setSelectedIds([]);
  };

  const teamMembers = useMemo(() => {
    if (!selectedManager) return [];

    return participants
      .map((participant) => ({
        ...participant,
        name: participant.name || participant.person_name || "",
        email: participant.email || participant.person_email || "",
        cost_bucket: participant.cost_bucket || participant.assignment?.cost_bucket || "operational"
      }))
      .filter((participant) => {
        const q = searchTerm.trim().toLowerCase();
        if (!q) return true;
        return (
          participant.name.toLowerCase().includes(q) ||
          participant.email.toLowerCase().includes(q) ||
          String(participant.phone || "").toLowerCase().includes(q)
        );
      });
  }, [participants, selectedManager, searchTerm]);

  const selectedParticipants = teamMembers.filter((p) => selectedIds.includes(p.participant_id));
  const selectedManagerRole = buildRoleContext(selectedManager);
  const canLinkSelectedManager = Boolean(selectedManager?.user_id);

  const handleEnterManager = (manager) => {
    setSelectedManager(manager);
    setSearchTerm("");
    setSelectedIds([]);
  };

  const handleWorkforceImported = async () => {
    await refreshSelectedManagerView(selectedManager);
  };

  const handleDeleteMember = async (participant) => {
    if (!window.confirm(`Excluir ${participant.name || participant.person_name} deste evento?`)) return;
    try {
      await api.delete(`/participants/${participant.participant_id}`);
      toast.success("Participante removido.");
      await refreshSelectedManagerView(selectedManager);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao excluir participante.");
    }
  };

  const handleCopyLink = async (token) => {
    if (!token) {
      toast.error("Token QR indisponível.");
      return;
    }
    try {
      await navigator.clipboard.writeText(`${window.location.origin}/invite?token=${token}`);
      toast.success("Link do QR copiado.");
    } catch {
      toast.error("Falha ao copiar link.");
    }
  };

  const toggleSelectAll = () => {
    if (selectedIds.length === teamMembers.length) {
      setSelectedIds([]);
      return;
    }
    setSelectedIds(teamMembers.map((m) => m.participant_id));
  };

  const toggleSelect = (id) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const openBulk = (type) => {
    if (selectedIds.length === 0) {
      toast.error("Selecione membros primeiro.");
      return;
    }
    setBulkType(type);
    setIsBulkModalOpen(true);
  };

  const handleBulkDelete = async () => {
    if (selectedIds.length === 0) {
      toast.error("Selecione membros para excluir.");
      return;
    }

    const confirmed = window.confirm(`Excluir ${selectedIds.length} membro(s) selecionado(s)?`);
    if (!confirmed) return;

    setBulkDeleting(true);
    try {
      const res = await api.post("/participants/bulk-delete", { ids: selectedIds });
      const data = res.data?.data || {};
      const deleted = Number(data.deleted || 0);
      const notFound = Array.isArray(data.not_found) ? data.not_found.length : 0;
      const forbidden = Array.isArray(data.forbidden) ? data.forbidden.length : 0;
      const failed = Array.isArray(data.failed) ? data.failed.length : 0;

      if (data.status === "success") {
        toast.success(`Exclusão em massa concluída (${deleted} removido(s)).`);
      } else if (data.status === "partial") {
        toast(`Exclusão parcial: ${deleted} removido(s), ${notFound} não encontrado(s), ${forbidden} sem permissão, ${failed} falha(s).`);
      } else {
        toast.error("Nenhum membro foi removido.");
      }

      await refreshSelectedManagerView(selectedManager);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro na exclusão em massa.");
    } finally {
      setBulkDeleting(false);
    }
  };

  if (!selectedManager) {
    return (
      <div className="space-y-6">
        <div className="card p-4 border border-gray-800 bg-gray-900/40">
          <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
              <h2 className="text-lg font-bold text-white">Liderança Operacional</h2>
              <p className="text-sm text-gray-400">Selecione um gerente para consultar e organizar sua equipe neste evento.</p>
            </div>
          </div>
        </div>

        <div className="card overflow-hidden p-0 border border-gray-800">
          <table className="w-full text-left text-sm text-gray-300">
            <thead className="bg-gray-900/80 text-gray-500 uppercase text-[10px] tracking-wider border-b border-gray-800">
              <tr>
                <th className="px-5 py-4">Gerente / Líder</th>
                <th className="px-5 py-4">Cargo / Setor</th>
                <th className="px-5 py-4">Tamanho da Equipe</th>
                <th className="px-5 py-4 text-right">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-800/60">
              {loading ? (
                <tr><td colSpan="4" className="p-10 text-center"><div className="spinner mx-auto" /></td></tr>
              ) : managers.length === 0 ? (
                <tr><td colSpan="4" className="p-10 text-center text-gray-500">Nenhum gerente escalado neste evento.</td></tr>
              ) : (
                managers.map((mgr) => (
                  <tr key={mgr.participant_id} className="hover:bg-gray-800/30">
                    <td className="px-5 py-4">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-brand/10 border border-brand/20 flex items-center justify-center text-brand font-black">
                          {(mgr.person_name || "?").charAt(0).toUpperCase()}
                        </div>
                        <div>
                           <p className="font-semibold text-white">{mgr.person_name}</p>
                           <p className="text-xs text-gray-500">{mgr.phone || mgr.person_email || "Sem contato"}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-4">
                      <p className="text-white font-medium">{mgr.role_name}</p>
                      <p className="text-xs text-gray-400 mt-1 uppercase">Setor: {(mgr.sector || "geral").replace(/_/g, " ")}</p>
                    </td>
                    <td className="px-5 py-4 text-gray-300 font-medium whitespace-nowrap">
                       <Users size={14} className="inline mr-2 text-gray-500" />
                       {mgr.team_size || 0} membro(s)
                    </td>
                    <td className="px-5 py-4">
                      <div className="flex justify-end gap-2">
                        <button onClick={() => handleEnterManager(mgr)} className="btn-secondary h-9 px-4 font-semibold border-brand/50 text-brand-light hover:bg-brand/10">Tabela do Gerente</button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <CsvImportModal
          isOpen={isImportModalOpen}
          onClose={() => {
            setIsImportModalOpen(false);
          }}
          eventId={eventId}
          mode="workforce"
          managerUserId={null}
          workforceSector={""}
          onImported={handleWorkforceImported}
        />

        <WorkforceRoleSettingsModal
          isOpen={isRoleSettingsModalOpen}
          role={roleSettingsRole}
          eventId={eventId}
          roleMembersCount={roleSettingsRole?.id ? roleMemberCount(roleSettingsRole.id) : 0}
          onClose={() => {
            setIsRoleSettingsModalOpen(false);
            setRoleSettingsRole(null);
          }}
          onSaved={async () => {
            await refreshSelectedManagerView(selectedManager);
          }}
        />

        <WorkforceSectorCostsModal
          isOpen={isSectorCostsModalOpen}
          role={sectorCostsRole}
          eventId={eventId}
          onClose={() => {
            setIsSectorCostsModalOpen(false);
            setSectorCostsRole(null);
          }}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-3">
        <button onClick={() => setSelectedManager(null)} className="btn-secondary flex items-center gap-2">
          <ArrowLeft size={16} /> Voltar para Liderança
        </button>
        <div className="text-right">
          <p className="text-xs uppercase text-gray-500 tracking-wider">Tabela do Gerente</p>
          <p className="text-lg font-black text-white">{selectedManager.person_name}</p>
          <p className="text-[10px] text-brand uppercase mt-1">{selectedManager.role_name} — Setor: {(selectedManager.sector || "geral").replace(/_/g, " ")}</p>
          {!canLinkSelectedManager && (
            <p className="text-[10px] text-amber-400 uppercase mt-2">
              Gerente sem usuário vinculado. Importação e alocação manual ficam bloqueadas.
            </p>
          )}
        </div>
      </div>

      {selectedIds.length > 0 && (
        <div className="bg-brand/10 border border-brand/20 p-4 rounded-2xl flex items-center justify-between">
          <span className="text-brand font-semibold">{selectedIds.length} selecionados</span>
          <div className="flex gap-2">
            <button onClick={() => setIsBulkSettingsModalOpen(true)} className="btn-secondary h-9 px-3 text-xs flex items-center gap-2">
              <Settings2 size={14} /> Configurar
            </button>
            <button onClick={() => openBulk("whatsapp")} className="btn-primary h-9 px-3 text-xs flex items-center gap-2">
              <MessageCircle size={14} /> WhatsApp
            </button>
            <button onClick={() => openBulk("email")} className="btn-secondary h-9 px-3 text-xs flex items-center gap-2">
              <Mail size={14} /> E-mail
            </button>
            <button
              onClick={handleBulkDelete}
              disabled={bulkDeleting}
              className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-red-700/60 text-red-400 hover:bg-red-900/30"
            >
              <Trash2 size={14} /> {bulkDeleting ? "Deletando..." : "Delete"}
            </button>
            <button onClick={() => setSelectedIds([])} className="btn-secondary h-9 px-3 text-xs">Cancelar</button>
          </div>
        </div>
      )}

      <div className="flex flex-col lg:flex-row gap-3 items-center justify-between">
        <div className="relative w-full lg:w-96">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" size={16} />
          <input
            className="input pl-9 w-full"
            placeholder="Buscar membro..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="flex gap-2 w-full lg:w-auto">
          <button
            onClick={() => {
              setSectorCostsRole(selectedManagerRole);
              setIsSectorCostsModalOpen(true);
            }}
            className="btn-secondary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2"
            disabled={!selectedManagerRole?.id}
          >
            <Settings2 size={16} /> Custos
          </button>
          <button
            onClick={() => {
              if (!canLinkSelectedManager) {
                toast.error("Vincule um usuário ativo a este gerente antes de importar equipe.");
                return;
              }
              setIsImportModalOpen(true);
            }}
            className="btn-secondary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2"
            disabled={!canLinkSelectedManager}
          >
            <FileDown size={16} /> Importar CSV
          </button>
          <button
            onClick={() => {
              if (!canLinkSelectedManager) {
                toast.error("Vincule um usuário ativo a este gerente antes de alocar a equipe manualmente.");
                return;
              }
              setIsAddModalOpen(true);
            }}
            className="btn-primary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2"
            disabled={!canLinkSelectedManager}
          >
            <Plus size={16} /> Alocação Manual
          </button>
        </div>
      </div>

      <div className="card overflow-hidden p-0 border border-gray-800">
        <table className="w-full text-left text-sm text-gray-300">
          <thead className="bg-gray-900/80 text-gray-500 uppercase text-[10px] tracking-wider border-b border-gray-800">
            <tr>
              <th className="px-5 py-4 w-10">
                <input type="checkbox" className="checkbox" checked={teamMembers.length > 0 && selectedIds.length === teamMembers.length} onChange={toggleSelectAll} />
              </th>
              <th className="px-5 py-4">Membro</th>
              <th className="px-5 py-4">Setor / Link</th>
              <th className="px-5 py-4 text-right">Ações</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-800/60">
            {loading ? (
              <tr><td colSpan="4" className="p-10 text-center"><div className="spinner mx-auto" /></td></tr>
            ) : teamMembers.length === 0 ? (
              <tr><td colSpan="4" className="p-10 text-center text-gray-500">Nenhum membro nesta equipe.</td></tr>
            ) : (
              teamMembers.map((m) => (
                <tr key={m.participant_id} className="hover:bg-gray-800/30">
                  <td className="px-5 py-4">
                    <input type="checkbox" className="checkbox" checked={selectedIds.includes(m.participant_id)} onChange={() => toggleSelect(m.participant_id)} />
                  </td>
                  <td className="px-5 py-4">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-full bg-brand/10 border border-brand/20 flex items-center justify-center text-brand font-black">
                        {(m.name || "?").charAt(0).toUpperCase()}
                      </div>
                      <div>
                        <p className="font-semibold text-white">{m.name}</p>
                        <p className="text-xs text-gray-500">{m.phone || m.email || "Sem contato"}</p>
                        <p className="text-[10px] text-gray-600 uppercase tracking-wider">REF #{m.participant_id}</p>
                        {(m.cost_bucket || "operational") === "managerial" ? (
                          <p className="text-[10px] text-amber-400 uppercase tracking-wider mt-1">Cargo gerencial/diretivo</p>
                        ) : (
                          <p className="text-[10px] text-cyan-400 uppercase tracking-wider mt-1">Membro operacional</p>
                        )}
                      </div>
                    </div>
                  </td>
                  <td className="px-5 py-4">
                    <div className="text-xs text-gray-400 uppercase">{(m.sector || selectedManager.sector || "geral").replace(/_/g, " ")}</div>
                    <div className="flex gap-1 mt-1">
                      <button
                        onClick={() => handleCopyLink(m.qr_token)}
                        className="p-1 px-2 text-[10px] bg-gray-800 border border-gray-700 rounded-lg inline-flex items-center gap-1"
                      >
                        <Copy size={11} /> Link
                      </button>
                      <a
                        href={`${window.location.origin}/invite?token=${m.qr_token}`}
                        target="_blank"
                        rel="noreferrer"
                        className="p-1 px-2 text-[10px] text-brand-light border border-brand/30 rounded-lg inline-flex items-center gap-1"
                      >
                        <QrCode size={11} /> QR
                      </a>
                    </div>
                    <div className="mt-2 text-[10px] text-gray-500">
                      Turnos: {m.max_shifts_event ?? 1} | Horas: {m.shift_hours ?? 8}h | Refeições/dia: {m.meals_per_day ?? 4} | Valor/turno: R$ {Number(m.payment_amount ?? 0).toFixed(2)}
                    </div>
                  </td>
                  <td className="px-5 py-4">
                    <div className="flex justify-end gap-1">
                      <button
                        onClick={() => {
                          setSettingsParticipant(m);
                          setIsSettingsModalOpen(true);
                        }}
                        className="p-2 rounded-lg border border-gray-700 text-cyan-400 hover:bg-cyan-900/20"
                        title="Configuração operacional"
                      >
                        <Briefcase size={14} />
                      </button>
                      <button
                        onClick={() => {
                          setEditingParticipant(m);
                          setIsEditModalOpen(true);
                        }}
                        className="p-2 rounded-lg border border-gray-700 text-blue-400 hover:bg-blue-900/20"
                        title="Editar"
                      >
                        <Pencil size={14} />
                      </button>
                      <button
                        onClick={() => handleDeleteMember(m)}
                        className="p-2 rounded-lg border border-gray-700 text-red-400 hover:bg-red-900/20"
                        title="Excluir participante"
                      >
                        <Trash2 size={14} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <AddWorkforceAssignmentModal
        isOpen={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        eventId={eventId}
        presetSector={selectedManager?.sector || ""}
        managerUserId={selectedManager?.user_id || null}
        lockSector={Boolean(selectedManager?.sector)}
        onAdded={async () => {
          await refreshSelectedManagerView(selectedManager);
        }}
      />

      <CsvImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        eventId={eventId}
        mode="workforce"
        managerUserId={selectedManager?.user_id || null}
        workforceSector={selectedManager?.sector || ""}
        onImported={handleWorkforceImported}
      />

      <EditParticipantModal
        isOpen={isEditModalOpen}
        onClose={() => {
          setIsEditModalOpen(false);
          setEditingParticipant(null);
        }}
        participant={editingParticipant}
        onUpdated={async () => {
          await refreshSelectedManagerView(selectedManager);
        }}
      />

      <BulkMessageModal
        isOpen={isBulkModalOpen}
        onClose={() => setIsBulkModalOpen(false)}
        selectedParticipants={selectedParticipants}
        type={bulkType}
      />

      <WorkforceMemberSettingsModal
        isOpen={isSettingsModalOpen}
        onClose={() => {
          setIsSettingsModalOpen(false);
          setSettingsParticipant(null);
        }}
        participant={settingsParticipant}
        onSaved={async () => {
          await refreshSelectedManagerView(selectedManager);
        }}
      />

      <BulkWorkforceSettingsModal
        isOpen={isBulkSettingsModalOpen}
        onClose={() => setIsBulkSettingsModalOpen(false)}
        participants={selectedParticipants}
        onSaved={async () => {
          await refreshSelectedManagerView(selectedManager);
        }}
      />

      <WorkforceRoleSettingsModal
        isOpen={isRoleSettingsModalOpen}
        role={roleSettingsRole}
        eventId={eventId}
        roleMembersCount={roleSettingsRole?.id ? roleMemberCount(roleSettingsRole.id) : 0}
        onClose={() => {
          setIsRoleSettingsModalOpen(false);
          setRoleSettingsRole(null);
        }}
        onSaved={async () => {
          await refreshSelectedManagerView(selectedManager);
        }}
      />

      <WorkforceSectorCostsModal
        isOpen={isSectorCostsModalOpen}
        role={sectorCostsRole}
        eventId={eventId}
        onClose={() => {
          setIsSectorCostsModalOpen(false);
          setSectorCostsRole(null);
        }}
      />
    </div>
  );
}
