import { useState, useEffect, useCallback } from "react";
import { v4 as uuidv4 } from "uuid";
import { useNavigate } from "react-router-dom"; // ADICIONADO
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
  Beer,
  UtensilsCrossed,
  Shirt,
  Wifi,
  WifiOff,
  ArrowLeft, // ADICIONADO
} from "lucide-react";
import { db } from "../lib/db";
import { useNetwork } from "../hooks/useNetwork";
import toast from "react-hot-toast";
import api from "../lib/api";
import {
  AreaChart,
  Area,
  BarChart,
  Bar as RechartsBar,
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

export default function POS({ fixedSector = "bar" }) {
  const navigate = useNavigate(); // ADICIONADO
  const { syncOfflineData } = useNetwork();

  // Estados Gerais
  const [tab, setTab] = useState("pos");
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("1");
  const [loading, setLoading] = useState(false);
  const [isOffline, setIsOffline] = useState(!navigator.onLine);
  const [, setOfflineQueue] = useState([]);

  // Setor Info
  const [currentSector, setCurrentSector] = useState(fixedSector);

  useEffect(() => {
    setCurrentSector(fixedSector);
    setCart([]);
  }, [fixedSector]);

  const getSectorInfo = () => {
    if (currentSector === "food")
      return {
        icon: <UtensilsCrossed className="text-orange-400" size={28} />,
        title: "ALIMENTAÇÃO",
        emoji: "🍔",
      };
    if (currentSector === "shop")
      return {
        icon: <Shirt className="text-blue-400" size={28} />,
        title: "LOJA",
        emoji: "👕",
      };
    return {
      icon: <Beer className="text-purple-400" size={28} />,
      title: "BAR",
      emoji: "🍻",
    };
  };

  const sectorInfo = getSectorInfo();

  // Estados de Carrinho e Vendas
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [total, setTotal] = useState(0);
  const [cardToken, setCardToken] = useState("");
  const [processingSale, setProcessingSale] = useState(false);

  // Estados de Relatórios, Gráficos e IA Gemini
  const [_recentSales, setRecentSales] = useState([]);
  const [reportData, setReportData] = useState(null);
  const [loadingInsight, setLoadingInsight] = useState(false);
  const [chatHistory, setChatHistory] = useState([]);
  const [timeFilter, setTimeFilter] = useState("24h");
  const [aiQuestion, setAiQuestion] = useState("");

  // Estados do Formulário de Estoque
  const [showAddForm, setShowAddForm] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [prodForm, setProdForm] = useState({
    id: null,
    name: "",
    price: "",
    stock_qty: "",
    low_stock_threshold: 5,
    sector: fixedSector,
  });

  // Sincronização Offline
  const syncQueue = useCallback(async () => {
    const q = JSON.parse(
      localStorage.getItem(`offline_sales_${currentSector}`) || "[]",
    );
    if (!q.length) return;
    try {
      await api.post("/sync", { records: q });
      toast.success(`${q.length} venda(s) sincronizada(s)!`);
      localStorage.setItem(`offline_sales_${currentSector}`, "[]");
      setOfflineQueue([]);
    } catch {
      toast.error("Falha na sincronização.");
    }
  }, [currentSector]);

  useEffect(() => {
    const handleOnline = () => {
      setIsOffline(false);
      syncQueue();
      syncOfflineData();
    };
    const handleOffline = () => setIsOffline(true);
    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, [syncQueue, syncOfflineData]);

  useEffect(() => {
    api
      .get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => {});
  }, []);

  const loadProducts = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(
        `/${currentSector}/products?event_id=${eventId}`,
      );
      if (res.data?.data) {
        setProducts(
          res.data.data.map((p) => ({
            ...p,
            price: parseFloat(p.price),
            icon:
              p.sector === "food" ? "🍔" : p.sector === "shop" ? "👕" : "🍻",
          })),
        );
      }
    } catch (err) {
      console.error("Erro ao listar catálogo", err);
    } finally {
      setLoading(false);
    }
  }, [currentSector, eventId]);

  const loadRecentSales = useCallback(async () => {
    try {
      const res = await api.get(
        `/${currentSector}/sales?event_id=${eventId}&filter=${timeFilter}`,
      );
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
  }, [currentSector, eventId, timeFilter]);

  useEffect(() => {
    loadProducts();
    loadRecentSales();
  }, [loadProducts, loadRecentSales]);

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
      event_id: eventId,
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

    if (isOffline) {
      const q = JSON.parse(
        localStorage.getItem(`offline_sales_${currentSector}`) || "[]",
      );
      q.push({ offline_id: offlineId, payload: payload });
      localStorage.setItem(`offline_sales_${currentSector}`, JSON.stringify(q));
      setOfflineQueue(q);
      toast.success("Venda salva offline!");
      setCart([]);
      setCardToken("");
    } else {
      try {
        await api.post(`/${currentSector}/checkout`, {
          ...payload,
          offline_id: offlineId,
        });
        toast.success("Venda Realizada!", { icon: "✅" });
        setCart([]);
        setCardToken("");
        loadProducts();
        loadRecentSales();
      } catch (err) {
        if (
          err.message === "Network Error" ||
          (err.response && err.response.status >= 500)
        ) {
          await db.offlineQueue.add({
            offline_id: offlineId,
            payload_type: "sale",
            payload: payload,
            status: "pending",
            created_offline_at: new Date().toISOString(),
            sector: currentSector,
          });
          toast.success("Salvo Offline!", { icon: "💾" });
          setCart([]);
          setCardToken("");
          syncOfflineData();
        } else {
          toast.error(err.response?.data?.message || "Erro na venda.");
        }
      }
    }
    setProcessingSale(false);
  };

  const handleAddProduct = async (e) => {
    e.preventDefault();
    setSavingProduct(true);
    try {
      const payload = {
        ...prodForm,
        price: parseFloat(prodForm.price),
        stock_qty: parseInt(prodForm.stock_qty, 10),
        event_id: eventId,
        sector: currentSector,
      };
      if (prodForm.id) {
        await api.put(`/${currentSector}/products/${prodForm.id}`, payload);
        toast.success("Atualizado!");
      } else {
        await api.post(`/${currentSector}/products`, payload);
        toast.success("Cadastrado!");
      }
      setShowAddForm(false);
      setProdForm({
        id: null,
        name: "",
        price: "",
        stock_qty: "",
        low_stock_threshold: 5,
        sector: currentSector,
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
      sector: p.sector || currentSector,
    });
    setShowAddForm(true);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const handleDeleteProduct = async (id) => {
    if (!window.confirm("Excluir produto?")) return;
    try {
      await api.delete(`/${currentSector}/products/${id}`);
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
      const res = await api.post(
        `/${currentSector}/insights?filter=${timeFilter}`,
        {
          event_id: eventId,
          question: q,
        },
      );
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

  // ─── RENDERIZAÇÃO PRINCIPAL ───
  return (
    <div className="flex flex-col h-screen bg-gray-950 text-white overflow-hidden">
      {/* ─── CABEÇALHO GLOBAL (BOTÃO VOLTAR + STATUS) ─── */}
      <header className="h-16 px-6 border-b border-gray-800 flex items-center justify-between bg-gray-950/50 backdrop-blur-md sticky top-0 z-50">
        <div className="flex items-center gap-4">
          <button
            onClick={() => navigate("/")}
            className="p-2 text-gray-400 hover:text-white hover:bg-gray-800 rounded-full transition-all flex items-center justify-center"
            title="Voltar ao Dashboard"
          >
            <ArrowLeft size={20} />
          </button>

          <div className="flex items-center gap-3">
            <div className="p-2 bg-gray-900 rounded-lg border border-gray-800">
              {sectorInfo.icon}
            </div>
            <div>
              <h1 className="font-bold text-sm tracking-widest text-white uppercase">
                PDV {sectorInfo.title}
              </h1>
              <p className="text-[10px] text-gray-500 font-medium uppercase tracking-tighter">
                EnjoyFun v2.0 • Unidade Digital
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-3">
          {isOffline ? (
            <div className="flex items-center gap-2 px-3 py-1 bg-red-500/10 border border-red-500/20 rounded-full text-red-400 text-[10px] font-bold">
              <WifiOff size={12} /> <span>OFFLINE</span>
            </div>
          ) : (
            <div className="flex items-center gap-2 px-3 py-1 bg-green-500/10 border border-green-500/20 rounded-full text-green-400 text-[10px] font-bold">
              <Wifi size={12} /> <span>ONLINE</span>
            </div>
          )}
        </div>
      </header>

      {/* ─── CONTEÚDO SCROLLABLE DO POS ─── */}
      <main className="flex-1 overflow-y-auto p-6 scrollbar-hide">
        <div className="max-w-7xl mx-auto flex flex-col gap-6">
          {/* BARRA DE CONTROLE LOCAL */}
          <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-6 bg-gray-900/40 p-4 rounded-2xl border border-gray-800/60">
            <div>
              <h1 className="text-2xl font-black text-white flex items-center gap-3 tracking-wide">
                {sectorInfo.icon} POS EnjoyFun{" "}
                <span className="text-gray-500 font-medium">
                  | {sectorInfo.title}
                </span>
              </h1>
            </div>

            <div className="flex flex-col sm:flex-row items-center gap-4">
              <select
                className="w-full sm:w-72 bg-gray-950 border border-gray-700 text-white rounded-xl px-4 py-3 text-sm font-medium focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all outline-none"
                value={eventId}
                onChange={(e) => setEventId(e.target.value)}
              >
                {events.map((ev) => (
                  <option key={ev.id} value={ev.id}>
                    {ev.name}
                  </option>
                ))}
              </select>

              <div className="flex gap-2 bg-gray-950 p-1.5 rounded-xl border border-gray-800 w-full sm:w-auto">
                <button
                  onClick={() => setTab("pos")}
                  className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "pos" ? "bg-purple-600 text-white shadow-lg shadow-purple-900/20" : "text-gray-400 hover:text-white hover:bg-gray-800"}`}
                >
                  <ShoppingCart size={16} /> VENDA
                </button>
                <button
                  onClick={() => setTab("stock")}
                  className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "stock" ? "bg-purple-600 text-white shadow-lg shadow-purple-900/20" : "text-gray-400 hover:text-white hover:bg-gray-800"}`}
                >
                  <Package size={16} /> ESTOQUE
                </button>
                <button
                  onClick={() => setTab("reports")}
                  className={`flex-1 flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold transition-all ${tab === "reports" ? "bg-purple-600 text-white shadow-lg shadow-purple-900/20" : "text-gray-400 hover:text-white hover:bg-gray-800"}`}
                >
                  📊 BI & IA
                </button>
              </div>
            </div>
          </div>

          {/* ABA POS */}
          {tab === "pos" && (
            <div className="flex flex-col lg:flex-row gap-6 h-full min-h-0">
              <div className="flex-[2] overflow-y-auto pr-2 pb-4">
                {loading ? (
                  <div className="flex justify-center py-20 text-purple-500">
                    <span className="animate-spin border-4 border-t-transparent border-purple-500 rounded-full w-10 h-10" />
                  </div>
                ) : (
                  <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                    {products
                      .filter(
                        (p) => (p.sector || currentSector) === currentSector,
                      )
                      .map((product) => (
                        <button
                          key={product.id}
                          onClick={() => addToCart(product)}
                          disabled={product.stock_qty <= 0}
                          className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-3 transition-all active:scale-95 ${product.stock_qty > 0 ? (product.stock_qty <= (product.low_stock_threshold || 5) ? "bg-red-900/20 border-red-500/50 hover:border-red-400" : "hover:border-purple-500 bg-gray-800/40 border-gray-700/50") : "opacity-40 cursor-not-allowed bg-gray-900 border-gray-800"}`}
                        >
                          <div className="text-4xl">
                            {product.icon || sectorInfo.emoji}
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
                )}
              </div>

              <div className="flex-1 bg-gray-900 border border-gray-800 rounded-2xl flex flex-col overflow-hidden min-h-[500px]">
                <div className="px-5 py-4 bg-gray-800/50 border-b border-gray-800 flex items-center justify-between font-bold text-white">
                  Venda Atual{" "}
                  <span className="bg-purple-600 px-2.5 py-1 rounded-full text-xs">
                    {cart.length} itens
                  </span>
                </div>
                <div className="flex-1 overflow-y-auto p-4 space-y-3">
                  {cart.length === 0 ? (
                    <div className="h-full flex flex-col items-center justify-center text-gray-600 gap-3">
                      <ShoppingCart size={48} className="opacity-20" />
                      <p className="text-sm">Carrinho vazio</p>
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
                  <input
                    value={cardToken}
                    onChange={(e) => setCardToken(e.target.value)}
                    placeholder="ESCANEIE O QR CLIENTE"
                    className="w-full bg-gray-900 border border-gray-700 text-white rounded-lg px-4 py-3 mb-4 text-sm outline-none focus:border-purple-500"
                  />
                  <div className="flex justify-between items-center mb-4 text-white">
                    <span className="text-gray-400">Total</span>
                    <span className="text-2xl font-extrabold">
                      R$ {total.toFixed(2)}
                    </span>
                  </div>
                  <button
                    onClick={handleCheckout}
                    disabled={cart.length === 0 || processingSale || !cardToken}
                    className="w-full bg-purple-600 hover:bg-purple-500 py-4 rounded-xl font-bold transition-all disabled:opacity-50"
                  >
                    {processingSale
                      ? "PROCESSANDO..."
                      : "💳 FINALIZAR PAGAMENTO"}
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* ABA ESTOQUE */}
          {tab === "stock" && (
            <div className="space-y-4">
              <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
                <p className="text-gray-400 text-sm">
                  Controle de Inventário: {sectorInfo.title}
                </p>
                <button
                  onClick={() => setShowAddForm(!showAddForm)}
                  className="flex items-center gap-2 bg-purple-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-purple-500"
                >
                  <Plus size={16} /> Adicionar Produto
                </button>
              </div>

              {showAddForm && (
                <div className="bg-gray-900 p-6 rounded-2xl border border-purple-800/40">
                  <h3 className="text-white font-bold mb-4">
                    {prodForm.id ? "Editar" : "Novo"} Produto
                  </h3>
                  <form
                    onSubmit={handleAddProduct}
                    className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4"
                  >
                    <div className="lg:col-span-1">
                      <label className="text-xs text-gray-500 block mb-1">
                        Setor
                      </label>
                      <select
                        className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
                        value={prodForm.sector}
                        disabled
                      >
                        <option value={currentSector}>
                          {sectorInfo.title}
                        </option>
                      </select>
                    </div>
                    <div className="lg:col-span-1">
                      <label className="text-xs text-gray-500 block mb-1">
                        Nome
                      </label>
                      <input
                        className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
                        required
                        value={prodForm.name}
                        onChange={(e) =>
                          setProdForm({ ...prodForm, name: e.target.value })
                        }
                      />
                    </div>
                    <div>
                      <label className="text-xs text-gray-500 block mb-1">
                        Preço (R$)
                      </label>
                      <input
                        className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
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
                      <label className="text-xs text-gray-500 block mb-1">
                        Estoque
                      </label>
                      <input
                        className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
                        type="number"
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
                      <label className="text-xs text-gray-500 block mb-1">
                        Mínimo
                      </label>
                      <input
                        className="w-full bg-gray-950 border border-gray-700 text-white rounded-lg p-2 text-sm"
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
              )}

              <div className="bg-gray-900 border border-gray-800 rounded-2xl p-4">
                {products.length === 0 ? (
                  <p className="text-center py-8 text-gray-500 text-sm">
                    Vazio.
                  </p>
                ) : (
                  products.map((p) => (
                    <div
                      key={p.id}
                      className="flex items-center gap-4 py-3 border-b border-gray-800 last:border-0 hover:bg-gray-800/30 px-2 rounded-xl"
                    >
                      <div className="text-2xl w-8">
                        {p.icon || sectorInfo.emoji}
                      </div>
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
                          <AlertTriangle
                            size={14}
                            className="text-yellow-500"
                          />
                        )}
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-bold ${p.stock_qty <= (p.low_stock_threshold || 5) ? "bg-yellow-500/10 text-yellow-500" : "bg-green-500/10 text-green-500"}`}
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

          {/* ABA BI & IA */}
          {tab === "reports" && (
            <div className="space-y-6">
              <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
                <div className="flex gap-2 bg-gray-800 p-1 rounded-lg">
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
                    className="bg-gray-950 border border-indigo-500/30 text-white rounded-lg px-4 py-2 text-xs w-64 outline-none focus:border-indigo-500"
                    onKeyDown={(e) => e.key === "Enter" && requestInsight()}
                  />
                  <button
                    onClick={requestInsight}
                    disabled={loadingInsight}
                    className="bg-indigo-600 px-4 py-2 rounded-lg text-white text-xs font-bold hover:bg-indigo-500"
                  >
                    {loadingInsight ? "..." : "✨ Analisar"}
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="bg-purple-900/20 border border-purple-800/50 p-6 rounded-2xl">
                  <h3 className="text-gray-400 text-xs font-bold uppercase">
                    Faturamento
                  </h3>
                  <p className="text-3xl font-extrabold text-white mt-2">
                    R$ {parseFloat(reportData?.total_revenue || 0).toFixed(2)}
                  </p>
                </div>
                <div className="bg-indigo-900/20 border border-indigo-800/50 p-6 rounded-2xl">
                  <h3 className="text-gray-400 text-xs font-bold uppercase">
                    Vendidos
                  </h3>
                  <p className="text-3xl font-extrabold text-white mt-2">
                    {reportData?.total_items || 0} un.
                  </p>
                </div>
              </div>

              {chatHistory.length > 0 && (
                <div className="bg-gray-900 border border-indigo-500/30 p-4 rounded-xl flex flex-col gap-3 max-h-96 overflow-y-auto">
                  {chatHistory.map((msg, i) => (
                    <div
                      key={i}
                      className={`p-3 rounded-lg text-sm max-w-[85%] ${msg.role === "user" ? "bg-indigo-600/20 text-indigo-100 self-end ml-auto" : "bg-purple-900/40 text-purple-100 self-start"}`}
                    >
                      <p className="whitespace-pre-wrap">{msg.content}</p>
                    </div>
                  ))}
                </div>
              )}

              <div className="bg-gray-900 border border-gray-800 p-6 rounded-2xl">
                <h3 className="text-white font-bold mb-4">
                  Timeline de Vendas
                </h3>
                {reportData?.sales_chart?.length ? (
                  <div className="h-72">
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
                        <XAxis dataKey="time" stroke="#9ca3af" fontSize={10} />
                        <YAxis
                          stroke="#9ca3af"
                          fontSize={10}
                          tickFormatter={(v) => `R$${v}`}
                        />
                        <RechartsTooltip content={<CustomTooltip />} />
                        <Area
                          type="monotone"
                          dataKey="revenue"
                          stroke="#a855f7"
                          fillOpacity={1}
                          fill="url(#colorRevenue)"
                        />
                      </AreaChart>
                    </ResponsiveContainer>
                  </div>
                ) : (
                  <p className="text-center text-gray-600 text-sm py-20">
                    Sem dados históricos.
                  </p>
                )}
              </div>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
