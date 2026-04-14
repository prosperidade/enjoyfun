import { useState, useEffect, useRef } from "react";
import { v4 as uuidv4 } from "uuid";
import { useNavigate } from "react-router-dom";
import { useNetwork } from "../hooks/useNetwork";
import toast from "react-hot-toast";
import api from "../lib/api";
import CartPanel from "../modules/pos/components/CartPanel";
import CheckoutPanel from "../modules/pos/components/CheckoutPanel";
import PosHeader from "../modules/pos/components/PosHeader";
import PosToolbar from "../modules/pos/components/PosToolbar";
import ProductGrid from "../modules/pos/components/ProductGrid";
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
import EmbeddedAIChat from "../components/EmbeddedAIChat";

function isCanonicalCardId(value) {
  return /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
    String(value || "").trim(),
  );
}

export default function POS({ fixedSector = "bar" }) {
  const navigate = useNavigate();
  const { syncOfflineData } = useNetwork();
  const [tab, setTab] = useState("pos");
  const currentSector = fixedSector;
  const sectorInfo = getSectorInfo(currentSector);
  const [cardReference, setCardReference] = useState("");
  const [resolvedCardId, setResolvedCardId] = useState("");
  const [resolvedCardMeta, setResolvedCardMeta] = useState(null);
  const [cardResolveError, setCardResolveError] = useState("");
  const [resolvingCard, setResolvingCard] = useState(false);
  const [processingSale, setProcessingSale] = useState(false);
  const [showAddForm, setShowAddForm] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [prodForm, setProdForm] = useState(() => createProductForm(fixedSector));
  const [hasOpenedReports, setHasOpenedReports] = useState(false);
  const cardResolveTimerRef = useRef(null);

  const {
    catalogError,
    events,
    eventId,
    eventsError,
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
    enqueueOfflineSale,
  } = usePosOfflineSync({
    currentSector,
    syncOfflineData,
  });

  const {
    aiQuestion,
    chatHistory,
    lastReportUpdatedAt,
    loadingInsight,
    loadingReports,
    loadRecentSales,
    reportData,
    reportError,
    reportStale,
    requestInsight,
    setAiQuestion,
    setTimeFilter,
    timeFilter,
  } = usePosReports({
    currentSector,
    eventId,
    reportsActive: tab === "reports",
  });

  const hasValidEventContext = Number(eventId) > 0;
  const trimmedCardReference = cardReference.trim();
  const hasScopedResolvedCard =
    Boolean(resolvedCardId) &&
    resolvedCardMeta?.reference === trimmedCardReference &&
    Number(resolvedCardMeta?.event_id || 0) === Number(eventId || 0) &&
    isCanonicalCardId(resolvedCardId);

  useEffect(() => {
    clearCart();
    setCardReference("");
    setResolvedCardId("");
    setResolvedCardMeta(null);
    setCardResolveError("");
  }, [clearCart, fixedSector]);

  useEffect(() => {
    setCardReference("");
    setResolvedCardId("");
    setResolvedCardMeta(null);
    setCardResolveError("");
  }, [eventId]);

  useEffect(() => {
    if (tab === "reports") {
      setHasOpenedReports(true);
    }
  }, [tab]);

  const handleCardReferenceChange = (value) => {
    setCardReference(value);
    setCardResolveError("");

    if (cardResolveTimerRef.current) {
      clearTimeout(cardResolveTimerRef.current);
      cardResolveTimerRef.current = null;
    }

    const trimmedValue = value.trim();

    if (trimmedValue === "") {
      setResolvedCardId("");
      setResolvedCardMeta(null);
      return;
    }

    cardResolveTimerRef.current = setTimeout(() => {
      if (
        resolvedCardMeta?.reference === trimmedValue &&
        Number(resolvedCardMeta?.event_id || 0) === Number(eventId || 0) &&
        isCanonicalCardId(String(resolvedCardMeta?.card_id || resolvedCardId || ""))
      ) {
        setResolvedCardId(String(resolvedCardMeta.card_id || resolvedCardId || ""));
        return;
      }

      setResolvedCardId("");
      setResolvedCardMeta(null);
    }, 300);
  };

  const resolveCheckoutCardId = async () => {
    const scopedEventId = Number(eventId || 0);
    if (trimmedCardReference === "") {
      throw new Error("Escaneie o cartão do cliente antes de finalizar.");
    }
    if (scopedEventId <= 0) {
      throw new Error("Selecione um evento válido antes de validar o cartão.");
    }

    if (hasScopedResolvedCard) {
      return resolvedCardId;
    }

    if (isOffline) {
      throw new Error(
        "Offline exige um cartão validado online para este evento antes da venda.",
      );
    }

    setResolvingCard(true);
    setCardResolveError("");

    try {
      const { data } = await api.post("/cards/resolve", {
        reference: trimmedCardReference,
        event_id: scopedEventId,
      });
      const card = data?.data || {};
      const nextCardId = String(card.card_id || "").trim();

      if (!isCanonicalCardId(nextCardId)) {
        throw new Error("A resolução do cartão não retornou um card_id válido.");
      }

      setResolvedCardId(nextCardId);
      setResolvedCardMeta({
        ...card,
        reference: trimmedCardReference,
        event_id: scopedEventId,
      });
      return nextCardId;
    } catch (err) {
      const message =
        err.response?.data?.message ||
        err.message ||
        "Nao foi possivel resolver o cartão.";
      setResolvedCardId("");
      setResolvedCardMeta(null);
      setCardResolveError(message);
      throw new Error(message);
    } finally {
      setResolvingCard(false);
    }
  };

  let cardHint = null;
  let cardHintTone = "muted";
  if (trimmedCardReference === "") {
    cardHint = null;
  } else if (cardResolveError) {
    cardHint = cardResolveError;
    cardHintTone = "danger";
  } else if (!hasValidEventContext) {
    cardHint = "Selecione um evento antes de validar o cartão.";
    cardHintTone = "warning";
  } else if (resolvingCard) {
    cardHint = "Validando o cartão para obter o card_id canônico...";
  } else if (hasScopedResolvedCard) {
    cardHint = `Cartão pronto para cobrança: ${resolvedCardId.slice(0, 8)}...`;
    cardHintTone = "success";
  } else if (isOffline) {
    cardHint = "Offline exige o cartão já validado online para este evento.";
    cardHintTone = "warning";
  } else if (isCanonicalCardId(trimmedCardReference)) {
    cardHint = "card_id canônico detectado; a validação do evento ocorrerá no checkout.";
  } else {
    cardHint = "A referência escaneada será convertida para card_id no checkout.";
  }

  const handleCheckout = async () => {
    if (!hasValidEventContext) {
      toast.error(
        "Selecione um evento valido antes de finalizar ou salvar a venda offline.",
      );
      return;
    }
    if (cart.length === 0 || !trimmedCardReference) return;

    let canonicalCardId = "";
    try {
      canonicalCardId = await resolveCheckoutCardId();
    } catch (err) {
      toast.error(err.message || "Nao foi possivel validar o cartão.");
      return;
    }

    setProcessingSale(true);
    const offlineId = uuidv4();
    const payload = {
      event_id: Number(eventId),
      total_amount: total,
      card_id: canonicalCardId,
      sector: currentSector,
      items: cart.map((item) => ({
        product_id: item.id,
        name: item.name,
        quantity: item.quantity,
        unit_price: item.price,
        subtotal: item.quantity * item.price,
      })),
    };

    try {
      if (isOffline) {
        await enqueueOfflineSale(payload, offlineId);
        toast.success("Venda salva offline!");
        clearCart();
        setCardReference("");
        setResolvedCardId("");
        setResolvedCardMeta(null);
        return;
      }

      try {
        await api.post(`/${currentSector}/checkout`, {
          ...payload,
          offline_id: offlineId,
        });
        toast.success("Venda Realizada!", { icon: "✅" });
        clearCart();
        setCardReference("");
        setResolvedCardId("");
        setResolvedCardMeta(null);
        loadProducts();
        loadRecentSales();
      } catch (err) {
        if (
          err.message === "Network Error" ||
          (err.response && err.response.status >= 500)
        ) {
          await enqueueOfflineSale(payload, offlineId);
          toast.success("Salvo Offline!", { icon: "💾" });
          clearCart();
          setCardReference("");
          setResolvedCardId("");
          setResolvedCardMeta(null);
          syncOfflineData();
        } else {
          toast.error(err.response?.data?.message || "Erro na venda.");
        }
      }
    } catch (offlineErr) {
      if (offlineErr?.code === 'HMAC_KEY_MISSING') {
        toast.error("Sessao expirada. Faca login novamente.");
        window.location.href = '/login';
        return;
      }
      toast.error("Nao foi possivel salvar a venda offline localmente.");
    } finally {
      setProcessingSale(false);
    }
  };

  const handleAddProduct = async (e) => {
    e.preventDefault();
    if (!hasValidEventContext) {
      toast.error("Selecione um evento valido antes de salvar o produto.");
      return;
    }
    setSavingProduct(true);
    try {
      const payload = {
        ...prodForm,
        price: parseFloat(prodForm.price),
        stock_qty: parseInt(prodForm.stock_qty, 10),
        event_id: Number(eventId),
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
      cost_price: p.cost_price || "",
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
            eventsError={eventsError}
            sectorInfo={sectorInfo}
            setEventId={setEventId}
            setTab={setTab}
            tab={tab}
          />

          <EmbeddedAIChat
            surface="bar"
            title={`Assistente ${sectorInfo.title}`}
            description={`PDV ${sectorInfo.title} — ${products.length} produtos cadastrados`}
            accentColor="purple"
            suggestions={[
              'Qual produto mais vendido hoje?',
              'Tem algum item com estoque critico?',
              'Resumo de vendas do turno atual',
            ]}
          />

          {/* ABA POS */}
          {tab === "pos" && (
            <div className="flex flex-col lg:flex-row gap-6 h-full min-h-0">
              <ProductGrid
                catalogError={catalogError}
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
                  cardHint={cardHint}
                  cardHintTone={cardHintTone}
                  cardReference={cardReference}
                  canCheckout={
                    cart.length > 0 &&
                    Boolean(trimmedCardReference) &&
                    hasValidEventContext &&
                    !resolvingCard &&
                    (!isOffline || hasScopedResolvedCard)
                  }
                  checkoutHint={
                    !hasValidEventContext
                      ? "Selecione um evento valido para habilitar o checkout."
                      : isOffline && !hasScopedResolvedCard
                        ? "Offline exige o cartão validado online para o evento atual."
                        : null
                  }
                  isOffline={isOffline}
                  onCardReferenceChange={handleCardReferenceChange}
                  onCheckout={handleCheckout}
                  processingSale={processingSale}
                  resolvingCard={resolvingCard}
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
          {hasOpenedReports && (
            <ReportsPanel isActive={tab === "reports"}>
              <ReportsControls
                aiQuestion={aiQuestion}
                hasValidEventContext={hasValidEventContext}
                lastReportUpdatedAt={lastReportUpdatedAt}
                loadingInsight={loadingInsight}
                loadingReports={loadingReports}
                onAiQuestionChange={setAiQuestion}
                onInsightComposerKeyDown={(e) =>
                  e.key === "Enter" && requestInsight()
                }
                onRequestInsight={requestInsight}
                onTimeFilterChange={setTimeFilter}
                reportError={reportError}
                reportStale={reportStale}
                sectorTitle={sectorInfo.title}
                timeFilter={timeFilter}
              />

              <ReportSummaryCards
                loadingReports={loadingReports}
                reportData={reportData}
                reportError={reportError}
              />

              <div className="flex flex-col gap-8 w-full">
                <SalesTimelineChart
                  loadingReports={loadingReports}
                  reportData={reportData}
                  reportError={reportError}
                />
                <ProductMixChart
                  loadingReports={loadingReports}
                  reportData={reportData}
                  reportError={reportError}
                />
              </div>
            </ReportsPanel>
          )}

        </div>
      </main>
    </div>
  );
}
