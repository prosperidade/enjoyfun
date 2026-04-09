import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Clock3, Database, RefreshCw, Banknote } from 'lucide-react';
import toast from 'react-hot-toast';
import {
  db,
  getOfflineQueueReconciliationSnapshot,
  getOfflineQueueRetryPolicy,
  requeueOfflineQueueItems,
  createOfflineQueueRecord,
} from '../lib/db';

/** Error substring that identifies an offline topup rejected for invalid payment method. */
const TOPUP_METHOD_ERROR_MARKER = 'nao permitido para recarga offline';

function isTopupMethodFailure(record) {
  if (record?.payload_type !== 'topup') return false;
  const error = String(record?.last_error || '').toLowerCase();
  return error.includes(TOPUP_METHOD_ERROR_MARKER) || error.includes('offline_payment_method_not_allowed');
}

function formatDateTimeLabel(value) {
  if (!value) {
    return 'Sem horario';
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return 'Sem horario';
  }

  return parsed.toLocaleString('pt-BR');
}

function formatSnippet(value, size = 10) {
  const normalized = String(value || '').trim();
  if (normalized.length <= size) {
    return normalized || 'sem referencia';
  }

  return `${normalized.slice(0, size)}...`;
}

function describeOfflineRecord(record) {
  const payload = record?.payload ?? record?.data ?? {};
  const eventId = Number(payload?.event_id || 0);

  switch (record?.payload_type) {
    case 'sale':
      return `Venda offline${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'meal':
      return `Meals offline${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'topup':
      return `Recarga offline${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'ticket_validate':
      return `Ticket validate${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'guest_validate':
      return `Guest validate${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'participant_validate':
      return `Participant validate${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'parking_entry':
      return `Parking entry${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'parking_exit':
      return `Parking exit${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    case 'parking_validate':
      return `Parking validate${eventId > 0 ? ` · evento ${eventId}` : ''}`;
    default:
      return record?.payload_type || 'Fila offline';
  }
}

function describeOfflineRecordContext(record) {
  const payload = record?.payload ?? record?.data ?? {};

  switch (record?.payload_type) {
    case 'sale':
      return [
        payload?.sector ? `setor ${payload.sector}` : null,
        payload?.card_id ? `cartao ${formatSnippet(payload.card_id, 8)}` : null,
        Array.isArray(payload?.items) ? `${payload.items.length} item(ns)` : null,
      ].filter(Boolean).join(' · ');
    case 'topup':
      return [
        payload?.payment_method ? `metodo: ${payload.payment_method}` : null,
        payload?.amount ? `valor: R$ ${Number(payload.amount).toFixed(2)}` : null,
        payload?.card_id ? `cartao ${formatSnippet(payload.card_id, 8)}` : null,
      ].filter(Boolean).join(' · ');
    case 'meal':
      return [
        payload?.sector ? `setor ${payload.sector}` : null,
        payload?.qr_token ? `QR ${formatSnippet(payload.qr_token, 8)}` : null,
        payload?.meal_service_code ? `servico ${payload.meal_service_code}` : null,
      ].filter(Boolean).join(' · ');
    case 'parking_entry':
    case 'parking_exit':
      return [
        payload?.license_plate ? `placa ${payload.license_plate}` : null,
        payload?.vehicle_type ? `tipo ${payload.vehicle_type}` : null,
      ].filter(Boolean).join(' · ');
    case 'parking_validate':
      return [
        payload?.action ? `acao ${payload.action}` : null,
        payload?.qr_token ? `QR ${formatSnippet(payload.qr_token, 8)}` : null,
      ].filter(Boolean).join(' · ');
    default:
      return [
        payload?.token ? `token ${formatSnippet(payload.token, 8)}` : null,
        payload?.scanned_token ? `scan ${formatSnippet(payload.scanned_token, 8)}` : null,
      ].filter(Boolean).join(' · ');
  }
}

/**
 * Corrects a failed topup record by changing its payment method to 'cash',
 * resetting its failure state, and re-enqueuing it for sync.
 * Does NOT duplicate: updates the existing record in-place via its offline_id.
 */
async function correctTopupPaymentMethodAndRequeue(offlineId) {
  const normalizedId = String(offlineId || '').trim();
  if (!normalizedId) {
    throw new Error('offline_id invalido.');
  }

  const now = new Date().toISOString();

  await db.transaction('rw', db.offlineQueue, async () => {
    const existing = await db.offlineQueue.get(normalizedId);
    if (!existing) {
      throw new Error('Registro offline nao encontrado no dispositivo.');
    }

    const record = createOfflineQueueRecord(existing);

    // Update payment method to cash and reset failure state
    const correctedPayload = {
      ...(record.payload || {}),
      payment_method: 'cash',
      _corrected_from_method: record.payload?.payment_method || 'unknown',
      _corrected_at: now,
    };

    await db.offlineQueue.put({
      ...record,
      payload: correctedPayload,
      status: 'pending',
      sync_attempts: 0,
      last_error: null,
      last_error_at: null,
      next_retry_at: now,
      retried_at: now,
    });
  });
}

export default function OfflineQueueReconciliationPanel() {
  const [isOpen, setIsOpen] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [actingOn, setActingOn] = useState(null);
  const [snapshot, setSnapshot] = useState({
    pendingCount: 0,
    readyCount: 0,
    scheduledCount: 0,
    failedCount: 0,
    failedIds: [],
    failedRecords: [],
  });

  const refreshSnapshot = useCallback(async () => {
    setIsRefreshing(true);

    try {
      const nextSnapshot = await getOfflineQueueReconciliationSnapshot({ limit: 6 });
      setSnapshot(nextSnapshot);
    } finally {
      setIsRefreshing(false);
    }
  }, []);

  useEffect(() => {
    refreshSnapshot();

    const handleFocus = () => {
      refreshSnapshot();
    };

    window.addEventListener('focus', handleFocus);
    window.addEventListener('online', handleFocus);
    const intervalId = window.setInterval(refreshSnapshot, 15_000);

    return () => {
      window.removeEventListener('focus', handleFocus);
      window.removeEventListener('online', handleFocus);
      window.clearInterval(intervalId);
    };
  }, [refreshSnapshot]);

  const handleRequeue = useCallback(async (offlineIds) => {
    const normalizedIds = Array.isArray(offlineIds)
      ? offlineIds.map((offlineId) => String(offlineId || '').trim()).filter(Boolean)
      : [];

    if (normalizedIds.length === 0) {
      toast.error('Nenhuma falha local disponivel para reenfileirar.');
      return;
    }

    try {
      await requeueOfflineQueueItems(normalizedIds);
      await refreshSnapshot();
      toast.success(`${normalizedIds.length} falha(s) offline reenfileirada(s).`);
    } catch {
      toast.error('Nao foi possivel reenfileirar a fila offline.');
    }
  }, [refreshSnapshot]);

  const handleCorrectTopupAndRequeue = useCallback(async (offlineId) => {
    setActingOn(offlineId);
    try {
      await correctTopupPaymentMethodAndRequeue(offlineId);
      await refreshSnapshot();
      toast.success('Recarga corrigida para dinheiro e reenfileirada.');
    } catch (err) {
      toast.error(err?.message || 'Nao foi possivel corrigir e reenfileirar a recarga.');
    } finally {
      setActingOn(null);
    }
  }, [refreshSnapshot]);

  return (
    <div className="relative">
      <button
        type="button"
        className={`relative flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors ${
          snapshot.failedCount > 0
            ? 'border-red-800/50 bg-red-900/20 text-red-200'
            : snapshot.scheduledCount > 0
              ? 'border-amber-800/40 bg-amber-900/10 text-amber-200'
              : 'border-gray-800 bg-gray-900/70 text-gray-300'
        }`}
        onClick={() => setIsOpen((current) => !current)}
      >
        <Database size={14} />
        <span className="hidden sm:inline">Fila offline</span>
        {snapshot.failedCount > 0 ? (
          <span className="inline-flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
            {snapshot.failedCount}
          </span>
        ) : null}
      </button>

      {isOpen ? (
        <div className="absolute right-0 top-12 z-30 w-[26rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-gray-800 bg-gray-950/95 p-4 shadow-2xl backdrop-blur">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-sm font-semibold text-white">Reconciliacao offline</p>
              <p className="mt-1 text-xs text-gray-400">
                Lote local deste dispositivo com retry automatico e reenfileiramento manual.
              </p>
            </div>
            <button
              type="button"
              className="rounded-full border border-gray-800 p-2 text-gray-400 transition-colors hover:border-gray-700 hover:text-white"
              onClick={refreshSnapshot}
              title="Atualizar fila offline"
            >
              <RefreshCw size={14} className={isRefreshing ? 'animate-spin' : ''} />
            </button>
          </div>

          <div className="mt-4 grid grid-cols-3 gap-2">
            <div className="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-[11px] uppercase tracking-wide text-gray-500">Prontas</p>
              <p className="mt-1 text-lg font-semibold text-white">{snapshot.readyCount}</p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-[11px] uppercase tracking-wide text-gray-500">Aguardando</p>
              <p className="mt-1 text-lg font-semibold text-amber-200">{snapshot.scheduledCount}</p>
            </div>
            <div className="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-[11px] uppercase tracking-wide text-gray-500">Falhas</p>
              <p className="mt-1 text-lg font-semibold text-red-200">{snapshot.failedCount}</p>
            </div>
          </div>

          <div className="mt-4 flex flex-wrap gap-2">
            <button
              type="button"
              className="btn-secondary inline-flex items-center gap-2"
              onClick={refreshSnapshot}
            >
              <RefreshCw size={14} className={isRefreshing ? 'animate-spin' : ''} />
              Atualizar
            </button>
            <button
              type="button"
              className="btn-primary inline-flex items-center gap-2"
              onClick={() => handleRequeue(snapshot.failedIds)}
              disabled={snapshot.failedCount === 0}
            >
              <RefreshCw size={14} />
              Reenfileirar falhas
            </button>
          </div>

          {snapshot.scheduledCount > 0 ? (
            <div className="mt-4 flex items-start gap-2 rounded-xl border border-amber-800/40 bg-amber-950/30 p-3 text-xs text-amber-100">
              <Clock3 size={14} className="mt-0.5 shrink-0" />
              <p>
                {snapshot.scheduledCount} item(ns) estao em backoff automatico e voltam para a fila quando a janela de retry vencer.
              </p>
            </div>
          ) : null}

          <div className="mt-4 space-y-3 max-h-[24rem] overflow-y-auto">
            {snapshot.failedCount === 0 ? (
              <div className="rounded-xl border border-dashed border-gray-800 p-4 text-sm text-gray-400">
                Nenhuma falha local aberta neste dispositivo.
              </div>
            ) : (
              snapshot.failedRecords.map((record) => {
                const policy = getOfflineQueueRetryPolicy(record?.payload_type);
                const attempts = Number(record?.sync_attempts || 0);
                const isTopupFix = isTopupMethodFailure(record);
                const isActing = actingOn === record.offline_id;

                return (
                  <div
                    key={record.offline_id}
                    className={`rounded-xl border p-3 ${
                      isTopupFix
                        ? 'border-amber-800/50 bg-amber-950/20'
                        : 'border-gray-800 bg-gray-900/70'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-semibold text-white">
                          {describeOfflineRecord(record)}
                        </p>
                        <p className="mt-1 text-xs text-gray-400">
                          {describeOfflineRecordContext(record) || 'Sem contexto adicional salvo.'}
                        </p>
                      </div>
                      <span className="inline-flex items-center gap-1 rounded-full bg-red-900/30 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-200">
                        <AlertTriangle size={12} />
                        {attempts}/{policy.maxAttempts}
                      </span>
                    </div>

                    <p className="mt-3 text-xs text-red-200">
                      {record?.last_error || 'Falha local sem detalhe registrado.'}
                    </p>

                    {/* Topup method failure: explain and offer correction */}
                    {isTopupFix ? (
                      <div className="mt-3 rounded-lg border border-amber-800/40 bg-amber-950/30 p-3 space-y-2">
                        <p className="text-xs text-amber-100 leading-relaxed">
                          <strong>Por que falhou:</strong> Recargas offline so aceitam pagamento em dinheiro.
                          O metodo original era <strong>"{record?.payload?.payment_method || 'desconhecido'}"</strong>, que
                          requer conexao com o gateway (pix, cartao, etc).
                        </p>
                        <p className="text-xs text-amber-100 leading-relaxed">
                          <strong>O que fazer:</strong> Clique abaixo para corrigir o metodo para <strong>dinheiro</strong> e
                          reenfileirar automaticamente. O valor e os demais dados serao mantidos.
                        </p>
                        <button
                          type="button"
                          disabled={isActing}
                          onClick={() => handleCorrectTopupAndRequeue(record.offline_id)}
                          className="inline-flex items-center gap-2 rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600 disabled:opacity-60 transition-colors"
                        >
                          <Banknote size={14} />
                          {isActing ? 'Corrigindo...' : 'Corrigir para dinheiro e reenfileirar'}
                        </button>
                      </div>
                    ) : null}

                    <div className="mt-3 flex items-center justify-between gap-3">
                      <span className="text-[11px] text-gray-500">
                        {formatDateTimeLabel(record?.last_error_at || record?.created_offline_at)}
                      </span>
                      <button
                        type="button"
                        className="text-xs font-medium text-blue-300 transition-colors hover:text-blue-200"
                        onClick={() => handleRequeue([record.offline_id])}
                      >
                        Reenfileirar
                      </button>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}
