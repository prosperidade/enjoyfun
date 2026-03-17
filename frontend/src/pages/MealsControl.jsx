import { useEffect, useMemo, useRef, useState } from "react";
import { Copy, QrCode, UtensilsCrossed, RefreshCw, Settings2, Save, X } from "lucide-react";
import toast from "react-hot-toast";
import api from "../lib/api";
import { db } from "../lib/db";

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
    mealServices: [],
    selectedMealService: null,
  };
}

function createOfflineId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `meal-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function formatCurrency(value) {
  return `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`;
}

function formatEventDayLabel(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }
  return date.toLocaleDateString("pt-BR");
}

function buildSyntheticEventDayOption(event) {
  if (!event?.id || !event?.starts_at) return null;
  const startLabel = formatEventDayLabel(event.starts_at);
  const endLabel = event.ends_at ? formatEventDayLabel(event.ends_at) : "";
  const suffix = endLabel && endLabel !== startLabel ? ` até ${endLabel}` : "";
  return {
    id: `event:${event.id}`,
    date: `${startLabel}${suffix} (dia-base do evento)`,
    synthetic: true,
  };
}

function buildInviteLink(token) {
  if (!token) return "";
  const path = `/invite?token=${encodeURIComponent(token)}`;
  if (typeof window === "undefined") return path;
  return `${window.location.origin}${path}`;
}

function formatSectorLabel(value) {
  const normalized = String(value || "").trim();
  if (!normalized) return "sem setor";
  return normalized.replace(/_/g, " ");
}

function formatMealServiceLabel(service) {
  if (!service) return "Sem refeição";
  const label = String(service.label || service.service_code || "Refeição").trim();
  const startsAt = String(service.starts_at || "").slice(0, 5);
  const endsAt = String(service.ends_at || "").slice(0, 5);
  const timeLabel = startsAt && endsAt ? ` • ${startsAt} - ${endsAt}` : "";
  return `${label}${timeLabel}`;
}

function buildMealsContextKey(eventId) {
  return `event:${eventId}`;
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
    event_has_no_meal_services: "O evento ainda não possui serviços de refeição ativos configurados.",
    meal_unit_cost_schema_unavailable: "O custo de refeição está indisponível neste ambiente.",
    meal_unit_cost_not_configured: "O custo de refeição ainda não foi configurado.",
  };
  return labels[code] || code;
}

function getConfigSourceLabel(source) {
  const labels = {
    member_override: "Config. do membro",
    role_settings: "Config. do cargo",
    default: "Cota padrão (4 ref/dia)",
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
    default: "Cota padrão",
    ambiguous: "Ambígua",
  };
  return labels[source] || "Sem origem";
}

export default function MealsControl() {
  const [events, setEvents] = useState([]);
  const [eventDays, setEventDays] = useState([]);
  const [eventShifts, setEventShifts] = useState([]);
  const [mealServices, setMealServices] = useState([]);

  const [eventId, setEventId] = useState("");
  const [eventDayId, setEventDayId] = useState("");
  const [eventShiftId, setEventShiftId] = useState("");
  const [mealServiceId, setMealServiceId] = useState("");
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
  const [mealServiceDrafts, setMealServiceDrafts] = useState([]);
  const [savingMealCost, setSavingMealCost] = useState(false);
  const [generatingStandaloneQrId, setGeneratingStandaloneQrId] = useState(null);
  const [externalQrForm, setExternalQrForm] = useState({ name: "", phone: "", meals_per_day: 4 });
  const [generatingExternalQr, setGeneratingExternalQr] = useState(false);
  const [generatedExternalQrs, setGeneratedExternalQrs] = useState([]);
  const selectedEventRef = useRef("");
  const staticDataRequestRef = useRef(0);
  const workforceRequestRef = useRef(0);
  const balanceRequestRef = useRef(0);
  const eventDetailRequestRef = useRef(0);

  const filteredShifts = useMemo(() => {
    if (!eventDayId) return [];
    return eventShifts.filter((s) => String(s.event_day_id) === String(eventDayId));
  }, [eventShifts, eventDayId]);
  const selectedEvent = useMemo(
    () => events.find((event) => String(event.id) === String(eventId)) || null,
    [eventId, events]
  );
  const syntheticEventDayOption = useMemo(
    () => buildSyntheticEventDayOption(selectedEvent),
    [selectedEvent]
  );
  const selectedMealService = useMemo(
    () => mealServices.find((service) => String(service.id) === String(mealServiceId)) || null,
    [mealServiceId, mealServices]
  );

  const loadCachedMealsContext = async (cacheKey) => {
    try {
      return await db.mealsContext.get(cacheKey);
    } catch {
      return null;
    }
  };

  const saveCachedMealsContext = async (cacheKey, patch) => {
    try {
      const current = (await db.mealsContext.get(cacheKey)) || { cache_key: cacheKey };
      await db.mealsContext.put({
        ...current,
        ...patch,
        cache_key: cacheKey,
        updated_at: new Date().toISOString(),
      });
    } catch {
      // cache offline é melhor esforço
    }
  };

  const loadEvents = async () => {
    try {
      const res = await api.get("/events");
      const list = res.data?.data || [];
      setEvents(list);
      await saveCachedMealsContext("events:list", { items: list });
      if (!eventId && list.length > 0) {
        const now = new Date();
        const inProgress = list.find((ev) => {
          if (!ev.starts_at || !ev.ends_at) return false;
          return new Date(ev.starts_at) <= now && new Date(ev.ends_at) >= now;
        });
        setEventId(String(inProgress ? inProgress.id : list[0].id));
      }
      return list;
    } catch (err) {
      const cached = await loadCachedMealsContext("events:list");
      const cachedList = Array.isArray(cached?.items) ? cached.items : [];
      if (cachedList.length > 0) {
        setEvents(cachedList);
        if (!eventId) {
          setEventId(String(cachedList[0].id));
        }
        toast("Eventos carregados do cache local do Meals.", { id: "meals-events-cache" });
        return cachedList;
      }
      throw err;
    }
  };

  const loadEventSnapshot = async (evtId) => {
    if (!evtId) return null;

    const normalizedEventId = String(evtId);
    const requestId = eventDetailRequestRef.current + 1;
    eventDetailRequestRef.current = requestId;

    try {
      const res = await api.get(`/events/${evtId}`);
      if (requestId !== eventDetailRequestRef.current || selectedEventRef.current !== normalizedEventId) {
        return null;
      }

      const eventData = res.data?.data || null;
      if (!eventData?.id) {
        return null;
      }

      setEvents((current) => {
        const next = [...current];
        const index = next.findIndex((event) => String(event.id) === String(eventData.id));
        if (index >= 0) {
          next[index] = { ...next[index], ...eventData };
        } else {
          next.push(eventData);
        }
        return next;
      });

      return eventData;
    } catch {
      return null;
    }
  };

  const loadStaticData = async (evtId) => {
    if (!evtId) return;
    const normalizedEventId = String(evtId);
    const cacheKey = buildMealsContextKey(evtId);
    const requestId = staticDataRequestRef.current + 1;
    staticDataRequestRef.current = requestId;
    let days = [];
    let shifts = [];
    try {
      const [daysRes, shiftsRes] = await Promise.all([
        api.get(`/event-days?event_id=${evtId}`),
        api.get(`/event-shifts?event_id=${evtId}`),
      ]);
      days = daysRes.data?.data || [];
      shifts = shiftsRes.data?.data || [];
      await saveCachedMealsContext(cacheKey, {
        eventDays: days,
        eventShifts: shifts,
      });
    } catch (err) {
      const cached = await loadCachedMealsContext(cacheKey);
      days = Array.isArray(cached?.eventDays) ? cached.eventDays : [];
      shifts = Array.isArray(cached?.eventShifts) ? cached.eventShifts : [];
      if (days.length === 0 && shifts.length === 0) {
        throw err;
      }
      toast("Dias, turnos e contexto operacional carregados do cache local.", { id: "meals-static-cache" });
    }
    if (requestId !== staticDataRequestRef.current || selectedEventRef.current !== normalizedEventId) {
      return { days: [], shifts: [], nextDay: "", nextShift: "", stale: true };
    }
    const nextDay = days.some((day) => String(day.id) === String(eventDayId))
      ? String(eventDayId)
      : (days[0] ? String(days[0].id) : "");
    const nextShift = nextDay && shifts.some(
      (shift) =>
        String(shift.event_day_id) === String(nextDay) &&
        String(shift.id) === String(eventShiftId)
    )
      ? String(eventShiftId)
      : "";
    setEventDays(days);
    setEventShifts(shifts);
    setPayload(createEmptyPayload());
    setEventDayId(nextDay);
    setEventShiftId(nextShift);
    return { days, shifts, nextDay, nextShift, stale: false };
  };

  const loadWorkforceBase = async (evtId) => {
    if (!evtId) {
      setWorkforceBaseItems([]);
      return;
    }

    const normalizedEventId = String(evtId);
    const cacheKey = buildMealsContextKey(evtId);
    const requestId = workforceRequestRef.current + 1;
    workforceRequestRef.current = requestId;

    try {
      const res = await api.get("/workforce/assignments", {
        params: { event_id: evtId },
      });
      if (requestId !== workforceRequestRef.current || selectedEventRef.current !== normalizedEventId) {
        return;
      }
      const rawItems = res.data?.data || [];
      const operationalItems = rawItems.filter(item => item.cost_bucket !== 'managerial');
      setWorkforceBaseItems(operationalItems);
      await saveCachedMealsContext(cacheKey, { workforceBaseItems: operationalItems });
    } catch (err) {
      if (requestId !== workforceRequestRef.current || selectedEventRef.current !== normalizedEventId) {
        return;
      }
      const cached = await loadCachedMealsContext(cacheKey);
      const cachedItems = Array.isArray(cached?.workforceBaseItems) ? cached.workforceBaseItems : [];
      if (cachedItems.length > 0) {
        setWorkforceBaseItems(cachedItems);
        toast("Base do Workforce carregada do cache local do Meals.", { id: "meals-workforce-cache" });
        return;
      }
      setWorkforceBaseItems([]);
      toast.error(err.response?.data?.message || "Erro ao carregar base do workforce para Meals.");
    }
  };

  const loadMealServices = async (evtId) => {
    if (!evtId) {
      setMealServices([]);
      setMealServiceDrafts([]);
      setMealServiceId("");
      return [];
    }

    const cacheKey = buildMealsContextKey(evtId);
    try {
      const res = await api.get("/meals/services", {
        params: { event_id: evtId },
      });
      const services = res.data?.data?.services || [];
      setMealServices(services);
      setMealServiceDrafts(services);
      setMealServiceId((current) =>
        services.some((service) => String(service.id) === String(current))
          ? current
          : (services[0] ? String(services[0].id) : "")
      );
      await saveCachedMealsContext(cacheKey, { mealServices: services });
      return services;
    } catch (err) {
      const cached = await loadCachedMealsContext(cacheKey);
      const services = Array.isArray(cached?.mealServices) ? cached.mealServices : [];
      if (services.length > 0) {
        setMealServices(services);
        setMealServiceDrafts(services);
        setMealServiceId((current) =>
          services.some((service) => String(service.id) === String(current))
            ? current
            : (services[0] ? String(services[0].id) : "")
        );
        toast("Serviços de refeição carregados do cache local.", { id: "meals-services-cache" });
        return services;
      }
      throw err;
    }
  };

  const loadBalance = async (overrides = {}) => {
    const targetEventId = String(overrides.eventId ?? eventId ?? "");
    const targetEventDayId = String(overrides.eventDayId ?? eventDayId ?? "");
    const targetEventShiftId = String(overrides.eventShiftId ?? eventShiftId ?? "");
    const targetMealServiceId = String(overrides.mealServiceId ?? mealServiceId ?? "");
    const targetSector = String(overrides.sector ?? sector ?? "").trim();

    if (!targetEventId || !targetEventDayId) {
      setPayload(createEmptyPayload());
      return;
    }

    const requestId = balanceRequestRef.current + 1;
    balanceRequestRef.current = requestId;
    setLoading(true);
    try {
      const res = await api.get("/meals/balance", {
        params: {
          event_id: targetEventId,
          event_day_id: targetEventDayId,
          event_shift_id: targetEventShiftId || undefined,
          meal_service_id: targetMealServiceId || undefined,
          sector: targetSector || undefined,
        },
      });
      if (requestId !== balanceRequestRef.current || selectedEventRef.current !== targetEventId) {
        return;
      }
      const data = res.data?.data || {};
      const nextPayload = {
        summary: data.summary || null,
        items: data.items || [],
        operationalSummary: data.operational_summary || null,
        projectionSummary: data.projection_summary || null,
        diagnostics: data.diagnostics || null,
        mealServices: data.meal_services || [],
        selectedMealService: data.selected_meal_service || null,
      };
      setPayload(nextPayload);
      if (Array.isArray(data.meal_services) && data.meal_services.length > 0) {
        setMealServices(data.meal_services);
        setMealServiceDrafts(data.meal_services);
      }
      if (data.selected_meal_service?.id) {
        setMealServiceId(String(data.selected_meal_service.id));
      }
      const unit = Number(
        data.projection_summary?.selected_meal_service_unit_cost ??
        data.projection_summary?.meal_unit_cost ??
        data.summary?.selected_meal_service_unit_cost ??
        data.summary?.meal_unit_cost ??
        0
      );
      setMealUnitCost(unit);
      await saveCachedMealsContext(buildMealsContextKey(targetEventId), {
        mealServices: Array.isArray(data.meal_services) ? data.meal_services : mealServices,
        balances: {
          [`${targetEventDayId}:${targetMealServiceId || "all"}:${targetEventShiftId || "all"}:${targetSector || "all"}`]: nextPayload,
        },
      });
    } catch (err) {
      if (requestId !== balanceRequestRef.current || selectedEventRef.current !== targetEventId) {
        return;
      }
      const cached = await loadCachedMealsContext(buildMealsContextKey(targetEventId));
      const cacheKey = `${targetEventDayId}:${targetMealServiceId || "all"}:${targetEventShiftId || "all"}:${targetSector || "all"}`;
      const cachedPayload = cached?.balances?.[cacheKey];
      if (cachedPayload) {
        setPayload({
          ...createEmptyPayload(),
          ...cachedPayload,
        });
        if (Array.isArray(cached?.mealServices)) {
          setMealServices(cached.mealServices);
          setMealServiceDrafts(cached.mealServices);
        }
        toast("Saldo Meals carregado do cache local.", { id: "meals-balance-cache" });
      } else {
        setPayload(createEmptyPayload());
        toast.error(err.response?.data?.message || "Erro ao carregar saldo de refeições.");
      }
    } finally {
      if (requestId === balanceRequestRef.current) {
        setLoading(false);
      }
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
    } catch {
      setMealUnitCost(0);
      setMealUnitCostAvailable(null);
    }
  };

  useEffect(() => {
    selectedEventRef.current = String(eventId || "");
  }, [eventId]);

  useEffect(() => {
    loadEvents().catch(() => toast.error("Erro ao carregar eventos."));
    loadMealUnitCost();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    staticDataRequestRef.current += 1;
    workforceRequestRef.current += 1;
    balanceRequestRef.current += 1;
    eventDetailRequestRef.current += 1;
    setEventDayId("");
    setEventShiftId("");
    setMealServiceId("");
    setEventDays([]);
    setEventShifts([]);
    setMealServices([]);
    setMealServiceDrafts([]);
    setWorkforceBaseItems([]);
    setPayload(createEmptyPayload());
  }, [eventId]);

  useEffect(() => {
    if (!eventId) return;
    loadEventSnapshot(eventId);
    loadStaticData(eventId).catch((err) =>
      toast.error(err.response?.data?.message || "Erro ao carregar dias/turnos.")
    );
    loadWorkforceBase(eventId);
    loadMealServices(eventId).catch((err) =>
      toast.error(err.response?.data?.message || "Erro ao carregar serviços de refeição.")
    );
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
    if (mealServices.length === 0) {
      setMealServiceId("");
      return;
    }
    const valid = mealServices.some((service) => String(service.id) === String(mealServiceId));
    if (!valid) {
      const firstActive = mealServices.find((service) => service.is_active !== false) || mealServices[0];
      setMealServiceId(firstActive ? String(firstActive.id) : "");
    }
  }, [mealServiceId, mealServices]);

  useEffect(() => {
    if (!eventId || eventDays.length > 0 || !syntheticEventDayOption) {
      return;
    }

    if (String(eventDayId) !== String(syntheticEventDayOption.id)) {
      setEventDayId(String(syntheticEventDayOption.id));
    }
  }, [eventDayId, eventDays.length, eventId, syntheticEventDayOption]);

  useEffect(() => {
    if (!eventId || !eventDayId) {
      setPayload(createEmptyPayload());
      return;
    }
    if (eventDays.length <= 0) {
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
  }, [eventId, eventDayId, eventShiftId, mealServiceId, sector, eventDays]);

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

  const standaloneQrMembers = useMemo(() => {
    const grouped = new Map();

    workforceBaseItems.forEach((item) => {
      const participantId = Number(item.participant_id || 0);
      if (participantId <= 0) return;

      const existing = grouped.get(participantId) || {
        participantId,
        name: item.person_name || item.name || `Participante #${participantId}`,
        phone: item.phone || "",
        email: item.person_email || item.email || "",
        qrToken: item.qr_token || "",
        roles: new Set(),
        assignmentRows: 0,
        hasDefinedSector: false,
        hasOperationalAssignment: false,
        hasNonOperationalAssignment: false,
      };

      existing.assignmentRows += 1;
      if (item.role_name) {
        existing.roles.add(String(item.role_name));
      }
      if (String(item.sector || "").trim() !== "") {
        existing.hasDefinedSector = true;
      }

      const costBucket = String(item.cost_bucket || "operational").trim().toLowerCase();
      if (costBucket === "operational") {
        existing.hasOperationalAssignment = true;
      } else {
        existing.hasNonOperationalAssignment = true;
      }

      if (!existing.qrToken && item.qr_token) {
        existing.qrToken = item.qr_token;
      }

      grouped.set(participantId, existing);
    });

    return [...grouped.values()]
      .filter(
        (member) =>
          member.hasOperationalAssignment &&
          !member.hasNonOperationalAssignment &&
          !member.hasDefinedSector
      )
      .map((member) => ({
        ...member,
        roles: [...member.roles].filter(Boolean),
      }))
      .sort((a, b) => a.name.localeCompare(b.name, "pt-BR"));
  }, [workforceBaseItems]);

  const enqueueOfflineMeal = async (recordPayload) => {
    const pendingMeals = await db.offlineQueue.where("status").equals("pending").toArray();
    const duplicatedPending = pendingMeals.some((record) => {
      const payloadItem = record?.payload ?? {};
      return (
        (record?.payload_type ?? record?.type) === "meal" &&
        String(payloadItem.qr_token || "").trim() === String(recordPayload.qr_token || "").trim() &&
        String(payloadItem.event_day_id || "") === String(recordPayload.event_day_id || "") &&
        String(payloadItem.meal_service_id || "") === String(recordPayload.meal_service_id || "")
      );
    });

    if (duplicatedPending) {
      throw new Error("Esta refeição já está pendente de sincronização offline para este QR e este serviço.");
    }

    const offlineId = createOfflineId();
    await db.offlineQueue.put({
      offline_id: offlineId,
      payload_type: "meal",
      payload: recordPayload,
      status: "pending",
      created_offline_at: new Date().toISOString(),
      sector: recordPayload.sector || null,
    });
    return offlineId;
  };

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
    if (!mealServiceId) {
      toast.error("Selecione a refeição a validar.");
      return;
    }

    const requestPayload = {
      event_id: Number(eventId),
      qr_token: token,
      event_day_id: Number(eventDayId),
      event_shift_id: eventShiftId ? Number(eventShiftId) : null,
      meal_service_id: Number(mealServiceId),
      meal_service_code: selectedMealService?.service_code || null,
      sector: sector || null,
      consumed_at: new Date().toISOString(),
    };

    setRegistering(true);
    try {
      if (!navigator.onLine) {
        await enqueueOfflineMeal(requestPayload);
        toast.success("Refeição salva na fila offline do Meals.");
      } else {
        await api.post("/meals", requestPayload);
        toast.success("Refeição registrada com sucesso.");
      }
      setQrInput("");
      await loadBalance();
    } catch (err) {
      const networkError = !navigator.onLine || err?.code === "ERR_NETWORK";
      if (networkError) {
        try {
          await enqueueOfflineMeal(requestPayload);
          setQrInput("");
          toast.success("Sem rede: refeição enviada para sincronização offline.");
          return;
        } catch (offlineErr) {
          toast.error(offlineErr.message || "Falha ao registrar refeição offline.");
          return;
        }
      }
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
    consumed_service_total: 0,
    meal_unit_cost: mealUnitCost,
    selected_meal_service_unit_cost: mealUnitCost,
    estimated_day_cost_total: 0,
    consumed_day_cost_total: 0,
    remaining_day_cost_total: 0,
    selected_service_estimated_cost_total: 0,
    selected_service_consumed_cost_total: 0,
  };
  const operationalSummary = payload.operationalSummary || {
    members: summary.members,
    meals_per_day_total: summary.meals_per_day_total,
    consumed_day_total: summary.consumed_day_total,
    remaining_day_total: summary.remaining_day_total,
    consumed_service_total: summary.consumed_service_total ?? summary.consumed_shift_total ?? 0,
  };
  const projectionSummary = payload.projectionSummary || {
    enabled: false,
    meal_unit_cost: 0,
    selected_meal_service_unit_cost: 0,
    estimated_day_cost_total: 0,
    consumed_day_cost_total: 0,
    remaining_day_cost_total: 0,
    selected_service_estimated_cost_total: 0,
    selected_service_consumed_cost_total: 0,
  };
  const diagnostics = payload.diagnostics || null;

  const currentMealUnitCost = Number(
    projectionSummary.selected_meal_service_unit_cost ??
    projectionSummary.meal_unit_cost ??
    summary.selected_meal_service_unit_cost ??
    summary.meal_unit_cost ??
    mealUnitCost ??
    0
  );
  const projectionEnabled = Boolean(projectionSummary.enabled);
  const hasSelectedShift = Boolean(eventShiftId);
  const selectedMealServiceData = payload.selectedMealService || selectedMealService || null;
  const selectedMealServiceLabel = formatMealServiceLabel(selectedMealServiceData);
  const hasConfiguredEventDays = eventDays.length > 0;
  const availableEventDayOptions = useMemo(() => {
    if (eventDays.length > 0) {
      return eventDays.map((day) => ({
        id: String(day.id),
        date: day.date,
        synthetic: false,
      }));
    }
    return syntheticEventDayOption ? [syntheticEventDayOption] : [];
  }, [eventDays, syntheticEventDayOption]);
  const displayedEventDayId = hasConfiguredEventDays
    ? eventDayId
    : String(syntheticEventDayOption?.id || "");
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
  const canRegisterMeal = canUseRealMeals && Boolean(mealServiceId);
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
      if (syntheticEventDayOption) {
        list.push({
          tone: "info",
          title: "Configuração atual do evento refletida no dia",
          body: `O seletor está usando ${syntheticEventDayOption.date} a partir de \`starts_at/ends_at\` do evento. O saldo real continua bloqueado até existir \`event_day\` real.`,
        });
      }
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
        body: "Selecione um dia operacional do evento para habilitar o saldo real de Meals, a refeição do momento e o registro por QR.",
      });
      return list;
    }

    if (!mealServiceId) {
      list.push({
        tone: "warn",
        title: "Refeição não selecionada",
        body: "Escolha o serviço de refeição do momento para impedir repetição da mesma baixa no mesmo dia.",
      });
    }

    if (filteredShifts.length === 0) {
      list.push({
        tone: "info",
        title: "Dia sem turnos cadastrados",
        body: "O turno segue opcional e complementar ao Workforce. A baixa principal do Meals agora depende da refeição escolhida.",
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
        title: "Equipe com cota padrão",
        body: "Parte da equipe não tem cota específica configurada nos cargos e está usando a cota padrão (4 refeições/dia).",
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
    mealServiceId,
    showWorkforceFallback,
    operationalDiagnosticsIssues,
    syntheticEventDayOption,
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
        label: "Consumidas refeição",
        value: operationalSummary.consumed_service_total,
        valueClassName: "text-blue-400",
        helper: `${selectedMealServiceLabel}.${hasSelectedShift ? " Turno aplicado como filtro complementar." : " Sem turno, o recorte continua diário."}`,
        badge: hasSelectedShift ? "Refeição + turno" : "Refeição ativa",
        badgeClassName: hasSelectedShift ? "badge badge-blue" : "badge badge-gray",
      },
    ];
  }, [
    configBreakdown.members_using_default_fallback,
    configBreakdown.members_with_ambiguous_baseline,
    hasSelectedShift,
    operationalSummary.consumed_day_total,
    operationalSummary.consumed_service_total,
    operationalSummary.meals_per_day_total,
    operationalSummary.members,
    operationalSummary.remaining_day_total,
    selectedMealServiceLabel,
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
            body: `A tela está lendo saldo e consumo reais do dia por serviço de refeição. Serviço selecionado: ${selectedMealServiceLabel}.${hasSelectedShift ? " O turno continua como filtro complementar do Workforce." : ""}`,
            badge: "Saldo real Meals",
            badgeClassName: "badge badge-green",
          };

  const registerMealMessage = showWorkforceFallback
    ? "Registro de refeição indisponível: este evento ainda não possui `event_days`, então o módulo permanece em modo complementar do Workforce."
    : !eventDayId
      ? "Selecione um dia operacional para habilitar o registro de refeição."
      : !mealServiceId
        ? "Selecione a refeição do momento para habilitar o registro e bloquear repetição indevida."
        : hasSelectedShift
          ? sector
            ? `Registro ativo para ${selectedMealServiceLabel}, turno e setor selecionados.`
            : `Registro ativo para ${selectedMealServiceLabel} com turno selecionado.`
          : sector
            ? `Registro ativo para ${selectedMealServiceLabel} e setor selecionados. O turno permanece opcional.`
            : `Registro ativo para ${selectedMealServiceLabel}. O turno permanece opcional.`;

  const handleRefresh = async () => {
    if (!eventId) return;
    await loadEvents();
    await loadEventSnapshot(eventId);
    const staticData = await loadStaticData(eventId);
    await loadWorkforceBase(eventId);
    await loadMealServices(eventId);

    if (!staticData?.stale && staticData?.nextDay) {
      await loadBalance({
        eventId,
        eventDayId: staticData.nextDay,
        eventShiftId: staticData.nextShift,
        mealServiceId,
        sector,
      });
    }
  };

  const syncParticipantQrToken = (participantId, qrToken) => {
    setWorkforceBaseItems((current) =>
      current.map((item) =>
        Number(item.participant_id || 0) === Number(participantId)
          ? { ...item, qr_token: qrToken }
          : item
      )
    );
    setPayload((current) => ({
      ...current,
      items: (current.items || []).map((item) =>
        Number(item.participant_id || 0) === Number(participantId)
          ? { ...item, qr_token: qrToken }
          : item
      ),
    }));
  };

  const handleCopyStandaloneInvite = async (token) => {
    const inviteLink = buildInviteLink(token);
    if (!inviteLink) {
      toast.error("Nenhum QR disponível para copiar.");
      return;
    }

    try {
      if (navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(inviteLink);
        toast.success("Link do QR copiado.");
        return;
      }
    } catch {
      // segue para fallback abaixo
    }

    if (typeof window !== "undefined") {
      window.prompt("Copie o link do QR:", inviteLink);
    }
  };

  const handleOpenStandaloneInvite = (token) => {
    const inviteLink = buildInviteLink(token);
    if (!inviteLink) {
      toast.error("Nenhum QR disponível para abrir.");
      return;
    }

    if (typeof window !== "undefined") {
      window.open(inviteLink, "_blank", "noopener,noreferrer");
    }
  };

  const handleGenerateStandaloneQr = async (member) => {
    setGeneratingStandaloneQrId(member.participantId);
    try {
      const res = await api.post("/meals/standalone-qrs", {
        participant_id: member.participantId,
      });
      const data = res.data?.data || {};
      const qrToken = String(data.qr_token || "").trim();
      if (!qrToken) {
        toast.error("O backend não retornou um QR válido.");
        return "";
      }

      syncParticipantQrToken(member.participantId, qrToken);
      toast.success(data.created_now ? "QR avulso gerado." : "QR avulso confirmado.");
      return qrToken;
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao gerar QR avulso do Meals.");
      return "";
    } finally {
      setGeneratingStandaloneQrId(null);
    }
  };

  const bannerClassByTone = {
    neutral: "border-gray-800 bg-gray-900/70 text-gray-200",
    info: "border-blue-900/60 bg-blue-950/30 text-blue-100",
    warn: "border-amber-900/60 bg-amber-950/30 text-amber-100",
  };

  const saveMealServices = async (e) => {
    e.preventDefault();
    if (!eventId) return;
    setSavingMealCost(true);
    try {
      const res = await api.put("/meals/services", {
        event_id: Number(eventId),
        services: mealServiceDrafts,
      });
      const saved = res.data?.data?.services || [];
      setMealServices(saved);
      setMealServiceDrafts(saved);
      setMealCostModalOpen(false);
      toast.success("Configuração de refeições salva.");
      await loadBalance();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao salvar configuração de refeições.");
    } finally {
      setSavingMealCost(false);
    }
  };

  const handleGenerateExternalQr = async (e) => {
    e.preventDefault();
    if (!eventId) { toast.error("Selecione um evento."); return; }
    if (!externalQrForm.name.trim()) { toast.error("Informe o nome do colaborador."); return; }
    setGeneratingExternalQr(true);
    try {
      const res = await api.post("/meals/external-qr", {
        event_id: Number(eventId),
        name: externalQrForm.name.trim(),
        phone: externalQrForm.phone.trim(),
        meals_per_day: Number(externalQrForm.meals_per_day) || 4,
        valid_days: Number(externalQrForm.valid_days) || 1,
      });
      const data = res.data?.data || {};
      setGeneratedExternalQrs((prev) => [
        { ...data, created_at: new Date().toISOString() },
        ...prev,
      ]);
      setExternalQrForm({ name: "", phone: "", meals_per_day: 4, valid_days: 1 });
      toast.success("QR do colaborador externo gerado!");
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao gerar QR externo.");
    } finally {
      setGeneratingExternalQr(false);
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
            setMealServiceDrafts(mealServices.length > 0 ? mealServices : []);
            setMealCostModalOpen(true);
          }}
          disabled={!eventId}
        >
          <Settings2 size={16} /> Configurar Refeições
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
          value={displayedEventDayId}
          onChange={(e) => setEventDayId(e.target.value)}
          disabled={!eventId || availableEventDayOptions.length <= 0}
        >
          <option value="">
            {showWorkforceFallback
              ? syntheticEventDayOption
                ? "Selecione o dia-base do evento"
                : "Evento sem dias operacionais"
              : "Selecione o dia..."}
          </option>
          {availableEventDayOptions.map((d) => (
            <option key={d.id} value={d.id}>
              {d.date}
            </option>
          ))}
        </select>

        {/* Seletor de refeição (principal) */}
        <select
          className="input"
          value={mealServiceId}
          onChange={(e) => setMealServiceId(e.target.value)}
          disabled={!eventId || !eventDayId || mealServices.length === 0}
        >
          <option value="">
            {!eventId
              ? "Selecione um evento"
              : !eventDayId
                ? "Selecione um dia"
                : mealServices.length === 0
                  ? "Sem serviços configurados"
                  : "Refeição atual..."}
          </option>
          {mealServices.map((svc) => (
            <option key={svc.id} value={svc.id} disabled={!svc.is_active}>
              {svc.label}{!svc.is_active ? " (inativa)" : ""}
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

      {eventId && (
        <div className="card p-4 space-y-4">
          <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
              <p className="text-sm font-semibold text-white">QR para colaborador externo</p>
              <p className="mt-1 text-xs text-gray-500">
                Gera um QR de refeição para colaboradores que não estão cadastrados no Workforce.
                O gerente informa nome + contato, o sistema cria o acesso e gera o link para enviar pelo WhatsApp.
              </p>
            </div>
            <span className="badge badge-blue">{generatedExternalQrs.length} gerado(s)</span>
          </div>

          <form onSubmit={handleGenerateExternalQr} className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <input
              className="input"
              placeholder="Nome completo *"
              value={externalQrForm.name}
              onChange={(e) => setExternalQrForm((f) => ({ ...f, name: e.target.value }))}
              disabled={generatingExternalQr}
            />
            <input
              className="input"
              placeholder="Telefone (WhatsApp)"
              value={externalQrForm.phone}
              onChange={(e) => setExternalQrForm((f) => ({ ...f, phone: e.target.value }))}
              disabled={generatingExternalQr}
            />
            <div className="flex gap-2">
              <div className="flex-1">
                <select
                  className="input w-full"
                  value={externalQrForm.meals_per_day}
                  onChange={(e) => setExternalQrForm((f) => ({ ...f, meals_per_day: Number(e.target.value) }))}
                  disabled={generatingExternalQr}
                >
                  {[1, 2, 3, 4].map((n) => (
                    <option key={n} value={n}>{n} refeição{n > 1 ? "ões" : " "}/dia</option>
                  ))}
                </select>
              </div>
              <div className="w-32">
                <div className="relative">
                  <input
                    type="number"
                    min="1"
                    max="30"
                    className="input w-full pr-12"
                    placeholder="Dias"
                    value={externalQrForm.valid_days || 1}
                    onChange={(e) => setExternalQrForm((f) => ({ ...f, valid_days: Math.max(1, Number(e.target.value)) }))}
                    disabled={generatingExternalQr}
                  />
                  <div className="absolute inset-y-0 right-3 flex items-center pointer-events-none">
                    <span className="text-gray-400 text-sm">{externalQrForm.valid_days === 1 ? 'dia' : 'dias'}</span>
                  </div>
                </div>
              </div>
              <button
                type="submit"
                className="btn-primary whitespace-nowrap"
                disabled={generatingExternalQr || !externalQrForm.name.trim()}
              >
                {generatingExternalQr ? "Gerando..." : "Gerar QR"}
              </button>
            </div>
          </form>

          {generatedExternalQrs.length > 0 && (
            <div className="space-y-2">
              <p className="text-xs text-gray-500 font-semibold">QRs gerados nesta sessão:</p>
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-3">
                {generatedExternalQrs.map((item, i) => {
                  const inviteLink = buildInviteLink(item.qr_token);
                  const waLink = item.phone
                    ? `https://wa.me/${item.phone.replace(/\D/g, "")}?text=${encodeURIComponent("Acesse seu QR de refeição: " + inviteLink)}`
                    : null;
                  return (
                    <div key={i} className="rounded-2xl border border-gray-800 bg-gray-950/60 p-3 space-y-2">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <p className="font-semibold text-white text-sm">{item.name}</p>
                          <p className="text-xs text-gray-500">{item.phone || "Sem telefone"} · {item.meals_per_day} refeições/dia</p>
                        </div>
                        <span className="badge badge-green">Ativo</span>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <button
                          type="button"
                          className="btn-secondary inline-flex items-center gap-2 text-xs"
                          onClick={() => handleCopyStandaloneInvite(item.qr_token)}
                        >
                          <Copy size={13} /> Copiar link
                        </button>
                        {waLink && (
                          <a
                            href={waLink}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="btn-secondary inline-flex items-center gap-2 text-xs"
                          >
                            <QrCode size={13} /> WhatsApp
                          </a>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}

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

      <div className="card p-4 space-y-3">
        <div className="flex items-center justify-between">
          <p className="text-sm font-semibold text-white">Membros por Setor (Breakdown)</p>
          <span className="badge badge-blue">
            {tableRows.length} membros na leitura atual
          </span>
        </div>
        <div className="flex flex-wrap gap-2 mt-2">
          {Object.entries(
            tableRows.reduce((acc, row) => {
              const sec = row.sector || "Sem setor";
              acc[sec] = (acc[sec] || 0) + 1;
              return acc;
            }, {})
          )
            .sort((a, b) => b[1] - a[1])
            .map(([sec, count]) => (
              <div key={sec} className="px-3 py-1.5 rounded-lg border border-gray-800 bg-gray-950/40 flex items-center gap-2">
                <span className="text-xs text-gray-400 font-medium capitalize">{sec}</span>
                <span className="text-xs text-white bg-gray-800 px-1.5 py-0.5 rounded">{count}</span>
              </div>
            ))}
        </div>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Pessoa</th>
              <th>Cota</th>
              <th>Leitura operacional</th>
              <th>Refeições</th>
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
                      {row.consumedDay > 0 ? (
                        <span className="badge badge-blue">Consumiu {row.consumedDay} hoje</span>
                      ) : (
                        <span className="badge badge-gray">Nenhuma hoje</span>
                      )}
                      <p className="text-xs text-gray-500">
                        {row.remainingDay !== null
                          ? `${row.remainingDay} restante${row.remainingDay === 1 ? "" : "s"} de ${row.mealsPerDay}`
                          : row.configSource === "default"
                            ? `Cota padrão: ${row.mealsPerDay ?? 4} refeições/dia`
                            : "Cota não resolvida"}
                      </p>
                      {row.shiftId && (
                        <p className="text-xs text-gray-400">
                          Turno: {row.shiftName || `#${row.shiftId}`}
                        </p>
                      )}
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
          <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden">
            <div className="p-4 border-b border-gray-800 flex items-center justify-between">
              <div>
                <h3 className="text-white font-bold">Configuração de Refeições</h3>
                <p className="text-xs text-gray-500 mt-1">Defina o valor e os horários de cada tipo de refeição do evento.</p>
              </div>
              <button onClick={() => setMealCostModalOpen(false)} className="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800">
                <X size={18} />
              </button>
            </div>

            <form onSubmit={saveMealServices} className="p-5 space-y-4">
              {mealServiceDrafts.length === 0 ? (
                <p className="text-sm text-gray-500">Nenhum serviço de refeição encontrado. Salve o evento para criar os serviços padrão.</p>
              ) : (
                <div className="space-y-3">
                  {mealServiceDrafts.map((svc, idx) => (
                    <div key={svc.id} className="rounded-xl border border-gray-800 bg-gray-950/60 p-3 space-y-2">
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-semibold text-white">{svc.label}</p>
                        <label className="flex items-center gap-2 text-xs text-gray-400 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={svc.is_active !== false}
                            onChange={(e) => setMealServiceDrafts((prev) =>
                              prev.map((s, i) => i === idx ? { ...s, is_active: e.target.checked } : s)
                            )}
                          />
                          Ativa
                        </label>
                      </div>
                      <div className="grid grid-cols-3 gap-2">
                        <div>
                          <label className="text-[11px] text-gray-500 block">Início</label>
                          <input
                            type="time"
                            className="input w-full text-xs"
                            value={(svc.starts_at || "").slice(0, 5)}
                            onChange={(e) => setMealServiceDrafts((prev) =>
                              prev.map((s, i) => i === idx ? { ...s, starts_at: e.target.value } : s)
                            )}
                          />
                        </div>
                        <div>
                          <label className="text-[11px] text-gray-500 block">Fim</label>
                          <input
                            type="time"
                            className="input w-full text-xs"
                            value={(svc.ends_at || "").slice(0, 5)}
                            onChange={(e) => setMealServiceDrafts((prev) =>
                              prev.map((s, i) => i === idx ? { ...s, ends_at: e.target.value } : s)
                            )}
                          />
                        </div>
                        <div>
                          <label className="text-[11px] text-gray-500 block">Valor (R$)</label>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="input w-full text-xs"
                            value={svc.unit_cost ?? 0}
                            onChange={(e) => setMealServiceDrafts((prev) =>
                              prev.map((s, i) => i === idx ? { ...s, unit_cost: Number(e.target.value) } : s)
                            )}
                          />
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setMealCostModalOpen(false)} className="btn-secondary">
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={savingMealCost || mealServiceDrafts.length === 0}
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
