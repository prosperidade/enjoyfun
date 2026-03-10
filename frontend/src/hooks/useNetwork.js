import { useState, useEffect, useCallback } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';
import toast from 'react-hot-toast';

function normalizePendingSyncRecord(record) {
  const payload = record?.payload ?? record?.data ?? {};
  const cardId =
    payload.card_id ??
    payload.qr_token ??
    payload.card_token ??
    payload.customer_id ??
    null;

  return {
    offline_id: record?.offline_id,
    payload_type: record?.payload_type ?? record?.type ?? 'sale',
    payload: {
      ...payload,
      sector: payload?.sector ?? record?.sector ?? 'bar',
      card_id: cardId,
    },
    created_offline_at: record?.created_offline_at ?? record?.created_at ?? new Date().toISOString(),
  };
}

export function useNetwork() {
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [isSyncing, setIsSyncing] = useState(false);

  const syncOfflineData = useCallback(async () => {
    if (isSyncing || !navigator.onLine) return;
    
    try {
      const pending = await db.offlineQueue.where('status').equals('pending').toArray();
      if (pending.length === 0) return;

      setIsSyncing(true);
      toast.loading(`Sincronizando ${pending.length} registros offline...`, { id: 'sync' });

      // Transform array for the bulk API endpoint
      const payloadOut = pending.map(normalizePendingSyncRecord);

      const { data } = await api.post('/sync', { items: payloadOut });
      
      if (data.success) {
        const processedIds = data?.data?.processed_ids ?? payloadOut.map(p => p.offline_id);
        const failedCount = Number(data?.data?.failed ?? 0);

        if (processedIds.length > 0) {
          await db.offlineQueue.bulkDelete(processedIds);
        }

        if (failedCount > 0) {
          toast.error(`${processedIds.length} registros sincronizados e ${failedCount} pendentes.`, { id: 'sync' });
        } else {
          toast.success(`${processedIds.length} registros sincronizados!`, { id: 'sync' });
        }
      }
    } catch (err) {
      console.error('Offline Sync error:', err);
      toast.error('Ocorreu um erro ao sincronizar em background.', { id: 'sync' });
    } finally {
      setIsSyncing(false);
    }
  }, [isSyncing]);

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
    if (navigator.onLine) {
      syncOfflineData();
    }

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, [syncOfflineData]);

  return { isOnline, isSyncing, syncOfflineData };
}
