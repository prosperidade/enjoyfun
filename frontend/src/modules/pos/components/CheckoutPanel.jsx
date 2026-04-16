import { Check, QrCode, WifiOff } from "lucide-react";

export default function CheckoutPanel({
  cardHint,
  cardHintTone = "muted",
  cardReference,
  canCheckout,
  checkoutHint,
  isOffline,
  onCardReferenceChange,
  onCheckout,
  processingSale,
  resolvingCard,
  total,
}) {
  const cardHintClass =
    cardHintTone === "danger"
      ? "text-red-300"
      : cardHintTone === "success"
        ? "text-emerald-300"
        : cardHintTone === "warning"
          ? "text-amber-300"
          : "text-slate-400";

  return (
    <div className="p-5 bg-slate-900/60 border-t border-slate-700/50 shadow-[0_-10px_30px_rgba(0,0,0,0.3)] z-10">
      <div className="relative mb-4">
        <QrCode
          size={18}
          className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"
        />
        <input
          value={cardReference}
          onChange={(e) => onCardReferenceChange(e.target.value)}
          placeholder="ESCANEIE O QR CLIENTE"
          autoFocus
          className="w-full bg-slate-950 border border-slate-700/50 text-slate-100 rounded-xl pl-10 pr-4 py-3.5 text-sm font-semibold outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all placeholder:text-slate-600"
        />
      </div>
      {cardHint ? (
        <p className={`mb-4 text-sm ${cardHintClass}`}>{cardHint}</p>
      ) : null}
      <div className="flex justify-between items-center mb-4 text-slate-100">
        <span className="text-slate-400">Total</span>
        <span className="text-2xl font-extrabold">R$ {total.toFixed(2)}</span>
      </div>
      {checkoutHint ? (
        <p className="mb-4 text-sm text-amber-300">{checkoutHint}</p>
      ) : null}
      <button
        onClick={onCheckout}
        disabled={!canCheckout || processingSale || resolvingCard}
        className={`w-full py-4 rounded-xl font-bold text-slate-100 transition-all disabled:opacity-50 flex items-center justify-center gap-2 ${isOffline ? "bg-amber-600 hover:bg-amber-500 shadow-[0_0_15px_rgba(217,119,6,0.2)]" : "bg-cyan-600 hover:bg-cyan-500 shadow-[0_0_15px_rgba(147,51,234,0.3)]"}`}
      >
        {processingSale || resolvingCard ? (
          <span className="animate-pulse">PROCESSANDO...</span>
        ) : isOffline ? (
          <>
            <WifiOff size={18} /> SALVAR OFFLINE
          </>
        ) : (
          <>
            <Check size={18} /> FINALIZAR PAGAMENTO
          </>
        )}
      </button>
    </div>
  );
}
