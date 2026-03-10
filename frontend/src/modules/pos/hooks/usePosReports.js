import { useCallback, useEffect, useRef, useState } from "react";
import api from "../../../lib/api";

export function usePosReports({ currentSector, eventId }) {
  const [, setRecentSales] = useState([]);
  const [reportData, setReportData] = useState(null);
  const [loadingInsight, setLoadingInsight] = useState(false);
  const [chatHistory, setChatHistory] = useState([]);
  const [timeFilter, setTimeFilter] = useState("24h");
  const [aiQuestion, setAiQuestion] = useState("");
  const latestSalesRequestRef = useRef(0);

  const loadRecentSales = useCallback(async () => {
    const requestId = latestSalesRequestRef.current + 1;
    latestSalesRequestRef.current = requestId;

    try {
      const res = await api.get(
        `/${currentSector}/sales?event_id=${eventId}&filter=${timeFilter}`,
      );
      if (requestId !== latestSalesRequestRef.current) {
        return;
      }

      if (res.data?.data) {
        if (res.data.data.recent_sales) {
          setRecentSales(res.data.data.recent_sales);
          if (res.data.data.report) {
            setReportData(res.data.data.report);
          }
        } else {
          setRecentSales(res.data.data);
          if (res.data.data.report) {
            setReportData(res.data.data.report);
          }
        }
      }
    } catch (err) {
      console.error("Erro ao carregar vendas", err);
    }
  }, [currentSector, eventId, timeFilter]);

  useEffect(() => {
    loadRecentSales();

    const intervalId = window.setInterval(() => {
      loadRecentSales();
    }, 30000);

    return () => window.clearInterval(intervalId);
  }, [loadRecentSales]);

  const requestInsight = useCallback(async () => {
    if (!aiQuestion.trim()) return;

    const question = aiQuestion;
    setAiQuestion("");
    setChatHistory((prev) => [...prev, { role: "user", content: question }]);
    setLoadingInsight(true);

    try {
      const dataRes = await api.post(
        `/${currentSector}/insights?filter=${timeFilter}`,
        { event_id: eventId, question },
      );

      const ctx = dataRes.data?.data?.context;
      let insight = "";

      if (ctx) {
        const aiRes = await api.post("/ai/insight", {
          context: ctx,
          question,
        });
        insight =
          aiRes.data?.data?.insight || "Nenhum insight retornado pela IA.";
      } else if (dataRes.data?.data?.insight) {
        insight = dataRes.data.data.insight;
      }

      setChatHistory((prev) => [
        ...prev,
        { role: "ai", content: insight || "Sem resposta da IA." },
      ]);
    } catch (err) {
      console.error("Erro na IA:", err);
      const msg = err.response?.data?.message || err.message;

      setChatHistory((prev) => [
        ...prev,
        { role: "ai", content: `Erro na conexão com IA: ${msg}` },
      ]);
    } finally {
      setLoadingInsight(false);
    }
  }, [aiQuestion, currentSector, eventId, timeFilter]);

  return {
    aiQuestion,
    chatHistory,
    loadingInsight,
    loadRecentSales,
    reportData,
    requestInsight,
    setAiQuestion,
    setTimeFilter,
    timeFilter,
  };
}
