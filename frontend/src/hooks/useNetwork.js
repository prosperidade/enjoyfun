import { useState, useEffect, useCallback } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';
import toast from 'react-hot-toast';

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
      const payloadOut = pending.map(p => ({
        offline_id: p.offline_id,
        payload_type: p.payload_type,
        payload: p.payload,
        created_offline_at: p.created_offline_at
      }));

      const { data } = await api.post('/sync', { items: payloadOut });
      
      if (data.success) {
        // Bulk delete or mark as synced
        const ids = pending.map(p => p.offline_id);
        await db.offlineQueue.bulkDelete(ids);
        toast.success(`${pending.length} registros sincronizados!`, { id: 'sync' });
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
