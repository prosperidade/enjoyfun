import { useState, useEffect, useCallback, useRef } from 'react';
import {
  db,
  isOfflineQueueTransientError,
  loadOfflineQueueItems,
  markOfflineQueueItemsFailed,
  scheduleOfflineQueueRetries,
} from '../lib/db';
import api from '../lib/api';
import toast from 'react-hot-toast';

const NETWORK_SYNC_TYPES = ['sale', 'meal', 'topup'];

/** Payment methods allowed for offline topup — mirrors backend OfflineSyncNormalizer. */
const OFFLINE_TOPUP_ALLOWED_METHODS = ['cash', 'manual', 'dinheiro', 'especie'];

function hasValidEventId(eventId) {
  return Number(eventId) > 0;
}

/**
 * S1-03: Check if an offline topup record has a valid (cash-only) payment method.
 * Returns null if valid, or an error string if the record must be rejected locally.
 */
function validateOfflineTopupMethod(payload) {
  const method = (payload?.payment_method ?? 'manual').toString().toLowerCase().trim() || 'manual';
  if (OFFLINE_TOPUP_ALLOWED_METHODS.includes(method)) {
    return null;
  }
  return (
    `Metodo de pagamento "${method}" nao permitido para recarga offline. ` +
    'Use a recarga online para pagamentos digitais (pix, cartao, etc). ' +
    'Este registro foi movido para reconciliacao manual.'
  );
}

function normalizePendingSyncRecord(record) {
  const payload = record?.payload ?? record?.data ?? {};
  const payloadType = record?.payload_type ?? record?.type ?? 'sale';
  const cardId =
    payload.card_id ??
    payload.qr_token ??
    payload.card_token ??
    payload.customer_id ??
    null;

  // S1-03: Block offline topup with digital payment methods before sending to backend
  if (payloadType === 'topup') {
    const topupError = validateOfflineTopupMethod(payload);
    if (topupError) {
      return {
        offline_id: record?.offline_id,
        payload_type: payloadType,
        payload,
        created_offline_at: record?.created_offline_at ?? record?.created_at ?? new Date().toISOString(),
        _localFailure: true,
        _localFailureError: topupError,
      };
    }
  }

  return {
    offline_id: record?.offline_id,
    payload_type: payloadType,
    payload: {
      ...payload,
      event_id: hasValidEventId(payload?.event_id) ? Number(payload.event_id) : null,
      sector: payload?.sector ?? record?.sector ?? (payloadType === 'sale' ? 'bar' : null),
      ...(payloadType === 'sale' || payloadType === 'topup' ? { card_id: cardId } : {}),
    },
    created_offline_at: record?.created_offline_at ?? record?.created_at ?? new Date().toISOString(),
  };
}

export function useNetwork() {
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [isSyncing, setIsSyncing] = useState(false);
  const isSyncingRef = useRef(false);
  const didInitialSyncRef = useRef(false);

  const syncOfflineData = useCallback(async () => {
    if (isSyncingRef.current || !navigator.onLine) return;

    isSyncingRef.current = true;
    setIsSyncing(true);
    let retryableRecords = [];
    
    try {
      const pending = await loadOfflineQueueItems({
        statuses: ['pending'],
        payloadTypes: NETWORK_SYNC_TYPES,
        readyOnly: true,
      });

      if (pending.length === 0) {
        toast.dismiss('sync');
        return;
      }

      toast.loading(`Sincronizando ${pending.length} registros offline...`, { id: 'sync' });

      const normalizedPending = pending.map(normalizePendingSyncRecord);

      // S1-03: Separate records rejected locally (e.g. offline topup with digital payment)
      const localFailures = normalizedPending.filter((r) => r._localFailure);
      const validRecords = normalizedPending.filter((r) => !r._localFailure);

      if (localFailures.length > 0) {
        await markOfflineQueueItemsFailed(
          localFailures.map((r) => r.offline_id),
          localFailures.map((r) => ({
            offline_id: r.offline_id,
            error: r._localFailureError || 'Registro rejeitado localmente antes do envio ao backend.',
          })),
        );
        toast.error(
          `${localFailures.length} recarga(s) offline com metodo digital rejeitada(s). Use a recarga online.`,
          { id: 'sync-topup-blocked' },
        );
      }

      const invalidPending = validRecords.filter(
        (record) => !hasValidEventId(record?.payload?.event_id),
      );
      const payloadOut = validRecords.filter((record) =>
        hasValidEventId(record?.payload?.event_id),
      );
      retryableRecords = payloadOut;

      if (payloadOut.length === 0) {
        await markOfflineQueueItemsFailed(
          invalidPending.map((record) => record.offline_id),
          invalidPending.map((record) => ({
            offline_id: record.offline_id,
            error: 'Registro offline sem event_id valido. Corrija o payload antes de reenfileirar.',
          })),
        );
        toast.error(
          'Existem registros offline pendentes sem evento valido. A sincronizacao foi bloqueada.',
          { id: 'sync' },
        );
        return;
      }

      if (invalidPending.length > 0) {
        await markOfflineQueueItemsFailed(
          invalidPending.map((record) => record.offline_id),
          invalidPending.map((record) => ({
            offline_id: record.offline_id,
            error: 'Registro offline sem event_id valido. Corrija o payload antes de reenfileirar.',
          })),
        );
      }

      const { data } = await api.post('/sync', { items: payloadOut });

      if (data.success) {
        const syncStatus = data?.data?.status ?? 'success';
        const processedIds = data?.data?.processed_ids ?? payloadOut.map(p => p.offline_id);
        const failedCount = Number(data?.data?.failed ?? 0);

        if (processedIds.length > 0) {
          await db.offlineQueue.bulkDelete(processedIds);
        }
        
        const failedIds = data?.data?.failed_ids ?? [];
        if (failedIds.length > 0) {
          await markOfflineQueueItemsFailed(failedIds, data?.data?.errors ?? []);
        }

        if (syncStatus === 'partial_failure' || failedCount > 0 || invalidPending.length > 0) {
          toast.error(
            `${processedIds.length} registros sincronizados, ${failedCount} mantidos como falha local e ${invalidPending.length} bloqueados por evento invalido.`,
            { id: 'sync' },
          );
        } else {
          toast.success(`${processedIds.length} registros sincronizados!`, { id: 'sync' });
        }
      } else {
        await markOfflineQueueItemsFailed(
          payloadOut.map((record) => record.offline_id),
          payloadOut.map((record) => ({
            offline_id: record.offline_id,
            error: 'O backend recusou a sincronizacao offline sem detalhar o motivo.',
          })),
        );
        toast.error('Nao foi possivel concluir a sincronizacao offline.', { id: 'sync' });
      }
    } catch (err) {
      console.error('Offline Sync error:', err);
      const errorEntries = retryableRecords.map((record) => ({
        offline_id: record.offline_id,
        error: err?.response?.data?.message || err?.message || 'Erro desconhecido na sincronizacao offline.',
      }));

      if (isOfflineQueueTransientError(err)) {
        const retryState = await scheduleOfflineQueueRetries(
          retryableRecords.map((record) => record.offline_id),
          errorEntries,
        );
        const terminalCount = Number(retryState?.failed || 0);
        const rescheduledCount = Number(retryState?.requeued || 0);

        if (terminalCount > 0) {
          toast.error(
            `${terminalCount} registro(s) atingiram o limite de retry e foram movidos para reconciliacao manual.`,
            { id: 'sync' },
          );
        } else if (rescheduledCount > 0) {
          toast(
            `${rescheduledCount} registro(s) offline reagendados com backoff automatico.`,
            { id: 'sync' },
          );
        } else {
          toast.error('Ocorreu um erro ao sincronizar em background.', { id: 'sync' });
        }
      } else {
        await markOfflineQueueItemsFailed(
          retryableRecords.map((record) => record.offline_id),
          errorEntries,
        );
        toast.error('Ocorreu um erro ao sincronizar em background.', { id: 'sync' });
      }
    } finally {
      isSyncingRef.current = false;
      setIsSyncing(false);
    }
  }, []);

  useEffect(() => {
    const handleOnline = () => {
      setIsOnline(true);
      syncOfflineData(); // Auto-sync on network reconnect
    };
    
    const handleOffline = () => {
      setIsOnline(false);
      toast('Você está offline. O PDV operará localmente.', { icon: '📡' });
    };

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    // Initial check on mount just in case
    if (navigator.onLine && !didInitialSyncRef.current) {
      didInitialSyncRef.current = true;
      syncOfflineData();
    }

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, [syncOfflineData]);

  return { isOnline, isSyncing, syncOfflineData };
}
