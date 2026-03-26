/**
 * useOfflineSync — Hook global de Background Sync para Scanner e Parking.
 *
 * Ao reconectar, lê todos os itens 'pending' da offlineQueue com
 * payload_type em [scanner_process, ticket_validate, parking_entry, parking_exit, parking_validate]
 * e tenta fazer o POST correspondente.
 *
 * Também roda em polling a cada POLL_INTERVAL_MS enquanto online, como fallback
 * para o caso de o evento 'online' não ser capturado (PWA, mobile, etc.).
 */
import { useCallback, useEffect, useRef } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';
import toast from 'react-hot-toast';

const SYNC_TYPES = ['scanner_process', 'ticket_validate', 'parking_entry', 'parking_exit', 'parking_validate'];
const MAX_ATTEMPTS = 3;
const POLL_INTERVAL_MS = 30_000; // 30 segundos

/** Mapeia payload_type → chamada de API */
async function dispatchQueueItem(item) {
  const { payload_type, payload } = item;

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

    case 'ticket_validate':
      return api.post('/tickets/validate', {
        dynamic_token: payload.token,
      });

    case 'parking_entry':
      return api.post('/parking', {
        event_id: payload.event_id,
        vehicle_type: payload.vehicle_type,
        license_plate: payload.license_plate,
      });

    case 'parking_exit':
      // payload.parking_id é o ID real do registro de estacionamento
      return api.post(`/parking/${payload.parking_id}/exit`);

    case 'parking_validate':
      return api.post('/parking/validate', {
        qr_token: payload.qr_token,
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
      const pending = await db.offlineQueue
        .where('status').equals('pending')
        .toArray();

      const relevant = pending.filter(
        (item) =>
          SYNC_TYPES.includes(item.payload_type) &&
          Number(item.sync_attempts || 0) < MAX_ATTEMPTS,
      );

      if (relevant.length === 0) return;

      let synced = 0;
      let failed = 0;

      for (const item of relevant) {
        try {
          await dispatchQueueItem(item);

          await db.offlineQueue.update(item.offline_id, {
            status: 'synced',
            synced_at: new Date().toISOString(),
          });
          synced += 1;
        } catch (err) {
          const attempts = Number(item.sync_attempts || 0) + 1;
          const newStatus = attempts >= MAX_ATTEMPTS ? 'failed' : 'pending';

          await db.offlineQueue.update(item.offline_id, {
            status: newStatus,
            sync_attempts: attempts,
            last_error: err?.response?.data?.message || err?.message || 'Erro desconhecido',
            last_error_at: new Date().toISOString(),
          });
          if (newStatus === 'failed') failed += 1;
        }
      }

      if (synced > 0) {
        toast.success(`${synced} operação(ões) offline sincronizadas!`, { id: 'offline-sync-ok' });
      }
      if (failed > 0) {
        toast.error(`${failed} operação(ões) offline falharam após ${MAX_ATTEMPTS} tentativas.`, { id: 'offline-sync-fail' });
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
