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

function looksLikeQrTokenValue(raw = "") {
  const value = String(raw || "").trim();
  if (!value) return false;
  if (/\/invite\?token=/i.test(value)) return true;
  return /^[a-f0-9]{40,}$/i.test(value);
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

function formatDateTimeLabel(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }
  return date.toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function hasValidEventId(value) {
  return Number(value) > 0;
}

function formatQrTokenSnippet(value = "") {
  const normalized = String(value || "").trim();
  if (!normalized) return "QR não informado";
  if (normalized.length <= 14) return normalized;
  return `${normalized.slice(0, 8)}...${normalized.slice(-4)}`;
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

function normalizeSectorKey(value = "") {
  return String(value || "").trim().toLowerCase();
}

function isExternalMealEntry(entry = {}) {
  const sector = normalizeSectorKey(entry.sector);
  const roleName = String(entry.roleName || entry.role_name || "").trim().toLowerCase();
  return sector === "externo" || sector === "external" || /extern/.test(roleName);
}

function formatMealServiceLabel(service) {
  if (!service) return "Sem refeição";
  const label = String(service.label || service.service_code || "Refeição").trim();
  const startsAt = String(service.starts_at || "").slice(0, 5);
  const endsAt = String(service.ends_at || "").slice(0, 5);
  const timeLabel = startsAt && endsAt ? ` • ${startsAt} - ${endsAt}` : "";
  return `${label}${timeLabel}`;
}

function resolveQueuedMealServiceLabel(record, services = []) {
  const payload = record?.payload ?? {};
  const explicitServiceId = String(payload?.meal_service_id || "").trim();
  if (explicitServiceId) {
    const matchById = services.find((service) => String(service?.id || "") === explicitServiceId);
    if (matchById) {
      return formatMealServiceLabel(matchById);
    }
  }

  const explicitServiceCode = String(payload?.meal_service_code || "").trim().toLowerCase();
  if (explicitServiceCode) {
    const matchByCode = services.find(
      (service) => String(service?.service_code || "").trim().toLowerCase() === explicitServiceCode
    );
    if (matchByCode) {
      return formatMealServiceLabel(matchByCode);
    }
  }

  const consumedAt = String(payload?.consumed_at || "").trim();
  if (consumedAt) {
    const consumedDate = new Date(consumedAt);
    if (!Number.isNaN(consumedDate.getTime())) {
      const resolved = resolveMealServiceByTime(services, getLocalTimeString(consumedDate));
      if (resolved) {
        return `${formatMealServiceLabel(resolved)} (captura offline)`;
      }
    }
  }

  return explicitServiceCode ? explicitServiceCode : "Automático na captura";
}

function getLocalTimeString(reference = new Date()) {
  const hours = String(reference.getHours()).padStart(2, "0");
  const minutes = String(reference.getMinutes()).padStart(2, "0");
  const seconds = String(reference.getSeconds()).padStart(2, "0");
  return `${hours}:${minutes}:${seconds}`;
}

function resolveMealServiceByTime(services, referenceTime = "") {
  const normalizedTime = String(referenceTime || "").trim();
  if (!normalizedTime || !Array.isArray(services) || services.length === 0) {
    return null;
  }

  const activeServices = services.filter((service) => service?.is_active !== false);
  const pool = activeServices.length > 0 ? activeServices : services;
  const ordered = [...pool].sort(
    (a, b) => Number(a?.sort_order || 0) - Number(b?.sort_order || 0)
  );

  const matched = ordered.find((service) => {
    const startsAt = String(service?.starts_at || "").slice(0, 8);
    const endsAt = String(service?.ends_at || "").slice(0, 8);
    return startsAt && endsAt && normalizedTime >= startsAt && normalizedTime <= endsAt;
  });

  return matched || ordered[0] || null;
}

function buildMealsContextKey(eventId) {
  return `event:${eventId}`;
}

function stampItemsWithEventId(items, eventId) {
  const normalizedEventId = String(eventId || "").trim();
  if (!Array.isArray(items)) return [];
  return items.map((item) => ({
    ...item,
    event_id: item?.event_id ?? normalizedEventId,
  }));
}

function belongsToSelectedEvent(item, eventId) {
  const normalizedEventId = String(eventId || "").trim();
  if (!normalizedEventId) return true;
  return String(item?.event_id ?? "").trim() === normalizedEventId;
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

function classifyRole(roleName = "", costBucket = "") {
  const normalizedName = String(roleName || "").trim().toLowerCase();
  const normalizedBucket = String(costBucket || "").trim().toLowerCase();

  if (normalizedBucket === "mixed") {
    return "mixed";
  }
  if (/(diretor|diretora|diretivo|diretiva|diretoria|director)/.test(normalizedName)) {
    return "director";
  }
  if (/(coordenador|coordenadora|coordinator)/.test(normalizedName)) {
    return "coordinator";
  }
  if (/(supervisor|supervisora|spervisor|lider|líder|lead)/.test(normalizedName)) {
    return "supervisor";
  }
  if (/(gerente|manager|gestor|gestora)/.test(normalizedName)) {
    return "manager";
  }
  if (normalizedBucket === "managerial") {
    return "manager";
  }
  return "operational";
}

function getRoleClassLabel(roleClass = "") {
  const labels = {
    director: "Diretivo",
    manager: "Gerência",
    coordinator: "Coordenação",
    supervisor: "Supervisão",
    mixed: "Função mista",
    operational: "Operacional",
  };
  return labels[roleClass] || "Operacional";
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
  const [mealHistoryItems, setMealHistoryItems] = useState([]);
  const [pendingOfflineMeals, setPendingOfflineMeals] = useState([]);
  const [workforceBaseItems, setWorkforceBaseItems] = useState([]);
  const [mealUnitCost, setMealUnitCost] = useState(0);
  const [mealUnitCostAvailable, setMealUnitCostAvailable] = useState(null);
  const [mealCostModalOpen, setMealCostModalOpen] = useState(false);
  const [mealCostDraft, setMealCostDraft] = useState(0);
  const [mealServiceDrafts, setMealServiceDrafts] = useState([]);
  const [savingMealCost, setSavingMealCost] = useState(false);
  const [mealHistoryLoading, setMealHistoryLoading] = useState(false);
  const [syncingOfflineMeals, setSyncingOfflineMeals] = useState(false);
  const [isDeviceOnline, setIsDeviceOnline] = useState(() => navigator.onLine);
  const [generatingStandaloneQrId, setGeneratingStandaloneQrId] = useState(null);
  const [externalQrForm, setExternalQrForm] = useState({ name: "", phone: "", meals_per_day: 4, valid_days: 1 });
  const [generatingExternalQr, setGeneratingExternalQr] = useState(false);
  const [generatedExternalQrs, setGeneratedExternalQrs] = useState([]);
  const [deviceNow, setDeviceNow] = useState(() => new Date());
  const selectedEventRef = useRef("");
  const staticDataRequestRef = useRef(0);
  const workforceRequestRef = useRef(0);
  const balanceRequestRef = useRef(0);
  const mealHistoryRequestRef = useRef(0);
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
  const deviceReferenceTime = useMemo(() => getLocalTimeString(deviceNow), [deviceNow]);
  const automaticMealServiceData = useMemo(
    () => resolveMealServiceByTime(mealServices, deviceReferenceTime) || payload.selectedMealService || null,
    [deviceReferenceTime, mealServices, payload.selectedMealService]
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
      const rawItems = stampItemsWithEventId(res.data?.data || [], evtId);
      setWorkforceBaseItems(rawItems);
      await saveCachedMealsContext(cacheKey, { workforceBaseItems: rawItems });
    } catch (err) {
      if (requestId !== workforceRequestRef.current || selectedEventRef.current !== normalizedEventId) {
        return;
      }
      const cached = await loadCachedMealsContext(cacheKey);
      const cachedItems = stampItemsWithEventId(
        Array.isArray(cached?.workforceBaseItems) ? cached.workforceBaseItems : [],
        evtId
      );
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
          : ""
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
            : ""
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
    const targetReferenceTime = String(
      overrides.referenceTime ?? getLocalTimeString(deviceNow)
    ).trim();
    const automaticService = !targetMealServiceId
      ? resolveMealServiceByTime(mealServices, targetReferenceTime)
      : null;
    const cacheMealKey = targetMealServiceId || `auto:${automaticService?.id || targetReferenceTime.slice(0, 5) || "none"}`;

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
          reference_time: !targetMealServiceId ? targetReferenceTime : undefined,
          sector: targetSector || undefined,
        },
      });
      if (requestId !== balanceRequestRef.current || selectedEventRef.current !== targetEventId) {
        return;
      }
      const data = res.data?.data || {};
      const nextPayload = {
        summary: data.summary || null,
        items: stampItemsWithEventId(data.items || [], targetEventId),
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
          [`${targetEventDayId}:${cacheMealKey}:${targetEventShiftId || "all"}:${targetSector || "all"}`]: nextPayload,
        },
      });
    } catch (err) {
      if (requestId !== balanceRequestRef.current || selectedEventRef.current !== targetEventId) {
        return;
      }
      const cached = await loadCachedMealsContext(buildMealsContextKey(targetEventId));
      const cacheKey = `${targetEventDayId}:${cacheMealKey}:${targetEventShiftId || "all"}:${targetSector || "all"}`;
      const cachedPayload = cached?.balances?.[cacheKey];
      if (cachedPayload) {
        setPayload({
          ...createEmptyPayload(),
          ...cachedPayload,
          items: stampItemsWithEventId(cachedPayload?.items || [], targetEventId),
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

  const loadMealHistory = async (overrides = {}) => {
    const targetEventId = String(overrides.eventId ?? eventId ?? "");
    const targetEventDayId = String(overrides.eventDayId ?? eventDayId ?? "");
    const targetEventShiftId = String(overrides.eventShiftId ?? eventShiftId ?? "");
    const targetMealServiceId = String(overrides.mealServiceId ?? mealServiceId ?? "");
    const targetReferenceTime = String(
      overrides.referenceTime ?? getLocalTimeString(deviceNow)
    ).trim();
    const automaticService = !targetMealServiceId
      ? resolveMealServiceByTime(mealServices, targetReferenceTime)
      : null;
    const resolvedMealServiceId = targetMealServiceId || String(automaticService?.id || "");

    if (!targetEventId || !targetEventDayId) {
      setMealHistoryItems([]);
      return;
    }

    const requestId = mealHistoryRequestRef.current + 1;
    mealHistoryRequestRef.current = requestId;
    setMealHistoryLoading(true);

    const historyCacheKey = `${targetEventDayId}:${resolvedMealServiceId || "all"}:${targetEventShiftId || "all"}`;
    const cacheContextKey = buildMealsContextKey(targetEventId);

    try {
      const res = await api.get("/meals", {
        params: {
          event_id: targetEventId,
          event_day_id: targetEventDayId,
          event_shift_id: targetEventShiftId || undefined,
          meal_service_id: resolvedMealServiceId || undefined,
          limit: 25,
        },
      });
      if (requestId !== mealHistoryRequestRef.current || selectedEventRef.current !== targetEventId) {
        return;
      }
      const historyItems = stampItemsWithEventId(res.data?.data || [], targetEventId);
      setMealHistoryItems(historyItems);

      const cached = await loadCachedMealsContext(cacheContextKey);
      await saveCachedMealsContext(cacheContextKey, {
        mealHistory: {
          ...(cached?.mealHistory || {}),
          [historyCacheKey]: historyItems,
        },
      });
    } catch (err) {
      if (requestId !== mealHistoryRequestRef.current || selectedEventRef.current !== targetEventId) {
        return;
      }
      const cached = await loadCachedMealsContext(cacheContextKey);
      const cachedHistory = Array.isArray(cached?.mealHistory?.[historyCacheKey])
        ? stampItemsWithEventId(cached.mealHistory[historyCacheKey], targetEventId)
        : [];
      if (cachedHistory.length > 0) {
        setMealHistoryItems(cachedHistory);
        toast("Histórico Meals carregado do cache local.", { id: "meals-history-cache" });
      } else {
        setMealHistoryItems([]);
        toast.error(err.response?.data?.message || "Erro ao carregar histórico de refeições.");
      }
    } finally {
      if (requestId === mealHistoryRequestRef.current) {
        setMealHistoryLoading(false);
      }
    }
  };

  const loadPendingOfflineMeals = async () => {
    try {
      const records = await db.offlineQueue.where("status").equals("pending").toArray();
      const mealRecords = records.filter(
        (record) => String(record?.payload_type ?? record?.type ?? "").trim().toLowerCase() === "meal"
      );
      setPendingOfflineMeals(mealRecords);
    } catch {
      setPendingOfflineMeals([]);
    }
  };

  const syncPendingOfflineMeals = async () => {
    if (!navigator.onLine) {
      toast.error("Sem rede: a fila do Meals será sincronizada quando a conexão voltar.");
      return;
    }

    const normalizedPending = pendingOfflineMeals.map((record) => {
      const payload = record?.payload ?? record?.data ?? {};
      return {
        offline_id: record?.offline_id,
        payload_type: "meal",
        payload: {
          ...payload,
          event_id: hasValidEventId(payload?.event_id) ? Number(payload.event_id) : null,
          sector: payload?.sector ?? record?.sector ?? null,
        },
        created_offline_at: record?.created_offline_at ?? record?.created_at ?? new Date().toISOString(),
      };
    });

    const validPending = normalizedPending.filter((record) => hasValidEventId(record?.payload?.event_id));
    const invalidPending = normalizedPending.filter((record) => !hasValidEventId(record?.payload?.event_id));

    if (validPending.length === 0) {
      toast.error("Não há refeições offline válidas para sincronizar.");
      await loadPendingOfflineMeals();
      return;
    }

    setSyncingOfflineMeals(true);
    toast.loading(`Sincronizando ${validPending.length} refeição(ões) offline...`, { id: "meals-sync" });

    try {
      const { data } = await api.post("/sync", { items: validPending });

      if (!data?.success) {
        toast.error("Não foi possível concluir a sincronização do Meals.", { id: "meals-sync" });
        return;
      }

      const processedIds = data?.data?.processed_ids ?? validPending.map((item) => item.offline_id);
      const failedIds = data?.data?.failed_ids ?? [];
      const failedCount = Number(data?.data?.failed ?? 0);

      if (processedIds.length > 0) {
        await db.offlineQueue.bulkDelete(processedIds);
      }
      if (failedIds.length > 0) {
        await db.offlineQueue.bulkDelete(failedIds);
      }

      await loadPendingOfflineMeals();

      if (eventId && eventDayId) {
        await Promise.all([loadBalance(), loadMealHistory()]);
      }

      if (failedCount > 0 || invalidPending.length > 0) {
        toast.error(
          `${processedIds.length} sincronizada(s), ${failedCount} removida(s) por falha letal e ${invalidPending.length} sem evento válido.`,
          { id: "meals-sync" }
        );
      } else {
        toast.success(`${processedIds.length} refeição(ões) offline sincronizada(s).`, { id: "meals-sync" });
      }
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao sincronizar a fila offline do Meals.", {
        id: "meals-sync",
      });
    } finally {
      setSyncingOfflineMeals(false);
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
    loadPendingOfflineMeals();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const intervalId = window.setInterval(() => {
      setDeviceNow(new Date());
    }, 30000);

    return () => window.clearInterval(intervalId);
  }, []);

  useEffect(() => {
    const handleOnline = () => {
      setIsDeviceOnline(true);
      window.setTimeout(() => {
        loadPendingOfflineMeals();
      }, 1200);
    };

    const handleOffline = () => {
      setIsDeviceOnline(false);
      loadPendingOfflineMeals();
    };

    const handleFocus = () => {
      loadPendingOfflineMeals();
    };

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    window.addEventListener("focus", handleFocus);

    const intervalId = window.setInterval(() => {
      loadPendingOfflineMeals();
    }, 15000);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
      window.removeEventListener("focus", handleFocus);
      window.clearInterval(intervalId);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    staticDataRequestRef.current += 1;
    workforceRequestRef.current += 1;
    balanceRequestRef.current += 1;
    mealHistoryRequestRef.current += 1;
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
    setMealHistoryItems([]);
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
      setMealServiceId("");
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
      setMealHistoryItems([]);
      return;
    }
    if (eventDays.length <= 0) {
      setPayload(createEmptyPayload());
      setMealHistoryItems([]);
      return;
    }
    // Previne chamadas com eventDayId de evento anterior durante a transição
    const isDayFromCurrentEvent = eventDays.some(d => String(d.id) === String(eventDayId));
    if (eventDays.length > 0 && !isDayFromCurrentEvent) {
      return;
    }
    loadBalance();
    loadMealHistory();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eventId, eventDayId, eventShiftId, mealServiceId, sector, eventDays]);

  const eventScopedWorkforceBaseItems = useMemo(
    () => workforceBaseItems.filter((item) => belongsToSelectedEvent(item, eventId)),
    [eventId, workforceBaseItems]
  );

  const availableSectors = useMemo(() => {
    const values = eventScopedWorkforceBaseItems
      .map((item) => String(item.sector || "").trim().toLowerCase())
      .filter(Boolean);
    return [...new Set(values)].sort((a, b) => a.localeCompare(b, "pt-BR"));
  }, [eventScopedWorkforceBaseItems]);

  useEffect(() => {
    if (sector && !availableSectors.includes(String(sector).trim().toLowerCase())) {
      setSector("");
    }
  }, [availableSectors, sector]);

  const filteredWorkforceItems = useMemo(() => {
    return eventScopedWorkforceBaseItems.filter((item) => {
      if (sector && String(item.sector || "").trim().toLowerCase() !== String(sector).trim().toLowerCase()) {
        return false;
      }
      return true;
    });
  }, [eventScopedWorkforceBaseItems, sector]);

  const internalWorkforceItems = useMemo(
    () => filteredWorkforceItems.filter((item) => !isExternalMealEntry(item)),
    [filteredWorkforceItems]
  );

  const externalWorkforceItems = useMemo(
    () => filteredWorkforceItems.filter((item) => isExternalMealEntry(item)),
    [filteredWorkforceItems]
  );

  const workforceSummary = useMemo(() => {
    const sectors = new Set();
    const uniqueParticipants = new Set();
    const uniqueExternalParticipants = new Set();
    let mealsPerDayTotal = 0;
    let assignmentsWithShift = 0;

    internalWorkforceItems.forEach((item) => {
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

    externalWorkforceItems.forEach((item) => {
      uniqueExternalParticipants.add(String(item.participant_id || `assignment:${item.id}`));
    });

    return {
      members: uniqueParticipants.size,
      assignmentRows: internalWorkforceItems.length,
      sectorsCount: sectors.size,
      mealsPerDayTotal,
      assignmentsWithShift,
      assignmentsWithoutShift: Math.max(0, internalWorkforceItems.length - assignmentsWithShift),
      externalMembers: uniqueExternalParticipants.size,
      externalAssignmentRows: externalWorkforceItems.length,
    };
  }, [externalWorkforceItems, internalWorkforceItems]);

  const standaloneQrMembers = useMemo(() => {
    const grouped = new Map();

    eventScopedWorkforceBaseItems.forEach((item) => {
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
  }, [eventScopedWorkforceBaseItems]);

  const eventScopedPayloadItems = useMemo(
    () => (payload.items || []).filter((item) => belongsToSelectedEvent(item, eventId)),
    [eventId, payload.items]
  );
  const visibleMealHistoryItems = useMemo(() => {
    const scopedItems = mealHistoryItems.filter((item) => belongsToSelectedEvent(item, eventId));
    if (!sector) {
      return scopedItems;
    }

    const participantIdsInScope = new Set(
      eventScopedPayloadItems
        .map((item) => Number(item.participant_id || 0))
        .filter((participantId) => participantId > 0)
    );
    if (participantIdsInScope.size === 0) {
      return [];
    }

    return scopedItems.filter((item) =>
      participantIdsInScope.has(Number(item.participant_id || 0))
    );
  }, [eventId, eventScopedPayloadItems, mealHistoryItems, sector]);
  const pendingOfflineMealsForCurrentEvent = useMemo(() => {
    if (!eventId) {
      return pendingOfflineMeals;
    }
    return pendingOfflineMeals.filter((record) =>
      String(record?.payload?.event_id || "").trim() === String(eventId).trim()
    );
  }, [eventId, pendingOfflineMeals]);
  const pendingOfflineMealsForCurrentDay = useMemo(() => {
    if (!eventDayId) {
      return pendingOfflineMealsForCurrentEvent;
    }
    return pendingOfflineMealsForCurrentEvent.filter((record) =>
      String(record?.payload?.event_day_id || "").trim() === String(eventDayId).trim()
    );
  }, [eventDayId, pendingOfflineMealsForCurrentEvent]);
  const visiblePendingOfflineMeals = useMemo(() => {
    const filtered = pendingOfflineMealsForCurrentDay.filter((record) => {
      if (!sector) return true;
      return String(record?.payload?.sector || record?.sector || "").trim().toLowerCase() === String(sector).trim().toLowerCase();
    });

    return [...filtered]
      .sort((a, b) => {
        const left = new Date(a?.created_offline_at || a?.payload?.consumed_at || 0).getTime();
        const right = new Date(b?.created_offline_at || b?.payload?.consumed_at || 0).getTime();
        return right - left;
      })
      .slice(0, 8);
  }, [pendingOfflineMealsForCurrentDay, sector]);

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
    if (!selectedMealServiceData) {
      toast.error("Nenhuma refeição ativa foi resolvida para este horário.");
      return;
    }

    const requestPayload = {
      event_id: Number(eventId),
      qr_token: token,
      event_day_id: Number(eventDayId),
      event_shift_id: eventShiftId ? Number(eventShiftId) : null,
      meal_service_id: mealServiceId ? Number(mealServiceId) : null,
      meal_service_code: mealServiceId ? (selectedMealService?.service_code || null) : null,
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
      await loadPendingOfflineMeals();
      await Promise.all([loadBalance(), loadMealHistory()]);
    } catch (err) {
      const networkError = !navigator.onLine || err?.code === "ERR_NETWORK";
      if (networkError) {
        try {
          await enqueueOfflineMeal(requestPayload);
          setQrInput("");
          await loadPendingOfflineMeals();
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

  const selectedMealServiceData = mealServiceId
    ? (selectedMealService || payload.selectedMealService || null)
    : automaticMealServiceData;
  const pricedMealServiceData = mealServiceId
    ? selectedMealServiceData
    : (automaticMealServiceData || selectedMealServiceData);
  const currentMealUnitCost = Number(
    pricedMealServiceData?.unit_cost ??
    projectionSummary.selected_meal_service_unit_cost ??
    projectionSummary.meal_unit_cost ??
    summary.selected_meal_service_unit_cost ??
    summary.meal_unit_cost ??
    mealUnitCost ??
    0
  );
  const projectionEnabled = Boolean(projectionSummary.enabled);
  const hasSelectedShift = Boolean(eventShiftId);
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
  const canRegisterMeal = canUseRealMeals && Boolean(selectedMealServiceData);

  useEffect(() => {
    if (!canUseRealMeals || showWorkforceFallback || mealServiceId) {
      return;
    }

    loadBalance({
      eventId,
      eventDayId,
      eventShiftId,
      mealServiceId: "",
      referenceTime: deviceReferenceTime,
      sector,
    });
    loadMealHistory({
      eventId,
      eventDayId,
      eventShiftId,
      mealServiceId: "",
      referenceTime: deviceReferenceTime,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canUseRealMeals, deviceReferenceTime, eventDayId, eventId, eventShiftId, mealServiceId, sector, showWorkforceFallback]);

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

    if (!selectedMealServiceData) {
      list.push({
        tone: "warn",
        title: "Refeição sem janela ativa",
        body: "Nenhum serviço ativo foi resolvido pelo horário atual. Revise as janelas configuradas ou selecione manualmente uma refeição.",
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
    selectedMealServiceData,
    showWorkforceFallback,
    operationalDiagnosticsIssues,
    syntheticEventDayOption,
    workforceSummary.assignmentsWithShift,
    workforceSummary.assignmentRows,
    workforceSummary.members,
  ]);

  const tableRows = useMemo(() => {
    if (showWorkforceFallback) {
      const grouped = new Map();

      filteredWorkforceItems.forEach((item) => {
        const participantId = Number(item.participant_id || 0);
        const groupKey = participantId > 0 ? `participant-${participantId}` : `assignment-${item.id}`;
        const existing = grouped.get(groupKey) || {
          key: `workforce-${groupKey}`,
          participantId: participantId || null,
          eventId: item.event_id ? Number(item.event_id) : null,
          name: item.person_name || item.name || `Participante #${participantId || item.id}`,
          qrToken: item.qr_token || "",
          assignmentsInScope: 0,
          roleNames: new Set(),
          sectors: new Set(),
          shiftIds: new Set(),
          shiftNames: new Set(),
          costBuckets: new Set(),
          mealsPerDayValues: new Set(),
        };

        existing.assignmentsInScope += 1;
        if (item.role_name) {
          existing.roleNames.add(String(item.role_name));
        }
        if (String(item.sector || "").trim()) {
          existing.sectors.add(String(item.sector).trim());
        }
        if (item.shift_id) {
          existing.shiftIds.add(Number(item.shift_id));
        }
        if (item.shift_name) {
          existing.shiftNames.add(String(item.shift_name));
        }

        const normalizedBucket = String(item.cost_bucket || "operational").trim().toLowerCase() || "operational";
        existing.costBuckets.add(normalizedBucket);

        if (item.meals_per_day !== null && item.meals_per_day !== undefined && String(item.meals_per_day) !== "") {
          existing.mealsPerDayValues.add(Number(item.meals_per_day));
        }
        if (!existing.qrToken && item.qr_token) {
          existing.qrToken = item.qr_token;
        }

        grouped.set(groupKey, existing);
      });

      return [...grouped.values()].map((item) => {
        const roleNames = [...item.roleNames];
        const sectorValues = [...item.sectors];
        const shiftIds = [...item.shiftIds];
        const shiftNames = [...item.shiftNames];
        const costBuckets = [...item.costBuckets];
        const mealsPerDayValues = [...item.mealsPerDayValues];
        const hasMultipleRoles = roleNames.length > 1;
        const hasMultipleSectors = sectorValues.length > 1;
        const hasMultipleShifts = shiftIds.length > 1;
        const costBucket = costBuckets.length === 1
          ? costBuckets[0]
          : (costBuckets.includes("managerial") && costBuckets.includes("operational") ? "mixed" : (costBuckets[0] || "operational"));
        const roleName = hasMultipleRoles
          ? "Cargos múltiplos"
          : (roleNames[0] || "Cargo não informado");
        const sectorLabel = hasMultipleSectors
          ? "Setores múltiplos"
          : (sectorValues[0] || "geral");
        const shiftId = shiftIds.length === 1 ? shiftIds[0] : null;
        const shiftName = shiftNames.length === 1 ? shiftNames[0] : "";
        const mealsPerDay = mealsPerDayValues.length === 0
          ? null
          : (mealsPerDayValues.length === 1 ? mealsPerDayValues[0] : Math.max(...mealsPerDayValues));

        return {
          key: item.key,
          participantId: item.participantId,
          eventId: item.eventId,
          name: item.name,
          roleName,
          roleClass: hasMultipleRoles ? classifyRole("", costBucket) : classifyRole(roleName, costBucket),
          costBucket,
          sector: sectorLabel,
          mealsPerDay,
          configSource: null,
          baselineStatus: "fallback",
          hasAmbiguousBaseline: false,
          consumedDay: null,
          remainingDay: null,
          consumedShift: null,
          shiftName,
          shiftId,
          assignmentsInScope: item.assignmentsInScope,
          hasMultipleAssignments: item.assignmentsInScope > 1,
          hasMultipleRoles,
          hasMultipleSectors,
          hasMultipleShifts,
          qrToken: item.qrToken || "",
          sourceLabel: "Base Workforce",
          sourceDescription: item.assignmentsInScope > 1
            ? `Base complementar consolidada em ${item.assignmentsInScope} assignments do evento selecionado.`
            : "Base real complementar do evento selecionado.",
          sourceBadgeClass: "badge badge-gray",
        };
      });
    }

    return eventScopedPayloadItems.map((item) => {
      const assignmentsInScope = Number(item.assignments_in_scope || 0);
      const hasMultipleAssignments = Boolean(item.has_multiple_assignments);
      const hasMultipleRoles = Boolean(item.has_multiple_roles);
      const hasMultipleSectors = Boolean(item.has_multiple_sectors);
      const hasMultipleShifts = Boolean(item.has_multiple_shifts);
      const hasAmbiguousBaseline = Boolean(item.has_ambiguous_baseline);
      const shiftId = item.shift_id ? Number(item.shift_id) : null;
      const roleName = item.role_name || (hasMultipleRoles ? "Cargos múltiplos" : "Cargo não unívoco");
      const costBucket = String(item.cost_bucket || "operational").trim().toLowerCase() || "operational";
      const sectorLabel = item.sector || (hasMultipleSectors ? "Setores múltiplos" : "Setor não unívoco");

      return {
        key: `meal-${item.participant_id}`,
        participantId: item.participant_id,
        eventId: item.event_id ? Number(item.event_id) : null,
        name: item.participant_name,
        roleName,
        roleClass: item.role_class || classifyRole(roleName, costBucket),
        costBucket,
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
        consumedShift: Number(item.consumed_service ?? item.consumed_shift ?? 0),
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
    eventScopedPayloadItems,
    filteredWorkforceItems,
    showWorkforceFallback,
  ]);

  const tableHighlights = useMemo(() => {
      const staffRows = tableRows.filter((row) => !isExternalMealEntry(row));
      return {
        consumedMembers: staffRows.filter((row) => Number(row.consumedDay || 0) > 0).length,
        exhaustedMembers: staffRows.filter(
          (row) => row.remainingDay !== null && Number(row.remainingDay) <= 0
        ).length,
        ambiguousBaselineMembers: staffRows.filter((row) => row.hasAmbiguousBaseline).length,
        withoutUniqueShiftMembers: staffRows.filter((row) => !row.shiftId).length,
        multiAssignmentMembers: staffRows.filter((row) => Number(row.assignmentsInScope || 0) > 1).length,
      };
  }, [tableRows]);

  const staffTableRows = useMemo(
    () => tableRows.filter((row) => !isExternalMealEntry(row)),
    [tableRows]
  );

  const externalTableRows = useMemo(
    () => tableRows.filter((row) => isExternalMealEntry(row)),
    [tableRows]
  );
  const participantNameByQrToken = useMemo(() => {
    const map = new Map();

    tableRows.forEach((row) => {
      const token = String(row.qrToken || "").trim();
      if (token) {
        map.set(token, row.name);
      }
    });

    eventScopedWorkforceBaseItems.forEach((item) => {
      const token = String(item.qr_token || "").trim();
      const name = String(item.person_name || item.name || "").trim();
      if (token && name && !map.has(token)) {
        map.set(token, name);
      }
    });

    return map;
  }, [eventScopedWorkforceBaseItems, tableRows]);

  const staffOperationalSummary = useMemo(() => {
    return staffTableRows.reduce((acc, row) => {
      acc.members += 1;
      acc.meals_per_day_total += Number(row.mealsPerDay || 0);
      acc.consumed_day_total += Number(row.consumedDay || 0);
      acc.remaining_day_total += Number(row.remainingDay || 0);
      acc.consumed_service_total += Number(row.consumedShift || 0);
      return acc;
    }, {
      members: 0,
      meals_per_day_total: 0,
      consumed_day_total: 0,
      remaining_day_total: 0,
      consumed_service_total: 0,
    });
  }, [staffTableRows]);

  const staffConfigBreakdown = useMemo(() => {
    return staffTableRows.reduce((acc, row) => {
      if (row.hasAmbiguousBaseline) {
        acc.members_with_ambiguous_baseline += 1;
      }
      if (row.configSource === "member_override") {
        acc.members_using_member_settings += 1;
      } else if (row.configSource === "role_settings") {
        acc.members_using_role_settings += 1;
      } else if (row.configSource === "default") {
        acc.members_using_default_fallback += 1;
      }
      return acc;
    }, {
      members_using_member_settings: 0,
      members_using_role_settings: 0,
      members_using_default_fallback: 0,
      members_with_ambiguous_baseline: 0,
    });
  }, [staffTableRows]);

  const roleComposition = useMemo(() => {
    return staffTableRows.reduce((acc, row) => {
      const roleClass = row.roleClass || classifyRole(row.roleName, row.costBucket);
      const costBucket = String(row.costBucket || "operational").trim().toLowerCase() || "operational";

      if (costBucket === "managerial") {
        acc.managerialMembers += 1;
      } else if (costBucket === "mixed") {
        acc.mixedBucketMembers += 1;
      } else {
        acc.operationalMembers += 1;
      }

      if (roleClass === "director") {
        acc.directors += 1;
      } else if (roleClass === "manager") {
        acc.managers += 1;
      } else if (roleClass === "coordinator") {
        acc.coordinators += 1;
      } else if (roleClass === "supervisor") {
        acc.supervisors += 1;
      }

      if (["director", "manager", "coordinator", "supervisor"].includes(roleClass)) {
        acc.leadershipMembers += 1;
      }

      return acc;
    }, {
      leadershipMembers: 0,
      directors: 0,
      managers: 0,
      coordinators: 0,
      supervisors: 0,
      managerialMembers: 0,
      operationalMembers: 0,
      mixedBucketMembers: 0,
    });
  }, [staffTableRows]);

  const breakdownEntries = useMemo(() => {
    const sectorEntries = Object.entries(
      staffTableRows.reduce((acc, row) => {
        const sectorName = row.sector || "Sem setor";
        acc[sectorName] = (acc[sectorName] || 0) + 1;
        return acc;
      }, {})
    )
      .sort((a, b) => b[1] - a[1])
      .map(([label, count]) => ({
        key: `sector-${label}`,
        label,
        count,
        capitalize: true,
      }));

    const roleEntries = [
      ["Diretivos", roleComposition.directors],
      ["Gerentes", roleComposition.managers],
      ["Coordenadores", roleComposition.coordinators],
      ["Supervisores", roleComposition.supervisors],
      ["Operacionais", roleComposition.operationalMembers],
    ]
      .filter(([, count]) => count > 0)
      .map(([label, count]) => ({
        key: `role-${label}`,
        label,
        count,
        capitalize: false,
      }));

    if (externalTableRows.length > 0) {
      roleEntries.unshift({
        key: "external-qr",
        label: "Externos com QR",
        count: externalTableRows.length,
        capitalize: false,
      });
    }

    return [...sectorEntries, ...roleEntries];
  }, [
    externalTableRows.length,
    roleComposition.coordinators,
    roleComposition.directors,
    roleComposition.managers,
    roleComposition.operationalMembers,
    roleComposition.supervisors,
    staffTableRows,
  ]);

  const operationalCards = useMemo(() => {
    if (showWorkforceFallback) {
      return [
        {
          label: "Pessoas no Workforce",
          value: workforceSummary.members,
          valueClassName: "text-white",
          helper: workforceSummary.assignmentRows > workforceSummary.members
            ? `Base complementar com ${workforceSummary.assignmentRows} assignments reais para ${workforceSummary.members} pessoa(s) do staff.${workforceSummary.externalMembers > 0 ? ` Externos com QR fora desta contagem: ${workforceSummary.externalMembers}.` : ""}`
            : `Base complementar do evento enquanto o saldo diário ainda não pode ser lido.${workforceSummary.externalMembers > 0 ? ` Externos com QR fora desta contagem: ${workforceSummary.externalMembers}.` : ""}`,
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
        {
          label: "Lideranças visíveis",
          value: roleComposition.leadershipMembers,
          valueClassName: roleComposition.leadershipMembers > 0 ? "text-cyan-400" : "text-white",
          helper: `${roleComposition.directors} diretivo(s) · ${roleComposition.managers} gerente(s) · ${roleComposition.coordinators} coordenador(es) · ${roleComposition.supervisors} supervisor(es).`,
          badge: roleComposition.leadershipMembers > 0 ? "Somadas" : "Sem liderança",
          badgeClassName: roleComposition.leadershipMembers > 0 ? "badge badge-blue" : "badge badge-gray",
        },
      ];
    }

    return [
      {
        label: "Membros",
        value: staffOperationalSummary.members,
        valueClassName: "text-white",
        helper: roleComposition.leadershipMembers > 0
          ? `Saldo real de Meals carregado para o dia selecionado. Inclui ${roleComposition.leadershipMembers} liderança(s) com assignment no recorte.${externalTableRows.length > 0 ? ` Externos com QR fora desta contagem principal: ${externalTableRows.length}.` : ""}`
          : `Saldo real de Meals carregado para o dia selecionado.${externalTableRows.length > 0 ? ` Externos com QR fora desta contagem principal: ${externalTableRows.length}.` : ""}`,
        badge: "Saldo real Meals",
        badgeClassName: "badge badge-green",
      },
      {
        label: "Cota dia",
        value: staffOperationalSummary.meals_per_day_total,
        valueClassName: "text-white",
        helper: staffConfigBreakdown.members_with_ambiguous_baseline > 0
          ? "Parte da equipe segue com baseline ambíguo e fica fora da cota derivada confiável."
          : staffConfigBreakdown.members_using_default_fallback > 0
            ? `Parte da equipe (${staffConfigBreakdown.members_using_default_fallback}) ainda usa fallback default neste recorte.`
            : "Cota diária consolidada para o recorte operacional do dia.",
        badge: staffConfigBreakdown.members_with_ambiguous_baseline > 0 ? "Origem parcial" : "Origem visível",
        badgeClassName: staffConfigBreakdown.members_with_ambiguous_baseline > 0 ? "badge badge-yellow" : "badge badge-blue",
      },
      {
        label: "Consumidas dia",
        value: staffOperationalSummary.consumed_day_total,
        valueClassName: "text-amber-400",
        helper: `${tableHighlights.consumedMembers} participante(s) ja consumiram no dia.`,
        badge: tableHighlights.consumedMembers > 0 ? "Com consumo" : "Sem consumo",
        badgeClassName: tableHighlights.consumedMembers > 0 ? "badge badge-blue" : "badge badge-gray",
      },
      {
        label: "Saldo dia",
        value: staffOperationalSummary.remaining_day_total,
        valueClassName: tableHighlights.exhaustedMembers > 0 ? "text-red-400" : "text-green-400",
        helper: `${tableHighlights.exhaustedMembers} participante(s) sem saldo restante.`,
        badge: tableHighlights.exhaustedMembers > 0 ? "Sem saldo" : "Saldo ok",
        badgeClassName: tableHighlights.exhaustedMembers > 0 ? "badge badge-red" : "badge badge-green",
      },
      {
        label: "Consumidas refeição",
        value: staffOperationalSummary.consumed_service_total,
        valueClassName: "text-blue-400",
        helper: `${selectedMealServiceLabel}.${hasSelectedShift ? " Turno aplicado como filtro complementar." : " Sem turno, o recorte continua diário."}`,
        badge: hasSelectedShift ? "Refeição + turno" : "Refeição ativa",
        badgeClassName: hasSelectedShift ? "badge badge-blue" : "badge badge-gray",
      },
      {
        label: "Lideranças",
        value: roleComposition.leadershipMembers,
        valueClassName: roleComposition.leadershipMembers > 0 ? "text-cyan-400" : "text-white",
        helper: `${roleComposition.directors} diretivo(s) · ${roleComposition.managers} gerente(s) · ${roleComposition.coordinators} coordenador(es) · ${roleComposition.supervisors} supervisor(es).`,
        badge: roleComposition.leadershipMembers > 0 ? "Somadas" : "Sem liderança",
        badgeClassName: roleComposition.leadershipMembers > 0 ? "badge badge-blue" : "badge badge-gray",
      },
    ];
  }, [
    externalTableRows.length,
    hasSelectedShift,
    roleComposition.coordinators,
    roleComposition.directors,
    roleComposition.leadershipMembers,
    roleComposition.managers,
    roleComposition.supervisors,
    selectedMealServiceLabel,
    showWorkforceFallback,
    staffConfigBreakdown.members_using_default_fallback,
    staffConfigBreakdown.members_with_ambiguous_baseline,
    staffOperationalSummary.consumed_day_total,
    staffOperationalSummary.consumed_service_total,
    staffOperationalSummary.meals_per_day_total,
    staffOperationalSummary.members,
    staffOperationalSummary.remaining_day_total,
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
      : !selectedMealServiceData
        ? "Nenhuma refeição ativa foi resolvida para o horário atual. Revise as janelas configuradas no modal de refeições."
        : hasSelectedShift
          ? sector
            ? `Registro ativo para ${selectedMealServiceLabel}, turno e setor selecionados.`
            : `Registro ativo para ${selectedMealServiceLabel} com turno selecionado.`
          : sector
            ? `Registro ativo para ${selectedMealServiceLabel} e setor selecionados. O turno permanece opcional.`
            : `Registro ativo para ${selectedMealServiceLabel}. O turno permanece opcional.`;
  const mealHistoryMessage = showWorkforceFallback
    ? "O histórico HTTP de refeições fica disponível quando o evento operar com `event_days` reais."
    : !eventDayId
      ? "Selecione um dia operacional para consultar o histórico recente de baixas."
      : selectedMealServiceData
        ? sector
          ? `Últimas baixas de ${selectedMealServiceLabel}, com o setor filtrado pela base atual do recorte.`
          : `Últimas baixas de ${selectedMealServiceLabel} no recorte atual do dia.`
        : "Últimas baixas do dia selecionado. Nenhuma refeição ativa foi resolvida para restringir o histórico por serviço.";
  const pendingOfflineMealsOutsideCurrentEventCount = Math.max(
    0,
    pendingOfflineMeals.length - pendingOfflineMealsForCurrentEvent.length
  );
  const pendingOfflineMealsMessage = !eventId
    ? "A fila offline do Meals mostra tudo o que ainda está salvo localmente neste dispositivo."
    : !eventDayId
      ? "A fila offline já está filtrada pelo evento atual. Selecione um dia para afunilar o recorte operacional."
      : sector
        ? "As pendências exibidas abaixo estão filtradas por evento, dia e setor."
        : "As pendências exibidas abaixo estão filtradas por evento e dia operacionais.";

  const handleRefresh = async () => {
    if (!eventId) return;
    await loadEvents();
    await loadEventSnapshot(eventId);
    const staticData = await loadStaticData(eventId);
    await loadWorkforceBase(eventId);
    await loadMealServices(eventId);
    await loadPendingOfflineMeals();

    if (!staticData?.stale && staticData?.nextDay) {
      await Promise.all([
        loadBalance({
          eventId,
          eventDayId: staticData.nextDay,
          eventShiftId: staticData.nextShift,
          mealServiceId,
          sector,
        }),
        loadMealHistory({
          eventId,
          eventDayId: staticData.nextDay,
          eventShiftId: staticData.nextShift,
          mealServiceId,
        }),
      ]);
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
      await Promise.all([loadBalance(), loadMealHistory()]);
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
    if (looksLikeQrTokenValue(externalQrForm.name)) {
      toast.error("O nome do colaborador não pode ser um QR/token.");
      return;
    }
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

  const visibleGeneratedExternalQrs = useMemo(
    () => generatedExternalQrs.filter((item) => !looksLikeQrTokenValue(item?.name || "")),
    [generatedExternalQrs]
  );


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
                  : selectedMealServiceData
                    ? `Automático: ${selectedMealServiceLabel}`
                    : "Automático pelo horário"}
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

      <div className="card p-4 space-y-4">
        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <p className="text-sm font-semibold text-white">Operação offline do Meals</p>
            <p className="mt-1 text-xs text-gray-500">{pendingOfflineMealsMessage}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <span className={isDeviceOnline ? "badge badge-green" : "badge badge-yellow"}>
              {isDeviceOnline ? "Conectado" : "Offline"}
            </span>
            <span className="badge badge-blue">
              {pendingOfflineMealsForCurrentEvent.length} pendente(s) {eventId ? "no evento" : "Meals"}
            </span>
            {eventId && pendingOfflineMealsOutsideCurrentEventCount > 0 && (
              <span className="badge badge-gray">
                +{pendingOfflineMealsOutsideCurrentEventCount} outro(s) evento(s)
              </span>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
            <p className="text-xs text-gray-500">Pendentes Meals</p>
            <p className="text-lg font-bold text-white">{pendingOfflineMeals.length}</p>
            <p className="mt-1 text-[11px] text-gray-500">Fila local total neste dispositivo.</p>
          </div>
          <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
            <p className="text-xs text-gray-500">Neste evento</p>
            <p className="text-lg font-bold text-white">{pendingOfflineMealsForCurrentEvent.length}</p>
            <p className="mt-1 text-[11px] text-gray-500">Pendências ligadas ao evento selecionado.</p>
          </div>
          <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
            <p className="text-xs text-gray-500">Neste dia</p>
            <p className="text-lg font-bold text-white">{pendingOfflineMealsForCurrentDay.length}</p>
            <p className="mt-1 text-[11px] text-gray-500">Recorte atual do dia operacional.</p>
          </div>
          <div className="rounded-xl border border-gray-800 bg-gray-950/60 p-3">
            <p className="text-xs text-gray-500">Sincronização</p>
            <p className={`text-lg font-bold ${syncingOfflineMeals ? "text-blue-400" : isDeviceOnline ? "text-green-400" : "text-amber-400"}`}>
              {syncingOfflineMeals ? "Em andamento" : isDeviceOnline ? "Pronta" : "Aguardando rede"}
            </p>
            <p className="mt-1 text-[11px] text-gray-500">
              A fila local também tenta sincronizar automaticamente quando a conexão volta.
            </p>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            className="btn-secondary inline-flex items-center gap-2"
            onClick={loadPendingOfflineMeals}
          >
            <RefreshCw size={14} /> Atualizar fila
          </button>
          <button
            type="button"
            className="btn-primary inline-flex items-center gap-2"
            onClick={syncPendingOfflineMeals}
            disabled={syncingOfflineMeals || !isDeviceOnline || pendingOfflineMeals.length === 0}
          >
            <RefreshCw size={14} className={syncingOfflineMeals ? "animate-spin" : ""} />
            {syncingOfflineMeals ? "Sincronizando..." : "Sincronizar pendentes"}
          </button>
        </div>

        {visiblePendingOfflineMeals.length === 0 ? (
          <p className="text-sm text-gray-400">
            Nenhuma refeição offline pendente neste recorte.
          </p>
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Captura</th>
                  <th>Pessoa / QR</th>
                  <th>Refeição</th>
                  <th>Contexto</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {visiblePendingOfflineMeals.map((record) => {
                  const payloadItem = record?.payload ?? {};
                  const qrToken = String(payloadItem?.qr_token || "").trim();
                  const participantName = participantNameByQrToken.get(qrToken) || "";
                  const shiftName = filteredShifts.find(
                    (shift) => String(shift?.id || "") === String(payloadItem?.event_shift_id || "")
                  )?.name;
                  const sectorLabel = String(payloadItem?.sector || record?.sector || "").trim();

                  return (
                    <tr key={`offline-meal-${record.offline_id}`}>
                      <td className="text-sm text-gray-300">
                        {formatDateTimeLabel(record.created_offline_at || payloadItem.consumed_at)}
                      </td>
                      <td>
                        <div className="space-y-1">
                          <p className="font-medium text-white">
                            {participantName || "Participante ainda não resolvido"}
                          </p>
                          <p className="text-xs text-gray-500">
                            QR {formatQrTokenSnippet(qrToken)}
                          </p>
                        </div>
                      </td>
                      <td className="text-sm text-gray-300">
                        {resolveQueuedMealServiceLabel(record, mealServices)}
                      </td>
                      <td className="text-sm text-gray-300">
                        {sectorLabel || "Sem setor"}{shiftName ? ` · ${shiftName}` : " · Sem turno"}
                      </td>
                      <td>
                        <span className="badge badge-yellow">Pendente local</span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

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
            <span className="badge badge-blue">{visibleGeneratedExternalQrs.length} gerado(s)</span>
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
          <p className="text-xs text-gray-500">
            O link para compartilhar aparece logo abaixo, com botões para copiar ou abrir direto no WhatsApp.
          </p>

          {visibleGeneratedExternalQrs.length > 0 && (
            <div className="space-y-2">
              <p className="text-xs text-gray-500 font-semibold">QRs gerados nesta sessão:</p>
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-3">
                {visibleGeneratedExternalQrs.map((item, i) => {
                  const inviteLink = buildInviteLink(item.qr_token);
                  const waLink = item.phone
                    ? `https://wa.me/${item.phone.replace(/\D/g, "")}?text=${encodeURIComponent("Acesse seu QR de refeição: " + inviteLink)}`
                    : null;
                  return (
                    <div key={i} className="rounded-2xl border border-gray-800 bg-gray-950/60 p-3 space-y-2">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <p className="font-semibold text-white text-sm">{item.name}</p>
                          <p className="text-xs text-gray-500">
                            {item.phone || "Sem telefone"} · {item.meals_per_day} refeições/dia · {item.valid_days || 1} dia(s)
                          </p>
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

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
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
              <p className="mt-1 text-[11px] text-gray-500">
                {mealServiceId && pricedMealServiceData
                  ? `Valor exibido pela refeição selecionada: ${formatMealServiceLabel(pricedMealServiceData)}`
                  : pricedMealServiceData
                    ? `Janela ativa do dispositivo: ${formatMealServiceLabel(pricedMealServiceData)}`
                    : "Valor dinâmico pela janela de horário configurada."}
              </p>
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
          {!showWorkforceFallback && roleComposition.leadershipMembers > 0 && (
            <span className="badge badge-blue">
              Liderança {roleComposition.leadershipMembers}
            </span>
          )}
          {!showWorkforceFallback && staffConfigBreakdown.members_using_default_fallback > 0 && (
            <span className="badge badge-yellow">
              Default {staffConfigBreakdown.members_using_default_fallback}
            </span>
          )}
          {!showWorkforceFallback && staffConfigBreakdown.members_with_ambiguous_baseline > 0 && (
            <span className="badge badge-red">
              Ambígua {staffConfigBreakdown.members_with_ambiguous_baseline}
            </span>
          )}
          {!showWorkforceFallback && externalTableRows.length > 0 && (
            <span className="badge badge-gray">
              Externos {externalTableRows.length}
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

      {!showWorkforceFallback && eventId && (
        <div className="card p-4 space-y-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="text-sm font-semibold text-white">Histórico recente de refeições</p>
              <p className="mt-1 text-xs text-gray-500">{mealHistoryMessage}</p>
            </div>
            <span className={mealHistoryLoading ? "badge badge-blue" : "badge badge-gray"}>
              {mealHistoryLoading ? "Carregando..." : `${visibleMealHistoryItems.length} registro(s)`}
            </span>
          </div>

          {!eventDayId ? (
            <p className="text-sm text-gray-400">
              O histórico fica disponível assim que um dia operacional for selecionado.
            </p>
          ) : mealHistoryLoading && visibleMealHistoryItems.length === 0 ? (
            <div className="py-6 text-center">
              <div className="spinner w-6 h-6 mx-auto" />
            </div>
          ) : visibleMealHistoryItems.length === 0 ? (
            <p className="text-sm text-gray-400">
              Nenhuma baixa recente foi encontrada para este recorte.
            </p>
          ) : (
            <div className="table-wrapper">
              <table className="table">
                <thead>
                  <tr>
                    <th>Horário</th>
                    <th>Pessoa</th>
                    <th>Refeição</th>
                    <th>Turno</th>
                    <th>Valor</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleMealHistoryItems.map((item) => (
                    <tr key={`meal-history-${item.id}`}>
                      <td className="text-sm text-gray-300">
                        {formatDateTimeLabel(item.consumed_at)}
                      </td>
                      <td>
                        <div className="space-y-1">
                          <p className="font-medium text-white">{item.person_name || `Participante #${item.participant_id}`}</p>
                          <p className="text-xs text-gray-500">{formatEventDayLabel(item.event_date)}</p>
                        </div>
                      </td>
                      <td className="text-sm text-gray-300">
                        {item.meal_service_label || item.meal_service_code || "Sem serviço"}
                      </td>
                      <td className="text-sm text-gray-300">
                        {item.shift_name || "Sem turno"}
                      </td>
                      <td className="text-sm text-gray-300">
                        {formatCurrency(item.unit_cost_applied)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      <div className="card p-4 space-y-3">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm font-semibold text-white">Membros por Setor (Breakdown)</p>
          <span className="badge badge-blue">
            {staffTableRows.length} membros na leitura atual
          </span>
        </div>
        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:flex lg:flex-nowrap lg:items-center lg:gap-2 lg:overflow-x-auto lg:pb-1">
          {breakdownEntries.map((entry) => (
            <div
              key={entry.key}
              className="rounded-lg border border-gray-800 bg-gray-950/40 px-3 py-2 flex items-center justify-between gap-3 min-w-0 lg:min-w-max lg:flex-none lg:py-1.5"
            >
              <span className={`text-xs text-gray-400 font-medium ${entry.capitalize ? "capitalize" : ""}`}>
                {entry.label}
              </span>
              <span className="text-xs text-white bg-gray-800 px-1.5 py-0.5 rounded shrink-0">
                {entry.count}
              </span>
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
            ) : (showWorkforceFallback ? tableRows.length === 0 : eventScopedPayloadItems.length === 0) ? (
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
                        {row.roleClass && row.roleClass !== "operational" && (
                          <span className="badge badge-blue">{getRoleClassLabel(row.roleClass)}</span>
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
