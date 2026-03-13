import { useCallback, useEffect, useState } from "react";
import { db } from "../../../lib/db";

const LEGACY_QUEUE_PREFIX = "offline_sales_";

function hasValidEventId(eventId) {
  return Number(eventId) > 0;
}

function createOfflineId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `offline-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function listLegacyQueueKeys() {
  if (typeof window === "undefined" || !window.localStorage) {
    return [];
  }

  const keys = [];
  for (let index = 0; index < window.localStorage.length; index += 1) {
    const key = window.localStorage.key(index);
    if (key?.startsWith(LEGACY_QUEUE_PREFIX)) {
      keys.push(key);
    }
  }

  return keys;
}

function readLegacyQueue(key) {
  if (typeof window === "undefined" || !window.localStorage) {
    return [];
  }

  try {
    const parsed = JSON.parse(window.localStorage.getItem(key) || "[]");
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    console.error(`Nao foi possivel ler a fila offline legada ${key}.`, error);
    return null;
  }
}

function removeLegacyQueue(key) {
  if (typeof window === "undefined" || !window.localStorage) {
    return;
  }

  window.localStorage.removeItem(key);
}

export function usePosOfflineSync({ currentSector, syncOfflineData }) {
  const [isOffline, setIsOffline] = useState(!navigator.onLine);

  const buildOfflineSaleItem = useCallback(
    (payload, offlineId, createdOfflineAt = null) => {
      const normalizedEventId = Number(payload?.event_id);
      const normalizedOfflineId = offlineId || createOfflineId();

      return {
        offline_id: normalizedOfflineId,
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

  const migrateLegacyQueues = useCallback(async () => {
    const legacyKeys = listLegacyQueueKeys();
    if (legacyKeys.length === 0) {
      return 0;
    }

    const migratedRecords = [];
    const migratedKeys = [];

    for (const key of legacyKeys) {
      const fallbackSector = key.slice(LEGACY_QUEUE_PREFIX.length) || currentSector;
      const rawQueue = readLegacyQueue(key);

      if (rawQueue === null) {
        continue;
      }

      if (rawQueue.length === 0) {
        removeLegacyQueue(key);
        continue;
      }

      const normalizedQueue = rawQueue
        .map((item) => {
          const rawPayload = item?.payload ?? item?.data ?? {};

          return {
            ...buildOfflineSaleItem(
              {
                ...rawPayload,
                sector: rawPayload?.sector ?? item?.sector ?? fallbackSector,
              },
              item?.offline_id ?? createOfflineId(),
              item?.created_offline_at ?? item?.created_at ?? null,
            ),
            status: "pending",
          };
        })
        .filter(Boolean);

      if (normalizedQueue.length === 0) {
        continue;
      }

      migratedRecords.push(...normalizedQueue);
      migratedKeys.push(key);
    }

    if (migratedRecords.length === 0) {
      return 0;
    }

    await db.transaction("rw", db.offlineQueue, async () => {
      await db.offlineQueue.bulkPut(migratedRecords);
    });

    migratedKeys.forEach(removeLegacyQueue);
    return migratedRecords.length;
  }, [buildOfflineSaleItem, currentSector]);

  const enqueueOfflineSale = useCallback(
    async (payload, offlineId) => {
      if (!hasValidEventId(payload?.event_id)) {
        throw new Error(
          "Selecione um evento valido antes de salvar a venda offline.",
        );
      }

      const record = {
        ...buildOfflineSaleItem(payload, offlineId),
        status: "pending",
      };

      await db.offlineQueue.put(record);
      return record;
    },
    [buildOfflineSaleItem],
  );

  const syncQueue = useCallback(async () => {
    await migrateLegacyQueues();
    await syncOfflineData();
  }, [migrateLegacyQueues, syncOfflineData]);

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

  useEffect(() => {
    let isMounted = true;

    async function bootstrapLegacyQueues() {
      const migratedCount = await migrateLegacyQueues();
      if (isMounted && migratedCount > 0 && navigator.onLine) {
        await syncOfflineData();
      }
    }

    bootstrapLegacyQueues();

    return () => {
      isMounted = false;
    };
  }, [migrateLegacyQueues, syncOfflineData]);

  return {
    enqueueOfflineSale,
    isOffline,
  };
}
