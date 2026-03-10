import { useState, useEffect } from "react";
import { v4 as uuidv4 } from "uuid";
import { useNavigate } from "react-router-dom";
import { db } from "../lib/db";
import { useNetwork } from "../hooks/useNetwork";
import toast from "react-hot-toast";
import api from "../lib/api";
import CartPanel from "../modules/pos/components/CartPanel";
import CheckoutPanel from "../modules/pos/components/CheckoutPanel";
import PosHeader from "../modules/pos/components/PosHeader";
import PosToolbar from "../modules/pos/components/PosToolbar";
import ProductGrid from "../modules/pos/components/ProductGrid";
import InsightChat from "../modules/pos/components/InsightChat";
import ProductMixChart from "../modules/pos/components/ProductMixChart";
import ReportsPanel from "../modules/pos/components/ReportsPanel";
import ReportsControls from "../modules/pos/components/ReportsControls";
import ReportSummaryCards from "../modules/pos/components/ReportSummaryCards";
import SalesTimelineChart from "../modules/pos/components/SalesTimelineChart";
import StockForm from "../modules/pos/components/StockForm";
import StockList from "../modules/pos/components/StockList";
import StockPanel from "../modules/pos/components/StockPanel";
import { usePosCart } from "../modules/pos/hooks/usePosCart";
import { usePosCatalog } from "../modules/pos/hooks/usePosCatalog";
import { usePosOfflineSync } from "../modules/pos/hooks/usePosOfflineSync";
import { usePosReports } from "../modules/pos/hooks/usePosReports";
import { createProductForm } from "../modules/pos/utils/createProductForm";
import { getSectorInfo } from "../modules/pos/utils/getSectorInfo";

export default function POS({ fixedSector = "bar" }) {
  const navigate = useNavigate();
  const { syncOfflineData } = useNetwork();
  const [tab, setTab] = useState("pos");
  const currentSector = fixedSector;
  const sectorInfo = getSectorInfo(currentSector);
  const [cardToken, setCardToken] = useState("");
  const [processingSale, setProcessingSale] = useState(false);
  const [showAddForm, setShowAddForm] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [prodForm, setProdForm] = useState(() => createProductForm(fixedSector));

  const {
    events,
    eventId,
    loading,
    loadProducts,
    products,
    setEventId,
  } = usePosCatalog({
    currentSector,
  });

  const {
    addToCart,
    cart,
    clearCart,
    removeFromCart,
    total,
    updateQuantity,
  } = usePosCart();

  const {
    isOffline,
    buildOfflineSaleItem,
    enqueueOfflineSale,
  } = usePosOfflineSync({
    currentSector,
    syncOfflineData,
  });

  const {
    aiQuestion,
    chatHistory,
    loadingInsight,
    loadRecentSales,
    reportData,
    requestInsight,
    setAiQuestion,
    setTimeFilter,
    timeFilter,
  } = usePosReports({
    currentSector,
    eventId,
  });

  useEffect(() => {
    clearCart();
  }, [clearCart, fixedSector]);

  const handleCheckout = async () => {
    if (cart.length === 0 || !cardToken) return;
    setProcessingSale(true);
    const offlineId = uuidv4();
    const payload = {
      event_id: eventId,
      total_amount: total,
      card_id: cardToken,
      sector: currentSector,
      items: cart.map((item) => ({
        product_id: item.id,
        name: item.name,
        quantity: item.quantity,
        unit_price: item.price,
        subtotal: item.quantity * item.price,
      })),
    };

    if (isOffline) {
      enqueueOfflineSale(payload, offlineId);
      toast.success("Venda salva offline!");
      clearCart();
      setCardToken("");
    } else {
      try {
        await api.post(`/${currentSector}/checkout`, {
          ...payload,
          offline_id: offlineId,
        });
        toast.success("Venda Realizada!", { icon: "✅" });
        clearCart();
        setCardToken("");
        loadProducts();
        loadRecentSales();
      } catch (err) {
        if (
          err.message === "Network Error" ||
          (err.response && err.response.status >= 500)
        ) {
          await db.offlineQueue.add({
            ...buildOfflineSaleItem(payload, offlineId),
            status: "pending",
          });
          toast.success("Salvo Offline!", { icon: "💾" });
          clearCart();
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
      setProdForm(createProductForm(currentSector));
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

  // ─── RENDERIZAÇÃO PRINCIPAL ───
  return (
    <div className="flex flex-col h-screen bg-gray-950 text-white overflow-hidden">
      <PosHeader
        isOffline={isOffline}
        navigate={navigate}
        sectorInfo={sectorInfo}
      />

      {/* ─── CONTEÚDO SCROLLABLE DO POS ─── */}
      <main className="flex-1 overflow-y-auto p-6 scrollbar-hide">
        <div className="max-w-7xl mx-auto flex flex-col gap-6">
          <PosToolbar
            eventId={eventId}
            events={events}
            sectorInfo={sectorInfo}
            setEventId={setEventId}
            setTab={setTab}
            tab={tab}
          />

          {/* ABA POS */}
          {tab === "pos" && (
            <div className="flex flex-col lg:flex-row gap-6 h-full min-h-0">
              <ProductGrid
                currentSector={currentSector}
                fallbackIcon={sectorInfo.fallbackIcon}
                loading={loading}
                onAddToCart={addToCart}
                products={products}
              />

              <CartPanel
                cart={cart}
                onRemoveFromCart={removeFromCart}
                onUpdateQuantity={updateQuantity}
              >
                <CheckoutPanel
                  cardToken={cardToken}
                  canCheckout={cart.length > 0 && Boolean(cardToken)}
                  isOffline={isOffline}
                  onCardTokenChange={setCardToken}
                  onCheckout={handleCheckout}
                  processingSale={processingSale}
                  total={total}
                />
              </CartPanel>
            </div>
          )}

          {/* ABA ESTOQUE */}
          {tab === "stock" && (
            <StockPanel
              onToggleForm={() => setShowAddForm(!showAddForm)}
              sectorTitle={sectorInfo.title}
            >
              <StockForm
                currentSector={currentSector}
                onCancel={() => setShowAddForm(false)}
                onSubmit={handleAddProduct}
                prodForm={prodForm}
                savingProduct={savingProduct}
                sectorTitle={sectorInfo.title}
                setProdForm={setProdForm}
                showAddForm={showAddForm}
              />

              <StockList
                fallbackIcon={sectorInfo.fallbackIcon}
                onDelete={handleDeleteProduct}
                onEdit={handleEditClick}
                products={products}
              />
            </StockPanel>
          )}

          {/* ABA BI & IA */}
          {tab === "reports" && (
            <ReportsPanel>
              <ReportsControls
                aiQuestion={aiQuestion}
                loadingInsight={loadingInsight}
                onAiQuestionChange={setAiQuestion}
                onInsightComposerKeyDown={(e) =>
                  e.key === "Enter" && requestInsight()
                }
                onRequestInsight={requestInsight}
                onTimeFilterChange={setTimeFilter}
                sectorTitle={sectorInfo.title}
                timeFilter={timeFilter}
              />

              <ReportSummaryCards reportData={reportData} />

              <InsightChat chatHistory={chatHistory} />

              <div className="flex flex-col gap-8 w-full">
                <SalesTimelineChart reportData={reportData} />
                <ProductMixChart reportData={reportData} />
              </div>
            </ReportsPanel>
          )}
        </div>
      </main>
    </div>
  );
}
