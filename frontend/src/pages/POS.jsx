import { useState, useEffect, useCallback } from "react";
import { v4 as uuidv4 } from "uuid";
import {
  ShoppingCart,
  Plus,
  Minus,
  Trash2,
  Package,
  AlertTriangle,
  Check,
  QrCode,
  Clock,
} from "lucide-react";
import { db } from "../lib/db";
import { useNetwork } from "../hooks/useNetwork";
import toast from "react-hot-toast";
import api from "../lib/api";
import {
  AreaChart,
  Area,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  ResponsiveContainer,
} from "recharts";

const CustomTooltip = ({ active, payload, label }) => {
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
      <div className="bg-gray-900 border border-gray-700 p-3 rounded-xl shadow-xl z-50">
        <p className="text-gray-400 text-xs mb-1">{label}</p>
        <p className="text-green-400 font-bold text-lg mb-2">
          R$ {parseFloat(data.revenue || 0).toFixed(2)}
        </p>
        {items.length > 0 && (
          <div className="space-y-1">
            {items.map((it, idx) => (
              <div
                key={idx}
                className="text-xs text-gray-300 flex justify-between gap-4"
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
};

export default function POS() {
  const { syncOfflineData } = useNetwork();
  const [tab, setTab] = useState("pos");
  const [cart, setCart] = useState([]);
  const [total, setTotal] = useState(0);
  const [cardToken, setCardToken] = useState("");
  const [products, setProducts] = useState([]);
  const [recentSales, setRecentSales] = useState([]);
  const [reportData, setReportData] = useState(null);
  const [processingSale, setProcessingSale] = useState(false);
  const [loadingInsight, setLoadingInsight] = useState(false);
  const [chatHistory, setChatHistory] = useState([]);
  const [timeFilter, setTimeFilter] = useState("24h");
  const [aiQuestion, setAiQuestion] = useState("");

  // Alternador de setor para o Admin Master
  const [currentSector, setCurrentSector] = useState("bar");

  const [showAddForm, setShowAddForm] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [prodForm, setProdForm] = useState({
    id: null,
    name: "",
    price: "",
    stock_qty: "",
    low_stock_threshold: 5,
    sector: "bar",
  });

  const loadProducts = useCallback(async () => {
    try {
      const res = await api.get("/bar/products");
      if (res.data?.data) {
        setProducts(
          res.data.data.map((p) => ({
            ...p,
            price: parseFloat(p.price),
            icon:
              p.sector === "food" ? "🍔" : p.sector === "shop" ? "👕" : "🍻",
            color: "bg-indigo-900/40 border-indigo-700/50",
          })),
        );
      }
    } catch (err) {
      console.error("Erro ao listar catálogo", err);
    }
  }, []);

  const loadRecentSales = useCallback(async () => {
    try {
      const res = await api.get(`/bar/sales?event_id=1&filter=${timeFilter}`);
      if (res.data?.data) {
        if (res.data.data.recent_sales) {
          setRecentSales(res.data.data.recent_sales);
          setReportData(res.data.data.report);
        } else {
          setRecentSales(res.data.data);
        }
      }
    } catch (err) {
      console.error("Erro ao carregar vendas", err);
    }
  }, [timeFilter]);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);
  useEffect(() => {
    loadRecentSales();
  }, [loadRecentSales]);

  useEffect(() => {
    setTotal(cart.reduce((acc, item) => acc + item.price * item.quantity, 0));
  }, [cart]);

  const addToCart = (product) => {
    setCart((prev) => {
      const existing = prev.find((p) => p.id === product.id);
      if (existing) {
        return prev.map((p) =>
          p.id === product.id ? { ...p, quantity: p.quantity + 1 } : p,
        );
      }
      return [...prev, { ...product, quantity: 1 }];
    });
  };

  const updateQuantity = (id, delta) => {
    setCart((prev) =>
      prev.map((p) => {
        if (p.id === id) {
          const newQ = p.quantity + delta;
          return newQ > 0 ? { ...p, quantity: newQ } : p;
        }
        return p;
      }),
    );
  };

  const removeFromCart = (id) =>
    setCart((prev) => prev.filter((p) => p.id !== id));

  const handleCheckout = async () => {
    if (cart.length === 0 || !cardToken) return;
    setProcessingSale(true);
    const offlineId = uuidv4();
    const payload = {
      event_id: 1,
      total_amount: total,
      qr_token: cardToken,
      items: cart.map((item) => ({
        product_id: item.id,
        name: item.name,
        quantity: item.quantity,
        unit_price: item.price,
        subtotal: item.quantity * item.price,
      })),
    };

    try {
      await api.post("/bar/checkout", { ...payload, offline_id: offlineId });
      toast.success("Venda Realizada!", { icon: "✅" });
      setCart([]);
      setCardToken("");
      await loadProducts();
      await loadRecentSales();
    } catch (err) {
      if (
        err.message === "Network Error" ||
        (err.response && err.response.status >= 500)
      ) {
        try {
          await db.offlineQueue.add({
            offline_id: offlineId,
            payload_type: "sale",
            payload: payload,
            status: "pending",
            created_offline_at: new Date().toISOString(),
          });
          toast.success("Salvo Offline!", { icon: "💾" });
          setCart([]);
          setCardToken("");
          syncOfflineData();
        } catch (e) {
          console.error("Erro offline", e);
        }
      } else {
        toast.error(err.response?.data?.message || "Erro na venda.");
      }
    } finally {
      setProcessingSale(false);
    }
  };

  const handleAddProduct = async (e) => {
    e.preventDefault();
    setSavingProduct(true);
    try {
      const payload = {
        ...prodForm,
        price: parseFloat(prodForm.price),
        stock_qty: parseInt(prodForm.stock_qty, 10),
        event_id: 1,
      };
      if (prodForm.id) {
        await api.put(`/bar/products/${prodForm.id}`, payload);
        toast.success("Atualizado!");
      } else {
        await api.post("/bar/products", payload);
        toast.success("Cadastrado!");
      }
      setShowAddForm(false);
      setProdForm({
        id: null,
        name: "",
        price: "",
        stock_qty: "",
        low_stock_threshold: 5,
        sector: "bar",
      });
      loadProducts();
    } catch {
      toast.error("Erro ao salvar.");
    } finally {
      setSavingProduct(false);
    }
  };

  const handleEditClick = (p) => {
    setProdForm({
      id: p.id,
      name: p.name,
      price: p.price,
      stock_qty: p.stock_qty,
      low_stock_threshold: p.low_stock_threshold || 5,
      sector: p.sector || "bar",
    });
    setShowAddForm(true);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const handleDeleteProduct = async (id) => {
    if (!window.confirm("Excluir produto?")) return;
    try {
      await api.delete(`/bar/products/${id}`);
      toast.success("Removido!");
      loadProducts();
    } catch {
      toast.error("Não foi possível remover.");
    }
  };

  const requestInsight = async () => {
    if (!aiQuestion.trim()) return;
    const q = aiQuestion;
    setAiQuestion("");
    setChatHistory((prev) => [...prev, { role: "user", content: q }]);
    setLoadingInsight(true);
    try {
      const res = await api.post(`/bar/insights?filter=${timeFilter}`, {
        event_id: 1,
        question: q,
      });
      if (res.data?.data?.insight) {
        setChatHistory((prev) => [
          ...prev,
          { role: "ai", content: res.data.data.insight },
        ]);
      }
    } catch {
      setChatHistory((prev) => [
        ...prev,
        { role: "ai", content: "Erro na conexão com IA." },
      ]);
    } finally {
      setLoadingInsight(false);
    }
  };

  return (
    <div className="h-[calc(100vh-8rem)] flex flex-col gap-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white flex items-center gap-2">
            <ShoppingCart className="text-purple-400" /> POS EnjoyFun
          </h1>

          {/* SELETOR DE SETORES - AGORA NO LUGAR CERTO */}
          <div className="flex gap-2 mt-3">
            <button
              onClick={() => setCurrentSector("bar")}
              className={`text-[10px] font-bold px-3 py-1 rounded-full transition-all ${currentSector === "bar" ? "bg-purple-600 text-white shadow-[0_0_10px_rgba(168,85,247,0.5)]" : "bg-gray-800 text-gray-400"}`}
            >
              🍻 BAR
            </button>
            <button
              onClick={() => setCurrentSector("food")}
              className={`text-[10px] font-bold px-3 py-1 rounded-full transition-all ${currentSector === "food" ? "bg-orange-600 text-white shadow-[0_0_10px_rgba(234,88,12,0.5)]" : "bg-gray-800 text-gray-400"}`}
            >
              🍔 ALIMENTAÇÃO
            </button>
            <button
              onClick={() => setCurrentSector("shop")}
              className={`text-[10px] font-bold px-3 py-1 rounded-full transition-all ${currentSector === "shop" ? "bg-blue-600 text-white shadow-[0_0_10px_rgba(37,99,235,0.5)]" : "bg-gray-800 text-gray-400"}`}
            >
              👕 LOJA
            </button>
          </div>
        </div>

        <div className="flex gap-2 bg-gray-800 p-1 rounded-xl w-fit">
          <button
            onClick={() => setTab("pos")}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === "pos" ? "bg-purple-600 text-white" : "text-gray-400 hover:text-white"}`}
          >
            🛒 Venda
          </button>
          <button
            onClick={() => setTab("stock")}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === "stock" ? "bg-purple-600 text-white" : "text-gray-400 hover:text-white"}`}
          >
            📦 Estoque
          </button>
          <button
            onClick={() => setTab("reports")}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === "reports" ? "bg-purple-600 text-white" : "text-gray-400 hover:text-white"}`}
          >
            📊 BI
          </button>
        </div>
      </div>

      {tab === "pos" && (
        <div className="flex flex-col lg:flex-row gap-6 h-full min-h-0">
          <div className="flex-[2] overflow-y-auto pr-2 pb-4">
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
              {products
                .filter((p) => (p.sector || "bar") === currentSector)
                .map((product) => (
                  <button
                    key={product.id}
                    onClick={() => addToCart(product)}
                    disabled={product.stock_qty <= 0}
                    className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-3 transition-all active:scale-95 ${product.stock_qty > 0 ? (product.stock_qty <= (product.low_stock_threshold || 5) ? "bg-red-900/20 border-red-500/50 hover:border-red-400" : "hover:border-purple-500 bg-gray-800/40 border-gray-700/50") : "opacity-40 cursor-not-allowed bg-gray-900 border-gray-800"}`}
                  >
                    <div className="text-4xl">
                      {product.icon || (currentSector === "bar" ? "🍻" : "🍔")}
                    </div>
                    <div className="text-center w-full">
                      <p className="text-white font-semibold text-sm leading-tight truncate">
                        {product.name}
                      </p>
                      <p className="text-purple-300 font-bold mt-1">
                        R$ {product.price.toFixed(2)}
                      </p>
                      <p
                        className={`text-xs mt-1 font-bold ${product.stock_qty <= (product.low_stock_threshold || 5) ? "text-red-500" : "text-gray-500"}`}
                      >
                        {product.stock_qty > 0
                          ? `${product.stock_qty} un.`
                          : "Esgotado"}
                      </p>
                    </div>
                  </button>
                ))}
            </div>
          </div>

          <div className="flex-1 bg-gray-900 border border-gray-800 rounded-2xl flex flex-col overflow-hidden min-h-[500px]">
            <div className="px-5 py-4 bg-gray-800/50 border-b border-gray-800 flex items-center justify-between font-bold text-white">
              Venda Atual{" "}
              <span className="bg-purple-600 px-2.5 py-1 rounded-full text-xs">
                {cart.reduce((a, c) => a + c.quantity, 0)} un.
              </span>
            </div>
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {cart.length === 0 ? (
                <div className="h-full flex flex-col items-center justify-center text-gray-600 gap-3">
                  <ShoppingCart size={48} className="opacity-20" />
                  <p className="text-sm">Selecione produtos</p>
                </div>
              ) : (
                cart.map((item) => (
                  <div
                    key={item.id}
                    className="flex flex-col gap-2 p-3 bg-gray-800/40 border border-gray-700/50 rounded-xl"
                  >
                    <div className="flex justify-between text-sm text-white font-medium">
                      <span className="truncate pr-2">{item.name}</span>
                      <span className="text-purple-300">
                        R$ {(item.price * item.quantity).toFixed(2)}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-1 bg-gray-900 rounded-lg p-1 border border-gray-700">
                        <button
                          onClick={() => updateQuantity(item.id, -1)}
                          className="p-1 text-gray-400 hover:text-white"
                        >
                          <Minus size={14} />
                        </button>
                        <span className="w-8 text-center text-sm font-bold">
                          {item.quantity}
                        </span>
                        <button
                          onClick={() => updateQuantity(item.id, 1)}
                          className="p-1 text-gray-400 hover:text-white"
                        >
                          <Plus size={14} />
                        </button>
                      </div>
                      <button
                        onClick={() => removeFromCart(item.id)}
                        className="p-1.5 text-gray-500 hover:text-red-400"
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
            <div className="p-5 bg-gray-800/80 border-t border-gray-700">
              <div className="relative mb-4">
                <QrCode
                  className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"
                  size={16}
                />
                <input
                  value={cardToken}
                  onChange={(e) => setCardToken(e.target.value)}
                  placeholder="ESCANEIE O QR CLIENTE"
                  className="w-full bg-gray-900 border border-gray-700 text-white rounded-lg pl-10 pr-4 py-3 text-sm focus:border-purple-500 outline-none"
                />
              </div>
              <div className="flex justify-between items-center mb-4 text-white">
                <span className="text-gray-400">Total</span>
                <span className="text-3xl font-extrabold">
                  R$ {total.toFixed(2)}
                </span>
              </div>
              <button
                onClick={handleCheckout}
                disabled={cart.length === 0 || processingSale || !cardToken}
                className="btn-primary w-full py-4 text-base font-bold"
              >
                {processingSale ? (
                  <span className="spinner w-5 h-5" />
                ) : (
                  "💳 FINALIZAR PAGAMENTO"
                )}
              </button>

              {recentSales.length > 0 && (
                <div className="mt-4 pt-4 border-t border-gray-800">
                  <p className="text-[10px] font-bold text-gray-500 uppercase flex items-center gap-1">
                    <Clock size={10} /> Últimas Vendas
                  </p>
                  {recentSales.slice(0, 2).map((s) => (
                    <div
                      key={s.id}
                      className="flex justify-between text-[10px] text-gray-500 mt-1"
                    >
                      <span>VENDA #{s.id}</span>
                      <span className="text-green-500 font-bold">
                        R$ {parseFloat(s.total_amount).toFixed(2)}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {tab === "stock" && (
        <div className="space-y-4">
          <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
            <p className="text-gray-400 text-sm">
              Controle de Inventário por Setor.
            </p>
            <button
              onClick={() => setShowAddForm(!showAddForm)}
              className="btn-primary text-sm"
            >
              <Plus size={16} /> Adicionar Produto
            </button>
          </div>

          {showAddForm && (
            <div className="card border-purple-800/40">
              <h3 className="section-title text-sm mb-4">
                {prodForm.id ? "Editar" : "Novo"} Produto
              </h3>
              <form
                onSubmit={handleAddProduct}
                className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4"
              >
                <div className="lg:col-span-1">
                  <label className="input-label text-xs">Setor</label>
                  <select
                    className="select text-sm"
                    value={prodForm.sector}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, sector: e.target.value })
                    }
                  >
                    <option value="bar">Bar</option>
                    <option value="food">Alimentação</option>
                    <option value="shop">Loja</option>
                  </select>
                </div>
                <div className="lg:col-span-1">
                  <label className="input-label text-xs">Nome</label>
                  <input
                    className="input text-sm"
                    required
                    value={prodForm.name}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, name: e.target.value })
                    }
                  />
                </div>
                <div>
                  <label className="input-label text-xs">Preço (R$)</label>
                  <input
                    className="input text-sm"
                    type="number"
                    step="0.01"
                    required
                    value={prodForm.price}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, price: e.target.value })
                    }
                  />
                </div>
                <div>
                  <label className="input-label text-xs">Estoque</label>
                  <input
                    className="input text-sm"
                    type="number"
                    required
                    value={prodForm.stock_qty}
                    onChange={(e) =>
                      setProdForm({ ...prodForm, stock_qty: e.target.value })
                    }
                  />
                </div>
                <div>
                  <label className="input-label text-xs">Mínimo</label>
                  <input
                    className="input text-sm"
                    type="number"
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
                <div className="lg:col-span-5 flex justify-end gap-2 mt-2">
                  <button
                    type="button"
                    onClick={() => setShowAddForm(false)}
                    className="btn-ghost text-xs"
                  >
                    Cancelar
                  </button>
                  <button
                    type="submit"
                    disabled={savingProduct}
                    className="btn-primary text-xs"
                  >
                    {savingProduct ? "..." : "Salvar"}
                  </button>
                </div>
              </form>
            </div>
          )}

          <div className="card">
            {products.length === 0 ? (
              <p className="text-center py-8 text-gray-500 text-sm">
                Nenhum produto cadastrado.
              </p>
            ) : (
              products.map((p) => (
                <div
                  key={p.id}
                  className="flex items-center gap-4 py-3 border-b border-gray-800 last:border-0 hover:bg-gray-800/30 px-2 rounded-xl"
                >
                  <div className="text-2xl w-8 text-center">{p.icon}</div>
                  <div className="flex-1">
                    <p className="text-sm font-medium text-white">
                      {p.name}{" "}
                      <span className="text-[10px] bg-gray-700 px-1.5 rounded uppercase ml-2 text-gray-400">
                        {p.sector}
                      </span>
                    </p>
                    <p className="text-xs text-gray-500">ID: #{p.id}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    {p.stock_qty <= (p.low_stock_threshold || 5) && (
                      <AlertTriangle size={14} className="text-yellow-500" />
                    )}
                    <span
                      className={`badge ${p.stock_qty <= (p.low_stock_threshold || 5) ? "badge-yellow" : "badge-green"}`}
                    >
                      {p.stock_qty} un.
                    </span>
                  </div>
                  <p className="text-gray-300 font-bold w-24 text-right">
                    R$ {p.price.toFixed(2)}
                  </p>
                  <div className="flex items-center gap-2 ml-4">
                    <button
                      onClick={() => handleEditClick(p)}
                      className="p-1.5 text-blue-400 hover:bg-blue-900/30 rounded-lg"
                    >
                      ✎
                    </button>
                    <button
                      onClick={() => handleDeleteProduct(p.id)}
                      className="p-1.5 text-red-500 hover:bg-red-900/30 rounded-lg"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      )}

      {tab === "reports" && (
        <div className="space-y-6 overflow-y-auto pb-6">
          <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
            <div className="flex gap-2 bg-gray-800 p-1 rounded-lg w-fit">
              {["1h", "5h", "24h", "total"].map((f) => (
                <button
                  key={f}
                  onClick={() => setTimeFilter(f)}
                  className={`px-3 py-1 text-xs font-medium rounded-md transition-all ${timeFilter === f ? "bg-indigo-600 text-white" : "text-gray-400 hover:text-white"}`}
                >
                  {f.toUpperCase()}
                </button>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <input
                value={aiQuestion}
                onChange={(e) => setAiQuestion(e.target.value)}
                placeholder="Pergunte à IA..."
                className="input bg-gray-900 border border-indigo-500/30 text-sm w-64"
                onKeyDown={(e) => e.key === "Enter" && requestInsight()}
              />
              <button
                onClick={requestInsight}
                disabled={loadingInsight}
                className="btn-primary text-sm bg-indigo-600"
              >
                {loadingInsight ? "..." : "✨ Analisar"}
              </button>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="card bg-purple-900/20 border-purple-800/50">
              <h3 className="text-gray-400 text-sm font-bold uppercase">
                Faturamento
              </h3>
              <p className="text-3xl font-extrabold text-white mt-2">
                R$ {parseFloat(reportData?.total_revenue || 0).toFixed(2)}
              </p>
            </div>
            <div className="card bg-indigo-900/20 border-indigo-800/50">
              <h3 className="text-gray-400 text-sm font-bold uppercase">
                Vendidos
              </h3>
              <p className="text-3xl font-extrabold text-white mt-2">
                {reportData?.total_items || 0} un.
              </p>
            </div>
          </div>

          <div className="grid gap-6">
            {chatHistory.length > 0 && (
              <div className="bg-gradient-to-r from-gray-900 to-gray-800 border border-indigo-500/30 p-4 rounded-xl shadow-lg flex flex-col gap-3 max-h-96 overflow-y-auto">
                {chatHistory.map((msg, i) => (
                  <div
                    key={i}
                    className={`p-3 rounded-lg text-sm max-w-[85%] ${msg.role === "user" ? "bg-indigo-600/20 text-indigo-100 self-end ml-auto" : "bg-purple-900/40 text-purple-100 self-start"}`}
                  >
                    <div className="text-[10px] font-bold uppercase mb-1">
                      {msg.role === "ai" ? "🤖 Gemini AI" : "👤 André"}
                    </div>
                    <p className="whitespace-pre-wrap">{msg.content}</p>
                  </div>
                ))}
              </div>
            )}

            <div className="card">
              <h3 className="section-title">Timeline</h3>
              {reportData?.sales_chart?.length ? (
                <div className="h-72 mt-4 text-xs">
                  <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={reportData.sales_chart}>
                      <defs>
                        <linearGradient
                          id="colorRevenue"
                          x1="0"
                          y1="0"
                          x2="0"
                          y2="1"
                        >
                          <stop
                            offset="5%"
                            stopColor="#9333ea"
                            stopOpacity={0.8}
                          />
                          <stop
                            offset="95%"
                            stopColor="#9333ea"
                            stopOpacity={0}
                          />
                        </linearGradient>
                      </defs>
                      <CartesianGrid
                        strokeDasharray="3 3"
                        stroke="#374151"
                        vertical={false}
                      />
                      <XAxis dataKey="time" stroke="#9ca3af" />
                      <YAxis
                        stroke="#9ca3af"
                        tickFormatter={(v) => `R$${v}`}
                        width={60}
                      />
                      <RechartsTooltip content={<CustomTooltip />} />
                      <Area
                        type="monotone"
                        dataKey="revenue"
                        stroke="#a855f7"
                        fill="url(#colorRevenue)"
                      />
                    </AreaChart>
                  </ResponsiveContainer>
                </div>
              ) : (
                <p className="text-gray-500 text-sm mt-4">Sem dados.</p>
              )}
            </div>

            <div className="card">
              <h3 className="section-title">Mix de Vendas</h3>
              {reportData?.product_mix?.length ? (
                <div className="h-72 mt-4 text-xs">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={reportData.product_mix} layout="vertical">
                      <CartesianGrid
                        strokeDasharray="3 3"
                        stroke="#374151"
                        horizontal={false}
                      />
                      <XAxis type="number" stroke="#9ca3af" />
                      <YAxis
                        dataKey="name"
                        type="category"
                        stroke="#9ca3af"
                        width={110}
                      />
                      <RechartsTooltip cursor={{ fill: "#1f2937" }} />
                      <Bar
                        dataKey="qty_sold"
                        fill="#6366f1"
                        radius={[0, 4, 4, 0]}
                        name="Vendas"
                      />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : (
                <p className="text-gray-500 text-sm mt-4">Sem dados.</p>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
