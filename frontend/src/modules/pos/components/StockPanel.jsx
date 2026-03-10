import { Plus } from "lucide-react";

export default function StockPanel({
  children,
  onToggleForm,
  sectorTitle,
}) {
  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
        <p className="text-gray-400 text-sm">Controle de Inventário: {sectorTitle}</p>
        <button
          onClick={onToggleForm}
          className="flex items-center gap-2 bg-purple-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-purple-500"
        >
          <Plus size={16} /> Adicionar Produto
        </button>
      </div>

      {children}
    </div>
  );
}
