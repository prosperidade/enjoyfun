import { useState, useEffect, useCallback, useDeferredValue } from "react";
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
import { useEventScope } from "../context/EventScopeContext";
import api from "../lib/api";
import toast from "react-hot-toast";
import Pagination from "../components/Pagination";
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from "../lib/pagination";

const PAGE_SIZE = 25;

const fmt = (value) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(value) || 0
  );

const STATUS_LABELS = {
  pending: { label: "Pendente", cls: "badge-yellow" },
  partial: { label: "Pago parcial", cls: "badge-yellow" },
  paid: { label: "Pago", cls: "badge-green" },
  overdue: { label: "Vencido", cls: "badge-red" },
  cancelled: { label: "Cancelado", cls: "badge-gray" },
};

const SOURCE_TYPE_OPTIONS = [
  { value: "internal", label: "Interno" },
  { value: "supplier", label: "Fornecedor" },
  { value: "artist", label: "Artista / Cachê" },
  { value: "logistics", label: "Logística do Artista" },
];

function formatArtistBookingLabel(booking) {
  const stageName = booking?.stage_name || "Artista sem nome";
  const stage = booking?.booking_stage_name ? ` · ${booking.booking_stage_name}` : "";
  const showTime = booking?.performance_start_at ? ` · ${booking.performance_start_at}` : "";
  return `${stageName}${stage}${showTime}`;
}

function resolveSourceLabel(payable) {
  if (payable.artist_stage_name) {
    return `Artista: ${payable.artist_stage_name}`;
  }

  return SOURCE_TYPE_OPTIONS.find((option) => option.value === payable.source_type)?.label || "Origem interna";
}

function NewPayableModal({
  eventId,
  categories,
  costCenters,
  suppliers,
  artistBookings,
  onSaved,
  onClose,
}) {
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
    event_artist_id: "",
    notes: "",
  });
  const [saving, setSaving] = useState(false);

  const setField = (key, value) =>
    setForm((current) => ({
      ...current,
      [key]: value,
      ...(key === "source_type" && !["artist", "logistics"].includes(value)
        ? { event_artist_id: "" }
        : {}),
    }));

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (!form.description || !form.category_id || !form.cost_center_id || !form.amount || !form.due_date) {
      toast.error("Preencha todos os campos obrigatórios.");
      return;
    }
    if (["artist", "logistics"].includes(form.source_type) && !form.event_artist_id) {
      toast.error("Selecione a contratação do artista vinculada ao lançamento.");
      return;
    }

    setSaving(true);
    try {
      await api.post("/event-finance/payables", {
        ...form,
        amount: parseFloat(form.amount),
        category_id: parseInt(form.category_id, 10),
        cost_center_id: parseInt(form.cost_center_id, 10),
        supplier_id: form.supplier_id ? parseInt(form.supplier_id, 10) : null,
        event_artist_id: form.event_artist_id ? parseInt(form.event_artist_id, 10) : null,
      });
      toast.success("Conta criada com sucesso!");
      onSaved();
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao criar conta.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
      <div className="card max-h-[90vh] w-full max-w-2xl overflow-y-auto border-cyan-800/40">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="section-title">Nova Conta a Pagar</h2>
          <button onClick={onClose} className="text-gray-500 hover:text-white">
            <X size={20} />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
          <div className="col-span-2">
            <label className="input-label">Descrição *</label>
            <input
              className="input"
              value={form.description}
              onChange={(event) => setField("description", event.target.value)}
              placeholder="Ex: Cachê Artista..."
            />
          </div>

          <div>
            <label className="input-label">Categoria *</label>
            <select className="select" value={form.category_id} onChange={(event) => setField("category_id", event.target.value)}>
              <option value="">Selecionar...</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>{category.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="input-label">Centro de Custo *</label>
            <select className="select" value={form.cost_center_id} onChange={(event) => setField("cost_center_id", event.target.value)}>
              <option value="">Selecionar...</option>
              {costCenters.map((center) => (
                <option key={center.id} value={center.id}>{center.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="input-label">Valor *</label>
            <input
              className="input"
              type="number"
              min="0"
              step="0.01"
              value={form.amount}
              onChange={(event) => setField("amount", event.target.value)}
              placeholder="0,00"
            />
          </div>

          <div>
            <label className="input-label">Vencimento *</label>
            <input
              className="input"
              type="date"
              value={form.due_date}
              onChange={(event) => setField("due_date", event.target.value)}
            />
          </div>

          <div>
            <label className="input-label">Origem do Lançamento</label>
            <select className="select" value={form.source_type} onChange={(event) => setField("source_type", event.target.value)}>
              {SOURCE_TYPE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="input-label">Fornecedor</label>
            <select className="select" value={form.supplier_id} onChange={(event) => setField("supplier_id", event.target.value)}>
              <option value="">Nenhum</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>{supplier.legal_name}</option>
              ))}
            </select>
          </div>

          {["artist", "logistics"].includes(form.source_type) && (
            <div className="col-span-2">
              <label className="input-label">Contratação do artista *</label>
              <select className="select" value={form.event_artist_id} onChange={(event) => setField("event_artist_id", event.target.value)}>
                <option value="">Selecionar contratação...</option>
                {artistBookings.map((booking) => (
                  <option key={booking.event_artist_id} value={booking.event_artist_id}>
                    {formatArtistBookingLabel(booking)}
                  </option>
                ))}
              </select>
            </div>
          )}

          <div>
            <label className="input-label">Forma de Pagamento</label>
            <select className="select" value={form.payment_method} onChange={(event) => setField("payment_method", event.target.value)}>
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
            <textarea
              className="input resize-none"
              rows={2}
              value={form.notes}
              onChange={(event) => setField("notes", event.target.value)}
            />
          </div>

          <div className="col-span-2 flex gap-3">
            <button type="submit" disabled={saving} className="btn-primary flex-1">
              {saving ? "Salvando..." : "Criar Conta"}
            </button>
            <button type="button" onClick={onClose} className="btn-outline flex-1">
              Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function EventFinancePayables() {
  const navigate = useNavigate();
  const { buildScopedPath, eventId, setEventId } = useEventScope();
  const [events, setEvents] = useState([]);
  const [payables, setPayables] = useState([]);
  const [categories, setCategories] = useState([]);
  const [costCenters, setCostCenters] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [artistBookings, setArtistBookings] = useState([]);
  const [loading, setLoading] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [search, setSearch] = useState("");
  const [filterStatus, setFilterStatus] = useState("");
  const [filterCategory, setFilterCategory] = useState("");
  const [page, setPage] = useState(1);
  const [payablesMeta, setPayablesMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE });
  const deferredSearch = useDeferredValue(search);

  useEffect(() => {
    api.get("/events").then((response) => setEvents(response.data.data || [])).catch(() => toast.error("Erro ao carregar eventos."));
    api.get("/event-finance/categories").then((response) => setCategories(response.data.data || [])).catch(() => toast.error("Erro ao carregar categorias."));
    api.get("/event-finance/suppliers").then((response) => setSuppliers(response.data.data || [])).catch(() => toast.error("Erro ao carregar fornecedores."));
  }, []);

  useEffect(() => {
    if (!eventId) {
      const timer = setTimeout(() => {
        setCostCenters([]);
        setArtistBookings([]);
      }, 0);
      return () => clearTimeout(timer);
    }

    api.get("/event-finance/cost-centers", { params: { event_id: eventId } })
      .then((response) => setCostCenters(response.data.data || []))
      .catch(() => { setCostCenters([]); toast.error("Erro ao carregar centros de custo."); });

    api.get("/artists", { params: { event_id: eventId, per_page: 200 } })
      .then((response) => setArtistBookings(response.data.data || []))
      .catch(() => { setArtistBookings([]); toast.error("Erro ao carregar artistas."); });
  }, [eventId]);

  const fetchPayables = useCallback(() => {
    if (!eventId) {
      setPayables([]);
      setPayablesMeta({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: 1 });
      return;
    }

    setLoading(true);
    const params = { event_id: eventId, page, per_page: PAGE_SIZE };
    if (filterStatus) params.status = filterStatus;
    if (filterCategory) params.category_id = filterCategory;
    if (deferredSearch.trim()) params.search = deferredSearch.trim();

    api.get("/event-finance/payables", { params })
      .then((response) => {
        setPayables(response.data.data || []);
        setPayablesMeta(extractPaginationMeta(response.data?.meta, { ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page }));
      })
      .catch(() => {
        setPayables([]);
        setPayablesMeta({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: 1 });
        toast.error("Erro ao carregar contas.");
      })
      .finally(() => setLoading(false));
  }, [deferredSearch, eventId, filterCategory, filterStatus, page]);

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchPayables();
    }, 0);
    return () => clearTimeout(timer);
  }, [fetchPayables]);

  return (
    <div className="space-y-6">
      {showNew && (
        <NewPayableModal
          eventId={parseInt(eventId, 10)}
          categories={categories}
          costCenters={costCenters}
          suppliers={suppliers}
          artistBookings={artistBookings}
          onSaved={() => {
            setShowNew(false);
            fetchPayables();
          }}
          onClose={() => setShowNew(false)}
        />
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Receipt size={22} className="text-cyan-400" /> Contas a Pagar
          </h1>
          <p className="text-sm text-gray-500">{payablesMeta.total} conta(s) encontrada(s)</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select className="select w-auto" value={eventId} onChange={(event) => { setPage(1); setEventId(event.target.value); }}>
            <option value="">Selecionar evento...</option>
            {events.map((eventItem) => (
              <option key={eventItem.id} value={eventItem.id}>{eventItem.name}</option>
            ))}
          </select>
          <button onClick={() => {
            if (categories.length === 0 || costCenters.length === 0) {
              toast.error("É necessário cadastrar categorias e centros de custo antes de criar uma conta.");
              return;
            }
            setShowNew(true);
          }} disabled={!eventId} className="btn-primary">
            <Plus size={16} /> Nova Conta
          </button>
        </div>
      </div>

      <div className="flex flex-wrap gap-3">
        <div className="relative min-w-[200px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" />
            <input
              className="input pl-8"
              placeholder="Buscar descrição, fornecedor ou artista..."
              value={search}
              onChange={(event) => {
                setPage(1);
                setSearch(event.target.value);
              }}
            />
          </div>
        <select className="select w-auto" value={filterStatus} onChange={(event) => {
          setPage(1);
          setFilterStatus(event.target.value);
        }}>
          <option value="">Todos os status</option>
          {Object.entries(STATUS_LABELS).map(([key, value]) => (
            <option key={key} value={key}>{value.label}</option>
          ))}
        </select>
        <select className="select w-auto" value={filterCategory} onChange={(event) => {
          setPage(1);
          setFilterCategory(event.target.value);
        }}>
          <option value="">Todas as categorias</option>
          {categories.map((category) => (
            <option key={category.id} value={category.id}>{category.name}</option>
          ))}
        </select>
        {(filterStatus || filterCategory || search) && (
          <button
            onClick={() => {
              setPage(1);
              setFilterStatus("");
              setFilterCategory("");
              setSearch("");
            }}
            className="btn-outline gap-1"
          >
            <XCircle size={14} /> Limpar
          </button>
        )}
      </div>

      {!eventId && (
        <div className="card border-dashed border-white/10 py-16 text-center text-gray-500">
          Selecione um evento para visualizar as contas.
        </div>
      )}

      {eventId && (
        <>
          <div className="table-wrapper">
            {loading ? (
              <p className="py-10 text-center text-gray-500">Carregando...</p>
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
                  {payables.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="py-10 text-center text-gray-500">
                        <AlertCircle className="mr-2 inline text-gray-600" size={16} />
                        Nenhuma conta encontrada
                      </td>
                    </tr>
                  ) : (
                    payables.map((payable) => {
                      const statusMeta = STATUS_LABELS[payable.status] || { label: payable.status, cls: "badge-gray" };
                      const isOverdue = payable.status === "overdue";
                      return (
                        <tr
                          key={payable.id}
                          className={`cursor-pointer hover:bg-white/5 ${isOverdue ? "bg-red-900/5" : ""}`}
                          onClick={() => navigate(buildScopedPath(`/finance/payables/${payable.id}`))}
                        >
                          <td className="max-w-[240px]">
                            <p className="truncate font-medium text-white">{payable.description}</p>
                            <p className="mt-1 text-xs text-gray-500">{resolveSourceLabel(payable)}</p>
                          </td>
                          <td className="text-sm text-gray-400">{payable.category_name}</td>
                          <td className="text-sm text-gray-400">{payable.cost_center_name}</td>
                          <td className={`text-sm font-mono ${isOverdue ? "text-red-400" : "text-gray-400"}`}>
                            {payable.due_date}
                          </td>
                          <td className="text-right tabular-nums">{fmt(payable.amount)}</td>
                          <td className="text-right tabular-nums text-green-400">{fmt(payable.paid_amount)}</td>
                          <td className="text-right tabular-nums text-yellow-400">{fmt(payable.remaining_amount)}</td>
                          <td><span className={statusMeta.cls}>{statusMeta.label}</span></td>
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
          {!loading && payablesMeta.total_pages > 1 ? (
            <Pagination
              page={payablesMeta.page}
              totalPages={payablesMeta.total_pages}
              onPrev={() => setPage((current) => Math.max(1, current - 1))}
              onNext={() => setPage((current) => Math.min(payablesMeta.total_pages, current + 1))}
            />
          ) : null}
        </>
      )}
    </div>
  );
}
