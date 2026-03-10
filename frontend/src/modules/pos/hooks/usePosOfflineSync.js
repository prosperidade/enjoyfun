import { useCallback, useEffect, useState } from "react";
import toast from "react-hot-toast";
import api from "../../../lib/api";

export function usePosOfflineSync({ currentSector, syncOfflineData }) {
  const [isOffline, setIsOffline] = useState(!navigator.onLine);
  const [, setOfflineQueue] = useState([]);

  const buildOfflineSaleItem = useCallback(
    (payload, offlineId, createdOfflineAt = null) => ({
      offline_id: offlineId,
      payload_type: "sale",
      payload: {
        ...payload,
        sector: payload.sector ?? currentSector,
        card_id:
          payload.card_id ??
          payload.qr_token ??
          payload.card_token ??
          payload.customer_id ??
          null,
      },
      created_offline_at: createdOfflineAt ?? new Date().toISOString(),
      sector: payload.sector ?? currentSector,
    }),
    [currentSector],
  );

  const enqueueOfflineSale = useCallback(
    (payload, offlineId) => {
      const queue = JSON.parse(
        localStorage.getItem(`offline_sales_${currentSector}`) || "[]",
      );
      const nextQueue = [...queue, buildOfflineSaleItem(payload, offlineId)];

      localStorage.setItem(
        `offline_sales_${currentSector}`,
        JSON.stringify(nextQueue),
      );
      setOfflineQueue(nextQueue);

      return nextQueue;
    },
    [buildOfflineSaleItem, currentSector],
  );

  const syncQueue = useCallback(async () => {
    const rawQueue = JSON.parse(
      localStorage.getItem(`offline_sales_${currentSector}`) || "[]",
    );
    if (!rawQueue.length) return;

    const queue = rawQueue
      .map((item) => {
        if (!item?.offline_id) return null;

        return buildOfflineSaleItem(
          item.payload ?? item.data ?? {},
          item.offline_id,
          item.created_offline_at ?? item.created_at ?? null,
        );
      })
      .filter(Boolean);

    if (!queue.length) return;

    try {
      const { data } = await api.post("/sync", { items: queue });
      const processedIds = new Set(
        data?.data?.processed_ids ?? queue.map((item) => item.offline_id),
      );
      const failedCount = Number(data?.data?.failed ?? 0);

      const remaining = rawQueue.filter(
        (item) => !processedIds.has(item?.offline_id),
      );
      localStorage.setItem(
        `offline_sales_${currentSector}`,
        JSON.stringify(remaining),
      );
      setOfflineQueue(remaining);

      if (failedCount > 0) {
        toast.error(
          `${processedIds.size} venda(s) sincronizada(s) e ${failedCount} pendente(s).`,
        );
      } else {
        toast.success(`${processedIds.size} venda(s) sincronizada(s)!`);
      }
    } catch {
      toast.error("Falha na sincronização.");
    }
  }, [buildOfflineSaleItem, currentSector]);

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
  }, [syncOfflineData, syncQueue]);

  return {
    buildOfflineSaleItem,
    enqueueOfflineSale,
    isOffline,
  };
}
