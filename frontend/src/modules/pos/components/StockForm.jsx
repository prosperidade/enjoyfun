import { useState, useEffect } from "react";
import toast from "react-hot-toast";
import api from "../../../lib/api";

export default function StockForm({
  currentSector,
  eventId,
  onCancel,
  onSubmit,
  prodForm,
  savingProduct,
  sectorTitle,
  setProdForm,
  showAddForm,
}) {
  const [pdvPoints, setPdvPoints] = useState([]);

  useEffect(() => {
    if (!showAddForm || !eventId) return;
    api
      .get(`/event-pdv-points?event_id=${eventId}`)
      .then((res) => {
        const all = res.data?.data || res.data || [];
        setPdvPoints(all.filter((p) => p.pdv_type === currentSector));
      })
      .catch(() => setPdvPoints([]));
  }, [showAddForm, eventId, currentSector]);

  if (!showAddForm) {
    return null;
  }

  const handleSubmit = (e) => {
    e.preventDefault();
    if (parseFloat(prodForm.price) <= 0) {
      toast.error("Preco deve ser maior que zero");
      return;
    }
    const costPrice = parseFloat(prodForm.cost_price) || 0;
    const salePrice = parseFloat(prodForm.price) || 0;
    if (costPrice > 0 && salePrice > 0 && costPrice >= salePrice) {
      toast("Custo do produto esta igual ou acima do preco de venda", { icon: "⚠️" });
    }
    if (parseInt(prodForm.low_stock_threshold, 10) < 0) {
      setProdForm({ ...prodForm, low_stock_threshold: 0 });
    }
    onSubmit(e);
  };

  return (
    <div className="bg-gray-900 p-6 rounded-2xl border border-purple-800/40">
      <h3 className="text-white font-bold mb-4">
        {prodForm.id ? "Editar" : "Novo"} Produto
      </h3>
      <form
        onSubmit={handleSubmit}
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7 gap-4"
      >
        <div>
          <label className="text-xs text-gray-500 block mb-1">Setor</label>
          <select
            className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
            value={prodForm.sector}
            disabled
          >
            <option value={currentSector}>{sectorTitle}</option>
          </select>
        </div>
        {pdvPoints.length > 0 && (
          <div>
            <label className="text-xs text-gray-500 block mb-1">Ponto de Venda</label>
            <select
              className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
              value={prodForm.pdv_point_id || ""}
              onChange={(e) =>
                setProdForm({ ...prodForm, pdv_point_id: e.target.value ? Number(e.target.value) : "" })
              }
            >
              <option value="">Nenhum (geral)</option>
              {pdvPoints.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
        )}
        <div>
          <label className="text-xs text-gray-500 block mb-1">Nome</label>
          <input
            className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
            required
            value={prodForm.name}
            onChange={(e) => setProdForm({ ...prodForm, name: e.target.value })}
          />
        </div>
        <div>
          <label className="text-xs text-gray-500 block mb-1">Preço (R$)</label>
          <input
            className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
            type="number"
            min="0.01"
            step="0.01"
            required
            value={prodForm.price}
            onChange={(e) => setProdForm({ ...prodForm, price: e.target.value })}
          />
        </div>
        <div>
          <label className="text-xs text-gray-500 block mb-1">Custo do Produto (R$)</label>
          <input
            className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            value={prodForm.cost_price}
            onChange={(e) => setProdForm({ ...prodForm, cost_price: e.target.value })}
          />
        </div>
        <div>
          <label className="text-xs text-gray-500 block mb-1">Estoque</label>
          <input
            className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
            type="number"
            min="0"
            required
            value={prodForm.stock_qty}
            onChange={(e) =>
              setProdForm({
                ...prodForm,
                stock_qty: e.target.value,
              })
            }
          />
        </div>
        <div>
          <label className="text-xs text-gray-500 block mb-1">Mínimo</label>
          <input
            className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
            type="number"
            min="0"
            required
            value={prodForm.low_stock_threshold}
            onChange={(e) =>
              setProdForm({
                ...prodForm,
                low_stock_threshold: e.target.value,
              })
            }
          />
        </div>
        <div className="col-span-full flex justify-end gap-2 mt-2">
          <button
            type="button"
            onClick={onCancel}
            className="px-4 py-2 text-gray-500 text-xs hover:text-white"
          >
            Cancelar
          </button>
          <button
            type="submit"
            disabled={savingProduct}
            className="bg-purple-600 px-6 py-2 rounded-lg text-white text-xs font-bold hover:bg-purple-500"
          >
            {savingProduct ? "..." : "Salvar"}
          </button>
        </div>
      </form>
    </div>
  );
}
