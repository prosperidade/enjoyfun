import { useEffect, useEffectEvent, useMemo, useState } from "react";
import {
  Activity,
  ArrowLeft,
  Briefcase,
  Clock3,
  Copy,
  CreditCard,
  FileDown,
  Mail,
  MessageCircle,
  Pencil,
  Plus,
  QrCode,
  RefreshCw,
  Search,
  Settings2,
  ShieldAlert,
  Trash2,
  Users
} from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";
import { useAuth } from "../../context/AuthContext";
import AddWorkforceAssignmentModal from "./AddWorkforceAssignmentModal";
import CsvImportModal from "./CsvImportModal";
import BulkMessageModal from "./BulkMessageModal";
import EditParticipantModal from "./EditParticipantModal";
import WorkforceMemberSettingsModal from "./WorkforceMemberSettingsModal";
import BulkWorkforceSettingsModal from "./BulkWorkforceSettingsModal";
import WorkforceCardIssuanceModal from "./WorkforceCardIssuanceModal";
import WorkforceRoleSettingsModal from "./WorkforceRoleSettingsModal";
import WorkforceSectorCostsModal from "./WorkforceSectorCostsModal";
import WorkforceAIAssistant from "../../components/WorkforceAIAssistant";
import { reportOperationalTelemetry } from "../../lib/operationalTelemetry";

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
  const roleKey = normalizeSector(roleName);
  if (/^(equipe|time|staff)(_|$)/.test(roleKey)) {
    return "operational";
  }
  if (/(gerente|diretor|coordenador|supervisor|lider|chefe|manager)/.test(roleKey)) {
    return "managerial";
  }

  const normalized = String(value || "").toLowerCase().trim();
  if (normalized === "managerial" || normalized === "operational") {
    return normalized;
  }

  return "operational";
};

const hasLeadershipIdentity = (row = {}) => {
  const hasBoundIdentity =
    Number(row?.leader_participant_id || 0) > 0 ||
    Number(row?.leader_user_id || 0) > 0;
  const hasManualIdentity =
    String(row?.leader_name || row?.leader_participant_name || "").trim() !== "" &&
    String(row?.leader_cpf || "").replace(/\D/g, "") !== "";

  return hasBoundIdentity || hasManualIdentity;
};

const inferRoleClassFromRoleName = (name = "", costBucket = "") => {
  const roleKey = normalizeSector(name);
  if (!roleKey) {
    return normalizeCostBucket(costBucket, name) === "managerial" ? "manager" : "operational";
  }

  if (/^(equipe|time|staff)(_|$)/.test(roleKey)) {
    return "operational";
  }
  if (/(^|_)(gerente|diretor|manager|gestor)(_|$)/.test(roleKey)) {
    return "manager";
  }
  if (/(^|_)coordenador(_|$)/.test(roleKey)) {
    return "coordinator";
  }
  if (/(^|_)(supervisor|lider|chefe)(_|$)/.test(roleKey)) {
    return "supervisor";
  }

  return normalizeCostBucket(costBucket, name) === "managerial" ? "manager" : "operational";
};

const parseSqlDateTime = (value = "") => {
  const normalized = String(value || "").trim();
  if (!normalized) return null;

  const parsed = new Date(normalized.replace(" ", "T"));
  return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const calculateShiftDurationHours = (shift = {}) => {
  const startsAt = parseSqlDateTime(shift?.starts_at);
  const endsAt = parseSqlDateTime(shift?.ends_at);
  if (!startsAt || !endsAt) {
    return 0;
  }

  let durationMs = endsAt.getTime() - startsAt.getTime();
  if (durationMs < 0) {
    durationMs += 24 * 60 * 60 * 1000;
  }

  return durationMs / (60 * 60 * 1000);
};

const calculateOperationalShiftSlots = (shifts = [], shiftHours = 8) => {
  const normalizedShiftHours = Number(shiftHours || 0);
  if (!(normalizedShiftHours > 0)) {
    return Array.isArray(shifts) ? shifts.length : 0;
  }

  return (Array.isArray(shifts) ? shifts : []).reduce((total, shift) => {
    const durationHours = calculateShiftDurationHours(shift);
    if (!(durationHours > 0)) {
      return total + 1;
    }

    return total + Math.max(1, Math.ceil(durationHours / normalizedShiftHours));
  }, 0);
};

const WORKFORCE_SNAPSHOT_PREFIX = "enjoyfun_workforce_snapshot_v2";
const WORKFORCE_BULK_CARD_FLAG_VALUES = new Set(["1", "true", "yes", "on", "enabled"]);

const getWorkforceSnapshotKey = (eventId) => `${WORKFORCE_SNAPSHOT_PREFIX}_${eventId}`;

const readWorkforceSnapshot = (eventId) => {
  if (!eventId || typeof window === "undefined" || !window.localStorage) {
    return { data: null, error: null };
  }

  try {
    const raw = window.localStorage.getItem(getWorkforceSnapshotKey(eventId));
    if (!raw) return { data: null, error: null };
    return { data: JSON.parse(raw), error: null };
  } catch (error) {
    return { data: null, error };
  }
};

const writeWorkforceSnapshot = (eventId, payload) => {
  if (!eventId || typeof window === "undefined" || !window.localStorage) {
    return { ok: false, error: null };
  }

  try {
    window.localStorage.setItem(
      getWorkforceSnapshotKey(eventId),
      JSON.stringify({
        ...payload,
        saved_at: new Date().toISOString()
      })
    );
    return { ok: true, error: null };
  } catch (error) {
    return { ok: false, error };
  }
};

const shouldPreferEventTree = (treeStatus = null, eventRoles = []) =>
  (String(treeStatus?.source_preference || "") === "event_roles" || Boolean(treeStatus?.tree_usable)) &&
  Array.isArray(eventRoles) &&
  eventRoles.some(
    (row) =>
      !Number(row?.parent_event_role_id || 0) &&
      normalizeCostBucket(row?.cost_bucket, row?.role_name) === "managerial"
  );

const hasEventManagerRoots = (eventRoles = []) =>
  Array.isArray(eventRoles) &&
  eventRoles.some(
    (row) =>
      !Number(row?.parent_event_role_id || 0) &&
      normalizeCostBucket(row?.cost_bucket, row?.role_name) === "managerial"
  );

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

  const legacyRows = Array.from(rows.values());
  const leadershipCountBySector = legacyRows.reduce((acc, row) => {
    const sector = normalizeSector(row?.sector || "");
    if (!sector) return acc;
    acc[sector] = (acc[sector] || 0) + 1;
    return acc;
  }, {});

  return legacyRows
    .map((row) => {
      const sector = normalizeSector(row?.sector || "") || "geral";
      const operationalMembersTotal = Number(teamSizeBySector[sector] || 0);
      const leadershipPositionsTotal = Number(leadershipCountBySector[sector] || 0);
      const plannedTeamSize = operationalMembersTotal + leadershipPositionsTotal;

      return {
        ...row,
        leadership_positions_total: leadershipPositionsTotal,
        leadership_filled_total: leadershipPositionsTotal,
        leadership_placeholder_total: 0,
        operational_members_total: operationalMembersTotal,
        planned_team_size: plannedTeamSize,
        filled_team_size: plannedTeamSize,
        team_size: plannedTeamSize
      };
    })
    .sort((left, right) => {
      const leftName = String(left?.person_name || left?.role_name || "").toLowerCase();
      const rightName = String(right?.person_name || right?.role_name || "").toLowerCase();
      return leftName.localeCompare(rightName, "pt-BR");
    });
};

const buildRootHeadcountMap = (eventRoles = [], assignments = []) => {
  const counts = {};
  const eventRolesById = eventRoles.reduce((acc, row) => {
    const eventRoleId = Number(row?.id || row?.event_role_id || 0);
    if (eventRoleId > 0) {
      acc[eventRoleId] = row;
    }
    return acc;
  }, {});

  const ensureBucket = (rootId, sector = "geral") => {
    if (!counts[rootId]) {
      counts[rootId] = {
        root_event_role_id: rootId,
        sector: sector || "geral",
        members: 0,
        planned_members_total: 0,
        filled_members_total: 0,
        leadership_positions_total: 0,
        leadership_filled_total: 0,
        leadership_placeholder_total: 0,
        operational_members_total: 0
      };
    }
    return counts[rootId];
  };

  assignments.forEach((assignment) => {
    const rootId = Number(assignment?.root_manager_event_role_id || 0);
    if (rootId <= 0) return;
    const boundEventRole = eventRolesById[Number(assignment?.event_role_id || 0)] || null;
    const normalizedCostBucket = normalizeCostBucket(
      boundEventRole?.cost_bucket || assignment?.cost_bucket,
      boundEventRole?.role_name || assignment?.role_name
    );
    if (normalizedCostBucket === "managerial") {
      return;
    }

    const bucket = ensureBucket(
      rootId,
      normalizeSector(
        assignment?.sector ||
          boundEventRole?.sector ||
          boundEventRole?.role_sector ||
          inferSectorFromRoleName(boundEventRole?.role_name || assignment?.role_name || "")
      ) || "geral"
    );
    bucket.operational_members_total += 1;
    bucket.planned_members_total += 1;
    bucket.filled_members_total += 1;
    bucket.members = bucket.planned_members_total;
  });

  eventRoles.forEach((row) => {
    if (normalizeCostBucket(row?.cost_bucket, row?.role_name) !== "managerial") {
      return;
    }

    const rootId = Number(row?.root_event_role_id || row?.id || 0);
    if (rootId <= 0) return;

    const bucket = ensureBucket(rootId, normalizeSector(row?.sector || row?.role_sector || "") || "geral");
    const isFilled = hasLeadershipIdentity(row);
    const isPlaceholder = !isFilled;

    bucket.leadership_positions_total += 1;
    bucket.planned_members_total += 1;
    if (isFilled) {
      bucket.leadership_filled_total += 1;
      bucket.filled_members_total += 1;
    }
    if (isPlaceholder) {
      bucket.leadership_placeholder_total += 1;
    }
    bucket.members = bucket.planned_members_total;
  });

  return counts;
};

const buildManagerRowsFromEventTree = (eventRoles = [], assignments = []) => {
  const headcountByRoot = buildRootHeadcountMap(eventRoles, assignments);

  return eventRoles
    .filter(
      (row) =>
        Number(row?.parent_event_role_id || 0) <= 0 &&
        normalizeCostBucket(row?.cost_bucket, row?.role_name) === "managerial"
    )
    .map((row) => {
      const eventRoleId = Number(row?.id || 0);
      const rootEventRoleId = Number(row?.root_event_role_id || eventRoleId || 0);
      const rootHeadcount = headcountByRoot[rootEventRoleId] || {};

      return {
        manager_key: `event-role-${eventRoleId}`,
        participant_id: Number(row?.leader_participant_id || 0),
        user_id: Number(row?.leader_user_id || 0) || null,
        qr_token: row?.leader_qr_token || null,
        role_id: Number(row?.role_id || 0),
        role_name: row?.role_name || "Cargo gerencial",
        sector: normalizeSector(row?.sector || row?.role_sector || inferSectorFromRoleName(row?.role_name || "")),
        cost_bucket: normalizeCostBucket(row?.cost_bucket, row?.role_name),
        person_name:
          row?.leader_participant_name ||
          row?.leader_name ||
          row?.role_name ||
          "Gerente",
        person_email: row?.leader_participant_email || row?.leader_user_email || "",
        phone: row?.leader_participant_phone || row?.leader_phone || "",
        leader_name: row?.leader_name || row?.leader_participant_name || "",
        leader_cpf: row?.leader_cpf || "",
        leader_phone: row?.leader_phone || row?.leader_participant_phone || "",
        event_role_id: eventRoleId || null,
        event_role_public_id: row?.public_id || "",
        root_event_role_id: rootEventRoleId || null,
        root_public_id: row?.root_public_id || row?.public_id || "",
        parent_event_role_id: Number(row?.parent_event_role_id || 0) || null,
        parent_public_id: row?.parent_public_id || "",
        role_class: row?.role_class || inferRoleClassFromRoleName(row?.role_name, row?.cost_bucket),
        authority_level: row?.authority_level || "",
        leadership_positions_total: Number(rootHeadcount?.leadership_positions_total || 0),
        leadership_filled_total: Number(rootHeadcount?.leadership_filled_total || 0),
        leadership_placeholder_total: Number(rootHeadcount?.leadership_placeholder_total || 0),
        operational_members_total: Number(rootHeadcount?.operational_members_total || 0),
        planned_team_size: Number(rootHeadcount?.planned_members_total || 0),
        filled_team_size: Number(rootHeadcount?.filled_members_total || 0),
        team_size: Number(rootHeadcount?.planned_members_total || 0)
      };
    })
    .sort((left, right) => {
      const leftName = String(left?.person_name || left?.role_name || "").toLowerCase();
      const rightName = String(right?.person_name || right?.role_name || "").toLowerCase();
      return leftName.localeCompare(rightName, "pt-BR");
    });
};

const buildManagerStructureRows = (eventRoles = [], assignments = [], manager = null) => {
  const rootId = Number(manager?.root_event_role_id || manager?.event_role_id || 0);
  if (rootId <= 0) return [];
  const rolesById = eventRoles.reduce((acc, row) => {
    const eventRoleId = Number(row?.id || 0);
    if (eventRoleId > 0) {
      acc[eventRoleId] = row;
    }
    return acc;
  }, {});

  const membersByEventRoleId = assignments.reduce((acc, assignment) => {
    const eventRoleId = Number(assignment?.event_role_id || 0);
    if (eventRoleId <= 0) return acc;
    acc[eventRoleId] = (acc[eventRoleId] || 0) + 1;
    return acc;
  }, {});

  const membersByRootId = assignments.reduce((acc, assignment) => {
    const boundRootId = Number(assignment?.root_manager_event_role_id || 0);
    if (boundRootId <= 0) return acc;
    acc[boundRootId] = (acc[boundRootId] || 0) + 1;
    return acc;
  }, {});

  const roleClassOrder = {
    manager: 0,
    coordinator: 1,
    supervisor: 2,
    operational: 3
  };

  return eventRoles
    .filter((row) => Number(row?.root_event_role_id || row?.id || 0) === rootId)
    .map((row) => {
      const eventRoleId = Number(row?.id || 0);
      const normalizedCostBucket = normalizeCostBucket(row?.cost_bucket, row?.role_name);
      const normalizedRoleClass = inferRoleClassFromRoleName(row?.role_name, normalizedCostBucket);
      const isManagerial = normalizedCostBucket === "managerial";
      const filledLeadership = isManagerial && hasLeadershipIdentity(row) ? 1 : 0;
      const directMembersCount = Number(membersByEventRoleId[eventRoleId] || 0);
      const parentRole = rolesById[Number(row?.parent_event_role_id || 0)] || null;
      const rootRole = rolesById[Number(row?.root_event_role_id || eventRoleId || 0)] || null;
      const parentRoleClass = inferRoleClassFromRoleName(parentRole?.role_name, parentRole?.cost_bucket);
      const rootRoleClass = inferRoleClassFromRoleName(rootRole?.role_name, rootRole?.cost_bucket);
      const ownLeaderName = row?.leader_participant_name || row?.leader_name || "";
      const inheritedLeaderName =
        parentRole?.leader_participant_name ||
        parentRole?.leader_name ||
        rootRole?.leader_participant_name ||
        rootRole?.leader_name ||
        "";
      const linkedLeaderName = isManagerial ? ownLeaderName : ownLeaderName || inheritedLeaderName;
      const linkedLeaderRoleClass = ownLeaderName
        ? normalizedRoleClass
        : !isManagerial && (parentRole?.leader_participant_name || parentRole?.leader_name)
          ? parentRoleClass
          : !isManagerial
            ? rootRoleClass
            : "";

      return {
        ...row,
        cost_bucket: normalizedCostBucket,
        role_class: normalizedRoleClass,
        qr_token: row?.leader_qr_token || "",
        event_role_id: eventRoleId || null,
        event_role_public_id: row?.public_id || "",
        root_event_role_id: Number(row?.root_event_role_id || eventRoleId || 0) || null,
        root_public_id: row?.root_public_id || row?.public_id || "",
        linked_leader_name: linkedLeaderName,
        linked_leader_role_class: linkedLeaderRoleClass,
        planned_members_count: isManagerial ? 1 : directMembersCount,
        filled_members_count: isManagerial ? filledLeadership : directMembersCount,
        members_count: isManagerial ? 1 : directMembersCount,
        root_members_count: Number(membersByRootId[rootId] || 0)
      };
    })
    .sort((left, right) => {
      const leftParent = Number(left?.parent_event_role_id || 0);
      const rightParent = Number(right?.parent_event_role_id || 0);
      if (leftParent === 0 && rightParent !== 0) return -1;
      if (leftParent !== 0 && rightParent === 0) return 1;

      const leftClassOrder = roleClassOrder[left?.role_class] ?? 99;
      const rightClassOrder = roleClassOrder[right?.role_class] ?? 99;
      if (leftClassOrder !== rightClassOrder) {
        return leftClassOrder - rightClassOrder;
      }

      const leftSort = Number(left?.sort_order || 0);
      const rightSort = Number(right?.sort_order || 0);
      if (leftSort !== rightSort) {
        return leftSort - rightSort;
      }

      const leftName = String(left?.role_name || "").toLowerCase();
      const rightName = String(right?.role_name || "").toLowerCase();
      return leftName.localeCompare(rightName, "pt-BR");
    });
};

const formatRoleClassLabel = (roleClass = "") => {
  switch (String(roleClass || "").toLowerCase()) {
    case "manager":
      return "Gerente";
    case "coordinator":
      return "Coordenador";
    case "supervisor":
      return "Supervisor";
    default:
      return "Operacional";
  }
};

const findManagerRow = (rows = [], manager = null) => {
  if (!manager) return null;

  return (
    rows.find(
      (row) =>
        Number(row?.event_role_id || 0) > 0 &&
        Number(row?.event_role_id || 0) === Number(manager?.event_role_id || 0)
    ) ||
    rows.find(
      (row) =>
        Number(row?.root_event_role_id || row?.event_role_id || 0) > 0 &&
        Number(row?.root_event_role_id || row?.event_role_id || 0) ===
          Number(manager?.root_event_role_id || manager?.event_role_id || 0)
    ) ||
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

const normalizeOperationalStatus = (value = "") => {
  const normalized = String(value || "").trim().toLowerCase();
  if (["healthy", "warning", "degraded", "no_data"].includes(normalized)) {
    return normalized;
  }
  return "no_data";
};

const operationalStatusMeta = (status = "") => {
  switch (normalizeOperationalStatus(status)) {
    case "healthy":
      return {
        label: "Estavel",
        badgeClass: "border-emerald-500/30 bg-emerald-500/10 text-emerald-300",
        cardClass: "border-emerald-500/20 bg-emerald-500/5"
      };
    case "warning":
      return {
        label: "Atencao",
        badgeClass: "border-amber-500/30 bg-amber-500/10 text-amber-300",
        cardClass: "border-amber-500/20 bg-amber-500/5"
      };
    case "degraded":
      return {
        label: "Instavel",
        badgeClass: "border-rose-500/30 bg-rose-500/10 text-rose-300",
        cardClass: "border-rose-500/20 bg-rose-500/5"
      };
    default:
      return {
        label: "Sem dados",
        badgeClass: "border-gray-700 bg-gray-900/70 text-gray-400",
        cardClass: "border-gray-800 bg-gray-950/50"
      };
  }
};

const formatOperationalSourceLabel = (value = "") => {
  switch (String(value || "").toLowerCase()) {
    case "event_roles":
      return "Estrutura do evento";
    case "hybrid":
      return "Estrutura em transicao";
    default:
      return "Cadastro antigo";
  }
};

const formatOperationalEndpointLabel = (endpoint = "") => {
  switch (String(endpoint || "")) {
    case "GET /workforce/tree-status":
      return "Leitura da estrutura";
    case "GET /workforce/roles":
      return "Cargos base";
    case "GET /workforce/event-roles":
      return "Cargos do evento";
    case "GET /workforce/managers":
      return "Lista de liderancas";
    case "GET /workforce/assignments":
      return "Lista da equipe";
    case "POST /workforce/assignments":
      return "Nova alocacao";
    case "DELETE /workforce/assignments/:id":
      return "Remocao de alocacao";
    case "POST /workforce/card-issuance/preview":
      return "Preview de emissao";
    case "POST /workforce/card-issuance/issue":
      return "Emissao de cartoes";
    case "GET /participants":
      return "Lista de participantes";
    case "POST /participants":
      return "Novo participante";
    case "DELETE /participants/:id":
      return "Exclusao de participante";
    case "POST /participants/backfill-qrs":
      return "Geracao de convites";
    case "POST /participants/bulk-delete":
      return "Exclusao em lote";
    case "POST /workforce/tree-backfill":
      return "Recomposicao de vinculos";
    case "POST /workforce/tree-sanitize":
      return "Correcao de estrutura";
    case "POST /sync":
      return "Sincronizacao offline";
    default:
      return endpoint || "Servico interno";
  }
};

export default function WorkforceOpsTab({ eventId }) {
  const { hasRole } = useAuth();
  const [managers, setManagers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [assignments, setAssignments] = useState([]);
  const [eventRoles, setEventRoles] = useState([]);
  const [treeStatus, setTreeStatus] = useState(null);
  const [participants, setParticipants] = useState([]);
  const [selectedManager, setSelectedManager] = useState(null);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedIds, setSelectedIds] = useState([]);
  const [loading, setLoading] = useState(true);
  const [loadedFromSnapshot, setLoadedFromSnapshot] = useState(false);
  const [snapshotWarning, setSnapshotWarning] = useState("");
  const [operationalHealth, setOperationalHealth] = useState(null);
  const [operationalHealthLoading, setOperationalHealthLoading] = useState(false);
  const [treeMaintenanceLoading, setTreeMaintenanceLoading] = useState("");
  const [overviewTab, setOverviewTab] = useState("operation");

  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isBulkModalOpen, setIsBulkModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isBulkSettingsModalOpen, setIsBulkSettingsModalOpen] = useState(false);
  const [isCardIssuanceModalOpen, setIsCardIssuanceModalOpen] = useState(false);
  const [cardIssuanceSelection, setCardIssuanceSelection] = useState([]);
  const [cardIssuanceContext, setCardIssuanceContext] = useState({
    managerEventRoleId: null,
    managerSector: "",
    label: "",
  });
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
  const [eventStructure, setEventStructure] = useState({
    days: 0,
    shifts: 0,
    registeredShifts: 0,
    shiftHours: 8
  });
  const [newManagerRoleName, setNewManagerRoleName] = useState("");
  const [savingNewManagerRole, setSavingNewManagerRole] = useState(false);
  const [pendingManagerRoleId, setPendingManagerRoleId] = useState(null);
  const [isCreatingRoleInline, setIsCreatingRoleInline] = useState(false);
  const [newRoleName, setNewRoleName] = useState("");
  const [savingNewRole, setSavingNewRole] = useState(false);

  const fetchManagers = async () => {
    setLoading(true);
    setOperationalHealthLoading(true);
    try {
      const [mgrRes, roleRes, assignmentRes, treeRes, healthRes] = await Promise.all([
        api.get(`/workforce/managers?event_id=${eventId}`),
        api.get(`/workforce/roles?event_id=${eventId}`),
        api.get(`/workforce/assignments?event_id=${eventId}`),
        api.get(`/workforce/tree-status?event_id=${eventId}`),
        api.get(`/health/workforce?event_id=${eventId}&window_minutes=60`).catch((error) => {
          console.error(error);
          return null;
        })
      ]);
      const nextManagers = mgrRes.data.data || [];
      const nextRoles = roleRes.data.data || [];
      const nextAssignments = assignmentRes.data.data || [];
      const nextTreeStatus = treeRes.data?.data || null;
      const nextOperationalHealth = healthRes?.data?.data || null;
      let nextEventRoles = [];

      if (nextTreeStatus?.migration_ready) {
        try {
          const eventRolesRes = await api.get(`/workforce/event-roles?event_id=${eventId}`);
          nextEventRoles = eventRolesRes.data?.data || [];
        } catch (eventRoleError) {
          console.error(eventRoleError);
          nextEventRoles = [];
        }
      }

      setManagers(nextManagers);
      setRoles(nextRoles);
      setAssignments(nextAssignments);
      setTreeStatus(nextTreeStatus);
      setEventRoles(nextEventRoles);
      setOperationalHealth(nextOperationalHealth);
      setLoadedFromSnapshot(false);

      const snapshotWrite = writeWorkforceSnapshot(eventId, {
        managers: nextManagers,
        roles: nextRoles,
        assignments: nextAssignments,
        tree_status: nextTreeStatus,
        event_roles: nextEventRoles
      });
      setSnapshotWarning(
        snapshotWrite.ok
          ? ""
          : "Nao foi possivel atualizar o snapshot local deste dispositivo. A operacao offline pode ficar desatualizada."
      );
      if (!snapshotWrite.ok) {
        reportOperationalTelemetry("workforce.snapshot.write_failed", {
          eventId,
          details: {
            message: snapshotWrite.error?.message || "snapshot_write_failed"
          }
        });
      }

      return {
        managers: nextManagers,
        roles: nextRoles,
        assignments: nextAssignments,
        treeStatus: nextTreeStatus,
        eventRoles: nextEventRoles
      };
    } catch (error) {
      console.error(error);
      const snapshotResult = readWorkforceSnapshot(eventId);
      const snapshot = snapshotResult.data;
      setSnapshotWarning(
        snapshotResult.error
          ? "O snapshot local deste evento esta indisponivel ou corrompido neste dispositivo."
          : ""
      );
      if (snapshotResult.error) {
        reportOperationalTelemetry("workforce.snapshot.read_failed", {
          eventId,
          details: {
            message: snapshotResult.error?.message || "snapshot_read_failed"
          }
        });
      }
      if (snapshot) {
        setManagers(snapshot.managers || []);
        setRoles(snapshot.roles || []);
        setAssignments(snapshot.assignments || []);
        setTreeStatus(snapshot.tree_status || null);
        setEventRoles(snapshot.event_roles || []);
        setOperationalHealth(null);
        setLoadedFromSnapshot(true);
        reportOperationalTelemetry("workforce.snapshot.fallback_used", {
          eventId,
          details: {
            reason: "network_error"
          }
        });
        toast("Falha na rede. Workforce carregado do snapshot local.");
        return {
          managers: snapshot.managers || [],
          roles: snapshot.roles || [],
          assignments: snapshot.assignments || [],
          treeStatus: snapshot.tree_status || null,
          eventRoles: snapshot.event_roles || []
        };
      }

      toast.error("Erro ao carregar gerentes e alocações.");
      setTreeStatus(null);
      setEventRoles([]);
      setOperationalHealth(null);
      return { managers: [], roles: [], assignments: [], treeStatus: null, eventRoles: [] };
    } finally {
      setLoading(false);
      setOperationalHealthLoading(false);
    }
  };

  const eventTreeActive = shouldPreferEventTree(treeStatus, eventRoles);
  const eventManagerRootsAvailable = hasEventManagerRoots(eventRoles);

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
      if (eventTreeActive && Number(manager?.root_event_role_id || manager?.event_role_id || 0) > 0) {
        params.set(
          "root_manager_event_role_id",
          String(Number(manager?.root_event_role_id || manager?.event_role_id || 0))
        );
      } else if (Number(manager?.user_id || 0) > 0) {
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
    const roleId = Number(row.role_id || 0);
    const eventRoleId = Number(row.event_role_id || row.id || 0) || null;
    const rootEventRoleId = Number(row.root_event_role_id || eventRoleId || 0) || null;
    return {
      id: roleId,
      name: row.role_name || row.name || "",
      sector: normalizeSector(row.sector || inferSectorFromRoleName(row.role_name || row.name || "")),
      event_role_id: eventRoleId,
      event_role_public_id: row.event_role_public_id || row.public_id || "",
      root_event_role_id: rootEventRoleId,
      root_public_id: row.root_public_id || row.event_role_public_id || row.public_id || "",
      parent_event_role_id: Number(row.parent_event_role_id || 0) || null,
      parent_public_id: row.parent_public_id || "",
      role_class: row.role_class || "",
      authority_level: row.authority_level || "",
      cost_bucket: row.cost_bucket || "operational"
    };
  };

  const loadManagerOperationalContext = async (manager = selectedManager) => {
    if (!manager?.role_id || !eventId) {
      setManagerRoleSettings(null);
      setEventStructure({ days: 0, shifts: 0, registeredShifts: 0, shiftHours: 8 });
      return;
    }

    setManagerRoleSettingsLoading(true);
    try {
      const roleParams = new URLSearchParams({
        event_id: String(eventId),
        sector: normalizeSector(manager?.sector || "")
      });
      if (Number(manager?.event_role_id || 0) > 0) {
        roleParams.set("event_role_id", String(Number(manager.event_role_id)));
      } else if (manager?.event_role_public_id) {
        roleParams.set("event_role_public_id", String(manager.event_role_public_id));
      }
      const [roleRes, daysRes, shiftsRes] = await Promise.all([
        api.get(`/workforce/role-settings/${manager.role_id}?${roleParams.toString()}`),
        api.get(`/event-days?event_id=${eventId}`),
        api.get(`/event-shifts?event_id=${eventId}`)
      ]);

      const nextRoleSettings = roleRes.data?.data || null;
      const nextShiftHours = Number(nextRoleSettings?.shift_hours ?? 8) || 8;
      const nextShifts = shiftsRes.data?.data || [];
      setManagerRoleSettings(nextRoleSettings);
      setEventStructure({
        days: (daysRes.data?.data || []).length,
        shifts: calculateOperationalShiftSlots(nextShifts, nextShiftHours),
        registeredShifts: nextShifts.length,
        shiftHours: nextShiftHours
      });
    } catch (error) {
      console.error(error);
      setManagerRoleSettings(null);
      setEventStructure({ days: 0, shifts: 0, registeredShifts: 0, shiftHours: 8 });
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
    setIsCardIssuanceModalOpen(false);
    setCardIssuanceSelection([]);
    setCardIssuanceContext({ managerEventRoleId: null, managerSector: "", label: "" });
    setOverviewTab("operation");
    syncManagersEffect();
  }, [eventId]);

  const managerRows = useMemo(
    () =>
      eventManagerRootsAvailable
        ? buildManagerRowsFromEventTree(eventRoles, assignments)
        : buildManagerRows(managers, roles, assignments),
    [assignments, eventManagerRootsAvailable, eventRoles, managers, roles]
  );

  const legacyManagersCount = useMemo(() => {
    const uniqueKeys = new Set();

    assignments.forEach((assignment) => {
      if (normalizeCostBucket(assignment?.cost_bucket, assignment?.role_name) !== "managerial") {
        return;
      }

      const hasFullStructuralBinding =
        Number(assignment?.event_role_id || 0) > 0 &&
        Number(assignment?.root_manager_event_role_id || 0) > 0;
      if (hasFullStructuralBinding) {
        return;
      }

      const participantId = Number(assignment?.participant_id || 0);
      const roleId = Number(assignment?.role_id || 0);
      const sector = normalizeSector(assignment?.sector || "") || "geral";
      uniqueKeys.add(
        participantId > 0 ? `participant-${participantId}` : `role-${roleId}-${sector}`
      );
    });

    return uniqueKeys.size;
  }, [assignments]);

  const selectedManagerStructureRows = useMemo(
    () => buildManagerStructureRows(eventRoles, assignments, selectedManager),
    [assignments, eventRoles, selectedManager]
  );

  const structureParticipantIds = useMemo(
    () =>
      new Set(
        selectedManagerStructureRows
          .map((row) => Number(row?.leader_participant_id || 0))
          .filter((value) => value > 0)
      ),
    [selectedManagerStructureRows]
  );

  useEffect(() => {
    if (!selectedManager) {
      setParticipants([]);
      setSelectedIds([]);
      return;
    }
    syncManagerMembersEffect(selectedManager);
  }, [selectedManager, selectedManager?.event_role_id, selectedManager?.user_id, selectedManager?.role_id, selectedManager?.sector, eventId, eventTreeActive]);

  useEffect(() => {
    if (!selectedManager) {
      setManagerRoleSettings(null);
      setEventStructure({ days: 0, shifts: 0, registeredShifts: 0, shiftHours: 8 });
      return;
    }
    syncManagerOperationalContextEffect(selectedManager);
  }, [selectedManager, selectedManager?.event_role_id, selectedManager?.role_id, selectedManager?.participant_id, eventId]);

  const refreshSelectedManagerView = async (manager = selectedManager) => {
    const overview = await fetchManagers();
    if (!manager) {
      return;
    }

    const nextRows = hasEventManagerRoots(overview.eventRoles)
      ? buildManagerRowsFromEventTree(overview.eventRoles, overview.assignments)
      : buildManagerRows(overview.managers, overview.roles, overview.assignments);
    const refreshedManager = findManagerRow(
      nextRows,
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
        if (!eventTreeActive) return true;
        return !structureParticipantIds.has(Number(participant?.participant_id || 0));
      })
      .filter((participant) => {
        const q = searchTerm.trim().toLowerCase();
        if (!q) return true;
        return (
          participant.name.toLowerCase().includes(q) ||
          participant.email.toLowerCase().includes(q) ||
          String(participant.phone || "").toLowerCase().includes(q)
        );
      });
  }, [eventTreeActive, participants, searchTerm, selectedManager, structureParticipantIds]);

  const selectedParticipants = teamMembers.filter((p) => selectedIds.includes(p.participant_id));
  const selectedManagerRole = buildRoleContext(selectedManager);
  const selectedManagerSector = normalizeSector(selectedManager?.sector || selectedManagerRole?.sector || "");
  const hasDirectManagerBinding = Boolean(selectedManager?.user_id);
  const canOperateSelectedManager = Boolean(selectedManagerRole?.id || selectedManagerSector);
  const canIssueSelectedParticipants = Number(eventId || 0) > 0 && selectedParticipants.length > 0;
  const selectedManagerPlannedTeamSize = Number(selectedManager?.planned_team_size || selectedManager?.team_size || teamMembers.length || 0);
  const selectedManagerFilledTeamSize = Number(selectedManager?.filled_team_size || teamMembers.length || 0);
  const selectedManagerLeadershipTotal = Number(selectedManager?.leadership_positions_total || 0);
  const selectedManagerLeadershipFilledTotal = Number(selectedManager?.leadership_filled_total || 0);
  const selectedManagerOperationalTotal = Number(selectedManager?.operational_members_total || teamMembers.length || 0);
  const workforceBulkCardIssuanceEnabled = WORKFORCE_BULK_CARD_FLAG_VALUES.has(
    String(import.meta.env.VITE_FEATURE_WORKFORCE_BULK_CARD_ISSUANCE || "").trim().toLowerCase()
  );
  const canIssueWorkforceCards =
    workforceBulkCardIssuanceEnabled && (hasRole?.("admin") || hasRole?.("organizer"));
  const managerRoleMembersCount =
    Number(selectedManager?.event_role_id || 0) > 0 ||
    Number(selectedManager?.leadership_filled_total || 0) > 0
      ? 1
      : 0;
  const treeBlockers = Array.isArray(treeStatus?.activation_blockers) ? treeStatus.activation_blockers : [];
  const canAccessAdvancedTab = hasRole?.("admin");
  const canRunTreeMaintenance = hasRole?.("admin");
  const activeOverviewTab = canAccessAdvancedTab ? overviewTab : "operation";
  const operationalHealthStatus = operationalStatusMeta(operationalHealth?.status);
  const endpointAlerts = useMemo(
    () =>
      Array.isArray(operationalHealth?.endpoints)
        ? operationalHealth.endpoints.filter((row) => normalizeOperationalStatus(row?.status) === "warning" || normalizeOperationalStatus(row?.status) === "degraded")
        : [],
    [operationalHealth]
  );
  const syncHealth = useMemo(
    () =>
      (Array.isArray(operationalHealth?.endpoints)
        ? operationalHealth.endpoints.find((row) => row.endpoint === "POST /sync")
        : null) || null,
    [operationalHealth]
  );
  const observedCriticalEndpoints = Number(operationalHealth?.summary?.critical_endpoints_observed || 0);
  const snapshotInvalidCount = Number(operationalHealth?.summary?.snapshot_invalid_count || 0);
  const snapshotFallbackUsed = Number(operationalHealth?.summary?.snapshot_fallback_used || 0);
  const syncFailureRate = Number(operationalHealth?.summary?.sync_failure_rate_pct || 0);

  const handleEnterManager = (manager) => {
    setSelectedManager(manager);
    setSearchTerm("");
    setSelectedIds([]);
    setIsCardIssuanceModalOpen(false);
    setCardIssuanceSelection([]);
    setCardIssuanceContext({ managerEventRoleId: null, managerSector: "", label: "" });
    setIsCreatingRoleInline(false);
    setNewRoleName("");
  };

  const openCardIssuanceModal = ({
    participants = [],
    managerEventRoleId = null,
    managerSector = "",
    label = "",
  } = {}) => {
    const normalizedParticipants = Array.from(
      new Map(
        (Array.isArray(participants) ? participants : [])
          .map((participant) => {
            const participantId = Number(participant?.participant_id || participant?.leader_participant_id || 0);
            if (participantId <= 0) {
              return null;
            }

            return [
              participantId,
              {
                ...participant,
                participant_id: participantId,
                name:
                  participant?.name ||
                  participant?.person_name ||
                  participant?.leader_participant_name ||
                  participant?.leader_name ||
                  participant?.linked_leader_name ||
                  participant?.role_name ||
                  "",
                person_name:
                  participant?.person_name ||
                  participant?.name ||
                  participant?.leader_participant_name ||
                  participant?.leader_name ||
                  participant?.linked_leader_name ||
                  participant?.role_name ||
                  "",
                sector: participant?.sector || managerSector || "",
                event_role_id:
                  Number(participant?.event_role_id || participant?.manager_event_role_id || managerEventRoleId || 0) || null,
              },
            ];
          })
          .filter(Boolean)
      ).values()
    );

    if (normalizedParticipants.length === 0) {
      toast.error("Vincule um participante ao cargo antes de emitir cartoes.");
      return;
    }

    setCardIssuanceSelection(normalizedParticipants);
    setCardIssuanceContext({
      managerEventRoleId: Number(managerEventRoleId || 0) || null,
      managerSector: managerSector || "",
      label: String(label || "").trim(),
    });
    setIsCardIssuanceModalOpen(true);
  };

  const openRoleSettingsForRow = (row) => {
    const roleContext = buildRoleContext(row);
    if (!roleContext?.id) {
      toast.error("Cargo estrutural inválido para configuração.");
      return;
    }

    setPendingManagerRoleId(null);
    setRoleSettingsRole(roleContext);
    setIsRoleSettingsModalOpen(true);
  };

  const openSectorCostsForRow = (row) => {
    const roleContext = buildRoleContext(row);
    if (!roleContext?.id) {
      toast.error("Cargo estrutural inválido para custos.");
      return;
    }

    setSectorCostsRole(roleContext);
    setIsSectorCostsModalOpen(true);
  };

  const handleWorkforceImported = async () => {
    await refreshSelectedManagerView(selectedManager);
  };

  const handleRefreshOperationalOverview = async () => {
    if (selectedManager) {
      await refreshSelectedManagerView(selectedManager);
      return;
    }
    await fetchManagers();
  };

  const handleCopyOperationalDiagnostic = async () => {
    const payload = {
      event_id: eventId,
      generated_at: new Date().toISOString(),
      loaded_from_snapshot: loadedFromSnapshot,
      snapshot_warning: snapshotWarning || null,
      tree_status: treeStatus,
      health_summary: operationalHealth?.summary || null,
      health_status: operationalHealth?.status || null,
      blockers: treeBlockers,
    };

    try {
      await navigator.clipboard.writeText(JSON.stringify(payload, null, 2));
      toast.success("Diagnóstico do Workforce copiado.");
    } catch {
      toast.error("Falha ao copiar diagnóstico.");
    }
  };

  const handleRunTreeMaintenance = async (mode) => {
    if (!eventId) {
      toast.error("Selecione um evento antes de executar manutenção.");
      return;
    }

    if (!canRunTreeMaintenance) {
      toast.error("Somente admin/organizer podem executar esta manutenção.");
      return;
    }

    if (mode === "backfill" && !treeStatus?.assignment_bindings_ready) {
      toast.error("O ambiente ainda não está pronto para backfill da árvore.");
      return;
    }

    if (mode === "backfill" && !treeStatus?.migration_ready) {
      toast.error("A migração estrutural do evento ainda não está pronta para backfill.");
      return;
    }

    if (mode === "sanitize" && !treeStatus?.migration_ready) {
      toast.error("A migração estrutural do evento ainda não está pronta para saneamento.");
      return;
    }

    const actionLabel = mode === "backfill" ? "backfill da árvore" : "saneamento da árvore";
    const blockersLabel = treeBlockers.length > 0 ? `\n\nBloqueios atuais: ${treeBlockers.join(", ")}` : "";
    const confirmed = window.confirm(
      `Executar ${actionLabel} no evento ${eventId}? Esta ação atua no Workforce estrutural e deve ser usada apenas em incidente ou saneamento controlado.${blockersLabel}`
    );
    if (!confirmed) return;

    setTreeMaintenanceLoading(mode);
    toast.loading(`Executando ${actionLabel}...`, { id: "workforce-maintenance" });
    try {
      const endpoint = mode === "backfill" ? "/workforce/tree-backfill" : "/workforce/tree-sanitize";
      await api.post(endpoint, { event_id: eventId });
      await handleRefreshOperationalOverview();
      toast.success(`${actionLabel} executado com sucesso.`, { id: "workforce-maintenance" });
    } catch (error) {
      toast.error(error.response?.data?.message || `Erro ao executar ${actionLabel}.`, {
        id: "workforce-maintenance",
      });
    } finally {
      setTreeMaintenanceLoading("");
    }
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

  const handleDeleteStructureRow = async (row) => {
    const targetId = row?.event_role_public_id || row?.event_role_id || row?.id;
    if (!targetId) {
      toast.error("Linha estrutural inválida.");
      return;
    }
    if (!window.confirm(`Excluir a linha estrutural "${row.role_name || row.name || "cargo"}"?`)) return;

    try {
      await api.delete(`/workforce/event-roles/${targetId}`);
      toast.success("Linha estrutural removida.");
      await refreshSelectedManagerView(selectedManager);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao excluir linha estrutural.");
    }
  };

  const handleDeleteManagerRow = async (manager) => {
    const roleContext = buildRoleContext(manager);
    const targetId = roleContext?.event_role_public_id || roleContext?.event_role_id;
    if (!targetId) {
      toast.error("Este gerente ainda não possui root estrutural para exclusão direta.");
      return;
    }

    const managerName = manager?.person_name || manager?.role_name || "gerente";
    const roleName = manager?.role_name || "cargo gerencial";
    const confirmed = window.confirm(
      `Excluir a linha raiz "${roleName}" de ${managerName}? Se houver filhos ou equipe vinculada, o sistema bloqueará a exclusão.`
    );
    if (!confirmed) return;

    try {
      await api.delete(`/workforce/event-roles/${targetId}`);
      toast.success("Linha raiz do gerente removida.");
      if (selectedManager && findManagerRow([manager], selectedManager)) {
        setSelectedManager(null);
      }
      await fetchManagers();
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao excluir linha raiz do gerente.");
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

    const inferredCostBucket = normalizeCostBucket("", safeName);
    const inferredRoleClass = inferRoleClassFromRoleName(safeName, inferredCostBucket);

    setSavingNewRole(true);
    try {
      const res = await api.post("/workforce/roles", {
        name: safeName,
        sector: selectedManagerSector || undefined,
        event_id: eventId || undefined,
        create_event_role: true,
        parent_event_role_id: Number(selectedManager?.event_role_id || 0) || undefined,
        parent_public_id: selectedManager?.event_role_public_id || undefined,
        root_event_role_id: Number(selectedManager?.root_event_role_id || selectedManager?.event_role_id || 0) || undefined,
        root_public_id: selectedManager?.root_public_id || selectedManager?.event_role_public_id || undefined,
        cost_bucket: inferredCostBucket,
        role_class: inferredRoleClass
      });
      const createdRole = res.data?.data || {};
      const roleContext = {
        id: Number(createdRole.id || 0),
        name: createdRole.name || safeName,
        sector: createdRole.sector || selectedManagerSector || "",
        event_role_id: Number(createdRole.event_role_id || 0) || null,
        event_role_public_id: createdRole.event_role_public_id || "",
        parent_event_role_id:
          Number(createdRole.parent_event_role_id || selectedManager?.event_role_id || 0) || null,
        parent_public_id: selectedManager?.event_role_public_id || "",
        root_event_role_id:
          Number(createdRole.root_event_role_id || selectedManager?.root_event_role_id || selectedManager?.event_role_id || 0) || null,
        root_public_id:
          createdRole.root_public_id || selectedManager?.root_public_id || selectedManager?.event_role_public_id || "",
        role_class: createdRole.role_class || inferredRoleClass,
        cost_bucket: createdRole.cost_bucket || inferredCostBucket,
        authority_level: createdRole.authority_level || "none"
      };

      toast.success("Cargo criado e registrado automaticamente na tabela do gerente.");
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
        sector: inferredSector || undefined,
        event_id: eventId || undefined,
        cost_bucket: "managerial",
        role_class: "manager",
        authority_level: "table_manager",
        is_placeholder: true
      });

      const createdPayload = res.data?.data || {};
      const createdRoleId = Number(createdPayload.id || 0);
      const createdRole = {
        id: createdRoleId,
        name: createdPayload.name || safeName,
        sector: createdPayload.sector || inferredSector,
        role_class: createdPayload.role_class || "manager",
        cost_bucket: createdPayload.cost_bucket || "managerial",
        authority_level: createdPayload.authority_level || "table_manager",
        event_role_id: Number(createdPayload.event_role_id || 0) || null,
        event_role_public_id: createdPayload.event_role_public_id || "",
        root_event_role_id: Number(createdPayload.root_event_role_id || createdPayload.event_role_id || 0) || null,
        root_public_id: createdPayload.root_public_id || createdPayload.event_role_public_id || "",
        is_placeholder: Boolean(createdPayload.is_placeholder)
      };

      toast.success("Cargo gerencial criado e registrado automaticamente na tabela do evento.");
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
    const nextRows = hasEventManagerRoots(overview.eventRoles)
      ? buildManagerRowsFromEventTree(overview.eventRoles, overview.assignments)
      : buildManagerRows(overview.managers, overview.roles, overview.assignments);
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
              placeholder="Novo cargo de liderança (ex: Gerente de Bar)"
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
              <p className="font-semibold text-white">Crie o cargo da liderança, configure nome/CPF/telefone e depois entre na tabela para importar a equipe.</p>
              <p className="mt-1">
                Setor inferido agora:{" "}
                <span className="text-brand">{inferSectorFromRoleName(newManagerRoleName) || "sera definido pelo nome do cargo"}</span>
              </p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-950/50 px-3 py-2 uppercase tracking-wider">
              {managerRows.length === 0
                ? "Nenhum gerente configurado"
                : `${managerRows.length} liderança(s) cadastrada(s)`}
            </div>
          </div>
        </div>

        {canAccessAdvancedTab ? (
          <div className="grid gap-8 xl:grid-cols-2 xl:items-stretch">
            <button
              type="button"
              onClick={() => setOverviewTab("operation")}
              className={`h-full rounded-2xl border px-4 py-4 text-left transition ${
                activeOverviewTab === "operation"
                  ? "border-brand/50 bg-brand/10 text-white"
                  : "border-gray-800 bg-gray-950/50 text-gray-400 hover:bg-gray-900/60"
              }`}
            >
              <p className="text-[10px] uppercase tracking-[0.18em]">Operacao</p>
              <p className="mt-1 text-sm font-semibold">Montagem da equipe</p>
            </button>
            <button
              type="button"
              onClick={() => setOverviewTab("advanced")}
              className={`h-full rounded-2xl border px-4 py-4 text-left transition ${
                activeOverviewTab === "advanced"
                  ? "border-brand/50 bg-brand/10 text-white"
                  : "border-gray-800 bg-gray-950/50 text-gray-400 hover:bg-gray-900/60"
              }`}
            >
              <div className="flex items-center justify-between gap-3">
                <p className="text-[10px] uppercase tracking-[0.18em]">Avancado</p>
                <span className={`rounded-lg border px-2 py-1 text-[10px] uppercase tracking-wider ${operationalHealthStatus.badgeClass}`}>
                  {operationalHealthStatus.label}
                </span>
              </div>
              <p className="mt-1 text-sm font-semibold">Diagnostico e manutencao</p>
            </button>
          </div>
        ) : null}

        <WorkforceAIAssistant
          eventId={eventId}
          assignmentsTotal={Number(treeStatus?.assignments_total || assignments.length || 0)}
          missingBindings={Number(treeStatus?.assignments_missing_bindings || 0)}
          managerRootsCount={Number(treeStatus?.manager_roots_count || managerRows.length || 0)}
          selectedManagerName={selectedManager?.person_name || selectedManager?.name || ""}
          selectedManagerRoleName={selectedManager?.role_name || ""}
          selectedManagerEventRoleId={selectedManager?.event_role_id || null}
          selectedManagerRootEventRoleId={
            selectedManager?.root_event_role_id || selectedManager?.event_role_id || null
          }
          selectedManagerRoleClass={selectedManager?.role_class || ""}
          selectedManagerSector={selectedManagerSector}
          selectedManagerPlannedTeamSize={selectedManagerPlannedTeamSize}
          selectedManagerFilledTeamSize={selectedManagerFilledTeamSize}
          selectedManagerLeadershipTotal={selectedManagerLeadershipTotal}
          selectedManagerLeadershipFilledTotal={selectedManagerLeadershipFilledTotal}
          selectedManagerOperationalTotal={selectedManagerOperationalTotal}
          selectedTeamMembersLoaded={teamMembers.length}
          eventStructure={eventStructure}
          treeUsable={Boolean(treeStatus?.tree_usable)}
          loadedFromSnapshot={loadedFromSnapshot}
          healthStatusLabel={operationalHealthStatus.label}
          syncFailureRate={syncFailureRate}
        />

        {activeOverviewTab === "operation" && (
          <div className="card p-4 border border-gray-800 bg-gray-900/40">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Resumo da equipe</p>
                <p className="mt-1 text-sm text-gray-400">
                  Veja quantas liderancas e pessoas ja estao ligadas corretamente na estrutura deste evento.
                </p>
              </div>
              {canAccessAdvancedTab && (
                <span className="rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2 text-[10px] uppercase tracking-wider text-gray-400">
                  Diagnostico completo no card avancado
                </span>
              )}
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Liderancas cadastradas</p>
                <p className="mt-2 text-lg font-black text-white">{Number(treeStatus?.manager_roots_count || 0).toLocaleString("pt-BR")}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Coordenacoes e supervisoes</p>
                <p className="mt-2 text-lg font-black text-white">{Number(treeStatus?.managerial_child_roles_count || 0).toLocaleString("pt-BR")}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Pessoas ligadas a lideranca</p>
                <p className="mt-2 text-lg font-black text-white">{Number(treeStatus?.assignments_with_root_manager || 0).toLocaleString("pt-BR")}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Pessoas sem lideranca</p>
                <p className="mt-2 text-lg font-black text-white">{Number(treeStatus?.assignments_missing_bindings || 0).toLocaleString("pt-BR")}</p>
              </div>
            </div>

            {loadedFromSnapshot && (
              <p className="mt-3 text-xs text-amber-400 uppercase tracking-wider">
                Painel exibido com dados salvos neste dispositivo. Confirme a sincronizacao antes de fazer alteracoes.
              </p>
            )}

            {snapshotWarning && (
              <p className="mt-3 text-xs text-rose-400">
                {snapshotWarning}
              </p>
            )}

            {Number(treeStatus?.assignments_missing_bindings || 0) > 0 && (
              <p className="mt-3 text-xs text-amber-300">
                Ainda existem pessoas sem lideranca definida. Revise os vinculos antes de fechar a montagem da equipe.
              </p>
            )}
          </div>
        )}

        {activeOverviewTab === "advanced" && (
          <div className="space-y-4">
            <div className={`card p-4 border ${operationalHealthStatus.cardClass}`}>
              <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Avancado</p>
                  <p className="mt-1 text-sm text-gray-400">
                    Diagnostico e manutencao do modulo. Use quando houver divergencia, falha de sincronizacao ou ajuste estrutural.
                  </p>
                </div>
                <span className={`rounded-xl border px-3 py-2 text-[10px] uppercase tracking-wider ${operationalHealthStatus.badgeClass}`}>
                  {operationalHealthStatus.label}
                </span>
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">Base usada na tela</p>
                  <p className="mt-2 text-sm font-semibold text-white">{formatOperationalSourceLabel(treeStatus?.source_preference)}</p>
                </div>
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">Cadastros antigos ainda ativos</p>
                  <p className="mt-2 text-lg font-black text-white">{Number(legacyManagersCount).toLocaleString("pt-BR")}</p>
                </div>
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">Estrutura pronta para uso</p>
                  <p className="mt-2 text-sm font-semibold text-white">{treeStatus?.tree_ready ? "Sim" : "Parcial"}</p>
                </div>
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">Dados salvos neste dispositivo</p>
                  <p className="mt-2 text-sm font-semibold text-white">{loadedFromSnapshot ? "Em uso" : "Atualizados"}</p>
                </div>
              </div>

              {loadedFromSnapshot && (
                <p className="mt-3 text-xs text-amber-400 uppercase tracking-wider">
                  A tela esta usando dados salvos neste dispositivo. Confirme a sincronizacao antes de alterar a estrutura.
                </p>
              )}

              {snapshotWarning && (
                <p className="mt-3 text-xs text-rose-400">
                  {snapshotWarning}
                </p>
              )}

              {!treeStatus?.migration_ready && (
                <p className="mt-3 text-xs text-amber-400">
                  A transicao para a nova estrutura ainda nao terminou neste ambiente. Parte da leitura continua apoiada no cadastro antigo.
                </p>
              )}

              {treeBlockers.length > 0 && (
                <p className="mt-3 text-xs text-gray-400">
                  Pendencias atuais: {treeBlockers.map((item) => item.replace(/_/g, " ")).join(", ")}.
                </p>
              )}
            </div>

            <div className={`card p-4 border border-gray-800 bg-gray-900/40 ${operationalHealthStatus.cardClass}`}>
              <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Situacao do sistema</p>
                  <p className="mt-1 text-sm text-gray-400">
                    Resumo interno da tabela, da sincronizacao offline e dos dados salvos neste dispositivo.
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={handleCopyOperationalDiagnostic}
                    className="btn-secondary h-9 px-3 text-xs flex items-center gap-2"
                    disabled={loading || operationalHealthLoading}
                    title="Copiar o diagnostico atual da estrutura e da saude do modulo."
                  >
                    <Copy size={13} />
                    Copiar diagnostico
                  </button>
                  <button
                    type="button"
                    onClick={handleRefreshOperationalOverview}
                    className="btn-secondary h-9 px-3 text-xs flex items-center gap-2"
                    disabled={loading || operationalHealthLoading || Boolean(treeMaintenanceLoading)}
                    title="Atualizar a leitura da estrutura e da saude do evento."
                  >
                    <RefreshCw size={13} className={loading || operationalHealthLoading ? "animate-spin" : ""} />
                    Atualizar leitura
                  </button>
                  {canRunTreeMaintenance && (
                    <button
                      type="button"
                      onClick={() => handleRunTreeMaintenance("backfill")}
                      className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-cyan-700/50 text-cyan-200 hover:bg-cyan-900/20"
                      disabled={loading || operationalHealthLoading || Boolean(treeMaintenanceLoading) || !treeStatus?.migration_ready || !treeStatus?.assignment_bindings_ready}
                      title={!treeStatus?.assignment_bindings_ready ? "O ambiente ainda nao permite recompor os vinculos estruturais." : "Recompor vinculos estruturais do evento."}
                    >
                      <RefreshCw size={13} className={treeMaintenanceLoading === "backfill" ? "animate-spin" : ""} />
                      Recompor vinculos
                    </button>
                  )}
                  {canRunTreeMaintenance && (
                    <button
                      type="button"
                      onClick={() => handleRunTreeMaintenance("sanitize")}
                      className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-amber-700/50 text-amber-200 hover:bg-amber-900/20"
                      disabled={loading || operationalHealthLoading || Boolean(treeMaintenanceLoading) || !treeStatus?.migration_ready}
                      title="Corrigir inconsistencias de lideranca e estrutura do evento."
                    >
                      <ShieldAlert size={13} className={treeMaintenanceLoading === "sanitize" ? "animate-pulse" : ""} />
                      Corrigir estrutura
                    </button>
                  )}
                </div>
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <div className="flex items-center gap-2 text-gray-500">
                    <Activity size={14} />
                    <p className="text-[10px] uppercase tracking-wider">Servicos acompanhados</p>
                  </div>
                  <p className="mt-2 text-lg font-black text-white">
                    {observedCriticalEndpoints.toLocaleString("pt-BR")}
                    <span className="ml-2 text-xs font-medium text-gray-500">/ {Number(operationalHealth?.summary?.critical_endpoints_total || 0).toLocaleString("pt-BR")}</span>
                  </p>
                </div>
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <div className="flex items-center gap-2 text-gray-500">
                    <ShieldAlert size={14} />
                    <p className="text-[10px] uppercase tracking-wider">Envio offline</p>
                  </div>
                  <p className={`mt-2 text-lg font-black ${syncFailureRate > 0 ? "text-rose-300" : "text-white"}`}>
                    {syncFailureRate.toLocaleString("pt-BR")}%
                  </p>
                  <p className="mt-1 text-[11px] text-gray-500">
                    {Number(operationalHealth?.summary?.sync_failed_requests || 0).toLocaleString("pt-BR")} falha(s) em{" "}
                    {Number(operationalHealth?.summary?.sync_requests_total || 0).toLocaleString("pt-BR")} envio(s)
                  </p>
                </div>
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <div className="flex items-center gap-2 text-gray-500">
                    <Clock3 size={14} />
                    <p className="text-[10px] uppercase tracking-wider">Falhas no cache local</p>
                  </div>
                  <p className={`mt-2 text-lg font-black ${snapshotInvalidCount > 0 ? "text-rose-300" : "text-white"}`}>
                    {snapshotInvalidCount.toLocaleString("pt-BR")}
                  </p>
                  <p className="mt-1 text-[11px] text-gray-500">
                    Dados salvos usados {snapshotFallbackUsed.toLocaleString("pt-BR")} vez(es) no periodo.
                  </p>
                </div>
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">Periodo analisado</p>
                  <p className="mt-2 text-lg font-black text-white">
                    {Number(operationalHealth?.window_minutes || 60).toLocaleString("pt-BR")} min
                  </p>
                  <p className="mt-1 text-[11px] text-gray-500">
                    Atualizado em {operationalHealth?.generated_at ? new Date(operationalHealth.generated_at).toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" }) : "--:--"}
                  </p>
                </div>
              </div>

              <div className="mt-4 grid gap-3 xl:grid-cols-[1.15fr,0.85fr]">
                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="text-[10px] uppercase tracking-wider text-gray-500">Servicos com alerta</p>
                      <p className="mt-1 text-xs text-gray-500">
                        Aqui aparecem apenas leituras com alerta ou falha dentro do periodo analisado.
                      </p>
                    </div>
                    <span className="rounded-lg border border-gray-800 bg-gray-900/70 px-2 py-1 text-[10px] uppercase tracking-wider text-gray-400">
                      {endpointAlerts.length} alerta(s)
                    </span>
                  </div>

                  {endpointAlerts.length === 0 ? (
                    <p className="mt-4 text-sm text-emerald-300">
                      Nenhum servico com alerta no periodo analisado.
                    </p>
                  ) : (
                    <div className="mt-4 space-y-2">
                      {endpointAlerts.slice(0, 5).map((row) => {
                        const rowStatus = operationalStatusMeta(row.status);
                        return (
                          <div key={row.endpoint} className="rounded-xl border border-gray-800 bg-gray-900/60 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              <div>
                                <p className="text-sm font-semibold text-white">{formatOperationalEndpointLabel(row.endpoint)}</p>
                                <p className="mt-1 text-[11px] font-mono text-gray-500">{row.endpoint}</p>
                              </div>
                              <span className={`rounded-lg border px-2 py-1 text-[10px] uppercase tracking-wider ${rowStatus.badgeClass}`}>
                                {rowStatus.label}
                              </span>
                            </div>
                            <p className="mt-2 text-xs text-gray-400">
                              Falhas: {Number(row.failure_rate_pct || 0).toLocaleString("pt-BR")}% • Lentidao p95:{" "}
                              {Number(row.p95_latency_ms || 0).toLocaleString("pt-BR")}ms • Leituras:{" "}
                              {Number(row.total_requests || 0).toLocaleString("pt-BR")}
                            </p>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>

                <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                  <p className="text-[10px] uppercase tracking-wider text-gray-500">Resumo rapido</p>
                  <div className="mt-4 space-y-3 text-sm">
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-gray-400">Envio offline</span>
                      <span className={syncFailureRate > 0 ? "font-semibold text-rose-300" : "font-semibold text-emerald-300"}>
                        {syncHealth ? `${Number(syncHealth.failed_requests || 0).toLocaleString("pt-BR")} falha(s)` : "Sem leitura"}
                      </span>
                    </div>
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-gray-400">Cache local com falha</span>
                      <span className={snapshotInvalidCount > 0 ? "font-semibold text-rose-300" : "font-semibold text-emerald-300"}>
                        {snapshotInvalidCount.toLocaleString("pt-BR")}
                      </span>
                    </div>
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-gray-400">Uso de dados salvos</span>
                      <span className={snapshotFallbackUsed > 0 ? "font-semibold text-amber-300" : "font-semibold text-emerald-300"}>
                        {snapshotFallbackUsed.toLocaleString("pt-BR")}
                      </span>
                    </div>
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-gray-400">Procedimento</span>
                      <span className="font-mono text-xs text-white">docs/qa/workforce_incident_playbook.md</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeOverviewTab === "operation" && (
          <div className="card overflow-hidden p-0 border border-gray-800">
          <table className="w-full text-left text-sm text-gray-300">
            <thead className="bg-gray-900/80 text-gray-500 uppercase text-[10px] tracking-wider border-b border-gray-800">
              <tr>
                <th className="px-5 py-4">Gerente / Lider</th>
                <th className="px-5 py-4">Cargo / Setor</th>
                <th className="px-5 py-4">Total do Setor</th>
                <th className="px-5 py-4 text-right">Acoes</th>
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
                     <td className="px-5 py-4 text-gray-300">
                        <div className="font-medium text-white whitespace-nowrap">
                          <Users size={14} className="inline mr-2 text-gray-500" />
                          {Number(mgr.planned_team_size || mgr.team_size || 0).toLocaleString("pt-BR")} posição(ões)
                        </div>
                        <p className="mt-1 text-xs text-gray-500">
                          Preenchido: {Number(mgr.filled_team_size || 0).toLocaleString("pt-BR")} • Liderança:{" "}
                          {Number(mgr.leadership_positions_total || 0).toLocaleString("pt-BR")} • Operação:{" "}
                          {Number(mgr.operational_members_total || 0).toLocaleString("pt-BR")}
                        </p>
                        <div className="mt-2">
                          {mgr.qr_token ? (
                            <div className="flex flex-wrap gap-2">
                              <button
                                type="button"
                                onClick={() => handleCopyLink(mgr.qr_token)}
                                className="p-1 px-2 text-[10px] bg-gray-800 border border-gray-700 rounded-lg inline-flex items-center gap-1"
                              >
                                <Copy size={11} /> Copiar link
                              </button>
                              <a
                                href={`${window.location.origin}/invite?token=${mgr.qr_token}`}
                                target="_blank"
                                rel="noreferrer"
                                className="p-1 px-2 text-[10px] text-brand-light border border-brand/30 rounded-lg inline-flex items-center gap-1"
                              >
                                <QrCode size={11} /> QR
                              </a>
                            </div>
                          ) : (
                            <p className="text-[10px] uppercase tracking-wider text-amber-400">
                              QR nominal indisponível até vincular participante ao cargo.
                            </p>
                          )}
                        </div>
                     </td>
                    <td className="px-5 py-4">
                        <div className="flex justify-end gap-2">
                        <button
                          type="button"
                          onClick={() => openRoleSettingsForRow(mgr)}
                          className="btn-secondary h-9 px-3 text-xs flex items-center gap-2"
                          disabled={!Number(mgr.role_id || 0)}
                        >
                          <Pencil size={13} /> Editar cargo
                        </button>
                        <button
                          type="button"
                          onClick={() => openSectorCostsForRow(mgr)}
                          className="btn-secondary h-9 px-3 text-xs flex items-center gap-2"
                          disabled={!Number(mgr.role_id || 0)}
                        >
                          <Briefcase size={13} /> Custos
                        </button>
                        <button
                          type="button"
                          onClick={() => handleEnterManager(mgr)}
                          className="btn-secondary h-9 px-3 font-semibold border-brand/50 text-brand-light hover:bg-brand/10"
                        >
                          Abrir equipe
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDeleteManagerRow(mgr)}
                          className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-red-700/60 text-red-400 hover:bg-red-900/30"
                          disabled={!Number(mgr.event_role_id || 0)}
                          title={
                            Number(mgr.event_role_id || 0) > 0
                              ? "Excluir a linha raiz do gerente"
                              : "Sem root estrutural disponível para exclusão direta"
                          }
                        >
                          <Trash2 size={13} /> Excluir
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
          </div>
        )}

        <WorkforceRoleSettingsModal
          isOpen={isRoleSettingsModalOpen}
          role={roleSettingsRole}
          eventId={eventId}
          availableRoles={roles}
          roleMembersCount={0}
          onClose={() => {
            setIsRoleSettingsModalOpen(false);
            setRoleSettingsRole(null);
            setPendingManagerRoleId(null);
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
                  openRoleSettingsForRow(selectedManager);
                }}
                className="btn-secondary h-10 px-4 text-xs flex items-center gap-2"
                disabled={!selectedManagerRole?.id}
              >
                <Settings2 size={14} /> Configurar Cargo
              </button>
              <button
                onClick={() => {
                  openSectorCostsForRow(selectedManager);
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
          <div className="grid grid-cols-2 gap-3">
            <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
              <p className="text-[10px] uppercase tracking-wider text-gray-500">Total planejado</p>
              <p className="mt-2 text-lg font-black text-white">{selectedManagerPlannedTeamSize.toLocaleString("pt-BR")}</p>
            </div>
            <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
              <p className="text-[10px] uppercase tracking-wider text-gray-500">Preenchido</p>
              <p className="mt-2 text-lg font-black text-white">{selectedManagerFilledTeamSize.toLocaleString("pt-BR")}</p>
            </div>
            <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
              <p className="text-[10px] uppercase tracking-wider text-gray-500">Liderança</p>
              <p className="mt-2 text-lg font-black text-white">{selectedManagerLeadershipTotal.toLocaleString("pt-BR")}</p>
              <p className="mt-1 text-[11px] text-gray-500">
                {selectedManagerLeadershipFilledTotal.toLocaleString("pt-BR")} preenchida(s)
              </p>
            </div>
            <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
              <p className="text-[10px] uppercase tracking-wider text-gray-500">Operação</p>
              <p className="mt-2 text-lg font-black text-white">{selectedManagerOperationalTotal.toLocaleString("pt-BR")}</p>
            </div>
          </div>

          <div>
            <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Estrutura operacional</p>
            <div className="mt-3 grid grid-cols-2 gap-3">
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Dias do evento</p>
                <p className="mt-2 text-lg font-black text-white">{eventStructure.days}</p>
              </div>
              <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Turnos operacionais</p>
                <p className="mt-2 text-lg font-black text-white">{eventStructure.shifts}</p>
                <p className="mt-1 text-[11px] text-gray-500">
                  {eventStructure.registeredShifts} janela(s) cadastrada(s) com base de {eventStructure.shiftHours}h por turno.
                </p>
              </div>
            </div>
            <p className="mt-3 text-xs text-gray-500">
              A alocacao manual do gerente continua permitindo escolher dia e turno do evento para cada membro.
            </p>
          </div>

          <div className="rounded-2xl border border-gray-800 bg-gray-950/50 p-3">
            <div className="flex items-center justify-between gap-3">
              <div>
                <p className="text-[10px] uppercase tracking-wider text-gray-500">Criar cargo do setor</p>
                <p className="mt-1 text-xs text-gray-500">Novo cargo no setor atual do gerente, incluindo coordenação ou supervisão.</p>
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

      <div className="card border border-gray-800 bg-gray-900/40 p-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
              <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Situação atual</p>
              <p className="mt-1 text-sm text-gray-400">
                Esta tabela usa {eventTreeActive ? "os cargos configurados neste evento" : "o cadastro antigo"} como fonte principal.
              </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2 text-[10px] uppercase tracking-wider text-gray-400">
              Gerentes: {Number(treeStatus?.manager_roots_count || 0).toLocaleString("pt-BR")}
            </span>
            <span className="rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2 text-[10px] uppercase tracking-wider text-gray-400">
              Sem gerente: {Number(treeStatus?.assignments_missing_bindings || 0).toLocaleString("pt-BR")}
            </span>
          </div>
        </div>

        {loadedFromSnapshot && (
          <p className="mt-3 text-xs text-amber-400 uppercase tracking-wider">
            Painel exibido com dados salvos neste dispositivo para operação sem internet.
          </p>
        )}

        {snapshotWarning && (
          <p className="mt-3 text-xs text-rose-400">
            {snapshotWarning}
          </p>
        )}
      </div>

      {selectedManagerStructureRows.length > 0 && (
        <div className="card border border-gray-800 bg-gray-900/40 p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <p className="text-[10px] uppercase tracking-[0.18em] text-gray-500">Liderança e cargos do setor</p>
              <p className="mt-1 text-sm text-gray-400">
                Gerente, coordenação, supervisão e demais cargos do setor aparecem aqui antes da equipe operacional.
              </p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2 text-[10px] uppercase tracking-wider text-gray-400">
               {selectedManagerStructureRows.length} cargo(s) no topo
            </div>
          </div>

          <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {selectedManagerStructureRows.map((row) => {
              const roleContext = buildRoleContext(row);
              return (
                <div
                  key={row.event_role_public_id || row.event_role_id || row.id}
                  className="rounded-2xl border border-gray-800 bg-gray-950/60 p-4"
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-[10px] uppercase tracking-wider text-gray-500">
                        {formatRoleClassLabel(row.role_class)}
                      </p>
                      <p className="mt-1 text-sm font-semibold text-white">{row.role_name || "Cargo"}</p>
                      <p className="mt-1 text-xs text-gray-500">
                        {row.linked_leader_name || "Sem liderança vinculada"}
                      </p>
                      {!row.leader_participant_name && !row.leader_name && row.linked_leader_name && (
                        <p className="mt-1 text-[10px] uppercase tracking-wider text-brand">
                          Liderança herdada da {formatRoleClassLabel(row.linked_leader_role_class || "manager").toLowerCase()}
                        </p>
                      )}
                    </div>
                    <div className="rounded-xl border border-gray-800 bg-gray-900/80 px-2 py-1 text-[10px] uppercase tracking-wider text-gray-300">
                      {Number(row.planned_members_count || row.members_count || 0).toLocaleString("pt-BR")} planejado(s)
                    </div>
                  </div>

                  <div className="mt-3 space-y-1 text-[11px] text-gray-500">
                    <p>Setor: {(row.sector || selectedManagerSector || "geral").replace(/_/g, " ")}</p>
                    <p>
                      Preenchido: {Number(row.filled_members_count || 0).toLocaleString("pt-BR")} /{" "}
                      {Number(row.planned_members_count || row.members_count || 0).toLocaleString("pt-BR")}
                    </p>
                    <p>
                      Turnos: {Number(row.max_shifts_event ?? 1).toLocaleString("pt-BR")} | Horas:{" "}
                      {Number(row.shift_hours ?? 8).toLocaleString("pt-BR")}h
                    </p>
                    <p>
                      Refeições/dia: {Number(row.meals_per_day ?? 4).toLocaleString("pt-BR")} | Valor/turno: R${" "}
                      {Number(row.payment_amount ?? 0).toFixed(2)}
                    </p>
                  </div>

                  {row.cost_bucket === "managerial" && (
                    <div className="mt-3">
                      {row.qr_token ? (
                        <div className="flex flex-wrap gap-2">
                          <button
                            type="button"
                            onClick={() => handleCopyLink(row.qr_token)}
                            className="p-1 px-2 text-[10px] bg-gray-800 border border-gray-700 rounded-lg inline-flex items-center gap-1"
                          >
                            <Copy size={11} /> Copiar link
                          </button>
                          <a
                            href={`${window.location.origin}/invite?token=${row.qr_token}`}
                            target="_blank"
                            rel="noreferrer"
                            className="p-1 px-2 text-[10px] text-brand-light border border-brand/30 rounded-lg inline-flex items-center gap-1"
                          >
                            <QrCode size={11} /> QR
                          </a>
                        </div>
                      ) : (
                        <p className="text-[10px] uppercase tracking-wider text-amber-400">
                          QR nominal indisponível até vincular participante ao cargo.
                        </p>
                      )}
                    </div>
                  )}

                  <div className="mt-4 flex items-center gap-2">
                    {canIssueWorkforceCards && row.cost_bucket === "managerial" && (
                      <button
                        type="button"
                        onClick={() =>
                          openCardIssuanceModal({
                            participants: [row],
                            managerEventRoleId: row.event_role_id || selectedManager?.event_role_id || null,
                            managerSector: row.sector || selectedManagerSector,
                            label: `Cargo diretivo: ${row.role_name || "lideranca"}`,
                          })
                        }
                        disabled={Number(row?.leader_participant_id || row?.participant_id || 0) <= 0}
                        className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-brand/40 text-brand-light hover:bg-brand/10 disabled:cursor-not-allowed disabled:opacity-50"
                        title={
                          Number(row?.leader_participant_id || row?.participant_id || 0) > 0
                            ? "Emitir cartao para este cargo diretivo"
                            : "Vincule um participante ao cargo para emitir o cartao."
                        }
                      >
                        <CreditCard size={13} /> Cartao
                      </button>
                    )}
                    <button
                      type="button"
                      onClick={() => openRoleSettingsForRow(row)}
                      className="btn-secondary h-9 px-3 text-xs flex items-center gap-2"
                      disabled={!roleContext?.id}
                    >
                      <Pencil size={13} /> Editar
                    </button>
                    {Number(row?.parent_event_role_id || 0) > 0 && (
                      <button
                        type="button"
                        onClick={() => handleDeleteStructureRow(row)}
                        className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-red-700/60 text-red-400 hover:bg-red-900/30"
                      >
                        <Trash2 size={13} /> Excluir
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {selectedIds.length > 0 && (
        <div className="bg-brand/10 border border-brand/20 p-4 rounded-2xl flex items-center justify-between">
          <span className="text-brand font-semibold">{selectedIds.length} selecionados</span>
          <div className="flex gap-2">
            {canIssueWorkforceCards && (
              <button
                onClick={() =>
                  openCardIssuanceModal({
                    participants: selectedParticipants,
                    managerEventRoleId: selectedManager?.event_role_id || null,
                    managerSector: selectedManagerSector,
                    label: `Equipe operacional do setor ${(selectedManagerSector || "geral").replace(/_/g, " ")}`,
                  })
                }
                disabled={!canIssueSelectedParticipants}
                className="btn-secondary h-9 px-3 text-xs flex items-center gap-2 border-brand/40 text-brand-light hover:bg-brand/10 disabled:cursor-not-allowed disabled:opacity-50"
                title={
                  canIssueSelectedParticipants
                    ? "Emitir cartoes para os participantes selecionados"
                    : "Selecione ao menos um participante do evento para emitir cartoes."
                }
              >
                <CreditCard size={14} /> Emitir cartoes
              </button>
            )}
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
        managerEventRoleId={selectedManager?.event_role_id || null}
        managerEventRolePublicId={selectedManager?.event_role_public_id || ""}
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
        managerEventRoleId={selectedManager?.event_role_id || null}
        managerEventRolePublicId={selectedManager?.event_role_public_id || ""}
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

      <WorkforceCardIssuanceModal
        isOpen={isCardIssuanceModalOpen}
        onClose={() => {
          setIsCardIssuanceModalOpen(false);
          setCardIssuanceSelection([]);
          setCardIssuanceContext({ managerEventRoleId: null, managerSector: "", label: "" });
        }}
        eventId={eventId}
        selectedParticipants={cardIssuanceSelection}
        managerEventRoleId={cardIssuanceContext.managerEventRoleId}
        managerSector={cardIssuanceContext.managerSector}
        contextLabel={cardIssuanceContext.label}
        onIssued={async () => {
          await refreshSelectedManagerView(selectedManager);
          setSelectedIds([]);
        }}
      />

      <WorkforceRoleSettingsModal
        isOpen={isRoleSettingsModalOpen}
        role={roleSettingsRole}
        eventId={eventId}
        availableRoles={roles}
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
