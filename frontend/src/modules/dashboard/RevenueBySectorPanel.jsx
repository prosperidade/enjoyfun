const sectorItems = [
  { key: "bar", label: "BAR", color: "text-purple-400", qtyLabel: "itens" },
  { key: "food", label: "FOOD", color: "text-orange-400", qtyLabel: "itens" },
  { key: "shop", label: "SHOP", color: "text-blue-400", qtyLabel: "itens" },
  { key: "parking", label: "PARKING", color: "text-cyan-400", qtyLabel: "registros" },
  { key: "tickets", label: "TICKETS", color: "text-pink-400", qtyLabel: "tickets" },
];

export default function RevenueBySectorPanel({ loading, salesSectorTotals }) {
  return (
    <div className="card">
      <h3 className="section-title">Receita por Setor (Últimas 24h)</h3>
      {loading ? (
        <div className="flex h-48 items-center justify-center">
          <div className="spinner h-8 w-8" />
        </div>
      ) : (
        <div className="mt-4 space-y-4">
          {sectorItems.map((item) => {
            const total = Number(salesSectorTotals?.[item.key]?.revenue || 0);
            const qty = Number(salesSectorTotals?.[item.key]?.qty || 0);

            return (
              <div
                key={item.key}
                className="rounded-xl border border-gray-700/60 bg-gray-800/40 px-4 py-3"
              >
                <div className="flex items-center justify-between gap-2">
                  <p className={`text-sm font-semibold ${item.color}`}>{item.label}</p>
                  <p className="text-xs text-gray-500">Consolidado 24h</p>
                </div>
                <div className="mt-2 flex items-end justify-between">
                  <p className="text-lg font-bold text-white">
                    R$ {total.toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                  </p>
                  <p className="text-xs text-gray-500">
                    {qty.toLocaleString("pt-BR")} {item.qtyLabel}
                  </p>
                </div>
              </div>
            );
          })}
          {!salesSectorTotals && (
            <p className="text-sm text-gray-500">
              Sem vendas setoriais nas últimas 24h para o filtro atual.
            </p>
          )}
          {salesSectorTotals &&
            sectorItems.every(
              (item) => Number(salesSectorTotals[item.key]?.revenue || 0) === 0
            ) && (
              <p className="mt-2 text-center text-sm text-gray-500">
                Sem vendas registradas nas últimas 24h para este evento.
              </p>
            )}
        </div>
      )}
    </div>
  );
}
