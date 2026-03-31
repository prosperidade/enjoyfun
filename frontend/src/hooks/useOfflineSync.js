/**
 * useOfflineSync — Hook global de Background Sync para scanner e parking.
 *
 * Ao reconectar, lê todos os itens 'pending' prontos para retry na offlineQueue com
 * payload_type em [scanner_process, ticket_validate, guest_validate, participant_validate, parking_entry, parking_exit, parking_validate]
 * e tenta fazer o replay correspondente, respeitando a janela de backoff.
 *
 * Também roda em polling a cada POLL_INTERVAL_MS enquanto online, como fallback
 * para o caso de o evento 'online' não ser capturado (PWA, mobile, etc.).
 */
import { useCallback, useEffect, useRef } from 'react';
import {
  db,
  isOfflineQueueTransientError,
  loadOfflineQueueItems,
  markOfflineQueueItemsFailed,
  scheduleOfflineQueueRetries,
} from '../lib/db';
import api from '../lib/api';
import toast from 'react-hot-toast';

const SYNC_TYPES = ['scanner_process', 'ticket_validate', 'guest_validate', 'participant_validate', 'parking_entry', 'parking_exit', 'parking_validate'];
const SYNC_ENDPOINT_TYPES = ['ticket_validate', 'guest_validate', 'participant_validate', 'parking_entry', 'parking_exit', 'parking_validate'];
const POLL_INTERVAL_MS = 30_000; // 30 segundos

function normalizeSyncQueueItem(item) {
  const payload = item?.payload ?? item?.data ?? {};

  return {
    offline_id: item?.offline_id,
    payload_type: item?.payload_type ?? item?.type,
    payload: {
      ...payload,
      event_id: Number(payload?.event_id ?? 0) || null,
    },
    created_offline_at: item?.created_offline_at ?? item?.created_at ?? new Date().toISOString(),
  };
}

async function dispatchThroughSync(item) {
  const normalizedItem = normalizeSyncQueueItem(item);
  const { data } = await api.post('/sync', { items: [normalizedItem] });

  if (!data?.success) {
    throw new Error('Nao foi possivel concluir o replay offline.');
  }

  const failedIds = Array.isArray(data?.data?.failed_ids) ? data.data.failed_ids : [];
  if (failedIds.includes(item.offline_id)) {
    const errors = Array.isArray(data?.data?.errors) ? data.data.errors : [];
    const matched = errors.find((entry) => entry?.offline_id === item.offline_id);
    throw new Error(matched?.error || 'Replay offline rejeitado pelo backend.');
  }

  return data;
}

/** Mapeia payload_type → chamada de API */
async function dispatchQueueItem(item) {
  const { payload_type, payload } = item;

  if (SYNC_ENDPOINT_TYPES.includes(payload_type)) {
    return dispatchThroughSync(item);
  }

  switch (payload_type) {
    case 'scanner_process':
      if (payload?.mode === 'portaria') {
        try {
          return await api.post('/tickets/validate', {
            dynamic_token: payload.token,
          });
        } catch (error) {
          if (error?.response?.status !== 404) {
            throw error;
          }
        }
      }

      return api.post('/scanner/process', {
        token: payload.token,
        mode: payload.mode,
      });

    default:
      throw new Error(`payload_type desconhecido: ${payload_type}`);
  }
}

export function useOfflineSync() {
  const isSyncingRef = useRef(false);

  const syncPendingItems = useCallback(async () => {
    if (isSyncingRef.current || !navigator.onLine) return;
    isSyncingRef.current = true;

    try {
      const relevant = await loadOfflineQueueItems({
        statuses: ['pending'],
        payloadTypes: SYNC_TYPES,
        readyOnly: true,
      });

      if (relevant.length === 0) return;

      let synced = 0;
      let rescheduled = 0;
      let failed = 0;

      for (const item of relevant) {
        try {
          await dispatchQueueItem(item);

          await db.offlineQueue.update(item.offline_id, {
            status: 'synced',
            synced_at: new Date().toISOString(),
            next_retry_at: null,
            last_error: null,
            last_error_at: null,
          });
          synced += 1;
        } catch (err) {
          const errorPayload = [{
            offline_id: item.offline_id,
            error: err?.response?.data?.message || err?.message || 'Erro desconhecido',
          }];

          if (isOfflineQueueTransientError(err)) {
            const retryState = await scheduleOfflineQueueRetries([item.offline_id], errorPayload);
            rescheduled += Number(retryState?.requeued || 0);
            failed += Number(retryState?.failed || 0);
          } else {
            await markOfflineQueueItemsFailed([item.offline_id], errorPayload);
            failed += 1;
          }
        }
      }

      if (synced > 0) {
        toast.success(`${synced} operação(ões) offline sincronizadas!`, { id: 'offline-sync-ok' });
      }
      if (rescheduled > 0) {
        toast(`${rescheduled} operação(ões) offline aguardando nova tentativa automática.`, {
          id: 'offline-sync-retry',
        });
      }
      if (failed > 0) {
        toast.error(`${failed} operação(ões) offline exigem reconciliacao manual.`, {
          id: 'offline-sync-fail',
        });
      }
    } finally {
      isSyncingRef.current = false;
    }
  }, []);

  // Dispara ao recuperar conexão
  useEffect(() => {
    const handleOnline = () => syncPendingItems();
    window.addEventListener('online', handleOnline);
    return () => window.removeEventListener('online', handleOnline);
  }, [syncPendingItems]);

  // Polling de 30s como fallback (só executa se online)
  useEffect(() => {
    const interval = setInterval(() => {
      if (navigator.onLine) syncPendingItems();
    }, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [syncPendingItems]);

  // Tentativa inicial ao montar (caso o app tenha sido aberto já com internet)
  useEffect(() => {
    syncPendingItems();
  }, [syncPendingItems]);
}
