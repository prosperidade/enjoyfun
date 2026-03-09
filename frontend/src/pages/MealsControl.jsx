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
  const [payload, setPayload] = useState({ summary: null, items: [] });
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
    setEventDays(days);
    setEventShifts(shifts);
    setRoles(rolesRes.data?.data || []);

    if (days.length > 0) {
      const defaultDay = String(days[0].id);
      setEventDayId((prev) => prev || defaultDay);
    } else {
      setEventDayId("");
    }
  };

  const loadBalance = async () => {
    if (!eventId || !eventDayId) return;
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
      setPayload({
        summary: res.data?.data?.summary || null,
        items: res.data?.data?.items || [],
      });
      const unit = Number(res.data?.data?.summary?.meal_unit_cost ?? mealUnitCost ?? 0);
      setMealUnitCost(unit);
    } catch (err) {
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
    if (!eventId) return;
    loadStaticData(eventId).catch((err) =>
      toast.error(err.response?.data?.message || "Erro ao carregar dias/turnos.")
    );
  }, [eventId]);

  useEffect(() => {
    if (!eventDayId) {
      setEventShiftId("");
      return;
    }
    const valid = filteredShifts.some((s) => String(s.id) === String(eventShiftId));
    if (!valid) {
      setEventShiftId(filteredShifts[0] ? String(filteredShifts[0].id) : "");
    }
  }, [eventDayId, eventShiftId, filteredShifts]);

  useEffect(() => {
    loadBalance();
  }, [eventId, eventDayId, eventShiftId, roleId, sector]);

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

  const currentMealUnitCost = Number(summary.meal_unit_cost ?? mealUnitCost ?? 0);

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
        >
          <Settings2 size={16} /> Valor Refeição
        </button>
      </div>

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
          <option value="">Todos os turnos</option>
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

        <input
          className="input"
          placeholder="Filtrar setor (ex: seguranca)"
          value={sector}
          onChange={(e) => setSector(e.target.value)}
        />
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
        <div className="card p-3"><p className="text-xs text-gray-500">Membros</p><p className="text-xl font-bold text-white">{summary.members}</p></div>
        <div className="card p-3"><p className="text-xs text-gray-500">Cota dia</p><p className="text-xl font-bold text-white">{summary.meals_per_day_total}</p></div>
        <div className="card p-3"><p className="text-xs text-gray-500">Consumidas dia</p><p className="text-xl font-bold text-amber-400">{summary.consumed_day_total}</p></div>
        <div className="card p-3"><p className="text-xs text-gray-500">Saldo dia</p><p className="text-xl font-bold text-green-400">{summary.remaining_day_total}</p></div>
        <div className="card p-3"><p className="text-xs text-gray-500">Consumidas turno</p><p className="text-xl font-bold text-blue-400">{summary.consumed_shift_total}</p></div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div className="card p-3">
          <p className="text-xs text-gray-500">Valor unitário refeição</p>
          <p className="text-lg font-bold text-white">
            R$ {Number(currentMealUnitCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
          </p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">Custo estimado (dia)</p>
          <p className="text-lg font-bold text-emerald-400">
            R$ {Number(summary.estimated_day_cost_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
          </p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">Custo consumido (dia)</p>
          <p className="text-lg font-bold text-amber-400">
            R$ {Number(summary.consumed_day_cost_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
          </p>
        </div>
        <div className="card p-3">
          <p className="text-xs text-gray-500">Custo saldo (dia)</p>
          <p className="text-lg font-bold text-cyan-400">
            R$ {Number(summary.remaining_day_cost_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
          </p>
        </div>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Cargo</th>
              <th>Setor</th>
              <th>Cota/dia</th>
              <th>Consumidas dia</th>
              <th>Saldo</th>
              <th>Consumidas turno</th>
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
            ) : payload.items.length === 0 ? (
              <tr>
                <td colSpan={8} className="py-8 text-center text-sm text-gray-500">
                  Nenhum membro encontrado para os filtros atuais.
                </td>
              </tr>
            ) : (
              payload.items.map((item) => (
                <tr key={item.participant_id}>
                  <td className="text-white font-medium">{item.participant_name}</td>
                  <td>{item.role_name}</td>
                  <td>{item.sector || "geral"}</td>
                  <td>{item.meals_per_day}</td>
                  <td>{item.consumed_day}</td>
                  <td className="font-semibold text-green-400">{item.remaining_day}</td>
                  <td>{item.consumed_shift}</td>
                  <td className="text-xs text-gray-500">{(item.qr_token || "").slice(-10) || "-"}</td>
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
