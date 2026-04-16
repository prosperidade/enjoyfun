import StockListRow from "./StockListRow";

export default function StockList({
  fallbackIcon,
  onDelete,
  onEdit,
  products,
}) {
  return (
    <div className="bg-slate-900/60 border border-slate-800/40 rounded-2xl p-4">
      {products.length === 0 ? (
        <p className="text-center py-8 text-slate-500 text-sm">Vazio.</p>
      ) : (
        products.map((product) => (
          <StockListRow
            key={product.id}
            fallbackIcon={fallbackIcon}
            onDelete={onDelete}
            onEdit={onEdit}
            product={product}
          />
        ))
      )}
    </div>
  );
}
