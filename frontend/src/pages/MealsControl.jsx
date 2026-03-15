import { useEffect, useMemo, useState } from "react";
import { QrCode, UtensilsCrossed, RefreshCw, Settings2, Save, X } from "lucide-react";
import toast from "react-hot-toast";
import api from "../lib/api";

function extractToken(raw = "") {
  const value = String(raw || "").trim();
  if (!value) return "";
  const match = value.match(/[?&]token=([^&]+)/i);
  if (match?.[1]) return decodeURIComponent(match[1]);
  return value;
}

function createEmptyPayload() {
  return {
    summary: null,
    items: [],
    operationalSummary: null,
    projectionSummary: null,
    diagnostics: null,
  };
}

function formatCurrency(value) {
  return `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`;
}

function getIssueLabel(code) {
  const labels = {
    event_has_no_days: "Este evento ainda não possui dias operacionais cadastrados.",
    event_has_no_shifts: "Este evento ainda não possui turnos cadastrados.",
    selected_day_has_no_shifts: "O dia selecionado ainda não possui turnos cadastrados.",
    no_assignments_in_scope: "Nenhum membro com assignment válido foi encontrado no recorte atual.",
    members_using_default_meal_fallback: "Parte da equipe está usando cota default, sem configuração específica.",
    ambiguous_meal_baseline_in_scope: "Parte da equipe está com baseline de refeições ambíguo neste recorte.",
    no_real_meal_consumption_for_day: "Ainda não há consumo real registrado para o dia selecionado.",
    meal_unit_cost_schema_unavailable: "O custo de refeição está indisponível neste ambiente.",
    meal_unit_cost_not_configured: "O custo de refeição ainda não foi configurado.",
  };
  return labels[code] || code;
}

function getConfigSourceLabel(source) {
  const labels = {
    member_override: "Config. do membro",
    role_settings: "Config. do cargo",
    default: "Fallback default",
    ambiguous: "Baseline ambíguo",
  };
  return labels[source] || "Origem não informada";
}

function getConfigSourceBadgeClass(source) {
  const labels = {
    member_override: "badge badge-green",
    role_settings: "badge badge-blue",
    default: "badge badge-yellow",
    ambiguous: "badge badge-red",
  };
  return labels[source] || "badge badge-gray";
}

function getConfigSourceBadgeLabel(source) {
  const labels = {
    member_override: "Cota própria",
    role_settings: "Cota do cargo",
    default: "Fallback",
    ambiguous: "Ambígua",
  };
  return labels[source] || "Sem origem";
}

export default function MealsControl() {
  const [events, setEvents] = useState([]);
  const [eventDays, setEventDays] = useState([]);
  const [eventShifts, setEventShifts] = useState([]);

  const [eventId, setEventId] = useState("");
  const [eventDayId, setEventDayId] = useState("");
  const [eventShiftId, setEventShiftId] = useState("");
  const [sector, setSector] = useState("");

  const [loading, setLoading] = useState(false);
  const [registering, setRegistering] = useState(false);
  const [qrInput, setQrInput] = useState("");
  const [payload, setPayload] = useState(createEmptyPayload);
  const [workforceBaseItems, setWorkforceBaseItems] = useState([]);
  const [mealUnitCost, setMealUnitCost] = useState(0);
  const [mealUnitCostAvailable, setMealUnitCostAvailable] = useState(null);
  const [mealCostModalOpen, setMealCostModalOpen] = useState(false);
  const [mealCostDraft, setMealCostDraft] = useState(0);
  const [savingMealCost, setSavingMealCost] = useState(false);

  const filteredShifts = useMemo(() => {
    if (!eventDayId) return [];
    return eventShifts.filter((s) => String(s.event_day_id) === String(eventDayId));
  }, [eventShifts, eventDayId]);

  const loadEvents = async () => {
    const res = await api.get("/events");
    const list = res.data?.data || [];
    setEvents(list);
    if (!eventId && list.length > 0) {
      // Prioriza selecionar um evento em andamento, fallback para o primeiro
      const now = new Date();
      const inProgress = list.find((ev) => {
        if (!ev.starts_at || !ev.ends_at) return false;
        return new Date(ev.starts_at) <= now && new Date(ev.ends_at) >= now;
      });
      setEventId(String(inProgress ? inProgress.id : list[0].id));
    }
  };

  const loadStaticData = async (evtId) => {
    if (!evtId) return;
    const [daysRes, shiftsRes] = await Promise.all([
      api.get(`/event-days?event_id=${evtId}`),
      api.get(`/event-shifts?event_id=${evtId}`),
    ]);
    const days = daysRes.data?.data || [];
    const shifts = shiftsRes.data?.data || [];
    setEventDays(days);
    setEventShifts(shifts);
    setPayload(createEmptyPayload());

    setEventDayId((prev) => {
      const nextDay = days.some((day) => String(day.id) === String(prev))
        ? String(prev)
        : (days[0] ? String(days[0].id) : "");
      if (!nextDay) {
        setEventShiftId("");
      }
      return nextDay;
    });
  };

  const loadWorkforceBase = async (evtId) => {
    if (!evtId) {
      setWorkforceBaseItems([]);
      return;
    }

    try {
      const res = await api.get("/workforce/assignments", {
        params: { event_id: evtId },
      });
      setWorkforceBaseItems(res.data?.data || []);
    } catch (err) {
      setWorkforceBaseItems([]);
      toast.error(err.response?.data?.message || "Erro ao carregar base do workforce para Meals.");
    }
  };

  const loadBalance = async () => {
    if (!eventId || !eventDayId) {
      setPayload(createEmptyPayload());
      return;
    }
    setLoading(true);
    try {
      const res = await api.get("/meals/balance", {
        params: {
          event_id: eventId,
          event_day_id: eventDayId,
          event_shift_id: eventShiftId || undefined,
          sector: sector.trim() || undefined,
        },
      });
      const data = res.data?.data || {};
      setPayload({
        summary: data.summary || null,
        items: data.items || [],
        operationalSummary: data.operational_summary || null,
        projectionSummary: data.projection_summary || null,
        diagnostics: data.diagnostics || null,
      });
      const unit = Number(
        data.projection_summary?.meal_unit_cost ??
        data.summary?.meal_unit_cost ??
        0
      );
      setMealUnitCost(unit);
    } catch (err) {
      setPayload(createEmptyPayload());
      toast.error(err.response?.data?.message || "Erro ao carregar saldo de refeições.");
    } finally {
      setLoading(false);
    }
  };

  const loadMealUnitCost = async () => {
    try {
      const res = await api.get("/organizer-finance/settings");
      const settings = res.data?.data || {};
      const unitCost = Number(settings.meal_unit_cost ?? 0);
      const unitCostAvailable = settings.meal_unit_cost_available !== false;
      setMealUnitCost(unitCost);
      setMealUnitCostAvailable(unitCostAvailable);
      setMealCostDraft(unitCost);
    } catch {
      setMealUnitCost(0);
      setMealUnitCostAvailable(null);
      setMealCostDraft(0);
    }
  };

  useEffect(() => {
    loadEvents().catch(() => toast.error("Erro ao carregar eventos."));
    loadMealUnitCost();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    setEventDayId("");
    setEventShiftId("");
    setPayload(createEmptyPayload());
  }, [eventId]);

  useEffect(() => {
    if (!eventId) return;
    loadStaticData(eventId).catch((err) =>
      toast.error(err.response?.data?.message || "Erro ao carregar dias/turnos.")
    );
    loadWorkforceBase(eventId);
  }, [eventId]);

  useEffect(() => {
    if (!eventDayId) {
      setEventShiftId("");
      return;
    }
    const valid = filteredShifts.some((s) => String(s.id) === String(eventShiftId));
    if (!valid) {
      setEventShiftId("");
    }
  }, [eventDayId, eventShiftId, filteredShifts]);

  useEffect(() => {
    if (!eventId || !eventDayId) {
      setPayload(createEmptyPayload());
      return;
    }
    // Previne chamadas com eventDayId de evento anterior durante a transição
    const isDayFromCurrentEvent = eventDays.some(d => String(d.id) === String(eventDayId));
    if (eventDays.length > 0 && !isDayFromCurrentEvent) {
      return;
    }
    loadBalance();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eventId, eventDayId, eventShiftId, sector, eventDays]);

  const availableSectors = useMemo(() => {
    const values = workforceBaseItems
      .map((item) => String(item.sector || "").trim().toLowerCase())
      .filter(Boolean);
    return [...new Set(values)].sort((a, b) => a.localeCompare(b, "pt-BR"));
  }, [workforceBaseItems]);

  useEffect(() => {
    if (sector && !availableSectors.includes(String(sector).trim().toLowerCase())) {
      setSector("");
    }
  }, [availableSectors, sector]);

  const filteredWorkforceItems = useMemo(() => {
    return workforceBaseItems.filter((item) => {
      if (sector && String(item.sector || "").trim().toLowerCase() !== String(sector).trim().toLowerCase()) {
        return false;
      }
      return true;
    });
  }, [sector, workforceBaseItems]);

  const workforceSummary = useMemo(() => {
    const sectors = new Set();
    const uniqueParticipants = new Set();
    let mealsPerDayTotal = 0;
    let assignmentsWithShift = 0;

    filteredWorkforceItems.forEach((item) => {
      uniqueParticipants.add(String(item.participant_id || `assignment:${item.id}`));
      const normalizedSector = String(item.sector || "").trim().toLowerCase();
      if (normalizedSector) {
        sectors.add(normalizedSector);
      }
      mealsPerDayTotal += Number(item.meals_per_day || 0);
      if (item.shift_id) {
        assignmentsWithShift += 1;
      }
    });

    return {
      members: uniqueParticipants.size,
      assignmentRows: filteredWorkforceItems.length,
      sectorsCount: sectors.size,
      mealsPerDayTotal,
      assignmentsWithShift,
      assignmentsWithoutShift: Math.max(0, filteredWorkforceItems.length - assignmentsWithShift),
    };
  }, [filteredWorkforceItems]);

  const handleRegisterMeal = async (e) => {
    e.preventDefault();
    const token = extractToken(qrInput);
    if (!token) {
      toast.error("Informe um token QR válido.");
      return;
    }
    if (!eventDayId) {
      toast.error("Selecione o dia do evento.");
      return;
    }

    setRegistering(true);
    try {
      await api.post("/meals", {
        qr_token: token,
        event_day_id: Number(eventDayId),
        event_shift_id: eventShiftId ? Number(eventShiftId) : null,
      });
      toast.success("Refeição registrada com sucesso.");
      setQrInput("");
      await loadBalance();
    } catch (err) {
      toast.error(err.response?.data?.message || "Falha ao registrar refeição.");
    } finally {
      setRegistering(false);
    }
  };

  const summary = payload.summary || {
    members: 0,
    meals_per_day_total: 0,
    consumed_day_total: 0,
    remaining_day_total: 0,
    consumed_shift_total: 0,
    meal_unit_cost: mealUnitCost,
    estimated_day_cost_total: 0,
    consumed_day_cost_total: 0,
    remaining_day_cost_total: 0,
  };
  const operationalSummary = payload.operationalSummary || {
    members: summary.members,
    meals_per_day_total: summary.meals_per_day_total,
    consumed_day_total: summary.consumed_day_total,
    remaining_day_total: summary.remaining_day_total,
    consumed_shift_total: summary.consumed_shift_total,
  };
  const projectionSummary = payload.projectionSummary || {
    enabled: false,
    meal_unit_cost: 0,
    estimated_day_cost_total: 0,
    consumed_day_cost_total: 0,
    remaining_day_cost_total: 0,
  };
  const diagnostics = payload.diagnostics || null;

  const currentMealUnitCost = Number(
    projectionSummary.meal_unit_cost ?? summary.meal_unit_cost ?? mealUnitCost ?? 0
  );
  const projectionEnabled = Boolean(projectionSummary.enabled);
  const hasSelectedShift = Boolean(eventShiftId);
  const hasConfiguredEventDays = eventDays.length > 0;
  const diagnosticsIssues = useMemo(() => diagnostics?.issues || [], [diagnostics]);
  const operationalDiagnosticsIssues = useMemo(
    () => diagnosticsIssues.filter((issue) => !issue.startsWith("meal_unit_cost_")),
    [diagnosticsIssues]
  );
  const financialDiagnosticsIssues = useMemo(
    () => diagnosticsIssues.filter((issue) => issue.startsWith("meal_unit_cost_")),
    [diagnosticsIssues]
  );
  const showWorkforceFallback = Boolean(eventId) && !hasConfiguredEventDays;
  const canUseRealMeals = Boolean(eventId) && hasConfiguredEventDays && Boolean(eventDayId);
  const canRegisterMeal = canUseRealMeals;
  const configBreakdown = diagnostics?.configuration || {
    members_using_member_settings: 0,
    members_using_role_settings: 0,
    members_using_default_fallback: 0,
    members_with_ambiguous_baseline: 0,
  };
  const emptyTableMessage = !eventId
    ? "Selecione um evento para iniciar a leitura operacional."
    : showWorkforceFallback
      ? sector
        ? "Nenhum membro da base complementar do Workforce foi encontrado para o setor selecionado."
        : workforceSummary.members > 0
          ? "Este evento está em modo complementar do Workforce porque ainda não possui dias operacionais configurados."
          : "Este evento ainda não possui dias operacionais nem equipe visível na base complementar do Workforce."
      : !eventDayId
        ? "Selecione um dia operacional do evento para carregar o saldo real de Meals."
        : operationalDiagnosticsIssues.includes("no_assignments_in_scope")
          ? "Nenhum membro com assignment válido foi encontrado neste recorte operacional."
          : sector
            ? "Nenhum membro encontrado para os filtros atuais."
            : "Nenhum membro encontrado para o dia selecionado.";
  const notices = useMemo(() => {
    const list = [];

    if (!eventId) {
      list.push({
        tone: "neutral",
        title: "Selecione um evento",
        body: "O Meals precisa de um evento e de um dia operacional para carregar o saldo.",
      });
      return list;
    }

    if (showWorkforceFallback) {
      list.push({
        tone: "info",
        title: "Modo complementar do Workforce",
        body: workforceSummary.members > 0
          ? `Este evento ainda não possui \`event_days\`. Disponível agora: base complementar do Workforce por pessoa/setor. Indisponível agora: saldo real por dia, registro de refeição e projeção financeira diária. Base atual: ${workforceSummary.members} pessoa(s) em ${workforceSummary.assignmentRows} assignment(s).`
          : "Este evento ainda não possui `event_days`. O Meals permanece em modo complementar até existir base diária do evento.",
      });
      if (workforceSummary.members > 0 && workforceSummary.assignmentsWithShift <= 0) {
        list.push({
          tone: "info",
          title: "Assignments sem vínculo de turno",
          body: "Os assignments visíveis no Workforce ainda não possuem vínculo de turno. Isso não impede a leitura complementar da equipe, mas impede recorte operacional por turno.",
        });
      }
      return list;
    }

    if (!eventDayId) {
      list.push({
        tone: "warn",
        title: "Dia não selecionado",
        body: "Selecione um dia operacional do evento para habilitar o saldo real de Meals, o recorte por turno e o registro de refeição.",
      });
      return list;
    }

    if (filteredShifts.length === 0) {
      list.push({
        tone: "info",
        title: "Dia sem turnos cadastrados",
        body: "O backend aceita operação sem turno. O filtro está realmente em `Todos os turnos` para este dia.",
      });
    }

    if (diagnostics?.status === "partial" && operationalDiagnosticsIssues.length > 0) {
      list.push({
        tone: "warn",
        title: "Leitura operacional parcial",
        body: "A base deste recorte está incompleta. Parte do saldo pode estar apoiada em fallback ou ausência de consumo real.",
      });
    }

    if (operationalDiagnosticsIssues.includes("no_real_meal_consumption_for_day")) {
      list.push({
        tone: "info",
        title: "Saldo ainda teórico",
        body: "Ainda não há consumo real registrado para o dia selecionado. O saldo exibido está apoiado apenas na cota configurada.",
      });
    }

    if (operationalDiagnosticsIssues.includes("members_using_default_meal_fallback")) {
      list.push({
        tone: "warn",
        title: "Equipe com fallback default",
        body: "Parte da equipe está sem configuração específica de refeições e está usando o fallback operacional padrão.",
      });
    }

    if (operationalDiagnosticsIssues.includes("ambiguous_meal_baseline_in_scope")) {
      list.push({
        tone: "warn",
        title: "Baseline ambíguo no recorte",
        body: "Parte da equipe tem conflito real de cota neste recorte. O consumo real continua visível, mas a cota e o saldo derivado desse subconjunto não são confiáveis.",
      });
    }

    return list;
  }, [
    diagnostics?.status,
    eventDayId,
    eventId,
    filteredShifts.length,
    showWorkforceFallback,
    operationalDiagnosticsIssues,
    workforceSummary.assignmentsWithShift,
    workforceSummary.assignmentRows,
    workforceSummary.members,
  ]);

  const tableRows = useMemo(() => {
    if (showWorkforceFallback) {
      return filteredWorkforceItems.map((item) => ({
        key: `workforce-${item.id ?? item.participant_id}`,
        participantId: item.participant_id,
        name: item.person_name,
        roleName: item.role_name,
        sector: item.sector || "geral",
        mealsPerDay: Number(item.meals_per_day || 0),
        configSource: null,
        consumedDay: null,
        remainingDay: null,
        consumedShift: null,
        shiftName: item.shift_name || "",
        shiftId: item.shift_id ? Number(item.shift_id) : null,
        qrToken: item.qr_token || "",
        sourceLabel: "Base Workforce",
        sourceDescription: "Base real complementar do evento",
        sourceBadgeClass: "badge badge-gray",
      }));
    }

    return (payload.items || []).map((item) => {
      const assignmentsInScope = Number(item.assignments_in_scope || 0);
      const hasMultipleAssignments = Boolean(item.has_multiple_assignments);
      const hasMultipleRoles = Boolean(item.has_multiple_roles);
        const hasMultipleSectors = Boolean(item.has_multiple_sectors);
        const hasMultipleShifts = Boolean(item.has_multiple_shifts);
        const hasAmbiguousBaseline = Boolean(item.has_ambiguous_baseline);
        const shiftId = item.shift_id ? Number(item.shift_id) : null;
        const roleName = item.role_name || (hasMultipleRoles ? "Cargos múltiplos" : "Cargo não unívoco");
        const sectorLabel = item.sector || (hasMultipleSectors ? "Setores múltiplos" : "Setor não unívoco");

      return {
        key: `meal-${item.participant_id}`,
        participantId: item.participant_id,
        name: item.participant_name,
        roleName,
        sector: sectorLabel,
        mealsPerDay: item.meals_per_day === null || item.meals_per_day === undefined
          ? null
          : Number(item.meals_per_day),
        configSource: item.config_source || "default",
        baselineStatus: item.baseline_status || (hasAmbiguousBaseline ? "ambiguous" : "resolved"),
        hasAmbiguousBaseline,
        consumedDay: Number(item.consumed_day || 0),
        remainingDay: item.remaining_day === null || item.remaining_day === undefined
          ? null
          : Number(item.remaining_day),
        consumedShift: Number(item.consumed_shift || 0),
        shiftName: item.shift_name || "",
        shiftId,
        assignmentsInScope,
        hasMultipleAssignments,
        hasMultipleRoles,
        hasMultipleSectors,
        hasMultipleShifts,
        qrToken: item.qr_token || "",
        sourceLabel: "Saldo real Meals",
        sourceDescription: hasAmbiguousBaseline
          ? "Consumo real preservado, mas a cota deste participante ficou ambígua no recorte."
          : assignmentsInScope > 1
            ? `Saldo real por participante com ${assignmentsInScope} assignments no recorte.`
          : "Saldo real Meals com contexto complementar consolidado do Workforce.",
        sourceBadgeClass: "badge badge-green",
      };
    });
  }, [
    filteredWorkforceItems,
    payload.items,
    showWorkforceFallback,
  ]);

  const tableHighlights = useMemo(() => {
      return {
        consumedMembers: tableRows.filter((row) => Number(row.consumedDay || 0) > 0).length,
        exhaustedMembers: tableRows.filter(
          (row) => row.remainingDay !== null && Number(row.remainingDay) <= 0
        ).length,
        ambiguousBaselineMembers: tableRows.filter((row) => row.hasAmbiguousBaseline).length,
        withoutUniqueShiftMembers: tableRows.filter((row) => !row.shiftId).length,
        multiAssignmentMembers: tableRows.filter((row) => Number(row.assignmentsInScope || 0) > 1).length,
      };
  }, [tableRows]);

  const operationalCards = useMemo(() => {
    if (showWorkforceFallback) {
      return [
        {
          label: "Pessoas no Workforce",
          value: workforceSummary.members,
          valueClassName: "text-white",
          helper: workforceSummary.assignmentRows > workforceSummary.members
            ? `Base complementar com ${workforceSummary.assignmentRows} assignments reais para ${workforceSummary.members} pessoa(s).`
            : "Base complementar do evento enquanto o saldo diário ainda não pode ser lido.",
          badge: "Base Workforce",
          badgeClassName: "badge badge-gray",
        },
        {
          label: "Assignments visíveis",
          value: workforceSummary.assignmentRows,
          valueClassName: "text-white",
          helper: "Medida explicitamente em assignments para não fingir consolidação diária inexistente.",
          badge: "Assignment-level",
          badgeClassName: "badge badge-blue",
        },
        {
          label: "Setores visíveis",
          value: workforceSummary.sectorsCount,
          valueClassName: "text-blue-400",
          helper: "Setores vindos da base real do Workforce para este evento.",
          badge: "Workspace real",
          badgeClassName: "badge badge-gray",
        },
      ];
    }

    return [
      {
        label: "Membros",
        value: operationalSummary.members,
        valueClassName: "text-white",
        helper: "Saldo real de Meals carregado para o dia selecionado.",
        badge: "Saldo real Meals",
        badgeClassName: "badge badge-green",
      },
      {
        label: "Cota dia",
        value: operationalSummary.meals_per_day_total,
        valueClassName: "text-white",
        helper: configBreakdown.members_with_ambiguous_baseline > 0
          ? "Parte da equipe segue com baseline ambíguo e fica fora da cota derivada confiável."
          : configBreakdown.members_using_default_fallback > 0
            ? `Parte da equipe (${configBreakdown.members_using_default_fallback}) ainda usa fallback default neste recorte.`
            : "Cota diária consolidada para o recorte operacional do dia.",
        badge: configBreakdown.members_with_ambiguous_baseline > 0 ? "Origem parcial" : "Origem visível",
        badgeClassName: configBreakdown.members_with_ambiguous_baseline > 0 ? "badge badge-yellow" : "badge badge-blue",
      },
      {
        label: "Consumidas dia",
        value: operationalSummary.consumed_day_total,
        valueClassName: "text-amber-400",
        helper: `${tableHighlights.consumedMembers} participante(s) ja consumiram no dia.`,
        badge: tableHighlights.consumedMembers > 0 ? "Com consumo" : "Sem consumo",
        badgeClassName: tableHighlights.consumedMembers > 0 ? "badge badge-blue" : "badge badge-gray",
      },
      {
        label: "Saldo dia",
        value: operationalSummary.remaining_day_total,
        valueClassName: tableHighlights.exhaustedMembers > 0 ? "text-red-400" : "text-green-400",
        helper: `${tableHighlights.exhaustedMembers} participante(s) sem saldo restante.`,
        badge: tableHighlights.exhaustedMembers > 0 ? "Sem saldo" : "Saldo ok",
        badgeClassName: tableHighlights.exhaustedMembers > 0 ? "badge badge-red" : "badge badge-green",
      },
      {
        label: hasSelectedShift ? "Consumidas turno" : "Consumo no recorte",
        value: operationalSummary.consumed_shift_total,
        valueClassName: "text-blue-400",
        helper: hasSelectedShift
          ? tableHighlights.withoutUniqueShiftMembers > 0
            ? `${tableHighlights.withoutUniqueShiftMembers} participante(s) seguem sem vínculo único de turno no Workforce.`
            : "Turno selecionado com vínculo operacional visível no recorte."
          : "Sem turno selecionado, o backend retorna o consumo agregado do dia neste recorte.",
        badge: hasSelectedShift
          ? (tableHighlights.withoutUniqueShiftMembers > 0 ? "Turno parcial" : "Turno ok")
          : "Sem recorte turno",
        badgeClassName: hasSelectedShift
          ? (tableHighlights.withoutUniqueShiftMembers > 0 ? "badge badge-yellow" : "badge badge-blue")
          : "badge badge-gray",
      },
    ];
  }, [
    configBreakdown.members_using_default_fallback,
    configBreakdown.members_with_ambiguous_baseline,
    hasSelectedShift,
    operationalSummary.consumed_day_total,
    operationalSummary.consumed_shift_total,
    operationalSummary.meals_per_day_total,
    operationalSummary.members,
    operationalSummary.remaining_day_total,
    showWorkforceFallback,
    tableHighlights.consumedMembers,
    tableHighlights.exhaustedMembers,
    tableHighlights.withoutUniqueShiftMembers,
    workforceSummary.assignmentRows,
    workforceSummary.members,
    workforceSummary.sectorsCount,
  ]);

  const readingContext = !eventId
    ? {
        title: "Selecione um evento",
        body: "O Meals precisa de um evento para definir se a tela vai operar com saldo real do dia ou com a base complementar do Workforce.",
        badge: "Aguardando contexto",
        badgeClassName: "badge badge-gray",
      }
    : showWorkforceFallback
      ? {
          title: "Base complementar do Workforce",
          body: "Este evento ainda não possui `event_days`. A tela mostra equipe, setor e cota configurada a partir da base real do Workforce. Saldo real por dia, recorte útil de turno e registro de refeição ficam indisponíveis neste modo.",
          badge: "Base Workforce",
          badgeClassName: "badge badge-gray",
        }
      : !eventDayId
        ? {
            title: "Aguardando dia operacional",
            body: "Os dias deste evento já existem, mas o saldo real do Meals só pode ser carregado quando um `event_day` for selecionado.",
            badge: "Selecione o dia",
            badgeClassName: "badge badge-yellow",
          }
        : {
            title: "Saldo real do Meals ativo",
            body: hasSelectedShift
              ? "A tela está lendo saldo e consumo reais do dia com recorte de turno aplicado. Turno e ausência de turno continuam visíveis como apoio complementar do Workforce."
              : "A tela está lendo saldo e consumo reais do dia. Sem turno selecionado, o consumo do recorte permanece agregado no dia.",
            badge: "Saldo real Meals",
            badgeClassName: "badge badge-green",
          };

  const registerMealMessage = showWorkforceFallback
    ? "Registro de refeição indisponível: este evento ainda não possui `event_days`, então o módulo permanece em modo complementar do Workforce."
    : !eventDayId
      ? "Selecione um dia operacional para habilitar o registro de refeição."
      : hasSelectedShift
        ? "Registro de refeição ativo para o dia e turno selecionados."
        : "Registro de refeição ativo para o dia selecionado. Sem turno, a baixa fica agregada no recorte diário.";

  const handleRefresh = async () => {
    if (!eventId) return;
    if (showWorkforceFallback) {
      await loadWorkforceBase(eventId);
      return;
    }
    await loadBalance();
  };

  const bannerClassByTone = {
    neutral: "border-gray-800 bg-gray-900/70 text-gray-200",
    info: "border-blue-900/60 bg-blue-950/30 text-blue-100",
    warn: "border-amber-900/60 bg-amber-950/30 text-amber-100",
  };

  const saveMealCost = async (e) => {
    e.preventDefault();
    if (mealUnitCostAvailable === false) {
      toast.error("O ambiente atual não sustenta `meal_unit_cost`. O ajuste permanece indisponível nesta base.");
      return;
    }
    const safeValue = Math.max(0, Number(mealCostDraft || 0));
    setSavingMealCost(true);
    try {
      const res = await api.get("/organizer-finance/settings");
      const settings = res.data?.data || {};
      const unitCostAvailable = settings.meal_unit_cost_available !== false;
      setMealUnitCostAvailable(unitCostAvailable);
      if (!unitCostAvailable) {
        toast.error("O ambiente atual não sustenta `meal_unit_cost`. O ajuste permanece indisponível nesta base.");
        return;
      }
      const saveRes = await api.put("/organizer-finance/settings", {
        currency: settings.currency || "BRL",
        tax_rate: Number(settings.tax_rate ?? 0),
        meal_unit_cost: safeValue
      });
      const persistedSettings = saveRes.data?.data || {};
      const persistedUnit = Number(persistedSettings.meal_unit_cost ?? 0);
      const persistedUnitAvailable = persistedSettings.meal_unit_cost_available !== false;
      const persistedMatchesRequest = Math.abs(persistedUnit - safeValue) < 0.005;
      setMealUnitCost(persistedUnit);
      setMealUnitCostAvailable(persistedUnitAvailable);
      setMealCostDraft(persistedUnit);
      setMealCostModalOpen(false);
      await loadBalance();
      if (persistedUnitAvailable && persistedMatchesRequest) {
        toast.success("Valor unitário de refeição atualizado.");
      } else {
        toast.error("O ambiente atual não sustentou `meal_unit_cost`. A projeção financeira continua indisponível.");
      }
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar valor unitário da refeição.");
    } finally {
      setSavingMealCost(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <UtensilsCrossed size={22} className="text-brand" /> Meals Control
          </h1>
          <p className="text-sm text-gray-400 mt-1">
            Baixa por QR e monitoramento de saldo operacional de refeições.
          </p>
        </div>
        <button
          className="btn-secondary flex items-center gap-2"
          onClick={handleRefresh}
          disabled={loading || !eventId}
        >
          <RefreshCw size={16} className={loading ? "animate-spin" : ""} /> Atualizar
        </button>
        <button
          className="btn-secondary flex items-center gap-2"
          onClick={() => {
            setMealCostDraft(mealUnitCost);
            setMealCostModalOpen(true);
          }}
        >
          <Settings2 size={16} /> Valor Refeição
        </button>
      </div>

      {notices.length > 0 && (
        <div className="space-y-3">
          {notices.map((notice) => (
            <div
              key={`${notice.title}-${notice.body}`}
              className={`card border p-4 ${bannerClassByTone[notice.tone] || bannerClassByTone.neutral}`}
            >
              <p className="text-sm font-semibold">{notice.title}</p>
              <p className="text-sm mt-1 opacity-90">{notice.body}</p>
            </div>
          ))}
        </div>
      )}

      <div className="card p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <select className="input" value={eventId} onChange={(e) => setEventId(e.target.value)}>
          <option value="">Selecione o evento...</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.name}
            </option>
          ))}
        </select>

        <select
          className="input"
          value={eventDayId}
          onChange={(e) => setEventDayId(e.target.value)}
          disabled={!eventId || showWorkforceFallback}
        >
          <option value="">
            {showWorkforceFallback
              ? "Evento sem dias operacionais"
              : "Selecione o dia..."}
          </option>
          {eventDays.map((d) => (
            <option key={d.id} value={d.id}>
              {d.date}
            </option>
          ))}
        </select>

        <select
          className="input"
          value={eventShiftId}
          onChange={(e) => setEventShiftId(e.target.value)}
          disabled={!eventId || showWorkforceFallback || !eventDayId}
        >
          <option value="">
            {showWorkforceFallback
              ? "Turnos indisponíveis sem dias operacionais"
              : !eventDayId
                ? "Selecione um dia primeiro"
                : filteredShifts.length > 0
                  ? "Todos os turnos"
                  : "Dia sem turnos cadastrados"}
          </option>
          {filteredShifts.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>

        <select className="input" value={sector} onChange={(e) => setSector(e.target.value)}>
          <option value="">Todos os setores</option>
          {availableSectors.map((sectorOption) => (
            <option key={sectorOption} value={sectorOption}>
              {sectorOption}
            </option>
          ))}
        </select>
      </div>

      <form onSubmit={handleRegisterMeal} className="card p-4">
        <div className="flex flex-col md:flex-row gap-3">
          <div className="relative flex-1">
            <QrCode size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" />
            <input
              className="input pl-9"
              placeholder="Cole o token QR (ou link /invite?token=...)"
              value={qrInput}
              onChange={(e) => setQrInput(e.target.value)}
              disabled={registering || !canRegisterMeal}
            />
          </div>
          <button className="btn-primary whitespace-nowrap" disabled={registering || !canRegisterMeal}>
            {registering ? "Registrando..." : "Registrar Refeição"}
          </button>
        </div>
        <p className="mt-3 text-xs text-gray-500">{registerMealMessage}</p>
      </form>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
        {operationalCards.map((card) => (
          <div key={card.label} className="card p-3 space-y-3">
            <div className="flex items-start justify-between gap-3">
              <p className="text-xs text-gray-500">{card.label}</p>
              <span className={card.badgeClassName}>{card.badge}</span>
            </div>
            <p className={`text-xl font-bold ${card.valueClassName}`}>{card.value}</p>
            <p className="text-xs text-gray-500">{card.helper}</p>
          </div>
        ))}
      </div>

      {canUseRealMeals && (
        <div className="card p-4 space-y-4">
          <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
              <p className="text-sm font-semibold text-white">Camada financeira complementar</p>
              <p className="mt-1 text-xs text-gray-500">
                Mantida visualmente secundaria e condicional ao schema e a configuracao real de `meal_unit_cost`.
              </p>
              {!projectionEnabled && (
                <p className="mt-2 text-xs text-gray-500">
                  {financialDiagnosticsIssues.includes("meal_unit_cost_schema_unavailable")
                    ? "A leitura operacional do Meals continua ativa. Apenas a projeção financeira está indisponível neste ambiente."
                    : financialDiagnosticsIssues.includes("meal_unit_cost_not_configured")
                      ? "A leitura operacional do Meals continua ativa. Apenas a projeção financeira segue zerada até configurar `meal_unit_cost`."
                      : "A leitura operacional do Meals continua ativa. Apenas a projeção financeira deste recorte está degradada."}
                </p>
              )}
            </div>
            <span className={projectionEnabled ? "badge badge-gray" : "badge badge-yellow"}>
              {projectionEnabled ? "Leitura condicional" : "Projeção indisponível"}
            </span>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
              <p className="text-xs text-gray-500">Valor unitário refeição</p>
              <p className="text-lg font-bold text-white">{projectionEnabled ? formatCurrency(currentMealUnitCost) : "Indisponível"}</p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
              <p className="text-xs text-gray-500">Custo estimado (dia)</p>
              <p className="text-lg font-bold text-emerald-400">
                {projectionEnabled ? formatCurrency(projectionSummary.estimated_day_cost_total) : "Indisponível"}
              </p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
              <p className="text-xs text-gray-500">Custo consumido (dia)</p>
              <p className="text-lg font-bold text-amber-400">
                {projectionEnabled ? formatCurrency(projectionSummary.consumed_day_cost_total) : "Indisponível"}
              </p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
              <p className="text-xs text-gray-500">Custo saldo (dia)</p>
              <p className="text-lg font-bold text-cyan-400">
                {projectionEnabled ? formatCurrency(projectionSummary.remaining_day_cost_total) : "Indisponível"}
              </p>
            </div>
          </div>
        </div>
      )}

      {operationalDiagnosticsIssues.length > 0 && (
        <div className="card p-4">
          <p className="text-sm font-semibold text-white">Leitura operacional do recorte</p>
          <ul className="mt-2 space-y-1 text-sm text-gray-300">
            {operationalDiagnosticsIssues.map((issue) => (
              <li key={issue}>- {getIssueLabel(issue)}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="card p-4 space-y-3">
        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <p className="text-sm font-semibold text-white">{readingContext.title}</p>
            <p className="mt-1 text-xs text-gray-500">{readingContext.body}</p>
          </div>
          <span className={readingContext.badgeClassName}>{readingContext.badge}</span>
        </div>
        <div className="flex flex-wrap gap-2">
          {!showWorkforceFallback && configBreakdown.members_using_default_fallback > 0 && (
            <span className="badge badge-yellow">
              Default {configBreakdown.members_using_default_fallback}
            </span>
          )}
          {!showWorkforceFallback && configBreakdown.members_with_ambiguous_baseline > 0 && (
            <span className="badge badge-red">
              Ambígua {configBreakdown.members_with_ambiguous_baseline}
            </span>
          )}
          {!showWorkforceFallback && tableHighlights.exhaustedMembers > 0 && (
            <span className="badge badge-red">
              Sem saldo {tableHighlights.exhaustedMembers}
            </span>
          )}
          {!showWorkforceFallback && tableHighlights.multiAssignmentMembers > 0 && (
            <span className="badge badge-gray">
              Multi-assignment {tableHighlights.multiAssignmentMembers}
            </span>
          )}
          {tableHighlights.withoutUniqueShiftMembers > 0 && (
            <span className="badge badge-yellow">
              Sem turno único {tableHighlights.withoutUniqueShiftMembers}
            </span>
          )}
        </div>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Pessoa</th>
              <th>Cota</th>
              <th>Leitura operacional</th>
              <th>Turno</th>
              <th>Base / QR</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={5} className="py-8 text-center">
                  <div className="spinner w-6 h-6 mx-auto" />
                </td>
              </tr>
            ) : (showWorkforceFallback ? filteredWorkforceItems.length === 0 : payload.items.length === 0) ? (
              <tr>
                <td colSpan={5} className="py-8 text-center text-sm text-gray-500">
                  {emptyTableMessage}
                </td>
              </tr>
            ) : (
              tableRows.map((row) => (
                <tr
                  key={row.key}
                  className={
                    row.hasAmbiguousBaseline
                      ? "bg-red-950/10"
                      : row.remainingDay !== null && row.remainingDay <= 0
                      ? "bg-red-950/10"
                      : !row.shiftId
                        ? "bg-amber-950/10"
                        : row.configSource === "default"
                          ? "bg-yellow-950/10"
                          : Number(row.consumedDay || 0) > 0
                            ? "bg-emerald-950/10"
                            : ""
                  }
                >
                  <td className="align-top">
                    <div className="space-y-2">
                      <div>
                        <p className="font-medium text-white">{row.name}</p>
                        <p className="text-xs text-gray-500">
                          {row.roleName} · {row.sector || "geral"}
                        </p>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        {!showWorkforceFallback && Number(row.assignmentsInScope || 0) > 1 && (
                          <span className="badge badge-gray">
                            {row.assignmentsInScope} assignments
                          </span>
                        )}
                        {!showWorkforceFallback && row.hasAmbiguousBaseline && (
                          <span className="badge badge-red">Baseline ambíguo</span>
                        )}
                        {!showWorkforceFallback && (row.hasMultipleRoles || row.hasMultipleSectors || row.hasMultipleShifts) && (
                          <span className="badge badge-gray">Contexto múltiplo</span>
                        )}
                        {!showWorkforceFallback && Number(row.consumedDay || 0) > 0 && (
                          <span className="badge badge-blue">Consumiu {row.consumedDay}</span>
                        )}
                        {!showWorkforceFallback && row.remainingDay !== null && row.remainingDay <= 0 && (
                          <span className="badge badge-red">Sem saldo</span>
                        )}
                        {!row.shiftId && (
                          <span className="badge badge-yellow">Sem turno</span>
                        )}
                      </div>
                    </div>
                  </td>
                  <td className="align-top">
                    <div className="space-y-2">
                      <p className="text-lg font-semibold text-white">{row.mealsPerDay ?? "N/D"}</p>
                      {!showWorkforceFallback && row.configSource ? (
                        <>
                          <span className={getConfigSourceBadgeClass(row.configSource)}>
                            {getConfigSourceBadgeLabel(row.configSource)}
                          </span>
                          <p className="text-[11px] text-gray-500">
                            {row.hasAmbiguousBaseline
                              ? "Conflito real entre assignments/cargos neste recorte."
                              : getConfigSourceLabel(row.configSource)}
                          </p>
                        </>
                      ) : (
                        <p className="text-[11px] text-gray-500">
                          Configuracao resolvida no Workforce.
                        </p>
                      )}
                    </div>
                  </td>
                  <td className="align-top">
                    {showWorkforceFallback ? (
                      <div className="space-y-1 text-sm">
                        <p className="font-semibold text-gray-200">Sem saldo real do Meals</p>
                        <p className="text-xs text-gray-500">
                          A leitura atual mostra apenas a cota configurada no Workforce.
                        </p>
                      </div>
                    ) : (
                      <div className="space-y-1 text-sm">
                        <p className={`font-semibold ${
                          row.remainingDay === null
                            ? "text-red-400"
                            : row.remainingDay <= 0
                              ? "text-red-400"
                              : "text-green-400"
                        }`}>
                          {row.remainingDay === null ? "Saldo derivado indisponível" : `Saldo restante: ${row.remainingDay}`}
                        </p>
                        {row.mealsPerDay !== null ? (
                          <p className="text-xs text-gray-400">Permitidas: {row.mealsPerDay}</p>
                        ) : (
                          <p className="text-xs text-gray-400">Cota não confiável neste recorte.</p>
                        )}
                        <p className="text-xs text-gray-400">Consumidas dia: {row.consumedDay}</p>
                        <p className="text-xs text-gray-400">
                          {hasSelectedShift
                            ? `Consumidas turno: ${row.consumedShift}`
                            : `Consumo no recorte: ${row.consumedShift} (agregado do dia)`}
                        </p>
                      </div>
                    )}
                  </td>
                  <td className="align-top">
                    <div className="space-y-2">
                      <span className={row.shiftId ? "badge badge-blue" : row.hasMultipleShifts ? "badge badge-yellow" : "badge badge-red"}>
                        {row.shiftId
                          ? (row.shiftName || `Turno #${row.shiftId}`)
                          : row.hasMultipleShifts
                            ? "Turnos múltiplos"
                            : "Sem vínculo"}
                      </span>
                      <p className="text-xs text-gray-500">
                        {row.shiftId
                          ? "Turno complementar unívoco visível na base do Workforce."
                          : row.hasMultipleShifts
                            ? "O participante possui mais de um contexto de turno no Workforce; o saldo continua consolidado por pessoa."
                            : "Ausencia de turno mantida explicita na leitura operacional."}
                      </p>
                    </div>
                  </td>
                  <td className="align-top">
                    <div className="space-y-2">
                      <span className={row.sourceBadgeClass}>{row.sourceLabel}</span>
                      <p className="text-xs text-gray-500">{row.sourceDescription}</p>
                      {!showWorkforceFallback && (
                        <p className="text-xs text-gray-500">
                          Assignments no recorte: {row.assignmentsInScope}
                        </p>
                      )}
                      <p className="text-xs text-gray-500">
                        QR: {(row.qrToken || "").slice(-10) || "-"}
                      </p>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {mealCostModalOpen && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md overflow-hidden">
            <div className="p-4 border-b border-gray-800 flex items-center justify-between">
              <div>
                <h3 className="text-white font-bold">Configuração de Refeições</h3>
                <p className="text-xs text-gray-500 mt-1">Defina o valor unitário para custo operacional.</p>
              </div>
              <button onClick={() => setMealCostModalOpen(false)} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
                <X size={18} />
              </button>
            </div>

            <form onSubmit={saveMealCost} className="p-5 space-y-4">
              <label className="text-xs text-gray-400 block">
                Valor unitário da refeição (R$)
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  className="input mt-1 w-full"
                  value={mealCostDraft}
                  onChange={(e) => setMealCostDraft(Number(e.target.value))}
                  disabled={mealUnitCostAvailable === false || savingMealCost}
                />
              </label>
              {mealUnitCostAvailable === false && (
                <p className="text-xs text-amber-300">
                  Este ambiente não possui a coluna `meal_unit_cost` em `organizer_financial_settings`.
                  O ajuste fica bloqueado ate a migration/readiness correta da base.
                </p>
              )}
              <p className="text-xs text-gray-500">
                O consumo total é calculado automaticamente com base em turnos e refeições configurados no Workforce.
              </p>

              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setMealCostModalOpen(false)} className="btn-secondary">
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={savingMealCost || mealUnitCostAvailable === false}
                  className="btn-primary flex items-center gap-2"
                >
                  <Save size={16} /> Salvar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
