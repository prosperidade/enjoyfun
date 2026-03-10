import StockListRow from "./StockListRow";

export default function StockList({
  fallbackIcon,
  onDelete,
  onEdit,
  products,
}) {
  return (
    <div className="bg-gray-900 border border-gray-800 rounded-2xl p-4">
      {products.length === 0 ? (
        <p className="text-center py-8 text-gray-500 text-sm">Vazio.</p>
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
