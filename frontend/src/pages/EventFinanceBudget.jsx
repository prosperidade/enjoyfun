import { useState, useEffect, useCallback } from "react";
import { BarChart3, Plus, Pencil, Trash2, X, TrendingUp, TrendingDown } from "lucide-react";
import api from "../lib/api";
import toast from "react-hot-toast";

const fmt = (v) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(v) || 0
  );

export default function EventFinanceBudget() {
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("");
  const [budget, setBudget] = useState(null);
  const [lines, setLines] = useState([]);
  const [categories, setCategories] = useState([]);
  const [costCenters, setCostCenters] = useState([]);
  const [loading, setLoading] = useState(false);

  // forms
  const [showNewBudget, setShowNewBudget] = useState(false);
  const [showNewLine, setShowNewLine] = useState(false);
  const [newBudget, setNewBudget] = useState({ total_budget: "", name: "Orçamento principal", notes: "" });
  const [newLine, setNewLine] = useState({ category_id: "", cost_center_id: "", description: "", budgeted_amount: "", notes: "" });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get("/events").then((r) => setEvents(r.data.data || [])).catch(() => {});
    api.get("/event-finance/categories").then((r) => setCategories(r.data.data || [])).catch(() => {});
  }, []);

  useEffect(() => {
    if (!eventId) return;
    api.get("/event-finance/cost-centers", { params: { event_id: eventId } })
      .then((r) => setCostCenters(r.data.data || [])).catch(() => {});
  }, [eventId]);

  const load = useCallback(() => {
    if (!eventId) return;
    setLoading(true);
    api.get("/event-finance/budgets", { params: { event_id: eventId } })
      .then((r) => {
        const budgets = r.data.data || [];
        if (budgets.length > 0) {
          const b = budgets[0];
          setBudget(b);
          // Carregar linhas com actuals
          return api.get(`/event-finance/budgets/${b.id}`);
        }
        setBudget(null);
        setLines([]);
        return null;
      })
      .then((r) => {
        if (r) setLines(r.data.data?.lines || []);
      })
      .catch(() => toast.error("Erro ao carregar orçamento."))
      .finally(() => setLoading(false));
  }, [eventId]);

  useEffect(() => { load(); }, [load]);

  const handleCreateBudget = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.post("/event-finance/budgets", {
        event_id: parseInt(eventId),
        name: newBudget.name,
        total_budget: parseFloat(newBudget.total_budget) || 0,
        notes: newBudget.notes || null,
      });
      toast.success("Orçamento criado!");
      setShowNewBudget(false);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao criar orçamento.");
    } finally {
      setSaving(false);
    }
  };

  const handleCreateLine = async (e) => {
    e.preventDefault();
    if (!newLine.category_id || !newLine.cost_center_id) {
      toast.error("Categoria e centro de custo são obrigatórios.");
      return;
    }
    setSaving(true);
    try {
      await api.post("/event-finance/budget-lines", {
        event_id: parseInt(eventId),
        budget_id: budget.id,
        category_id: parseInt(newLine.category_id),
        cost_center_id: parseInt(newLine.cost_center_id),
        description: newLine.description || null,
        budgeted_amount: parseFloat(newLine.budgeted_amount) || 0,
        notes: newLine.notes || null,
      });
      toast.success("Linha adicionada!");
      setShowNewLine(false);
      setNewLine({ category_id: "", cost_center_id: "", description: "", budgeted_amount: "", notes: "" });
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao criar linha.");
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteLine = async (lineId) => {
    if (!window.confirm("Remover esta linha do orçamento?")) return;
    try {
      await api.delete(`/event-finance/budget-lines/${lineId}`);
      toast.success("Linha removida.");
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao remover linha.");
    }
  };

  const summary = budget?.summary;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <BarChart3 size={22} className="text-cyan-400" /> Orçamento
          </h1>
          <p className="text-gray-500 text-sm">Previsto × comprometido × pago</p>
        </div>
        <select className="select w-auto" value={eventId} onChange={(e) => setEventId(e.target.value)}>
          <option value="">Selecionar evento...</option>
          {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
        </select>
      </div>

      {!eventId && (
        <div className="card border-dashed border-white/10 text-center py-16 text-gray-500">
          Selecione um evento para ver o orçamento.
        </div>
      )}

      {eventId && loading && <div className="text-center py-12 text-gray-500">Carregando...</div>}

      {eventId && !loading && !budget && (
        <div className="card border-dashed border-white/10 text-center py-12 space-y-4">
          <p className="text-gray-500">Nenhum orçamento encontrado para este evento.</p>
          {!showNewBudget && (
            <button onClick={() => setShowNewBudget(true)} className="btn-primary">
              <Plus size={16} /> Criar Orçamento
            </button>
          )}
          {showNewBudget && (
            <form onSubmit={handleCreateBudget} className="max-w-sm mx-auto space-y-3 text-left">
              <div>
                <label className="input-label">Nome</label>
                <input className="input" value={newBudget.name}
                  onChange={(e) => setNewBudget(f => ({ ...f, name: e.target.value }))} />
              </div>
              <div>
                <label className="input-label">Valor Total (R$) *</label>
                <input className="input" type="number" min="0" step="0.01" value={newBudget.total_budget}
                  onChange={(e) => setNewBudget(f => ({ ...f, total_budget: e.target.value }))} placeholder="0,00" />
              </div>
              <div className="flex gap-2">
                <button type="submit" disabled={saving} className="btn-primary flex-1">{saving ? "..." : "Criar"}</button>
                <button type="button" onClick={() => setShowNewBudget(false)} className="btn-outline flex-1">Cancelar</button>
              </div>
            </form>
          )}
        </div>
      )}

      {eventId && !loading && budget && (
        <>
          {/* Summary cards */}
          {summary && (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div className="card border-white/5 text-center">
                <p className="text-xs text-gray-500 uppercase">Orçamento</p>
                <p className="text-xl font-bold text-white mt-1">{fmt(summary.total_budget)}</p>
              </div>
              <div className="card border-white/5 text-center">
                <p className="text-xs text-gray-500 uppercase">Comprometido</p>
                <p className={`text-xl font-bold mt-1 ${summary.is_over_budget ? "text-red-400" : "text-yellow-400"}`}>
                  {fmt(summary.committed)}
                </p>
              </div>
              <div className="card border-white/5 text-center">
                <p className="text-xs text-gray-500 uppercase">Pago</p>
                <p className="text-xl font-bold text-green-400 mt-1">{fmt(summary.paid)}</p>
              </div>
              <div className="card border-white/5 text-center">
                <p className="text-xs text-gray-500 uppercase">Saldo Livre</p>
                <p className={`text-xl font-bold mt-1 ${summary.is_over_budget ? "text-red-400" : "text-cyan-400"}`}>
                  {summary.is_over_budget ? "-" + fmt(summary.overage) : fmt(summary.budget_remaining)}
                </p>
              </div>
            </div>
          )}

          {/* Linhas do orçamento */}
          <div className="card border-white/5">
            <div className="flex items-center justify-between mb-4">
              <h2 className="section-title">Linhas do Orçamento</h2>
              <button onClick={() => setShowNewLine(!showNewLine)} className="btn-outline text-sm gap-1">
                <Plus size={14} /> Adicionar Linha
              </button>
            </div>

            {showNewLine && (
              <form onSubmit={handleCreateLine} className="card border-cyan-800/30 grid grid-cols-2 gap-3 mb-4">
                <div>
                  <label className="input-label">Categoria *</label>
                  <select className="select" value={newLine.category_id}
                    onChange={(e) => setNewLine(f => ({ ...f, category_id: e.target.value }))}>
                    <option value="">Selecionar...</option>
                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="input-label">Centro de Custo *</label>
                  <select className="select" value={newLine.cost_center_id}
                    onChange={(e) => setNewLine(f => ({ ...f, cost_center_id: e.target.value }))}>
                    <option value="">Selecionar...</option>
                    {costCenters.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="input-label">Descrição</label>
                  <input className="input" value={newLine.description}
                    onChange={(e) => setNewLine(f => ({ ...f, description: e.target.value }))} />
                </div>
                <div>
                  <label className="input-label">Previsto (R$)</label>
                  <input className="input" type="number" min="0" step="0.01" value={newLine.budgeted_amount}
                    onChange={(e) => setNewLine(f => ({ ...f, budgeted_amount: e.target.value }))} placeholder="0,00" />
                </div>
                <div className="col-span-2 flex gap-2">
                  <button type="submit" disabled={saving} className="btn-primary flex-1 text-sm">{saving ? "Salvando..." : "Adicionar"}</button>
                  <button type="button" onClick={() => setShowNewLine(false)} className="btn-outline flex-1 text-sm">Cancelar</button>
                </div>
              </form>
            )}

            {lines.length === 0 ? (
              <p className="text-gray-500 text-sm">Nenhuma linha cadastrada.</p>
            ) : (
              <div className="table-wrapper">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Categoria</th>
                      <th>Centro</th>
                      <th>Descrição</th>
                      <th className="text-right">Previsto</th>
                      <th className="text-right">Comprometido</th>
                      <th className="text-right">Variação</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((l) => (
                      <tr key={l.id} className={l.is_over ? "bg-red-900/5" : ""}>
                        <td className="text-sm">{l.category_name}</td>
                        <td className="text-sm text-gray-400">{l.cost_center_name}</td>
                        <td className="text-sm text-gray-400">{l.description || "—"}</td>
                        <td className="text-right tabular-nums">{fmt(l.budgeted_amount)}</td>
                        <td className="text-right tabular-nums">{fmt(l.committed)}</td>
                        <td className="text-right tabular-nums">
                          <span className={`flex items-center justify-end gap-1 ${l.is_over ? "text-red-400" : "text-green-400"}`}>
                            {l.is_over ? <TrendingUp size={12} /> : <TrendingDown size={12} />}
                            {fmt(Math.abs(l.variance))}
                          </span>
                        </td>
                        <td>
                          <button onClick={() => handleDeleteLine(l.id)}
                            className="p-1 text-gray-600 hover:text-red-400 transition-colors">
                            <Trash2 size={13} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
