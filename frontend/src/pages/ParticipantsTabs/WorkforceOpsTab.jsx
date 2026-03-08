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

const DEFAULT_SECTOR_BY_ROLE = (name = "") => {
  const normalized = name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\s+/g, "_")
    .trim();
  if (!normalized) return "";

  const prefixes = [
    "gerente_de_",
    "diretor_de_",
    "coordenador_de_",
    "supervisor_de_",
    "lider_de_",
    "chefe_de_",
    "equipe_de_",
    "time_de_"
  ];

  let sector = normalized;
  for (const p of prefixes) {
    if (sector.startsWith(p)) {
      sector = sector.slice(p.length);
      break;
    }
  }

  return sector.replace(/^_+|_+$/g, "");
};

export default function WorkforceOpsTab({ eventId }) {
  const [roles, setRoles] = useState([]);
  const [assignments, setAssignments] = useState([]);
  const [participants, setParticipants] = useState([]);
  const [selectedRole, setSelectedRole] = useState(null);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedIds, setSelectedIds] = useState([]);
  const [loading, setLoading] = useState(true);

  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isBulkModalOpen, setIsBulkModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isBulkSettingsModalOpen, setIsBulkSettingsModalOpen] = useState(false);
  const [bulkType, setBulkType] = useState("whatsapp");
  const [editingParticipant, setEditingParticipant] = useState(null);
  const [settingsParticipant, setSettingsParticipant] = useState(null);
  const [isSettingsModalOpen, setIsSettingsModalOpen] = useState(false);

  const [newRoleName, setNewRoleName] = useState("");
  const [newRoleSector, setNewRoleSector] = useState("");
  const [creatingRole, setCreatingRole] = useState(false);

  const fetchRolesAndAssignments = async () => {
    setLoading(true);
    try {
      const [roleRes, assignmentRes] = await Promise.all([
        api.get("/workforce/roles"),
        api.get(`/workforce/assignments?event_id=${eventId}`)
      ]);
      setRoles(roleRes.data.data || []);
      setAssignments(assignmentRes.data.data || []);
    } catch (error) {
      console.error(error);
      toast.error("Erro ao carregar cargos e alocações.");
    } finally {
      setLoading(false);
    }
  };

  const fetchRoleMembers = async (roleId) => {
    try {
      const res = await api.get(`/participants?event_id=${eventId}&assigned_only=1&role_id=${roleId}`);
      setParticipants(res.data.data || []);
      setSelectedIds([]);
    } catch (error) {
      console.error(error);
      toast.error("Erro ao carregar membros do cargo.");
    }
  };

  useEffect(() => {
    if (!eventId) return;
    fetchRolesAndAssignments();
  }, [eventId]);

  useEffect(() => {
    if (!selectedRole) return;
    fetchRoleMembers(selectedRole.id);
  }, [selectedRole?.id]);

  const roleStats = useMemo(() => {
    const byRole = {};
    for (const a of assignments) {
      byRole[a.role_id] = (byRole[a.role_id] || 0) + 1;
    }
    return byRole;
  }, [assignments]);

  const roleMembers = useMemo(() => {
    if (!selectedRole) return [];
    const roleAssignmentMap = new Map(
      assignments
        .filter((a) => a.role_id === selectedRole.id)
        .map((a) => [a.participant_id, a])
    );

    return participants
      .filter((p) => roleAssignmentMap.has(p.participant_id))
      .map((p) => ({ ...p, assignment: roleAssignmentMap.get(p.participant_id) }))
      .filter((p) => {
        const q = searchTerm.trim().toLowerCase();
        if (!q) return true;
        return (
          (p.name || "").toLowerCase().includes(q) ||
          (p.email || "").toLowerCase().includes(q) ||
          (p.phone || "").toLowerCase().includes(q)
        );
      });
  }, [participants, assignments, selectedRole, searchTerm]);

  const selectedParticipants = roleMembers.filter((p) => selectedIds.includes(p.participant_id));

  const handleEnterRole = (role) => {
    setSelectedRole(role);
    setSearchTerm("");
    setSelectedIds([]);
  };

  const handleCreateRole = async () => {
    const name = newRoleName.trim();
    if (!name) {
      toast.error("Informe o nome do cargo.");
      return;
    }

    setCreatingRole(true);
    try {
      await api.post("/workforce/roles", {
        name,
        sector: DEFAULT_SECTOR_BY_ROLE(name) || newRoleSector || undefined
      });
      toast.success("Cargo criado.");
      setNewRoleName("");
      setNewRoleSector("");
      await fetchRolesAndAssignments();
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao criar cargo.");
    } finally {
      setCreatingRole(false);
    }
  };

  const handleDeleteRole = async (role) => {
    if (!window.confirm(`Excluir cargo "${role.name}"?`)) return;
    try {
      await api.delete(`/workforce/roles/${role.id}`);
      toast.success("Cargo excluído.");
      if (selectedRole?.id === role.id) setSelectedRole(null);
      await fetchRolesAndAssignments();
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao excluir cargo.");
    }
  };

  const handleDeleteMember = async (participant) => {
    if (!window.confirm(`Excluir ${participant.name} deste evento?`)) return;
    try {
      await api.delete(`/participants/${participant.participant_id}`);
      toast.success("Participante removido.");
      await fetchRolesAndAssignments();
      await fetchRoleMembers(selectedRole.id);
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
    if (selectedIds.length === roleMembers.length) {
      setSelectedIds([]);
      return;
    }
    setSelectedIds(roleMembers.map((m) => m.participant_id));
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

  if (!selectedRole) {
    return (
      <div className="space-y-6">
        <div className="card p-4 border border-gray-800 bg-gray-900/40">
          <div className="grid grid-cols-1 md:grid-cols-[1fr_130px] gap-3">
            <input
              className="input"
              placeholder="Novo cargo (ex: Gerente de Bar)"
              value={newRoleName}
              onChange={(e) => setNewRoleName(e.target.value)}
            />
            <button disabled={creatingRole} onClick={handleCreateRole} className="btn-primary flex items-center justify-center gap-2">
              <Plus size={16} /> Criar Cargo
            </button>
          </div>
          <p className="text-xs text-gray-500 mt-2">
            Setor automático: <span className="text-brand">{DEFAULT_SECTOR_BY_ROLE(newRoleName) || "será definido pelo nome do cargo"}</span>
          </p>
        </div>

        <div className="card overflow-hidden p-0 border border-gray-800">
          <table className="w-full text-left text-sm text-gray-300">
            <thead className="bg-gray-900/80 text-gray-500 uppercase text-[10px] tracking-wider border-b border-gray-800">
              <tr>
                <th className="px-5 py-4">Cargo</th>
                <th className="px-5 py-4">Setor</th>
                <th className="px-5 py-4">Qtd. Membros</th>
                <th className="px-5 py-4 text-right">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-800/60">
              {loading ? (
                <tr><td colSpan="4" className="p-10 text-center"><div className="spinner mx-auto" /></td></tr>
              ) : roles.length === 0 ? (
                <tr><td colSpan="4" className="p-10 text-center text-gray-500">Nenhum cargo cadastrado.</td></tr>
              ) : (
                roles.map((role) => (
                  <tr key={role.id} className="hover:bg-gray-800/30">
                    <td className="px-5 py-4 font-semibold text-white">{role.name}</td>
                    <td className="px-5 py-4 text-gray-400 uppercase">{(role.sector || "geral").replace(/_/g, " ")}</td>
                    <td className="px-5 py-4">{roleStats[role.id] || 0}</td>
                    <td className="px-5 py-4">
                      <div className="flex justify-end gap-2">
                        <button onClick={() => handleEnterRole(role)} className="btn-secondary h-9 px-3">Entrar</button>
                        <button
                          onClick={() => {
                            setSelectedRole(role);
                            setIsImportModalOpen(true);
                          }}
                          className="btn-primary h-9 px-3 flex items-center gap-2"
                        >
                          <FileDown size={14} /> Importar
                        </button>
                        <button onClick={() => handleDeleteRole(role)} className="p-2 rounded-lg border border-gray-700 text-red-400 hover:bg-red-900/20">
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

        <CsvImportModal
          isOpen={isImportModalOpen}
          onClose={() => {
            setIsImportModalOpen(false);
            setSelectedRole(null);
          }}
          eventId={eventId}
          mode="workforce"
          workforceRoleId={selectedRole?.id || null}
          workforceSector={selectedRole?.sector || DEFAULT_SECTOR_BY_ROLE(selectedRole?.name || "")}
          workforceRoleName={selectedRole?.name || ""}
          onImported={async () => {
            await fetchRolesAndAssignments();
          }}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-3">
        <button onClick={() => setSelectedRole(null)} className="btn-secondary flex items-center gap-2">
          <ArrowLeft size={16} /> Voltar para Cargos
        </button>
        <div className="text-right">
          <p className="text-xs uppercase text-gray-500 tracking-wider">Cargo Atual</p>
          <p className="text-lg font-black text-white">{selectedRole.name}</p>
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
            <button onClick={() => setSelectedIds([])} className="btn-secondary h-9 px-3 text-xs">Limpar</button>
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
          <button onClick={() => setIsImportModalOpen(true)} className="btn-secondary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2">
            <FileDown size={16} /> Importar CSV
          </button>
          <button onClick={() => setIsAddModalOpen(true)} className="btn-primary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2">
            <Plus size={16} /> Alocação Manual
          </button>
        </div>
      </div>

      <div className="card overflow-hidden p-0 border border-gray-800">
        <table className="w-full text-left text-sm text-gray-300">
          <thead className="bg-gray-900/80 text-gray-500 uppercase text-[10px] tracking-wider border-b border-gray-800">
            <tr>
              <th className="px-5 py-4 w-10">
                <input type="checkbox" className="checkbox" checked={roleMembers.length > 0 && selectedIds.length === roleMembers.length} onChange={toggleSelectAll} />
              </th>
              <th className="px-5 py-4">Membro</th>
              <th className="px-5 py-4">Setor / Link</th>
              <th className="px-5 py-4 text-right">Ações</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-800/60">
            {loading ? (
              <tr><td colSpan="4" className="p-10 text-center"><div className="spinner mx-auto" /></td></tr>
            ) : roleMembers.length === 0 ? (
              <tr><td colSpan="4" className="p-10 text-center text-gray-500">Nenhum membro neste cargo.</td></tr>
            ) : (
              roleMembers.map((m) => (
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
                      </div>
                    </div>
                  </td>
                  <td className="px-5 py-4">
                    <div className="text-xs text-gray-400 uppercase">{(m.assignment?.sector || selectedRole.sector || "geral").replace(/_/g, " ")}</div>
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
                      Turnos: {m.assignment?.max_shifts_event ?? 1} | Horas: {m.assignment?.shift_hours ?? 8}h | Refeições/dia: {m.assignment?.meals_per_day ?? 4}
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
        presetRoleId={selectedRole.id}
        presetSector={selectedRole.sector || DEFAULT_SECTOR_BY_ROLE(selectedRole.name)}
        lockRole
        onAdded={async () => {
          await fetchRolesAndAssignments();
          await fetchRoleMembers(selectedRole.id);
        }}
      />

      <CsvImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        eventId={eventId}
        mode="workforce"
        workforceRoleId={selectedRole.id}
        workforceSector={selectedRole.sector || DEFAULT_SECTOR_BY_ROLE(selectedRole.name)}
        workforceRoleName={selectedRole.name}
        onImported={async () => {
          await fetchRolesAndAssignments();
          await fetchRoleMembers(selectedRole.id);
        }}
      />

      <EditParticipantModal
        isOpen={isEditModalOpen}
        onClose={() => {
          setIsEditModalOpen(false);
          setEditingParticipant(null);
        }}
        participant={editingParticipant}
        onUpdated={async () => {
          await fetchRolesAndAssignments();
          await fetchRoleMembers(selectedRole.id);
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
          await fetchRolesAndAssignments();
          await fetchRoleMembers(selectedRole.id);
        }}
      />

      <BulkWorkforceSettingsModal
        isOpen={isBulkSettingsModalOpen}
        onClose={() => setIsBulkSettingsModalOpen(false)}
        participants={selectedParticipants}
        onSaved={async () => {
          await fetchRolesAndAssignments();
          await fetchRoleMembers(selectedRole.id);
        }}
      />
    </div>
  );
}
