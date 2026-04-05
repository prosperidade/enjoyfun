import { useCallback, useEffect, useState } from "react";
import { createOfflineQueueRecord, db } from "../../../lib/db";
import { signPayload } from "../../../lib/hmac";
import { getAccessToken } from "../../../lib/session";

function hasValidEventId(eventId) {
  return Number(eventId) > 0;
}

function isCanonicalCardId(value) {
  return /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
    String(value || "").trim(),
  );
}

function createOfflineId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `offline-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function usePosOfflineSync({ currentSector, syncOfflineData }) {
  const [isOffline, setIsOffline] = useState(!navigator.onLine);

  const buildOfflineSaleItem = useCallback(
    (payload, offlineId, createdOfflineAt = null) => {
      const normalizedEventId = Number(payload?.event_id);
      const normalizedOfflineId = offlineId || createOfflineId();
      const cardReference =
        payload.card_id ??
        payload.qr_token ??
        payload.card_token ??
        payload.customer_id ??
        null;
      const normalizedCardId = String(cardReference || "").trim();

      if (!isCanonicalCardId(normalizedCardId)) {
        throw new Error(
          "Venda offline exige card_id canônico resolvido antes de entrar na fila local.",
        );
      }

      return {
        offline_id: normalizedOfflineId,
        payload_type: "sale",
        payload: {
          ...payload,
          event_id: hasValidEventId(normalizedEventId) ? normalizedEventId : null,
          sector: payload.sector ?? currentSector,
          card_id: normalizedCardId || null,
          card_reference_kind: isCanonicalCardId(normalizedCardId)
            ? "card_id"
            : "legacy_reference",
        },
        created_offline_at: createdOfflineAt ?? new Date().toISOString(),
        sector: payload.sector ?? currentSector,
      };
    },
    [currentSector],
  );

  const enqueueOfflineSale = useCallback(
    async (payload, offlineId) => {
      if (!hasValidEventId(payload?.event_id)) {
        throw new Error(
          "Selecione um evento valido antes de salvar a venda offline.",
        );
      }

      const item = buildOfflineSaleItem(payload, offlineId);

      // HMAC-SHA256 signing (C07) — integrity proof for offline payloads
      let hmac = null;
      try {
        const token = getAccessToken();
        if (token) {
          hmac = await signPayload(item.payload, token);
        }
      } catch {
        // HMAC signing is best-effort; the sale must still be queued even if
        // Web Crypto is unavailable (e.g. non-secure context in dev).
      }

      const record = createOfflineQueueRecord({
        ...item,
        hmac: hmac || null,
        status: "pending",
      });

      await db.offlineQueue.put(record);
      return record;
    },
    [buildOfflineSaleItem],
  );

  const syncQueue = useCallback(async () => {
    await syncOfflineData();
  }, [syncOfflineData]);

  useEffect(() => {
    const handleOnline = () => {
      setIsOffline(false);
      void syncQueue();
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
    if (!navigator.onLine) {
      return;
    }

    void syncOfflineData();
  }, [syncOfflineData]);

  return {
    enqueueOfflineSale,
    isOffline,
  };
}
