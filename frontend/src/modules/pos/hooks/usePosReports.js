import { useCallback, useEffect, useRef, useState } from "react";
import api from "../../../lib/api";

const EMPTY_REPORT = {
  total_revenue: 0,
  total_items: 0,
  sales_chart: [],
  mix_chart: [],
};

function normalizeReportData(report) {
  if (!report || typeof report !== "object") {
    return { ...EMPTY_REPORT };
  }

  return {
    total_revenue: Number(report.total_revenue || 0),
    total_items: Number(report.total_items || 0),
    sales_chart: Array.isArray(report.sales_chart) ? report.sales_chart : [],
    mix_chart: Array.isArray(report.mix_chart) ? report.mix_chart : [],
  };
}

function resolveReportPayload(data) {
  if (Array.isArray(data)) {
    return {
      recentSales: data,
      report: { ...EMPTY_REPORT },
    };
  }

  return {
    recentSales: Array.isArray(data?.recent_sales) ? data.recent_sales : [],
    report: normalizeReportData(data?.report),
  };
}

export function usePosReports({ currentSector, eventId, reportsActive }) {
  const [, setRecentSales] = useState([]);
  const [reportData, setReportData] = useState(null);
  const [loadingReports, setLoadingReports] = useState(false);
  const [reportError, setReportError] = useState("");
  const [reportStale, setReportStale] = useState(false);
  const [lastReportUpdatedAt, setLastReportUpdatedAt] = useState(null);
  const [loadingInsight, setLoadingInsight] = useState(false);
  const [chatHistory, setChatHistory] = useState([]);
  const [timeFilter, setTimeFilter] = useState("24h");
  const [aiQuestion, setAiQuestion] = useState("");
  const latestSalesRequestRef = useRef(0);
  const reportSnapshotRef = useRef(null);

  useEffect(() => {
    reportSnapshotRef.current = reportData;
  }, [reportData]);

  const loadRecentSales = useCallback(async () => {
    const normalizedEventId = Number(eventId);
    if (normalizedEventId <= 0) {
      setRecentSales([]);
      setReportData(null);
      setLoadingReports(false);
      setReportError("");
      setReportStale(false);
      setLastReportUpdatedAt(null);
      return;
    }

    const requestId = latestSalesRequestRef.current + 1;
    latestSalesRequestRef.current = requestId;
    setLoadingReports(true);
    setReportError("");
    setReportStale(reportSnapshotRef.current !== null);

    try {
      const res = await api.get(
        `/${currentSector}/sales?event_id=${normalizedEventId}&filter=${timeFilter}`,
      );
      if (requestId !== latestSalesRequestRef.current) {
        return;
      }

      if (res.data?.data) {
        const nextPayload = resolveReportPayload(res.data.data);
        setRecentSales(nextPayload.recentSales);
        setReportData(nextPayload.report);
        setLastReportUpdatedAt(new Date().toISOString());
      } else {
        setRecentSales([]);
        setReportData({ ...EMPTY_REPORT });
        setLastReportUpdatedAt(new Date().toISOString());
      }

      setLoadingReports(false);
      setReportError("");
      setReportStale(false);
    } catch (err) {
      if (requestId !== latestSalesRequestRef.current) {
        return;
      }

      console.error("Erro ao carregar vendas", err);
      setLoadingReports(false);
      setReportError(
        err.response?.data?.message ||
          "Nao foi possivel atualizar os indicadores agora.",
      );
      setReportStale(reportSnapshotRef.current !== null);
    }
  }, [currentSector, eventId, timeFilter]);

  useEffect(() => {
    if (!reportsActive) {
      return undefined;
    }

    loadRecentSales();

    const normalizedEventId = Number(eventId);
    if (normalizedEventId <= 0) {
      return undefined;
    }

    const intervalId = window.setInterval(() => {
      if (document.visibilityState !== "visible") {
        return;
      }
      loadRecentSales();
    }, 45000);

    return () => window.clearInterval(intervalId);
  }, [eventId, loadRecentSales, reportsActive]);

  const requestInsight = useCallback(async () => {
    if (!aiQuestion.trim()) return;
    if (Number(eventId) <= 0) {
      setChatHistory((prev) => [
        ...prev,
        {
          role: "ai",
          content: "Selecione um evento valido antes de consultar insights.",
        },
      ]);
      return;
    }

    const question = aiQuestion;
    setAiQuestion("");
    setChatHistory((prev) => [...prev, { role: "user", content: question }]);
    setLoadingInsight(true);

    try {
      const dataRes = await api.post(
        `/${currentSector}/insights?filter=${timeFilter}`,
        { event_id: Number(eventId), question },
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
  };
}
