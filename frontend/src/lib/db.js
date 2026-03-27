import Dexie from 'dexie';

export const db = new Dexie('EnjoyFunDB');

db.version(1).stores({
  // Tabela local de produtos para acesso muito rápido
  products: 'id, event_id, name, price, stock_qty',
  
  // Tabela de sincronização (fila)
  offlineQueue: 'offline_id, status, payload_type' // uuid, pending|failed|synced, sale|topup|meal|ticket_validate|guest_validate|participant_validate|parking_*
});

db.version(2).stores({
  products: 'id, event_id, name, price, stock_qty',
  offlineQueue: 'offline_id, status, payload_type',
  mealsContext: 'cache_key, updated_at'
});

db.version(3).stores({
  products: 'id, event_id, name, price, stock_qty',
  offlineQueue: 'offline_id, status, payload_type',
  mealsContext: 'cache_key, updated_at',
  scannerCache: 'token, type, event_id, status, used_offline'
});

db.version(4).stores({
  products: 'id, event_id, name, price, stock_qty',
  offlineQueue: 'offline_id, status, payload_type',
  mealsContext: 'cache_key, updated_at',
  scannerCache: 'token, type, event_id, status, used_offline, token_lookup, ref_lookup, [event_id+token_lookup], [event_id+ref_lookup]'
});

export async function markOfflineQueueItemsFailed(offlineIds = [], errors = []) {
  if (!Array.isArray(offlineIds) || offlineIds.length === 0) {
    return;
  }

  const errorById = new Map(
    (Array.isArray(errors) ? errors : [])
      .map((entry) => [String(entry?.offline_id || "").trim(), String(entry?.error || "").trim()])
      .filter(([offlineId]) => offlineId)
  );
  const now = new Date().toISOString();

  await db.transaction('rw', db.offlineQueue, async () => {
    for (const rawId of offlineIds) {
      const offlineId = String(rawId || "").trim();
      if (!offlineId) continue;

      const existing = await db.offlineQueue.get(offlineId);
      if (!existing) continue;

      await db.offlineQueue.put({
        ...existing,
        status: 'failed',
        last_error: errorById.get(offlineId) || existing?.last_error || 'Falha na sincronização.',
        last_error_at: now,
        sync_attempts: Number(existing?.sync_attempts || 0) + 1,
      });
    }
  });
}

export async function requeueOfflineQueueItems(offlineIds = []) {
  if (!Array.isArray(offlineIds) || offlineIds.length === 0) {
    return;
  }

  const now = new Date().toISOString();
  await db.transaction('rw', db.offlineQueue, async () => {
    for (const rawId of offlineIds) {
      const offlineId = String(rawId || "").trim();
      if (!offlineId) continue;

      const existing = await db.offlineQueue.get(offlineId);
      if (!existing) continue;

      await db.offlineQueue.put({
        ...existing,
        status: 'pending',
        last_error: null,
        last_error_at: null,
        retried_at: now,
      });
    }
  });
}
