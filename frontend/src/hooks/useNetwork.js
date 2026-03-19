import { useState, useEffect, useCallback, useRef } from 'react';
import { db, markOfflineQueueItemsFailed } from '../lib/db';
import api from '../lib/api';
import toast from 'react-hot-toast';

function hasValidEventId(eventId) {
  return Number(eventId) > 0;
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

  return {
    offline_id: record?.offline_id,
    payload_type: payloadType,
    payload: {
      ...payload,
      event_id: hasValidEventId(payload?.event_id) ? Number(payload.event_id) : null,
      sector: payload?.sector ?? record?.sector ?? (payloadType === 'sale' ? 'bar' : null),
      ...(payloadType === 'sale' ? { card_id: cardId } : {}),
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
    
    try {
      const pending = await db.offlineQueue.where('status').equals('pending').toArray();
      if (pending.length === 0) {
        toast.dismiss('sync');
        return;
      }

      toast.loading(`Sincronizando ${pending.length} registros offline...`, { id: 'sync' });

      const normalizedPending = pending.map(normalizePendingSyncRecord);
      const invalidPending = normalizedPending.filter(
        (record) => !hasValidEventId(record?.payload?.event_id),
      );
      const payloadOut = normalizedPending.filter((record) =>
        hasValidEventId(record?.payload?.event_id),
      );

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

        if (invalidPending.length > 0) {
          await markOfflineQueueItemsFailed(
            invalidPending.map((record) => record.offline_id),
            invalidPending.map((record) => ({
              offline_id: record.offline_id,
              error: 'Registro offline sem event_id valido. Corrija o payload antes de reenfileirar.',
            })),
          );
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
        toast.error('Nao foi possivel concluir a sincronizacao offline.', { id: 'sync' });
      }
    } catch (err) {
      console.error('Offline Sync error:', err);
      toast.error('Ocorreu um erro ao sincronizar em background.', { id: 'sync' });
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
