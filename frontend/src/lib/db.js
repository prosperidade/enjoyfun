import Dexie from 'dexie';

export const db = new Dexie('EnjoyFunDB');

const DEFAULT_RETRY_POLICY = Object.freeze({
  priority: 'standard',
  maxAttempts: 5,
  baseDelayMs: 30_000,
  maxDelayMs: 15 * 60_000,
});

const OFFLINE_QUEUE_RETRY_POLICIES = Object.freeze({
  sale: {
    priority: 'critical',
    maxAttempts: 8,
    baseDelayMs: 15_000,
    maxDelayMs: 20 * 60_000,
  },
  meal: {
    priority: 'high',
    maxAttempts: 6,
    baseDelayMs: 20_000,
    maxDelayMs: 15 * 60_000,
  },
  topup: {
    priority: 'critical',
    maxAttempts: 8,
    baseDelayMs: 15_000,
    maxDelayMs: 20 * 60_000,
  },
  scanner_process: {
    priority: 'high',
    maxAttempts: 6,
    baseDelayMs: 10_000,
    maxDelayMs: 10 * 60_000,
  },
  ticket_validate: {
    priority: 'high',
    maxAttempts: 6,
    baseDelayMs: 10_000,
    maxDelayMs: 10 * 60_000,
  },
  guest_validate: {
    priority: 'high',
    maxAttempts: 6,
    baseDelayMs: 10_000,
    maxDelayMs: 10 * 60_000,
  },
  participant_validate: {
    priority: 'high',
    maxAttempts: 6,
    baseDelayMs: 10_000,
    maxDelayMs: 10 * 60_000,
  },
  parking_entry: {
    priority: 'operational',
    maxAttempts: 5,
    baseDelayMs: 20_000,
    maxDelayMs: 15 * 60_000,
  },
  parking_exit: {
    priority: 'operational',
    maxAttempts: 5,
    baseDelayMs: 20_000,
    maxDelayMs: 15 * 60_000,
  },
  parking_validate: {
    priority: 'operational',
    maxAttempts: 5,
    baseDelayMs: 20_000,
    maxDelayMs: 15 * 60_000,
  },
});

const OFFLINE_QUEUE_SCHEMA_VERSIONS = Object.freeze({
  sale: 2,
  meal: 1,
  topup: 1,
  scanner_process: 1,
  ticket_validate: 1,
  guest_validate: 1,
  participant_validate: 1,
  parking_entry: 1,
  parking_exit: 1,
  parking_validate: 1,
});

function normalizeOfflineQueueId(rawId) {
  return String(rawId || '').trim();
}

function normalizeOfflineAttempts(value) {
  const numericValue = Number(value || 0);
  if (!Number.isFinite(numericValue) || numericValue <= 0) {
    return 0;
  }

  return Math.floor(numericValue);
}

function resolveIsoDate(value, fallbackIso) {
  const parsed = value ? new Date(value) : null;
  if (parsed && !Number.isNaN(parsed.getTime())) {
    return parsed.toISOString();
  }

  return fallbackIso;
}

function createRetryJitterMultiplier() {
  return 0.85 + Math.random() * 0.3;
}

function buildErrorMessageMap(errors = []) {
  return new Map(
    (Array.isArray(errors) ? errors : [])
      .map((entry) => [normalizeOfflineQueueId(entry?.offline_id), String(entry?.error || '').trim()])
      .filter(([offlineId]) => offlineId),
  );
}

function normalizeOfflineQueuePayload(payloadType, payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return payload;
  }

  const schemaVersion = getOfflineQueueSchemaVersion(payloadType);
  const normalizedPayload = { ...payload };

  if (!schemaVersion) {
    return normalizedPayload;
  }

  const existingVersion = Number(normalizedPayload?.client_schema_version || 0);
  normalizedPayload.client_schema_version = existingVersion > 0
    ? existingVersion
    : schemaVersion;

  return normalizedPayload;
}

export function getOfflineQueueRetryPolicy(payloadType) {
  const normalizedType = String(payloadType || '').trim();
  return OFFLINE_QUEUE_RETRY_POLICIES[normalizedType] || DEFAULT_RETRY_POLICY;
}

export function getOfflineQueueSchemaVersion(payloadType) {
  const normalizedType = String(payloadType || '').trim();
  return OFFLINE_QUEUE_SCHEMA_VERSIONS[normalizedType] || null;
}

export function createOfflineQueueRecord(record = {}) {
  const nowIso = new Date().toISOString();
  const payloadType = String(record?.payload_type ?? record?.type ?? '').trim();
  const status = String(record?.status || 'pending').trim() || 'pending';
  const createdOfflineAt = resolveIsoDate(
    record?.created_offline_at ?? record?.created_at,
    nowIso,
  );
  const policy = getOfflineQueueRetryPolicy(payloadType);

  return {
    ...record,
    payload_type: payloadType,
    payload: normalizeOfflineQueuePayload(payloadType, record?.payload ?? record?.data ?? null),
    status,
    created_offline_at: createdOfflineAt,
    sync_attempts: normalizeOfflineAttempts(record?.sync_attempts),
    next_retry_at:
      status === 'pending'
        ? resolveIsoDate(record?.next_retry_at, createdOfflineAt)
        : record?.next_retry_at ?? null,
    retry_priority: record?.retry_priority ?? policy.priority,
  };
}

export function computeOfflineQueueNextRetryAt(record, attempts, now = new Date()) {
  const policy = getOfflineQueueRetryPolicy(record?.payload_type);
  const nowDate = now instanceof Date ? now : new Date(now);
  const normalizedAttempts = Math.max(1, normalizeOfflineAttempts(attempts));
  const exponentialDelay = Math.min(
    policy.baseDelayMs * (2 ** Math.max(0, normalizedAttempts - 1)),
    policy.maxDelayMs,
  );
  const jitteredDelay = Math.max(
    policy.baseDelayMs,
    Math.round(exponentialDelay * createRetryJitterMultiplier()),
  );

  return new Date(nowDate.getTime() + jitteredDelay).toISOString();
}

export function isOfflineQueueItemReadyForSync(record, now = new Date()) {
  if (String(record?.status || '').trim() !== 'pending') {
    return false;
  }

  const target = record?.next_retry_at;
  if (!target) {
    return true;
  }

  const parsedTarget = new Date(target);
  if (Number.isNaN(parsedTarget.getTime())) {
    return true;
  }

  const nowDate = now instanceof Date ? now : new Date(now);
  return parsedTarget.getTime() <= nowDate.getTime();
}

export async function loadOfflineQueueItems({
  statuses = ['pending'],
  payloadTypes = null,
  readyOnly = false,
  now = new Date(),
} = {}) {
  const normalizedStatuses = Array.isArray(statuses)
    ? statuses.map((status) => String(status || '').trim()).filter(Boolean)
    : [];

  if (normalizedStatuses.length === 0) {
    return [];
  }

  const records = normalizedStatuses.length === 1
    ? await db.offlineQueue.where('status').equals(normalizedStatuses[0]).toArray()
    : await db.offlineQueue.where('status').anyOf(...normalizedStatuses).toArray();

  const normalizedPayloadTypes = Array.isArray(payloadTypes)
    ? payloadTypes.map((payloadType) => String(payloadType || '').trim()).filter(Boolean)
    : null;

  return records
    .map((record) => createOfflineQueueRecord(record))
    .filter((record) => (
      !normalizedPayloadTypes || normalizedPayloadTypes.includes(record.payload_type)
    ))
    .filter((record) => (
      !readyOnly || isOfflineQueueItemReadyForSync(record, now)
    ));
}

export async function scheduleOfflineQueueRetries(offlineIds = [], errors = []) {
  if (!Array.isArray(offlineIds) || offlineIds.length === 0) {
    return { requeued: 0, failed: 0 };
  }

  const errorById = buildErrorMessageMap(errors);
  const now = new Date();
  const nowIso = now.toISOString();
  let requeued = 0;
  let failed = 0;

  await db.transaction('rw', db.offlineQueue, async () => {
    for (const rawId of offlineIds) {
      const offlineId = normalizeOfflineQueueId(rawId);
      if (!offlineId) continue;

      const existing = await db.offlineQueue.get(offlineId);
      if (!existing) continue;

      const normalizedRecord = createOfflineQueueRecord(existing);
      const attempts = normalizeOfflineAttempts(normalizedRecord.sync_attempts) + 1;
      const policy = getOfflineQueueRetryPolicy(normalizedRecord.payload_type);
      const shouldFail = attempts >= policy.maxAttempts;

      await db.offlineQueue.put({
        ...normalizedRecord,
        status: shouldFail ? 'failed' : 'pending',
        sync_attempts: attempts,
        last_error: errorById.get(offlineId) || normalizedRecord?.last_error || 'Falha na sincronizacao.',
        last_error_at: nowIso,
        next_retry_at: shouldFail
          ? null
          : computeOfflineQueueNextRetryAt(normalizedRecord, attempts, now),
        retry_priority: policy.priority,
      });

      if (shouldFail) {
        failed += 1;
      } else {
        requeued += 1;
      }
    }
  });

  return { requeued, failed };
}

export function isOfflineQueueTransientError(error) {
  if (error?.offlineSyncTerminal === true) {
    return false;
  }

  const status = Number(error?.response?.status || 0);
  const code = String(error?.code || '').trim().toUpperCase();

  if (!error?.response) {
    return true;
  }

  if (code === 'ERR_NETWORK' || code === 'ECONNABORTED' || code === 'ETIMEDOUT') {
    return true;
  }

  return [408, 425, 429].includes(status) || status >= 500;
}

export async function getOfflineQueueReconciliationSnapshot({ limit = 8 } = {}) {
  const records = await loadOfflineQueueItems({ statuses: ['pending', 'failed'] });
  const allFailedRecords = records
    .filter((record) => record.status === 'failed')
    .sort((left, right) => {
      const leftTime = new Date(left?.last_error_at || left?.created_offline_at || 0).getTime();
      const rightTime = new Date(right?.last_error_at || right?.created_offline_at || 0).getTime();
      return rightTime - leftTime;
    });
  const failedRecords = allFailedRecords.slice(0, Math.max(1, Number(limit) || 8));
  const pendingRecords = records.filter((record) => record.status === 'pending');
  const readyCount = pendingRecords.filter((record) => isOfflineQueueItemReadyForSync(record)).length;

  return {
    pendingCount: pendingRecords.length,
    readyCount,
    scheduledCount: Math.max(0, pendingRecords.length - readyCount),
    failedCount: allFailedRecords.length,
    failedIds: allFailedRecords.map((record) => record.offline_id),
    failedRecords,
  };
}

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

  const errorById = buildErrorMessageMap(errors);
  const now = new Date().toISOString();

  await db.transaction('rw', db.offlineQueue, async () => {
    for (const rawId of offlineIds) {
      const offlineId = normalizeOfflineQueueId(rawId);
      if (!offlineId) continue;

      const existing = await db.offlineQueue.get(offlineId);
      if (!existing) continue;
      const normalizedRecord = createOfflineQueueRecord(existing);
      const attempts = normalizeOfflineAttempts(normalizedRecord?.sync_attempts) + 1;

      await db.offlineQueue.put({
        ...normalizedRecord,
        status: 'failed',
        last_error: errorById.get(offlineId) || normalizedRecord?.last_error || 'Falha na sincronizacao.',
        last_error_at: now,
        sync_attempts: attempts,
        next_retry_at: null,
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
      const offlineId = normalizeOfflineQueueId(rawId);
      if (!offlineId) continue;

      const existing = await db.offlineQueue.get(offlineId);
      if (!existing) continue;
      const normalizedRecord = createOfflineQueueRecord(existing);

      await db.offlineQueue.put({
        ...normalizedRecord,
        status: 'pending',
        sync_attempts: 0,
        last_error: null,
        last_error_at: null,
        next_retry_at: now,
        retried_at: now,
      });
    }
  });
}
