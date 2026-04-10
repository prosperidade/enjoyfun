import { useEffect, useMemo, useState } from "react";
import { Save, X } from "lucide-react";
import api from "../../lib/api";
import toast from "react-hot-toast";

const DEFAULT_FORM = {
  max_shifts_event: 1,
  shift_hours: 8,
  meals_per_day: 4,
  payment_amount: 0,
  cost_bucket: "operational",
  authority_level: "none",
  leader_user_id: "",
  leader_participant_id: "",
  is_placeholder: false,
  leader_name: "",
  leader_cpf: "",
  leader_phone: ""
};

const normalizeSector = (value = "") =>
  String(value || "")
    .toLowerCase()
    .trim()
    .replace(/\s+/g, "_");

const normalizeEmail = (value = "") => String(value || "").trim().toLowerCase();
const normalizeDocument = (value = "") => String(value || "").replace(/\D/g, "");

const findUserMatchForParticipant = (participant, users = []) => {
  const participantEmail = normalizeEmail(participant?.email);
  const participantDocument = normalizeDocument(participant?.document);
  return (
    users.find((user) => participantEmail && normalizeEmail(user?.email) === participantEmail) ||
    users.find((user) => participantDocument && normalizeDocument(user?.cpf) === participantDocument) ||
    null
  );
};

const findParticipantMatchForUser = (user, participants = []) => {
  const userEmail = normalizeEmail(user?.email);
  const userDocument = normalizeDocument(user?.cpf);
  return (
    participants.find((participant) => userEmail && normalizeEmail(participant?.email) === userEmail) ||
    participants.find((participant) => userDocument && normalizeDocument(participant?.document) === userDocument) ||
    null
  );
};

const hasLeadershipIdentity = (payload = {}) =>
  Number(payload?.leader_user_id || 0) > 0 ||
  Number(payload?.leader_participant_id || 0) > 0 ||
  (String(payload?.leader_name || "").trim() !== "" && normalizeDocument(payload?.leader_cpf) !== "");

const formatRoleClassLabel = (value = "") => {
  switch (String(value || "").toLowerCase()) {
    case "manager":
      return "Gerente";
    case "coordinator":
      return "Coordenador";
    case "supervisor":
      return "Supervisor";
    default:
      return "Liderança";
  }
};

export default function WorkforceRoleSettingsModal({
  isOpen,
  role,
  eventId,
  availableRoles = [],
  roleMembersCount = 0,
  onClose,
  onSaved
}) {
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState(DEFAULT_FORM);
  const [sectorCostLoading, setSectorCostLoading] = useState(false);
  const [sectorCostSummary, setSectorCostSummary] = useState(null);
  const [bindingOptionsLoading, setBindingOptionsLoading] = useState(false);
  const [eventParticipants, setEventParticipants] = useState([]);
  const [organizerUsers, setOrganizerUsers] = useState([]);
  const [treeRoles, setTreeRoles] = useState([]);
  const [participantSearch, setParticipantSearch] = useState("");
  const [participantOptionsLoading, setParticipantOptionsLoading] = useState(false);
  const [structuralLinkValue, setStructuralLinkValue] = useState("");
  const [selectedRoleId, setSelectedRoleId] = useState("");
  const [roleNameInput, setRoleNameInput] = useState("");
  const [roleSectorInput, setRoleSectorInput] = useState("");
  const normalizedSector = normalizeSector(role?.sector || "");
  const currentTreeRootId = useMemo(
    () => Number(role?.root_event_role_id || role?.event_role_id || 0),
    [role?.root_event_role_id, role?.event_role_id]
  );
  const currentTreeRootPublicId = useMemo(
    () => String(role?.root_public_id || role?.event_role_public_id || "").trim(),
    [role?.root_public_id, role?.event_role_public_id]
  );
  const roleAlreadyStructured = useMemo(
    () => Number(role?.event_role_id || 0) > 0 || Boolean(role?.event_role_public_id),
    [role?.event_role_id, role?.event_role_public_id]
  );

  const leadershipLinked = useMemo(
    () => Number(form.leader_user_id || 0) > 0 || Number(form.leader_participant_id || 0) > 0,
    [form.leader_participant_id, form.leader_user_id]
  );
  const leadershipResolved = useMemo(() => hasLeadershipIdentity(form), [form]);

  const selectedLeaderParticipant = useMemo(
    () =>
      eventParticipants.find(
        (participant) => Number(participant?.participant_id || 0) === Number(form.leader_participant_id || 0)
      ) || null,
    [eventParticipants, form.leader_participant_id]
  );

  const treeLeadershipOptions = useMemo(() => {
    const roleClassOrder = {
      manager: 0,
      coordinator: 1,
      supervisor: 2,
      operational: 3
    };

    return treeRoles
      .filter((treeRole) => Number(treeRole?.id || 0) > 0)
      .filter((treeRole) => Number(treeRole?.id || 0) !== Number(role?.event_role_id || 0))
      .filter((treeRole) => {
        const hasBindingContext =
          Number(treeRole?.leader_user_id || 0) > 0 ||
          Number(treeRole?.leader_participant_id || 0) > 0 ||
          Boolean(String(treeRole?.leader_name || treeRole?.leader_participant_name || "").trim());
        const roleClass = String(treeRole?.role_class || "").toLowerCase();
        const isLeadershipRole = roleClass === "manager" || roleClass === "coordinator" || roleClass === "supervisor";
        return hasBindingContext || isLeadershipRole;
      })
      .sort((left, right) => {
        const leftOrder = roleClassOrder[String(left?.role_class || "").toLowerCase()] ?? 99;
        const rightOrder = roleClassOrder[String(right?.role_class || "").toLowerCase()] ?? 99;
        if (leftOrder !== rightOrder) {
          return leftOrder - rightOrder;
        }
        return String(left?.role_name || "").localeCompare(String(right?.role_name || ""), "pt-BR");
      })
      .map((treeRole) => ({
        value: `tree:${Number(treeRole.id || 0)}`,
        event_role_id: Number(treeRole.id || 0),
        public_id: String(treeRole?.public_id || ""),
        role_name: treeRole?.role_name || treeRole?.name || "Cargo estrutural",
        role_class: String(treeRole?.role_class || "").toLowerCase(),
        leader_user_id: Number(treeRole?.leader_user_id || 0) || "",
        leader_participant_id: Number(treeRole?.leader_participant_id || 0) || "",
        leader_name: treeRole?.leader_participant_name || treeRole?.leader_name || "",
        leader_cpf: treeRole?.leader_cpf || "",
        leader_phone: treeRole?.leader_participant_phone || treeRole?.leader_phone || "",
        display_label: `${treeRole?.role_name || treeRole?.name || "Cargo estrutural"} • ${
          treeRole?.leader_participant_name || treeRole?.leader_name || "Sem nome vinculado"
        }`
      }));
  }, [role?.event_role_id, treeRoles]);

  const selectedTreeLeadershipOption = useMemo(
    () => treeLeadershipOptions.find((option) => option.value === structuralLinkValue) || null,
    [structuralLinkValue, treeLeadershipOptions]
  );

  const selectableRoles = useMemo(() => {
    return (availableRoles || [])
      .filter((candidate) => Number(candidate?.id || 0) > 0)
      .sort((left, right) =>
        String(left?.name || "").localeCompare(String(right?.name || ""), "pt-BR")
      );
  }, [availableRoles]);

  const selectedRoleOption = useMemo(
    () =>
      selectableRoles.find(
        (candidate) => Number(candidate?.id || 0) === Number(selectedRoleId || role?.id || 0)
      ) || null,
    [role?.id, selectableRoles, selectedRoleId]
  );
  const effectiveSector = normalizeSector(roleSectorInput || selectedRoleOption?.sector || normalizedSector);

  const visibleUsers = useMemo(() => {
    return organizerUsers.filter((user) => {
      const userSector = normalizeSector(user?.sector || "");
      return (
        Number(user?.id || 0) === Number(form.leader_user_id || 0) ||
        !effectiveSector ||
        userSector === "" ||
        userSector === "all" ||
        userSector === effectiveSector
      );
    });
  }, [effectiveSector, form.leader_user_id, organizerUsers]);

  const selectedLeaderUser = useMemo(
    () => visibleUsers.find((user) => Number(user?.id || 0) === Number(form.leader_user_id || 0)) || null,
    [form.leader_user_id, visibleUsers]
  );

  const leaderBindingValue = useMemo(() => {
    if (selectedTreeLeadershipOption) {
      return selectedTreeLeadershipOption.value;
    }
    if (Number(form.leader_user_id || 0) > 0) {
      return `user:${Number(form.leader_user_id)}`;
    }
    return "";
  }, [form.leader_user_id, selectedTreeLeadershipOption]);

  useEffect(() => {
    if (!isOpen || !role?.id) return;
    setLoading(true);
    setStructuralLinkValue("");
    setSelectedRoleId(String(role?.id || ""));
    setRoleNameInput(String(role?.name || ""));
    setRoleSectorInput(normalizeSector(role?.sector || ""));
    const params = new URLSearchParams();
    if (eventId) params.set("event_id", String(eventId));
    if (normalizedSector) params.set("sector", normalizedSector);
    if (Number(role?.event_role_id || 0) > 0) {
      params.set("event_role_id", String(Number(role.event_role_id)));
    } else if (role?.event_role_public_id) {
      params.set("event_role_public_id", String(role.event_role_public_id));
    }
    if (Number(role?.parent_event_role_id || 0) > 0) {
      params.set("parent_event_role_id", String(Number(role.parent_event_role_id)));
    } else if (role?.parent_public_id) {
      params.set("parent_public_id", String(role.parent_public_id));
    }
    api
      .get(`/workforce/role-settings/${role.id}${params.toString() ? `?${params.toString()}` : ""}`)
      .then((res) => {
        const d = res.data?.data || {};
        setForm({
          max_shifts_event: Number(d.max_shifts_event ?? 1),
          shift_hours: Number(d.shift_hours ?? 8),
          meals_per_day: Number(d.meals_per_day ?? 4),
          payment_amount: Number(d.payment_amount ?? 0),
          cost_bucket: d.cost_bucket === "managerial" ? "managerial" : "operational",
          authority_level: d.authority_level ?? role?.authority_level ?? "none",
          leader_user_id: Number(d.leader_user_id || 0) > 0 ? Number(d.leader_user_id) : "",
          leader_participant_id: Number(d.leader_participant_id || 0) > 0 ? Number(d.leader_participant_id) : "",
          is_placeholder: Boolean(d.is_placeholder),
          leader_name: d.leader_name ?? "",
          leader_cpf: d.leader_cpf ?? "",
          leader_phone: d.leader_phone ?? ""
        });
      })
      .catch((err) => {
        toast.error(err.response?.data?.message || "Erro ao carregar configuração do cargo.");
      })
      .finally(() => setLoading(false));
  }, [isOpen, role?.id, role?.name, role?.sector, role?.event_role_id, role?.event_role_public_id, role?.parent_event_role_id, role?.parent_public_id, role?.authority_level, eventId, normalizedSector]);

  useEffect(() => {
    if (!isOpen || !selectedRoleOption) return;
    setRoleNameInput(String(selectedRoleOption?.name || ""));
    setRoleSectorInput(normalizeSector(selectedRoleOption?.sector || ""));
  }, [isOpen, selectedRoleOption]);

  useEffect(() => {
    if (!isOpen || !eventId) {
      setEventParticipants([]);
      setOrganizerUsers([]);
      setTreeRoles([]);
      setParticipantSearch("");
      setStructuralLinkValue("");
      return;
    }

    let cancelled = false;

    const loadBindingOptions = async () => {
      setBindingOptionsLoading(true);
      try {
        const treeQuery = new URLSearchParams({ event_id: String(eventId) });
        if (currentTreeRootId > 0) {
          treeQuery.set("root_event_role_id", String(currentTreeRootId));
        } else if (currentTreeRootPublicId) {
          treeQuery.set("root_public_id", currentTreeRootPublicId);
        }

        const [usersRes, treeRolesRes] = await Promise.all([
          api.get("/users"),
          currentTreeRootId > 0 || currentTreeRootPublicId
            ? api.get(`/workforce/event-roles?${treeQuery.toString()}`)
            : Promise.resolve({ data: { data: [] } })
        ]);
        if (cancelled) return;
        setOrganizerUsers(Array.isArray(usersRes.data?.data) ? usersRes.data.data : []);
        setTreeRoles(Array.isArray(treeRolesRes.data?.data) ? treeRolesRes.data.data : []);
      } catch {
        if (!cancelled) {
          setEventParticipants([]);
          setOrganizerUsers([]);
          setTreeRoles([]);
        }
      } finally {
        if (!cancelled) {
          setBindingOptionsLoading(false);
        }
      }
    };

    loadBindingOptions();

    return () => {
      cancelled = true;
    };
  }, [currentTreeRootId, currentTreeRootPublicId, eventId, isOpen]);

  useEffect(() => {
    if (!isOpen || !eventId) {
      setEventParticipants([]);
      return;
    }

    let cancelled = false;
    const timeoutId = window.setTimeout(async () => {
      setParticipantOptionsLoading(true);
      try {
        const fallbackSearch =
          Number(form.leader_participant_id || 0) > 0
            ? String(form.leader_cpf || form.leader_name || "").trim()
            : "";
        const res = await api.get("/participants", {
          params: {
            event_id: eventId,
            per_page: 50,
            search: String(participantSearch || fallbackSearch).trim() || undefined,
          },
        });
        if (cancelled) return;
        const nextItems = Array.isArray(res.data?.data) ? res.data.data : [];
        setEventParticipants((current) => {
          const selectedId = Number(form.leader_participant_id || 0);
          if (selectedId <= 0 || nextItems.some((item) => Number(item?.participant_id || 0) === selectedId)) {
            return nextItems;
          }
          const selectedItem =
            current.find((item) => Number(item?.participant_id || 0) === selectedId) || null;
          return selectedItem ? [selectedItem, ...nextItems] : nextItems;
        });
      } catch {
        if (!cancelled) {
          setEventParticipants([]);
        }
      } finally {
        if (!cancelled) {
          setParticipantOptionsLoading(false);
        }
      }
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(timeoutId);
    };
  }, [eventId, form.leader_cpf, form.leader_name, form.leader_participant_id, isOpen, participantSearch]);

  useEffect(() => {
    if (!isOpen) {
      setStructuralLinkValue("");
      return;
    }

    if (Number(role?.parent_event_role_id || 0) > 0) {
      const parentValue = `tree:${Number(role.parent_event_role_id)}`;
      if (treeLeadershipOptions.some((option) => option.value === parentValue)) {
        setStructuralLinkValue(parentValue);
        return;
      }
    }

    setStructuralLinkValue("");
  }, [isOpen, role?.parent_event_role_id, treeLeadershipOptions]);

  useEffect(() => {
    if (!isOpen || !role?.id || !eventId || !normalizedSector) {
      setSectorCostSummary(null);
      return;
    }

    setSectorCostLoading(true);
    api
      .get(`/organizer-finance/workforce-costs?event_id=${eventId}&sector=${encodeURIComponent(normalizedSector)}`)
      .then((res) => {
        const data = res.data?.data || {};
        const bySector = Array.isArray(data.by_sector) ? data.by_sector : [];
        const row =
          bySector.find((entry) => normalizeSector(entry.sector) === normalizedSector) ||
          bySector[0] ||
          null;
        if (!row) {
          setSectorCostSummary(null);
          return;
        }

        const paymentTotal = Number(row.estimated_payment_total || 0);
        const mealsTotal = Number(row.estimated_meals_total || 0);
        const plannedMembersTotal = Number(row.planned_members_total || row.members || 0);
        const filledMembersTotal = Number(row.filled_members_total || 0);
        const mealUnitCost = Number(data.summary?.meal_unit_cost || 0);
        const sectorTotal = paymentTotal + mealsTotal * mealUnitCost;

        setSectorCostSummary({
          sector: normalizeSector(row.sector) || normalizedSector,
          plannedMembersTotal,
          filledMembersTotal,
          leadershipPositionsTotal: Number(row.leadership_positions_total || 0),
          leadershipFilledTotal: Number(row.leadership_filled_total || 0),
          operationalMembersTotal: Number(row.operational_members_total || 0),
          paymentTotal,
          mealsTotal,
          mealUnitCost,
          sectorTotal
        });
      })
      .catch(() => {
        setSectorCostSummary(null);
      })
      .finally(() => setSectorCostLoading(false));
  }, [isOpen, role?.id, eventId, normalizedSector]);

  const estimatedTotal = useMemo(() => {
    const shifts = Number(form.max_shifts_event || 0);
    const perShift = Number(form.payment_amount || 0);
    return shifts * perShift;
  }, [form.max_shifts_event, form.payment_amount]);

  const missingLeadSlot = useMemo(() => {
    if (roleAlreadyStructured) return 0;
    return Number(roleMembersCount || 0) > 0 ? 0 : 1;
  }, [roleAlreadyStructured, roleMembersCount]);
  const projectedSectorTotal = useMemo(() => {
    const baseSectorTotal = Number(sectorCostSummary?.sectorTotal || 0);
    return baseSectorTotal + estimatedTotal * missingLeadSlot;
  }, [sectorCostSummary?.sectorTotal, estimatedTotal, missingLeadSlot]);
  const projectedSectorMembers = useMemo(() => {
    const baseMembers = Number(sectorCostSummary?.plannedMembersTotal || 0);
    return baseMembers + missingLeadSlot;
  }, [sectorCostSummary?.plannedMembersTotal, missingLeadSlot]);

  const handleLeaderParticipantChange = (value) => {
    const nextParticipantId = value ? Number(value) : "";
    const participant =
      eventParticipants.find((item) => Number(item?.participant_id || 0) === Number(nextParticipantId || 0)) || null;

    setForm((previous) => {
      const matchedUser =
        participant && Number(previous.leader_user_id || 0) <= 0
          ? findUserMatchForParticipant(participant, visibleUsers)
          : null;
      const next = {
        ...previous,
        leader_participant_id: nextParticipantId,
        leader_user_id:
          Number(previous.leader_user_id || 0) > 0
            ? previous.leader_user_id
            : Number(matchedUser?.id || 0) > 0
              ? Number(matchedUser.id)
              : "",
        leader_name: participant?.name || previous.leader_name,
        leader_cpf: participant?.document || previous.leader_cpf,
        leader_phone: participant?.phone || previous.leader_phone
      };
      next.is_placeholder = !hasLeadershipIdentity(next) && next.cost_bucket === "managerial";
      return next;
    });
  };

  const handleLeaderUserChange = (value) => {
    const nextUserId = value ? Number(value) : "";
    const user = visibleUsers.find((item) => Number(item?.id || 0) === Number(nextUserId || 0)) || null;

    setForm((previous) => {
      const matchedParticipant =
        user && Number(previous.leader_participant_id || 0) <= 0
          ? findParticipantMatchForUser(user, eventParticipants)
          : null;
      const next = {
        ...previous,
        leader_user_id: nextUserId,
        leader_participant_id:
          Number(previous.leader_participant_id || 0) > 0
            ? previous.leader_participant_id
            : Number(matchedParticipant?.participant_id || 0) > 0
              ? Number(matchedParticipant.participant_id)
              : "",
        leader_name: user?.name || previous.leader_name,
        leader_cpf: user?.cpf || previous.leader_cpf,
        leader_phone: user?.phone || previous.leader_phone
      };
      next.is_placeholder = !hasLeadershipIdentity(next) && next.cost_bucket === "managerial";
      return next;
    });
  };

  const handleLeaderBindingChange = (value) => {
    if (!value) {
      setStructuralLinkValue("");
      handleLeaderUserChange("");
      return;
    }

    if (value.startsWith("user:")) {
      setStructuralLinkValue("");
      handleLeaderUserChange(value.slice(5));
      return;
    }

    if (!value.startsWith("tree:")) {
      setStructuralLinkValue("");
      handleLeaderUserChange(value);
      return;
    }

    if (treeLeadershipOptions.some((option) => option.value === value)) {
      setStructuralLinkValue(value);
      return;
    }

    setStructuralLinkValue("");
    handleLeaderUserChange("");
  };

  if (!isOpen || !role) return null;

  const save = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await api.put(`/workforce/role-settings/${role.id}`, {
        ...form,
        role_id: Number(selectedRoleId || role?.id || 0) || undefined,
        role_name: String(roleNameInput || "").trim() || undefined,
        event_id: eventId || undefined,
        sector: effectiveSector || undefined,
        event_role_id: role?.event_role_id || undefined,
        event_role_public_id: role?.event_role_public_id || undefined,
        parent_event_role_id: role?.parent_event_role_id || undefined,
        parent_public_id: role?.parent_public_id || undefined,
        root_event_role_id: role?.root_event_role_id || undefined,
        root_public_id: role?.root_public_id || undefined,
        role_class: role?.role_class || undefined,
        authority_level: form.authority_level || role?.authority_level || undefined,
        leader_user_id: Number(form.leader_user_id || 0) > 0 ? Number(form.leader_user_id) : null,
        leader_participant_id: Number(form.leader_participant_id || 0) > 0 ? Number(form.leader_participant_id) : null,
        is_placeholder: !leadershipResolved && form.cost_bucket === "managerial"
      });
      toast.success("Configuração do cargo salva.");
      onSaved?.();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar configuração do cargo.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-xl max-h-[90vh] overflow-hidden flex flex-col">
        <div className="p-4 border-b border-gray-800 flex items-center justify-between flex-shrink-0">
          <div>
            <h3 className="text-white font-bold">Configuração por Cargo</h3>
            <p className="text-xs text-gray-500 mt-1">{roleNameInput || role.name}</p>
          </div>
          <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={save} className="flex flex-col min-h-0">
          <div className="p-4 grid grid-cols-2 gap-3 overflow-y-auto min-h-0">
            <label className="text-xs text-gray-400 col-span-2">
              Cargo existente
              <select
                className="input mt-1 w-full"
                value={selectedRoleId}
                onChange={(e) => setSelectedRoleId(e.target.value)}
              >
                <option value="">Usar o cargo atual</option>
                {selectableRoles.map((candidate) => (
                  <option key={candidate.id} value={String(candidate.id)}>
                    {candidate.name}{candidate?.sector ? ` • ${candidate.sector}` : ""}
                  </option>
                ))}
              </select>
            </label>
            <label className="text-xs text-gray-400 col-span-2">
              Nome do cargo
              <input
                type="text"
                className="input mt-1 w-full"
                value={roleNameInput}
                onChange={(e) => setRoleNameInput(e.target.value)}
                placeholder="Ex.: Supervisor de Bar"
              />
            </label>
            <label className="text-xs text-gray-400 col-span-2">
              Setor do cargo
              <input
                type="text"
                className="input mt-1 w-full"
                value={roleSectorInput}
                onChange={(e) => setRoleSectorInput(e.target.value)}
                placeholder="Ex.: bar"
              />
            </label>
            <label className="text-xs text-gray-400 col-span-2">
              Nome da liderança
              <input
                type="text"
                className="input mt-1 w-full"
                value={form.leader_name}
                onChange={(e) => setForm((p) => ({ ...p, leader_name: e.target.value }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              CPF
              <input
                type="text"
                className="input mt-1 w-full"
                value={form.leader_cpf}
                onChange={(e) => setForm((p) => ({ ...p, leader_cpf: e.target.value }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              Celular
              <input
                type="text"
                className="input mt-1 w-full"
                value={form.leader_phone}
                onChange={(e) => setForm((p) => ({ ...p, leader_phone: e.target.value }))}
              />
            </label>

            <div className="col-span-2 rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-3">
              <p className="text-[11px] text-gray-400 uppercase tracking-wider">Conta de acesso da liderança</p>
              <p className="mt-1 text-xs text-gray-500">
                Se este cargo estiver ligado a uma conta ou participante, o sistema reconhece essa pessoa como responsável oficial.
              </p>
              <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <label className="text-xs text-gray-400">
                  Participante do Evento
                  <input
                    type="text"
                    className="input mt-1 w-full"
                    placeholder="Buscar participante por nome, email, CPF ou telefone"
                    value={participantSearch}
                    onChange={(e) => setParticipantSearch(e.target.value)}
                  />
                  <select
                    className="input mt-1 w-full"
                    value={form.leader_participant_id}
                    onChange={(e) => handleLeaderParticipantChange(e.target.value)}
                    disabled={bindingOptionsLoading || participantOptionsLoading}
                  >
                    <option value="">
                      {participantOptionsLoading ? "Carregando participantes..." : "Sem vínculo de participante"}
                    </option>
                    {eventParticipants.map((participant) => (
                      <option key={participant.participant_id} value={participant.participant_id}>
                        {participant.name} {participant.email ? `• ${participant.email}` : `• REF #${participant.participant_id}`}
                      </option>
                    ))}
                  </select>
                  <p className="mt-1 text-[11px] text-gray-500">
                    Busca remota limitada a 50 resultados por vez para não cortar eventos grandes.
                  </p>
                </label>

                <label className="text-xs text-gray-400">
                  Conta de acesso / liderança já cadastrada
                  <select
                    className="input mt-1 w-full"
                    value={leaderBindingValue}
                    onChange={(e) => handleLeaderBindingChange(e.target.value)}
                    disabled={bindingOptionsLoading}
                  >
                    <option value="">Sem vínculo de usuário</option>
                    {treeLeadershipOptions.length > 0 && (
                      <optgroup label="Lideranças já cadastradas neste setor">
                        {treeLeadershipOptions.map((treeRole) => (
                          <option key={treeRole.value} value={treeRole.value}>
                            {formatRoleClassLabel(treeRole.role_class)} • {treeRole.display_label}
                          </option>
                        ))}
                      </optgroup>
                    )}
                      <optgroup label="Contas do organizador">
                      {visibleUsers.map((user) => (
                        <option key={user.id} value={`user:${user.id}`}>
                          {user.name} {user.email ? `• ${user.email}` : ""}
                        </option>
                      ))}
                    </optgroup>
                  </select>
                </label>
              </div>

              <div className="mt-3 rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2 text-xs text-gray-400">
                <p className="font-semibold text-white">
                  {leadershipLinked
                    ? "Liderança vinculada"
                    : leadershipResolved
                      ? "Liderança identificada"
                      : form.is_placeholder
                        ? "Cargo sem responsável definido"
                        : "Sem conta vinculada"}
                </p>
                <p className="mt-1">
                  Participante: {selectedLeaderParticipant?.name || "não vinculado"} | Usuário: {selectedLeaderUser?.name || "não vinculado"}
                </p>
                {selectedTreeLeadershipOption && (
                  <p className="mt-1 text-gray-500">
                    Este cargo responde para: {selectedTreeLeadershipOption.role_name} • {selectedTreeLeadershipOption.leader_name || "sem nome"}
                  </p>
                )}
                {selectedTreeLeadershipOption && (
                  <p className="mt-1 text-gray-500">
                    Essa referência não troca o nome/CPF deste cargo; ela apenas mostra a liderança acima dele.
                  </p>
                )}
                {(selectedLeaderParticipant || selectedLeaderUser) && (
                  <p className="mt-1 text-gray-500">
                    Se os dados coincidirem, o sistema tenta completar o outro cadastro automaticamente.
                  </p>
                )}
              </div>
            </div>
            <label className="text-xs text-gray-400">
              Número de Turnos
              <input
                type="number"
                min="0"
                className="input mt-1 w-full"
                value={form.max_shifts_event}
                onChange={(e) => setForm((p) => ({ ...p, max_shifts_event: Number(e.target.value) }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              Horas por Turno
              <input
                type="number"
                min="0"
                step="0.5"
                className="input mt-1 w-full"
                value={form.shift_hours}
                onChange={(e) => setForm((p) => ({ ...p, shift_hours: Number(e.target.value) }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              Refeições por Dia
              <input
                type="number"
                min="0"
                className="input mt-1 w-full"
                value={form.meals_per_day}
                onChange={(e) => setForm((p) => ({ ...p, meals_per_day: Number(e.target.value) }))}
              />
            </label>
            <label className="text-xs text-gray-400">
              Valor por Turno (R$)
              <input
                type="number"
                min="0"
                step="0.01"
                className="input mt-1 w-full"
                value={form.payment_amount}
                onChange={(e) => setForm((p) => ({ ...p, payment_amount: Number(e.target.value) }))}
              />
            </label>

            <label className="text-xs text-gray-400 col-span-2">
              Tipo de Custo
              <select
                className="input mt-1 w-full"
                value={form.cost_bucket}
                onChange={(e) =>
                  setForm((p) => {
                    const nextCostBucket = e.target.value;
                    return {
                      ...p,
                      cost_bucket: nextCostBucket,
                      is_placeholder: nextCostBucket === "managerial" && !hasLeadershipIdentity(p)
                    };
                  })
                }
              >
                <option value="operational">Membro operacional</option>
                <option value="managerial">Cargo gerencial/diretivo</option>
              </select>
            </label>

            <div className="col-span-2 rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2">
              <p className="text-[11px] text-gray-400">
                Total estimado por membro neste cargo = Valor por Turno x Número de Turnos
              </p>
              <p className="text-sm font-semibold text-emerald-400 mt-1">
                R$ {Number(estimatedTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
              </p>
            </div>

            <div className="col-span-2 rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2">
              <p className="text-[11px] text-gray-400">
                Custo total do setor ({(normalizedSector || "geral").replace(/_/g, " ")}) incluindo este cargo
              </p>
              {sectorCostLoading ? (
                <p className="text-sm text-gray-500 mt-1">Carregando custo do setor...</p>
                ) : (
                  <>
                    <p className="text-xs text-gray-500 mt-1">
                      Base atual: R$ {Number(sectorCostSummary?.sectorTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })} | Planejado:{" "}
                      {Number(sectorCostSummary?.plannedMembersTotal || 0).toLocaleString("pt-BR")} | Preenchido:{" "}
                      {Number(sectorCostSummary?.filledMembersTotal || 0).toLocaleString("pt-BR")}
                    </p>
                    <p className="text-[11px] text-gray-500 mt-1">
                      Liderança: {Number(sectorCostSummary?.leadershipPositionsTotal || 0).toLocaleString("pt-BR")} • Operação:{" "}
                      {Number(sectorCostSummary?.operationalMembersTotal || 0).toLocaleString("pt-BR")}
                    </p>
                    <p className="text-sm font-semibold text-cyan-400 mt-1">
                      Projeção com o cargo: R$ {Number(projectedSectorTotal || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                    </p>
                    <p className="text-[11px] text-gray-500 mt-1">
                      Planejado no setor: {Number(projectedSectorMembers || 0).toLocaleString("pt-BR")}
                      {missingLeadSlot > 0 ? " (inclui 1 posição-base deste cargo)" : ""}
                    </p>
                  </>
              )}
            </div>
          </div>

          <div className="flex justify-end gap-2 p-4 border-t border-gray-800 flex-shrink-0">
            <button type="button" onClick={onClose} className="btn-secondary">Cancelar</button>
            <button type="submit" disabled={loading} className="btn-primary flex items-center gap-2">
              <Save size={16} /> Salvar
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
