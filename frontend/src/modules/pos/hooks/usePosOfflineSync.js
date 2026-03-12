import { useCallback, useEffect, useState } from "react";
import toast from "react-hot-toast";
import api from "../../../lib/api";

function hasValidEventId(eventId) {
  return Number(eventId) > 0;
}

export function usePosOfflineSync({ currentSector, syncOfflineData }) {
  const [isOffline, setIsOffline] = useState(!navigator.onLine);
  const [, setOfflineQueue] = useState([]);

  const buildOfflineSaleItem = useCallback(
    (payload, offlineId, createdOfflineAt = null) => {
      const normalizedEventId = Number(payload?.event_id);

      return {
        offline_id: offlineId,
        payload_type: "sale",
        payload: {
          ...payload,
          event_id: hasValidEventId(normalizedEventId) ? normalizedEventId : null,
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
      };
    },
    [currentSector],
  );

  const enqueueOfflineSale = useCallback(
    (payload, offlineId) => {
      if (!hasValidEventId(payload?.event_id)) {
        throw new Error(
          "Selecione um evento valido antes de salvar a venda offline.",
        );
      }

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

    const validQueue = queue.filter((item) =>
      hasValidEventId(item?.payload?.event_id),
    );
    const invalidCount = queue.length - validQueue.length;

    if (invalidCount > 0) {
      toast.error(
        `${invalidCount} venda(s) offline permanecem pendentes sem evento valido.`,
      );
    }
    if (!validQueue.length) return;

    try {
      const { data } = await api.post("/sync", { items: validQueue });
      const processedIds = new Set(
        data?.data?.processed_ids ?? validQueue.map((item) => item.offline_id),
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

        if (failedCount > 0 || invalidCount > 0) {
          toast.error(
          `${processedIds.size} venda(s) sincronizada(s), ${failedCount} pendente(s) por falha e ${invalidCount} sem evento valido.`,
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
