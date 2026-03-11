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
  };
  return labels[source] || "Origem não informada";
}

export default function MealsControl() {
  const [events, setEvents] = useState([]);
  const [eventDays, setEventDays] = useState([]);
  const [eventShifts, setEventShifts] = useState([]);
  const [roles, setRoles] = useState([]);

  const [eventId, setEventId] = useState("");
  const [eventDayId, setEventDayId] = useState("");
  const [eventShiftId, setEventShiftId] = useState("");
  const [roleId, setRoleId] = useState("");
  const [sector, setSector] = useState("");

  const [loading, setLoading] = useState(false);
  const [registering, setRegistering] = useState(false);
  const [qrInput, setQrInput] = useState("");
  const [payload, setPayload] = useState(createEmptyPayload);
  const [workforceBaseItems, setWorkforceBaseItems] = useState([]);
  const [mealUnitCost, setMealUnitCost] = useState(0);
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
      setEventId(String(list[0].id));
    }
  };

  const loadStaticData = async (evtId) => {
    if (!evtId) return;
    const [daysRes, shiftsRes, rolesRes] = await Promise.all([
      api.get(`/event-days?event_id=${evtId}`),
      api.get(`/event-shifts?event_id=${evtId}`),
      api.get("/workforce/roles"),
    ]);
    const days = daysRes.data?.data || [];
    const shifts = shiftsRes.data?.data || [];
    const nextRoles = rolesRes.data?.data || [];
    setEventDays(days);
    setEventShifts(shifts);
    setRoles(nextRoles);
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
          role_id: roleId || undefined,
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
        mealUnitCost ??
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
      setMealUnitCost(unitCost);
      setMealCostDraft(unitCost);
    } catch {
      setMealUnitCost(0);
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
    loadBalance();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eventId, eventDayId, eventShiftId, roleId, sector]);

  const availableSectors = useMemo(() => {
    const values = [
      ...workforceBaseItems.map((item) => String(item.sector || "").trim().toLowerCase()),
      ...roles.map((role) => String(role.sector || "").trim().toLowerCase()),
    ].filter(Boolean);
    return [...new Set(values)].sort((a, b) => a.localeCompare(b, "pt-BR"));
  }, [roles, workforceBaseItems]);

  useEffect(() => {
    if (sector && !availableSectors.includes(String(sector).trim().toLowerCase())) {
      setSector("");
    }
  }, [availableSectors, sector]);

  useEffect(() => {
    if (roleId && !roles.some((role) => String(role.id) === String(roleId))) {
      setRoleId("");
    }
  }, [roleId, roles]);

  const filteredWorkforceItems = useMemo(() => {
    return workforceBaseItems.filter((item) => {
      if (roleId && String(item.role_id) !== String(roleId)) {
        return false;
      }
      if (sector && String(item.sector || "").trim().toLowerCase() !== String(sector).trim().toLowerCase()) {
        return false;
      }
      return true;
    });
  }, [roleId, sector, workforceBaseItems]);

  const workforceSummary = useMemo(() => {
    const sectors = new Set();
    let mealsPerDayTotal = 0;
    let assignmentsWithShift = 0;

    filteredWorkforceItems.forEach((item) => {
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
      members: filteredWorkforceItems.length,
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
    enabled: true,
    meal_unit_cost: summary.meal_unit_cost ?? mealUnitCost,
    estimated_day_cost_total: summary.estimated_day_cost_total ?? 0,
    consumed_day_cost_total: summary.consumed_day_cost_total ?? 0,
    remaining_day_cost_total: summary.remaining_day_cost_total ?? 0,
  };
  const diagnostics = payload.diagnostics || null;

  const currentMealUnitCost = Number(
    projectionSummary.meal_unit_cost ?? summary.meal_unit_cost ?? mealUnitCost ?? 0
  );
  const projectionEnabled = Boolean(projectionSummary.enabled);
  const diagnosticsIssues = useMemo(() => diagnostics?.issues || [], [diagnostics]);
  const showWorkforceFallback = Boolean(eventId) && (!eventDayId || eventDays.length === 0);
  const emptyTableMessage = !eventId
    ? "Selecione um evento para iniciar a leitura operacional."
    : eventDays.length === 0
      ? workforceSummary.members > 0
        ? "Este evento ainda não possui dias operacionais, mas já há equipe alocada no Workforce."
        : "Este evento ainda não possui dias operacionais cadastrados."
      : !eventDayId
        ? workforceSummary.members > 0
          ? "Selecione um dia operacional para trocar da base do Workforce para o saldo real de Meals."
          : "Selecione um dia operacional para carregar o saldo."
        : diagnosticsIssues.includes("no_assignments_in_scope")
          ? "Nenhum membro com assignment válido foi encontrado neste recorte operacional."
          : roleId || sector
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

    if (eventDays.length === 0) {
      list.push({
        tone: "warn",
        title: "Evento sem dias operacionais",
        body: workforceSummary.members > 0
          ? `Este evento ainda não possui \`event_days\`, então o saldo por dia não pode ser calculado. A base atual do Workforce mostra ${workforceSummary.members} membros em ${workforceSummary.sectorsCount} setor(es).`
          : "Este evento ainda não possui `event_days`. O saldo por dia não pode ser carregado neste contexto.",
      });
      if (workforceSummary.members > 0 && workforceSummary.assignmentsWithShift <= 0) {
        list.push({
          tone: "info",
          title: "Equipe sem vínculo de turno",
          body: "Os assignments do Workforce deste evento ainda não estão vinculados a turnos.",
        });
      }
      return list;
    }

    if (!eventDayId) {
      list.push({
        tone: "warn",
        title: "Dia não selecionado",
        body: "Selecione um dia operacional para consultar o saldo de refeições.",
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

    if (diagnostics?.status === "partial") {
      list.push({
        tone: "warn",
        title: "Leitura operacional parcial",
        body: "A base deste recorte está incompleta. Parte do saldo pode estar apoiada em fallback ou ausência de consumo real.",
      });
    }

    if (diagnosticsIssues.includes("no_real_meal_consumption_for_day")) {
      list.push({
        tone: "info",
        title: "Saldo ainda teórico",
        body: "Ainda não há consumo real registrado para o dia selecionado. O saldo exibido está apoiado apenas na cota configurada.",
      });
    }

    if (diagnosticsIssues.includes("members_using_default_meal_fallback")) {
      list.push({
        tone: "warn",
        title: "Equipe com fallback default",
        body: "Parte da equipe está sem configuração específica de refeições e está usando o fallback operacional padrão.",
      });
    }

    if (diagnosticsIssues.includes("meal_unit_cost_schema_unavailable")) {
      list.push({
        tone: "neutral",
        title: "Custo indisponível",
        body: "A camada de custo de refeição não está disponível neste ambiente porque `meal_unit_cost` não existe no schema real.",
      });
    } else if (diagnosticsIssues.includes("meal_unit_cost_not_configured")) {
      list.push({
        tone: "neutral",
        title: "Custo não configurado",
        body: "O custo unitário de refeição ainda não foi configurado. A leitura financeira permanece zerada.",
      });
    }

    return list;
  }, [
    diagnostics?.status,
    diagnosticsIssues,
    eventDayId,
    eventDays.length,
    eventId,
    filteredShifts.length,
    workforceSummary.assignmentsWithShift,
    workforceSummary.members,
    workforceSummary.sectorsCount,
  ]);

  const bannerClassByTone = {
    neutral: "border-gray-800 bg-gray-900/70 text-gray-200",
    info: "border-blue-900/60 bg-blue-950/30 text-blue-100",
    warn: "border-amber-900/60 bg-amber-950/30 text-amber-100",
  };

  const saveMealCost = async (e) => {
    e.preventDefault();
    const safeValue = Math.max(0, Number(mealCostDraft || 0));
    setSavingMealCost(true);
    try {
      const res = await api.get("/organizer-finance/settings");
      const settings = res.data?.data || {};
      await api.put("/organizer-finance/settings", {
        currency: settings.currency || "BRL",
        tax_rate: Number(settings.tax_rate ?? 0),
        meal_unit_cost: safeValue
      });
      setMealUnitCost(safeValue);
      toast.success("Valor unitário de refeição atualizado.");
      setMealCostModalOpen(false);
      await loadBalance();
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
          onClick={loadBalance}
          disabled={loading}
        >
          <RefreshCw size={16} className={loading ? "animate-spin" : ""} /> Atualizar
        </button>
        <button
          className="btn-secondary flex items-center gap-2"
          onClick={() => {
            setMealCostDraft(currentMealUnitCost);
            setMealCostModalOpen(true);
          }}
          disabled={!projectionEnabled}
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

      <div className="card p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
        <select className="input" value={eventId} onChange={(e) => setEventId(e.target.value)}>
          <option value="">Selecione o evento...</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.name}
            </option>
          ))}
        </select>

        <select className="input" value={eventDayId} onChange={(e) => setEventDayId(e.target.value)}>
          <option value="">Selecione o dia...</option>
          {eventDays.map((d) => (
            <option key={d.id} value={d.id}>
              {d.date}
            </option>
          ))}
        </select>

        <select className="input" value={eventShiftId} onChange={(e) => setEventShiftId(e.target.value)}>
          <option value="">{filteredShifts.length > 0 ? "Todos os turnos" : "Todos os turnos (sem turnos cadastrados)"}</option>
          {filteredShifts.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>

        <select className="input" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
          <option value="">Todos os cargos</option>
          {roles.map((r) => (
            <option key={r.id} value={r.id}>
              {r.name}
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
              disabled={registering}
            />
          </div>
          <button className="btn-primary whitespace-nowrap" disabled={registering}>
            {registering ? "Registrando..." : "Registrar Refeição"}
          </button>
        </div>
      </form>

      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div className="card p-3">
          <p className="text-xs text-gray-500">{showWorkforceFallback ? "Membros workforce" : "Membros"}</p>
          <p className="text-xl font-bold text-white">{showWorkforceFallback ? workforceSummary.members : operationalSummary.members}</p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">{showWorkforceFallback ? "Refeições configuradas" : "Cota dia"}</p>
          <p className="text-xl font-bold text-white">{showWorkforceFallback ? workforceSummary.mealsPerDayTotal : operationalSummary.meals_per_day_total}</p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">{showWorkforceFallback ? "Assignments com turno" : "Consumidas dia"}</p>
          <p className="text-xl font-bold text-amber-400">{showWorkforceFallback ? workforceSummary.assignmentsWithShift : operationalSummary.consumed_day_total}</p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">{showWorkforceFallback ? "Assignments sem turno" : "Saldo dia"}</p>
          <p className="text-xl font-bold text-green-400">{showWorkforceFallback ? workforceSummary.assignmentsWithoutShift : operationalSummary.remaining_day_total}</p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">{showWorkforceFallback ? "Setores no evento" : "Consumidas turno"}</p>
          <p className="text-xl font-bold text-blue-400">{showWorkforceFallback ? workforceSummary.sectorsCount : operationalSummary.consumed_shift_total}</p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div className="card p-3">
          <p className="text-xs text-gray-500">Valor unitário refeição</p>
          <p className="text-lg font-bold text-white">{projectionEnabled ? formatCurrency(currentMealUnitCost) : "Indisponível"}</p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">Custo estimado (dia)</p>
          <p className="text-lg font-bold text-emerald-400">
            {projectionEnabled ? formatCurrency(projectionSummary.estimated_day_cost_total) : "Indisponível"}
          </p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">Custo consumido (dia)</p>
          <p className="text-lg font-bold text-amber-400">
            {projectionEnabled ? formatCurrency(projectionSummary.consumed_day_cost_total) : "Indisponível"}
          </p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">Custo saldo (dia)</p>
          <p className="text-lg font-bold text-cyan-400">
            {projectionEnabled ? formatCurrency(projectionSummary.remaining_day_cost_total) : "Indisponível"}
          </p>
        </div>
      </div>

      {diagnosticsIssues.length > 0 && (
        <div className="card p-4">
          <p className="text-sm font-semibold text-white">Leitura operacional do recorte</p>
          <ul className="mt-2 space-y-1 text-sm text-gray-300">
            {diagnosticsIssues.map((issue) => (
              <li key={issue}>- {getIssueLabel(issue)}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Cargo</th>
              <th>Setor</th>
              <th>{showWorkforceFallback ? "Refeições/dia" : "Cota/dia"}</th>
              <th>{showWorkforceFallback ? "Turno configurado" : "Consumidas dia"}</th>
              <th>{showWorkforceFallback ? "Origem da leitura" : "Saldo"}</th>
              <th>{showWorkforceFallback ? "Vínculo de turno" : "Consumidas turno"}</th>
              <th>Ref QR</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={8} className="py-8 text-center">
                  <div className="spinner w-6 h-6 mx-auto" />
                </td>
              </tr>
            ) : (showWorkforceFallback ? filteredWorkforceItems.length === 0 : payload.items.length === 0) ? (
              <tr>
                <td colSpan={8} className="py-8 text-center text-sm text-gray-500">
                  {emptyTableMessage}
                </td>
              </tr>
            ) : (
              (showWorkforceFallback ? filteredWorkforceItems : payload.items).map((item) => (
                <tr key={item.participant_id}>
                  <td className="text-white font-medium">{showWorkforceFallback ? item.person_name : item.participant_name}</td>
                  <td>{item.role_name}</td>
                  <td>{item.sector || "geral"}</td>
                  <td>
                    <div className="flex flex-col">
                      <span>{item.meals_per_day}</span>
                      {!showWorkforceFallback && (
                        <span className="text-[11px] text-gray-500">{getConfigSourceLabel(item.config_source)}</span>
                      )}
                    </div>
                  </td>
                  <td>{showWorkforceFallback ? (item.shift_name || "Sem turno") : item.consumed_day}</td>
                  <td className={showWorkforceFallback ? "text-gray-300" : "font-semibold text-green-400"}>
                    {showWorkforceFallback ? "Base Workforce" : item.remaining_day}
                  </td>
                  <td>{showWorkforceFallback ? (item.shift_id ? `Turno #${item.shift_id}` : "Sem vínculo") : item.consumed_shift}</td>
                  <td className="text-xs text-gray-500">
                    {(item.qr_token || "").slice(-10) || "-"}
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
                />
              </label>
              <p className="text-xs text-gray-500">
                O consumo total é calculado automaticamente com base em turnos e refeições configurados no Workforce.
              </p>

              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setMealCostModalOpen(false)} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={savingMealCost} className="btn-primary flex items-center gap-2">
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
