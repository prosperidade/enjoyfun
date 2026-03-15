import { useEffect, useEffectEvent, useMemo, useState } from "react";
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

const normalizeSector = (value = "") =>
  String(value || "")
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim()
    .replace(/\s+/g, "_")
    .replace(/^_+|_+$/g, "");

const inferSectorFromRoleName = (name = "") => {
  const normalized = normalizeSector(name);
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
  for (const prefix of prefixes) {
    if (sector.startsWith(prefix)) {
      sector = sector.slice(prefix.length);
      break;
    }
  }

  return sector.replace(/^_+|_+$/g, "");
};

const normalizeCostBucket = (value = "", roleName = "") => {
  const normalized = String(value || "").toLowerCase().trim();
  if (normalized === "managerial" || normalized === "operational") {
    return normalized;
  }

  const roleKey = normalizeSector(roleName);
  return /(gerente|diretor|coordenador|supervisor|lider|chefe|manager)/.test(roleKey)
    ? "managerial"
    : "operational";
};

const buildManagerRows = (managers = [], roles = [], assignments = []) => {
  const teamSizeBySector = assignments.reduce((acc, assignment) => {
    const sectorKey = normalizeSector(assignment?.sector || "");
    if (!sectorKey) return acc;
    acc[sectorKey] = (acc[sectorKey] || 0) + 1;
    return acc;
  }, {});

  const rows = new Map();

  managers.forEach((manager) => {
    const sector = normalizeSector(manager?.sector || inferSectorFromRoleName(manager?.role_name || ""));
    const roleId = Number(manager?.role_id || 0);
    const managerKey =
      Number(manager?.user_id || 0) > 0
        ? `user-${Number(manager.user_id)}`
        : roleId > 0
          ? `role-${roleId}-${sector || "geral"}`
          : `participant-${Number(manager?.participant_id || 0)}`;

    rows.set(managerKey, {
      ...manager,
      manager_key: managerKey,
      role_id: roleId,
      sector,
      cost_bucket: normalizeCostBucket(manager?.cost_bucket, manager?.role_name),
      person_name: manager?.person_name || manager?.role_name || "Gerente",
      phone: manager?.phone || "",
      team_size: Number(manager?.team_size || (sector ? teamSizeBySector[sector] || 0 : 0))
    });
  });

  roles.forEach((role) => {
    const roleId = Number(role?.id || 0);
    const costBucket = normalizeCostBucket(role?.cost_bucket, role?.name);
    if (costBucket !== "managerial" || roleId <= 0) {
      return;
    }

    const sector = normalizeSector(role?.sector || inferSectorFromRoleName(role?.name || ""));
    const existingEntry = Array.from(rows.values()).find((row) => Number(row?.role_id || 0) === roleId);

    if (existingEntry) {
      existingEntry.sector = existingEntry.sector || sector;
      existingEntry.cost_bucket = "managerial";
      existingEntry.leader_name = role?.leader_name || existingEntry?.leader_name || "";
      existingEntry.leader_cpf = role?.leader_cpf || existingEntry?.leader_cpf || "";
      existingEntry.leader_phone = role?.leader_phone || existingEntry?.leader_phone || "";
      if (!existingEntry.phone) {
        existingEntry.phone = role?.leader_phone || "";
      }
      return;
    }

    const managerKey = `role-${roleId}-${sector || "geral"}`;
    rows.set(managerKey, {
      manager_key: managerKey,
      participant_id: 0,
      user_id: null,
      qr_token: null,
      role_id: roleId,
      role_name: role?.name || "Cargo gerencial",
      sector,
      cost_bucket: "managerial",
      person_name: role?.leader_name || role?.name || "Gerente",
      person_email: "",
      phone: role?.leader_phone || "",
      leader_name: role?.leader_name || "",
      leader_cpf: role?.leader_cpf || "",
      leader_phone: role?.leader_phone || "",
      team_size: sector ? teamSizeBySector[sector] || 0 : 0
    });
  });

  return Array.from(rows.values()).sort((left, right) => {
    const leftName = String(left?.person_name || left?.role_name || "").toLowerCase();
    const rightName = String(right?.person_name || right?.role_name || "").toLowerCase();
    return leftName.localeCompare(rightName, "pt-BR");
  });
};

const findManagerRow = (rows = [], manager = null) => {
  if (!manager) return null;

  return (
    rows.find((row) => Number(row?.user_id || 0) > 0 && Number(row?.user_id || 0) === Number(manager?.user_id || 0)) ||
    rows.find(
      (row) =>
        Number(row?.role_id || 0) === Number(manager?.role_id || 0) &&
        normalizeSector(row?.sector || "") === normalizeSector(manager?.sector || "")
    ) ||
    rows.find((row) => String(row?.manager_key || "") === String(manager?.manager_key || "")) ||
    null
  );
};

export default function WorkforceOpsTab({ eventId }) {
  const [managers, setManagers] = useState([]);
  const [roles, setRoles] = useState([]);
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
  const [managerRoleSettings, setManagerRoleSettings] = useState(null);
  const [managerRoleSettingsLoading, setManagerRoleSettingsLoading] = useState(false);
  const [eventStructure, setEventStructure] = useState({ days: 0, shifts: 0 });
  const [newManagerRoleName, setNewManagerRoleName] = useState("");
  const [savingNewManagerRole, setSavingNewManagerRole] = useState(false);
  const [pendingManagerRoleId, setPendingManagerRoleId] = useState(null);
  const [isCreatingRoleInline, setIsCreatingRoleInline] = useState(false);
  const [newRoleName, setNewRoleName] = useState("");
  const [savingNewRole, setSavingNewRole] = useState(false);

  const fetchManagers = async () => {
    setLoading(true);
    try {
      const [mgrRes, roleRes, assignmentRes] = await Promise.all([
        api.get(`/workforce/managers?event_id=${eventId}`),
        api.get("/workforce/roles"),
        api.get(`/workforce/assignments?event_id=${eventId}`)
      ]);
      const nextManagers = mgrRes.data.data || [];
      const nextRoles = roleRes.data.data || [];
      const nextAssignments = assignmentRes.data.data || [];
      setManagers(nextManagers);
      setRoles(nextRoles);
      setAssignments(nextAssignments);
      return { managers: nextManagers, roles: nextRoles, assignments: nextAssignments };
    } catch (error) {
      console.error(error);
      toast.error("Erro ao carregar gerentes e alocações.");
      return { managers: [], roles: [], assignments: [] };
    } finally {
      setLoading(false);
    }
  };

  const fetchManagerMembers = async (manager) => {
    setLoading(true);
    if (!manager) {
      setParticipants([]);
      setSelectedIds([]);
      setLoading(false);
      return;
    }
    try {
      const params = new URLSearchParams({ event_id: String(eventId) });
      if (Number(manager?.user_id || 0) > 0) {
        params.set("manager_user_id", String(manager.user_id));
      } else if (normalizeSector(manager?.sector || "")) {
        params.set("sector", normalizeSector(manager.sector));
      } else if (Number(manager?.role_id || 0) > 0) {
        params.set("role_id", String(manager.role_id));
      } else {
        setParticipants([]);
        setSelectedIds([]);
        setLoading(false);
        return;
      }

      const res = await api.get(`/workforce/assignments?${params.toString()}`);
      setParticipants(res.data.data || []);
      setSelectedIds([]);
    } catch (error) {
      console.error(error);
      toast.error("Erro ao carregar equipe do gerente.");
    } finally {
      setLoading(false);
    }
  };

  const buildRoleContext = (row) => {
    if (!row) return null;
    return {
      id: Number(row.role_id || row.id || 0),
      name: row.role_name || row.name || "",
      sector: normalizeSector(row.sector || inferSectorFromRoleName(row.role_name || row.name || ""))
    };
  };

  const loadManagerOperationalContext = async (manager = selectedManager) => {
    if (!manager?.role_id || !eventId) {
      setManagerRoleSettings(null);
      setEventStructure({ days: 0, shifts: 0 });
      return;
    }

    setManagerRoleSettingsLoading(true);
    try {
      const [roleRes, daysRes, shiftsRes] = await Promise.all([
        api.get(`/workforce/role-settings/${manager.role_id}`),
        api.get(`/event-days?event_id=${eventId}`),
        api.get(`/event-shifts?event_id=${eventId}`)
      ]);

      setManagerRoleSettings(roleRes.data?.data || null);
      setEventStructure({
        days: (daysRes.data?.data || []).length,
        shifts: (shiftsRes.data?.data || []).length
      });
    } catch (error) {
      console.error(error);
      setManagerRoleSettings(null);
      setEventStructure({ days: 0, shifts: 0 });
    } finally {
      setManagerRoleSettingsLoading(false);
    }
  };

  const syncManagersEffect = useEffectEvent(() => {
    fetchManagers();
  });

  const syncManagerMembersEffect = useEffectEvent((manager) => {
    fetchManagerMembers(manager);
  });

  const syncManagerOperationalContextEffect = useEffectEvent((manager) => {
    loadManagerOperationalContext(manager);
  });

  useEffect(() => {
    if (!eventId) return;
    setSelectedManager(null);
    setParticipants([]);
    setSelectedIds([]);
    syncManagersEffect();
  }, [eventId]);

  const managerRows = useMemo(() => buildManagerRows(managers, roles, assignments), [managers, roles, assignments]);

  useEffect(() => {
    if (!selectedManager) {
      setParticipants([]);
      setSelectedIds([]);
      return;
    }
    syncManagerMembersEffect(selectedManager);
  }, [selectedManager?.user_id, selectedManager?.role_id, selectedManager?.sector, eventId]);

  useEffect(() => {
    if (!selectedManager) {
      setManagerRoleSettings(null);
      setEventStructure({ days: 0, shifts: 0 });
      return;
    }
    syncManagerOperationalContextEffect(selectedManager);
  }, [selectedManager, selectedManager?.role_id, selectedManager?.participant_id, eventId]);

  const refreshSelectedManagerView = async (manager = selectedManager) => {
    const overview = await fetchManagers();
    if (!manager) {
      return;
    }

    const refreshedManager = findManagerRow(
      buildManagerRows(overview.managers, overview.roles, overview.assignments),
      manager
    ) || manager;

    setSelectedManager(refreshedManager);
    await loadManagerOperationalContext(refreshedManager);
    await fetchManagerMembers(refreshedManager);
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
  const selectedManagerSector = normalizeSector(selectedManager?.sector || selectedManagerRole?.sector || "");
  const hasDirectManagerBinding = Boolean(selectedManager?.user_id);
  const canOperateSelectedManager = Boolean(selectedManagerRole?.id || selectedManagerSector);
  const managerRoleMembersCount = Number(selectedManager?.team_size || teamMembers.length || 0);

  const handleEnterManager = (manager) => {
    setSelectedManager(manager);
    setSearchTerm("");
    setSelectedIds([]);
    setIsCreatingRoleInline(false);
    setNewRoleName("");
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

  const handleCreateOperationalRole = async () => {
    const safeName = newRoleName.trim();
    if (!safeName) {
      toast.error("Informe o nome do cargo.");
      return;
    }

    setSavingNewRole(true);
    try {
      const res = await api.post("/workforce/roles", {
        name: safeName,
        sector: selectedManagerSector || undefined
      });
      const createdRole = res.data?.data || {};
      const roleContext = {
        id: Number(createdRole.id || 0),
        name: createdRole.name || safeName,
        sector: createdRole.sector || selectedManagerSector || ""
      };

      toast.success("Cargo criado com sucesso.");
      setNewRoleName("");
      setIsCreatingRoleInline(false);
      setRoleSettingsRole(roleContext);
      setIsRoleSettingsModalOpen(true);
      await refreshSelectedManagerView(selectedManager);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao criar cargo.");
    } finally {
      setSavingNewRole(false);
    }
  };

  const handleCreateManagerRole = async () => {
    const safeName = newManagerRoleName.trim();
    if (!safeName) {
      toast.error("Informe o nome do cargo gerencial.");
      return;
    }

    const inferredSector = inferSectorFromRoleName(safeName);

    setSavingNewManagerRole(true);
    try {
      const res = await api.post("/workforce/roles", {
        name: safeName,
        sector: inferredSector || undefined
      });

      const createdRoleId = Number(res.data?.data?.id || 0);
      const createdRole = {
        id: createdRoleId,
        name: safeName,
        sector: inferredSector
      };

      toast.success("Cargo gerencial criado. Configure o gerente para liberar a tabela.");
      setNewManagerRoleName("");
      setPendingManagerRoleId(createdRoleId || null);
      setRoleSettingsRole(createdRole);
      setIsRoleSettingsModalOpen(true);
      await fetchManagers();
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao criar cargo gerencial.");
    } finally {
      setSavingNewManagerRole(false);
    }
  };

  const handleRoleSettingsSaved = async () => {
    const overview = await fetchManagers();
    const nextRows = buildManagerRows(overview.managers, overview.roles, overview.assignments);
    const targetRoleId = Number(pendingManagerRoleId || 0);

    if (targetRoleId > 0) {
      const nextManager = nextRows.find((row) => Number(row?.role_id || 0) === targetRoleId) || null;
      if (nextManager) {
        setSelectedManager(nextManager);
        await loadManagerOperationalContext(nextManager);
        await fetchManagerMembers(nextManager);
      }
    } else if (selectedManager) {
      const refreshedManager = findManagerRow(nextRows, selectedManager) || selectedManager;
      setSelectedManager(refreshedManager);
      await loadManagerOperationalContext(refreshedManager);
      await fetchManagerMembers(refreshedManager);
    }

    setPendingManagerRoleId(null);
  };

  if (!selectedManager) {
    return (
      <div className="space-y-6">
        <div className="card p-4 border border-gray-800 bg-gray-900/40">
          <div className="grid grid-cols-1 md:grid-cols-[1fr_180px] gap-3">
            <input
              className="input"
              placeholder="Novo cargo gerencial (ex: Gerente de Bar)"
              value={newManagerRoleName}
              onChange={(event) => setNewManagerRoleName(event.target.value)}
            />
            <button
              disabled={savingNewManagerRole}
              onClick={handleCreateManagerRole}
              className="btn-primary flex items-center justify-center gap-2"
            >
              <Plus size={16} /> {savingNewManagerRole ? "Criando..." : "Criar Cargo"}
            </button>
          </div>
          <div className="mt-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-xs text-gray-500">
            <div>
              <p className="font-semibold text-white">Crie o cargo do gerente, configure nome/CPF/telefone e depois entre na tabela para importar a equipe.</p>
              <p className="mt-1">
                Setor inferido agora:{" "}
                <span className="text-brand">{inferSectorFromRoleName(newManagerRoleName) || "sera definido pelo nome do cargo"}</span>
              </p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-950/50 px-3 py-2 uppercase tracking-wider">
              {managerRows.length === 0
                ? "Nenhum gerente configurado"
                : `${managerRows.length} gerente(s) / cargo(s) gerencial(is)`}
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
              ) : managerRows.length === 0 ? (
                <tr><td colSpan="4" className="p-10 text-center text-gray-500">Nenhum gerente ou cargo gerencial configurado neste evento.</td></tr>
              ) : (
                managerRows.map((mgr) => (
                  <tr key={mgr.manager_key || `${mgr.role_id}-${mgr.sector}`} className="hover:bg-gray-800/30">
                    <td className="px-5 py-4">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-brand/10 border border-brand/20 flex items-center justify-center text-brand font-black">
                          {(mgr.person_name || mgr.role_name || "?").charAt(0).toUpperCase()}
                        </div>
                        <div>
                           <p className="font-semibold text-white">{mgr.person_name || mgr.role_name}</p>
                           <p className="text-xs text-gray-500">{mgr.phone || mgr.person_email || "Sem contato"}</p>
                           {!mgr.user_id && (
                             <p className="text-[10px] text-amber-400 uppercase tracking-wider mt-1">
                               Operando por setor/cargo
                             </p>
                           )}
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

        <WorkforceRoleSettingsModal
          isOpen={isRoleSettingsModalOpen}
          role={roleSettingsRole}
          eventId={eventId}
          roleMembersCount={0}
          onClose={() => {
            setIsRoleSettingsModalOpen(false);
            setRoleSettingsRole(null);
            setPendingManagerRoleId(null);
          }}
          onSaved={handleRoleSettingsSaved}
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
          <p className="text-lg font-black text-white">{selectedManager.person_name || selectedManager.role_name}</p>
          <p className="text-[10px] text-brand uppercase mt-1">{selectedManager.role_name} — Setor: {(selectedManagerSector || "geral").replace(/_/g, " ")}</p>
          {!hasDirectManagerBinding && (
            <p className="text-[10px] text-amber-400 uppercase mt-2">
              Gerente sem usuário vinculado. A operação segue pelo setor/cargo configurado.
            </p>
          )}
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-[1.4fr,1fr]">
        <div className="card border border-gray-800 bg-gray-900/40 p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Painel do Gerente</p>
              <p className="mt-1 text-sm text-gray-400">
                Recuperado no fluxo operacional do gerente: custos, configuração do cargo e dados-base da liderança.
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <button
                onClick={() => {
                  setRoleSettingsRole(selectedManagerRole);
                  setIsRoleSettingsModalOpen(true);
                }}
                className="btn-secondary h-10 px-4 text-xs flex items-center gap-2"
                disabled={!selectedManagerRole?.id}
              >
                <Settings2 size={14} /> Configurar Cargo
              </button>
              <button
                onClick={() => {
                  setSectorCostsRole(selectedManagerRole);
                  setIsSectorCostsModalOpen(true);
                }}
                className="btn-secondary h-10 px-4 text-xs flex items-center gap-2"
                disabled={!selectedManagerRole?.id}
              >
                <Briefcase size={14} /> Custos
              </button>
            </div>
          </div>

          {managerRoleSettingsLoading ? (
            <div className="h-24 flex items-center justify-center">
              <div className="spinner" />
            </div>
          ) : (
            <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Nome</p>
                <p className="mt-2 text-sm font-semibold text-white">{managerRoleSettings?.leader_name || selectedManager?.person_name || selectedManager?.role_name || "Nao informado"}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">CPF</p>
                <p className="mt-2 text-sm font-semibold text-white">{managerRoleSettings?.leader_cpf || "Nao informado"}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Telefone</p>
                <p className="mt-2 text-sm font-semibold text-white">{managerRoleSettings?.leader_phone || selectedManager?.phone || "Nao informado"}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Tipo de custo</p>
                <p className="mt-2 text-sm font-semibold text-white">
                  {String(managerRoleSettings?.cost_bucket || selectedManager?.cost_bucket || "operational") === "managerial"
                    ? "Gerencial"
                    : "Operacional"}
                </p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Quantidade de turnos</p>
                <p className="mt-2 text-sm font-semibold text-white">{Number(managerRoleSettings?.max_shifts_event ?? 1).toLocaleString("pt-BR")}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Horas por turno</p>
                <p className="mt-2 text-sm font-semibold text-white">{Number(managerRoleSettings?.shift_hours ?? 8).toLocaleString("pt-BR")}h</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Refeicoes</p>
                <p className="mt-2 text-sm font-semibold text-white">{Number(managerRoleSettings?.meals_per_day ?? 4).toLocaleString("pt-BR")} por dia</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Valor por turno</p>
                <p className="mt-2 text-sm font-semibold text-white">R$ {Number(managerRoleSettings?.payment_amount ?? 0).toFixed(2)}</p>
              </div>
            </div>
          )}
        </div>

        <div className="card border border-gray-800 bg-gray-900/40 p-4 space-y-4">
          <div>
            <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Estrutura operacional</p>
            <div className="mt-3 grid grid-cols-2 gap-3">
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Dias do evento</p>
                <p className="mt-2 text-lg font-black text-white">{eventStructure.days}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Turnos</p>
                <p className="mt-2 text-lg font-black text-white">{eventStructure.shifts}</p>
              </div>
            </div>
            <p className="mt-3 text-xs text-gray-500">
              A alocacao manual do gerente continua permitindo escolher dia e turno do evento para cada membro.
            </p>
          </div>

          <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
            <div className="flex items-center justify-between gap-3">
              <div>
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Criar cargo operacional</p>
                <p className="mt-1 text-xs text-gray-500">Novo cargo no setor atual do gerente, sem sair do painel.</p>
              </div>
              <button
                type="button"
                onClick={() => setIsCreatingRoleInline((prev) => !prev)}
                className="btn-secondary h-9 px-3 text-xs flex items-center gap-2"
              >
                <Plus size={14} /> {isCreatingRoleInline ? "Fechar" : "Novo Cargo"}
              </button>
            </div>

            {isCreatingRoleInline && (
              <div className="mt-3 flex flex-col gap-2">
                <input
                  type="text"
                  className="input w-full"
                  placeholder="Ex: Coordenador de Equipe"
                  value={newRoleName}
                  onChange={(e) => setNewRoleName(e.target.value)}
                />
                <div className="flex items-center justify-between gap-2">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">
                    Setor: {(selectedManagerSector || "geral").replace(/_/g, " ")}
                  </p>
                  <button
                    type="button"
                    onClick={handleCreateOperationalRole}
                    disabled={savingNewRole}
                    className="btn-primary h-9 px-3 text-xs"
                  >
                    {savingNewRole ? "Criando..." : "Criar Cargo"}
                  </button>
                </div>
              </div>
            )}
          </div>
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
            onClick={() => setIsImportModalOpen(true)}
            className="btn-secondary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2"
            disabled={!canOperateSelectedManager}
          >
            <FileDown size={16} /> Importar CSV
          </button>
          <button
            onClick={() => setIsAddModalOpen(true)}
            className="btn-primary flex-1 lg:flex-none h-11 px-4 flex items-center gap-2"
            disabled={!canOperateSelectedManager}
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
                    <div className="text-xs text-gray-400 uppercase">{(m.sector || selectedManagerSector || "geral").replace(/_/g, " ")}</div>
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
        presetRoleCostBucket={selectedManager?.cost_bucket || "operational"}
        presetSector={selectedManagerSector}
        managerUserId={hasDirectManagerBinding ? selectedManager?.user_id || null : null}
        lockSector={Boolean(selectedManagerSector)}
        onAdded={async () => {
          await refreshSelectedManagerView(selectedManager);
        }}
      />

      <CsvImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        eventId={eventId}
        mode="workforce"
        workforceRoleId={selectedManagerRole?.id || null}
        workforceSector={selectedManagerSector}
        workforceRoleCostBucket={selectedManager?.cost_bucket || "operational"}
        managerUserId={hasDirectManagerBinding ? selectedManager?.user_id || null : null}
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
        roleMembersCount={Number(roleSettingsRole?.id || 0) === Number(selectedManagerRole?.id || 0) ? managerRoleMembersCount : 0}
        onClose={() => {
          setIsRoleSettingsModalOpen(false);
          setRoleSettingsRole(null);
        }}
        onSaved={handleRoleSettingsSaved}
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
