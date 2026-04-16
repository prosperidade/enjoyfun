import { useState, useEffect, useCallback } from "react";
import {
  Building2, Plus, ChevronDown, ChevronUp, X, FileText,
} from "lucide-react";
import { useEventScope } from "../context/EventScopeContext";
import api from "../lib/api";
import toast from "react-hot-toast";

const fmt = (v) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(v) || 0
  );

const CONTRACT_STATUS = {
  draft: { label: "Rascunho", cls: "badge-gray" },
  active: { label: "Ativo", cls: "badge-green" },
  completed: { label: "Concluído", cls: "badge-blue" },
  cancelled: { label: "Cancelado", cls: "badge-red" },
};

function SupplierRow({ supplier, events, onUpdated, scopedEventId, onScopedEventChange }) {
  const [open, setOpen] = useState(false);
  const [contracts, setContracts] = useState([]);
  const [eventId, setEventId] = useState(scopedEventId || "");
  const [loadingContracts, setLoadingContracts] = useState(false);
  const [showNewContract, setShowNewContract] = useState(false);
  const [newContract, setNewContract] = useState({
    event_id: scopedEventId || "", supplier_id: supplier.id, description: "",
    total_amount: "", signed_at: "", valid_until: "", status: "draft", notes: "",
  });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setEventId(scopedEventId || "");
    setNewContract((current) => (
      current.event_id
        ? current
        : { ...current, event_id: scopedEventId || "" }
    ));
  }, [scopedEventId]);

  const loadContracts = useCallback((eid) => {
    if (!eid) return;
    setLoadingContracts(true);
    api.get("/event-finance/contracts", { params: { event_id: eid } })
      .then((r) => setContracts((r.data.data || []).filter(c => c.supplier_id === supplier.id)))
      .catch(() => {})
      .finally(() => setLoadingContracts(false));
  }, [supplier.id]);

  useEffect(() => {
    if (!open) {
      return;
    }

    if (!eventId) {
      setContracts([]);
      return;
    }

    loadContracts(eventId);
  }, [eventId, loadContracts, open]);

  const handleContractSave = async (e) => {
    e.preventDefault();
    if (!newContract.description || !newContract.event_id) {
      toast.error("Preencha evento e descrição.");
      return;
    }
    setSaving(true);
    try {
      await api.post("/event-finance/contracts", {
        ...newContract,
        total_amount: parseFloat(newContract.total_amount) || 0,
        event_id: parseInt(newContract.event_id),
      });
      toast.success("Contrato criado!");
      setShowNewContract(false);
      loadContracts(eventId);
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao criar contrato.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="card border-slate-800/40">
      <div
        className="flex items-start justify-between cursor-pointer"
        onClick={() => setOpen((o) => !o)}
      >
        <div>
          <p className="font-semibold text-slate-100">{supplier.legal_name}</p>
          {supplier.trade_name && <p className="text-sm text-slate-400">{supplier.trade_name}</p>}
          <div className="flex gap-4 mt-1 text-xs text-slate-400">
            {supplier.document_number && <span>Doc: {supplier.document_number}</span>}
            {supplier.contact_email && <span>{supplier.contact_email}</span>}
            {supplier.contact_phone && <span>{supplier.contact_phone}</span>}
          </div>
        </div>
        <div className="flex items-center gap-2">
          {!supplier.is_active && <span className="badge-gray">Inativo</span>}
          {open ? <ChevronUp size={16} className="text-slate-400" /> : <ChevronDown size={16} className="text-slate-400" />}
        </div>
      </div>

      {open && (
        <div className="mt-4 border-t border-slate-800/40 pt-4 space-y-4">
          {/* Pix / banco */}
          {(supplier.pix_key || supplier.bank_name) && (
            <div className="grid grid-cols-2 gap-4 text-xs text-slate-400">
              {supplier.pix_key && (
                <div><span className="text-slate-500 block uppercase">Chave PIX</span>{supplier.pix_key}</div>
              )}
              {supplier.bank_name && (
                <div><span className="text-slate-500 block uppercase">Banco</span>{supplier.bank_name} {supplier.bank_agency && `Ag. ${supplier.bank_agency}`} {supplier.bank_account && `CC ${supplier.bank_account}`}</div>
              )}
            </div>
          )}

          {/* Contratos */}
          <div>
            <div className="flex items-center justify-between mb-3">
              <p className="text-sm font-medium text-slate-300 flex items-center gap-1">
                <FileText size={14} /> Contratos
              </p>
              <div className="flex items-center gap-2">
                <select
                  className="select w-auto text-xs"
                  value={eventId}
                  onChange={(e) => {
                    setEventId(e.target.value);
                    onScopedEventChange?.(e.target.value);
                  }}
                >
                  <option value="">Filtrar por evento...</option>
                  {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
                </select>
                <button onClick={() => setShowNewContract(!showNewContract)} className="btn-outline text-xs gap-1 py-1">
                  <Plus size={12} /> Contrato
                </button>
              </div>
            </div>

            {showNewContract && (
              <form onSubmit={handleContractSave} className="card border-cyan-500/30 grid grid-cols-2 gap-3 mb-3">
                <div className="col-span-2">
                  <label className="input-label">Evento *</label>
                  <select className="select" value={newContract.event_id}
                    onChange={(e) => setNewContract(f => ({ ...f, event_id: e.target.value }))}>
                    <option value="">Selecionar...</option>
                    {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
                  </select>
                </div>
                <div className="col-span-2">
                  <label className="input-label">Descrição *</label>
                  <input className="input" value={newContract.description}
                    onChange={(e) => setNewContract(f => ({ ...f, description: e.target.value }))} />
                </div>
                <div>
                  <label className="input-label">Valor</label>
                  <input className="input" type="number" min="0" step="0.01" value={newContract.total_amount}
                    onChange={(e) => setNewContract(f => ({ ...f, total_amount: e.target.value }))} placeholder="0,00" />
                </div>
                <div>
                  <label className="input-label">Status</label>
                  <select className="select" value={newContract.status}
                    onChange={(e) => setNewContract(f => ({ ...f, status: e.target.value }))}>
                    <option value="draft">Rascunho</option>
                    <option value="active">Ativo</option>
                    <option value="completed">Concluído</option>
                    <option value="cancelled">Cancelado</option>
                  </select>
                </div>
                <div className="col-span-2 flex gap-2">
                  <button type="submit" disabled={saving} className="btn-primary flex-1 text-sm">
                    {saving ? "Salvando..." : "Criar Contrato"}
                  </button>
                  <button type="button" onClick={() => setShowNewContract(false)} className="btn-outline flex-1 text-sm">Cancelar</button>
                </div>
              </form>
            )}

            {loadingContracts
              ? <p className="text-xs text-slate-400">Carregando contratos...</p>
              : contracts.length === 0
              ? <p className="text-xs text-slate-500">Nenhum contrato para o evento selecionado.</p>
              : (
                <div className="space-y-2">
                  {contracts.map((c) => {
                    const cs = CONTRACT_STATUS[c.status] || { label: c.status, cls: "badge-gray" };
                    return (
                      <div key={c.id} className="flex items-center justify-between text-sm border-l-2 border-slate-700/50 pl-3">
                        <div>
                          <p className="text-slate-300">{c.description}</p>
                          {c.contract_number && <p className="text-xs text-slate-500">Nº {c.contract_number}</p>}
                        </div>
                        <div className="flex items-center gap-3 text-right">
                          <span className="text-slate-100 font-medium">{fmt(c.total_amount)}</span>
                          <span className={cs.cls}>{cs.label}</span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
          </div>
        </div>
      )}
    </div>
  );
}

function NewSupplierModal({ onSaved, onClose }) {
  const [form, setForm] = useState({
    legal_name: "", trade_name: "", supplier_type: "pessoa_juridica",
    document_number: "", pix_key: "", bank_name: "", bank_agency: "", bank_account: "",
    contact_name: "", contact_email: "", contact_phone: "", notes: "",
  });
  const [saving, setSaving] = useState(false);
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!form.legal_name) { toast.error("Razão social é obrigatória."); return; }
    setSaving(true);
    try {
      await api.post("/event-finance/suppliers", form);
      toast.success("Fornecedor criado!");
      onSaved();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao criar fornecedor.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
      <div className="card max-w-2xl w-full border-cyan-500/30 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h2 className="section-title">Novo Fornecedor</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-100"><X size={20} /></button>
        </div>
        <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
          <div className="col-span-2">
            <label className="input-label">Razão Social *</label>
            <input className="input" value={form.legal_name} onChange={(e) => set("legal_name", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Nome Fantasia</label>
            <input className="input" value={form.trade_name} onChange={(e) => set("trade_name", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Tipo</label>
            <select className="select" value={form.supplier_type} onChange={(e) => set("supplier_type", e.target.value)}>
              <option value="pessoa_juridica">Pessoa Jurídica</option>
              <option value="pessoa_fisica">Pessoa Física</option>
              <option value="estrangeiro">Estrangeiro</option>
            </select>
          </div>
          <div>
            <label className="input-label">CNPJ/CPF</label>
            <input className="input" value={form.document_number} onChange={(e) => set("document_number", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Chave PIX</label>
            <input className="input" value={form.pix_key} onChange={(e) => set("pix_key", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Contato</label>
            <input className="input" value={form.contact_name} onChange={(e) => set("contact_name", e.target.value)} placeholder="Nome" />
          </div>
          <div>
            <label className="input-label">E-mail</label>
            <input className="input" type="email" value={form.contact_email} onChange={(e) => set("contact_email", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Telefone</label>
            <input className="input" value={form.contact_phone} onChange={(e) => set("contact_phone", e.target.value)} />
          </div>
          <div>
            <label className="input-label">Banco</label>
            <input className="input" value={form.bank_name} onChange={(e) => set("bank_name", e.target.value)} />
          </div>
          <div className="col-span-2 flex gap-3">
            <button type="submit" disabled={saving} className="btn-primary flex-1">{saving ? "Salvando..." : "Criar Fornecedor"}</button>
            <button type="button" onClick={onClose} className="btn-outline flex-1">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function EventFinanceSuppliers() {
  const { eventId, setEventId } = useEventScope();
  const [suppliers, setSuppliers] = useState([]);
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showNew, setShowNew] = useState(false);
  const [search, setSearch] = useState("");

  const loadSuppliers = useCallback(() => {
    setLoading(true);
    api.get("/event-finance/suppliers", { params: { active: false } })
      .then((r) => setSuppliers(r.data.data || []))
      .catch(() => toast.error("Erro ao carregar fornecedores."))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    loadSuppliers();
    api.get("/events").then((r) => setEvents(r.data.data || [])).catch(() => {});
  }, [loadSuppliers]);

  const filtered = suppliers.filter((s) =>
    s.legal_name?.toLowerCase().includes(search.toLowerCase()) ||
    s.trade_name?.toLowerCase().includes(search.toLowerCase()) ||
    s.document_number?.includes(search)
  );

  return (
    <div className="space-y-6">
      {showNew && (
        <NewSupplierModal
          onSaved={() => { setShowNew(false); loadSuppliers(); }}
          onClose={() => setShowNew(false)}
        />
      )}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <Building2 size={22} className="text-cyan-400" /> Fornecedores
          </h1>
          <p className="text-slate-400 text-sm">{filtered.length} fornecedor(es)</p>
        </div>
        <button onClick={() => setShowNew(true)} className="btn-primary">
          <Plus size={16} /> Novo Fornecedor
        </button>
      </div>

      <div className="relative">
        <input
          className="input pl-8"
          placeholder="Buscar nome, fantasia ou CNPJ..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {loading ? (
        <div className="text-center py-12 text-slate-400">Carregando...</div>
      ) : filtered.length === 0 ? (
        <div className="card border-dashed border-slate-700/50 text-center py-16 text-slate-400">
          Nenhum fornecedor encontrado.
        </div>
      ) : (
        <div className="space-y-3">
          {filtered.map((s) => (
            <SupplierRow
              key={s.id}
              supplier={s}
              events={events}
              onUpdated={loadSuppliers}
              scopedEventId={eventId}
              onScopedEventChange={setEventId}
            />
          ))}
        </div>
      )}
    </div>
  );
}
