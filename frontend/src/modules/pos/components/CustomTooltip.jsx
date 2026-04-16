export default function CustomTooltip({ active, payload, label }) {
  if (active && payload && payload.length) {
    const data = payload[0].payload;
    let items = [];

    if (data.items_detail) {
      try {
        items =
          typeof data.items_detail === "string"
            ? JSON.parse(data.items_detail)
            : data.items_detail;
      } catch (err) {
        console.error("Erro parse tooltip", err);
      }
    }

    return (
      <div className="bg-slate-900/60 border border-slate-700/50 p-3 rounded-xl shadow-xl z-50">
        <p className="text-slate-400 text-xs mb-1">{label}</p>
        <p className="text-green-400 font-bold text-lg mb-2">
          R$ {parseFloat(data.revenue || 0).toFixed(2)}
        </p>
        {items.length > 0 && (
          <div className="space-y-1">
            {items.map((it, idx) => (
              <div
                key={idx}
                className="text-xs text-slate-300 flex justify-between gap-4"
              >
                <span>
                  {it.qty}x {it.name}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  return null;
}
