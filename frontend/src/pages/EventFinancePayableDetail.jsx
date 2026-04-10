import { useState, useEffect, useCallback } from "react";
import { Link, useParams, useNavigate } from "react-router-dom";
import {
  ArrowLeft,
  Clock,
  CheckCircle,
  AlertTriangle,
  XCircle,
  Plus,
  Paperclip,
  RotateCcw,
  Ban,
} from "lucide-react";
import api from "../lib/api";
import toast from "react-hot-toast";
import { useEventScope } from "../context/EventScopeContext";

const fmt = (v) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(v) || 0
  );

const STATUS_ICONS = {
  pending: <Clock size={16} className="text-yellow-400" />,
  partial: <Clock size={16} className="text-yellow-400" />,
  paid: <CheckCircle size={16} className="text-green-400" />,
  overdue: <AlertTriangle size={16} className="text-red-400" />,
  cancelled: <XCircle size={16} className="text-gray-500" />,
};

const STATUS_LABELS = {
  pending: { label: "Pendente", cls: "badge-yellow" },
  partial: { label: "Pago parcial", cls: "badge-yellow" },
  paid: { label: "Pago", cls: "badge-green" },
  overdue: { label: "Vencido", cls: "badge-red" },
  cancelled: { label: "Cancelado", cls: "badge-gray" },
};

function RegisterPaymentModal({ payableId, eventId, remaining, onSaved, onClose }) {
  const [form, setForm] = useState({
    payable_id: payableId,
    event_id: eventId,
    amount: "",
    payment_date: new Date().toISOString().split("T")[0],
    payment_method: "pix",
    reference_code: "",
    notes: "",
  });
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    const amount = parseFloat(form.amount);
    if (!amount || amount <= 0) {
      toast.error("Informe um valor válido.");
      return;
    }
    if (amount > remaining + 0.01) {
      toast.error(`Valor excede o saldo restante de ${fmt(remaining)}.`);
      return;
    }
    setSaving(true);
    try {
      await api.post("/event-finance/payments", { ...form, amount });
      toast.success("Pagamento registrado com sucesso!");
      onSaved();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao registrar pagamento.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
      <div className="card max-w-md w-full border-green-800/40">
        <div className="flex items-center justify-between mb-4">
          <h2 className="section-title">Registrar Pagamento</h2>
          <button onClick={onClose} className="text-gray-500 hover:text-white"><XCircle size={20} /></button>
        </div>
        <p className="text-sm text-gray-400 mb-4">
          Saldo restante: <span className="text-yellow-400 font-semibold">{fmt(remaining)}</span>
        </p>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="input-label">Valor *</label>
              <input className="input" type="number" min="0.01" step="0.01" max={remaining} value={form.amount}
                onChange={(e) => setForm(f => ({ ...f, amount: e.target.value }))} placeholder="0,00" />
            </div>
            <div>
              <label className="input-label">Data *</label>
              <input className="input" type="date" value={form.payment_date}
                onChange={(e) => setForm(f => ({ ...f, payment_date: e.target.value }))} />
            </div>
          </div>
          <div>
            <label className="input-label">Forma de Pagamento</label>
            <select className="select" value={form.payment_method} onChange={(e) => setForm(f => ({ ...f, payment_method: e.target.value }))}>
              <option value="pix">PIX</option>
              <option value="ted">TED</option>
              <option value="dinheiro">Dinheiro</option>
              <option value="cartao">Cartão</option>
              <option value="boleto">Boleto</option>
            </select>
          </div>
          <div>
            <label className="input-label">Código de Referência</label>
            <input className="input" value={form.reference_code}
              onChange={(e) => setForm(f => ({ ...f, reference_code: e.target.value }))} placeholder="Comprovante, NSU..." />
          </div>
          <div>
            <label className="input-label">Observações</label>
            <textarea className="input resize-none" rows={2} value={form.notes}
              onChange={(e) => setForm(f => ({ ...f, notes: e.target.value }))} />
          </div>
          <div className="flex gap-3">
            <button type="submit" disabled={saving} className="btn-primary flex-1">
              {saving ? "Salvando..." : "Registrar Pagamento"}
            </button>
            <button type="button" onClick={onClose} className="btn-outline flex-1">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function EventFinancePayableDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { buildScopedPath } = useEventScope();
  const [payable, setPayable] = useState(null);
  const [payments, setPayments] = useState([]);
  const [attachments, setAttachments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showPayment, setShowPayment] = useState(false);
  const [cancelReason, setCancelReason] = useState("");
  const [showCancel, setShowCancel] = useState(false);
  const [reversing, setReversing] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [pRes, payRes, attRes] = await Promise.all([
        api.get(`/event-finance/payables/${id}`),
        api.get("/event-finance/payments", { params: { payable_id: id, per_page: 100 } }),
        api.get("/event-finance/attachments", { params: { payable_id: id, per_page: 100 } }),
      ]);
      setPayable(pRes.data.data);
      setPayments(payRes.data.data || []);
      setAttachments(attRes.data.data || []);
    } catch {
      toast.error("Erro ao carregar conta.");
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const handleCancel = async () => {
    try {
      await api.post(`/event-finance/payables/${id}/cancel`, { reason: cancelReason });
      toast.success("Conta cancelada.");
      setShowCancel(false);
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao cancelar.");
    }
  };

  const handleReverse = async (paymentId) => {
    if (!window.confirm("Confirmar estorno deste pagamento?")) return;
    setReversing(paymentId);
    try {
      await api.post(`/event-finance/payments/${paymentId}/reverse`, { reason: "Estorno manual" });
      toast.success("Pagamento estornado.");
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || "Erro ao estornar.");
    } finally {
      setReversing(null);
    }
  };

  if (loading) {
    return <div className="text-center py-16 text-gray-500">Carregando...</div>;
  }
  if (!payable) {
    return <div className="text-center py-16 text-gray-500">Conta não encontrada.</div>;
  }

  const st = STATUS_LABELS[payable.status] || { label: payable.status, cls: "badge-gray" };
  const canPay = !["cancelled", "paid"].includes(payable.status);
  const canCancel = !["cancelled", "paid"].includes(payable.status);
  const paidPct = payable.amount > 0 ? Math.min(100, (payable.paid_amount / payable.amount) * 100) : 0;

  return (
    <div className="space-y-6 max-w-4xl">
      {showPayment && (
        <RegisterPaymentModal
          payableId={parseInt(id)}
          eventId={payable.event_id}
          remaining={parseFloat(payable.remaining_amount)}
          onSaved={() => { setShowPayment(false); load(); }}
          onClose={() => setShowPayment(false)}
        />
      )}

      {/* Header */}
      <div className="flex items-center gap-3">
        <button onClick={() => navigate(buildScopedPath("/finance/payables", payable?.event_id))} className="btn-outline p-2">
          <ArrowLeft size={16} />
        </button>
        <div className="flex-1">
          <div className="flex items-center gap-2">
            {STATUS_ICONS[payable.status]}
            <h1 className="page-title">{payable.description}</h1>
          </div>
          <p className="text-gray-500 text-sm">{payable.category_name} · {payable.cost_center_name}</p>
        </div>
        <div className="flex items-center gap-2">
          <span className={st.cls}>{st.label}</span>
          {canPay && (
            <button onClick={() => setShowPayment(true)} className="btn-primary">
              <Plus size={16} /> Pagar
            </button>
          )}
          {canCancel && (
            <button onClick={() => setShowCancel(!showCancel)} className="btn-outline text-red-400 border-red-500/40">
              <Ban size={16} /> Cancelar
            </button>
          )}
        </div>
      </div>

      {/* Cancelamento inline */}
      {showCancel && (
        <div className="card border-red-500/30 bg-red-900/5 space-y-3">
          <p className="text-red-400 text-sm font-medium">Motivo do cancelamento</p>
          <input className="input" placeholder="Ex: Negociação cancelada..." value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)} />
          <div className="flex gap-2">
            <button onClick={handleCancel} className="btn-outline text-red-400 border-red-500/40">Confirmar Cancelamento</button>
            <button onClick={() => setShowCancel(false)} className="btn-outline">Voltar</button>
          </div>
        </div>
      )}

      {/* Valores */}
      <div className="grid grid-cols-3 gap-4">
        <div className="card border-white/5 text-center">
          <p className="text-xs text-gray-500 uppercase">Valor Total</p>
          <p className="text-2xl font-bold text-white mt-1">{fmt(payable.amount)}</p>
        </div>
        <div className="card border-white/5 text-center">
          <p className="text-xs text-gray-500 uppercase">Pago</p>
          <p className="text-2xl font-bold text-green-400 mt-1">{fmt(payable.paid_amount)}</p>
        </div>
        <div className="card border-white/5 text-center">
          <p className="text-xs text-gray-500 uppercase">Saldo</p>
          <p className={`text-2xl font-bold mt-1 ${parseFloat(payable.remaining_amount) > 0 ? "text-yellow-400" : "text-gray-500"}`}>
            {fmt(payable.remaining_amount)}
          </p>
        </div>
      </div>

      {/* Progresso */}
      <div className="card border-white/5">
        <div className="flex justify-between text-xs text-gray-400 mb-2">
          <span>Progresso de pagamento</span>
          <span>{paidPct.toFixed(0)}%</span>
        </div>
        <div className="w-full bg-white/5 rounded-full h-2">
          <div className="h-2 rounded-full bg-green-500 transition-all" style={{ width: `${paidPct}%` }} />
        </div>
        <div className="flex justify-between text-xs text-gray-500 mt-2">
          <span>Vencimento: {payable.due_date}</span>
          {payable.supplier_name && <span>Fornecedor: {payable.supplier_name}</span>}
          {payable.payment_method && <span>Pagamento: {payable.payment_method.toUpperCase()}</span>}
        </div>
        {payable.artist_id && (
          <div className="mt-3 rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-3 py-2 text-sm text-cyan-100">
            <span className="text-cyan-300">Origem vinculada:</span>{" "}
            <Link
              to={buildScopedPath(`/artists/${payable.artist_id}?tab=bookings`, payable.event_id)}
              className="font-medium text-cyan-200 hover:text-white"
            >
              {payable.artist_stage_name || `Artista #${payable.artist_id}`}
            </Link>
          </div>
        )}
      </div>

      {/* Pagamentos */}
      <div className="card border-white/5">
        <h2 className="section-title">Histórico de Pagamentos</h2>
        {payments.length === 0 ? (
          <p className="text-gray-500 text-sm mt-3">Nenhum pagamento registrado.</p>
        ) : (
          <div className="table-wrapper mt-4">
            <table className="table">
              <thead>
                <tr>
                  <th>Data</th>
                  <th className="text-right">Valor</th>
                  <th>Forma</th>
                  <th>Referência</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {payments.map((pay) => (
                  <tr key={pay.id} className={pay.status === "reversed" ? "opacity-40" : ""}>
                    <td className="font-mono text-sm">{pay.payment_date}</td>
                    <td className="text-right font-semibold text-green-400">{fmt(pay.amount)}</td>
                    <td className="text-sm capitalize">{pay.payment_method || "—"}</td>
                    <td className="text-sm text-gray-400">{pay.reference_code || "—"}</td>
                    <td>
                      {pay.status === "reversed"
                        ? <span className="badge-red">Estornado</span>
                        : <span className="badge-green">Registrado</span>}
                    </td>
                    <td>
                      {pay.status === "posted" && (
                        <button
                          onClick={() => handleReverse(pay.id)}
                          disabled={reversing === pay.id}
                          className="p-1.5 text-gray-500 hover:text-red-400 transition-colors"
                          title="Estornar"
                        >
                          <RotateCcw size={14} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Anexos */}
      <div className="card border-white/5">
        <h2 className="section-title flex items-center gap-2">
          <Paperclip size={16} className="text-gray-400" /> Anexos
        </h2>
        {attachments.length === 0 ? (
          <p className="text-gray-500 text-sm mt-3">Nenhum anexo.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {attachments.map((a) => (
              <li key={a.id} className="flex items-center gap-3 text-sm">
                <Paperclip size={14} className="text-gray-500" />
                <span className="text-gray-300">{a.original_name}</span>
                <span className="text-xs text-gray-600">({a.attachment_type})</span>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Observações */}
      {payable.notes && (
        <div className="card border-white/5">
          <h2 className="section-title">Observações</h2>
          <p className="text-gray-400 text-sm mt-2">{payable.notes}</p>
        </div>
      )}
    </div>
  );
}
