import { useState, useEffect, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import {
  Receipt,
  Plus,
  Search,
  X,
  ChevronRight,
  AlertCircle,
  XCircle,
} from "lucide-react";
import api from "../lib/api";
import toast from "react-hot-toast";

const fmt = (v) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(v) || 0
  );

const STATUS_LABELS = {
  pending: { label: "Pendente", cls: "badge-yellow" },
  partial: { label: "Pago parcial", cls: "badge-yellow" },
  paid: { label: "Pago", cls: "badge-green" },
  overdue: { label: "Vencido", cls: "badge-red" },
  cancelled: { label: "Cancelado", cls: "badge-gray" },
};

function NewPayableModal({ eventId, categories, costCenters, suppliers, onSaved, onClose }) {
  const [form, setForm] = useState({
    event_id: eventId,
    description: "",
    category_id: "",
    cost_center_id: "",
    supplier_id: "",
    amount: "",
    due_date: "",
    payment_method: "",
    source_type: "internal",
    notes: "",
  });
  const [saving, setSaving] = useState(false);

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!form.description || !form.category_id || !form.cost_center_id || !form.amount || !form.due_date) {
      toast.error("Preencha todos os campos obrigatórios.");
      return;
    }
    setSaving(true);
    try {
      await api.post("/event-finance/payables", {
        ...form,
        amount: parseFloat(form.amount),
        category_id: parseInt(form.category_id),
        cost_center_id: parseInt(form.cost_center_id),
        supplier_id: form.supplier_id ? parseInt(form.supplier_id) : null,
      });
      toast.success("Conta criada com sucesso!");
      onSaved();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao criar conta.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
      <div className="card max-w-2xl w-full border-cyan-800/40 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h2 className="section-title">Nova Conta a Pagar</h2>
          <button onClick={onClose} className="text-gray-500 hover:text-white">
            <X size={20} />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
          <div className="col-span-2">
            <label className="input-label">Descrição *</label>
            <input className="input" value={form.description} onChange={(e) => set("description", e.target.value)} placeholder="Ex: Cachê Artista..." />
          </div>
          <div>
            <label className="input-label">Categoria *</label>
            <select className="select" value={form.category_id} onChange={(e) => set("category_id", e.target.value)}>
              <option value="">Selecionar...</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div>
            <label className="input-label">Centro de Custo *</label>
            <select className="select" value={form.cost_center_id} onChange={(e) => set("cost_center_id", e.target.value)}>
              <option value="">Selecionar...</option>
              {costCenters.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div>
            <label className="input-label">Valor *</label>
            <input className="input" type="number" min="0" step="0.01" value={form.amount} onChange={(e) => set("amount", e.target.value)} placeholder="0,00" />
          </div>
          <div>
            <label className="input-label">Vencimento *</label>
            <input className="input" type="date" value={form.due_date} onChange={(e) => set("due_date", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Fornecedor</label>
            <select className="select" value={form.supplier_id} onChange={(e) => set("supplier_id", e.target.value)}>
              <option value="">Nenhum</option>
              {suppliers.map((s) => <option key={s.id} value={s.id}>{s.legal_name}</option>)}
            </select>
          </div>
          <div>
            <label className="input-label">Forma de Pagamento</label>
            <select className="select" value={form.payment_method} onChange={(e) => set("payment_method", e.target.value)}>
              <option value="">Não definida</option>
              <option value="pix">PIX</option>
              <option value="ted">TED</option>
              <option value="dinheiro">Dinheiro</option>
              <option value="cartao">Cartão</option>
              <option value="boleto">Boleto</option>
            </select>
          </div>
          <div className="col-span-2">
            <label className="input-label">Observações</label>
            <textarea className="input resize-none" rows={2} value={form.notes} onChange={(e) => set("notes", e.target.value)} />
          </div>
          <div className="col-span-2 flex gap-3">
            <button type="submit" disabled={saving} className="btn-primary flex-1">
              {saving ? "Salvando..." : "Criar Conta"}
            </button>
            <button type="button" onClick={onClose} className="btn-outline flex-1">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function EventFinancePayables() {
  const navigate = useNavigate();
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("");
  const [payables, setPayables] = useState([]);
  const [categories, setCategories] = useState([]);
  const [costCenters, setCostCenters] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [showNew, setShowNew] = useState(false);

  // Filtros
  const [search, setSearch] = useState("");
  const [filterStatus, setFilterStatus] = useState("");
  const [filterCategory, setFilterCategory] = useState("");

  useEffect(() => {
    api.get("/events").then((r) => setEvents(r.data.data || [])).catch(() => {});
    api.get("/event-finance/categories").then((r) => setCategories(r.data.data || [])).catch(() => {});
    api.get("/event-finance/suppliers").then((r) => setSuppliers(r.data.data || [])).catch(() => {});
  }, []);

  useEffect(() => {
    if (!eventId) return;
    api.get("/event-finance/cost-centers", { params: { event_id: eventId } })
      .then((r) => setCostCenters(r.data.data || [])).catch(() => {});
  }, [eventId]);

  const fetchPayables = useCallback(() => {
    if (!eventId) return;
    setLoading(true);
    const params = { event_id: eventId };
    if (filterStatus) params.status = filterStatus;
    if (filterCategory) params.category_id = filterCategory;

    api.get("/event-finance/payables", { params })
      .then((r) => setPayables(r.data.data || []))
      .catch(() => toast.error("Erro ao carregar contas."))
      .finally(() => setLoading(false));
  }, [eventId, filterStatus, filterCategory]);

  useEffect(() => { fetchPayables(); }, [fetchPayables]);

  const filtered = payables.filter((p) =>
    p.description?.toLowerCase().includes(search.toLowerCase()) ||
    p.supplier_name?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="space-y-6">
      {showNew && (
        <NewPayableModal
          eventId={parseInt(eventId)}
          categories={categories}
          costCenters={costCenters}
          suppliers={suppliers}
          onSaved={() => { setShowNew(false); fetchPayables(); }}
          onClose={() => setShowNew(false)}
        />
      )}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Receipt size={22} className="text-cyan-400" /> Contas a Pagar
          </h1>
          <p className="text-gray-500 text-sm">
            {filtered.length} conta(s) encontrada(s)
          </p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <select className="select w-auto" value={eventId} onChange={(e) => setEventId(e.target.value)}>
            <option value="">Selecionar evento...</option>
            {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
          </select>
          <button onClick={() => setShowNew(true)} disabled={!eventId} className="btn-primary">
            <Plus size={16} /> Nova Conta
          </button>
        </div>
      </div>

      {/* Filtros */}
      <div className="flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-[200px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" />
          <input
            className="input pl-8"
            placeholder="Buscar descrição ou fornecedor..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <select className="select w-auto" value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
          <option value="">Todos os status</option>
          {Object.entries(STATUS_LABELS).map(([k, v]) => (
            <option key={k} value={k}>{v.label}</option>
          ))}
        </select>
        <select className="select w-auto" value={filterCategory} onChange={(e) => setFilterCategory(e.target.value)}>
          <option value="">Todas as categorias</option>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        {(filterStatus || filterCategory || search) && (
          <button onClick={() => { setFilterStatus(""); setFilterCategory(""); setSearch(""); }} className="btn-outline gap-1">
            <XCircle size={14} /> Limpar
          </button>
        )}
      </div>

      {!eventId && (
        <div className="card border-dashed border-white/10 text-center py-16 text-gray-500">
          Selecione um evento para visualizar as contas.
        </div>
      )}

      {eventId && (
        <div className="table-wrapper">
          {loading ? (
            <p className="text-center text-gray-500 py-10">Carregando...</p>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Descrição</th>
                  <th>Categoria</th>
                  <th>Centro</th>
                  <th>Vencimento</th>
                  <th className="text-right">Valor</th>
                  <th className="text-right">Pago</th>
                  <th className="text-right">Saldo</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="text-center text-gray-500 py-10">
                      <AlertCircle className="inline mr-2 text-gray-600" size={16} />
                      Nenhuma conta encontrada
                    </td>
                  </tr>
                ) : (
                  filtered.map((p) => {
                    const st = STATUS_LABELS[p.status] || { label: p.status, cls: "badge-gray" };
                    const isOverdue = p.status === "overdue";
                    return (
                      <tr
                        key={p.id}
                        className={`cursor-pointer hover:bg-white/5 ${isOverdue ? "bg-red-900/5" : ""}`}
                        onClick={() => navigate(`/finance/payables/${p.id}`)}
                      >
                        <td className="font-medium text-white max-w-[180px] truncate">{p.description}</td>
                        <td className="text-sm text-gray-400">{p.category_name}</td>
                        <td className="text-sm text-gray-400">{p.cost_center_name}</td>
                        <td className={`text-sm font-mono ${isOverdue ? "text-red-400" : "text-gray-400"}`}>
                          {p.due_date}
                        </td>
                        <td className="text-right tabular-nums">{fmt(p.amount)}</td>
                        <td className="text-right tabular-nums text-green-400">{fmt(p.paid_amount)}</td>
                        <td className="text-right tabular-nums text-yellow-400">{fmt(p.remaining_amount)}</td>
                        <td><span className={st.cls}>{st.label}</span></td>
                        <td>
                          <ChevronRight size={16} className="text-gray-600" />
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
