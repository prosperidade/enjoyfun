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
      ? "text-red-400"
      : cardHintTone === "success"
        ? "text-emerald-400"
        : cardHintTone === "warning"
          ? "text-amber-400"
          : "text-slate-500";

  return (
    <div className="space-y-3 pt-4 border-t border-slate-700/30">
      {/* Card input */}
      <div className="relative">
        <QrCode size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
        <input
          value={cardReference}
          onChange={(e) => onCardReferenceChange(e.target.value)}
          placeholder="ESCANEIE O QR CLIENTE"
          autoFocus
          className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 rounded-xl pl-10 pr-4 py-3 text-sm font-semibold outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 transition-all placeholder:text-slate-600"
        />
      </div>

      {cardHint && <p className={`text-xs ${cardHintClass}`}>{cardHint}</p>}
      {checkoutHint && <p className="text-xs text-amber-400">{checkoutHint}</p>}

      {/* Totals */}
      <div className="flex justify-between text-slate-400 text-sm">
        <span>Subtotal</span>
        <span>R$ {total.toFixed(2)}</span>
      </div>
      <div className="flex justify-between items-end pt-2">
        <span className="text-slate-200 font-headline font-bold uppercase tracking-widest text-xs">Total</span>
        <span className="text-cyan-400 font-headline font-black text-4xl leading-none">
          R$ {total.toFixed(2)}
        </span>
      </div>

      {/* Checkout button */}
      <div className="pt-4">
        <button
          onClick={onCheckout}
          disabled={!canCheckout || processingSale || resolvingCard}
          className={`w-full h-14 rounded-xl font-headline font-black text-lg tracking-widest uppercase transition-all disabled:opacity-50 flex items-center justify-center gap-2 ${
            isOffline
              ? "bg-gradient-to-r from-amber-500 to-amber-400 text-slate-950 shadow-[0_0_20px_rgba(245,158,11,0.2)] hover:scale-[1.02]"
              : "bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 shadow-[0_0_20px_rgba(0,240,255,0.2)] hover:scale-[1.02]"
          }`}
        >
          {processingSale || resolvingCard ? (
            <span className="animate-pulse">PROCESSANDO...</span>
          ) : isOffline ? (
            <><WifiOff size={18} /> SALVAR OFFLINE</>
          ) : (
            <><Check size={18} /> CHECKOUT</>
          )}
        </button>
      </div>
    </div>
  );
}
