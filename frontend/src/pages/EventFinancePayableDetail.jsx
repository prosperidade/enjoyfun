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
  cancelled: <XCircle size={16} className="text-slate-500" />,
};

const STATUS_LABELS = {
  pending: { label: "Pendente", cls: "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-500/15 text-amber-400" },
  partial: { label: "Pago parcial", cls: "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-500/15 text-amber-400" },
  paid: { label: "Pago", cls: "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-500/15 text-green-400" },
  overdue: { label: "Vencido", cls: "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-500/15 text-red-400" },
  cancelled: { label: "Cancelado", cls: "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-slate-700/50 text-slate-400" },
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
      toast.error("Informe um valor valido.");
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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-xl">
      <div className="bg-slate-900/95 backdrop-blur-xl max-w-md w-full border border-green-800/40 rounded-2xl p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-slate-200">Registrar Pagamento</h2>
          <button onClick={onClose} className="text-slate-500 hover:text-slate-100 transition-colors"><XCircle size={20} /></button>
        </div>
        <p className="text-sm text-slate-400 mb-4">
          Saldo restante: <span className="text-yellow-400 font-semibold">{fmt(remaining)}</span>
        </p>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="text-xs text-slate-400 uppercase tracking-wider">Valor *</label>
              <input className="mt-1 w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl text-slate-200 px-3 py-2 text-sm outline-none transition-colors" type="number" min="0.01" step="0.01" max={remaining} value={form.amount}
                onChange={(e) => setForm(f => ({ ...f, amount: e.target.value }))} placeholder="0,00" />
            </div>
            <div>
              <label className="text-xs text-slate-400 uppercase tracking-wider">Data *</label>
              <input className="mt-1 w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl text-slate-200 px-3 py-2 text-sm outline-none transition-colors" type="date" value={form.payment_date}
                onChange={(e) => setForm(f => ({ ...f, payment_date: e.target.value }))} />
            </div>
          </div>
          <div>
            <label className="text-xs text-slate-400 uppercase tracking-wider">Forma de Pagamento</label>
            <select className="mt-1 w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl text-slate-200 px-3 py-2 text-sm outline-none transition-colors" value={form.payment_method} onChange={(e) => setForm(f => ({ ...f, payment_method: e.target.value }))}>
              <option value="pix">PIX</option>
              <option value="ted">TED</option>
              <option value="dinheiro">Dinheiro</option>
              <option value="cartao">Cartao</option>
              <option value="boleto">Boleto</option>
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-400 uppercase tracking-wider">Codigo de Referencia</label>
            <input className="mt-1 w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl text-slate-200 px-3 py-2 text-sm outline-none transition-colors" value={form.reference_code}
              onChange={(e) => setForm(f => ({ ...f, reference_code: e.target.value }))} placeholder="Comprovante, NSU..." />
          </div>
          <div>
            <label className="text-xs text-slate-400 uppercase tracking-wider">Observacoes</label>
            <textarea className="mt-1 w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl text-slate-200 px-3 py-2 text-sm outline-none transition-colors resize-none" rows={2} value={form.notes}
              onChange={(e) => setForm(f => ({ ...f, notes: e.target.value }))} />
          </div>
          <div className="flex gap-3">
            <button type="submit" disabled={saving} className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex-1 transition-opacity hover:opacity-90 disabled:opacity-50">
              {saving ? "Salvando..." : "Registrar Pagamento"}
            </button>
            <button type="button" onClick={onClose} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 flex-1 transition-colors">Cancelar</button>
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
    return <div className="text-center py-16 text-slate-500">Carregando...</div>;
  }
  if (!payable) {
    return <div className="text-center py-16 text-slate-500">Conta nao encontrada.</div>;
  }

  const st = STATUS_LABELS[payable.status] || { label: payable.status, cls: "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-slate-700/50 text-slate-400" };
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
        <button onClick={() => navigate(buildScopedPath("/finance/payables", payable?.event_id))} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl p-2 transition-colors">
          <ArrowLeft size={16} />
        </button>
        <div className="flex-1">
          <div className="flex items-center gap-2">
            {STATUS_ICONS[payable.status]}
            <h1 className="text-2xl font-bold font-headline text-slate-100">{payable.description}</h1>
          </div>
          <p className="text-slate-500 text-sm">{payable.category_name} · {payable.cost_center_name}</p>
        </div>
        <div className="flex items-center gap-2">
          <span className={st.cls}>{st.label}</span>
          {canPay && (
            <button onClick={() => setShowPayment(true)} className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex items-center gap-1.5 transition-opacity hover:opacity-90">
              <Plus size={16} /> Pagar
            </button>
          )}
          {canCancel && (
            <button onClick={() => setShowCancel(!showCancel)} className="border border-red-500/30 text-red-400 hover:bg-red-500/10 rounded-xl px-4 py-2 flex items-center gap-1.5 transition-colors">
              <Ban size={16} /> Cancelar
            </button>
          )}
        </div>
      </div>

      {/* Cancelamento inline */}
      {showCancel && (
        <div className="bg-[#111827] border border-red-500/30 rounded-2xl p-5 space-y-3">
          <p className="text-red-400 text-sm font-medium">Motivo do cancelamento</p>
          <input className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl text-slate-200 px-3 py-2 text-sm outline-none transition-colors" placeholder="Ex: Negociacao cancelada..." value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)} />
          <div className="flex gap-2">
            <button onClick={handleCancel} className="border border-red-500/30 text-red-400 hover:bg-red-500/10 rounded-xl px-4 py-2 transition-colors">Confirmar Cancelamento</button>
            <button onClick={() => setShowCancel(false)} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 transition-colors">Voltar</button>
          </div>
        </div>
      )}

      {/* Valores */}
      <div className="grid grid-cols-3 gap-4">
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 text-center">
          <p className="text-xs text-slate-400 uppercase tracking-wider">Valor Total</p>
          <p className="text-2xl font-bold text-slate-100 mt-1">{fmt(payable.amount)}</p>
        </div>
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 text-center">
          <p className="text-xs text-slate-400 uppercase tracking-wider">Pago</p>
          <p className="text-2xl font-bold text-green-400 mt-1">{fmt(payable.paid_amount)}</p>
        </div>
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 text-center">
          <p className="text-xs text-slate-400 uppercase tracking-wider">Saldo</p>
          <p className={`text-2xl font-bold mt-1 ${parseFloat(payable.remaining_amount) > 0 ? "text-yellow-400" : "text-slate-500"}`}>
            {fmt(payable.remaining_amount)}
          </p>
        </div>
      </div>

      {/* Progresso */}
      <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
        <div className="flex justify-between text-xs text-slate-400 mb-2">
          <span>Progresso de pagamento</span>
          <span>{paidPct.toFixed(0)}%</span>
        </div>
        <div className="w-full bg-slate-800/50 rounded-full h-2">
          <div className="h-2 rounded-full bg-green-500 transition-all" style={{ width: `${paidPct}%` }} />
        </div>
        <div className="flex justify-between text-xs text-slate-500 mt-2">
          <span>Vencimento: {payable.due_date}</span>
          {payable.supplier_name && <span>Fornecedor: {payable.supplier_name}</span>}
          {payable.payment_method && <span>Pagamento: {payable.payment_method.toUpperCase()}</span>}
        </div>
        {payable.artist_id && (
          <div className="mt-3 rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-3 py-2 text-sm text-cyan-100">
            <span className="text-cyan-300">Origem vinculada:</span>{" "}
            <Link
              to={buildScopedPath(`/artists/${payable.artist_id}?tab=bookings`, payable.event_id)}
              className="font-medium text-cyan-200 hover:text-slate-100"
            >
              {payable.artist_stage_name || `Artista #${payable.artist_id}`}
            </Link>
          </div>
        )}
      </div>

      {/* Pagamentos */}
      <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
        <h2 className="text-lg font-semibold text-slate-200">Historico de Pagamentos</h2>
        {payments.length === 0 ? (
          <p className="text-slate-500 text-sm mt-3">Nenhum pagamento registrado.</p>
        ) : (
          <div className="overflow-x-auto rounded-2xl border border-slate-800/40 bg-[#111827] mt-4">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-slate-800/50">
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Data</th>
                  <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-400">Valor</th>
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Forma</th>
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Referencia</th>
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Status</th>
                  <th className="px-4 py-3"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/40">
                {payments.map((pay) => (
                  <tr key={pay.id} className={`hover:bg-slate-800/20 transition-colors ${pay.status === "reversed" ? "opacity-40" : ""}`}>
                    <td className="px-4 py-3 font-mono text-sm">{pay.payment_date}</td>
                    <td className="px-4 py-3 text-right font-semibold text-green-400">{fmt(pay.amount)}</td>
                    <td className="px-4 py-3 text-sm capitalize text-slate-300">{pay.payment_method || "—"}</td>
                    <td className="px-4 py-3 text-sm text-slate-400">{pay.reference_code || "—"}</td>
                    <td className="px-4 py-3">
                      {pay.status === "reversed"
                        ? <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-500/15 text-red-400">Estornado</span>
                        : <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-500/15 text-green-400">Registrado</span>}
                    </td>
                    <td className="px-4 py-3">
                      {pay.status === "posted" && (
                        <button
                          onClick={() => handleReverse(pay.id)}
                          disabled={reversing === pay.id}
                          className="p-1.5 text-slate-500 hover:text-red-400 transition-colors"
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
      <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
        <h2 className="text-lg font-semibold text-slate-200 flex items-center gap-2">
          <Paperclip size={16} className="text-slate-400" /> Anexos
        </h2>
        {attachments.length === 0 ? (
          <p className="text-slate-500 text-sm mt-3">Nenhum anexo.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {attachments.map((a) => (
              <li key={a.id} className="flex items-center gap-3 text-sm">
                <Paperclip size={14} className="text-slate-500" />
                <span className="text-slate-300">{a.original_name}</span>
                <span className="text-xs text-slate-600">({a.attachment_type})</span>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Observacoes */}
      {payable.notes && (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
          <h2 className="text-lg font-semibold text-slate-200">Observacoes</h2>
          <p className="text-slate-400 text-sm mt-2">{payable.notes}</p>
        </div>
      )}
    </div>
  );
}
