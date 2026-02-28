import { useEffect, useState, useCallback } from "react";
import api from "../lib/api";
import {
  ShoppingCart,
  Plus,
  Package,
  AlertTriangle,
  Wifi,
  WifiOff,
  Check,
  Trash2,
  Clock,
} from "lucide-react";
import toast from "react-hot-toast";

export default function Bar() {
  const [tab, setTab] = useState("pos");
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [cardToken, setCardToken] = useState("");
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("1"); // Mantém o ID do evento
  const [loading, setLoading] = useState(false);
  const [isOffline, setIsOffline] = useState(!navigator.onLine);
  const [offlineQueue, setOfflineQueue] = useState([]);
  const [processingSale, setProcessingSale] = useState(false);
  const [currentSector, setCurrentSector] = useState("bar");

  const [showAddForm, setShowAddForm] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [prodForm, setProdForm] = useState({
    name: "",
    price: "",
    stock_qty: "",
    low_stock_threshold: 5,
  });

  // 1. Sincronização Offline (Recuperando a função que tínhamos)
  const syncQueue = useCallback(async () => {
    const q = JSON.parse(localStorage.getItem("offline_sales") || "[]");
    if (!q.length) return;
    try {
      await api.post("/sync", { records: q });
      toast.success(`${q.length} venda(s) sincronizada(s)!`);
      localStorage.setItem("offline_sales", "[]");
      setOfflineQueue([]);
    } catch {
      toast.error("Falha na sincronização.");
    }
  }, []);

  useEffect(() => {
    const handleOnline = () => {
      setIsOffline(false);
      syncQueue();
    };
    const handleOffline = () => setIsOffline(true);
    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, [syncQueue]);

  // 2. Carregar Eventos (Usando o setEventId)
  useEffect(() => {
    api
      .get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => {});
  }, []);

  // 3. Carregar Produtos (Usando o loading)
  const loadProducts = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(
        `/${currentSector}/products?event_id=${eventId}`,
      );
      setProducts(res.data.data || []);
    } catch {
      toast.error("Erro ao carregar catálogo.");
    } finally {
      setLoading(false);
    }
  }, [eventId, currentSector]);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);

  const addToCart = (p) => {
    setCart((prev) => {
      const exists = prev.find((i) => i.id === p.id);
      if (exists)
        return prev.map((i) => (i.id === p.id ? { ...i, qty: i.qty + 1 } : i));
      return [...prev, { ...p, qty: 1 }];
    });
  };

  const handleSale = async () => {
    if (!cart.length || !cardToken) return;
    setProcessingSale(true);
    const saleData = {
      event_id: eventId,
      total_amount: cart.reduce((s, i) => s + i.price * i.qty, 0),
      qr_token: cardToken,
      items: cart.map((i) => ({
        product_id: i.id,
        quantity: i.qty,
        unit_price: i.price,
        subtotal: i.qty * i.price,
      })),
    };

    if (isOffline) {
      const q = JSON.parse(localStorage.getItem("offline_sales") || "[]");
      q.push({ offline_id: `sale-${Date.now()}`, payload: saleData });
      localStorage.setItem("offline_sales", JSON.stringify(q));
      setOfflineQueue(q);
      toast.success("Venda salva offline!");
      setCart([]);
      setCardToken("");
    } else {
      try {
        await api.post(`/${currentSector}/checkout`, saleData);
        toast.success("Venda concluída!");
        setCart([]);
        setCardToken("");
        loadProducts();
      } catch (err) {
        toast.error(err.response?.data?.message || "Erro na venda.");
      }
    }
    setProcessingSale(false);
  };

  const handleAddProduct = async (e) => {
    e.preventDefault();
    setSavingProduct(true);
    try {
      await api.post(`/${currentSector}/products`, {
        ...prodForm,
        event_id: eventId,
      });
      toast.success("Cadastrado com sucesso!");
      setShowAddForm(false);
      loadProducts(); // Recarrega para o novo item aparecer na tabela filtrada
    } catch {
      toast.error("Erro ao salvar");
    } finally {
      setSavingProduct(false);
    }
  };
  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white flex items-center gap-2">
            <ShoppingCart className="text-purple-400" /> EnjoyFun POS
          </h1>
          <div className="flex items-center gap-3 mt-1">
            {isOffline ? (
              <span className="text-yellow-500 text-xs flex items-center gap-1">
                <WifiOff size={12} /> Offline
              </span>
            ) : (
              <span className="text-green-500 text-xs flex items-center gap-1">
                <Wifi size={12} /> Online
              </span>
            )}
            {offlineQueue.length > 0 && (
              <button
                onClick={syncQueue}
                className="text-xs text-purple-400 underline"
              >
                Sincronizar {offlineQueue.length}
              </button>
            )}
          </div>
        </div>

        <div className="flex gap-2">
          <select
            className="select text-xs w-40"
            value={eventId}
            onChange={(e) => setEventId(e.target.value)}
          >
            {events.map((ev) => (
              <option key={ev.id} value={ev.id}>
                {ev.name}
              </option>
            ))}
          </select>
          {["bar", "food", "shop"].map((s) => (
            <button
              key={s}
              onClick={() => setCurrentSector(s)} // <-- Isso TEM que disparar a mudança
              className={`px-3 py-1 rounded-full text-[10px] font-bold uppercase transition-all ${
                currentSector === s
                  ? "bg-purple-600 text-white"
                  : "bg-gray-800 text-gray-500"
              }`}
            >
              {s === "bar" ? "🍻 Bar" : s === "food" ? "🍔 Food" : "👕 Loja"}
            </button>
          ))}
        </div>
      </div>

      <div className="flex gap-2 bg-gray-800 p-1 rounded-xl w-fit">
        <button
          onClick={() => setTab("pos")}
          className={`px-6 py-1.5 rounded-lg text-sm font-bold ${tab === "pos" ? "bg-purple-600 text-white" : "text-gray-400 hover:text-white"}`}
        >
          🛒 VENDA
        </button>
        <button
          onClick={() => setTab("stock")}
          className={`px-6 py-1.5 rounded-lg text-sm font-bold ${tab === "stock" ? "bg-purple-600 text-white" : "text-gray-400 hover:text-white"}`}
        >
          📦 ESTOQUE
        </button>
      </div>

      {tab === "pos" && (
        <div className="grid lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2">
            {loading ? (
              <div className="flex justify-center py-20">
                <span className="spinner w-10 h-10 border-purple-500" />
              </div>
            ) : (
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                {products.map((p) => (
                  <button
                    key={p.id}
                    onClick={() => addToCart(p)}
                    className="bg-gray-900 border border-gray-800 p-4 rounded-2xl text-left hover:border-purple-500 transition-all active:scale-95 group"
                  >
                    <p className="font-bold text-white group-hover:text-purple-300 transition-colors">
                      {p.name}
                    </p>
                    <p className="text-purple-400 font-black text-lg">
                      R$ {parseFloat(p.price).toFixed(2)}
                    </p>
                    <div className="flex justify-between items-center mt-2">
                      <span
                        className={`text-[10px] font-bold ${p.stock_qty <= p.low_stock_threshold ? "text-red-500" : "text-gray-500"}`}
                      >
                        QTD: {p.stock_qty}
                      </span>
                      {p.stock_qty <= p.low_stock_threshold && (
                        <AlertTriangle
                          size={12}
                          className="text-red-500 animate-pulse"
                        />
                      )}
                    </div>
                  </button>
                ))}
              </div>
            )}
          </div>

          <div className="card h-fit sticky top-6 border-purple-500/10">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-white">Carrinho</h3>
              <span className="text-[10px] bg-purple-600 px-2 py-0.5 rounded-full">
                {cart.reduce((s, i) => s + i.qty, 0)} itens
              </span>
            </div>
            <div className="max-h-60 overflow-y-auto mb-4 space-y-2">
              {cart.map((i) => (
                <div
                  key={i.id}
                  className="flex justify-between text-sm bg-gray-800/50 p-2 rounded-lg"
                >
                  <span className="text-gray-300">
                    {i.qty}x {i.name}
                  </span>
                  <div className="flex items-center gap-3">
                    <span className="font-bold">
                      R$ {(i.qty * i.price).toFixed(2)}
                    </span>
                    <button
                      onClick={() =>
                        setCart((prev) =>
                          prev.filter((item) => item.id !== i.id),
                        )
                      }
                      className="text-red-500"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                </div>
              ))}
            </div>
            <div className="border-t border-gray-800 pt-4 mb-4">
              <div className="flex justify-between text-gray-400 text-sm mb-1">
                <span>Subtotal</span>
                <span>
                  R$ {cart.reduce((s, i) => s + i.price * i.qty, 0).toFixed(2)}
                </span>
              </div>
              <div className="flex justify-between text-white font-black text-xl">
                <span>Total</span>
                <span>
                  R$ {cart.reduce((s, i) => s + i.price * i.qty, 0).toFixed(2)}
                </span>
              </div>
            </div>
            <div className="relative mb-4">
              <Clock
                className="absolute left-3 top-3 text-gray-500"
                size={16}
              />
              <input
                className="input pl-10"
                placeholder="QR TOKEN CLIENTE"
                value={cardToken}
                onChange={(e) => setCardToken(e.target.value)}
              />
            </div>
            <button
              onClick={handleSale}
              disabled={!cart.length || processingSale || !cardToken}
              className="btn-primary w-full py-4 font-bold text-base"
            >
              {processingSale ? "PROCESSANDO..." : "FINALIZAR PAGAMENTO"}
            </button>
          </div>
        </div>
      )}

      {tab === "stock" && (
        <div className="space-y-4">
          <div className="flex justify-between items-center">
            <h2 className="text-lg font-bold text-white flex items-center gap-2">
              <Package size={18} /> Inventário - {currentSector.toUpperCase()}
            </h2>
            <button
              onClick={() => setShowAddForm(!showAddForm)}
              className="btn-primary text-xs px-4 flex items-center gap-2"
            >
              <Plus size={14} /> NOVO PRODUTO
            </button>
          </div>

          {showAddForm && (
            <div className="card border-purple-500/20 bg-gray-900/50 backdrop-blur-sm">
              <form
                onSubmit={handleAddProduct}
                className="grid grid-cols-1 md:grid-cols-4 gap-4"
              >
                <div className="space-y-1">
                  <label className="text-[10px] text-gray-500 uppercase font-bold">
                    Nome
                  </label>
                  <input
                    className="input"
                    value={prodForm.name}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, name: e.target.value })
                    }
                    required
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] text-gray-500 uppercase font-bold">
                    Preço
                  </label>
                  <input
                    className="input"
                    type="number"
                    step="0.01"
                    value={prodForm.price}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, price: e.target.value })
                    }
                    required
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] text-gray-500 uppercase font-bold">
                    Estoque
                  </label>
                  <input
                    className="input"
                    type="number"
                    value={prodForm.stock_qty}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, stock_qty: e.target.value })
                    }
                    required
                  />
                </div>
                <div className="flex items-end">
                  <button
                    type="submit"
                    disabled={savingProduct}
                    className="btn-primary w-full h-10"
                  >
                    {savingProduct ? "..." : "SALVAR"}
                  </button>
                </div>
              </form>
            </div>
          )}

          <div className="card p-0 overflow-hidden border-gray-800">
            <table className="w-full text-left">
              <thead className="bg-gray-800/80 text-[10px] uppercase text-gray-400">
                <tr>
                  <th className="p-4">Produto</th>
                  <th className="p-4 text-center">Preço</th>
                  <th className="p-4 text-center">Estoque</th>
                  <th className="p-4 text-right">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-800">
                {products.map((p) => (
                  <tr
                    key={p.id}
                    className="hover:bg-gray-800/30 transition-colors"
                  >
                    <td className="p-4 font-medium text-gray-200">{p.name}</td>
                    <td className="p-4 text-center text-gray-300 font-bold">
                      R$ {parseFloat(p.price).toFixed(2)}
                    </td>
                    <td className="p-4 text-center">
                      <span
                        className={`px-2 py-1 rounded-lg text-xs font-bold ${p.stock_qty <= p.low_stock_threshold ? "bg-red-900/30 text-red-500" : "bg-green-900/30 text-green-500"}`}
                      >
                        {p.stock_qty} un.
                      </span>
                    </td>
                    <td className="p-4 text-right">
                      {p.stock_qty <= p.low_stock_threshold ? (
                        <span className="text-[10px] text-red-500 font-bold uppercase">
                          Repor Já
                        </span>
                      ) : (
                        <Check size={14} className="ml-auto text-green-500" />
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
